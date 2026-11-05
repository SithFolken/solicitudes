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
    $nombre_analista_sesion = $_SESSION['nombre'] ?? '';
    
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
    // Filtro por Rol (CON FALLBACK INTELIGENTE)
    // ==========================================
    if ($rol_actual === 'analista') {
        // NIVEL 1: Buscar por SUBFAMILIAS (Sistema Nuevo)
        $sql_sub = "SELECT DISTINCT id_sub_familia FROM input_clientes.planificacion_asignacion_equipos WHERE analista_repo_tienda = :nombre_analista";
        $stmt_sub = $pdo->prepare($sql_sub);
        $stmt_sub->execute(['nombre_analista' => $nombre_analista_sesion]);
        $subfamilias_asignadas = $stmt_sub->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($subfamilias_asignadas)) {
            $placeholders = [];
            foreach ($subfamilias_asignadas as $index => $sub) {
                $param_name = 'sub_' . $index;
                $placeholders[] = ':' . $param_name;
                $params[$param_name] = $sub;
            }
            $where[] = "(s.id_sub_familia IN (" . implode(',', $placeholders) . ") OR s.usuario_proceso = :usuario_nombre_1)";
            $params['usuario_nombre_1'] = $nombre_analista_sesion;
            $subfamilias_para_frontend = $subfamilias_asignadas;
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
                foreach ($familias_asignadas as $index => $fam) {
                    $param_name = 'fam_' . $index;
                    $placeholders[] = ':' . $param_name;
                    $params[$param_name] = $fam;
                }
                $where[] = "s.id_familia IN (" . implode(',', $placeholders) . ")";
                $subfamilias_para_frontend = [];
            } else {
                // NIVEL 3 (EMERGENCIA): Filtrar solo por usuario_proceso
                $where[] = "s.usuario_proceso = :usuario_nombre_2";
                $params['usuario_nombre_2'] = $nombre_analista_sesion;
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
    
    $sql = "SELECT s.id_solicitud, s.id_tienda, s.id_familia, s.id_sub_familia, s.estado_general, s.fecha_solicitud, s.fecha_generacion_excel, s.tipo_exportacion,
            COUNT(DISTINCT sc.id_detalle) as total_skus,
            COALESCE(SUM(sc.carga_solicitada), 0) as total_cantidad_solicitada,
            COALESCE(t.nombre_tienda, CONCAT('Tienda ID: ', s.id_tienda)) as nombre_tienda
            FROM Analisis_Procesos.solicitudes s
            LEFT JOIN Analisis_Procesos.solicitudes_carga sc ON s.id_solicitud = sc.id_solicitud
            LEFT JOIN (SELECT id_tienda, MAX(nombre_tienda) as nombre_tienda FROM rct.sugerido_diario GROUP BY id_tienda) t ON s.id_tienda = t.id_tienda
            $where_clause
            GROUP BY s.id_solicitud, s.id_tienda, s.id_familia, s.id_sub_familia, s.estado_general, s.fecha_solicitud, s.fecha_generacion_excel, s.tipo_exportacion, t.nombre_tienda
            ORDER BY s.id_solicitud DESC
            LIMIT $por_pagina OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
    $stmt->execute();
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($solicitudes as &$sol) {
        $estado_tiempo = 'A_TIEMPO';
        if (in_array($sol['estado_general'], ['PENDIENTE', 'EN_PROCESO']) && $sol['fecha_solicitud']) {
            $horas_transcurridas = (time() - strtotime($sol['fecha_solicitud'])) / 3600;
            if ($horas_transcurridas > 48) $estado_tiempo = 'VENCIDA';
            elseif ($horas_transcurridas > 36) $estado_tiempo = 'POR_CUMPLIRSE';
        }
        $sol['estado_tiempo'] = $estado_tiempo;
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