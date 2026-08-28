<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
$id_solicitud = (int)($data['id_solicitud'] ?? 0);
$usuario_tienda = $_SESSION['user_id'];

try {
    // El "Candado": Solo borra si es PENDIENTE y pertenece al usuario
    $sql = "DELETE s, sc 
            FROM Analisis_Procesos.solicitudes s
            LEFT JOIN Analisis_Procesos.solicitudes_carga sc ON s.id_solicitud = sc.id_solicitud
            WHERE s.id_solicitud = :id 
              AND s.usuario_tienda = :usuario 
              AND s.estado_general = 'PENDIENTE'";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id_solicitud, 'usuario' => $usuario_tienda]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Solicitud eliminada correctamente']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se pudo eliminar. La solicitud ya está siendo procesada o no existe.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error del servidor']);
}
?>