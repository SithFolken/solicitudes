<?php
session_start();

// 1. Capturar datos del usuario ANTES de destruir la sesión
$nombre_usuario = $_SESSION['nombre'] ?? 'Usuario';
$rol = $_SESSION['rol'] ?? 'usuario';

// 2. Destruir la sesión de forma segura
$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sesión Finalizada - Sistema de Cargas</title>
    
    <!-- Bootstrap (Opcional, para iconos si no los tienes en otro lado) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- ✅ Enlace al archivo CSS separado -->
    <link rel="stylesheet" href="assets/css/logout.css">
</head>
<body>
    <div class="logout-card">
        <div class="logout-icon">
            <i class="bi bi-check-lg"></i>
        </div>
        
        <h1 class="logout-title">¡Hasta pronto, <?= htmlspecialchars($nombre_usuario) ?>!</h1>
        <p class="logout-subtitle">Tu sesión ha sido cerrada correctamente.</p>
        
        <div class="logout-info">
            <p><i class="bi bi-person-badge me-2"></i><strong>Rol:</strong> <?= ucfirst($rol) ?></p>
            <p><i class="bi bi-clock me-2"></i><strong>Hora de salida:</strong> <?= date('d-m-Y H:i') ?> hrs</p>
        </div>
        
        <p class="text-muted small mb-0">
            <i class="bi bi-shield-check me-1"></i>
            Gracias por usar el <strong>Sistema de Cargas</strong>
        </p>
        
        <div class="progress-bar-custom">
            <div class="bar"></div>
        </div>
        
        <p class="text-muted small mt-3 mb-0">
            Serás redirigido al inicio en <strong id="contador">3</strong> segundos...
        </p>
    </div>

    <script>
        // Contador regresivo
        let segundos = 3;
        const contadorEl = document.getElementById('contador');
        
        const intervalo = setInterval(() => {
            segundos--;
            if (contadorEl) contadorEl.textContent = segundos;
            
            if (segundos <= 0) {
                clearInterval(intervalo);
                window.location.href = 'index.php';
            }
        }, 1000);
    </script>
</body>
</html>