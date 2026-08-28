<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
    exit;
}

require_once '../config/database.php';
require_once 'ArbolDecision.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $archivo = $_FILES['archivo'];
    
    $id_tienda = (int)$_SESSION['id_tienda'];
    $usuario_tienda = $_SESSION['user_id'];

    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv'])) {
        echo json_encode(['success' => false, 'message' => 'Solo se permite formato CSV']);
        exit;
    }

    try {
        $contenido = file_get_contents($archivo['tmp_name']);
        $lineas = explode("\n", $contenido);
        
        $registros = [];
        // Saltar encabezado
        for ($i = 1; $i < count($lineas); $i++) {
            $columnas = str_getcsv($lineas[$i], ';');
            if (count($columnas) >= 2 && !empty($columnas[0])) {
                $registros[] = [
                    'sku' => trim($columnas[0]),
                    'cantidad' => (int)$columnas[1],
                    'descripcion' => trim($columnas[2] ?? ''),
                    'id_familia' => trim($columnas[3] ?? '')
                ];
            }
        }

        if (empty($registros)) {
            echo json_encode(['success' => false, 'message' => 'No se encontraron registros válidos']);
            exit;
        }

        $detalle = [];
        $aceptados = 0;
        $rechazados = 0;
        $cantidad_total_autorizada = 0;

        // Evaluar cada SKU (SIN guardar)
        foreach ($registros as $reg) {
            if ($reg['sku'] && $reg['cantidad'] > 0) {
                // Buscar datos en sugerido
                $sql_sug = "SELECT 
                                ROUND((COALESCE(vta_sem_3, 0) + COALESCE(vta_sem_2, 0) + COALESCE(vta_sem_1, 0)) / 3, 2) as PV6,
                                disp as disp_tda, pend as pend_tda, disp_bod,pend_bod, MD as MD_sugerido
                            FROM rct.sugerido_diario 
                            WHERE id_tienda = :id_tienda AND sku = :sku
                            ORDER BY fecha DESC LIMIT 1";
                $stmt_sug = $pdo->prepare($sql_sug);
                $stmt_sug->execute(['id_tienda' => $id_tienda, 'sku' => $reg['sku']]);
                $datos_sugerido = $stmt_sug->fetch();

                // Evaluar con árbol de decisión
                $evaluacion = ArbolDecision::evaluar($reg['sku'], $reg['cantidad'], $datos_sugerido, $pdo);

                $detalle[] = [
                    'sku' => $reg['sku'],
                    'descripcion' => $reg['descripcion'],
                    'cantidad_solicitada' => $reg['cantidad'],
                    'puede_cargar' => $evaluacion['puede_cargar'],
                    'motivo' => $evaluacion['motivo_rechazo'],
                    'familia' => $reg['id_familia']
                ];

                if ($evaluacion['puede_cargar']) {
                    $aceptados++;
                    $cantidad_total_autorizada += $reg['cantidad'];
                } else {
                    $rechazados++;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'detalle' => $detalle,
            'resumen' => [
                'total' => count($detalle),
                'aceptados' => $aceptados,
                'rechazados' => $rechazados,
                'cantidad_total' => $cantidad_total_autorizada
            ]
        ]);

    } catch (PDOException $e) {
        error_log("Error preview archivo: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error del servidor']);
    }
}
?>