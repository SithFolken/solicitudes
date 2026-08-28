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

try {
    // OBTENER SOLO FAMILIAS QUE TIENEN ANALISTAS ASIGNADOS
    // Esto evita que la tienda seleccione una familia que nadie va a revisar
    $sql = "SELECT DISTINCT 
                f.id_familia,
                f.descripcion_familia
            FROM sodimac_grt.familias f
            INNER JOIN FulFillment.distribucion_analista_familia d 
                ON f.id_familia = d.Familia
            ORDER BY f.id_familia ASC";
    
    $stmt = $pdo->query($sql);
    $familias = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => $familias
    ]);

} catch (PDOException $e) {
    error_log("Error obtener familias: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
?>