<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'supervisor') {
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';
require_once 'obtener_familias_supervisor.php';

try {
    $usuario_actual = $_SESSION['user_id'] ?? '';
    $nombre_supervisor = $_SESSION['nombre'] ?? 'Supervisor';

    $fecha_fin = date('Y-m-d');
    $fecha_inicio = date('Y-m-d', strtotime('-30 days'));
    
    if (isset($_GET['fecha_inicio'])) $fecha_inicio = $_GET['fecha_inicio'];
    if (isset($_GET['fecha_fin'])) $fecha_fin = $_GET['fecha_fin'];

    // 1. Obtener familias del supervisor (ahora retorna centenas: [1, 2] para "100-200")
    $familias_supervisor = obtenerFamiliasSupervisor($pdo, $usuario_actual);
    
    // 2. Construir cláusula WHERE dinámica
    $where_familias_s = "";
    $params_base = ['inicio' => $fecha_inicio, 'fin' => $fecha_fin];
    $params_familias = [];

    if ($familias_supervisor !== 'TODAS' && !empty($familias_supervisor)) {
        // ✅ NUEVA LÓGICA: Usar FLOOR(id_familia/100) IN (1, 2) para "100-200"
        $placeholders = [];
        foreach ($familias_supervisor as $index => $centena) {
            $param_name = 'fam_' . $index;
            $placeholders[] = ':' . $param_name;
            $params_familias[$param_name] = $centena;
        }
        $where_familias_s = " AND FLOOR(s.id_familia / 100) IN (" . implode(',', $placeholders) . ")";
    }
    
    // Combinar todos los parámetros
    $all_params = array_merge($params_base, $params_familias);

    // ==========================================
    // 1. KPIs GENERALES
    // ==========================================
    $sql_general = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN estado_general = 'PENDIENTE' THEN 1 ELSE 0 END) as pendientes,
                        SUM(CASE WHEN estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL') THEN 1 ELSE 0 END) as procesadas,
                        SUM(CASE WHEN estado_general = 'RECHAZADA' THEN 1 ELSE 0 END) as rechazadas,
                        SUM(CASE WHEN estado_general = 'EN_PROCESO' THEN 1 ELSE 0 END) as en_proceso
                    FROM Analisis_Procesos.solicitudes s
                    WHERE DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin $where_familias_s";
    
    $stmt_gen = $pdo->prepare($sql_general);
    $stmt_gen->execute($all_params);
    $kpis_generales = $stmt_gen->fetch(PDO::FETCH_ASSOC);
    
    $total = (int)($kpis_generales['total'] ?? 0);
    $rechazadas = (int)($kpis_generales['rechazadas'] ?? 0);
    $kpis_generales['tasa_rechazo'] = $total > 0 ? round(($rechazadas / $total) * 100, 1) : 0;

    // ==========================================
    // 2. SOLICITUDES CON RETRASO (✅ CORREGIDO: Ahora incluye PENDIENTE y EN_PROCESO para cuadrar con el detalle)
    // ==========================================
    $sql_retraso = "SELECT 
                        COUNT(*) as total_retrasadas,
                        ROUND(AVG(TIMESTAMPDIFF(HOUR, s.fecha_solicitud, NOW())), 1) as promedio_horas_retraso
                    FROM Analisis_Procesos.solicitudes s
                    WHERE s.estado_general IN ('PENDIENTE', 'EN_PROCESO')
                      AND s.fecha_solicitud < DATE_SUB(NOW(), INTERVAL 48 HOUR)
                      AND DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin 
                      $where_familias_s";
    
    $stmt_retraso = $pdo->prepare($sql_retraso);
    $stmt_retraso->execute($all_params);
    $solicitudes_retrasadas = $stmt_retraso->fetch(PDO::FETCH_ASSOC);

    // ==========================================
    // 3. DISTRIBUCIÓN POR MD
    // ==========================================
    $sql_md = "SELECT 
                    CASE 
                        WHEN UPPER(sc.md) IN ('TRF', 'TRANSFERENCIA') THEN 'TRANSFERENCIA'
                        WHEN UPPER(sc.md) IN ('XD', 'CROSS_DOCKING') THEN 'CROSS DOCKING'
                        WHEN UPPER(sc.md) IN ('CL', 'COMPRA_LOCAL') THEN 'COMPRA LOCAL'
                        ELSE 'OTROS' 
                    END as md_grupo,
                    COUNT(DISTINCT sc.id_solicitud) as cantidad
                FROM Analisis_Procesos.solicitudes_carga sc
                INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
                WHERE DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin
                  AND sc.estado_item != 'RECHAZADO'
                  $where_familias_s
                GROUP BY md_grupo
                ORDER BY cantidad DESC";
    
    $stmt_md = $pdo->prepare($sql_md);
    $stmt_md->execute($all_params);
    $distribucion_md = $stmt_md->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // 4. DESEMPEÑO POR ANALISTA
    // ==========================================
    $sql_analistas = "SELECT 
                        COALESCE(
                            u.nombre, 
                            s.usuario_proceso,
                            'Sin Asignar'
                        ) as nombre_analista,
                        COUNT(s.id_solicitud) as total_procesadas,
                        SUM(CASE WHEN s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL') THEN 1 ELSE 0 END) as aprobadas,
                        SUM(CASE WHEN s.estado_general = 'RECHAZADA' THEN 1 ELSE 0 END) as rechazadas,
                        SUM(CASE WHEN s.estado_general = 'PENDIENTE' THEN 1 ELSE 0 END) as pendientes,
                        SUM(CASE WHEN s.estado_general = 'EN_PROCESO' THEN 1 ELSE 0 END) as en_proceso,
                        SUM(CASE WHEN s.estado_general IN ('PENDIENTE', 'EN_PROCESO') 
                                 AND s.fecha_solicitud < DATE_SUB(NOW(), INTERVAL 48 HOUR) 
                            THEN 1 ELSE 0 END) as con_retraso,
                        ROUND(AVG(CASE 
                            WHEN s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL', 'RECHAZADA') 
                            THEN TIMESTAMPDIFF(HOUR, s.fecha_solicitud, s.fecha_procesamiento) 
                            ELSE NULL 
                        END), 1) as tiempo_promedio_horas
                      FROM Analisis_Procesos.solicitudes s
                      LEFT JOIN rct.usuarios_solicitudes_oc u ON s.usuario_proceso = u.usuario
                      WHERE DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin
                      $where_familias_s
                      GROUP BY nombre_analista
                      ORDER BY total_procesadas DESC";
    
    $stmt_analistas = $pdo->prepare($sql_analistas);
    $stmt_analistas->execute($all_params);
    $desempeno_analistas = $stmt_analistas->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // 5. TOP 10 TIENDAS
    // ==========================================
    $sql_tiendas = "SELECT 
                        CONCAT('ID: ', s.id_tienda,'-', COALESCE(t.nombre_tienda, 'Desconocida')) as nombre_tienda,
                        COUNT(DISTINCT s.id_solicitud) as total_solicitudes,
                        COUNT(DISTINCT CASE WHEN s.estado_general = 'RECHAZADA' THEN s.id_solicitud END) as rechazadas,
                        ROUND((COUNT(DISTINCT CASE WHEN s.estado_general = 'RECHAZADA' THEN s.id_solicitud END) / NULLIF(COUNT(DISTINCT s.id_solicitud), 0)) * 100, 1) as tasa_rechazo
                    FROM Analisis_Procesos.solicitudes s
                    LEFT JOIN sodimac_grt.tiendas t ON s.id_tienda = t.id_tienda
                    WHERE DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin
                    $where_familias_s
                    GROUP BY s.id_tienda, t.nombre_tienda
                    HAVING total_solicitudes > 0
                    ORDER BY total_solicitudes DESC
                    LIMIT 10";
    
    $stmt_tiendas = $pdo->prepare($sql_tiendas);
    $stmt_tiendas->execute($all_params);
    $top_tiendas = $stmt_tiendas->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // 6. TOP FAMILIAS
    // ==========================================
    $sql_familias = "SELECT 
                        s.id_familia,
                        CONCAT('Familia ', s.id_familia) as descripcion_familia,
                        COUNT(s.id_solicitud) as total_solicitudes,
                        SUM(CASE WHEN s.estado_general = 'RECHAZADA' THEN 1 ELSE 0 END) as rechazadas,
                        ROUND((SUM(CASE WHEN s.estado_general = 'RECHAZADA' THEN 1 ELSE 0 END) / NULLIF(COUNT(s.id_solicitud), 0)) * 100, 1) as tasa_rechazo
                    FROM Analisis_Procesos.solicitudes s
                    WHERE DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin
                    $where_familias_s
                    GROUP BY s.id_familia
                    HAVING total_solicitudes > 0
                    ORDER BY total_solicitudes DESC
                    LIMIT 10";
    
    $stmt_familias = $pdo->prepare($sql_familias);
    $stmt_familias->execute($all_params);
    $top_familias = $stmt_familias->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // 7. MOTIVOS MÁS COMUNES
    // ==========================================
    $sql_total = "SELECT COUNT(*) as total FROM Analisis_Procesos.solicitudes s 
                  WHERE DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin $where_familias_s";
    $stmt_total = $pdo->prepare($sql_total);
    $stmt_total->execute($all_params);
    $total_solicitudes = (int)($stmt_total->fetchColumn() ?? 1);

    $sql_motivos = "SELECT 
                        COALESCE(motivo_carga, 'Sin Motivo') as motivo_carga,
                        COUNT(*) as cantidad
                    FROM Analisis_Procesos.solicitudes s
                    WHERE DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin
                    $where_familias_s
                    GROUP BY motivo_carga
                    ORDER BY cantidad DESC
                    LIMIT 8";
    $stmt_motivos = $pdo->prepare($sql_motivos);
    $stmt_motivos->execute($all_params);
    $top_motivos_raw = $stmt_motivos->fetchAll(PDO::FETCH_ASSOC);
    
    $top_motivos = array_map(function($m) use ($total_solicitudes) {
        $m['porcentaje'] = $total_solicitudes > 0 ? round(($m['cantidad'] / $total_solicitudes) * 100, 1) : 0;
        return $m;
    }, $top_motivos_raw);

    // ==========================================
    // 8. SKUs CON MÁS RECHAZOS
    // ==========================================
    $sql_skus_problema = "SELECT 
                            sc.sku,
                            sc.descripcion_producto,
                            COUNT(*) as veces_rechazado,
                            GROUP_CONCAT(DISTINCT sc.campo_cambios SEPARATOR ' | ') as motivos_rechazo
                        FROM Analisis_Procesos.solicitudes_carga sc
                        INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
                        WHERE DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin
                          AND sc.estado_item = 'RECHAZADO'
                          $where_familias_s
                        GROUP BY sc.sku, sc.descripcion_producto
                        ORDER BY veces_rechazado DESC
                        LIMIT 10";
    $stmt_skus = $pdo->prepare($sql_skus_problema);
    $stmt_skus->execute($all_params);
    $skus_problema = $stmt_skus->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // 9. MOTIVOS DE RECHAZO GENERALIZADOS
    // ==========================================
    $sql_motivos_categorias = "SELECT 
        CASE 
            WHEN sc.campo_cambios LIKE '%SDS actual%' AND sc.campo_cambios LIKE '%excede%' THEN 'SDS excede máximo'
            WHEN sc.campo_cambios LIKE '%Cantidad excesiva%' OR sc.campo_cambios LIKE '%alcanzarías%' THEN 'Cantidad excesiva'
            WHEN sc.campo_cambios LIKE '%Fuera del mix%' THEN 'Fuera del mix'
            WHEN sc.campo_cambios LIKE '%Stock insuficiente%' OR sc.campo_cambios LIKE '%sin stock%' THEN 'Stock insuficiente'
            WHEN sc.campo_cambios LIKE '%Proveedor%' THEN 'Proveedor no disponible'
            WHEN sc.campo_cambios LIKE '%Conectar al JL%' OR sc.campo_cambios LIKE '%JL para cargar%' THEN 'Requiere aprobación JL'
            ELSE 'Otros motivos'
        END as categoria_motivo,
        COUNT(*) as cantidad
    FROM Analisis_Procesos.solicitudes_carga sc
    INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
    WHERE DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin
      AND sc.estado_item = 'RECHAZADO'
      AND sc.campo_cambios IS NOT NULL
      $where_familias_s
    GROUP BY categoria_motivo
    ORDER BY cantidad DESC";
    
    $stmt_motivos_cat = $pdo->prepare($sql_motivos_categorias);
    $stmt_motivos_cat->execute($all_params);
    $motivos_categorias = $stmt_motivos_cat->fetchAll(PDO::FETCH_ASSOC);
    
    $sql_motivos_tienda = "SELECT 
        COALESCE(
            (SELECT DISTINCT t2.nombre_tienda FROM rct.sugerido_diario t2 WHERE t2.id_tienda = s.id_tienda LIMIT 1),
            CONCAT('Tienda ID: ', s.id_tienda)
        ) as nombre_tienda,
        CASE 
            WHEN sc.campo_cambios LIKE '%SDS actual%' AND sc.campo_cambios LIKE '%excede%' THEN 'SDS excede máximo'
            WHEN sc.campo_cambios LIKE '%Cantidad excesiva%' OR sc.campo_cambios LIKE '%alcanzarías%' THEN 'Cantidad excesiva'
            WHEN sc.campo_cambios LIKE '%Fuera del mix%' THEN 'Fuera del mix'
            ELSE 'Otros'
        END as categoria_motivo,
        COUNT(*) as cantidad
    FROM Analisis_Procesos.solicitudes_carga sc
    INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
    WHERE DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin
      AND sc.estado_item = 'RECHAZADO'
      AND sc.campo_cambios IS NOT NULL
      $where_familias_s
    GROUP BY s.id_tienda, categoria_motivo
    ORDER BY cantidad DESC";
    
    $stmt_motivos_tienda = $pdo->prepare($sql_motivos_tienda);
    $stmt_motivos_tienda->execute($all_params);
    $motivos_por_tienda = $stmt_motivos_tienda->fetchAll(PDO::FETCH_ASSOC);
    
    $sql_motivos_familia = "SELECT 
        s.id_familia,
        CASE 
            WHEN sc.campo_cambios LIKE '%SDS actual%' AND sc.campo_cambios LIKE '%excede%' THEN 'SDS excede máximo'
            WHEN sc.campo_cambios LIKE '%Cantidad excesiva%' OR sc.campo_cambios LIKE '%alcanzarías%' THEN 'Cantidad excesiva'
            WHEN sc.campo_cambios LIKE '%Fuera del mix%' THEN 'Fuera del mix'
            ELSE 'Otros'
        END as categoria_motivo,
        COUNT(*) as cantidad
    FROM Analisis_Procesos.solicitudes_carga sc
    INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
    WHERE DATE(s.fecha_solicitud) BETWEEN :inicio AND :fin
      AND sc.estado_item = 'RECHAZADO'
      AND sc.campo_cambios IS NOT NULL
      $where_familias_s
    GROUP BY s.id_familia, categoria_motivo
    ORDER BY cantidad DESC";
    
    $stmt_motivos_familia = $pdo->prepare($sql_motivos_familia);
    $stmt_motivos_familia->execute($all_params);
    $motivos_por_familia = $stmt_motivos_familia->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // RESPUESTA JSON
    // ==========================================
    echo json_encode([
        'success' => true,
        'supervisor' => [
            'nombre' => $nombre_supervisor,
            'rol' => 'supervisor',
            'vista' => ($familias_supervisor === 'TODAS') ? 'Global (Todas las familias)' : 'Filtrada por familias asignadas'
        ],
        'kpis_generales' => $kpis_generales,
        'solicitudes_retrasadas' => $solicitudes_retrasadas,
        'distribucion_md' => $distribucion_md,
        'desempeno_analistas' => $desempeno_analistas,
        'top_tiendas' => $top_tiendas,
        'top_familias' => $top_familias,
        'top_motivos' => $top_motivos,
        'skus_problema' => $skus_problema,
        'motivos_categorias' => $motivos_categorias,
        'motivos_por_tienda' => $motivos_por_tienda,
        'motivos_por_familia' => $motivos_por_familia,
        'familias_acceso' => $familias_supervisor,
        'es_global' => ($familias_supervisor === 'TODAS')
    ]);

} catch (PDOException $e) {
    error_log("Error KPIs Supervisor: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>