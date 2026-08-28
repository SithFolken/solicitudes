<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] !== 'analista' && $_SESSION['rol'] !== 'ejecutor')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

// 1. Incluir la librería ligera (Sin Composer)
require_once 'SimpleXLSXGen.php';
use Shuchkin\SimpleXLSXGen;

require_once '../config/database.php';

$ciclo_corte = isset($_GET['ciclo']) ? $_GET['ciclo'] : date('Y-m-d');

try {
    // 2. Obtener solicitudes procesadas de este ciclo
    $sql_solicitudes = "SELECT id_solicitud, id_tienda, usuario_tienda, estado_general 
                        FROM Analisis_Procesos.solicitudes 
                        WHERE ciclo_corte = :ciclo 
                          AND estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')";
    $stmt = $pdo->prepare($sql_solicitudes);
    $stmt->execute(['ciclo' => $ciclo_corte]);
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ VALIDACIÓN PARA EL FRONTEND (Evita descargas fallidas y muestra mensaje)
    if (isset($_GET['validar']) && $_GET['validar'] == '1') {
        if (empty($solicitudes)) {
            echo json_encode([
                'success' => false, 
                'tiene_datos' => false, 
                'message' => 'No hay solicitudes con estado PROCESADA o PROCESADA_PARCIAL para el ciclo ' . $ciclo_corte . '.<br><br>Verifica que hayas procesado solicitudes y que el campo <code>ciclo_corte</code> esté guardado en la base de datos.'
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

    // 3. Obtener items aprobados
    $sql_items = "SELECT sc.id_solicitud, s.id_tienda, sc.sku, sc.descripcion_producto, sc.carga_final, sc.md 
                  FROM Analisis_Procesos.solicitudes_carga sc
                  INNER JOIN Analisis_Procesos.solicitudes s ON sc.id_solicitud = s.id_solicitud
                  WHERE sc.id_solicitud IN ($placeholders) AND sc.estado_item = 'APROBADO'
                  ORDER BY sc.md, sc.sku";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->execute($ids_solicitudes);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar por MD
    $items_por_md = ['TRANSFERENCIA' => [], 'CROSS_DOCKING' => [], 'COMPRA_LOCAL' => []];
    foreach ($items as $item) {
        $md = strtoupper(trim($item['md']));
        if (isset($items_por_md[$md])) {
            // Obtener LT y Proveedor
            $sql_sug = "SELECT lead_time_total as LT, nombre_proveedor FROM rct.sugerido_diario WHERE id_tienda = ? AND sku = ? ORDER BY fecha DESC LIMIT 1";
            $stmt_sug = $pdo->prepare($sql_sug);
            $stmt_sug->execute([$item['id_tienda'], $item['sku']]);
            $sugerido = $stmt_sug->fetch(PDO::FETCH_ASSOC);
            $item['LT'] = $sugerido['LT'] ?? 0;
            $item['proveedor'] = $sugerido['nombre_proveedor'] ?? 'N/A';
            $items_por_md[$md][] = $item;
        }
    }

    // ==========================================
    // 4. GENERAR EXCEL MODERNO (SimpleXLSXGen)
    // ==========================================
    
    // Función auxiliar para crear encabezados con estilo moderno
    $crearEncabezado = function($textos, $colorFondo, $colorTexto) {
        $encabezados = [];
        foreach ($textos as $texto) {
            $encabezados[] = [
                $texto => 'bold', 
                'fill' => $colorFondo, 
                'color' => $colorTexto
            ];
        }
        return $encabezados;
    };

    // --- HOJA 1: TRANSFERENCIA (Verde) ---
    $rows_trf = [];
    if (!empty($items_por_md['TRANSFERENCIA'])) {
        $rows_trf[] = $crearEncabezado(['TIENDA', 'CODIGO', 'DIGITO', 'CANTIDAD', 'R:'], '#28A745', '#FFFFFF');
        foreach ($items_por_md['TRANSFERENCIA'] as $i) {
            $rows_trf[] = [
                $i['id_tienda'], 
                substr($i['sku'], 0, -1), 
                substr($i['sku'], -1), 
                $i['carga_final'], 
                'R:'
            ];
        }
    }

    // --- HOJA 2: CROSS DOCKING (Azul) ---
    $rows_cd = [];
    if (!empty($items_por_md['CROSS_DOCKING'])) {
        $rows_cd[] = $crearEncabezado(['TIENDA', 'SKU', 'CANTIDAD'], '#007BFF', '#FFFFFF');
        foreach ($items_por_md['CROSS_DOCKING'] as $i) {
            $rows_cd[] = [
                $i['id_tienda'], 
                $i['sku'], 
                $i['carga_final']
            ];
        }
    }

    // --- HOJA 3: COMPRA LOCAL (Naranja) ---
    $rows_cl = [];
    if (!empty($items_por_md['COMPRA_LOCAL'])) {
        $fecha_hoy = date('Y-m-d');
        $rows_cl[] = $crearEncabezado(['TIENDA', 'CANTIDAD', 'FECHA_SOLICITUD', 'FECHA_RECEPCION', 'FECHA_CANCELACION', 'PROVEEDOR'], '#FD7E14', '#FFFFFF');
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
    }

    // Verificar que al menos haya una hoja con datos
    if (empty($rows_trf) && empty($rows_cd) && empty($rows_cl)) {
        throw new Exception('No hay items aprobados para generar el Excel en este ciclo.');
    }

    // Crear el objeto Excel dinámicamente con las hojas que tengan datos
    $primera = true;
    $xlsx = null;
    
    if (!empty($rows_trf)) {
        if ($primera) { 
            $xlsx = SimpleXLSXGen::fromArray($rows_trf, 'TRANSFERENCIA'); 
            $primera = false; 
        } else { 
            $xlsx->addSheet($rows_trf, 'TRANSFERENCIA'); 
        }
    }
    
    if (!empty($rows_cd)) {
        if ($primera) { 
            $xlsx = SimpleXLSXGen::fromArray($rows_cd, 'CROSS DOCKING'); 
            $primera = false; 
        } else { 
            $xlsx->addSheet($rows_cd, 'CROSS DOCKING'); 
        }
    }
    
    if (!empty($rows_cl)) {
        if ($primera) { 
            $xlsx = SimpleXLSXGen::fromArray($rows_cl, 'COMPRA LOCAL'); 
            $primera = false; 
        } else { 
            $xlsx->addSheet($rows_cl, 'COMPRA LOCAL'); 
        }
    }

    // 5. Marcar TODAS las solicitudes del ciclo como generadas en Excel
    $sql_mark = "UPDATE Analisis_Procesos.solicitudes 
                 SET fecha_generacion_excel = NOW(),
                     usuario_genero_excel = :usuario
                 WHERE ciclo_corte = :ciclo
                   AND estado_general IN ('PROCESADA', 'PROCESADA_PARCIAL')
                   AND (fecha_generacion_excel IS NULL OR DATE(fecha_generacion_excel) != CURDATE())";
    $stmt_mark = $pdo->prepare($sql_mark);
    $stmt_mark->execute([
        'usuario' => $_SESSION['user_id'],
        'ciclo' => $ciclo_corte
    ]);
    
    $afectadas = $stmt_mark->rowCount();
    error_log("Excel generado: $afectadas solicitudes marcadas del ciclo $ciclo_corte");

    // 6. Descargar archivo (MÉTODO CORRECTO PARA SimpleXLSXGen)
    $nombre_archivo = "CARGA_CICLO_" . str_replace('-', '', $ciclo_corte) . "_" . date('His') . ".xlsx";
    $xlsx->downloadAs($nombre_archivo);
    exit;

} catch (Exception $e) {
    error_log("Error Excel: " . $e->getMessage());
    
    // Si es una petición de validación, devolver JSON limpio
    if (isset($_GET['validar']) && $_GET['validar'] == '1') {
        echo json_encode(['success' => false, 'tiene_datos' => false, 'message' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>