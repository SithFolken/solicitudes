<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'analista') {
    echo json_encode(['success' => false, 'message' => 'Acceso denegado: No eres analista']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id_solicitud = isset($data['id_solicitud']) ? (int)$data['id_solicitud'] : 0;
$usuario_actual = $_SESSION['user_id'] ?? '';

if ($id_solicitud <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de solicitud inválido']);
    exit;
}

try {
    require_once '../config/database.php';

    // 1. Obtener datos de la solicitud
    $sql_sol = "SELECT id_familia, id_sub_familia, usuario_proceso, estado_general 
                FROM Analisis_Procesos.solicitudes 
                WHERE id_solicitud = :id_solicitud";
    $stmt_sol = $pdo->prepare($sql_sol);
    $stmt_sol->execute(['id_solicitud' => $id_solicitud]);
    $solicitud = $stmt_sol->fetch(PDO::FETCH_ASSOC);

    if (!$solicitud) {
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit;
    }

    // ✅ Cargar helper de reemplazos
    $analistas_efectivos = [$usuario_actual]; // Fallback por defecto
    try {
        require_once __DIR__ . '/helpers_reemplazos.php';
        $analistas_temp = obtenerAnalistasEfectivos($pdo, $usuario_actual);
        if (!empty($analistas_temp)) {
            $analistas_efectivos = $analistas_temp;
        }
    } catch (Exception $e) {
        error_log("Error en helper reemplazos: " . $e->getMessage());
    }

    $puede_acceder = false;

    // NIVEL 0: Validación directa por usuario_proceso (La más rápida y común)
    if (in_array(trim($solicitud['usuario_proceso']), $analistas_efectivos)) {
        $puede_acceder = true;
    }

    // NIVEL 1: Por SUBFAMILIA
    if (!$puede_acceder && !empty($solicitud['id_sub_familia'])) {
        $placeholders_sub = implode(',', array_fill(0, count($analistas_efectivos), '?'));
        $sql_sub = "SELECT 1 FROM input_clientes.planificacion_asignacion_equipos p
                    INNER JOIN rct.usuarios_solicitudes_oc u ON TRIM(p.analista_repo_tienda) = u.nombre
                    WHERE u.usuario IN ($placeholders_sub) AND p.id_sub_familia = :sub_fam LIMIT 1";
        $stmt_sub = $pdo->prepare($sql_sub);
        $stmt_sub->execute(array_merge($analistas_efectivos, ['sub_fam' => $solicitud['id_sub_familia']]));
        if ($stmt_sub->fetch()) $puede_acceder = true;
    }

    // NIVEL 2: Por FAMILIA
    if (!$puede_acceder && !empty($solicitud['id_familia'])) {
        $placeholders_fam = implode(',', array_fill(0, count($analistas_efectivos), '?'));
        $sql_fam = "SELECT 1 FROM FulFillment.distribucion_analista_familia d
                    INNER JOIN rct.usuarios_solicitudes_oc u ON d.Analista_reposicion_tienda LIKE CONCAT('%', u.nombre, '%')
                    WHERE u.usuario IN ($placeholders_fam) AND d.Familia = :fam LIMIT 1";
        $stmt_fam = $pdo->prepare($sql_fam);
        $stmt_fam->execute(array_merge($analistas_efectivos, ['fam' => $solicitud['id_familia']]));
        if ($stmt_fam->fetch()) $puede_acceder = true;
    }

    // ==========================================
    // RESULTADO FINAL
    // ==========================================
    if (!$puede_acceder) {
        error_log("❌ ACCESO DENEGADO: ID=$id_solicitud | Usuario=$usuario_actual | usuario_proceso='{$solicitud['usuario_proceso']}' | efectivos=[" . implode(',', $analistas_efectivos) . "]");
        echo json_encode(['success' => false, 'message' => 'No tienes permiso para procesar esta solicitud.']);
        exit;
    }

    // ✅ SI TIENE PERMISO, CAMBIAR EL ESTADO A EN_PROCESO AQUÍ MISMO (Operación Atómica)
    $sql_update = "UPDATE Analisis_Procesos.solicitudes 
                   SET estado_general = 'EN_PROCESO' 
                   WHERE id_solicitud = :id_solicitud AND estado_general = 'PENDIENTE'";
    $stmt_update = $pdo->prepare($sql_update);
    $stmt_update->execute(['id_solicitud' => $id_solicitud]);

    // Guardar en sesión
    $_SESSION['solicitud_activa'] = $id_solicitud;
    
    echo json_encode(['success' => true, 'message' => 'Sesión iniciada y estado actualizado correctamente']);

} catch (PDOException $e) {
    error_log("ERROR PDO: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos']);
} catch (Exception $e) {
    error_log("ERROR GENERAL: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error inesperado']);
}
?>