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
    $usuario_actual = $_SESSION['user_id'] ?? '';

    // ==========================================
    // 1. OBTENER FAMILIAS ASIGNADAS (CONSULTA MAESTRA)
    // ==========================================
    $sql_familias = "SELECT DISTINCT d.Familia as id_familia
                     FROM FulFillment.distribucion_analista_familia d
                     INNER JOIN rct.usuarios_solicitudes_oc u 
                         ON d.Analista_reposicion_tienda = u.nombre
                     WHERE u.usuario = :usuario_analista";
    
    $stmt_familias = $pdo->prepare($sql_familias);
    $stmt_familias->execute(['usuario_analista' => $usuario_actual]);
    $familias_asignadas = $stmt_familias->fetchAll(PDO::FETCH_COLUMN);

    if (empty($familias_asignadas)) {
        echo json_encode([
            'success' => true,
            'kpis' => ['pendientes' => 0, 'procesadasHoy' => 0, 'tasaAprobacion' => 0, 'totalSKUs' => 0, 'sla_a_tiempo' => 0, 'sla_por_cumplirse' => 0, 'sla_vencidas' => 0],
            'graficos' => ['porEstado' => [], 'porDias' => [], 'porMD' => [], 'topTiendas' => [], 'topSKUs' => []]
        ]);
        exit;
    }

    $familias_params = [];
    $placeholders = [];
    foreach ($familias_asignadas as $index => $familia) {
        $param_name = 'fam_' . $index;
        $placeholders[] = ':' . $param_name;
        $familias_params[$param_name] = $familia;
    }
    $familias_in_clause = "IN (" . implode(',', $placeholders) . ")";

    // ==========================================
    // 2. KPIs (Filtrados por familias del analista)
    // ==========================================
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM Analisis_Procesos.solicitudes WHERE estado_general = 'PENDIENTE' AND id_familia $familias_in_clause");
    $stmt->execute($familias_params);
    $pendientes = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM Analisis_Procesos.solicitudes WHERE DATE(fecha_procesamiento) = CURDATE() AND estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL') AND id_familia $familias_in_clause");
    $stmt->execute($familias_params);
    $procesadasHoy = $stmt->fetch()['total'] ?? 0;

    $stmt_tasa = $pdo->prepare("SELECT COUNT(sc.id_detalle) as total_procesados, SUM(CASE WHEN sc.estado_item = 'APROBADO' THEN 1 ELSE 0 END) as aprobados FROM Analisis_Procesos.solicitudes_carga sc INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud WHERE sc.estado_item IN ('APROBADO', 'RECHAZADO') AND MONTH(s.fecha_solicitud) = MONTH(CURRENT_DATE()) AND YEAR(s.fecha_solicitud) = YEAR(CURRENT_DATE()) AND s.id_familia $familias_in_clause");
    $stmt_tasa->execute($familias_params);
    $datosTasa = $stmt_tasa->fetch();
    $totalProcesados = $datosTasa['total_procesados'] ?? 0;
    $aprobados = $datosTasa['aprobados'] ?? 0;
    $tasaAprobacion = $totalProcesados > 0 ? round(($aprobados / $totalProcesados) * 100, 1) : 0;

    $stmt_total = $pdo->prepare("SELECT COUNT(sc.id_detalle) as total_mes FROM Analisis_Procesos.solicitudes_carga sc INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud WHERE MONTH(s.fecha_solicitud) = MONTH(CURRENT_DATE()) AND YEAR(s.fecha_solicitud) = YEAR(CURRENT_DATE()) AND s.id_familia $familias_in_clause");
    $stmt_total->execute($familias_params);
    $totalSKUs = $stmt_total->fetch()['total_mes'] ?? 0;

    $stmt = $pdo->prepare("SELECT SUM(CASE WHEN TIMESTAMPDIFF(HOUR, fecha_solicitud, NOW()) <= 36 THEN 1 ELSE 0 END) as a_tiempo, SUM(CASE WHEN TIMESTAMPDIFF(HOUR, fecha_solicitud, NOW()) > 36 AND TIMESTAMPDIFF(HOUR, fecha_solicitud, NOW()) <= 48 THEN 1 ELSE 0 END) as por_cumplirse, SUM(CASE WHEN TIMESTAMPDIFF(HOUR, fecha_solicitud, NOW()) > 48 THEN 1 ELSE 0 END) as vencidas FROM Analisis_Procesos.solicitudes WHERE estado_general IN ('PENDIENTE', 'EN_PROCESO') AND id_familia $familias_in_clause");
    $stmt->execute($familias_params);
    $tiempos = $stmt->fetch();

    // ==========================================
    // 3. GRÁFICOS (Filtrados por familias del analista)
    // ==========================================
    $stmt = $pdo->prepare("SELECT estado_general, COUNT(*) as total FROM Analisis_Procesos.solicitudes WHERE id_familia $familias_in_clause GROUP BY estado_general");
    $stmt->execute($familias_params);
    $porEstado = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT DATE(fecha_procesamiento) as fecha, COUNT(*) as total FROM Analisis_Procesos.solicitudes WHERE fecha_procesamiento >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL') AND id_familia $familias_in_clause GROUP BY DATE(fecha_procesamiento) ORDER BY fecha ASC");
    $stmt->execute($familias_params);
    $porDias = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT sc.md, COUNT(*) as total FROM Analisis_Procesos.solicitudes_carga sc INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud WHERE sc.md IS NOT NULL AND sc.md != '' AND s.id_familia $familias_in_clause GROUP BY sc.md");
    $stmt->execute($familias_params);
    $porMD = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id_tienda, COUNT(*) as total FROM Analisis_Procesos.solicitudes WHERE id_tienda IS NOT NULL AND id_tienda > 0 AND id_familia $familias_in_clause GROUP BY id_tienda ORDER BY total DESC LIMIT 10");
    $stmt->execute($familias_params);
    $topTiendas = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT sc.sku, sc.descripcion_producto, COUNT(*) as total FROM Analisis_Procesos.solicitudes_carga sc INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud WHERE s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL', 'PENDIENTE', 'EN_PROCESO') AND s.id_familia $familias_in_clause GROUP BY sc.sku, sc.descripcion_producto ORDER BY total DESC LIMIT 10");
    $stmt->execute($familias_params);
    $topSKUs = $stmt->fetchAll();

    $skusConTiendas = [];
    foreach ($topSKUs as $sku) {
        $stmt2 = $pdo->prepare("SELECT s.id_tienda, COUNT(*) as total FROM Analisis_Procesos.solicitudes_carga sc INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud WHERE sc.sku = :sku AND s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL', 'PENDIENTE', 'EN_PROCESO') AND s.id_familia $familias_in_clause GROUP BY s.id_tienda ORDER BY total DESC LIMIT 5");
        $params_drill = array_merge(['sku' => $sku['sku']], $familias_params);
        $stmt2->execute($params_drill);
        $skusConTiendas[] = ['sku' => $sku['sku'], 'descripcion' => $sku['descripcion_producto'] ?? '', 'total' => $sku['total'], 'tiendas' => $stmt2->fetchAll()];
    }

    echo json_encode([
        'success' => true,
        'kpis' => [
            'pendientes' => (int)$pendientes, 'procesadasHoy' => (int)$procesadasHoy, 'tasaAprobacion' => (float)$tasaAprobacion, 'totalSKUs' => (int)$totalSKUs,
            'sla_a_tiempo' => (int)($tiempos['a_tiempo'] ?? 0), 'sla_por_cumplirse' => (int)($tiempos['por_cumplirse'] ?? 0), 'sla_vencidas' => (int)($tiempos['vencidas'] ?? 0)
        ],
        'graficos' => ['porEstado' => $porEstado, 'porDias' => $porDias, 'porMD' => $porMD, 'topTiendas' => $topTiendas, 'topSKUs' => $skusConTiendas]
    ]);

} catch (PDOException $e) {
    error_log("Error KPIs Analista: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al cargar KPIs: ' . $e->getMessage()]);
}
?>