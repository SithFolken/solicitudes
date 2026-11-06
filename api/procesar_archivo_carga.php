<?php
// api/procesar_archivo_carga.php - VERSIÓN OPTIMIZADA CON SOPORTE DE REEMPLAZOS POR VACACIONES
if (ob_get_level()) ob_end_clean();
session_start();

require_once '../includes/verificar_piloto.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.']);
    exit;
}

require_once '../config/database.php';
require_once 'ArbolDecision.php';
require_once 'config_motivos.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No se recibió el archivo.']);
    exit;
}

$observaciones = trim($_POST['observaciones'] ?? '');
$motivo = trim($_POST['motivo'] ?? '');
$id_tienda = isset($_SESSION['id_tienda']) ? (int)$_SESSION['id_tienda'] : 0;
$usuario_tienda = $_SESSION['user_id'] ?? '';

if ($id_tienda <= 0 || empty($usuario_tienda)) {
    echo json_encode(['success' => false, 'message' => 'Datos de sesión incompletos.']);
    exit;
}

if (empty($motivo) || !validarMotivo($motivo)) {
    echo json_encode(['success' => false, 'message' => 'Debe seleccionar un motivo válido antes de cargar el archivo.']);
    exit;
}

try {
    // ==========================================
    // VALIDACIÓN Y SUBIDA DEL PDF PARA MOTIVOS ESPECIALES
    // ==========================================
    $motivos_con_pdf = ['VENTA_CLIENTE_BLACK', 'VENTA_CLIENTE_CONTACTABILIDAD', 'VENTA_CLIENTE_VE'];
    $ruta_pdf = null;

    if (in_array($motivo, $motivos_con_pdf)) {
        if (!isset($_FILES['archivo_venta_pdf']) || $_FILES['archivo_venta_pdf']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'El documento de venta en PDF es obligatorio para este motivo.']);
            exit;
        }
        
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
        if (!is_dir($carpeta_uploads)) {
            mkdir($carpeta_uploads, 0755, true);
        }
        
        $nombre_archivo = 'venta_' . $id_tienda . '_' . time() . '_' . uniqid() . '.pdf';
        $ruta_destino = $carpeta_uploads . $nombre_archivo;
        
        if (!move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
            echo json_encode(['success' => false, 'message' => 'Error del servidor al guardar el documento.']);
            exit;
        }
        
        $ruta_pdf = 'uploads/ventas/' . $nombre_archivo;
    }

    // ==========================================
    // PROCESAMIENTO DEL CSV
    // ==========================================
    $archivo_temp = $_FILES['archivo']['tmp_name'];
    $contenido = file_get_contents($archivo_temp);
    if (substr($contenido, 0, 3) === "\xEF\xBB\xBF") $contenido = substr($contenido, 3);
    
    $delimitador = ',';
    $primeraLinea = strtok($contenido, "\n");
    if (substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',')) $delimitador = ';';
    
    $lineas_csv = str_getcsv($contenido, "\n");
    $headers = str_getcsv($lineas_csv[0], $delimitador);
    $headers = array_map(function($h) { return strtolower(trim(str_replace(["\xEF\xBB\xBF", "\r"], '', $h))); }, $headers);
    
    $idx_sku = array_search('sku', $headers);
    $idx_cantidad = false;
    $idx_descripcion = false;
    foreach ($headers as $i => $h) {
        if (in_array($h, ['cantidad', 'cant', 'carga', 'qty'])) { $idx_cantidad = $i; break; }
    }
    foreach ($headers as $i => $h) {
        if (in_array($h, ['descripcion', 'descripcion_producto', 'producto', 'desc'])) { $idx_descripcion = $i; break; }
    }
    
    if ($idx_sku === false || $idx_cantidad === false) throw new Exception('Columnas SKU o Cantidad no encontradas');
    
    $datos_filas = [];
    $skus_del_archivo = [];
    for ($i = 1; $i < count($lineas_csv); $i++) {
        $row = str_getcsv($lineas_csv[$i], $delimitador);
        if (empty($row)) continue;
        $sku = isset($row[$idx_sku]) ? trim($row[$idx_sku]) : '';
        $cantidad = isset($row[$idx_cantidad]) ? (int)trim($row[$idx_cantidad]) : 0;
        $descripcion = ($idx_descripcion !== false && isset($row[$idx_descripcion])) ? trim($row[$idx_descripcion]) : '';
        if (!$sku || $cantidad <= 0) continue;
        $skus_del_archivo[] = $sku;
        $datos_filas[] = ['sku' => $sku, 'cantidad' => $cantidad, 'descripcion' => $descripcion];
    }
    
    $skus_del_archivo = array_unique($skus_del_archivo);
    if (empty($skus_del_archivo)) throw new Exception('No hay SKUs válidos');
    
    // Validación masiva de pendientes
    $placeholders = implode(',', array_fill(0, count($skus_del_archivo), '?'));
    $sql_val = "SELECT DISTINCT s.id_solicitud, s.estado_general, DATE_FORMAT(s.fecha_solicitud, '%d-%m-%Y %H:%i') as fecha, sc.sku, sc.estado_item
                FROM Analisis_Procesos.solicitudes s
                INNER JOIN Analisis_Procesos.solicitudes_carga sc ON s.id_solicitud = sc.id_solicitud
                WHERE s.usuario_tienda = ? AND sc.sku IN ($placeholders) AND s.estado_general IN ('PENDIENTE', 'EN_PROCESO') ORDER BY s.id_solicitud";
    $params_val = array_merge([$usuario_tienda], $skus_del_archivo);
    $stmt_val = $pdo->prepare($sql_val);
    $stmt_val->execute($params_val);
    $skus_pendientes = $stmt_val->fetchAll();
    
    if (!empty($skus_pendientes)) {
        $skus_conflictos = array_unique(array_map(function($item) { return $item['sku']; }, $skus_pendientes));
        echo json_encode([
            'success' => false,
            'message' => "Los siguientes SKUs ya están en la solicitud **#{$skus_pendientes[0]['id_solicitud']}**: <strong>" . implode(', ', $skus_conflictos) . "</strong>.",
            'tipo_error' => 'SKU_PENDIENTE',
            'skus_conflicto' => $skus_conflictos
        ]);
        exit;
    }

    // ==========================================
    // OPTIMIZADO: AGRUPAR SKUs POR ANALISTA + TIPO (Consolida el trabajo)
    // ==========================================
    $grupos = [];
    $skus_procesados = [];
    $errores_detalle = [];
    
    // ✅ Cargar helper de reemplazos una sola vez al inicio del bucle
    require_once __DIR__ . '/helpers_reemplazos.php';

    foreach ($datos_filas as $fila) {
        $sku = $fila['sku'];
        if (in_array($sku, $skus_procesados)) continue;
        
        // 1. Detectar SOLO la sub_fam desde sugerido_diario
        $sql_info = "SELECT DISTINCT sub_fam FROM rct.sugerido_diario WHERE sku = :sku LIMIT 1";
        $stmt_info = $pdo->prepare($sql_info);
        $stmt_info->execute(['sku' => $sku]);
        $info_sku = $stmt_info->fetch(PDO::FETCH_ASSOC);
        
        if (!$info_sku || empty($info_sku['sub_fam'])) {
            $errores_detalle[] = "SKU {$sku}: No encontrado en sugerido_diario";
            continue;
        }
        
        $id_sub_familia = (int)$info_sku['sub_fam'];
        
        // 2. Buscar familia y analista asignado en la tabla maestra de planificación (MAPEANDO A USERNAME)
        $sql_asignacion = "SELECT p.id_familia, 
                                  COALESCE(u.usuario, TRIM(p.analista_repo_tienda)) as analista_username
                           FROM input_clientes.planificacion_asignacion_equipos p
                           LEFT JOIN rct.usuarios_solicitudes_oc u 
                               ON TRIM(p.analista_repo_tienda) = u.nombre
                           WHERE p.id_sub_familia = :id_sub_familia 
                           LIMIT 1";
        $stmt_asignacion = $pdo->prepare($sql_asignacion);
        $stmt_asignacion->execute(['id_sub_familia' => $id_sub_familia]);
        $row_asignacion = $stmt_asignacion->fetch(PDO::FETCH_ASSOC);
        
        if (!$row_asignacion) {
            $errores_detalle[] = "SKU {$sku}: La subfamilia {$id_sub_familia} no tiene configuración en planificacion_asignacion_equipos";
            continue;
        }
        
        $id_familia = (int)$row_asignacion['id_familia'];
        $analista = trim($row_asignacion['analista_username']);
        
        // ✅ NUEVO: Verificar si el analista asignado está de vacaciones
        $analista = verificarYAplicarReemplazo($pdo, $analista);
        
        // 3. Detectar si es peligroso (ASIGNACIÓN DINÁMICA desde config_analistas_peligrosos)
        $es_peligroso = false;
        $familia_final = $id_familia;
        try {
            $sql_peligroso = "SELECT clase FROM sodimac_grt.codigos_peligrosos WHERE sku = :sku LIMIT 1";
            $stmt_peligroso = $pdo->prepare($sql_peligroso);
            $stmt_peligroso->execute(['sku' => $sku]);
            $prod_peligroso = $stmt_peligroso->fetch(PDO::FETCH_ASSOC);
            
            if ($prod_peligroso && !empty($prod_peligroso['clase'])) {
                $clase = (int)$prod_peligroso['clase'];
                
                $sql_config = "SELECT analista_asignado, id_familia_asociada 
                            FROM Analisis_Procesos.config_analistas_peligrosos 
                            WHERE clase_peligro = :clase AND activo = 1 LIMIT 1";
                $stmt_config = $pdo->prepare($sql_config);
                $stmt_config->execute(['clase' => $clase]);
                $config_peligroso = $stmt_config->fetch(PDO::FETCH_ASSOC);
                
                if ($config_peligroso) {
                    $es_peligroso = true;
                    $familia_final = (int)$config_peligroso['id_familia_asociada'];
                    $analista = trim($config_peligroso['analista_asignado']);
                    
                    // ✅ NUEVO: Verificar si el analista de peligrosos está de vacaciones
                    $analista = verificarYAplicarReemplazo($pdo, $analista);
                    
                    error_log("PELIGROSO CLASE $clase: SKU $sku → Familia $familia_final → Analista final: $analista");
                } else {
                    error_log("⚠️ SIN CONFIGURACIÓN para clase $clase. SKU $sku mantiene analista original.");
                }
            }
        } catch (PDOException $e) {
            error_log("Error verificando productos peligrosos: " . $e->getMessage());
        }
        
        // 4. OPTIMIZACIÓN: Crear clave de agrupación por ANALISTA y TIPO
        $tipo = $es_peligroso ? 'peligroso' : 'normal';
        $grupo_key = "{$analista}_{$tipo}";
        
        if (!isset($grupos[$grupo_key])) {
            $grupos[$grupo_key] = [
                'id_familia' => $familia_final,
                'id_sub_familia' => $id_sub_familia,
                'analista' => $analista,
                'tipo' => $tipo,
                'skus' => []
            ];
        }
        
        $grupos[$grupo_key]['skus'][] = $fila;
        $skus_procesados[] = $sku;
    }

    if (empty($grupos)) {
        echo json_encode([
            'success' => false,
            'message' => 'No se pudieron procesar los SKUs. Verifique que existan en el maestro y tengan subfamilia asignada.',
            'errores' => $errores_detalle
        ]);
        exit;
    }

    // ==========================================
    // CREAR SOLICITUDES POR CADA GRUPO
    // ==========================================
    $solicitudes_creadas = [];
    $detalle = [];
    $total_procesados = 0;
    $total_rechazados = 0;
    
    foreach ($grupos as $grupo_key => $grupo) {
        $sql_padre = "INSERT INTO Analisis_Procesos.solicitudes 
                      (id_tienda, usuario_tienda, id_familia, id_sub_familia, usuario_proceso, estado_general, observaciones_generales, motivo_carga, archivo_venta_pdf, fecha_solicitud) 
                      VALUES (:id_tienda, :usuario_tienda, :id_familia, :id_sub_familia, :usuario_proceso, 'PENDIENTE', :observaciones, :motivo, :ruta_pdf, NOW())";
        
        $stmt_padre = $pdo->prepare($sql_padre);
        $stmt_padre->execute([
            'id_tienda' => $id_tienda,
            'usuario_tienda' => $usuario_tienda,
            'id_familia' => $grupo['id_familia'],
            'id_sub_familia' => $grupo['id_sub_familia'],
            'usuario_proceso' => $grupo['analista'], // ✅ Guarda el username (o el del reemplazo)
            'observaciones' => $observaciones,
            'motivo' => $motivo,
            'ruta_pdf' => $ruta_pdf
        ]);
        
        $id_solicitud_padre = $pdo->lastInsertId();
        if (!$id_solicitud_padre) continue;
        
        $detalles_guardados_grupo = 0;
        $detalles_rechazados_grupo = 0;
        
        foreach ($grupo['skus'] as $fila) {
            $sku = $fila['sku'];
            $cantidad_original = $fila['cantidad'];
            $descripcion = $fila['descripcion'];
            
            if (!$descripcion) {
                $sql_prod = "SELECT descripcion_producto FROM sodimac_grt.productos_maestro WHERE sku = :sku LIMIT 1";
                $stmt_prod = $pdo->prepare($sql_prod);
                $stmt_prod->execute(['sku' => $sku]);
                $prod = $stmt_prod->fetch();
                $descripcion = $prod ? $prod['descripcion_producto'] : 'Sin descripción';
            }
            
            $sql_sug = "SELECT 
                            ROUND((COALESCE(sd.vta_sem_6, 0) + COALESCE(sd.vta_sem_5, 0) + COALESCE(sd.vta_sem_4, 0) + COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 6, 2) as PV6, 
                            ROUND((COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 3, 2) as pv3,
                            sd.disp as disp_tda, sd.pend as pend_tda, 
                            COALESCE(bod_saldo.disp_bod_calc, 0) as disp_bod, COALESCE(bod_saldo.pend_bod_calc, 0) as pend_bod,
                            sd.MD as MD_sugerido, sd.vta_sem_1, sd.vta_sem_2, sd.vta_sem_3, sd.lead_time_total, 
                            COALESCE(sd.`MIN`, 0) as min_despacho
                        FROM rct.sugerido_diario sd
                        LEFT JOIN (
                            SELECT inv.sku, SUM(inv.saldo_disponible) as disp_bod_calc, SUM(inv.saldo_pendiente) as pend_bod_calc
                            FROM sodimac_grt.inventario_diario inv
                            INNER JOIN sodimac_grt.bodegas b ON inv.id_sucursal = b.id_bodega WHERE b.asigna = 1 GROUP BY inv.sku
                        ) as bod_saldo ON sd.sku = bod_saldo.sku
                        WHERE sd.id_tienda = :id_tienda AND sd.sku = :sku ORDER BY sd.fecha DESC LIMIT 1";
            
            $stmt_sug = $pdo->prepare($sql_sug);
            $stmt_sug->execute(['id_tienda' => $id_tienda, 'sku' => $sku]);
            $datos_sugerido = $stmt_sug->fetch();

            if (in_array($motivo, $motivos_con_pdf) && $ruta_pdf !== null) {
                $evaluacion = [
                    'puede_cargar' => true,
                    'cantidad_autorizada' => $cantidad_original,
                    'nota_aprobacion' => "Aprobación automática por documento de venta (Motivo: $motivo). PDF adjunto.",
                    'motivo_rechazo' => null
                ];
            } else {
                $evaluacion = ArbolDecision::evaluar($sku, $cantidad_original, $datos_sugerido, $pdo);
            }

            $estado_item = $evaluacion['puede_cargar'] ? 'PENDIENTE' : 'RECHAZADO';
            $motivo_detalle = $evaluacion['motivo_rechazo'] ?? '';
            if ($evaluacion['puede_cargar'] && !empty($evaluacion['nota_aprobacion'])) {
                $motivo_detalle = $evaluacion['nota_aprobacion'];
            }
            
            $sql_detalle_insert = "INSERT INTO Analisis_Procesos.solicitudes_carga 
                           (id_solicitud, sku, descripcion_producto, carga_solicitada, carga_final, estado_item, campo_cambios, id_familia) 
                           VALUES (:id_solicitud, :sku, :descripcion, :cantidad_solicitada, :cantidad_final, :estado_item, :motivo, :id_familia)";
            
            $stmt_detalle_insert = $pdo->prepare($sql_detalle_insert);
            $stmt_detalle_insert->execute([
                'id_solicitud' => $id_solicitud_padre,
                'sku' => $sku,
                'descripcion' => $descripcion,
                'cantidad_solicitada' => $cantidad_original,
                'cantidad_final' => $evaluacion['cantidad_autorizada'],
                'estado_item' => $estado_item,
                'motivo' => $motivo_detalle,
                'id_familia' => $familia_final 
            ]);
            
            if ($evaluacion['puede_cargar']) {
                $detalles_guardados_grupo++;
                $total_procesados++;
                $detalle[] = [
                    'sku' => $sku,
                    'descripcion' => $descripcion,
                    'cantidad' => $cantidad_original,
                    'estado' => 'GUARDADO',
                    'nota' => $evaluacion['nota_aprobacion'] ?? ''
                ];
            } else {
                $detalles_rechazados_grupo++;
                $total_rechazados++;
                $detalle[] = [
                    'sku' => $sku,
                    'descripcion' => $descripcion,
                    'cantidad' => $cantidad_original,
                    'estado' => 'RECHAZADO',
                    'motivo' => $motivo_detalle
                ];
            }
        }
        
        $solicitudes_creadas[] = [
            'id' => $id_solicitud_padre,
            'analista' => $grupo['analista'],
            'sub_familia' => $grupo['id_sub_familia'],
            'tipo' => $grupo['tipo'],
            'aprobados' => $detalles_guardados_grupo,
            'rechazados' => $detalles_rechazados_grupo
        ];
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Se crearon " . count($solicitudes_creadas) . " solicitudes automáticamente: $total_procesados aprobados, $total_rechazados rechazados",
        'solicitudes_generadas' => $solicitudes_creadas,
        'total_solicitudes' => count($solicitudes_creadas),
        'procesados' => $total_procesados,
        'rechazados' => $total_rechazados,
        'detalle' => $detalle,
        'errores_detalle' => $errores_detalle
    ]);
    
} catch (Exception $e) {
    error_log("ERROR FINAL: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>