<?php
session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validar que sea usuario de tienda
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    die('Acceso no autorizado');
}

// Nombre del archivo
$filename = "plantilla_carga.csv";

// Headers para forzar la descarga
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Crear el archivo CSV
$output = fopen('php://output', 'w');

// Agregar BOM para Excel (UTF-8 compatible, evita problemas con tildes)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Encabezados (Agregamos una columna de Nota solo para guía)
fputcsv($output, ['SKU', 'Cantidad', 'Nota (Guía)'], ';');

// Filas de ejemplo con la aclaración que pediste
fputcsv($output, ['123456', '10', '<-- Ejemplo: Reemplace con su SKU real'], ';');
fputcsv($output, ['789012', '25', '<-- Ejemplo: Reemplace con la cantidad'], ';');
fputcsv($output, ['345678', '15', '<-- Ejemplo: Borre esta fila antes de subir'], ';');

fclose($output);
exit;
?>