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

if ($id_solicitud <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de solicitud inválido']);
    exit;
}

try {
    require_once '../config/database.php';

    // ✅ CONSULTA MAESTRA: Obtener familias del analista
    $sql_familias = "SELECT DISTINCT d.Familia as id_familia
                     FROM FulFillment.distribucion_analista_familia d
                     INNER JOIN rct.usuarios_solicitudes_oc u 
                         ON d.Analista_reposicion_tienda = u.nombre
                     WHERE u.usuario = :usuario_analista";
    
    $stmt_familias = $pdo->prepare($sql_familias);
    $stmt_familias->execute(['usuario_analista' => $usuario_actual]);
    $familias_asignadas = $stmt_familias->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($familias_asignadas)) {
        echo json_encode(['success' => false, 'message' => 'No tienes familias asignadas para procesar.']);
        exit;
    }
    
    $placeholders = [];
    $params = ['id_solicitud' => $id_solicitud];
    foreach ($familias_asignadas as $index => $familia) {
        $param_name = 'fam_' . $index;
        $placeholders[] = ':' . $param_name;
        $params[$param_name] = $familia;
    }
    
    // ✅ Verificar que la solicitud exista Y pertenezca a sus familias
    $sql_check = "SELECT COUNT(*) as existe 
                  FROM Analisis_Procesos.solicitudes 
                  WHERE id_solicitud = :id_solicitud 
                  AND id_familia IN (" . implode(',', $placeholders) . ")";
    
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute($params);
    $resultado = $stmt_check->fetch();
    
    if ($resultado['existe'] == 0) {
        echo json_encode(['success' => false, 'message' => 'No tienes permiso para procesar esta solicitud.']);
        exit;
    }

    // Si pasa la validación, guardar en sesión
    $_SESSION['solicitud_activa'] = $id_solicitud;
    error_log("Sesión guardada: solicitud_activa = " . $id_solicitud . " | Usuario: " . $usuario_actual);
    
    echo json_encode(['success' => true, 'message' => 'Sesión de procesamiento iniciada']);

} catch (PDOException $e) {
    error_log("Error iniciar procesamiento: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos']);
}
?>