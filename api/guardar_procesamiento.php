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
$id_solicitud = $input['id_solicitud'] ?? 0;
$items = $input['items'] ?? [];
$procesar = $input['procesar'] ?? true;

if ($id_solicitud <= 0 || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    $skus_procesados = 0;
    $skus_aprobados = 0;
    $skus_rechazados = 0;
    
    foreach ($items as $item) {
        $id_detalle = $item['id_detalle'];
        $cantidad_final = $item['cantidad_final'];
        $md = $item['md'];
        $no_cargar = $item['no_cargar'];
        $motivo = trim($item['motivo']);
        
        if ($no_cargar) {
            $estado_item = 'RECHAZADO';
            $skus_rechazados++;
        } else {
            $estado_item = 'APROBADO';
            $skus_aprobados++;
        }
        
        // ✅ CORREGIDO: Usar placeholders únicos (:motivo1 y :motivo2) para evitar el error HY093
        $sql_update = "UPDATE Analisis_Procesos.solicitudes_carga 
                       SET carga_final = :cantidad_final,
                           md = :md,
                           estado_item = :estado_item,
                           campo_cambios = CASE 
                               WHEN :motivo1 != '' THEN CONCAT(COALESCE(campo_cambios, ''), ' | ', :motivo2)
                               ELSE campo_cambios
                           END
                       WHERE id_detalle = :id_detalle";
        
        $stmt = $pdo->prepare($sql_update);
        $stmt->execute([
            'cantidad_final' => $cantidad_final,
            'md' => $md,
            'estado_item' => $estado_item,
            'motivo1' => $motivo, // ✅ Placeholder único 1
            'motivo2' => $motivo, // ✅ Placeholder único 2
            'id_detalle' => $id_detalle
        ]);
        
        $skus_procesados++;
    }
    
    // ✅ DETERMINAR ESTADO Y ACTUALIZAR PADRE
    if ($procesar) {
        if ($skus_rechazados > 0 && $skus_aprobados === 0) {
            $estado_general = 'RECHAZADA';
        } elseif ($skus_rechazados > 0 && $skus_aprobados > 0) {
            $estado_general = 'PROCESADA_PARCIAL';
        } else {
            $estado_general = 'PROCESADA';
        }
        
        // Calcular ciclo de corte
        $hora_actual = (int)date('H');
        $ciclo_corte = ($hora_actual < 13) ? date('Y-m-d') : date('Y-m-d', strtotime('+1 day'));
        
        $sql_padre = "UPDATE Analisis_Procesos.solicitudes 
                      SET estado_general = :estado_general,
                          fecha_procesamiento = NOW(),
                          usuario_proceso = :usuario,
                          ciclo_corte = :ciclo_corte
                      WHERE id_solicitud = :id_solicitud";
        
        $stmt_padre = $pdo->prepare($sql_padre);
        $stmt_padre->execute([
            'estado_general' => $estado_general,
            'usuario' => $_SESSION['user_id'],
            'ciclo_corte' => $ciclo_corte,
            'id_solicitud' => $id_solicitud
        ]);
        
        $mensaje = "Solicitud #$id_solicitud procesada. Estado: $estado_general ($skus_aprobados aprobados, $skus_rechazados rechazados)";
    } else {
        // Modo: SOLO GUARDAR (sin cambiar estado)
        $sql_padre = "UPDATE Analisis_Procesos.solicitudes 
                      SET fecha_modificacion = NOW()
                      WHERE id_solicitud = :id_solicitud";
        
        $stmt_padre = $pdo->prepare($sql_padre);
        $stmt_padre->execute([
            'id_solicitud' => $id_solicitud
        ]);
        
        $mensaje = "Cambios guardados. $skus_aprobados aprobados, $skus_rechazados rechazados. La solicitud permanece en su estado actual.";
    }
        
    $pdo->commit();
    
    // Limpiar sesión al finalizar exitosamente
    unset($_SESSION['solicitud_activa']);
    
    echo json_encode([
        'success' => true,
        'message' => $mensaje,
        'skus_procesados' => $skus_procesados,
        'procesado' => $procesar
    ]);
        
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error guardando cambios: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al guardar: ' . $e->getMessage()]);
}
?>