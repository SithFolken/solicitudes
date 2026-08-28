<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si ya hay sesión, redirigir según el rol
if (isset($_SESSION['user_id']) && isset($_SESSION['rol'])) {
    if ($_SESSION['rol'] === 'analista') {
        header("Location: dashboard.php");
        exit;
    } elseif ($_SESSION['rol'] === 'tienda') {
        header("Location: dashboard_tienda.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema de Solicitud de Carga</title>
    
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
                    <div class="text-center">
                        <span class="system-title">Sistema de Solicitud de Carga</span>
                    </div>

                    <!-- Logo -->
                    <div class="logo-container">
                        <img src="assets/img/imagen_sodimac.png" alt="Logo Sodimac">
                    </div>
                    
                    <!-- Título de login -->
                    <h2 class="login-title">Iniciar Sesión</h2>
                    
                    <!-- Mensaje de error/éxito -->
                    <div id="loginMessage" class="alert d-none" role="alert"></div>

                    <form id="loginForm">
                        <div class="mb-4">
                            <label class="form-label">Usuario</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-person-fill"></i>
                                </span>
                                <input type="text" name="usuario" class="form-control form-control-modern" placeholder="Ingresa tu usuario" required autofocus>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Contraseña</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-lock-fill"></i>
                                </span>
                                <input type="password" name="password" class="form-control form-control-modern" placeholder="••••••••" required>
                            </div>
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" id="btnLogin" class="btn btn-login">
                                <span class="spinner-border spinner-border-sm d-none me-2" id="loadingSpinner"></span>
                                Ingresar
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

<script src="assets/js/app.js"></script>
</body>
</html>