<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si ya hay sesión, redirigir según el rol
if (isset($_SESSION['user_id']) && isset($_SESSION['rol'])) {
    if ($_SESSION['rol'] === 'analista') {
        header("Location: views/dashboard.php");
        exit;
    } elseif ($_SESSION['rol'] === 'tienda') {
        header("Location: views/dashboard_tienda.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema de Solicitud de Excepciones de Cargas</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-6 col-lg-4">
            <div class="card login-card p-4 p-md-5">
                <div class="card-body">
                    
                    <!-- Título del sistema -->
                    <div class="text-center mb-3">
                        <span class="system-title">Sistema de Solicitud de Excepciones de Cargas</span>
                    </div>

                    <!-- Logo -->
                    <div class="logo-container">
                        <img src="assets/img/imagen_sodimac.png" alt="Logo Sodimac" onerror="this.style.display='none'">
                    </div>
                    
                    <!-- Título de login -->
                    <h2 class="login-title">Iniciar Sesión</h2>
                    
                    <!-- Mensaje de error/éxito -->
                    <div id="loginMessage" class="alert d-none" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <span id="messageText"></span>
                    </div>

                    <form id="loginForm">
                        <div class="mb-4">
                            <label class="form-label" for="usuario">Usuario</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-person-fill"></i>
                                </span>
                                <input type="text" id="usuario" name="usuario" class="form-control form-control-modern" placeholder="Ingresa tu usuario" required autocomplete="username" autofocus>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label" for="password">Contraseña</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-lock-fill"></i>
                                </span>
                                <input type="password" id="password" name="password" class="form-control form-control-modern" placeholder="••••••••" required autocomplete="current-password">
                            </div>
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" id="btnLogin" class="btn btn-login">
                                <span class="spinner-border spinner-border-sm d-none me-2" id="loadingSpinner" role="status" aria-hidden="true"></span>
                                <span id="btnText">Ingresar</span>
                            </button>
                        </div>
                    </form>
                    
                    <!-- Footer -->
                    <div class="login-footer text-center">
                        <small class="d-block mb-1">
                            <i class="bi bi-building-gear me-1"></i>
                            Área de Planificación y Reposición
                        </small>
                        <small class="d-block">
                            Desarrollado por: <strong>Angel Perez</strong>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>