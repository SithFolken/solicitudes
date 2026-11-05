<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (function_exists('opcache_reset')) {
    opcache_reset();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] !== 'analista' && $_SESSION['rol'] !== 'ejecutor')) {
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';

try {
    $usuario_actual = $_SESSION['user_id'] ?? '';
    $rol_actual = $_SESSION['rol'] ?? '';
    $nombre_analista = $_SESSION['nombre'] ?? ''; // ✅ Necesario para el LIKE

    $ciclo_nombre = isset($_GET['ciclo']) ? $_GET['ciclo'] : date('Y-m-d');
    $es_rescate = (isset($_GET['tipo']) && $_GET['tipo'] === 'rescate');
    $tipo_exportacion = $es_rescate ? 'ATRASADO' : 'NORMAL';
    $nombre_ciclo_archivo = $es_rescate ? 'RESCATE_' . $ciclo_nombre : $ciclo_nombre;
    
    if (!isset($_GET['fecha_inicio']) || !isset($_GET['fecha_fin'])) {
        throw new Exception('No se recibieron las fechas del ciclo.');
    }
    
    $fecha_corte_inicio = $_GET['fecha_inicio'];
    $fecha_corte_fin = $_GET['fecha_fin'];

    $where_familia = '';
    $params_familia = [];
    
    if ($rol_actual === 'analista') {
        // ✅ CORREGIDO: Usar LIKE para manejar múltiples analistas separados por '|'
        $sql_familias = "SELECT DISTINCT d.Familia as id_familia
                         FROM FulFillment.distribucion_analista_familia d
                         INNER JOIN rct.usuarios_solicitudes_oc u 
                             ON d.Analista_reposicion_tienda LIKE CONCAT('%', u.nombre, '%')
                         WHERE u.usuario = :usuario_analista";
                         
        $stmt_familias = $pdo->prepare($sql_familias);
        $stmt_familias->execute(['usuario_analista' => $usuario_actual]);
        $familias_asignadas = $stmt_familias->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($familias_asignadas)) {
            echo json_encode(['success' => false, 'tiene_datos' => false, 'message' => 'No tienes familias asignadas.']);
            exit;
        }
        
        $placeholders = [];
        foreach ($familias_asignadas as $index => $familia) {
            $param_name = 'fam_' . $index;
            $placeholders[] = ':' . $param_name;
            $params_familia[$param_name] = $familia;
        }
        $where_familia = " AND s.id_familia IN (" . implode(',', $placeholders) . ")";
    }

    if ($es_rescate) {
        $where_fecha = " AND s.fecha_procesamiento >= :inicio AND s.fecha_procesamiento <= :fin ";
        $where_exportado = " AND (s.fecha_generacion_excel IS NULL OR s.tipo_exportacion = 'ATRASADO') ";
    } else {
        $where_fecha = " AND s.fecha_solicitud >= :inicio AND s.fecha_solicitud < :fin ";
        $where_exportado = " AND s.fecha_generacion_excel IS NULL ";
    }

    // Modo validación
    if (isset($_GET['validar']) && $_GET['validar'] == '1') {
        $sql_validar = "SELECT COUNT(DISTINCT s.id_solicitud) as total
                        FROM Analisis_Procesos.solicitudes s 
                        WHERE s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')
                        {$where_fecha} {$where_exportado} {$where_familia}";
        $stmt_validar = $pdo->prepare($sql_validar);
        $stmt_validar->execute(array_merge(['inicio' => $fecha_corte_inicio, 'fin' => $fecha_corte_fin], $params_familia));
        $resultado = $stmt_validar->fetch();
        
        if ($resultado['total'] == 0) {
            echo json_encode(['success' => false, 'tiene_datos' => false, 'message' => "No hay solicitudes para exportar."]);
        } else {
            echo json_encode(['success' => true, 'tiene_datos' => true, 'total_solicitudes' => $resultado['total']]);
        }
        exit;
    }

    // Modo marcar como exportadas
    if (isset($_GET['marcar']) && $_GET['marcar'] == '1') {
        // 1. Buscar las solicitudes que cumplen las condiciones
        $sql_solicitudes = "SELECT s.id_solicitud
                            FROM Analisis_Procesos.solicitudes s
                            WHERE s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')
                            {$where_fecha} {$where_exportado} {$where_familia}";
        $stmt = $pdo->prepare($sql_solicitudes);
        $stmt->execute(array_merge(['inicio' => $fecha_corte_inicio, 'fin' => $fecha_corte_fin], $params_familia));
        $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $rowsAffected = 0;
        if (!empty($solicitudes)) {
            $ids = array_column($solicitudes, 'id_solicitud');
            
            $named_placeholders = [];
            $update_params = [
                'tipo_exportacion' => $tipo_exportacion,
                'usuario' => $usuario_actual
            ];
            
            foreach ($ids as $index => $id) {
                $param_name = 'id_' . $index;
                $named_placeholders[] = ':' . $param_name;
                $update_params[$param_name] = $id;
            }
            
            $sql_mark = "UPDATE Analisis_Procesos.solicitudes 
                         SET fecha_generacion_excel = NOW(), 
                             tipo_exportacion = :tipo_exportacion, 
                             usuario_genero_excel = :usuario
                         WHERE id_solicitud IN (" . implode(',', $named_placeholders) . ")";
            
            $stmt_mark = $pdo->prepare($sql_mark);
            $stmt_mark->execute($update_params);
            $rowsAffected = $stmt_mark->rowCount();
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Proceso de marcado finalizado',
            'encontradas' => count($solicitudes),
            'actualizadas' => $rowsAffected
        ]);
        exit;
    }
    
    // ==========================================
    // OBTENER DATOS PARA EL EXCEL
    // ==========================================
    $sql_solicitudes = "SELECT s.id_solicitud, s.id_tienda, s.usuario_tienda, s.estado_general, 
                               s.fecha_solicitud, s.fecha_procesamiento, s.tipo_exportacion
                        FROM Analisis_Procesos.solicitudes s
                        WHERE s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')
                        {$where_fecha} {$where_exportado} {$where_familia}
                        ORDER BY s.fecha_procesamiento DESC";
    
    $stmt = $pdo->prepare($sql_solicitudes);
    $stmt->execute(array_merge(['inicio' => $fecha_corte_inicio, 'fin' => $fecha_corte_fin], $params_familia));
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($solicitudes)) {
        throw new Exception("No hay solicitudes para exportar en el período.");
    }

    $ids_solicitudes = array_column($solicitudes, 'id_solicitud');
    $placeholders_ids = implode(',', array_fill(0, count($ids_solicitudes), '?'));

    $sql_items = "SELECT sc.id_solicitud, s.id_tienda, sc.sku, sc.descripcion_producto, sc.carga_final, sc.md 
                  FROM Analisis_Procesos.solicitudes_carga sc
                  INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
                  WHERE sc.id_solicitud IN ($placeholders_ids) AND sc.carga_final > 0
                  ORDER BY sc.md, sc.sku";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->execute($ids_solicitudes);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    $items_por_md = ['TRANSFERENCIA' => [], 'CROSS_DOCKING' => [], 'COMPRA_LOCAL' => []];
    $md_map = ['TRF' => 'TRANSFERENCIA', 'TRANSFERENCIA' => 'TRANSFERENCIA', 'XD' => 'CROSS_DOCKING', 'CROSS_DOCKING' => 'CROSS_DOCKING', 'CL' => 'COMPRA_LOCAL', 'COMPRA_LOCAL' => 'COMPRA_LOCAL'];

    foreach ($items as $item) {
        $md_raw = strtoupper(trim($item['md']));
        $md = $md_map[$md_raw] ?? null;
        if ($md && isset($items_por_md[$md])) {
            $sql_sug = "SELECT lead_time_total as LT, nombre_proveedor, id_proveedor 
                        FROM rct.sugerido_diario 
                        WHERE id_tienda = ? AND sku = ? 
                        ORDER BY fecha DESC LIMIT 1";
            $stmt_sug = $pdo->prepare($sql_sug);
            $stmt_sug->execute([$item['id_tienda'], $item['sku']]);
            $sugerido = $stmt_sug->fetch(PDO::FETCH_ASSOC);
            $item['LT'] = $sugerido['LT'] ?? 0;
            $item['proveedor'] = $sugerido['nombre_proveedor'] ?? 'N/A';
            $item['id_proveedor'] = $sugerido['id_proveedor'] ?? 'N/A';
            $items_por_md[$md][] = $item;
        }
    }

    // ✅ DEVOLVER JSON CON LOS DATOS
    echo json_encode([
        'success' => true,
        'nombre_archivo' => "CARGA_{$tipo_exportacion}_" . str_replace('-', '', $nombre_ciclo_archivo) . "_" . date('His') . ".xlsx",
        'datos' => [
            'TRANSFERENCIA' => $items_por_md['TRANSFERENCIA'],
            'CROSS_DOCKING' => $items_por_md['CROSS_DOCKING'],
            'COMPRA_LOCAL' => $items_por_md['COMPRA_LOCAL']
        ]
    ]);
    exit;

} catch (Exception $e) {
    error_log("Error Excel: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>