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
$nombre_analista_sesion = $_SESSION['nombre'] ?? '';

if ($id_solicitud <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

try {
    $params_final = ['id_solicitud' => $id_solicitud];
    $where_familia = '';

    // 1. Si es analista, aplicar filtro de familias CON SOPORTE DE REEMPLAZOS
    if ($rol_actual === 'analista') {
        // ✅ Cargar helper de reemplazos
        require_once __DIR__ . '/helpers_reemplazos.php';
        $analistas_efectivos = obtenerAnalistasEfectivos($pdo, $usuario_actual);
        
        $puede_acceder = false;

        // NIVEL 1: Verificar por SUBFAMILIA (Sistema Nuevo)
        $params_sub = [];
        $placeholders_sub = [];
        foreach ($analistas_efectivos as $index => $usr) {
            $param_name = 'usr_sub_' . $index;
            $placeholders_sub[] = ':' . $param_name;
            $params_sub[$param_name] = $usr;
        }
        
        $sql_sub = "SELECT DISTINCT p.id_sub_familia 
                    FROM input_clientes.planificacion_asignacion_equipos p
                    WHERE p.analista_repo_tienda IN (" . implode(',', $placeholders_sub) . ")";
        $stmt_sub = $pdo->prepare($sql_sub);
        $stmt_sub->execute($params_sub); // ✅ Solo parámetros de esta consulta
        $subfamilias_asignadas = $stmt_sub->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($subfamilias_asignadas)) {
            $params_fam_sub = [];
            $placeholders_fam_sub = [];
            foreach ($subfamilias_asignadas as $index => $sub) {
                $param_name = 'sub_' . $index;
                $placeholders_fam_sub[] = ':' . $param_name;
                $params_fam_sub[$param_name] = $sub;
            }
            
            // También permitir si el usuario_proceso coincide con cualquiera de los analistas efectivos
            $params_proc = [];
            $placeholders_proc = [];
            foreach ($analistas_efectivos as $index => $usr) {
                $param_name = 'usr_proc1_' . $index;
                $placeholders_proc[] = ':' . $param_name;
                $params_proc[$param_name] = $usr;
            }
            
            $where_familia = " AND (s.id_sub_familia IN (" . implode(',', $placeholders_fam_sub) . ") OR s.usuario_proceso IN (" . implode(',', $placeholders_proc) . "))";
            $params_final = array_merge($params_final, $params_fam_sub, $params_proc);
            $puede_acceder = true;
        } else {
            // NIVEL 2 (FALLBACK): Buscar por FAMILIAS (Sistema Antiguo)
            $params_usr = [];
            $placeholders_usr = [];
            foreach ($analistas_efectivos as $index => $usr) {
                $param_name = 'usr_fam_' . $index;
                $placeholders_usr[] = ':' . $param_name;
                $params_usr[$param_name] = $usr;
            }
            
            $sql_fam = "SELECT DISTINCT d.Familia as id_familia
                        FROM FulFillment.distribucion_analista_familia d
                        INNER JOIN rct.usuarios_solicitudes_oc u 
                            ON d.Analista_reposicion_tienda LIKE CONCAT('%', u.nombre, '%')
                        WHERE u.usuario IN (" . implode(',', $placeholders_usr) . ")";
            
            $stmt_fam = $pdo->prepare($sql_fam);
            $stmt_fam->execute($params_usr); // ✅ Solo parámetros de esta consulta
            $familias_asignadas = $stmt_fam->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($familias_asignadas)) {
                $params_fam2 = [];
                $placeholders_fam2 = [];
                foreach ($familias_asignadas as $index => $familia) {
                    $param_name = 'fam_' . $index;
                    $placeholders_fam2[] = ':' . $param_name;
                    $params_fam2[$param_name] = $familia;
                }
                $where_familia = " AND s.id_familia IN (" . implode(',', $placeholders_fam2) . ")";
                $params_final = array_merge($params_final, $params_fam2);
                $puede_acceder = true;
            } else {
                // NIVEL 3 (EMERGENCIA): Filtrar solo por usuario_proceso
                $params_proc3 = [];
                $placeholders_proc3 = [];
                foreach ($analistas_efectivos as $index => $usr) {
                    $param_name = 'usr_proc3_' . $index;
                    $placeholders_proc3[] = ':' . $param_name;
                    $params_proc3[$param_name] = $usr;
                }
                $where_familia = " AND s.usuario_proceso IN (" . implode(',', $placeholders_proc3) . ")";
                $params_final = array_merge($params_final, $params_proc3);
                $puede_acceder = true;
            }
        }
        
        if (!$puede_acceder) {
            echo json_encode(['success' => false, 'message' => 'No tienes familias asignadas.']);
            exit;
        }
    }

    // 2. Consulta COMPLETA de SKUs con Inventario Real de Bodegas
    $sql = "SELECT 
                sc.id_detalle, sc.sku, sc.descripcion_producto, sc.carga_solicitada, sc.carga_final, 
                sc.md, sc.campo_cambios, sc.estado_item,
                sd.PV6 as pv6,
                ROUND((COALESCE(sd.vta_sem_3, 0) + COALESCE(sd.vta_sem_2, 0) + COALESCE(sd.vta_sem_1, 0)) / 3, 2) as pv3,
                sd.capacity, sd.lead_time_total as lt,
                sd.disp as disp_tda, sd.pend as pend_tda, 
                COALESCE(bod_saldo.disp_bod_calc, 0) as disp_bod, 
                COALESCE(bod_saldo.pend_bod_calc, 0) as pend_bod,
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
                SELECT sku, id_tienda, nombre_tienda, PV6, capacity, lead_time_total, disp, pend, 
                       vta_sem_1, vta_sem_2, vta_sem_3, vta_sem_4, vta_sem_5, vta_sem_6, unid_pallet, MD
                FROM rct.sugerido_diario sd1
                WHERE fecha = (
                    SELECT MAX(fecha) 
                    FROM rct.sugerido_diario sd2 
                    WHERE sd2.sku = sd1.sku AND sd2.id_tienda = sd1.id_tienda
                )
            ) sd ON sc.sku = sd.sku AND s.id_tienda = sd.id_tienda
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
            WHERE sc.id_solicitud = :id_solicitud {$where_familia}
            GROUP BY sc.id_detalle
            ORDER BY sc.id_detalle";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params_final); // ✅ Solo parámetros que realmente usa
    $skus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($skus)) {
        echo json_encode(['success' => false, 'message' => 'No tienes permiso para ver esta solicitud o no contiene datos.']);
        exit;
    }

    // 3. Consulta del padre
    $sql_padre = "SELECT s.estado_general, s.fecha_solicitud, s.observaciones_generales, s.motivo_carga, s.archivo_venta_pdf
                  FROM Analisis_Procesos.solicitudes s
                  WHERE s.id_solicitud = :id_solicitud {$where_familia}";
    $stmt_padre = $pdo->prepare($sql_padre);
    $stmt_padre->execute($params_final); // ✅ Solo parámetros que realmente usa
    $solicitud = $stmt_padre->fetch();
    
    echo json_encode([
        'success' => true,
        'skus' => $skus,
        'estado_general' => $solicitud['estado_general'] ?? 'PENDIENTE',
        'fecha_solicitud' => $solicitud['fecha_solicitud'] ?? null,
        'observaciones_generales' => $solicitud['observaciones_generales'] ?? null,
        'motivo_carga' => $solicitud['motivo_carga'] ?? null,          
        'archivo_venta_pdf' => $solicitud['archivo_venta_pdf'] ?? null 
    ]);
    
} catch (PDOException $e) {
    error_log("Error detalle completo: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>