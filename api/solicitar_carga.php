<?php
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
require_once 'config_motivos.php'; // ✅ Incluir configuración de motivos

header('Content-Type: application/json; charset=utf-8');

error_log("=== INICIO SOLICITUD MANUAL ===");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (is_array($input)) {
    $sku = trim($input['sku'] ?? '');
    $cantidad = (int)($input['cantidad'] ?? 0);
    $descripcion = trim($input['descripcion'] ?? '');
    $id_familia = trim($input['id_familia'] ?? '');
    $observaciones = trim($input['observaciones'] ?? '');
    $motivo = trim($input['motivo'] ?? ''); // ✅ NUEVO
} else {
    $sku = trim($_POST['sku'] ?? '');
    $cantidad = (int)($_POST['cantidad'] ?? 0);
    $descripcion = trim($_POST['descripcion'] ?? '');
    $id_familia = trim($_POST['id_familia'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    $motivo = trim($_POST['motivo'] ?? ''); // ✅ NUEVO
}

$id_tienda = isset($_SESSION['id_tienda']) ? (int)$_SESSION['id_tienda'] : 0;
$usuario_tienda = $_SESSION['user_id'] ?? '';
$nombre_tienda = $_SESSION['nombre'] ?? 'Tienda';

if (!$sku || $cantidad <= 0) {
    echo json_encode(['success' => false, 'message' => 'El SKU y la cantidad son obligatorios']);
    exit;
}

if (!$id_familia) {
    echo json_encode(['success' => false, 'message' => 'La familia del producto es obligatoria']);
    exit;
}

// ✅ VALIDACIÓN DE MOTIVO
if (empty($motivo) || !validarMotivo($motivo)) {
    echo json_encode(['success' => false, 'message' => 'Debe seleccionar un motivo válido para la carga.']);
    exit;
}

if ($id_tienda <= 0 || empty($usuario_tienda)) {
    echo json_encode(['success' => false, 'message' => 'Error de sesión: No se pudo identificar tu tienda.']);
    exit;
}

try {
    // Validación de SKU pendiente (código existente)
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

    // Obtener datos del sugerido
    $sql_sug = "SELECT ROUND((COALESCE(vta_sem_3, 0) + COALESCE(vta_sem_2, 0) + COALESCE(vta_sem_1, 0)) / 3, 2) as PV6, disp as disp_tda, pend as pend_tda, disp_bod, pend_bod, MD as MD_sugerido, vta_sem_1, vta_sem_2, vta_sem_3, lead_time_total, COALESCE(`MIN`, 0) as min_despacho
                FROM rct.sugerido_diario WHERE id_tienda = :id_tienda AND sku = :sku ORDER BY fecha DESC LIMIT 1";
    $stmt_sug = $pdo->prepare($sql_sug);
    $stmt_sug->execute(['id_tienda' => $id_tienda, 'sku' => $sku]);
    $datos_sugerido = $stmt_sug->fetch();

    // Evaluar con Árbol de Decisión
    $evaluacion = ArbolDecision::evaluar($sku, $cantidad, $datos_sugerido, $pdo);
    if (!$evaluacion['puede_cargar']) {
        echo json_encode(['success' => false, 'message' => $evaluacion['motivo_rechazo']]);
        exit;
    }

    // CREAR SOLICITUD PADRE (con motivo)
    $sql_padre = "INSERT INTO Analisis_Procesos.solicitudes 
                  (id_tienda, usuario_tienda, id_familia, estado_general, observaciones_generales, motivo_carga, fecha_solicitud) 
                  VALUES (:id_tienda, :usuario_tienda, :id_familia, 'PENDIENTE', :observaciones, :motivo, NOW())";
    
    $stmt_padre = $pdo->prepare($sql_padre);
    $stmt_padre->execute([
        'id_tienda' => $id_tienda,
        'usuario_tienda' => $usuario_tienda,
        'id_familia' => $id_familia,
        'observaciones' => $observaciones,
        'motivo' => $motivo // ✅ GUARDAR MOTIVO
    ]);
    
    $id_solicitud_padre = $pdo->lastInsertId();
    if (!$id_solicitud_padre || $id_solicitud_padre <= 0) throw new Exception('No se pudo crear la solicitud padre');

    // CREAR DETALLE
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

    // (Código de envío de correos se mantiene igual...)
    
    echo json_encode([
        'success' => true,
        'message' => 'Solicitud creada correctamente. Estado: PENDIENTE',
        'id_solicitud' => $id_solicitud_padre
    ]);

} catch (PDOException $e) {
    error_log("Error PDO: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error general: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>