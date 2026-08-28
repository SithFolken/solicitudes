<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] !== 'analista' && $_SESSION['rol'] !== 'ejecutor')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // Obtener código de asignación del analista
    $codigo_asignacion = $_SESSION['codigo_asignacion'] ?? '';
    
    if (empty($codigo_asignacion)) {
        echo json_encode(['success' => false, 'message' => 'Analista sin familias asignadas']);
        exit;
    }

    // Obtener parámetros de filtro
    $estado = isset($_GET['estado']) ? strtoupper(trim($_GET['estado'])) : 'TODOS';
    $md = isset($_GET['md']) ? strtoupper(trim($_GET['md'])) : 'TODOS';
    $fecha_desde = isset($_GET['fecha_desde']) ? trim($_GET['fecha_desde']) : '';
    $fecha_hasta = isset($_GET['fecha_hasta']) ? trim($_GET['fecha_hasta']) : '';
    
    // Construir WHERE dinámico
    $where_conditions = ["d.codigo_asignacion = :codigo_asignacion"];
    $params = ['codigo_asignacion' => $codigo_asignacion];
    
    if ($estado !== 'TODOS') {
        $where_conditions[] = "s.estado_general = :estado";
        $params['estado'] = $estado;
    }
    
    if ($fecha_desde) {
        $where_conditions[] = "DATE(s.fecha_solicitud) >= :fecha_desde";
        $params['fecha_desde'] = $fecha_desde;
    }
    
    if ($fecha_hasta) {
        $where_conditions[] = "DATE(s.fecha_solicitud) <= :fecha_hasta";
        $params['fecha_hasta'] = $fecha_hasta;
    }
    
    $where = "WHERE " . implode(" AND ", $where_conditions);
    
    // Consulta de solicitudes PADRE agrupadas
    $sql_lista = "SELECT 
                    s.id_solicitud,
                    s.id_tienda,
                    s.usuario_tienda,
                    s.id_familia,
                    s.estado_general as estado_actual,
                    s.estado_general as estado_solicitud,
                    s.ciclo_corte,
                    s.fecha_generacion_excel,
                    DATE_FORMAT(s.fecha_solicitud, '%d-%m-%Y %H:%i') as fecha_solicitud,
                    s.observaciones_generales as observaciones,
                    COUNT(sc.id_detalle) as total_skus,
                    COALESCE(SUM(sc.carga_solicitada), 0) as total_cantidad_solicitada,
                    u.nombre_usuario as nombre_tienda
                FROM Analisis_Procesos.solicitudes s
                INNER JOIN FulFillment.distribucion_analista_familia d 
                    ON s.id_familia = d.Familia
                LEFT JOIN Analisis_Procesos.solicitudes_carga sc 
                    ON s.id_solicitud = sc.id_solicitud
                LEFT JOIN rst_central.usuarios u 
                    ON s.id_tienda = u.id_tienda AND s.usuario_tienda = u.id_usuario
                $where
                GROUP BY s.id_solicitud
                ORDER BY s.fecha_solicitud DESC 
                LIMIT 100";
    
    $stmt_lista = $pdo->prepare($sql_lista);
    $stmt_lista->execute($params);
    $solicitudes = $stmt_lista->fetchAll();

    echo json_encode([
        'success' => true,
        'solicitudes' => is_array($solicitudes) ? $solicitudes : []
    ]);

} catch (PDOException $e) {
    error_log("Error obtener solicitudes: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error de base de datos: ' . $e->getMessage()
    ]);
}
?>