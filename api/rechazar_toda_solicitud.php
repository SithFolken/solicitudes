<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'analista') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id_solicitud = (int)($input['id_solicitud'] ?? 0);
$motivo = trim($input['motivo'] ?? '');

if ($id_solicitud <= 0 || empty($motivo)) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Verificar que la solicitud existe y no está ya finalizada
    $sql_check = "SELECT estado_general FROM Analisis_Procesos.solicitudes WHERE id_solicitud = :id";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute(['id' => $id_solicitud]);
    $solicitud = $stmt_check->fetch();

    if (!$solicitud) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit;
    }

    if (in_array($solicitud['estado_general'], ['PROCESADA', 'PROCESADA_PARCIAL', 'RECHAZADA'])) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => "La solicitud ya está en estado '{$solicitud['estado_general']}'"]);
        exit;
    }

    // 2. Rechazar TODOS los items pendientes/aprobados de la solicitud
    $sql_update_items = "UPDATE Analisis_Procesos.solicitudes_carga 
                         SET estado_item = 'RECHAZADO',
                             campo_cambios = CONCAT(COALESCE(campo_cambios, ''), ' | RECHAZO TOTAL: ', :motivo),
                             carga_final = 0
                         WHERE id_solicitud = :id_solicitud";
    
    $stmt_items = $pdo->prepare($sql_update_items);
    $stmt_items->execute(['motivo' => $motivo, 'id_solicitud' => $id_solicitud]);
    $items_rechazados = $stmt_items->rowCount();

    // 3. Cambiar estado general de la solicitud a RECHAZADA
    $sql_update_padre = "UPDATE Analisis_Procesos.solicitudes 
                         SET estado_general = 'RECHAZADA',
                             fecha_procesamiento = NOW(),
                             usuario_proceso = :usuario,
                             observaciones_generales = CONCAT(COALESCE(observaciones_generales, ''), ' | RECHAZO TOTAL: ', :motivo)
                         WHERE id_solicitud = :id_solicitud";
    
    $stmt_padre = $pdo->prepare($sql_update_padre);
    $stmt_padre->execute(['usuario' => $_SESSION['user_id'], 'motivo' => $motivo, 'id_solicitud' => $id_solicitud]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Solicitud #$id_solicitud rechazada correctamente. $items_rechazados items marcados como rechazados.",
        'items_rechazados' => $items_rechazados
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error rechazando solicitud: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al rechazar: ' . $e->getMessage()]);
}
?>