<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $id_tienda = $_SESSION['id_tienda'] ?? 0;
    $usuario_tienda = $_SESSION['user_id'];
    
    // KPI 1: Pendientes
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM Analisis_Procesos.solicitudes 
                           WHERE id_tienda = ? AND usuario_tienda = ? AND estado_general = 'PENDIENTE'");
    $stmt->execute([$id_tienda, $usuario_tienda]);
    $pendientes = $stmt->fetch()['total'] ?? 0;

    // KPI 2: Aprobadas (este mes)
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM Analisis_Procesos.solicitudes 
                           WHERE id_tienda = ? AND usuario_tienda = ? 
                           AND estado_general = 'APROBADA' 
                           AND MONTH(fecha_solicitud) = MONTH(CURRENT_DATE())");
    $stmt->execute([$id_tienda, $usuario_tienda]);
    $aprobadas = $stmt->fetch()['total'] ?? 0;

    // KPI 3: Procesadas
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM Analisis_Procesos.solicitudes 
                           WHERE id_tienda = ? AND usuario_tienda = ? 
                           AND estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')");
    $stmt->execute([$id_tienda, $usuario_tienda]);
    $procesadas = $stmt->fetch()['total'] ?? 0;

    // KPI 4: Rechazadas
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM Analisis_Procesos.solicitudes 
                           WHERE id_tienda = ? AND usuario_tienda = ? AND estado_general = 'RECHAZADA'");
    $stmt->execute([$id_tienda, $usuario_tienda]);
    $rechazadas = $stmt->fetch()['total'] ?? 0;

    // Gráfico 1: Por Estado
    $stmt = $pdo->prepare("SELECT estado_general, COUNT(*) as total 
                           FROM Analisis_Procesos.solicitudes 
                           WHERE id_tienda = ? AND usuario_tienda = ?
                           GROUP BY estado_general");
    $stmt->execute([$id_tienda, $usuario_tienda]);
    $porEstado = $stmt->fetchAll();

    // Gráfico 2: Por Mes (últimos 6 meses)
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(fecha_solicitud, '%Y-%m') as mes, COUNT(*) as total 
                           FROM Analisis_Procesos.solicitudes 
                           WHERE id_tienda = ? AND usuario_tienda = ?
                           AND fecha_solicitud >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                           GROUP BY DATE_FORMAT(fecha_solicitud, '%Y-%m')
                           ORDER BY mes ASC");
    $stmt->execute([$id_tienda, $usuario_tienda]);
    $porMes = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'kpis' => [
            'pendientes' => (int)$pendientes,
            'aprobadas' => (int)$aprobadas,
            'procesadas' => (int)$procesadas,
            'rechazadas' => (int)$rechazadas
        ],
        'graficos' => [
            'porEstado' => $porEstado,
            'porMes' => $porMes
        ]
    ]);

} catch (PDOException $e) {
    error_log("Error KPIs Tienda: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al cargar KPIs']);
}
?>