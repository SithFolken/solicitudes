<?php
session_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'ejecutor') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_solicitud = (int)($_POST['id_solicitud'] ?? 0);
    $carga_final = (int)($_POST['carga_final'] ?? 0);
    $md = trim($_POST['md'] ?? '');
    $mid = (float)($_POST['mid'] ?? 0);
    $hubo_cambios = (int)($_POST['hubo_cambios'] ?? 0);
    $campo_cambios = trim($_POST['campo_cambios'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    $usuario_proceso = $_SESSION['user_id'];

    try {
        // Si no hubo cambios, la carga final es la solicitada
        if ($hubo_cambios == 0) {
            $sql = "SELECT carga_solicitada FROM Analisis_Procesos.solicitudes_carga WHERE id_solicitud = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id_solicitud]);
            $solicitud = $stmt->fetch();
            
            if ($solicitud) {
                $carga_final = $solicitud['carga_solicitada'];
            }
        }

        // Actualizar solicitud
        $sql = "UPDATE Analisis_Procesos.solicitudes_carga 
                SET 
                    carga_final = :carga_final,
                    md = :md,
                    mid = :mid,
                    campo_cambios = :campo_cambios,
                    observaciones = :observaciones,
                    estado_solicitud = 'PROCESADA',
                    fecha_procesamiento = NOW(),
                    usuario_proceso = :usuario_proceso
                WHERE id_solicitud = :id_solicitud";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'carga_final' => $carga_final,
            'md' => $md,
            'mid' => $mid,
            'campo_cambios' => $campo_cambios,
            'observaciones' => $observaciones,
            'usuario_proceso' => $usuario_proceso,
            'id_solicitud' => $id_solicitud
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Carga procesada correctamente'
        ]);

    } catch (PDOException $e) {
        error_log("Error procesar carga: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error del servidor']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
}
?>