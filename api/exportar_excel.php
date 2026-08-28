<?php
session_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'analista') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

require_once '../config/database.php';

// Obtener el código de asignación del analista logueado
$codigo_asignacion = $_SESSION['codigo_asignacion'] ?? '';

if (empty($codigo_asignacion)) {
    die('Analista sin familias asignadas');
}

// Obtener filtros
$estado = $_GET['estado'] ?? '';
$md = $_GET['md'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

$sql = "SELECT 
            s.id_solicitud,
            s.id_tienda,
            u.nombre_usuario as tienda,
            s.usuario_tienda,
            s.sku,
            s.descripcion_producto,
            s.id_familia,
            s.carga_solicitada,
            s.carga_final,
            s.campo_cambios,
            s.md,
            s.mid,
            s.estado_solicitud,
            s.observaciones,
            s.fecha_solicitud,
            s.fecha_procesamiento,
            s.usuario_proceso
        FROM Analisis_Procesos.solicitudes_carga s
        LEFT JOIN rst_central.usuarios u ON s.id_tienda = u.id_tienda
        LEFT JOIN FulFillment.distribucion_analista_familia d ON s.id_familia = d.Familia
        WHERE d.codigo_asignacion = :codigo_asignacion";

$params = ['codigo_asignacion' => $codigo_asignacion];

if ($estado) {
    $sql .= " AND s.estado_solicitud = :estado";
    $params['estado'] = $estado;
}

if ($md) {
    $sql .= " AND s.md = :md";
    $params['md'] = $md;
}

if ($fecha_desde) {
    $sql .= " AND DATE(s.fecha_solicitud) >= :fecha_desde";
    $params['fecha_desde'] = $fecha_desde;
}

if ($fecha_hasta) {
    $sql .= " AND DATE(s.fecha_solicitud) <= :fecha_hasta";
    $params['fecha_hasta'] = $fecha_hasta;
}

$sql .= " ORDER BY s.fecha_solicitud DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$solicitudes = $stmt->fetchAll();

// Generar Excel (formato CSV compatible con Excel)
$filename = "solicitudes_carga_" . date('Y-m-d_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// BOM para UTF-8 en Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Encabezados
fputcsv($output, [
    'ID Solicitud',
    'ID Tienda',
    'Tienda',
    'Usuario Tienda',
    'SKU',
    'Descripción Producto',
    'ID Familia',
    'Carga Solicitada',
    'Carga Final',
    'Campo Cambios',
    'Método Compra (MD)',
    'Mínimo Comprar (MID)',
    'Estado',
    'Observaciones',
    'Fecha Solicitud',
    'Fecha Procesamiento',
    'Usuario Proceso'
], ';');

// Datos
foreach ($solicitudes as $s) {
    fputcsv($output, [
        $s['id_solicitud'],
        $s['id_tienda'],
        $s['tienda'] ?? 'N/A',
        $s['usuario_tienda'],
        $s['sku'],
        $s['descripcion_producto'],
        $s['id_familia'],
        $s['carga_solicitada'],
        $s['carga_final'] ?? '',
        $s['campo_cambios'] ?? '',
        $s['md'] ?? '',
        $s['mid'] ?? '',
        $s['estado_solicitud'],
        $s['observaciones'] ?? '',
        $s['fecha_solicitud'],
        $s['fecha_procesamiento'] ?? '',
        $s['usuario_proceso'] ?? ''
    ], ';');
}

fclose($output);
exit;
?>