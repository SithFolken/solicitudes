<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] !== 'analista' && $_SESSION['rol'] !== 'ejecutor')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once 'SimpleXLSXGen.php';
use Shuchkin\SimpleXLSXGen;
require_once '../config/database.php';

$ciclo_corte = isset($_GET['ciclo']) ? $_GET['ciclo'] : date('Y-m-d');

try {
    // 1. Obtener solicitudes procesadas de este ciclo
    $sql_solicitudes = "SELECT id_solicitud, id_tienda, usuario_tienda, estado_general 
                        FROM Analisis_Procesos.solicitudes 
                        WHERE ciclo_corte = :ciclo 
                          AND estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')";
    $stmt = $pdo->prepare($sql_solicitudes);
    $stmt->execute(['ciclo' => $ciclo_corte]);
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Validación para el frontend
    if (isset($_GET['validar']) && $_GET['validar'] == '1') {
        if (empty($solicitudes)) {
            echo json_encode([
                'success' => false, 
                'tiene_datos' => false, 
                'message' => 'No hay solicitudes procesadas para este ciclo.'
            ]);
        } else {
            echo json_encode(['success' => true, 'tiene_datos' => true]);
        }
        exit;
    }

    if (empty($solicitudes)) {
        throw new Exception('No hay solicitudes procesadas para este ciclo.');
    }

    $ids_solicitudes = array_column($solicitudes, 'id_solicitud');
    $placeholders = implode(',', array_fill(0, count($ids_solicitudes), '?'));

    // 2. Obtener items aprobados
    $sql_items = "SELECT sc.id_solicitud, s.id_tienda, sc.sku, sc.descripcion_producto, sc.carga_final, sc.md 
                  FROM Analisis_Procesos.solicitudes_carga sc
                  INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
                  WHERE sc.id_solicitud IN ($placeholders) AND sc.estado_item = 'APROBADO'
                  ORDER BY sc.md, sc.sku";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->execute($ids_solicitudes);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    // 3. Agrupar por MD con mapeo de abreviaturas
    $items_por_md = ['TRANSFERENCIA' => [], 'CROSS_DOCKING' => [], 'COMPRA_LOCAL' => []];
    $md_map = [
        'TRF' => 'TRANSFERENCIA',
        'TRANSFERENCIA' => 'TRANSFERENCIA',
        'XD' => 'CROSS_DOCKING',
        'CROSS_DOCKING' => 'CROSS_DOCKING',
        'CL' => 'COMPRA_LOCAL',
        'COMPRA_LOCAL' => 'COMPRA_LOCAL'
    ];

    foreach ($items as $item) {
        $md_raw = strtoupper(trim($item['md']));
        $md = $md_map[$md_raw] ?? null;
        
        if ($md && isset($items_por_md[$md])) {
            $sql_sug = "SELECT lead_time_total as LT, nombre_proveedor 
                        FROM rct.sugerido_diario 
                        WHERE id_tienda = ? AND sku = ? 
                        ORDER BY fecha DESC LIMIT 1";
            $stmt_sug = $pdo->prepare($sql_sug);
            $stmt_sug->execute([$item['id_tienda'], $item['sku']]);
            $sugerido = $stmt_sug->fetch(PDO::FETCH_ASSOC);
            
            $item['LT'] = $sugerido['LT'] ?? 0;
            $item['proveedor'] = $sugerido['nombre_proveedor'] ?? 'N/A';
            $items_por_md[$md][] = $item;
        }
    }

    // ==========================================
    // 4. GENERAR EXCEL (Versión 100% compatible)
    // ==========================================
    
    $primera = true;
    $xlsx = null;

    // --- HOJA 1: TRANSFERENCIA ---
    if (!empty($items_por_md['TRANSFERENCIA'])) {
        $rows_trf = [];
        // La primera fila son los encabezados
        $rows_trf[] = ['TIENDA', 'CODIGO', 'DIGITO', 'CANTIDAD', 'R:'];
        
        foreach ($items_por_md['TRANSFERENCIA'] as $i) {
            $rows_trf[] = [
                $i['id_tienda'], 
                substr($i['sku'], 0, -1), 
                substr($i['sku'], -1), 
                $i['carga_final'], 
                'R:'
            ];
        }
        
        if ($primera) { 
            $xlsx = SimpleXLSXGen::fromArray($rows_trf, 'TRANSFERENCIA'); 
            $primera = false; 
        } else { 
            $xlsx->addSheet($rows_trf, 'TRANSFERENCIA'); 
        }
    }

    // --- HOJA 2: CROSS DOCKING ---
    if (!empty($items_por_md['CROSS_DOCKING'])) {
        $rows_cd = [];
        $rows_cd[] = ['TIENDA', 'SKU', 'CANTIDAD'];
        
        foreach ($items_por_md['CROSS_DOCKING'] as $i) {
            $rows_cd[] = [
                $i['id_tienda'], 
                $i['sku'], 
                $i['carga_final']
            ];
        }
        
        if ($primera) { 
            $xlsx = SimpleXLSXGen::fromArray($rows_cd, 'CROSS DOCKING'); 
            $primera = false; 
        } else { 
            $xlsx->addSheet($rows_cd, 'CROSS DOCKING'); 
        }
    }

    // --- HOJA 3: COMPRA LOCAL ---
    if (!empty($items_por_md['COMPRA_LOCAL'])) {
        $rows_cl = [];
        $fecha_hoy = date('Y-m-d');
        $rows_cl[] = ['TIENDA', 'CANTIDAD', 'FECHA_SOLICITUD', 'FECHA_RECEPCION', 'FECHA_CANCELACION', 'PROVEEDOR'];
        
        foreach ($items_por_md['COMPRA_LOCAL'] as $i) {
            $lt = (int)$i['LT'];
            $f_recepcion = date('Y-m-d', strtotime("$fecha_hoy + $lt days"));
            $f_cancel = date('Y-m-d', strtotime("$f_recepcion + 3 days"));
            $rows_cl[] = [
                $i['id_tienda'], 
                $i['carga_final'], 
                $fecha_hoy, 
                $f_recepcion, 
                $f_cancel, 
                $i['proveedor']
            ];
        }
        
        if ($primera) { 
            $xlsx = SimpleXLSXGen::fromArray($rows_cl, 'COMPRA LOCAL'); 
            $primera = false; 
        } else { 
            $xlsx->addSheet($rows_cl, 'COMPRA LOCAL'); 
        }
    }

    // Verificar que se haya creado al menos una hoja
    if ($xlsx === null) {
        throw new Exception('No hay items aprobados para generar el Excel en este ciclo.');
    }

    // 5. Marcar solicitudes como generadas en Excel
    $sql_mark = "UPDATE Analisis_Procesos.solicitudes 
                 SET fecha_generacion_excel = NOW(),
                     usuario_genero_excel = :usuario
                 WHERE ciclo_corte = :ciclo
                   AND estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')";
    $stmt_mark = $pdo->prepare($sql_mark);
    $stmt_mark->execute([
        'usuario' => $_SESSION['user_id'],
        'ciclo' => $ciclo_corte
    ]);

    // 6. Descargar archivo
    $nombre_archivo = "CARGA_CICLO_" . str_replace('-', '', $ciclo_corte) . "_" . date('His') . ".xlsx";
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