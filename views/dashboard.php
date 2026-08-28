<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// 1. Si no hay sesión, ir al login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$nombre_analista = htmlspecialchars($_SESSION['nombre'] ?? 'Analista');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Analista - Sistema de Cargas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/dashboard_analista.css">
    <style>
        body { background: #f8f9fa; }
        .navbar-custom { background: #198754 !important; }
        .card-kpi { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); transition: transform 0.2s; }
        .card-kpi:hover { transform: translateY(-3px); }
        .card-kpi .icon { font-size: 2.5rem; opacity: 0.3; }
        .bg-gradient-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .bg-gradient-success { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; }
        .bg-gradient-warning { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; }
        .bg-gradient-info { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; }
        .chart-container { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .chart-container h5 { color: #2c3e50; font-weight: 600; }
        .quick-actions { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .btn-action { border-radius: 8px; padding: 12px 20px; font-weight: 500; }
        .page-header { background: white; padding: 25px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .loading-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(255,255,255,0.9); z-index: 9999;
            display: flex; align-items: center; justify-content: center;
        }
    </style>
</head>
<body>

    <!-- Loading -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="text-center">
            <div class="spinner-border text-success" style="width: 3rem; height: 3rem;"></div>
            <p class="mt-3 text-muted">Cargando dashboard...</p>
        </div>
    </div>

    <!-- Navbar -->
    <nav class="navbar navbar-dark navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <i class="bi bi-box-seam me-2"></i> Sistema de Cargas
            </a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3">
                    <i class="bi bi-person-circle me-1"></i>
                    <?= $nombre_analista ?>
                    <span class="badge bg-light text-dark ms-2">ANALISTA</span>
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
                    <h1 class="h3 mb-1"><i class="bi bi-speedometer2 me-2"></i>Panel Analista</h1>
                    <p class="text-muted mb-0">Resumen de actividad y métricas</p>
                </div>
                <div>
                    <button class="btn btn-outline-success" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise me-2"></i>Actualizar
                    </button>
                </div>
            </div>
        </div>

        <!-- Acciones Rápidas -->
        <div class="quick-actions mb-4">
            <h5 class="mb-3"><i class="bi bi-lightning-charge me-2"></i>Acciones Rápidas</h5>
            <div class="d-flex gap-2 flex-wrap">
                <a href="gestionar_cargas.php" class="btn btn-success btn-action">
                    <i class="bi bi-clipboard-check me-2"></i>Gestionar Cargas
                </a>
                <a href="gestionar_cargas.php?estado=PENDIENTE" class="btn btn-warning btn-action text-dark">
                    <i class="bi bi-clock-history me-2"></i>Ver Pendientes
                </a>
            </div>
        </div>

        <!-- KPIs Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card card-kpi bg-gradient-primary h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase mb-2" style="opacity: 0.9;">Pendientes</h6>
                                <h2 class="mb-0" id="kpiPendientes">-</h2>
                                <small style="opacity: 0.8;">Solicitudes por revisar</small>
                            </div>
                            <div class="icon"><i class="bi bi-clock-history"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card card-kpi bg-gradient-success h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase mb-2" style="opacity: 0.9;">Procesadas Hoy</h6>
                                <h2 class="mb-0" id="kpiProcesadasHoy">-</h2>
                                <small style="opacity: 0.8;">Tu productividad</small>
                            </div>
                            <div class="icon"><i class="bi bi-check-circle"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card card-kpi bg-gradient-warning h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase mb-2" style="opacity: 0.9;">Tasa Aprobación</h6>
                                <h2 class="mb-0" id="kpiTasaAprobacion">-%</h2>
                                <small style="opacity: 0.8;">SKUs aprobados</small>
                            </div>
                            <div class="icon"><i class="bi bi-percent"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card card-kpi bg-gradient-info h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase mb-2" style="opacity: 0.9;">Total SKUs</h6>
                                <h2 class="mb-0" id="kpiTotalSKUs">-</h2>
                                <small style="opacity: 0.8;">Procesados este mes</small>
                            </div>
                            <div class="icon"><i class="bi bi-boxes"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-6">
                <div class="chart-container">
                    <h5><i class="bi bi-pie-chart me-2"></i>Distribución por Estado</h5>
                    <div id="chartEstado" style="height: 350px;"></div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="chart-container">
                    <h5><i class="bi bi-bar-chart me-2"></i>Solicitudes Procesadas (Últimos 7 días)</h5>
                    <div id="chartDias" style="height: 350px;"></div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="chart-container">
                    <h5><i class="bi bi-pie-chart me-2"></i>Distribución por Método de Compra</h5>
                    <div id="chartMD" style="height: 350px;"></div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="chart-container">
                    <h5><i class="bi bi-trophy me-2"></i>Top 5 Tiendas con Más Solicitudes</h5>
                    <div id="chartTiendas" style="height: 350px;"></div>
                </div>
            </div>

            <!-- Gráfico 5: Top SKUs Más Solicitados -->
            <div class="col-md-12">
                <div class="chart-container">
                    <h5><i class="bi bi-bar-chart-steps me-2"></i>Top 10 SKUs Más Solicitados <small class="text-muted">(clic para ver detalle por tienda)</small></h5>
                    <div id="chartTopSKUs" style="height: 450px;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <script src="https://code.highcharts.com/modules/drilldown.js"></script>
    <script src="https://code.highcharts.com/modules/exporting.js"></script>
    <script src="https://code.highcharts.com/modules/accessibility.js"></script>
    <script src="../assets/js/dashboard_analista.js"></script>
</body>
</html>