<?php
// api/helpers.php
require_once __DIR__ . '/../config/database.php';

/**
 * Detecta la información completa de un SKU:
 * - Subfamilia
 * - Familia
 * - Analista asignado
 * - Tipo (normal / peligroso)
 */
function detectarInfoSKU($pdo, $sku, $es_peligroso = false) {
    // 1. Buscar sub_fam en sugerido_diario
    $sql = "SELECT DISTINCT sub_fam, id_familia 
            FROM rct.sugerido_diario 
            WHERE sku = :sku 
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['sku' => $sku]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$info || empty($info['sub_fam'])) {
        return [
            'sku' => $sku,
            'error' => 'SKU no encontrado en sugerido_diario',
            'sub_familia' => null,
            'familia' => null,
            'analista' => null,
            'tipo' => $es_peligroso ? 'peligroso' : 'normal'
        ];
    }
    
    $sub_familia = (int)$info['sub_fam'];
    $familia = (int)$info['id_familia'];
    
    // 2. Buscar analista en planificacion_asignacion_equipos
    $sql_analista = "SELECT analista_repo_tienda 
                     FROM input_clientes.planificacion_asignacion_equipos 
                     WHERE id_sub_familia = :sub_familia 
                     LIMIT 1";
    $stmt_analista = $pdo->prepare($sql_analista);
    $stmt_analista->execute(['sub_familia' => $sub_familia]);
    $analista_row = $stmt_analista->fetch(PDO::FETCH_ASSOC);
    
    $analista = $analista_row['analista_repo_tienda'] ?? null;
    
    return [
        'sku' => $sku,
        'sub_familia' => $sub_familia,
        'familia' => $familia,
        'analista' => $analista,
        'tipo' => $es_peligroso ? 'peligroso' : 'normal',
        'error' => null
    ];
}

/**
 * Agrupa un array de SKUs por {sub_familia + tipo}
 * y devuelve la estructura lista para crear solicitudes
 */
function agruparSKUsParaSolicitudes($pdo, $skus, $skus_peligrosos = []) {
    $grupos = [];
    
    // Procesar SKUs normales
    foreach ($skus as $sku) {
        $info = detectarInfoSKU($pdo, $sku, false);
        if ($info['error']) continue;
        
        $key = $info['sub_familia'] . '_normal';
        if (!isset($grupos[$key])) {
            $grupos[$key] = [
                'sub_familia' => $info['sub_familia'],
                'familia' => $info['familia'],
                'analista' => $info['analista'],
                'tipo' => 'normal',
                'skus' => []
            ];
        }
        $grupos[$key]['skus'][] = $sku;
    }
    
    // Procesar SKUs peligrosos (usando tu lógica existente de detección)
    foreach ($skus_peligrosos as $sku) {
        $info = detectarInfoSKU($pdo, $sku, true);
        if ($info['error']) continue;
        
        $key = $info['sub_familia'] . '_peligroso';
        if (!isset($grupos[$key])) {
            $grupos[$key] = [
                'sub_familia' => $info['sub_familia'],
                'familia' => $info['familia'],
                'analista' => $info['analista'],
                'tipo' => 'peligroso',
                'skus' => []
            ];
        }
        $grupos[$key]['skus'][] = $sku;
    }
    
    return $grupos;
}
?>