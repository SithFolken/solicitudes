<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// Validación de seguridad
if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['rol'] ?? '')) !== 'tienda') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $usuario_tienda = $_SESSION['user_id'];
    
    // ==========================================
    // PARÁMETROS DE PAGINACIÓN Y FILTROS
    // ==========================================
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $por_pagina = in_array((int)($_GET['por_pagina'] ?? 50), [10, 25, 50, 100, 200]) 
                  ? (int)$_GET['por_pagina'] 
                  : 50;
    $offset = ($pagina - 1) * $por_pagina;
    
    $estado = isset($_GET['estado']) ? strtoupper(trim($_GET['estado'])) : 'TODOS';
    $md = isset($_GET['md']) ? strtoupper(trim($_GET['md'])) : 'TODOS';
    $fecha_desde = isset($_GET['fecha_desde']) ? trim($_GET['fecha_desde']) : '';
    $fecha_hasta = isset($_GET['fecha_hasta']) ? trim($_GET['fecha_hasta']) : '';
    
    $where_conditions = ["s.usuario_tienda = :usuario"];
    $params = ['usuario' => $usuario_tienda];
    
    if ($estado !== 'TODOS') {
        $where_conditions[] = "s.estado_general = :estado";
        $params['estado'] = $estado;
    }
    
    if ($md !== 'TODOS') {
        $where_conditions[] = "EXISTS (SELECT 1 FROM Analisis_Procesos.solicitudes_carga sc2 
                                 WHERE sc2.id_solicitud = s.id_solicitud AND UPPER(sc2.md) = :md)";
        $params['md'] = $md;
    }
    
    if ($fecha_desde) {
        $where_conditions[] = "DATE(s.fecha_solicitud) >= :fecha_desde";
        $params['fecha_desde'] = $fecha_desde;
    }
    
    if ($fecha_hasta) {
        $where_conditions[] = "DATE(s.fecha_solicitud) <= :fecha_hasta";
        $params['fecha_hasta'] = $fecha_hasta;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // ==========================================
    // CONTADORES (Sin paginación, para toda la tienda)
    // ==========================================
    $sql_contadores = "SELECT s.estado_general as estado, COUNT(DISTINCT s.id_solicitud) as total 
                       FROM Analisis_Procesos.solicitudes s
                       WHERE s.usuario_tienda = :usuario 
                       GROUP BY s.estado_general";
    $stmt_cont = $pdo->prepare($sql_contadores);
    $stmt_cont->execute(['usuario' => $usuario_tienda]);
    $contadores_raw = $stmt_cont->fetchAll(PDO::FETCH_KEY_PAIR);

    $contadores = [
        'PENDIENTE' => $contadores_raw['PENDIENTE'] ?? 0,
        //'APROBADA' => $contadores_raw['APROBADA'] ?? 0,
        'EN_PROCESO' => $contadores_raw['EN_PROCESO'] ?? 0,
        'PROCESADA' => $contadores_raw['PROCESADA'] ?? 0,
        'PROCESADA_PARCIAL' => $contadores_raw['PROCESADA_PARCIAL'] ?? 0,
        'RECHAZADA' => $contadores_raw['RECHAZADA'] ?? 0
    ];

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
    // CONSULTA PRINCIPAL CON PAGINACIÓN
    // ==========================================
    $sql_lista = "SELECT 
                    s.id_solicitud,
                    s.id_tienda,
                    s.estado_general as estado_actual,
                    DATE_FORMAT(s.fecha_solicitud, '%d-%m-%Y %H:%i') as fecha_solicitud,
                    s.observaciones_generales as observaciones,
                    COUNT(sc.id_detalle) as total_skus,
                    COALESCE(SUM(sc.carga_solicitada), 0) as total_cantidad_solicitada
                FROM Analisis_Procesos.solicitudes s
                LEFT JOIN Analisis_Procesos.solicitudes_carga sc ON s.id_solicitud = sc.id_solicitud
                $where_clause
                GROUP BY s.id_solicitud, s.id_tienda, s.estado_general, s.fecha_solicitud, s.observaciones_generales
                ORDER BY s.id_solicitud DESC 
                LIMIT $por_pagina OFFSET $offset";
    
    $stmt_lista = $pdo->prepare($sql_lista);
    foreach ($params as $key => $value) {
        $stmt_lista->bindValue($key, $value);
    }
    $stmt_lista->execute();
    $solicitudes = $stmt_lista->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // RESPUESTA
    // ==========================================
    echo json_encode([
        'success' => true,
        'contadores' => $contadores,
        'solicitudes' => is_array($solicitudes) ? $solicitudes : [],
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
    error_log("Error obtener mis solicitudes: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error de base de datos: ' . $e->getMessage()
    ]);
}
?>