<?php
session_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$termino = trim($_GET['q'] ?? '');

if (strlen($termino) < 2) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    $sql = "SELECT sku, descripcion_producto 
            FROM sodimac_grt.productos_maestro 
            WHERE sku LIKE :termino 
               OR descripcion_producto LIKE :termino2
            LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'termino' => '%' . $termino . '%',
        'termino2' => '%' . $termino . '%'
    ]);
    $productos = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => $productos
    ]);

} catch (PDOException $e) {
    error_log("Error buscar SKU: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
?>