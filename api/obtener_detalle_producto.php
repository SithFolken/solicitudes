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
    // ==========================================
    // ✅ NUEVA CONSULTA: Sugerido + Inventario Real de Bodegas
    // ==========================================
    $sql = "SELECT 
                sd.sku,
                sd.descripcion_producto,
                ROUND((COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 3, 2) as PV6,
                ROUND((COALESCE(sd.vta_sem_6, 0) + COALESCE(sd.vta_sem_5, 0) + COALESCE(sd.vta_sem_4, 0)) / 3, 2) as PV3,
                sd.capacity,
                sd.lead_time_total as lt,
                sd.disp as disp_tda,
                sd.pend as pend_tda,
                
                -- ✅ Reemplazo de disp_bod y pend_bod por cálculo real de bodegas
                COALESCE(bod_saldo.disp_bod_calc, 0) as disp_bod,
                COALESCE(bod_saldo.pend_bod_calc, 0) as pend_bod,
                
                sd.vta_sem_1 as v1,
                sd.vta_sem_2 as v2,
                sd.vta_sem_3 as v3,
                sd.vta_sem_4 as v4,
                sd.vta_sem_5 as v5,
                sd.vta_sem_6 as v6,
                sd.MD as md_defecto,
                sd.unid_pallet,
                COALESCE(sd.`MIN`, 0) as min_despacho,
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
            LEFT JOIN (
                SELECT 
                    inv.sku,
                    SUM(inv.saldo_disponible) as disp_bod_calc,
                    SUM(inv.saldo_pendiente) as pend_bod_calc
                FROM sodimac_grt.inventario_diario inv
                INNER JOIN sodimac_grt.bodegas b ON inv.id_sucursal = b.id_bodega
                WHERE b.asigna = 1
                GROUP BY inv.sku
            ) as bod_saldo ON sd.sku = bod_saldo.sku
            WHERE sd.id_tienda = :id_tienda AND sd.sku = :sku
            ORDER BY sd.fecha DESC LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id_tienda' => $id_tienda, 'sku' => $sku]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($producto) {
        // ✅ LOG para depuración: verificar que MIN y stock de bodega llegan correctamente
        error_log("DETALLE PRODUCTO - SKU: {$producto['sku']}, MIN: {$producto['min_despacho']}, PV6: {$producto['PV6']}, DISP_BOD: {$producto['disp_bod']}");
        
        echo json_encode(['success' => true, 'producto' => $producto]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Producto no encontrado en el sugerido para esta tienda']);
    }
    
} catch (PDOException $e) {
    error_log("Error obtener_detalle_producto: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>