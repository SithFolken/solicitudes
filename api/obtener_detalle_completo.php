<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$id_solicitud = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$rol_actual = $_SESSION['rol'] ?? '';
$usuario_actual = $_SESSION['user_id'] ?? '';

if ($id_solicitud <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

try {
    $params = [];
    $where_familia = '';

    // 1. Si es analista, aplicar filtro de familias
    if ($rol_actual === 'analista') {
        $sql_familias = "SELECT DISTINCT d.Familia as id_familia
                         FROM FulFillment.distribucion_analista_familia d
                         INNER JOIN rct.usuarios_solicitudes_oc u 
                             ON d.Analista_reposicion_tienda = u.nombre
                         WHERE u.usuario = :usuario_analista";
        
        $stmt_familias = $pdo->prepare($sql_familias);
        $stmt_familias->execute(['usuario_analista' => $usuario_actual]);
        $familias_asignadas = $stmt_familias->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($familias_asignadas)) {
            echo json_encode(['success' => false, 'message' => 'No tienes familias asignadas.']);
            exit;
        }
        
        $placeholders = [];
        foreach ($familias_asignadas as $index => $familia) {
            $param_name = 'fam_' . $index;
            $placeholders[] = ':' . $param_name;
            $params[$param_name] = $familia;
        }
        $where_familia = " AND s.id_familia IN (" . implode(',', $placeholders) . ")";
    }

    $params['id_solicitud'] = $id_solicitud;

    // 2. Consulta COMPLETA de SKUs CORREGIDA
    $sql = "SELECT 
                sc.id_detalle, sc.sku, sc.descripcion_producto, sc.carga_solicitada, sc.carga_final, 
                sc.md, sc.campo_cambios, sc.estado_item,
                sd.PV6 as pv6,
                ROUND((COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 3, 2) as pv3,
                sd.capacity, sd.lead_time_total as lt,
                sd.disp as disp_tda, sd.pend as pend_tda, sd.disp_bod as disp_bod, sd.pend_bod as pend_bod,
                sd.vta_sem_1 as v1, sd.vta_sem_2 as v2, sd.vta_sem_3 as v3, sd.vta_sem_4 as v4, sd.vta_sem_5 as v5, sd.vta_sem_6 as v6,
                sd.unid_pallet,
                ROUND((COALESCE(sd.disp, 0) + COALESCE(sd.pend, 0)) / NULLIF(sd.PV6, 0), 1) as sds_actual,
                ROUND((COALESCE(sd.disp, 0) + COALESCE(sd.pend, 0) + sc.carga_solicitada) / NULLIF(sd.PV6, 0), 1) as sds_carga,
                sd.MD as md_defecto_sku,
                s.id_tienda,
                sd.nombre_tienda
            FROM Analisis_Procesos.solicitudes_carga sc
            INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
            LEFT JOIN (
                -- ✅ CORRECCIÓN: Subconsulta para traer SOLO el registro más reciente por SKU y Tienda
                SELECT sku, id_tienda, nombre_tienda, PV6, capacity, lead_time_total, disp, pend, disp_bod, pend_bod, 
                       vta_sem_1, vta_sem_2, vta_sem_3, vta_sem_4, vta_sem_5, vta_sem_6, unid_pallet, MD
                FROM rct.sugerido_diario sd1
                WHERE fecha = (
                    SELECT MAX(fecha) 
                    FROM rct.sugerido_diario sd2 
                    WHERE sd2.sku = sd1.sku AND sd2.id_tienda = sd1.id_tienda
                )
            ) sd ON sc.sku = sd.sku AND s.id_tienda = sd.id_tienda
            WHERE sc.id_solicitud = :id_solicitud {$where_familia}
            GROUP BY sc.id_detalle
            ORDER BY sc.id_detalle";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $skus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($skus)) {
        echo json_encode(['success' => false, 'message' => 'No tienes permiso para ver esta solicitud o no contiene datos.']);
        exit;
    }

    // 3. Consulta del padre
    $sql_padre = "SELECT s.estado_general, s.fecha_solicitud, s.observaciones_generales
                  FROM Analisis_Procesos.solicitudes s
                  WHERE s.id_solicitud = :id_solicitud {$where_familia}";
    $stmt_padre = $pdo->prepare($sql_padre);
    $stmt_padre->execute($params);
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
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>