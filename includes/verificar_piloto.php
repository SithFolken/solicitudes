<?php
// Este archivo protege las APIs críticas por si alguien intenta saltarse el login
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'tienda') {
    require_once __DIR__ . '/../api/validar_piloto.php';
    require_once __DIR__ . '/../config/database.php';
    
    $id_tienda = (int)($_SESSION['id_tienda'] ?? 0);
    
    if (!validarAccesoPiloto($pdo, $id_tienda)) {
        session_destroy(); // Destruye la sesión por seguridad
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Acceso denegado: Tu tienda no está habilitada para el piloto.']);
        exit;
    }
}
?>