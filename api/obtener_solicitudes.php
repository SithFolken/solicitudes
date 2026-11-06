<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

$rol_permitido = isset($_SESSION['rol']) && in_array($_SESSION['rol'], ['analista', 'ejecutor', 'tienda']);
if (!isset($_SESSION['user_id']) || !$rol_permitido) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $usuario_actual = $_SESSION['user_id'] ?? '';
    $rol_actual = $_SESSION['rol'] ?? '';
    
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $por_pagina = in_array((int)($_GET['por_pagina'] ?? 50), [10, 25, 50, 100, 200]) ? (int)$_GET['por_pagina'] : 50;
    $offset = ($pagina - 1) * $por_pagina;
    $estado = $_GET['estado'] ?? 'TODOS';
    $md = $_GET['md'] ?? 'TODOS';
    $fecha_desde = $_GET['fecha_desde'] ?? null;
    $fecha_hasta = $_GET['fecha_hasta'] ?? null;
    $where = [];
    $params = [];
    $subfamilias_para_frontend = [];
    
    // ==========================================
    // Filtro por Rol (CON FALLBACK INTELIGENTE Y REEMPLAZOS POR FECHA)
    // ==========================================
    if ($rol_actual === 'analista' || $rol_actual === 'ejecutor') {
        require_once __DIR__ . '/helpers_reemplazos.php';
        $analistas_efectivos = obtenerAnalistasEfectivos($pdo, $usuario_actual);
        
        // ✅ Obtener nombres completos para el badge
        $nombres_analistas = [];
        if (!empty($analistas_efectivos)) {
            $placeholders_nom = implode(',', array_fill(0, count($analistas_efectivos), '?'));
            $sql_nombres = "SELECT usuario, nombre FROM rct.usuarios_solicitudes_oc WHERE usuario IN ($placeholders_nom)";
            $stmt_nombres = $pdo->prepare($sql_nombres);
            $stmt_nombres->execute($analistas_efectivos);
            while ($row_nom = $stmt_nombres->fetch(PDO::FETCH_ASSOC)) {
                $nombres_analistas[$row_nom['usuario']] = $row_nom['nombre'];
            }
        }
        
        // ✅ Obtener fechas de reemplazo para filtrar solicitudes del analista original
        $reemplazos_con_fechas = obtenerReemplazosActivosConFechas($pdo, $usuario_actual);
        
        // NIVEL 1: Buscar por SUBFAMILIAS (Sistema Nuevo)
        $placeholders_sub = [];
        $params_sub = [];
        foreach ($analistas_efectivos as $index => $usr) {
            $param_name = 'usr_sub_' . $index;
            $placeholders_sub[] = ':' . $param_name;
            $params_sub[$param_name] = $usr;
        }
        
        // ✅ CORRECCIÓN CLAVE: Usar JOIN para traducir username a nombre completo
        $sql_sub = "SELECT DISTINCT p.id_sub_familia 
                    FROM input_clientes.planificacion_asignacion_equipos p
                    INNER JOIN rct.usuarios_solicitudes_oc u ON TRIM(p.analista_repo_tienda) = u.nombre
                    WHERE u.usuario IN (" . implode(',', $placeholders_sub) . ")";
                    
        $stmt_sub = $pdo->prepare($sql_sub);
        $stmt_sub->execute($params_sub);
        $subfamilias_asignadas = $stmt_sub->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($subfamilias_asignadas)) {
            $placeholders_fam_sub = [];
            foreach ($subfamilias_asignadas as $index => $sub) {
                $param_name = 'sub_' . $index;
                $placeholders_fam_sub[] = ':' . $param_name;
                $params[$param_name] = $sub;
            }
            
            // Construir la condición de usuario con filtro de fecha para reemplazos
            $condiciones_usuario = ["s.usuario_proceso = :usuario_propio"];
            $params['usuario_propio'] = $usuario_actual;
            
            foreach ($reemplazos_con_fechas as $usr_original => $fechas) {
                $idx = $usr_original;
                // Ve sus solicitudes en el periodo, O las que siguen activas (pendientes/en proceso)
                $condiciones_usuario[] = "(s.usuario_proceso = :usr_orig_$idx AND (s.fecha_solicitud BETWEEN :fecha_ini_$idx AND :fecha_fin_$idx OR s.estado_general IN ('PENDIENTE', 'EN_PROCESO')))";
                $params['usr_orig_' . $idx] = $usr_original;
                $params['fecha_ini_' . $idx] = $fechas['fecha_inicio'];
                $params['fecha_fin_' . $idx] = $fechas['fecha_fin'];
            }
            
            $condicion_usuario_final = "(" . implode(' OR ', $condiciones_usuario) . ")";
            
            // Unimos: Debe ser de las subfamilias O cumplir la condición de usuario (para no perder las propias)
            $where[] = "(s.id_sub_familia IN (" . implode(',', $placeholders_fam_sub) . ") OR $condicion_usuario_final)";
            $subfamilias_para_frontend = $subfamilias_asignadas; // ✅ Esto hará que el mensaje reaparezca
            
        } else {
            // NIVEL 2 (FALLBACK): Buscar por FAMILIAS (Sistema Antiguo)
            $placeholders_usr = [];
            $params_usr = [];
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
            $stmt_fam->execute($params_usr);
            $familias_asignadas = $stmt_fam->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($familias_asignadas)) {
                $placeholders_fam2 = [];
                foreach ($familias_asignadas as $index => $fam) {
                    $param_name = 'fam_' . $index;
                    $placeholders_fam2[] = ':' . $param_name;
                    $params[$param_name] = $fam;
                }
                
                $condiciones_usuario = ["s.usuario_proceso = :usuario_propio2"];
                $params['usuario_propio2'] = $usuario_actual;
                
                foreach ($reemplazos_con_fechas as $usr_original => $fechas) {
                    $idx = $usr_original;
                    $condiciones_usuario[] = "(s.usuario_proceso = :usr_orig2_$idx AND (s.fecha_solicitud BETWEEN :fecha_ini2_$idx AND :fecha_fin2_$idx OR s.estado_general IN ('PENDIENTE', 'EN_PROCESO')))";
                    $params['usr_orig2_' . $idx] = $usr_original;
                    $params['fecha_ini2_' . $idx] = $fechas['fecha_inicio'];
                    $params['fecha_fin2_' . $idx] = $fechas['fecha_fin'];
                }
                
                $condicion_usuario_final = "(" . implode(' OR ', $condiciones_usuario) . ")";
                $where[] = "(s.id_familia IN (" . implode(',', $placeholders_fam2) . ") OR $condicion_usuario_final)";
                $subfamilias_para_frontend = [];
                
            } else {
                // NIVEL 3 (EMERGENCIA): Filtrar solo por usuario_proceso
                $condiciones_usuario = ["s.usuario_proceso = :usuario_propio3"];
                $params['usuario_propio3'] = $usuario_actual;
                
                foreach ($reemplazos_con_fechas as $usr_original => $fechas) {
                    $idx = $usr_original;
                    $condiciones_usuario[] = "(s.usuario_proceso = :usr_orig3_$idx AND (s.fecha_solicitud BETWEEN :fecha_ini3_$idx AND :fecha_fin3_$idx OR s.estado_general IN ('PENDIENTE', 'EN_PROCESO')))";
                    $params['usr_orig3_' . $idx] = $usr_original;
                    $params['fecha_ini3_' . $idx] = $fechas['fecha_inicio'];
                    $params['fecha_fin3_' . $idx] = $fechas['fecha_fin'];
                }
                
                $where[] = "(" . implode(' OR ', $condiciones_usuario) . ")";
                $subfamilias_para_frontend = [];
            }
        }
    } elseif ($rol_actual === 'tienda') {
        $where[] = "s.usuario_tienda = :usuario_tienda";
        $params['usuario_tienda'] = $usuario_actual;
    }
    
    if ($estado !== 'TODOS') { $where[] = "s.estado_general = :estado"; $params['estado'] = $estado; }
    if ($md !== 'TODOS') { $where[] = "EXISTS (SELECT 1 FROM Analisis_Procesos.solicitudes_carga sc2 WHERE sc2.id_solicitud = s.id_solicitud AND UPPER(sc2.md) = :md)"; $params['md'] = strtoupper($md); }
    if ($fecha_desde) { $where[] = "s.fecha_solicitud >= :fecha_desde"; $params['fecha_desde'] = $fecha_desde . ' 00:00:00'; }
    if ($fecha_hasta) { $where[] = "s.fecha_solicitud <= :fecha_hasta"; $params['fecha_hasta'] = $fecha_hasta . ' 23:59:59'; }
    
    $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql_total = "SELECT COUNT(DISTINCT s.id_solicitud) as total FROM Analisis_Procesos.solicitudes s $where_clause";
    $stmt_total = $pdo->prepare($sql_total);
    foreach ($params as $key => $value) { $stmt_total->bindValue($key, $value); }
    $stmt_total->execute();
    $total_registros = (int)$stmt_total->fetchColumn();
    $total_paginas = $total_registros > 0 ? ceil($total_registros / $por_pagina) : 1;
    
    $sql = "SELECT s.id_solicitud, s.id_tienda, s.id_familia, s.id_sub_familia, s.estado_general, s.fecha_solicitud, s.fecha_generacion_excel, s.tipo_exportacion, s.usuario_proceso,
            COUNT(DISTINCT sc.id_detalle) as total_skus,
            COALESCE(SUM(sc.carga_solicitada), 0) as total_cantidad_solicitada,
            COALESCE(t.nombre_tienda, CONCAT('Tienda ID: ', s.id_tienda)) as nombre_tienda
            FROM Analisis_Procesos.solicitudes s
            LEFT JOIN Analisis_Procesos.solicitudes_carga sc ON s.id_solicitud = sc.id_solicitud
            LEFT JOIN (SELECT id_tienda, MAX(nombre_tienda) as nombre_tienda FROM rct.sugerido_diario GROUP BY id_tienda) t ON s.id_tienda = t.id_tienda
            $where_clause
            GROUP BY s.id_solicitud, s.id_tienda, s.id_familia, s.id_sub_familia, s.estado_general, s.fecha_solicitud, s.fecha_generacion_excel, s.tipo_exportacion, s.usuario_proceso, t.nombre_tienda
            ORDER BY s.id_solicitud DESC
            LIMIT $por_pagina OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
    $stmt->execute();
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ==========================================
    // ✅ BLOQUE ACTUALIZADO: SLA CON HORAS HÁBILES + DETECCIÓN CORRECTA DE REEMPLAZO
    // ==========================================
    require_once __DIR__ . '/helpers_sla.php'; // Cargar el helper de SLA
    
    foreach ($solicitudes as &$sol) {
        $estado_tiempo = 'A_TIEMPO';
        if (in_array($sol['estado_general'], ['PENDIENTE', 'EN_PROCESO']) && $sol['fecha_solicitud']) {
            // ✅ Usar cálculo de horas hábiles (ignora sábados, domingos y feriados de la tabla CALENDARIO)
            $horas_transcurridas = calcularHorasHabiles($sol['fecha_solicitud'], $pdo);
            
            if ($horas_transcurridas > 48) $estado_tiempo = 'VENCIDA';
            elseif ($horas_transcurridas > 36) $estado_tiempo = 'POR_CUMPLIRSE';
        }
        $sol['estado_tiempo'] = $estado_tiempo;
        
        // ✅ Detectar si es solicitud de reemplazo usando in_array (evita falsos positivos por mayúsculas/espacios)
        $sol['es_reemplazo'] = !in_array(trim($sol['usuario_proceso']), $analistas_efectivos);
        $sol['nombre_analista_original'] = $nombres_analistas[trim($sol['usuario_proceso'])] ?? $sol['usuario_proceso'];
    }
    unset($sol);
    
    echo json_encode([
        'success' => true,
        'solicitudes' => $solicitudes,
        'subfamilias_asignadas' => $subfamilias_para_frontend,
        'paginacion' => [
            'pagina_actual' => $pagina, 'por_pagina' => $por_pagina, 'total_registros' => $total_registros,
            'total_paginas' => $total_paginas, 'desde' => $total_registros > 0 ? $offset + 1 : 0,
            'hasta' => min($offset + $por_pagina, $total_registros)
        ]
    ]);
} catch (PDOException $e) {
    error_log("Error obtener solicitudes: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>