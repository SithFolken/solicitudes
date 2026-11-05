<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'analista') {
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id_solicitud = isset($data['id_solicitud']) ? (int)$data['id_solicitud'] : 0;
$usuario_actual = $_SESSION['user_id'] ?? '';
$nombre_analista_sesion = $_SESSION['nombre'] ?? ''; // ✅ Necesario para la nueva tabla

if ($id_solicitud <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de solicitud inválido']);
    exit;
}

try {
    require_once '../config/database.php';

    // 1. Obtener datos de la solicitud
    $sql_sol = "SELECT id_familia, id_sub_familia, usuario_proceso 
                FROM Analisis_Procesos.solicitudes 
                WHERE id_solicitud = :id_solicitud";
    $stmt_sol = $pdo->prepare($sql_sol);
    $stmt_sol->execute(['id_solicitud' => $id_solicitud]);
    $solicitud = $stmt_sol->fetch(PDO::FETCH_ASSOC);

    if (!$solicitud) {
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada.']);
        exit;
    }

    $puede_acceder = false;

    // ==========================================
    // ✅ VALIDACIÓN DE 3 NIVELES (CORREGIDA)
    // ==========================================
    
    // NIVEL 1: Verificar por SUBFAMILIA (Sistema Nuevo)
    if (!empty($solicitud['id_sub_familia'])) {
        $sql_sub = "SELECT 1 FROM input_clientes.planificacion_asignacion_equipos 
                    WHERE analista_repo_tienda = :nombre AND id_sub_familia = :sub_fam";
        $stmt_sub = $pdo->prepare($sql_sub);
        $stmt_sub->execute([
            'nombre' => $nombre_analista_sesion,
            'sub_fam' => $solicitud['id_sub_familia']
        ]);
        if ($stmt_sub->fetch()) {
            $puede_acceder = true;
        }
    }

    // NIVEL 2: Fallback por FAMILIA (Sistema Antiguo)
    if (!$puede_acceder && !empty($solicitud['id_familia'])) {
        // ✅ CORREGIDO: Usar LIKE para manejar múltiples analistas separados por '|'
        $sql_fam = "SELECT 1 FROM FulFillment.distribucion_analista_familia d
                    INNER JOIN rct.usuarios_solicitudes_oc u 
                        ON d.Analista_reposicion_tienda LIKE CONCAT('%', u.nombre, '%')
                    WHERE u.usuario = :usuario AND d.Familia = :fam";
        $stmt_fam = $pdo->prepare($sql_fam);
        $stmt_fam->execute([
            'usuario' => $usuario_actual,
            'fam' => $solicitud['id_familia']
        ]);
        if ($stmt_fam->fetch()) {
            $puede_acceder = true;
        }
    }

    // NIVEL 3: Emergencia - Si es el usuario asignado directamente
    if (!$puede_acceder && $solicitud['usuario_proceso'] === $nombre_analista_sesion) {
        $puede_acceder = true;
    }

    if (!$puede_acceder) {
        echo json_encode(['success' => false, 'message' => 'No tienes permiso para procesar esta solicitud.']);
        exit;
    }

    // Si pasa la validación, guardar en sesión
    $_SESSION['solicitud_activa'] = $id_solicitud;
    error_log("Sesión guardada: solicitud_activa = " . $id_solicitud . " | Usuario: " . $usuario_actual);
    
    echo json_encode(['success' => true, 'message' => 'Sesión de procesamiento iniciada']);

} catch (PDOException $e) {
    error_log("Error iniciar procesamiento: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>