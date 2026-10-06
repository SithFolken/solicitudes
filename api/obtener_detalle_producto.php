<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$sku = isset($_GET['sku']) ? trim($_GET['sku']) : '';
$id_tienda = isset($_SESSION['id_tienda']) ? (int)$_SESSION['id_tienda'] : 0;

if (!$sku || $id_tienda <= 0) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

try {
    // ✅ CORREGIDO: La fórmula del SDS es AHORA IDÉNTICA a la del Árbol de Decisión
    // SDS = (disp_tda + pend_tda) / PV6
    $sql = "SELECT 
                sd.sku,
                sd.descripcion_producto,
                -- Calcular PV6 exactamente como lo hace el Árbol de Decisión
                ROUND((COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 3, 2) as PV6,
                ROUND((COALESCE(sd.vta_sem_6, 0) + COALESCE(sd.vta_sem_5, 0) + COALESCE(sd.vta_sem_4, 0)) / 3, 2) as PV3,
                sd.capacity,
                sd.lead_time_total as lt,
                sd.disp as disp_tda,
                sd.pend as pend_tda,
                sd.disp_bod,
                sd.pend_bod,
                sd.vta_sem_1 as v1,
                sd.vta_sem_2 as v2,
                sd.vta_sem_3 as v3,
                sd.vta_sem_4 as v4,
                sd.vta_sem_5 as v5,
                sd.vta_sem_6 as v6,
                sd.MD as md_defecto,
                sd.unid_pallet,
                -- ✅ FÓRMULA EXACTA DEL ÁRBOL DE DECISIÓN:
                CASE 
                    WHEN ROUND((COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 3, 2) > 0 
                    THEN ROUND(
                        (COALESCE(sd.disp, 0) + COALESCE(sd.pend, 0)) / 
                        ROUND((COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 3, 2), 
                        1
                    )
                    ELSE 999 
                END as sds_actual
            FROM rct.sugerido_diario sd
            WHERE sd.id_tienda = :id_tienda AND sd.sku = :sku
            ORDER BY sd.fecha DESC LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id_tienda' => $id_tienda, 'sku' => $sku]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($producto) {
        // Log para depuración: verificar que los valores coincidan con el servidor
        error_log("SDS Preview - SKU: {$producto['sku']}, Disp: {$producto['disp_tda']}, Pend: {$producto['pend_tda']}, PV6: {$producto['PV6']}, SDS Calculado: {$producto['sds_actual']}");
        
        echo json_encode(['success' => true, 'producto' => $producto]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Producto no encontrado en el sugerido diario']);
    }
    
} catch (PDOException $e) {
    error_log("Error obtener_detalle_producto: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>