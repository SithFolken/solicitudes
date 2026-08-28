<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// Debug: Ver qué hay en la sesión
error_log("DEBUG SESIÓN MIS SOLICITUDES - user_id: " . ($_SESSION['user_id'] ?? 'NO SET'));
error_log("DEBUG SESIÓN MIS SOLICITUDES - rol: '" . ($_SESSION['rol'] ?? 'NO SET') . "'");

// Validación más flexible (case-insensitive)
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado - No hay sesión activa']);
    exit;
}

$rol_sesion = strtolower(trim($_SESSION['rol'] ?? ''));

if ($rol_sesion !== 'tienda') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Acceso no autorizado - Rol actual: ' . $_SESSION['rol']
    ]);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$usuario_tienda = $_SESSION['user_id'];

try {
    $estado = isset($_GET['estado']) ? strtoupper(trim($_GET['estado'])) : 'TODOS';
    $fecha_desde = isset($_GET['fecha_desde']) ? trim($_GET['fecha_desde']) : '';
    $fecha_hasta = isset($_GET['fecha_hasta']) ? trim($_GET['fecha_hasta']) : '';
    
    $where_conditions = ["s.usuario_tienda = :usuario"];
    $params = ['usuario' => $usuario_tienda];
    
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
    
    // Contadores
    $sql_contadores = "SELECT 
                            s.estado_general as estado, 
                            COUNT(DISTINCT s.id_solicitud) as total 
                        FROM Analisis_Procesos.solicitudes s
                        WHERE s.usuario_tienda = :usuario 
                        GROUP BY s.estado_general";
    $stmt_cont = $pdo->prepare($sql_contadores);
    $stmt_cont->execute(['usuario' => $usuario_tienda]);
    $contadores_raw = $stmt_cont->fetchAll(PDO::FETCH_KEY_PAIR);

    $contadores = [
        'PENDIENTE' => $contadores_raw['PENDIENTE'] ?? 0,
        'APROBADA' => $contadores_raw['APROBADA'] ?? 0,
        'EN_PROCESO' => $contadores_raw['EN_PROCESO'] ?? 0,
        'PROCESADA' => $contadores_raw['PROCESADA'] ?? 0,
        'PROCESADA_PARCIAL' => $contadores_raw['PROCESADA_PARCIAL'] ?? 0,
        'RECHAZADA' => $contadores_raw['RECHAZADA'] ?? 0
    ];

    // Listado de solicitudes
    $sql_lista = "SELECT 
                    s.id_solicitud,
                    s.id_tienda,
                    s.id_familia,
                    s.estado_general as estado_actual,
                    DATE_FORMAT(s.fecha_solicitud, '%d-%m-%Y %H:%i') as fecha_solicitud,
                    s.observaciones_generales as observaciones,
                    COUNT(sc.id_detalle) as total_skus,
                    SUM(CASE WHEN sc.estado_item = 'PENDIENTE' THEN 1 ELSE 0 END) as skus_pendientes,
                    SUM(CASE WHEN sc.estado_item = 'RECHAZADO' THEN 1 ELSE 0 END) as skus_rechazados,
                    COALESCE(SUM(sc.carga_solicitada), 0) as total_cantidad_solicitada
                FROM Analisis_Procesos.solicitudes s
                LEFT JOIN Analisis_Procesos.solicitudes_carga sc 
                    ON s.id_solicitud = sc.id_solicitud
                $where
                GROUP BY s.id_solicitud
                ORDER BY s.fecha_solicitud DESC 
                LIMIT 100";
    
    $stmt_lista = $pdo->prepare($sql_lista);
    $stmt_lista->execute($params);
    $solicitudes = $stmt_lista->fetchAll();

    echo json_encode([
        'success' => true,
        'contadores' => $contadores,
        'solicitudes' => is_array($solicitudes) ? $solicitudes : []
    ]);

} catch (PDOException $e) {
    error_log("Error obtener mis solicitudes: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error de base de datos: ' . $e->getMessage()
    ]);
}
?>