<?php
session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] !== 'analista' && $_SESSION['rol'] !== 'ejecutor')) {
    header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'message' => 'Acceso denegado'));
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['id_solicitud']) || !isset($input['estado'])) {
        echo json_encode(array('success' => false, 'message' => 'Datos inválidos. Faltan parámetros.'));
        exit;
    }

    $id_solicitud = (int)$input['id_solicitud'];
    $nuevo_estado = strtoupper(trim($input['nuevo_estado'] ?? $input['estado'] ?? ''));
    $observaciones = trim(isset($input['observaciones']) ? $input['observaciones'] : '');

    $estados_validos = array('PENDIENTE', 'APROBADA', 'EN_PROCESO', 'PROCESADA', 'PROCESADA_PARCIAL', 'RECHAZADA');
    if (!in_array($nuevo_estado, $estados_validos)) {
        echo json_encode(array('success' => false, 'message' => "Estado '{$nuevo_estado}' no es válido."));
        exit;
    }

    // 1. Verificar que la solicitud existe y obtener su estado actual
    $sql_check = "SELECT id_solicitud, estado_general 
                  FROM Analisis_Procesos.solicitudes 
                  WHERE id_solicitud = :id_solicitud 
                  LIMIT 1";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute(array('id_solicitud' => $id_solicitud));
    $solicitud = $stmt_check->fetch();

    if (!$solicitud) {
        echo json_encode(array('success' => false, 'message' => "La solicitud #{$id_solicitud} no existe"));
        exit;
    }

    // 2. BLOQUEAR CAMBIOS SI YA ESTÁ EN ESTADO FINAL
    $estado_actual_db = $solicitud['estado_general'];
    $estados_finales = array('PROCESADA', 'PROCESADA_PARCIAL', 'RECHAZADA');

    if (in_array($estado_actual_db, $estados_finales)) {
        echo json_encode(array(
            'success' => false, 
            'message' => "No se puede cambiar el estado: la solicitud ya está en estado '{$estado_actual_db}' y no puede ser modificada."
        ));
        exit;
    }

    // 3. Actualizar el estado general del PADRE
    $sql = "UPDATE Analisis_Procesos.solicitudes 
            SET estado_general = :estado_nuevo,
                fecha_procesamiento = CASE 
                    WHEN :estado_check IN ('PROCESADA', 'PROCESADA_PARCIAL', 'RECHAZADA') THEN NOW()
                    ELSE fecha_procesamiento
                END,
                observaciones_generales = CASE 
                    WHEN :obs_length > 0 THEN CONCAT(IFNULL(observaciones_generales, ''), ' | ', :observaciones)
                    ELSE observaciones_generales
                END
            WHERE id_solicitud = :id_solicitud";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        'estado_nuevo' => $nuevo_estado,
        'estado_check' => $nuevo_estado,
        'obs_length' => strlen($observaciones),
        'observaciones' => $observaciones,
        'id_solicitud' => $id_solicitud
    ));

    // 4. ✅ PROPAGAR EL ESTADO A TODOS LOS ITEMS HIJOS
    // Si el estado es RECHAZADA, todos los items se marcan como RECHAZADO
    // Si es APROBADA, todos los items se marcan como APROBADO
    // Si es EN_PROCESO o PENDIENTE, no cambiamos los items individuales
    if (in_array($nuevo_estado, array('RECHAZADA', 'APROBADA'))) {
        $estado_item = ($nuevo_estado === 'RECHAZADA') ? 'RECHAZADO' : 'APROBADO';
        
        $sql_items = "UPDATE Analisis_Procesos.solicitudes_carga 
                      SET estado_item = :estado_item 
                      WHERE id_solicitud = :id_solicitud";
        $stmt_items = $pdo->prepare($sql_items);
        $stmt_items->execute(array(
            'estado_item' => $estado_item,
            'id_solicitud' => $id_solicitud
        ));
    }

    echo json_encode(array(
        'success' => true,
        'message' => "Estado cambiado exitosamente a {$nuevo_estado}",
        'estado' => $nuevo_estado
    ));

} catch (PDOException $e) {
    error_log("Error cambiar estado: " . $e->getMessage());
    echo json_encode(array(
        'success' => false, 
        'message' => 'Error de base de datos: ' . $e->getMessage()
    ));
} catch (Exception $e) {
    error_log("Error general cambiar estado: " . $e->getMessage());
    echo json_encode(array(
        'success' => false, 
        'message' => 'Error del servidor: ' . $e->getMessage()
    ));
}
?>