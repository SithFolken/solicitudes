<?php
session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ✅ Permitir tanto a analistas como a ejecutores
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] !== 'analista' && $_SESSION['rol'] !== 'ejecutor')) {
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';

// ✅ Cargar helper de reemplazos
require_once __DIR__ . '/helpers_reemplazos.php';

$usuario_actual = $_SESSION['user_id'] ?? '';

// ✅ Validación segura con respuesta JSON (no usar die)
if (empty($usuario_actual)) {
    echo json_encode(['success' => false, 'message' => 'Sesión inválida: No se pudo identificar al usuario.']);
    exit;
}

// Obtener los analistas efectivos (el propio + los que está reemplazando hoy)
$analistas_efectivos = obtenerAnalistasEfectivos($pdo, $usuario_actual);

if (empty($analistas_efectivos)) {
    echo json_encode(['success' => false, 'message' => 'No se pudieron determinar las familias asignadas para este usuario.']);
    exit;
}

// Obtener filtros
$estado = $_GET['estado'] ?? '';
$md = $_GET['md'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

$where = [];
$params = [];

// ==========================================
// CONSTRUIR FILTRO DE FAMILIAS / USUARIOS EFECTIVOS
// ==========================================
$placeholders_usr = [];
foreach ($analistas_efectivos as $index => $usr) {
    $param_name = 'usr_exp_' . $index;
    $placeholders_usr[] = ':' . $param_name;
    $params[$param_name] = $usr;
}

// Buscar las familias asociadas a estos analistas efectivos
$sql_fam = "SELECT DISTINCT d.Familia as id_familia 
            FROM FulFillment.distribucion_analista_familia d 
            INNER JOIN rct.usuarios_solicitudes_oc u 
                ON d.Analista_reposicion_tienda LIKE CONCAT('%', u.nombre, '%') 
            WHERE u.usuario IN (" . implode(',', $placeholders_usr) . ")";

$stmt_fam = $pdo->prepare($sql_fam);
$stmt_fam->execute($params);
$familias_asignadas = $stmt_fam->fetchAll(PDO::FETCH_COLUMN);

if (!empty($familias_asignadas)) {
    $placeholders_fam = [];
    foreach ($familias_asignadas as $index => $fam) {
        $param_name = 'fam_exp_' . $index;
        $placeholders_fam[] = ':' . $param_name;
        $params[$param_name] = $fam;
    }
    // Filtrar por familia O por usuario_proceso directo
    $where[] = "(s.id_familia IN (" . implode(',', $placeholders_fam) . ") OR s.usuario_proceso IN (" . implode(',', $placeholders_usr) . "))";
} else {
    // Fallback: solo filtrar por usuario_proceso
    $where[] = "s.usuario_proceso IN (" . implode(',', $placeholders_usr) . ")";
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Agregar filtros adicionales del usuario
if ($estado) { 
    $where_clause .= ($where_clause ? ' AND ' : 'WHERE ') . "s.estado_general = :estado"; 
    $params['estado'] = $estado; 
}
if ($md) { 
    $where_clause .= ($where_clause ? ' AND ' : 'WHERE ') . "sc.md = :md"; 
    $params['md'] = strtoupper($md); 
}
if ($fecha_desde) { 
    $where_clause .= ($where_clause ? ' AND ' : 'WHERE ') . "DATE(s.fecha_solicitud) >= :fecha_desde"; 
    $params['fecha_desde'] = $fecha_desde; 
}
if ($fecha_hasta) { 
    $where_clause .= ($where_clause ? ' AND ' : 'WHERE ') . "DATE(s.fecha_solicitud) <= :fecha_hasta"; 
    $params['fecha_hasta'] = $fecha_hasta; 
}

// ==========================================
// CONSULTA PRINCIPAL
// ==========================================
$sql = "SELECT 
            s.id_solicitud,
            s.id_tienda,
            COALESCE(t.nombre_tienda, CONCAT('Tienda ID: ', s.id_tienda)) as tienda,
            s.usuario_tienda,
            sc.sku,
            sc.descripcion_producto,
            sc.id_familia,
            sc.carga_solicitada,
            sc.carga_final,
            sc.campo_cambios,
            sc.md,
            sc.mid,
            s.estado_general as estado_solicitud,
            s.observaciones_generales as observaciones,
            s.fecha_solicitud,
            s.fecha_procesamiento,
            s.usuario_proceso
        FROM Analisis_Procesos.solicitudes s
        LEFT JOIN Analisis_Procesos.solicitudes_carga sc ON s.id_solicitud = sc.id_solicitud
        LEFT JOIN (SELECT id_tienda, MAX(nombre_tienda) as nombre_tienda FROM rct.sugerido_diario GROUP BY id_tienda) t ON s.id_tienda = t.id_tienda
        $where_clause
        ORDER BY s.fecha_solicitud DESC, sc.id_detalle ASC";

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) { 
        $stmt->bindValue($key, $value); 
    }
    $stmt->execute();
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error SQL en exportar_excel: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos al generar el reporte.']);
    exit;
}

// ==========================================
// GENERAR EXCEL (formato CSV compatible con Excel)
// ==========================================
$filename = "solicitudes_carga_" . date('Y-m-d_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// BOM para UTF-8 en Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Encabezados
fputcsv($output, [
    'ID Solicitud', 'ID Tienda', 'Tienda', 'Usuario Tienda', 'SKU', 'Descripción Producto',
    'ID Familia', 'Carga Solicitada', 'Carga Final', 'Campo Cambios', 'Método Compra (MD)',
    'Mínimo Comprar (MID)', 'Estado', 'Observaciones', 'Fecha Solicitud', 'Fecha Procesamiento', 'Usuario Proceso'
], ';');

// Datos
foreach ($solicitudes as $s) {
    fputcsv($output, [
        $s['id_solicitud'], $s['id_tienda'], $s['tienda'] ?? 'N/A', $s['usuario_tienda'],
        $s['sku'], $s['descripcion_producto'], $s['id_familia'], $s['carga_solicitada'],
        $s['carga_final'] ?? '', $s['campo_cambios'] ?? '', $s['md'] ?? '', $s['mid'] ?? '',
        $s['estado_solicitud'], $s['observaciones'] ?? '', $s['fecha_solicitud'],
        $s['fecha_procesamiento'] ?? '', $s['usuario_proceso'] ?? ''
    ], ';');
}

fclose($output);
exit;
?>