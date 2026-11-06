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
    $nombre_analista_sesion = $_SESSION['nombre'] ?? '';
    
    // ==========================================
    // 1. LÓGICA DE FILTRO UNIFICADA (3 NIVELES + REEMPLAZOS)
    // ==========================================
    $where_analista = "";
    $params = [];

    // ✅ Cargar helper de reemplazos
    require_once __DIR__ . '/helpers_reemplazos.php';
    $analistas_efectivos = obtenerAnalistasEfectivos($pdo, $usuario_actual);

    // NIVEL 1: Buscar por SUBFAMILIAS (Sistema Nuevo)
    $params_sub = [];
    $placeholders_sub = [];
    foreach ($analistas_efectivos as $index => $usr) {
        $param_name = 'usr_sub_' . $index;
        $placeholders_sub[] = ':' . $param_name;
        $params_sub[$param_name] = $usr;
    }
    
    $sql_sub = "SELECT DISTINCT id_sub_familia FROM input_clientes.planificacion_asignacion_equipos 
                WHERE analista_repo_tienda IN (" . implode(',', $placeholders_sub) . ")";
    $stmt_sub = $pdo->prepare($sql_sub);
    $stmt_sub->execute($params_sub); // ✅ Solo los parámetros de esta consulta
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
        
        $where_analista = "AND (s.id_sub_familia IN (" . implode(',', $placeholders_fam_sub) . ") OR s.usuario_proceso IN (" . implode(',', $placeholders_proc) . "))";
        $params = array_merge($params_fam_sub, $params_proc);
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
        $stmt_fam->execute($params_usr); // ✅ Solo los parámetros de esta consulta
        $familias_asignadas = $stmt_fam->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($familias_asignadas)) {
            $params_fam = [];
            $placeholders_fam = [];
            foreach ($familias_asignadas as $index => $fam) {
                $param_name = 'fam_' . $index;
                $placeholders_fam[] = ':' . $param_name;
                $params_fam[$param_name] = $fam;
            }
            $where_analista = "AND s.id_familia IN (" . implode(',', $placeholders_fam) . ")";
            $params = $params_fam;
        } else {
            // NIVEL 3 (EMERGENCIA): Filtrar solo por usuario_proceso
            $params_proc3 = [];
            $placeholders_proc3 = [];
            foreach ($analistas_efectivos as $index => $usr) {
                $param_name = 'usr_proc3_' . $index;
                $placeholders_proc3[] = ':' . $param_name;
                $params_proc3[$param_name] = $usr;
            }
            $where_analista = "AND s.usuario_proceso IN (" . implode(',', $placeholders_proc3) . ")";
            $params = $params_proc3;
        }
    }

    // ==========================================
    // 2. KPIs (Usando el WHERE unificado y alias 's')
    // ==========================================
    
    // Pendientes
    $sql_pendientes = "SELECT COUNT(*) as total FROM Analisis_Procesos.solicitudes s WHERE s.estado_general = 'PENDIENTE' $where_analista";
    $stmt = $pdo->prepare($sql_pendientes);
    $stmt->execute($params);
    $pendientes = (int)($stmt->fetch()['total'] ?? 0);

    // Procesadas Hoy
    $sql_hoy = "SELECT COUNT(*) as total FROM Analisis_Procesos.solicitudes s WHERE DATE(s.fecha_procesamiento) = CURDATE() AND s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL') $where_analista";
    $stmt = $pdo->prepare($sql_hoy);
    $stmt->execute($params);
    $procesadasHoy = (int)($stmt->fetch()['total'] ?? 0);

    // Tasa de Aprobación (Mes actual)
    $sql_tasa = "SELECT COUNT(sc.id_detalle) as total_procesados, SUM(CASE WHEN sc.estado_item = 'APROBADO' THEN 1 ELSE 0 END) as aprobados 
                 FROM Analisis_Procesos.solicitudes_carga sc 
                 INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud 
                 WHERE sc.estado_item IN ('APROBADO', 'RECHAZADO') 
                 AND MONTH(s.fecha_solicitud) = MONTH(CURRENT_DATE()) AND YEAR(s.fecha_solicitud) = YEAR(CURRENT_DATE()) 
                 $where_analista";
    $stmt_tasa = $pdo->prepare($sql_tasa);
    $stmt_tasa->execute($params);
    $datosTasa = $stmt_tasa->fetch();
    $totalProcesados = (int)($datosTasa['total_procesados'] ?? 0);
    $aprobados = (int)($datosTasa['aprobados'] ?? 0);
    $tasaAprobacion = $totalProcesados > 0 ? round(($aprobados / $totalProcesados) * 100, 1) : 0.0;

    // Total SKUs (Mes actual)
    $sql_total_skus = "SELECT COUNT(sc.id_detalle) as total_mes FROM Analisis_Procesos.solicitudes_carga sc INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud WHERE MONTH(s.fecha_solicitud) = MONTH(CURRENT_DATE()) AND YEAR(s.fecha_solicitud) = YEAR(CURRENT_DATE()) $where_analista";
    $stmt_total = $pdo->prepare($sql_total_skus);
    $stmt_total->execute($params);
    $totalSKUs = (int)($stmt_total->fetch()['total_mes'] ?? 0);

    // SLA (Tiempos)
    $sql_sla = "SELECT 
                    SUM(CASE WHEN TIMESTAMPDIFF(HOUR, s.fecha_solicitud, NOW()) <= 36 THEN 1 ELSE 0 END) as a_tiempo, 
                    SUM(CASE WHEN TIMESTAMPDIFF(HOUR, s.fecha_solicitud, NOW()) > 36 AND TIMESTAMPDIFF(HOUR, s.fecha_solicitud, NOW()) <= 48 THEN 1 ELSE 0 END) as por_cumplirse, 
                    SUM(CASE WHEN TIMESTAMPDIFF(HOUR, s.fecha_solicitud, NOW()) > 48 THEN 1 ELSE 0 END) as vencidas 
                FROM Analisis_Procesos.solicitudes s 
                WHERE s.estado_general IN ('PENDIENTE', 'EN_PROCESO') $where_analista";
    $stmt_sla = $pdo->prepare($sql_sla);
    $stmt_sla->execute($params);
    $tiempos = $stmt_sla->fetch();

    // ==========================================
    // 3. GRÁFICOS (Usando el WHERE unificado y alias 's')
    // ==========================================
    
    // Por Estado
    $stmt = $pdo->prepare("SELECT s.estado_general, COUNT(*) as total FROM Analisis_Procesos.solicitudes s WHERE 1=1 $where_analista GROUP BY s.estado_general");
    $stmt->execute($params);
    $porEstado = $stmt->fetchAll();

    // Por Días (Últimos 7 días)
    $stmt = $pdo->prepare("SELECT DATE(s.fecha_procesamiento) as fecha, COUNT(*) as total FROM Analisis_Procesos.solicitudes s WHERE s.fecha_procesamiento >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL') $where_analista GROUP BY DATE(s.fecha_procesamiento) ORDER BY fecha ASC");
    $stmt->execute($params);
    $porDias = $stmt->fetchAll();

    // Por Método de Compra (MD)
    $stmt = $pdo->prepare("SELECT sc.md, COUNT(*) as total FROM Analisis_Procesos.solicitudes_carga sc INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud WHERE sc.md IS NOT NULL AND sc.md != '' $where_analista GROUP BY sc.md");
    $stmt->execute($params);
    $porMD = $stmt->fetchAll();

    // Top Tiendas
    $stmt = $pdo->prepare("SELECT s.id_tienda, COUNT(*) as total FROM Analisis_Procesos.solicitudes s WHERE s.id_tienda IS NOT NULL AND s.id_tienda > 0 $where_analista GROUP BY s.id_tienda ORDER BY total DESC LIMIT 10");
    $stmt->execute($params);
    $topTiendas = $stmt->fetchAll();

    // Top SKUs
    $stmt = $pdo->prepare("SELECT sc.sku, sc.descripcion_producto, COUNT(*) as total FROM Analisis_Procesos.solicitudes_carga sc INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud WHERE s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL', 'PENDIENTE', 'EN_PROCESO') $where_analista GROUP BY sc.sku, sc.descripcion_producto ORDER BY total DESC LIMIT 10");
    $stmt->execute($params);
    $topSKUs = $stmt->fetchAll();

    // Drilldown de Tiendas por SKU
    $skusConTiendas = [];
    foreach ($topSKUs as $sku) {
        $sql_drill = "SELECT s.id_tienda, COUNT(*) as total FROM Analisis_Procesos.solicitudes_carga sc INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud WHERE sc.sku = :sku AND s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL', 'PENDIENTE', 'EN_PROCESO') $where_analista GROUP BY s.id_tienda ORDER BY total DESC LIMIT 5";
        $stmt2 = $pdo->prepare($sql_drill);
        // Combinar parámetros del SKU con los del analista
        $params_drill = array_merge(['sku' => $sku['sku']], $params);
        $stmt2->execute($params_drill);
        $skusConTiendas[] = [
            'sku' => $sku['sku'], 
            'descripcion' => $sku['descripcion_producto'] ?? '', 
            'total' => (int)$sku['total'], 
            'tiendas' => $stmt2->fetchAll()
        ];
    }

    echo json_encode([
        'success' => true,
        'kpis' => [
            'pendientes' => $pendientes, 
            'procesadasHoy' => $procesadasHoy, 
            'tasaAprobacion' => $tasaAprobacion, 
            'totalSKUs' => $totalSKUs,
            'sla_a_tiempo' => (int)($tiempos['a_tiempo'] ?? 0), 
            'sla_por_cumplirse' => (int)($tiempos['por_cumplirse'] ?? 0), 
            'sla_vencidas' => (int)($tiempos['vencidas'] ?? 0)
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
    echo json_encode(['success' => false, 'message' => 'Error al cargar KPIs: ' . $e->getMessage()]);
}
?>