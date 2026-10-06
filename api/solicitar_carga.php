<?php
// 1. Limpiar output buffer
if (ob_get_level()) {
    ob_end_clean();
}

// 2. Iniciar sesión
session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Verificar autenticación
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
    exit; 
}

// 4. Incluir dependencias
require_once '../config/database.php';
require_once 'ArbolDecision.php';
require_once 'EmailService.php';

header('Content-Type: application/json; charset=utf-8');

// 5. Logs iniciales
error_log("=== INICIO SOLICITUD MANUAL ===");
error_log("Usuario: " . ($_SESSION['user_id'] ?? 'NULL'));
error_log("ID Tienda: " . ($_SESSION['id_tienda'] ?? 'NULL'));

// 6. Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// ✅ 7. CORRECCIÓN: Leer datos (puede venir como JSON o POST tradicional)
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

// Si viene como JSON (desde el JS)
if (is_array($input)) {
    $sku = trim($input['sku'] ?? '');
    $cantidad = (int)($input['cantidad'] ?? 0);
    $descripcion = trim($input['descripcion'] ?? '');
    $id_familia = trim($input['id_familia'] ?? '');
    $observaciones = trim($input['observaciones'] ?? '');
} else {
    // Si viene como POST tradicional
    $sku = trim($_POST['sku'] ?? '');
    $cantidad = (int)($_POST['cantidad'] ?? 0);
    $descripcion = trim($_POST['descripcion'] ?? '');
    $id_familia = trim($_POST['id_familia'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
}

$id_tienda = isset($_SESSION['id_tienda']) ? (int)$_SESSION['id_tienda'] : 0;
$usuario_tienda = $_SESSION['user_id'] ?? '';
$nombre_tienda = $_SESSION['nombre'] ?? 'Tienda';

error_log("Datos recibidos - SKU: $sku, Cantidad: $cantidad, Familia: $id_familia");

// 8. Validaciones básicas
if (!$sku || $cantidad <= 0) {
    error_log("ERROR VALIDACIÓN: SKU='$sku', Cantidad=$cantidad");
    echo json_encode(['success' => false, 'message' => 'El SKU y la cantidad son obligatorios']);
    exit;
}

if (!$id_familia) {
    echo json_encode(['success' => false, 'message' => 'La familia del producto es obligatoria']);
    exit;
}

if ($id_tienda <= 0 || empty($usuario_tienda)) {
    error_log("ERROR: ID Tienda=$id_tienda, Usuario=$usuario_tienda");
    echo json_encode(['success' => false, 'message' => 'Error de sesión: No se pudo identificar tu tienda.']);
    exit;
}

try {
    // ==========================================
    // VALIDACIÓN: Verificar si el SKU ya está en una solicitud pendiente
    // ==========================================
    error_log("VALIDACIÓN INICIANDO - SKU: $sku, Usuario: $usuario_tienda");
    
    try {
        $sql_val = "SELECT 
                        s.id_solicitud,
                        s.estado_general,
                        DATE_FORMAT(s.fecha_solicitud, '%d-%m-%Y %H:%i') as fecha,
                        sc.sku,
                        sc.estado_item
                    FROM Analisis_Procesos.solicitudes s
                    INNER JOIN Analisis_Procesos.solicitudes_carga sc 
                        ON s.id_solicitud = sc.id_solicitud
                    WHERE s.usuario_tienda = :usuario 
                      AND sc.sku = :sku
                      AND s.estado_general IN ('PENDIENTE', 'EN_PROCESO')
                    LIMIT 5";
        
        error_log("SQL Validación: $sql_val");
        
        $stmt_val = $pdo->prepare($sql_val);
        $stmt_val->execute(['usuario' => $usuario_tienda, 'sku' => $sku]);
        $resultados_val = $stmt_val->fetchAll();
        
        error_log("Resultados validación: " . count($resultados_val) . " registros encontrados");
        
        foreach ($resultados_val as $reg) {
            error_log("  - Solicitud #{$reg['id_solicitud']}, Estado: {$reg['estado_general']}, Item Estado: {$reg['estado_item']}");
        }
        
        // Filtrar solo los que están PENDIENTE
        $pendientes = array_filter($resultados_val, function($r) {
            return $r['estado_item'] === 'PENDIENTE' || in_array($r['estado_general'], ['PENDIENTE', 'EN_PROCESO']);
        });
        
        if (!empty($pendientes)) {
            $solicitud_pendiente = array_values($pendientes)[0];
            
            error_log("BLOQUEADO - SKU $sku ya está en solicitud #{$solicitud_pendiente['id_solicitud']}");
            
            echo json_encode([
                'success' => false,
                'message' => "El SKU **{$sku}** ya está en la solicitud **#{$solicitud_pendiente['id_solicitud']}** (estado: **{$solicitud_pendiente['estado_general']}**, creada el {$solicitud_pendiente['fecha']}). Espera a que sea procesada antes de solicitarlo nuevamente.",
                'tipo_error' => 'SKU_PENDIENTE',
                'id_solicitud_existente' => $solicitud_pendiente['id_solicitud']
            ]);
            exit;
        }
        
        error_log("VALIDACIÓN PASÓ - SKU $sku no está pendiente");
        
    } catch (PDOException $e_val) {
        error_log("ERROR en validación: " . $e_val->getMessage());
    }

    // ==========================================
    // OBTENER DATOS DEL SUGERIDO DIARIO
    // ==========================================
        $sql_sug = "SELECT 
                    ROUND((COALESCE(vta_sem_3, 0) + COALESCE(vta_sem_2, 0) + COALESCE(vta_sem_1, 0)) / 3, 2) as PV6,
                    disp as disp_tda, 
                    pend as pend_tda, 
                    disp_bod, 
                    pend_bod, 
                    MD as MD_sugerido,
                    -- ✅ AGREGADO: Columnas necesarias para el Árbol de Decisión (Bypass y Lead Time)
                    vta_sem_1, vta_sem_2, vta_sem_3,
                    lead_time_total
                FROM rct.sugerido_diario 
                WHERE id_tienda = :id_tienda AND sku = :sku
                ORDER BY fecha DESC LIMIT 1";
    
    $stmt_sug = $pdo->prepare($sql_sug);
    $stmt_sug->execute(['id_tienda' => $id_tienda, 'sku' => $sku]);
    $datos_sugerido = $stmt_sug->fetch();

    // ==========================================
    // EVALUAR CON EL ÁRBOL DE DECISIÓN
    // ==========================================
    $evaluacion = ArbolDecision::evaluar($sku, $cantidad, $datos_sugerido, $pdo);

    if (!$evaluacion['puede_cargar']) {
        echo json_encode([
            'success' => false, 
            'message' => $evaluacion['motivo_rechazo']
        ]);
        exit;
    }

    // ==========================================
    // CREAR SOLICITUD PADRE
    // ==========================================
    error_log("CREANDO SOLICITUD PADRE");
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql_padre = "INSERT INTO Analisis_Procesos.solicitudes 
                  (id_tienda, usuario_tienda, id_familia, estado_general, observaciones_generales, fecha_solicitud) 
                  VALUES (:id_tienda, :usuario_tienda, :id_familia, 'PENDIENTE', :observaciones, NOW())";
    
    $stmt_padre = $pdo->prepare($sql_padre);
    $stmt_padre->execute([
        'id_tienda' => $id_tienda,
        'usuario_tienda' => $usuario_tienda,
        'id_familia' => $id_familia,
        'observaciones' => $observaciones
    ]);
    
    $id_solicitud_padre = $pdo->lastInsertId();
    
    error_log("ID PADRE CREADO: $id_solicitud_padre");
    
    if (!$id_solicitud_padre || $id_solicitud_padre <= 0) {
        throw new Exception('No se pudo crear la solicitud padre');
    }

    // ==========================================
    // CREAR DETALLE (HIJO)
    // ==========================================
    error_log("CREANDO DETALLE - Padre ID: $id_solicitud_padre, SKU: $sku");
    
    $estado_item = 'PENDIENTE';
    $motivo = '';
    
    $sql_detalle = "INSERT INTO Analisis_Procesos.solicitudes_carga 
                   (id_solicitud, sku, descripcion_producto, carga_solicitada, carga_final, estado_item, campo_cambios, id_familia) 
                   VALUES (:id_solicitud, :sku, :descripcion, :cantidad, 0, :estado_item, :motivo, :id_familia)";
    
    $stmt_detalle = $pdo->prepare($sql_detalle);
    $stmt_detalle->execute([
        'id_solicitud' => $id_solicitud_padre,
        'sku' => $sku,
        'descripcion' => $descripcion,
        'cantidad' => $cantidad,
        'estado_item' => $estado_item,
        'motivo' => $motivo,
        'id_familia' => $id_familia
    ]);
    
    if ($stmt_detalle->rowCount() === 0) {
        throw new Exception('No se pudo guardar el detalle');
    }
    
    $id_detalle = $pdo->lastInsertId();
    error_log("Detalle creado - ID: $id_detalle, id_solicitud: $id_solicitud_padre");

    // ==========================================
    // ENVÍO DE CORREOS
    // ==========================================
    try {
        $sql_analista = "SELECT u.email, u.nombre 
                         FROM rct.usuarios_solicitudes_oc u
                         INNER JOIN FulFillment.distribucion_analista_familia d ON u.codigo_asignacion = d.codigo_asignacion
                         WHERE d.Familia = :familia
                         LIMIT 1";
        $stmt_analista = $pdo->prepare($sql_analista);
        $stmt_analista->execute(['familia' => $id_familia]);
        $analista = $stmt_analista->fetch();

        if ($analista && !empty($analista['email'])) {
            EmailService::notificarNuevaSolicitud(
                $analista['email'],
                $analista['nombre'] ?? 'Analista',
                [
                    'id_solicitud' => $id_solicitud_padre,
                    'id_familia' => $id_familia,
                    'nombre_tienda' => $nombre_tienda,
                    'usuario_tienda' => $usuario_tienda,
                    'sku' => $sku,
                    'descripcion_producto' => $descripcion,
                    'carga_solicitada' => $cantidad,
                    'fecha_solicitud' => date('Y-m-d H:i:s')
                ]
            );
        }

        $sql_tienda = "SELECT email, nombre_usuario FROM rst_central.usuarios WHERE id_tienda = :id_tienda AND id_usuario = :usuario_tienda LIMIT 1";
        $stmt_tienda = $pdo->prepare($sql_tienda);
        $stmt_tienda->execute(['id_tienda' => $id_tienda, 'usuario_tienda' => $usuario_tienda]);
        $datos_tienda = $stmt_tienda->fetch();
        
        $email_tienda = $datos_tienda['email'] ?? ($_SESSION['email'] ?? 'tienda@sodimac.cl');
        $nombre_tienda_db = $datos_tienda['nombre_usuario'] ?? $nombre_tienda;

        EmailService::notificarSolicitudCreada(
            $email_tienda,
            $nombre_tienda_db,
            [
                'id_solicitud' => $id_solicitud_padre,
                'sku' => $sku,
                'descripcion_producto' => $descripcion,
                'carga_solicitada' => $cantidad,
                'fecha_solicitud' => date('Y-m-d H:i:s')
            ]
        );
    } catch (Exception $e_mail) {
        error_log("Error enviando correos: " . $e_mail->getMessage());
    }

    // ==========================================
    // RESPUESTA EXITOSA
    // ==========================================
    error_log("ÉXITO: Solicitud #$id_solicitud_padre creada para SKU: $sku");
    
    echo json_encode([
        'success' => true,
        'message' => 'Solicitud creada correctamente. Estado: PENDIENTE',
        'id_solicitud' => $id_solicitud_padre
    ]);

} catch (PDOException $e) {
    error_log("Error PDO: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos']);
} catch (Exception $e) {
    error_log("Error general: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>