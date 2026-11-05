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
$nombre_analista_sesion = $_SESSION['nombre'] ?? ''; // ✅ Necesario para la nueva tabla

if ($id_solicitud <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

try {
    $where_extra = '';
    $params_padre = ['id_solicitud' => $id_solicitud];
    
    if ($rol_actual === 'tienda') {
        $where_extra .= " AND s.usuario_tienda = :usuario_tienda";
        $params_padre['usuario_tienda'] = $usuario_actual;
        
    } elseif ($rol_actual === 'analista') {
        // ==========================================
        // ✅ LÓGICA UNIFICADA DE 3 NIVELES (CORREGIDA)
        // ==========================================
        $puede_acceder = false;
        
        // NIVEL 1: Verificar por SUBFAMILIA (Sistema Nuevo)
        $sql_sub = "SELECT DISTINCT p.id_sub_familia 
                    FROM input_clientes.planificacion_asignacion_equipos p
                    WHERE p.analista_repo_tienda = :nombre_analista";
        $stmt_sub = $pdo->prepare($sql_sub);
        $stmt_sub->execute(['nombre_analista' => $nombre_analista_sesion]);
        $subfamilias_asignadas = $stmt_sub->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($subfamilias_asignadas)) {
            $placeholders = [];
            foreach ($subfamilias_asignadas as $index => $sub) {
                $param_name = 'sub_' . $index;
                $placeholders[] = ':' . $param_name;
                $params_padre[$param_name] = $sub;
            }
            $where_extra .= " AND s.id_sub_familia IN (" . implode(',', $placeholders) . ")";
            $puede_acceder = true;
        } else {
            // NIVEL 2 (FALLBACK): Buscar por FAMILIAS (Sistema Antiguo)
            // ✅ CORREGIDO: Usar LIKE para manejar múltiples analistas separados por '|'
            $sql_fam = "SELECT DISTINCT d.Familia as id_familia
                        FROM FulFillment.distribucion_analista_familia d
                        INNER JOIN rct.usuarios_solicitudes_oc u 
                            ON d.Analista_reposicion_tienda LIKE CONCAT('%', u.nombre, '%')
                        WHERE u.usuario = :usuario_analista";
            
            $stmt_fam = $pdo->prepare($sql_fam);
            $stmt_fam->execute(['usuario_analista' => $usuario_actual]);
            $familias_asignadas = $stmt_fam->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($familias_asignadas)) {
                $placeholders = [];
                foreach ($familias_asignadas as $index => $familia) {
                    $param_name = 'fam_' . $index;
                    $placeholders[] = ':' . $param_name;
                    $params_padre[$param_name] = $familia;
                }
                $where_extra .= " AND s.id_familia IN (" . implode(',', $placeholders) . ")";
                $puede_acceder = true;
            } else {
                // NIVEL 3 (EMERGENCIA): Filtrar solo por usuario_proceso
                $where_extra .= " AND s.usuario_proceso = :usuario_nombre";
                $params_padre['usuario_nombre'] = $nombre_analista_sesion;
                $puede_acceder = true;
            }
        }
        
        if (!$puede_acceder) {
            echo json_encode(['success' => false, 'message' => 'No tienes familias asignadas.']);
            exit;
        }
    }
    
    // 1. Obtener datos de la solicitud padre (con filtro de seguridad y alias 's')
    $sql_padre = "SELECT s.id_familia, s.id_sub_familia, s.observaciones_generales, s.estado_general, s.motivo_carga, s.archivo_venta_pdf
                  FROM Analisis_Procesos.solicitudes s
                  WHERE s.id_solicitud = :id_solicitud {$where_extra}";
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
                    sd.disp as disp_tda, sd.pend as pend_tda, 
                    COALESCE(bod_saldo.disp_bod_calc, 0) as disp_bod, 
                    COALESCE(bod_saldo.pend_bod_calc, 0) as pend_bod,
                    sd.vta_sem_1 as v1, sd.vta_sem_2 as v2, sd.vta_sem_3 as v3, sd.vta_sem_4 as v4, sd.vta_sem_5 as v5, sd.vta_sem_6 as v6,
                    sd.unid_pallet,
                    ROUND((COALESCE(sd.disp, 0) + COALESCE(sd.pend, 0)) / NULLIF(sd.PV6, 0), 1) as sds_actual,
                    ROUND((COALESCE(sd.disp, 0) + COALESCE(sd.pend, 0) + sc.carga_solicitada) / NULLIF(sd.PV6, 0), 1) as sds_carga,
                    sd.MD as md_defecto_sku
                FROM Analisis_Procesos.solicitudes_carga sc
                INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
                LEFT JOIN rct.sugerido_diario sd ON sc.sku = sd.sku AND s.id_tienda = sd.id_tienda
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
                WHERE sc.id_solicitud = :id_solicitud AND sc.estado_item != 'RECHAZADO'
                ORDER BY sc.id_detalle";
    
    $stmt_skus = $pdo->prepare($sql_skus);
    $stmt_skus->execute(['id_solicitud' => $id_solicitud]);
    $skus = $stmt_skus->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'solicitud' => [
            'id_familia' => $solicitud['id_familia'],
            'id_sub_familia' => $solicitud['id_sub_familia'] ?? null,
            'observaciones_generales' => $solicitud['observaciones_generales'],
            'estado_general' => $solicitud['estado_general'],
            'motivo_carga' => $solicitud['motivo_carga'],          
            'archivo_venta_pdf' => $solicitud['archivo_venta_pdf'] 
        ],
        'skus' => $skus
    ]);
    
} catch (PDOException $e) {
    error_log("Error obteniendo detalle: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>