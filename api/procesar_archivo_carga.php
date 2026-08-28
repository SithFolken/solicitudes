<?php
// 1. Limpiar output buffer
if (ob_get_level()) {
    ob_end_clean();
}

// 2. Iniciar sesión
session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Validar sesión
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.']);
    exit;
}

require_once '../config/database.php';
require_once 'ArbolDecision.php';
header('Content-Type: application/json; charset=utf-8');

// 4. Logs iniciales
error_log("=== INICIO PROCESAR ARCHIVO ===");
error_log("Usuario: " . ($_SESSION['user_id'] ?? 'NULL'));
error_log("ID Tienda: " . ($_SESSION['id_tienda'] ?? 'NULL'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No se recibió el archivo.']);
    exit;
}

$id_familia = trim($_POST['id_familia'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');
$id_tienda = isset($_SESSION['id_tienda']) ? (int)$_SESSION['id_tienda'] : 0;
$usuario_tienda = $_SESSION['user_id'] ?? '';

if (!$id_familia || $id_tienda <= 0 || empty($usuario_tienda)) {
    error_log("ERROR: Datos incompletos - id_familia: $id_familia, id_tienda: $id_tienda, usuario: $usuario_tienda");
    echo json_encode(['success' => false, 'message' => 'Datos de sesión incompletos.']);
    exit;
}

try {
    // Leer archivo una sola vez
    $archivo_temp = $_FILES['archivo']['tmp_name'];
    $contenido = file_get_contents($archivo_temp);
    
    if (substr($contenido, 0, 3) === "\xEF\xBB\xBF") {
        $contenido = substr($contenido, 3);
    }
    
    $delimitador = ',';
    $primeraLinea = strtok($contenido, "\n");
    if (substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',')) {
        $delimitador = ';';
    }
    
    $lineas_csv = str_getcsv($contenido, "\n");
    $headers = str_getcsv($lineas_csv[0], $delimitador);
    $headers = array_map(function($h) {
        return strtolower(trim(str_replace(["\xEF\xBB\xBF", "\r"], '', $h)));
    }, $headers);
    
    $idx_sku = array_search('sku', $headers);
    $idx_cantidad = false;
    $idx_descripcion = false;
    
    foreach ($headers as $i => $h) {
        if (in_array($h, ['cantidad', 'cant', 'carga', 'qty'])) {
            $idx_cantidad = $i;
            break;
        }
    }
    
    foreach ($headers as $i => $h) {
        if (in_array($h, ['descripcion', 'descripcion_producto', 'producto', 'desc'])) {
            $idx_descripcion = $i;
            break;
        }
    }
    
    if ($idx_sku === false || $idx_cantidad === false) {
        throw new Exception('Columnas SKU o Cantidad no encontradas');
    }
    
    // Extraer datos
    $datos_filas = [];
    $skus_del_archivo = [];
    
    for ($i = 1; $i < count($lineas_csv); $i++) {
        $row = str_getcsv($lineas_csv[$i], $delimitador);
        if (empty($row)) continue;
        
        $sku = isset($row[$idx_sku]) ? trim($row[$idx_sku]) : '';
        $cantidad = isset($row[$idx_cantidad]) ? (int)trim($row[$idx_cantidad]) : 0;
        $descripcion = ($idx_descripcion !== false && isset($row[$idx_descripcion])) ? trim($row[$idx_descripcion]) : '';
        
        if (!$sku || $cantidad <= 0) continue;
        
        $skus_del_archivo[] = $sku;
        $datos_filas[] = ['sku' => $sku, 'cantidad' => $cantidad, 'descripcion' => $descripcion];
    }
    
    $skus_del_archivo = array_unique($skus_del_archivo);
    
    if (empty($skus_del_archivo)) {
        throw new Exception('No hay SKUs válidos');
    }
    
    error_log("SKUs encontrados: " . count($skus_del_archivo));
    
    // ==========================================
    // VALIDACIÓN: Verificar si alguno de los SKUs ya está en solicitud pendiente
    // ==========================================
    error_log("VALIDACIÓN MASIVA INICIANDO - SKUs: " . implode(', ', $skus_del_archivo));
    
    try {
        $placeholders = implode(',', array_fill(0, count($skus_del_archivo), '?'));
        
        $sql_val = "SELECT DISTINCT
                        s.id_solicitud,
                        s.estado_general,
                        DATE_FORMAT(s.fecha_solicitud, '%d-%m-%Y %H:%i') as fecha,
                        sc.sku,
                        sc.estado_item
                    FROM Analisis_Procesos.solicitudes s
                    INNER JOIN Analisis_Procesos.solicitudes_carga sc 
                        ON s.id_solicitud = sc.id_solicitud
                    WHERE s.usuario_tienda = ?
                      AND sc.sku IN ($placeholders)
                      AND s.estado_general IN ('PENDIENTE', 'EN_PROCESO')
                    ORDER BY s.id_solicitud";
        
        $params_val = array_merge([$usuario_tienda], $skus_del_archivo);
        error_log("SQL Validación Masiva: $sql_val");
        
        $stmt_val = $pdo->prepare($sql_val);
        $stmt_val->execute($params_val);
        $skus_pendientes = $stmt_val->fetchAll();
        
        error_log("SKUs pendientes encontrados: " . count($skus_pendientes));
        
        if (!empty($skus_pendientes)) {
            $skus_conflictos = array_unique(array_map(function($item) {
                return $item['sku'];
            }, $skus_pendientes));
            
            $id_solicitud_conflicto = $skus_pendientes[0]['id_solicitud'];
            $estado_conflicto = $skus_pendientes[0]['estado_general'];
            $fecha_conflicto = $skus_pendientes[0]['fecha'];
            
            error_log("BLOQUEADO - SKUs conflictivos: " . implode(', ', $skus_conflictos));
            
            echo json_encode([
                'success' => false,
                'message' => "Los siguientes SKUs ya están en la solicitud **#{$id_solicitud_conflicto}** (estado: **{$estado_conflicto}**): <strong>" . implode(', ', $skus_conflictos) . "</strong>. Espera a que sean procesados antes de solicitarlos nuevamente.",
                'tipo_error' => 'SKU_PENDIENTE',
                'skus_conflicto' => $skus_conflictos,
                'id_solicitud_existente' => $id_solicitud_conflicto
            ]);
            exit;
        }
        
        error_log("VALIDACIÓN MASIVA PASÓ - Ningún SKU está pendiente");
        
    } catch (PDOException $e_val) {
        error_log("ERROR en validación masiva: " . $e_val->getMessage());
    }

    // ==========================================
    // CREAR SOLICITUD PADRE
    // ==========================================
    error_log("CREANDO SOLICITUD PADRE");
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql_padre = "INSERT INTO Analisis_Procesos.solicitudes 
                  (id_tienda, usuario_tienda, id_familia, estado_general, observaciones_generales, fecha_solicitud) 
                  VALUES (:id_tienda, :usuario_tienda, :id_familia, 'PENDIENTE', :observaciones, NOW())";
    
    $stmt_padre = $pdo->prepare($sql_padre);
    $stmt_padre->execute([
        'id_tienda' => $id_tienda,
        'usuario_tienda' => $usuario_tienda,
        'id_familia' => $id_familia,
        'observaciones' => $observaciones
    ]);
    
    $id_solicitud_padre = $pdo->lastInsertId();
    
    error_log("ID PADRE CREADO: $id_solicitud_padre");
    
    if (!$id_solicitud_padre || $id_solicitud_padre <= 0) {
        throw new Exception('No se pudo crear la solicitud padre');
    }

    // ==========================================
    // PROCESAR DETALLES
    // ==========================================
    error_log("PROCESANDO DETALLES - Padre ID: $id_solicitud_padre");
    
    $detalles_guardados = 0;
    $detalles_rechazados = 0;
    $resultados = [];
    $skus_procesados = [];
    
    foreach ($datos_filas as $fila) {
        $sku = $fila['sku'];
        $cantidad = $fila['cantidad'];
        $descripcion = $fila['descripcion'];
        
        if (in_array($sku, $skus_procesados)) continue;
        $skus_procesados[] = $sku;
        
        if (!$descripcion) {
            $sql_prod = "SELECT descripcion_producto FROM sodimac_grt.productos_maestro WHERE sku = :sku LIMIT 1";
            $stmt_prod = $pdo->prepare($sql_prod);
            $stmt_prod->execute(['sku' => $sku]);
            $prod = $stmt_prod->fetch();
            $descripcion = $prod ? $prod['descripcion_producto'] : 'Sin descripción';
        }
        
        $sql_sug = "SELECT 
                        ROUND((COALESCE(vta_sem_3, 0) + COALESCE(vta_sem_2, 0) + COALESCE(vta_sem_1, 0)) / 3, 2) as PV6,
                        disp as disp_tda, pend as pend_tda, disp_bod, pend_bod, MD as MD_sugerido
                    FROM rct.sugerido_diario 
                    WHERE id_tienda = :id_tienda AND sku = :sku
                    ORDER BY fecha DESC LIMIT 1";
        $stmt_sug = $pdo->prepare($sql_sug);
        $stmt_sug->execute(['id_tienda' => $id_tienda, 'sku' => $sku]);
        $datos_sugerido = $stmt_sug->fetch();
        
        $evaluacion = ArbolDecision::evaluar($sku, $cantidad, $datos_sugerido, $pdo);
        
        $estado_item = $evaluacion['puede_cargar'] ? 'PENDIENTE' : 'RECHAZADO';
        $motivo = $evaluacion['puede_cargar'] ? '' : $evaluacion['motivo_rechazo'];
        
        $sql_detalle = "INSERT INTO Analisis_Procesos.solicitudes_carga 
                       (id_solicitud, sku, descripcion_producto, carga_solicitada, carga_final, estado_item, campo_cambios, id_familia) 
                       VALUES (:id_solicitud, :sku, :descripcion, :cantidad, 0, :estado_item, :motivo, :id_familia)";
        
        $stmt_detalle = $pdo->prepare($sql_detalle);
        $stmt_detalle->execute([
            'id_solicitud' => $id_solicitud_padre,
            'sku' => $sku,
            'descripcion' => $descripcion,
            'cantidad' => $cantidad,
            'estado_item' => $estado_item,
            'motivo' => $motivo,
            'id_familia' => $id_familia
        ]);
        
        if ($stmt_detalle->rowCount() === 0) {
            throw new Exception("No se pudo insertar el detalle para SKU: $sku");
        }
        
        $id_detalle = $pdo->lastInsertId();
        error_log("Detalle insertado - ID: $id_detalle, SKU: $sku, Padre: $id_solicitud_padre");
        
        if ($evaluacion['puede_cargar']) {
            $detalles_guardados++;
            $resultados[] = ['sku' => $sku, 'descripcion' => $descripcion, 'cantidad' => $cantidad, 'estado' => 'GUARDADO'];
        } else {
            $detalles_rechazados++;
            $resultados[] = ['sku' => $sku, 'descripcion' => $descripcion, 'cantidad' => $cantidad, 'estado' => 'RECHAZADO', 'motivo' => $motivo];
        }
    }
    
    error_log("Procesamiento completado - Guardados: $detalles_guardados, Rechazados: $detalles_rechazados");
    
    echo json_encode([
        'success' => true,
        'message' => "Solicitud #$id_solicitud_padre creada: $detalles_guardados aprobados, $detalles_rechazados rechazados",
        'id_solicitud' => $id_solicitud_padre,
        'procesados' => $detalles_guardados,
        'rechazados' => $detalles_rechazados,
        'detalle' => $resultados
    ]);
    
} catch (Exception $e) {
    error_log("ERROR FINAL: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>