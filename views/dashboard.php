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
    
    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- HOJAS DE ESTILO CORPORATIVAS (El orden es importante) -->
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/dashboard_analista.css">
</head>
<body>

    <!-- Loading -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="text-center">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;"></div>
            <p class="mt-3 text-muted fw-bold">Cargando dashboard...</p>
        </div>
    </div>

    <!-- Navbar con estilos inline corporativos -->
    <nav class="navbar navbar-dark mb-4" style="background: linear-gradient(135deg, #0056b3 0%, #003d82 100%) !important; box-shadow: 0 2px 8px rgba(0,0,0,0.15); min-height: 60px;">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php" style="color: white !important; font-weight: 700; font-size: 1.25rem;">
                <i class="bi bi-box-seam me-2"></i> Sistema de Cargas
            </a>
            <div class="d-flex align-items-center">
                <span class="me-3" style="color: white !important;">
                    <i class="bi bi-person-circle me-1"></i>
                    <?= $nombre_analista ?>
                    <span class="badge bg-light text-dark ms-2" style="color: #0056b3 !important;">ANALISTA</span>
                </span>
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
                    <h1 class="h3 mb-1"><i class="bi bi-speedometer2 me-2"></i>Panel Analista</h1>
                    <p class="text-muted mb-0">Resumen de actividad y métricas</p>
                </div>
                <div>
                    <button class="btn btn-outline-primary" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise me-2"></i>Actualizar
                    </button>
                </div>
            </div>
        </div>

        <!-- Acciones Rápidas -->
        <div class="quick-actions mb-4">
            <h5 class="mb-3"><i class="bi bi-lightning-charge me-2 text-corp-azul"></i>Acciones Rápidas</h5>
            <div class="d-flex gap-2 flex-wrap">
                <a href="gestionar_cargas.php" class="btn btn-primary btn-action">
                    <i class="bi bi-clipboard-check me-2"></i>Gestionar Cargas
                </a>
                <a href="gestionar_cargas.php?estado=PENDIENTE" class="btn btn-warning btn-action">
                    <i class="bi bi-clock-history me-2"></i>Ver Pendientes
                </a>
            </div>
        </div>

        <!-- KPIs Cards (Ahora usan los gradientes corporativos del CSS) -->
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

        <!-- KPIs de Tiempos (SLA) -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card card-kpi border-start border-4 border-success h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase mb-2 text-muted">A Tiempo</h6>
                                <h2 class="mb-0 text-success" id="kpiSlaTiempo">-</h2>
                                <small class="text-muted">Menos de 36 hrs</small>
                            </div>
                            <div class="icon text-success"><i class="bi bi-check-circle-fill"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <div class="card card-kpi border-start border-4 border-warning h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase mb-2 text-muted">Por Cumplirse</h6>
                                <h2 class="mb-0 text-warning" id="kpiSlaUrgente">-</h2>
                                <small class="text-muted">Entre 36 y 48 hrs</small>
                            </div>
                            <div class="icon text-warning"><i class="bi bi-exclamation-circle-fill"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <div class="card card-kpi border-start border-4 border-danger h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase mb-2 text-muted">Vencidas</h6>
                                <h2 class="mb-0 text-danger" id="kpiSlaVencidas">-</h2>
                                <small class="text-muted">Más de 48 hrs</small>
                            </div>
                            <div class="icon text-danger"><i class="bi bi-x-octagon-fill"></i></div>
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
                    <h5><i class="bi bi-trophy me-2"></i>Top 10 Tiendas con Más Solicitudes</h5>
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
     <script src="../assets/js/global.js"></script>
    <script src="../assets/js/dashboard_analista.js"></script>
</body>
</html>