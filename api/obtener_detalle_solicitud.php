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
    $where_extra = '';
    $params_padre = ['id_solicitud' => $id_solicitud];
    
    if ($rol_actual === 'tienda') {
        $where_extra .= " AND usuario_tienda = :usuario_tienda";
        $params_padre['usuario_tienda'] = $usuario_actual;
        
    } elseif ($rol_actual === 'analista') {
        // ✅ CONSULTA MAESTRA: Unir por NOMBRE para obtener TODAS las familias
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
            $params_padre[$param_name] = $familia;
        }
        $where_extra .= " AND id_familia IN (" . implode(',', $placeholders) . ")";
    }
    
    // 1. Obtener datos de la solicitud padre (con filtro de seguridad)
    $sql_padre = "SELECT id_familia, observaciones_generales, estado_general 
                  FROM Analisis_Procesos.solicitudes 
                  WHERE id_solicitud = :id_solicitud {$where_extra}";
    $stmt_padre = $pdo->prepare($sql_padre);
    $stmt_padre->execute($params_padre);
    $solicitud = $stmt_padre->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitud) {
        echo json_encode([
            'success' => false, 
            'message' => 'No se encontraron datos o no tienes permiso para ver esta solicitud'
        ]);
        exit;
    }
    
    // 2. Obtener los SKUs
    $sql_skus = "SELECT 
                    sc.id_detalle, sc.sku, sc.descripcion_producto, sc.carga_solicitada, sc.carga_final, 
                    sc.md, sc.campo_cambios, sc.estado_item, sc.id_familia,
                    sd.id_tienda, sd.nombre_tienda, sd.PV6 as pv6,
                    ROUND((COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 3, 2) as pv3,
                    sd.MIN as min, sd.lead_time_total as lt,
                    sd.disp as disp_tda, sd.pend as pend_tda, sd.disp_bod as disp_bod, sd.pend_bod as pend_bod,
                    sd.vta_sem_1 as v1, sd.vta_sem_2 as v2, sd.vta_sem_3 as v3, sd.vta_sem_4 as v4, sd.vta_sem_5 as v5, sd.vta_sem_6 as v6,
                    sd.unid_pallet,
                    ROUND((COALESCE(sd.disp, 0) + COALESCE(sd.pend, 0)) / NULLIF(sd.PV6, 0), 1) as sds_actual,
                    ROUND((COALESCE(sd.disp, 0) + COALESCE(sd.pend, 0) + sc.carga_solicitada) / NULLIF(sd.PV6, 0), 1) as sds_carga,
                    sd.MD as md_defecto_sku
                FROM Analisis_Procesos.solicitudes_carga sc
                INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
                LEFT JOIN rct.sugerido_diario sd ON sc.sku = sd.sku AND s.id_tienda = sd.id_tienda
                WHERE sc.id_solicitud = :id_solicitud AND sc.estado_item != 'RECHAZADO'
                ORDER BY sc.id_detalle";
    
    $stmt_skus = $pdo->prepare($sql_skus);
    $stmt_skus->execute(['id_solicitud' => $id_solicitud]);
    $skus = $stmt_skus->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'solicitud' => [
            'id_familia' => $solicitud['id_familia'],
            'observaciones_generales' => $solicitud['observaciones_generales']
        ],
        'skus' => $skus
    ]);
    
} catch (PDOException $e) {
    error_log("Error obteniendo detalle: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>