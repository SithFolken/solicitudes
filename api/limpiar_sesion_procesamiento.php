<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'analista') {
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

// Limpiar la sesión de procesamiento
unset($_SESSION['solicitud_activa']);

echo json_encode(['success' => true, 'message' => 'Sesión limpiada']);
?>