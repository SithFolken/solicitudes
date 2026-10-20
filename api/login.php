<?php
// Iniciar sesión de forma segura
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si ya hay sesión activa
if (isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Sesión ya iniciada', 
        'rol' => $_SESSION['rol']
    ]);
    exit;
}

// Incluir conexión a la base de datos
require_once '../config/database.php';

// Configurar headers
header('Content-Type: application/json');

// Verificar que sea método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// Obtener datos del formulario
$usuario_input = trim($_POST['usuario'] ?? '');
$password_input = $_POST['password'] ?? '';

// Validar que no estén vacíos
if (empty($usuario_input) || empty($password_input)) {
    echo json_encode(['success' => false, 'message' => 'Usuario y contraseña son requeridos']);
    exit;
}

$rol = null;
$user_data = null;

try {
    // ==========================================
    // 1. Buscar en ANALISTAS (rct.usuarios_solicitudes_oc)
    // ==========================================
    $stmt_analista = $pdo->prepare("
        SELECT usuario, pass, nombre, tipo_perfil, codigo_asignacion
        FROM rct.usuarios_solicitudes_oc 
        WHERE usuario = :usuario
    ");
    $stmt_analista->execute(['usuario' => $usuario_input]);
    $analista = $stmt_analista->fetch();

    if ($analista) {
        if (password_verify($password_input, $analista['pass']) || $password_input === $analista['pass']) {
            $rol = 'analista';
            $user_data = [
                'id' => $analista['usuario'],
                'nombre' => $analista['nombre'],
                'tipo_perfil' => $analista['tipo_perfil'],
                'codigo_asignacion' => $analista['codigo_asignacion'],
                'id_tienda' => null // ✅ Agregado para evitar warnings
            ];
        }
    }

    // ==========================================
    // 2. Si no es analista, buscar en TIENDAS (rst_central.usuarios)
    // ==========================================
    if (!$rol) {
        $stmt_tienda = $pdo->prepare("
            SELECT id_tienda, id_usuario, pass_usuario, nombre_usuario, tipo_comprador
            FROM rst_central.usuarios 
            WHERE id_usuario = :usuario
        ");
        $stmt_tienda->execute(['usuario' => $usuario_input]);
        $tienda = $stmt_tienda->fetch();

        if ($tienda) {
            if (password_verify($password_input, $tienda['pass_usuario']) || $password_input === $tienda['pass_usuario']) {
                
                // ✅ VALIDACIÓN DE PILOTO ANTES DE PERMITIR EL LOGIN
                require_once 'validar_piloto.php';
                $id_tienda_login = (int)$tienda['id_tienda'];
                
                if (!validarAccesoPiloto($pdo, $id_tienda_login)) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Tu tienda (ID: {$id_tienda_login}) aún no está habilitada para la fase piloto del Sistema de Cargas.",
                        'error_type' => 'PILOTO_NO_HABILITADO'
                    ]);
                    exit;
                }
                
                $rol = 'tienda';
                
                $nombre_tienda_real = "Tienda ID: " . $tienda['id_tienda']; 
                try {
                    $stmt_nombre = $pdo->prepare("SELECT nombre_tienda FROM rct.sugerido_diario WHERE id_tienda = :id_tienda LIMIT 1");
                    $stmt_nombre->execute(['id_tienda' => $tienda['id_tienda']]);
                    $res_nombre = $stmt_nombre->fetch(PDO::FETCH_ASSOC);
                    if ($res_nombre && !empty($res_nombre['nombre_tienda'])) {
                        $nombre_tienda_real = $res_nombre['nombre_tienda'];
                    }
                } catch (Exception $e) {
                    error_log("Error obteniendo nombre de tienda: " . $e->getMessage());
                }

                $user_data = [
                    'id' => $tienda['id_usuario'],
                    'id_tienda' => $tienda['id_tienda'],
                    'nombre_usuario' => $tienda['nombre_usuario'],
                    'nombre_tienda' => $nombre_tienda_real,
                    'tipo_comprador' => $tienda['tipo_comprador']
                ];
            }
        }
    }

    // ==========================================
    // 3. Respuesta final, guardado en sesión y REGISTRO DE ACCESO
    // ==========================================
    if ($rol && $user_data) {
        $_SESSION['user_id'] = $user_data['id'];
        $_SESSION['rol']     = $rol;
        
        if ($rol === 'analista') {
            $_SESSION['nombre'] = $user_data['nombre'];
            $_SESSION['tipo_perfil'] = $user_data['tipo_perfil'];
            $_SESSION['codigo_asignacion'] = $user_data['codigo_asignacion'];
        } else {
            $_SESSION['nombre_usuario'] = $user_data['nombre_usuario'];
            $_SESSION['nombre'] = $user_data['nombre_usuario'];
            $_SESSION['nombre_tienda'] = $user_data['nombre_tienda'];
            $_SESSION['id_tienda'] = $user_data['id_tienda'];
            $_SESSION['tipo_comprador'] = $user_data['tipo_comprador'];
        }

        // ✅ NUEVO: REGISTRAR EL LOGIN EN LA BASE DE DATOS
        try {
            $ip_cliente = $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';
            $id_tienda_log = ($rol === 'tienda') ? $user_data['id_tienda'] : null;
            
            $sql_log = "INSERT INTO Analisis_Procesos.registro_accesos 
                        (user_id, rol, id_tienda, ip_address, fecha_login) 
                        VALUES (:user_id, :rol, :id_tienda, :ip, NOW())";
            
            $stmt_log = $pdo->prepare($sql_log);
            $stmt_log->execute([
                'user_id' => $user_data['id'],
                'rol' => $rol,
                'id_tienda' => $id_tienda_log,
                'ip' => $ip_cliente
            ]);
            
            // Guardamos el ID del registro en la sesión para cerrarlo al hacer logout
            $_SESSION['id_sesion_log'] = $pdo->lastInsertId();
            
        } catch (PDOException $e_log) {
            // Si falla el log, no interrumpimos el login, solo lo reportamos
            error_log("Error registrando login: " . $e_log->getMessage());
        }

        $url_redireccion = ($rol === 'analista') ? 'views/dashboard.php' : 'views/dashboard_tienda.php';

        echo json_encode([
            'success' => true,
            'message' => 'Login exitoso',
            'rol' => $rol,
            'redirect' => $url_redireccion
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Usuario o contraseña incorrectos'
        ]);
    }

} catch (PDOException $e) {
    error_log("Error en login API: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos']);
} catch (Exception $e) {
    error_log("Error general en login: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error del servidor']);
}
?>