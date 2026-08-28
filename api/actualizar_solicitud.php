<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
    exit;
}

require_once '../config/database.php';
require_once 'ArbolDecision.php';
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
$id_solicitud = (int)($data['id_solicitud'] ?? 0);
$observaciones = trim($data['observaciones'] ?? '');
$nuevos_skus = $data['skus'] ?? [];
$usuario_tienda = $_SESSION['user_id'];
$id_tienda = (int)$_SESSION['id_tienda'];

if (empty($nuevos_skus)) {
    echo json_encode(['success' => false, 'message' => 'Debe incluir al menos un SKU']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. EL CANDADO DE CONCURRENCIA: Verificar que sigue PENDIENTE
    $sql_check = "SELECT id_familia FROM Analisis_Procesos.solicitudes 
                  WHERE id_solicitud = :id AND usuario_tienda = :usuario AND estado_general = 'PENDIENTE' FOR UPDATE";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute(['id' => $id_solicitud, 'usuario' => $usuario_tienda]);
    $solicitud = $stmt_check->fetch();

    if (!$solicitud) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'No se puede modificar: La solicitud ya está siendo procesada por un analista o fue eliminada.']);
        exit;
    }

    $id_familia = $solicitud['id_familia'];

    // 2. Borrar los detalles antiguos
    $stmt_del = $pdo->prepare("DELETE FROM Analisis_Procesos.solicitudes_carga WHERE id_solicitud = :id");
    $stmt_del->execute(['id' => $id_solicitud]);

    // 3. Insertar los nuevos detalles pasando por el Árbol de Decisión
    $detalles_guardados = 0;
    $detalles_rechazados = 0;

    foreach ($nuevos_skus as $sku_data) {
        $sku = trim($sku_data['sku']);
        $cantidad = (int)$sku_data['cantidad'];
        
        if (!$sku || $cantidad <= 0) continue;

        // Obtener datos del sugerido
        $sql_sug = "SELECT ROUND((COALESCE(vta_sem_3,0)+COALESCE(vta_sem_2,0)+COALESCE(vta_sem_1,0))/3, 2) as PV6, disp as disp_tda, pend as pend_tda, disp_bod, pend_bod, MD as MD_sugerido FROM rct.sugerido_diario WHERE id_tienda = :id_tienda AND sku = :sku ORDER BY fecha DESC LIMIT 1";
        $stmt_sug = $pdo->prepare($sql_sug);
        $stmt_sug->execute(['id_tienda' => $id_tienda, 'sku' => $sku]);
        $datos_sugerido = $stmt_sug->fetch();

        // EVALUAR CON EL ÁRBOL
        $evaluacion = ArbolDecision::evaluar($sku, $cantidad, $datos_sugerido, $pdo);
        
        $estado_item = $evaluacion['puede_cargar'] ? 'PENDIENTE' : 'RECHAZADO';
        $motivo = $evaluacion['puede_cargar'] ? '' : $evaluacion['motivo_rechazo'];

        $sql_insert = "INSERT INTO Analisis_Procesos.solicitudes_carga (id_solicitud, sku, descripcion_producto, carga_solicitada, carga_final, estado_item, campo_cambios, id_familia) VALUES (:id, :sku, :desc, :cant, 0, :estado, :motivo, :fam)";
        $stmt_insert = $pdo->prepare($sql_insert);
        $stmt_insert->execute([
            'id' => $id_solicitud,
            'sku' => $sku,
            'desc' => $sku_data['descripcion'] ?? 'Sin descripción',
            'cant' => $cantidad,
            'estado' => $estado_item,
            'motivo' => $motivo,
            'fam' => $id_familia
        ]);

        if ($evaluacion['puede_cargar']) $detalles_guardados++;
        else $detalles_rechazados++;
    }

    // 4. Actualizar estado del padre (si todo fue rechazado, cambia a RECHAZADA, si no, sigue PENDIENTE)
    $nuevo_estado = ($detalles_guardados > 0) ? 'PENDIENTE' : 'RECHAZADA';
    
    $sql_update_padre = "UPDATE Analisis_Procesos.solicitudes SET estado_general = :estado, observaciones_generales = :obs WHERE id_solicitud = :id";
    $pdo->prepare($sql_update_padre)->execute(['estado' => $nuevo_estado, 'obs' => $observaciones, 'id' => $id_solicitud]);

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => "Solicitud actualizada. $detalles_guardados aprobados, $detalles_rechazados rechazados por el sistema.",
        'procesados' => $detalles_guardados
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error actualizar solicitud: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al actualizar: ' . $e->getMessage()]);
}
?>