<?php
// api/solicitar_carga.php - VERSIÓN FINAL CON SOPORTE DE REEMPLAZOS POR VACACIONES
if (ob_get_level()) ob_end_clean();
session_start();

require_once '../includes/verificar_piloto.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
    exit; 
}

require_once '../config/database.php';
require_once 'ArbolDecision.php';
require_once 'EmailService.php';
require_once 'config_motivos.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// ✅ SOPORTAR TANTO JSON COMO FORMDATA
if ($_SERVER['CONTENT_TYPE'] && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    $sku = trim($input['sku'] ?? '');
    $cantidad = (int)($input['cantidad'] ?? 0);
    $descripcion = trim($input['descripcion'] ?? '');
    $observaciones = trim($input['observaciones'] ?? '');
    $motivo = trim($input['motivo'] ?? '');
} else {
    $sku = trim($_POST['sku'] ?? '');
    $cantidad = (int)($_POST['cantidad'] ?? 0);
    $descripcion = trim($_POST['descripcion'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    $motivo = trim($_POST['motivo'] ?? '');
}

$id_tienda = isset($_SESSION['id_tienda']) ? (int)$_SESSION['id_tienda'] : 0;
$usuario_tienda = $_SESSION['user_id'] ?? '';
$nombre_tienda = $_SESSION['nombre'] ?? 'Tienda';

if (!$sku || $cantidad <= 0) {
    echo json_encode(['success' => false, 'message' => 'El SKU y la cantidad son obligatorios']);
    exit;
}

if (empty($motivo) || !validarMotivo($motivo)) {
    echo json_encode(['success' => false, 'message' => 'Debe seleccionar un motivo válido para la carga.']);
    exit;
}

if ($id_tienda <= 0 || empty($usuario_tienda)) {
    echo json_encode(['success' => false, 'message' => 'Error de sesión: No se pudo identificar tu tienda.']);
    exit;
}

try {
    // ==========================================
    // ✅ DETECCIÓN AUTOMÁTICA DE FAMILIA, SUBFAMILIA Y ANALISTA (CON MAPEO A USERNAME Y REEMPLAZOS)
    // ==========================================
    $id_familia = null;
    $id_sub_familia = null;
    $analista_asignado = null;

    // 1. Buscar en sugerido_diario
    $sql_info = "SELECT DISTINCT sub_fam, fam FROM rct.sugerido_diario WHERE sku = :sku LIMIT 1";
    $stmt_info = $pdo->prepare($sql_info);
    $stmt_info->execute(['sku' => $sku]);
    $info_sku = $stmt_info->fetch(PDO::FETCH_ASSOC);

    if ($info_sku && !empty($info_sku['sub_fam'])) {
        $id_sub_familia = (int)$info_sku['sub_fam'];
        $id_familia = (int)$info_sku['fam'];
        
        // ✅ 2. Buscar analista en planificacion_asignacion_equipos Y MAPEAR A USERNAME
        $sql_analista = "SELECT COALESCE(u.usuario, TRIM(p.analista_repo_tienda)) as analista_username 
                         FROM input_clientes.planificacion_asignacion_equipos p
                         LEFT JOIN rct.usuarios_solicitudes_oc u 
                             ON TRIM(p.analista_repo_tienda) = u.nombre
                         WHERE p.id_sub_familia = :id_sub_familia 
                         LIMIT 1";
        $stmt_analista = $pdo->prepare($sql_analista);
        $stmt_analista->execute(['id_sub_familia' => $id_sub_familia]);
        $row_analista = $stmt_analista->fetch(PDO::FETCH_ASSOC);
        
        if ($row_analista && !empty($row_analista['analista_username'])) {
            $analista_asignado = trim($row_analista['analista_username']);
            
            // ✅ NUEVO: Verificar si el analista asignado está de vacaciones
            require_once __DIR__ . '/helpers_reemplazos.php';
            $analista_asignado = verificarYAplicarReemplazo($pdo, $analista_asignado);
        }
    }

    if (!$id_familia) {
        echo json_encode(['success' => false, 'message' => "No se pudo detectar la familia/subfamilia para el SKU: {$sku}. Verifique que el SKU exista en el maestro de productos."]);
        exit;
    }

    // Validación de SKU pendiente
    $sql_val = "SELECT s.id_solicitud, s.estado_general, DATE_FORMAT(s.fecha_solicitud, '%d-%m-%Y %H:%i') as fecha, sc.sku, sc.estado_item
                FROM Analisis_Procesos.solicitudes s
                INNER JOIN Analisis_Procesos.solicitudes_carga sc ON s.id_solicitud = sc.id_solicitud
                WHERE s.usuario_tienda = :usuario AND sc.sku = :sku AND s.estado_general IN ('PENDIENTE', 'EN_PROCESO') LIMIT 5";
    $stmt_val = $pdo->prepare($sql_val);
    $stmt_val->execute(['usuario' => $usuario_tienda, 'sku' => $sku]);
    $resultados_val = $stmt_val->fetchAll();
    
    $pendientes = array_filter($resultados_val, function($r) {
        return $r['estado_item'] === 'PENDIENTE' || in_array($r['estado_general'], ['PENDIENTE', 'EN_PROCESO']);
    });
    
    if (!empty($pendientes)) {
        $solicitud_pendiente = array_values($pendientes)[0];
        echo json_encode([
            'success' => false,
            'message' => "El SKU **{$sku}** ya está en la solicitud **#{$solicitud_pendiente['id_solicitud']}** (estado: **{$solicitud_pendiente['estado_general']}**, creada el {$solicitud_pendiente['fecha']}). Espera a que sea procesada.",
            'tipo_error' => 'SKU_PENDIENTE',
            'id_solicitud_existente' => $solicitud_pendiente['id_solicitud']
        ]);
        exit;
    }

    // Consulta de datos del producto
    $sql_sug = "SELECT 
                    ROUND((COALESCE(sd.vta_sem_6, 0) + COALESCE(sd.vta_sem_5, 0) + COALESCE(sd.vta_sem_4, 0) + COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 6, 2) as PV6, 
                    ROUND((COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 3, 2) as pv3,
                    sd.disp as disp_tda, 
                    sd.pend as pend_tda, 
                    COALESCE(bod_saldo.disp_bod_calc, 0) as disp_bod,
                    COALESCE(bod_saldo.pend_bod_calc, 0) as pend_bod,
                    sd.MD as MD_sugerido, 
                    sd.vta_sem_1, sd.vta_sem_2, sd.vta_sem_3, 
                    sd.lead_time_total, 
                    COALESCE(sd.`MIN`, 0) as min_despacho
                FROM rct.sugerido_diario sd
                LEFT JOIN (
                    SELECT 
                        inv.sku,
                        SUM(inv.saldo_disponible) as disp_bod_calc,
                        SUM(inv.saldo_pendiente) as pend_bod_calc
                    FROM sodimac_grt.inventario_diario inv
                    INNER JOIN sodimac_grt.bodegas b ON inv.id_sucursal = b.id_bodega
                    WHERE b.asigna = 1
                    GROUP BY inv.sku
                ) as bod_saldo ON sd.sku = bod_saldo.sku
                WHERE sd.id_tienda = :id_tienda AND sd.sku = :sku 
                ORDER BY sd.fecha DESC LIMIT 1";
    
    $stmt_sug = $pdo->prepare($sql_sug);
    $stmt_sug->execute(['id_tienda' => $id_tienda, 'sku' => $sku]);
    $datos_sugerido = $stmt_sug->fetch();

    // Validación PDF
    $motivos_con_pdf = ['VENTA_CLIENTE_BLACK', 'VENTA_CLIENTE_CONTACTABILIDAD', 'VENTA_CLIENTE_VE'];
    $ruta_pdf = null;

    if (in_array($motivo, $motivos_con_pdf)) {
        if (isset($_FILES['archivo_venta_pdf']) && $_FILES['archivo_venta_pdf']['error'] === UPLOAD_ERR_OK) {
            $archivo = $_FILES['archivo_venta_pdf'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $archivo['tmp_name']);
            finfo_close($finfo);
            
            if ($mime_type !== 'application/pdf') {
                echo json_encode(['success' => false, 'message' => 'El archivo debe ser un PDF válido.']);
                exit;
            }
            if ($archivo['size'] > 10 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'El PDF no debe superar los 10MB.']);
                exit;
            }
            
            $carpeta_uploads = __DIR__ . '/../uploads/ventas/';
            if (!is_dir($carpeta_uploads)) mkdir($carpeta_uploads, 0755, true);
            
            $nombre_archivo = 'venta_' . $id_tienda . '_' . time() . '_' . uniqid() . '.pdf';
            $ruta_destino = $carpeta_uploads . $nombre_archivo;
            
            if (move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
                $ruta_pdf = 'uploads/ventas/' . $nombre_archivo;
            } else {
                echo json_encode(['success' => false, 'message' => 'Error del servidor al guardar el documento.']);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'El documento de venta en PDF es obligatorio para este motivo.']);
            exit;
        }
    }

    // ✅ DETECCIÓN DE PRODUCTOS PELIGROSOS (DINÁMICO desde config_analistas_peligrosos)
    try {
        $sql_peligroso = "SELECT clase FROM sodimac_grt.codigos_peligrosos WHERE sku = :sku LIMIT 1";
        $stmt_peligroso = $pdo->prepare($sql_peligroso);
        $stmt_peligroso->execute(['sku' => $sku]);
        $producto_peligroso = $stmt_peligroso->fetch(PDO::FETCH_ASSOC);

        if ($producto_peligroso && !empty($producto_peligroso['clase'])) {
            $clase = (int)$producto_peligroso['clase'];
            
            // ✅ CONSULTA DINÁMICA: Buscar analista asignado para esta clase
            $sql_config = "SELECT analista_asignado, id_familia_asociada 
                        FROM Analisis_Procesos.config_analistas_peligrosos 
                        WHERE clase_peligro = :clase AND activo = 1 LIMIT 1";
            $stmt_config = $pdo->prepare($sql_config);
            $stmt_config->execute(['clase' => $clase]);
            $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
            
            if ($config) {
                $id_familia = (int)$config['id_familia_asociada'];
                $analista_asignado = trim($config['analista_asignado']); 
                
                // ✅ NUEVO: Verificar si el analista de peligrosos está de vacaciones
                require_once __DIR__ . '/helpers_reemplazos.php';
                $analista_asignado = verificarYAplicarReemplazo($pdo, $analista_asignado);
                
                error_log("PELIGROSO CLASE $clase: SKU $sku → Familia $id_familia → Analista final: $analista_asignado");
            } else {
                error_log("⚠️ SIN CONFIGURACIÓN para clase $clase. SKU $sku mantiene analista original.");
            }
        }
    } catch (PDOException $e) {
        error_log("Error verificando productos peligrosos: " . $e->getMessage());
    }

    // Evaluar con Árbol de Decisión
    $evaluacion = ArbolDecision::evaluar($sku, $cantidad, $datos_sugerido, $pdo);
    if (!$evaluacion['puede_cargar']) {
        echo json_encode(['success' => false, 'message' => $evaluacion['motivo_rechazo']]);
        exit;
    }

    // Crear Solicitud Padre
    $sql_padre = "INSERT INTO Analisis_Procesos.solicitudes 
                  (id_tienda, usuario_tienda, id_familia, id_sub_familia, usuario_proceso, estado_general, observaciones_generales, motivo_carga, archivo_venta_pdf, fecha_solicitud) 
                  VALUES (:id_tienda, :usuario_tienda, :id_familia, :id_sub_familia, :usuario_proceso, 'PENDIENTE', :observaciones, :motivo, :ruta_pdf, NOW())";
    
    $stmt_padre = $pdo->prepare($sql_padre);
    $stmt_padre->execute([
        'id_tienda' => $id_tienda,
        'usuario_tienda' => $usuario_tienda,
        'id_familia' => $id_familia,
        'id_sub_familia' => $id_sub_familia,
        'usuario_proceso' => $analista_asignado, // ✅ Guarda el username (o el del reemplazo si aplica)
        'observaciones' => $observaciones,
        'motivo' => $motivo,
        'ruta_pdf' => $ruta_pdf
    ]);
    
    $id_solicitud_padre = $pdo->lastInsertId();
    if (!$id_solicitud_padre || $id_solicitud_padre <= 0) throw new Exception('No se pudo crear la solicitud padre');

    // Crear Detalle
    $estado_item = 'PENDIENTE';
    $motivo_detalle = $evaluacion['nota_aprobacion'] ?? ''; 
    
    $sql_detalle = "INSERT INTO Analisis_Procesos.solicitudes_carga 
                   (id_solicitud, sku, descripcion_producto, carga_solicitada, carga_final, estado_item, campo_cambios, id_familia) 
                   VALUES (:id_solicitud, :sku, :descripcion, :cantidad_solicitada, :cantidad_final, :estado_item, :motivo, :id_familia)";
    
    $stmt_detalle = $pdo->prepare($sql_detalle);
    $stmt_detalle->execute([
        'id_solicitud' => $id_solicitud_padre,
        'sku' => $sku,
        'descripcion' => $descripcion,
        'cantidad_solicitada' => $cantidad,
        'cantidad_final' => $evaluacion['cantidad_autorizada'],
        'estado_item' => $estado_item,
        'motivo' => $motivo_detalle,
        'id_familia' => $id_familia
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Solicitud creada correctamente y asignada automáticamente.',
        'id_solicitud' => $id_solicitud_padre,
        'analista_asignado' => $analista_asignado
    ]);

} catch (PDOException $e) {
    error_log("Error PDO: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error general: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>