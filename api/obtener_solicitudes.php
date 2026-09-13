<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] !== 'analista' && $_SESSION['rol'] !== 'ejecutor')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // ==========================================
    // PARÁMETROS DE PAGINACIÓN Y FILTROS
    // ==========================================
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $por_pagina = in_array((int)($_GET['por_pagina'] ?? 50), [10, 25, 50, 100, 200]) 
                  ? (int)$_GET['por_pagina'] 
                  : 50;
    $offset = ($pagina - 1) * $por_pagina;
    
    // Filtros
    $estado = $_GET['estado'] ?? 'TODOS';
    $md = $_GET['md'] ?? 'TODOS';
    $fecha_desde = $_GET['fecha_desde'] ?? null;
    $fecha_hasta = $_GET['fecha_hasta'] ?? null;

    // ==========================================
    // CONSTRUCCIÓN DE LA CONSULTA
    // ==========================================
    $where = [];
    $params = [];

    // Filtro de estado
    if ($estado !== 'TODOS') {
        $where[] = "s.estado_general = :estado";
        $params['estado'] = $estado;
    }

    // Filtro de método de compra (se aplica sobre los items)
    if ($md !== 'TODOS') {
        $where[] = "EXISTS (SELECT 1 FROM Analisis_Procesos.solicitudes_carga sc2 
                     WHERE sc2.id_solicitud = s.id_solicitud AND UPPER(sc2.md) = :md)";
        $params['md'] = strtoupper($md);
    }

    // Filtros de fecha (con manejo de NULL)
    if ($fecha_desde) {
        $where[] = "(s.fecha_solicitud IS NULL OR s.fecha_solicitud >= :fecha_desde)";
        $params['fecha_desde'] = $fecha_desde . ' 00:00:00';
    }
    if ($fecha_hasta) {
        $where[] = "(s.fecha_solicitud IS NULL OR s.fecha_solicitud <= :fecha_hasta)";
        $params['fecha_hasta'] = $fecha_hasta . ' 23:59:59';
    }

    $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // ==========================================
    // CONSULTA DE TOTAL (para calcular páginas)
    // ==========================================
    $sql_total = "SELECT COUNT(DISTINCT s.id_solicitud) as total 
                  FROM Analisis_Procesos.solicitudes s 
                  $where_clause";
    $stmt_total = $pdo->prepare($sql_total);
    $stmt_total->execute($params);
    $total_registros = (int)$stmt_total->fetch()['total'];
    $total_paginas = $total_registros > 0 ? ceil($total_registros / $por_pagina) : 1;

    // ==========================================
    // CONSULTA PRINCIPAL CON PAGINACIÓN (CORREGIDA)
    // ==========================================
    $sql = "SELECT 
                s.id_solicitud,
                s.id_tienda,
                s.estado_general,
                s.fecha_solicitud,
                s.fecha_generacion_excel,
                COUNT(DISTINCT sc.id_detalle) as total_skus,
                COALESCE(SUM(sc.carga_solicitada), 0) as total_cantidad_solicitada,
                COALESCE(t.nombre_tienda, CONCAT('Tienda ID: ', s.id_tienda)) as nombre_tienda
            FROM Analisis_Procesos.solicitudes s
            LEFT JOIN Analisis_Procesos.solicitudes_carga sc ON s.id_solicitud = sc.id_solicitud
            LEFT JOIN (
                SELECT id_tienda, MAX(nombre_tienda) as nombre_tienda
                FROM rct.sugerido_diario
                GROUP BY id_tienda
            ) t ON s.id_tienda = t.id_tienda
            $where_clause
            GROUP BY s.id_solicitud, s.id_tienda, s.estado_general, s.fecha_solicitud, 
                     s.fecha_generacion_excel, t.nombre_tienda
            ORDER BY s.id_solicitud DESC
            LIMIT $por_pagina OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // CALCULAR ESTADO DE TIEMPO (SLA)
    // ==========================================
    foreach ($solicitudes as &$sol) {
        $estado_tiempo = 'A_TIEMPO';
        if (in_array($sol['estado_general'], ['PENDIENTE', 'EN_PROCESO']) && $sol['fecha_solicitud']) {
            $fecha_solicitud = strtotime($sol['fecha_solicitud']);
            $horas_transcurridas = (time() - $fecha_solicitud) / 3600;
            
            if ($horas_transcurridas > 48) {
                $estado_tiempo = 'VENCIDA';
            } elseif ($horas_transcurridas > 36) {
                $estado_tiempo = 'POR_CUMPLIRSE';
            }
        }
        $sol['estado_tiempo'] = $estado_tiempo;
    }
    unset($sol);

    // ==========================================
    // RESPUESTA
    // ==========================================
    echo json_encode([
        'success' => true,
        'solicitudes' => $solicitudes,
        'paginacion' => [
            'pagina_actual' => $pagina,
            'por_pagina' => $por_pagina,
            'total_registros' => $total_registros,
            'total_paginas' => $total_paginas,
            'desde' => $total_registros > 0 ? $offset + 1 : 0,
            'hasta' => min($offset + $por_pagina, $total_registros)
        ]
    ]);

} catch (PDOException $e) {
    error_log("Error obtener solicitudes: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error de base de datos: ' . $e->getMessage()
    ]);
}
?>