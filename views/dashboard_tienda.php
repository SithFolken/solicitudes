<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// Validar que sea usuario tienda
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    header("Location: ../index.php");
    exit;
}

// ✅ CORREGIDO: Usar las variables correctas de la sesión
$nombre_tienda_real = htmlspecialchars($_SESSION['nombre_tienda'] ?? 'Tienda');
$id_tienda = $_SESSION['id_tienda'] ?? 0;
$nombre_usuario = htmlspecialchars($_SESSION['nombre_usuario'] ?? $_SESSION['nombre'] ?? 'Usuario');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Tienda - Sistema de Cargas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body>

    <!-- ✅ Navbar mejorado con información de tienda, usuario y badge de conectado -->
    <nav class="navbar navbar-dark navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard_tienda.php">
                <i class="bi bi-box-seam me-2"></i> Sistema de Cargas
            </a>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Información de la tienda y usuario -->
                <div class="user-info text-white">
                    <!-- Badge de conectado -->
                    <span class="badge badge-conectado d-flex align-items-center gap-1">
                        <span class="dot-pulse"></span>
                        Conectado
                    </span>
                    
                    <!-- Nombre de la tienda -->
                    <div class="d-flex align-items-center gap-2">
                        <span class="tienda-nombre">
                            <i class="bi bi-shop me-1"></i><?= $nombre_tienda_real ?>
                        </span>
                        <span class="tienda-id">(ID: <?= $id_tienda ?>)</span>
                    </div>
                    
                    <!-- Separador -->
                    <span class="separator">|</span>
                    
                    <!-- Nombre del usuario -->
                    <span class="usuario-nombre">
                        <i class="bi bi-person-circle me-1"></i><?= $nombre_usuario ?>
                    </span>
                </div>
                
                <!-- Botón de salir -->
                <a href="#" class="btn btn-outline-light btn-sm" onclick="confirmarSalida(event)" title="Cerrar sesión">
                    <i class="bi bi-box-arrow-right"></i> Salir
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <!-- Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1"><i class="bi bi-speedometer2 me-2"></i>Mi Dashboard</h1>
                    <p class="text-muted mb-0">Resumen de mis solicitudes de carga</p>
                </div>
                <div>
                    <button class="btn btn-outline-primary" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise me-2"></i>Actualizar
                    </button>
                </div>
            </div>
        </div>

        <!-- Acciones Rápidas -->
        <div class="quick-actions">
            <h5 class="mb-3"><i class="bi bi-lightning-charge me-2"></i>Acciones Principales</h5>
            <div class="d-flex gap-2 flex-wrap">
                <a href="solicitar_carga.php" class="btn btn-primary btn-action">
                    <i class="bi bi-cart-plus me-2"></i>Crear Nueva Solicitud
                </a>
                <a href="mis_solicitudes.php" class="btn btn-outline-secondary btn-action">
                    <i class="bi bi-clipboard-list me-2"></i>Gestionar Solicitudes
                </a>
            </div>
        </div>

        <!-- KPIs Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card card-kpi bg-gradient-pendiente h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase mb-2" style="opacity: 0.9;">Pendientes</h6>
                                <h2 class="mb-0" id="kpiPendientes">-</h2>
                                <small style="opacity: 0.8;">Por aprobar</small>
                            </div>
                            <div class="icon"><i class="bi bi-clock-history"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card card-kpi bg-gradient-aprobada h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase mb-2" style="opacity: 0.9;">Aprobadas</h6>
                                <h2 class="mb-0" id="kpiAprobadas">-</h2>
                                <small style="opacity: 0.8;">Este mes</small>
                            </div>
                            <div class="icon"><i class="bi bi-check-circle"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card card-kpi bg-gradient-procesada h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase mb-2" style="opacity: 0.9;">Procesadas</h6>
                                <h2 class="mb-0" id="kpiProcesadas">-</h2>
                                <small style="opacity: 0.8;">Completadas</small>
                            </div>
                            <div class="icon"><i class="bi bi-truck"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card card-kpi bg-gradient-rechazada h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase mb-2" style="opacity: 0.9;">Rechazadas</h6>
                                <h2 class="mb-0" id="kpiRechazadas">-</h2>
                                <small style="opacity: 0.8;">Total histórico</small>
                            </div>
                            <div class="icon"><i class="bi bi-x-circle"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-6">
                <div class="chart-container">
                    <h5><i class="bi bi-pie-chart me-2"></i>Mis Solicitudes por Estado</h5>
                    <div id="chartEstado" style="height: 350px;"></div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="chart-container">
                    <h5><i class="bi bi-bar-chart me-2"></i>Evolución Mensual (Últimos 6 meses)</h5>
                    <div id="chartMensual" style="height: 350px;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <script src="../assets/js/global.js"></script>
    <script src="../assets/js/dashboard_tienda.js"></script>
</body>
</html>