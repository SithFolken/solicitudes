<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// FORZAR LA LIMPIEZA DE CACHÉ DE PHP
if (function_exists('opcache_reset')) {
    opcache_reset();
}



if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] !== 'analista' && $_SESSION['rol'] !== 'ejecutor')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once 'SimpleXLSXGen.php';
use Shuchkin\SimpleXLSXGen;
require_once '../config/database.php';

try {
    $usuario_actual = $_SESSION['user_id'] ?? '';
    $rol_actual = $_SESSION['rol'] ?? '';

    $ciclo_nombre = isset($_GET['ciclo']) ? $_GET['ciclo'] : date('Y-m-d');
    
    // ✅ DETECTAR SI ES RESCATE
    $es_rescate = (isset($_GET['tipo']) && $_GET['tipo'] === 'rescate');
    $tipo_exportacion = $es_rescate ? 'ATRASADO' : 'NORMAL';
    $nombre_ciclo_archivo = $es_rescate ? 'RESCATE_' . $ciclo_nombre : $ciclo_nombre;
    
    if (!isset($_GET['fecha_inicio']) || !isset($_GET['fecha_fin'])) {
        throw new Exception('No se recibieron las fechas del ciclo.');
    }
    
    $fecha_corte_inicio = $_GET['fecha_inicio'];
    $fecha_corte_fin = $_GET['fecha_fin'];

    // ==========================================
    // CONSULTA MAESTRA: Obtener familias si es analista
    // ==========================================
    $where_familia = '';
    $params_familia = [];
    
    if ($rol_actual === 'analista') {
        $sql_familias = "SELECT DISTINCT d.Familia as id_familia
                         FROM FulFillment.distribucion_analista_familia d
                         INNER JOIN rct.usuarios_solicitudes_oc u 
                             ON d.Analista_reposicion_tienda = u.nombre
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

    // ==========================================
    // ✅ LÓGICA DE FECHAS: Solicitud (Normal) vs Procesamiento (Rescate)
    // ==========================================
    if ($es_rescate) {
        // RESCATE: Buscar por FECHA DE PROCESAMIENTO (cuando el analista la aprobó)
        $where_fecha = " AND s.fecha_procesamiento >= :inicio AND s.fecha_procesamiento <= :fin ";
        // Excluir las que ya fueron exportadas en el ciclo NORMAL
        $where_exportado = " AND (s.fecha_generacion_excel IS NULL OR s.tipo_exportacion = 'ATRASADO') ";
    } else {
        // NORMAL: Buscar por FECHA DE SOLICITUD (ciclo de corte de creación)
        $where_fecha = " AND s.fecha_solicitud >= :inicio AND s.fecha_solicitud < :fin ";
        // Excluir cualquier cosa que ya haya sido exportada
        $where_exportado = " AND s.fecha_generacion_excel IS NULL ";
    }

    // ==========================================
    // MODO VALIDACIÓN
    // ==========================================
    if (isset($_GET['validar']) && $_GET['validar'] == '1') {
        $sql_validar = "SELECT COUNT(DISTINCT s.id_solicitud) as total
                        FROM Analisis_Procesos.solicitudes s 
                        WHERE s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')
                        {$where_fecha}
                        {$where_exportado}
                        {$where_familia}";
        
        $stmt_validar = $pdo->prepare($sql_validar);
        $stmt_validar->execute(array_merge(['inicio' => $fecha_corte_inicio, 'fin' => $fecha_corte_fin], $params_familia));
        $resultado = $stmt_validar->fetch();
        
        if ($resultado['total'] == 0) {
            echo json_encode(['success' => false, 'tiene_datos' => false, 'message' => "No hay solicitudes para exportar en este período."]);
        } else {
            echo json_encode(['success' => true, 'tiene_datos' => true, 'total_solicitudes' => $resultado['total']]);
        }
        exit;
    }
    
    // ==========================================
    // GENERAR EXCEL
    // ==========================================
    $sql_solicitudes = "SELECT s.id_solicitud, s.id_tienda, s.usuario_tienda, s.estado_general, 
                               s.fecha_solicitud, s.fecha_procesamiento, s.tipo_exportacion
                        FROM Analisis_Procesos.solicitudes s
                        WHERE s.estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')
                        {$where_fecha}
                        {$where_exportado}
                        {$where_familia}
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
                  WHERE sc.id_solicitud IN ($placeholders_ids) AND sc.estado_item = 'APROBADO'
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

    $primera = true;
    $xlsx = null;
    $crearEncabezado = function($textos, $colorFondo) {
        $fila = [];
        foreach ($textos as $texto) { $fila[] = ['v' => $texto, 'style' => "fill:{$colorFondo};color:#FFFFFF;font-weight:bold;font-size:11pt;"]; }
        return $fila;
    };

    if (!empty($items_por_md['TRANSFERENCIA'])) {
        $rows_trf = [$crearEncabezado(['TIENDA', 'CODIGO', 'DIGITO', 'CANTIDAD', 'R:'], '#28A745')];
        foreach ($items_por_md['TRANSFERENCIA'] as $i) { $rows_trf[] = [$i['id_tienda'], substr($i['sku'], 0, -1), substr($i['sku'], -1), $i['carga_final'], 'R:']; }
        $xlsx = $primera ? SimpleXLSXGen::fromArray($rows_trf, 'TRANSFERENCIA') : $xlsx->addSheet($rows_trf, 'TRANSFERENCIA');
        $primera = false;
    }
    if (!empty($items_por_md['CROSS_DOCKING'])) {
        $rows_cd = [$crearEncabezado(['TIENDA', 'SKU', 'CANTIDAD'], '#007BFF')];
        foreach ($items_por_md['CROSS_DOCKING'] as $i) { $rows_cd[] = [$i['id_tienda'], $i['sku'], $i['carga_final']]; }
        $xlsx = $primera ? SimpleXLSXGen::fromArray($rows_cd, 'CROSS DOCKING') : $xlsx->addSheet($rows_cd, 'CROSS DOCKING');
        $primera = false;
    }
    if (!empty($items_por_md['COMPRA_LOCAL'])) {
        $rows_cl = [$crearEncabezado(['TIENDA', 'ID_PROVEEDOR', 'CANTIDAD', 'FECHA_SOLICITUD', 'FECHA_RECEPCION', 'FECHA_CANCELACION', 'PROVEEDOR'], '#FD7E14')];
        foreach ($items_por_md['COMPRA_LOCAL'] as $i) {
            $lt = (int)$i['LT']; $fecha_hoy = date('Y-m-d');
            $f_recepcion = date('Y-m-d', strtotime("$fecha_hoy + $lt days"));
            $f_cancel = date('Y-m-d', strtotime("$f_recepcion + 3 days"));
            $rows_cl[] = [$i['id_tienda'], $i['id_proveedor'], $i['carga_final'], $fecha_hoy, $f_recepcion, $f_cancel, $i['proveedor']];
        }
        $xlsx = $primera ? SimpleXLSXGen::fromArray($rows_cl, 'COMPRA LOCAL') : $xlsx->addSheet($rows_cl, 'COMPRA LOCAL');
        $primera = false;
    }

    if ($xlsx === null) {
        throw new Exception('No hay items aprobados para generar el Excel.');
    }

    // ==========================================
    // MARCAR COMO EXPORTADAS
    // ==========================================
    $placeholders_ids_named = [];
    $params_update = ['usuario' => $usuario_actual, 'tipo_exportacion' => $tipo_exportacion];
    
    foreach ($ids_solicitudes as $index => $id) {
        $param_name = 'id_' . $index;
        $placeholders_ids_named[] = ':' . $param_name;
        $params_update[$param_name] = $id;
    }
    
    $placeholders_fam_update = [];
    foreach ($params_familia as $key => $val) {
        $new_key = 'up_' . $key;
        $placeholders_fam_update[] = ':' . $new_key;
        $params_update[$new_key] = $val;
    }
    
    $sql_mark = "UPDATE Analisis_Procesos.solicitudes 
                 SET fecha_generacion_excel = NOW(), 
                     tipo_exportacion = :tipo_exportacion, 
                     usuario_genero_excel = :usuario
                 WHERE id_solicitud IN (" . implode(',', $placeholders_ids_named) . ")
                 AND id_familia IN (" . implode(',', $placeholders_fam_update) . ")";
    
    $stmt_mark = $pdo->prepare($sql_mark);
    $stmt_mark->execute($params_update);

    $ciclo_limpio = str_replace('-', '', $nombre_ciclo_archivo);
    $nombre_archivo = "CARGA_{$tipo_exportacion}_{$ciclo_limpio}_" . date('His') . ".xlsx";
    $xlsx->downloadAs($nombre_archivo);
    exit;

} catch (Exception $e) {
    error_log("Error Excel: " . $e->getMessage());
    if (isset($_GET['validar']) && $_GET['validar'] == '1') {
        echo json_encode(['success' => false, 'tiene_datos' => false, 'message' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>