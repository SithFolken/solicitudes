<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// Validar que esté logueado (tienda O analista)
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$id_solicitud = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_solicitud <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

try {
    // ✅ Consulta COMPLETA con todos los datos del sugerido
    $sql = "SELECT 
                sc.id_detalle,
                sc.sku,
                sc.descripcion_producto,
                sc.carga_solicitada,
                sc.carga_final,
                sc.md,
                sc.campo_cambios,
                sc.estado_item,
                
                -- Parámetros de venta
                sd.PV6 as pv6,
                ROUND((COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 3, 2) as pv3,
                sd.capacity,
                sd.lead_time_total as lt,
                
                -- Inventario
                sd.disp as disp_tda,
                sd.pend as pend_tda,
                sd.disp_bod as disp_bod,
                sd.pend_bod as pend_bod,
                
                -- ✅ Ventas últimas 6 semanas (abreviadas v1-v6)
                sd.vta_sem_1 as v1,
                sd.vta_sem_2 as v2,
                sd.vta_sem_3 as v3,
                sd.vta_sem_4 as v4,
                sd.vta_sem_5 as v5,
                sd.vta_sem_6 as v6,
                
                -- SDS calculados
                ROUND((COALESCE(sd.disp, 0) + COALESCE(sd.pend, 0)) / NULLIF(sd.PV6, 0), 1) as sds_actual,
                ROUND((COALESCE(sd.disp, 0) + COALESCE(sd.pend, 0) + sc.carga_solicitada) / NULLIF(sd.PV6, 0), 1) as sds_carga,
                
                -- MD por defecto
                sd.MD as md_defecto_sku
                
            FROM Analisis_Procesos.solicitudes_carga sc
            INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
            LEFT JOIN rct.sugerido_diario sd ON sc.sku = sd.sku AND s.id_tienda = sd.id_tienda
            WHERE sc.id_solicitud = :id_solicitud
            ORDER BY sc.id_detalle";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id_solicitud' => $id_solicitud]);
    $skus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener info de la solicitud padre
    $sql_padre = "SELECT estado_general, fecha_solicitud, observaciones_generales
                  FROM Analisis_Procesos.solicitudes 
                  WHERE id_solicitud = :id_solicitud";
    $stmt_padre = $pdo->prepare($sql_padre);
    $stmt_padre->execute(['id_solicitud' => $id_solicitud]);
    $solicitud = $stmt_padre->fetch();
    
    echo json_encode([
        'success' => true,
        'skus' => $skus,
        'estado_general' => $solicitud['estado_general'] ?? 'PENDIENTE',
        'fecha_solicitud' => $solicitud['fecha_solicitud'] ?? null,
        'observaciones_generales' => $solicitud['observaciones_generales'] ?? null
    ]);
    
} catch (PDOException $e) {
    error_log("Error detalle completo: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos']);
}
?>