<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'analista') {
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id_solicitud = isset($data['id_solicitud']) ? (int)$data['id_solicitud'] : 0;

if ($id_solicitud > 0) {
    $_SESSION['solicitud_activa'] = $id_solicitud;
    
    // Debug: guardar en log
    error_log("Sesión guardada: solicitud_activa = " . $id_solicitud);
    
    echo json_encode(['success' => true, 'message' => 'Sesión de procesamiento iniciada']);
} else {
    echo json_encode(['success' => false, 'message' => 'ID de solicitud inválido']);
}
?>