<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si ya hay sesión, redirigir según el rol
if (isset($_SESSION['user_id']) && isset($_SESSION['rol'])) {
    if ($_SESSION['rol'] === 'supervisor') {
        header("Location: views/supervisor.php");
        exit;
    } elseif ($_SESSION['rol'] === 'analista') {
        header("Location: views/dashboard.php");
        exit;
    } elseif ($_SESSION['rol'] === 'tienda') {
        header("Location: views/mis_solicitudes.php");
        exit;
    } elseif ($_SESSION['rol'] === 'ejecutor') {
        // Ajusta esta ruta si el ejecutor tiene un dashboard diferente
        header("Location: views/dashboard.php"); 
        exit;
    }
}

// Si no hay sesión, mostrar login
require_once 'views/login.php';
?>