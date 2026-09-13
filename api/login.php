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
        // Validar contraseña (texto plano o hash)
        if (password_verify($password_input, $analista['pass']) || 
            $password_input === $analista['pass']) {
            
            $rol = 'analista';
            $user_data = [
                'id' => $analista['usuario'],
                'nombre' => $analista['nombre'],
                'tipo_perfil' => $analista['tipo_perfil'],
                'codigo_asignacion' => $analista['codigo_asignacion']
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
            // Validar contraseña
            if (password_verify($password_input, $tienda['pass_usuario']) || 
                $password_input === $tienda['pass_usuario']) {
                
                $rol = 'tienda';
                
                // ✅ NUEVO: Obtener el nombre REAL de la tienda desde sugerido_diario
                $nombre_tienda_real = "Tienda ID: " . $tienda['id_tienda']; // Valor por defecto
                try {
                    $stmt_nombre = $pdo->prepare("SELECT nombre_tienda FROM rct.sugerido_diario WHERE id_tienda = :id_tienda LIMIT 1");
                    $stmt_nombre->execute(['id_tienda' => $tienda['id_tienda']]);
                    $res_nombre = $stmt_nombre->fetch(PDO::FETCH_ASSOC);
                    if ($res_nombre && !empty($res_nombre['nombre_tienda'])) {
                        $nombre_tienda_real = $res_nombre['nombre_tienda'];
                    }
                } catch (Exception $e) {
                    error_log("Error obteniendo nombre de tienda: " . $e->getMessage());
                    // Si falla, se queda con el valor por defecto
                }

                $user_data = [
                    'id' => $tienda['id_usuario'],
                    'id_tienda' => $tienda['id_tienda'],
                    'nombre_usuario' => $tienda['nombre_usuario'], // Ej: Angel Perez
                    'nombre_tienda' => $nombre_tienda_real,        // Ej: SHC CERRILLOS
                    'tipo_comprador' => $tienda['tipo_comprador']
                ];
            }
        }
    }

    // ==========================================
    // 3. Respuesta final y guardado en sesión
    // ==========================================
    if ($rol && $user_data) {
        // Guardar en sesión
        $_SESSION['user_id'] = $user_data['id'];
        $_SESSION['rol']     = $rol;
        
        if ($rol === 'analista') {
            $_SESSION['nombre'] = $user_data['nombre'];
            $_SESSION['tipo_perfil'] = $user_data['tipo_perfil'];
            $_SESSION['codigo_asignacion'] = $user_data['codigo_asignacion'];
        } else {
            // ✅ GUARDAMOS AMBOS NOMBRES EN LA SESIÓN PARA TIENDAS
            $_SESSION['nombre_usuario'] = $user_data['nombre_usuario']; // Nombre del usuario
            $_SESSION['nombre'] = $user_data['nombre_usuario'];          // Para compatibilidad
            $_SESSION['nombre_tienda'] = $user_data['nombre_tienda'];    // Nombre real de la tienda
            $_SESSION['id_tienda'] = $user_data['id_tienda'];
            $_SESSION['tipo_comprador'] = $user_data['tipo_comprador'];
        }

        // ✅ CORRECCIÓN: Definir URL de redirección según el rol
        $url_redireccion = ($rol === 'analista') ? 'views/dashboard.php' : 'views/dashboard_tienda.php';

        echo json_encode([
            'success' => true,
            'message' => 'Login exitoso',
            'rol' => $rol,
            'redirect' => $url_redireccion  // ✅ CORRECCIÓN: Agregar redirect
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Usuario o contraseña incorrectos'
        ]);
    }

} catch (PDOException $e) {
    error_log("Error en login API: " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error de base de datos'
    ]);
} catch (Exception $e) {
    error_log("Error general en login: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Error del servidor'
    ]);
}
?>