<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    // Si no hay sesión, expulsar al login
    header("Location: index.php");
    exit;
}

function requireRol($rolRequerido) {
    if ($_SESSION['rol'] !== $rolRequerido) {
        // Si un ejecutor intenta entrar a una URL de tienda, lo sacamos
        header("Location: dashboard.php?error=acceso_denegado");
        exit;
    }
}
?>