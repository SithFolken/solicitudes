<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'analista') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // KPI 1: Solicitudes Pendientes
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM Analisis_Procesos.solicitudes WHERE estado_general = 'PENDIENTE'");
    $pendientes = $stmt->fetch()['total'] ?? 0;

    // KPI 2: Procesadas Hoy
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM Analisis_Procesos.solicitudes 
                         WHERE DATE(fecha_procesamiento) = CURDATE() 
                         AND estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')");
    $procesadasHoy = $stmt->fetch()['total'] ?? 0;

    // KPI 3 & 4: Tasa de Aprobación y Total SKUs (este mes)
    $stmt = $pdo->query("SELECT 
                            COUNT(sc.id_detalle) as total,
                            SUM(CASE WHEN sc.estado_item IN ('APROBADO', 'PENDIENTE') THEN 1 ELSE 0 END) as aprobados
                         FROM Analisis_Procesos.solicitudes_carga sc
                         INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
                         WHERE MONTH(s.fecha_solicitud) = MONTH(CURRENT_DATE()) 
                         AND YEAR(s.fecha_solicitud) = YEAR(CURRENT_DATE())");
    $datosSKUs = $stmt->fetch();
    $totalSKUs = $datosSKUs['total'] ?? 0;
    $aprobados = $datosSKUs['aprobados'] ?? 0;
    $tasaAprobacion = $totalSKUs > 0 ? round(($aprobados / $totalSKUs) * 100, 1) : 0;

    // Gráfico 1: Distribución por Estado
    $stmt = $pdo->query("SELECT estado_general, COUNT(*) as total 
                         FROM Analisis_Procesos.solicitudes 
                         GROUP BY estado_general");
    $porEstado = $stmt->fetchAll();

    // Gráfico 2: Solicitudes por Día (últimos 7 días)
    $stmt = $pdo->query("SELECT DATE(fecha_procesamiento) as fecha, COUNT(*) as total 
                         FROM Analisis_Procesos.solicitudes 
                         WHERE fecha_procesamiento >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                         AND estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')
                         GROUP BY DATE(fecha_procesamiento)
                         ORDER BY fecha ASC");
    $porDias = $stmt->fetchAll();

    // Gráfico 3: Por Método de Compra
    $stmt = $pdo->query("SELECT md, COUNT(*) as total 
                         FROM Analisis_Procesos.solicitudes_carga 
                         WHERE md IS NOT NULL AND md != ''
                         GROUP BY md");
    $porMD = $stmt->fetchAll();

    // Gráfico 4: Top 5 Tiendas (CORREGIDO: Mostrar ID de tienda)
    $stmt = $pdo->query("SELECT id_tienda, COUNT(*) as total 
                        FROM Analisis_Procesos.solicitudes 
                        WHERE id_tienda IS NOT NULL AND id_tienda > 0
                        GROUP BY id_tienda
                        ORDER BY total DESC
                        LIMIT 10");
    $topTiendas = $stmt->fetchAll();

        // ✅ Gráfico 5: Top 10 SKUs Más Solicitados (con drill-down por tienda)
    $stmt = $pdo->query("SELECT sc.sku, sc.descripcion_producto, COUNT(*) as total 
                         FROM Analisis_Procesos.solicitudes_carga sc
                         INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
                         WHERE s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL', 'PENDIENTE', 'EN_PROCESO')
                         GROUP BY sc.sku, sc.descripcion_producto
                         ORDER BY total DESC
                         LIMIT 10");
    $topSKUs = $stmt->fetchAll();

    // Para cada SKU top, obtener las tiendas que más lo solicitan
    $skusConTiendas = [];
    foreach ($topSKUs as $sku) {
        $stmt2 = $pdo->prepare("SELECT s.id_tienda, COUNT(*) as total 
                                FROM Analisis_Procesos.solicitudes_carga sc
                                INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
                                WHERE sc.sku = :sku
                                  AND s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL', 'PENDIENTE', 'EN_PROCESO')
                                GROUP BY s.id_tienda
                                ORDER BY total DESC
                                LIMIT 5");
        $stmt2->execute(['sku' => $sku['sku']]);
        $tiendas = $stmt2->fetchAll();
        
        $skusConTiendas[] = [
            'sku' => $sku['sku'],
            'descripcion' => $sku['descripcion_producto'] ?? '',
            'total' => $sku['total'],
            'tiendas' => $tiendas
        ];
    }

    echo json_encode([
        'success' => true,
        'kpis' => [
            'pendientes' => (int)$pendientes,
            'procesadasHoy' => (int)$procesadasHoy,
            'tasaAprobacion' => (float)$tasaAprobacion,
            'totalSKUs' => (int)$totalSKUs
        ],
        'graficos' => [
            'porEstado' => $porEstado,
            'porDias' => $porDias,
            'porMD' => $porMD,
            'topTiendas' => $topTiendas,
            'topSKUs' => $skusConTiendas 
        ]
    ]);

} catch (PDOException $e) {
    error_log("Error KPIs Analista: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error al cargar KPIs: ' . $e->getMessage(),
        'kpis' => [
            'pendientes' => 0,
            'procesadasHoy' => 0,
            'tasaAprobacion' => 0,
            'totalSKUs' => 0
        ],
        'graficos' => [
            'porEstado' => [],
            'porDias' => [],
            'porMD' => [],
            'topTiendas' => [],
            'porFamilias' => []
        ]
    ]);
}
?>