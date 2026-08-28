<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// Validar que sea usuario tienda
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    header("Location: ../index.php");
    exit;
}

$nombre_tienda = htmlspecialchars($_SESSION['nombre'] ?? 'Tienda');
$id_tienda = $_SESSION['id_tienda'] ?? 0;
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
    <style>
        body { background: #f8f9fa; }
        .navbar-custom { background: #0d6efd !important; }
        .card-kpi { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); transition: transform 0.2s; }
        .card-kpi:hover { transform: translateY(-3px); }
        .card-kpi .icon { font-size: 2.5rem; opacity: 0.3; }
        .bg-gradient-pendiente { background: linear-gradient(135deg, #6c757d 0%, #495057 100%); color: white; }
        .bg-gradient-aprobada { background: linear-gradient(135deg, #0dcaf0 0%, #0d6efd 100%); color: white; }
        .bg-gradient-procesada { background: linear-gradient(135deg, #198754 0%, #20c997 100%); color: white; }
        .bg-gradient-rechazada { background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%); color: white; }
        .chart-container { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .chart-container h5 { color: #2c3e50; font-weight: 600; }
        .quick-actions { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-bottom: 25px; }
        .btn-action { border-radius: 10px; padding: 15px 25px; font-weight: 600; font-size: 16px; }
        .page-header { background: white; padding: 25px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-dark navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard_tienda.php">
                <i class="bi bi-box-seam me-2"></i> Sistema de Cargas
            </a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3">
                    <i class="bi bi-person-circle me-1"></i>
                    <?= $nombre_tienda ?>
                    <span class="badge bg-light text-dark ms-2">TIENDA</span>
                </span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right me-1"></i> Salir
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
    <script src="../assets/js/dashboard_tienda.js"></script>
</body>
</html>