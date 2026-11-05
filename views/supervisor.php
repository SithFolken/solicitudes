<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'supervisor') {
    header("Location: ../index.php");
    exit;
}

require_once '../config/database.php';
require_once '../api/obtener_familias_supervisor.php'; // ✅ Incluimos la función

$nombre_supervisor = htmlspecialchars($_SESSION['nombre'] ?? 'Supervisor');
$id_supervisor = $_SESSION['user_id'] ?? '';
$familias_supervisor = obtenerFamiliasSupervisor($pdo, $id_supervisor);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Supervisor - KPIs</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f6f9; }
        .kpi-card { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .kpi-card:hover { transform: translateY(-3px); }
        .kpi-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .chart-container { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.05); height: 100%; }
        .table-container { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

<nav class="navbar navbar-dark mb-4" style="background: linear-gradient(135deg, #0056b3 0%, #003d82 100%); min-height: 60px;">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-2">
            <a href="#" class="btn btn-outline-light btn-sm" onclick="confirmarSalida(event)" title="Cerrar sesión">
                <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
            </a>
            <button class="btn btn-light btn-sm" onclick="cargarKPIs()" title="Actualizar datos">
                <i class="bi bi-arrow-clockwise me-1"></i> Actualizar
            </button>
        </div>
        
        <span class="navbar-brand mb-0 h1 text-white">
            <i class="bi bi-speedometer2 me-2"></i>Dashboard de Supervisor
        </span>
        
        <!-- ✅ DERECHA: Usuario + BADGE DE FAMILIAS -->
        <div class="text-white text-end">
            <div><i class="bi bi-person-circle me-1"></i><?= $nombre_supervisor ?></div>
            <small>
                <i class="bi bi-eye me-1"></i>
                <?php if ($familias_supervisor === 'TODAS'): ?>
                    <span class="badge bg-success">Vista Global</span>
                <?php elseif (is_array($familias_supervisor) && count($familias_supervisor) > 0): ?>
                    <?php 
                    // $familias_supervisor ahora es un array de centenas: [1, 2] para "100-200"
                    $centenas = $familias_supervisor;
                    $texto = '';
                    
                    if (count($centenas) === 1) {
                        // Solo una centena: "100" → "Familias 1xx"
                        $texto = "Familias {$centenas[0]}xx";
                    } else {
                        // Múltiples centenas: [1, 2] → "Familias 1xx y 2xx"
                        $texto = "Familias " . implode('xx, ', $centenas) . "xx";
                    }
                    
                    echo '<span class="badge bg-info text-dark" title="Familias asignadas">';
                    echo $texto;
                    echo '</span>';
                    ?>
                <?php else: ?>
                    <span class="badge bg-danger">Sin acceso</span>
                <?php endif; ?>
            </small>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    <!-- Filtros de Fecha -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm p-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <h6 class="mb-0 text-muted"><i class="bi bi-calendar-range me-2"></i>Período de Análisis:</h6>
                    <div class="d-flex gap-2 flex-wrap">
                        <input type="date" id="fechaInicio" class="form-control form-control-sm" style="width: 150px;">
                        <input type="date" id="fechaFin" class="form-control form-control-sm" style="width: 150px;">
                        <button class="btn btn-primary btn-sm" onclick="cargarKPIs()"><i class="bi bi-search me-1"></i> Filtrar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tarjetas de KPIs Principales -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card kpi-card p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-muted mb-1">Total</h6><h3 class="mb-0 fw-bold" id="kpiTotal">0</h3></div>
                    <div class="kpi-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-inbox"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card kpi-card p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-muted mb-1">Pendientes</h6><h3 class="mb-0 fw-bold text-warning" id="kpiPendientes">0</h3></div>
                    <div class="kpi-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-clock-history"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card kpi-card p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-muted mb-1">En Proceso</h6><h3 class="mb-0 fw-bold text-info" id="kpiEnProceso">0</h3></div>
                    <div class="kpi-icon bg-info bg-opacity-10 text-info"><i class="bi bi-hourglass-split"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card kpi-card p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-muted mb-1">Procesadas</h6><h3 class="mb-0 fw-bold text-success" id="kpiProcesadas">0</h3></div>
                    <div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="bi bi-check-circle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card kpi-card p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-muted mb-1">Rechazadas</h6><h3 class="mb-0 fw-bold text-danger" id="kpiRechazadas">0</h3>
                    <small class="text-muted" id="kpiTasaRechazo">0%</small></div>
                    <div class="kpi-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-x-octagon"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card kpi-card p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-muted mb-1">Con Retraso</h6><h3 class="mb-0 fw-bold text-danger" id="kpiRetrasadas">0</h3>
                    <small class="text-muted" id="kpiPromedioRetraso">0h prom.</small></div>
                    <div class="kpi-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-exclamation-triangle"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Gráficos -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="chart-container">
                <h6 class="fw-bold mb-3"><i class="bi bi-pie-chart me-2"></i>Distribución por Método de Despacho</h6>
                <div id="chartMD" style="height: 300px;"></div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="chart-container">
                <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart me-2"></i>Top 5 Familias</h6>
                <div id="chartFamilias" style="height: 300px;"></div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="chart-container">
                <h6 class="fw-bold mb-3"><i class="bi bi-graph-up me-2"></i>Motivos Más Comunes</h6>
                <div id="chartMotivos" style="height: 300px;"></div>
            </div>
        </div>
    </div>

    <!-- Desempeño de Analistas -->
        <!-- Desempeño de Analistas -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="table-container">
                <h6 class="fw-bold mb-3"><i class="bi bi-people me-2"></i>Desempeño por Analista</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Analista</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Aprobadas</th>
                                <th class="text-center">Rechazadas</th>
                                <th class="text-center">Pendientes</th>
                                <th class="text-center">En Proceso</th>
                                <th class="text-center">Con Retraso</th>
                                <th class="text-center">Tasa Aprobación</th>
                                <th class="text-center">Tiempo Promedio</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyAnalistas">
                            <tr><td colspan="9" class="text-center py-4 text-muted">Cargando datos...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Tiendas y Familias -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="table-container">
                <h6 class="fw-bold mb-3"><i class="bi bi-shop me-2"></i>Top 10 Tiendas por Volumen</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr><th>Tienda</th><th class="text-center">Total</th><th class="text-center">Rechazadas</th><th class="text-center">Tasa Rechazo</th><th class="text-center">Estado</th></tr>
                        </thead>
                        <tbody id="tbodyTiendas"><tr><td colspan="5" class="text-center py-4">Cargando datos...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="table-container">
                <h6 class="fw-bold mb-3"><i class="bi bi-box-seam me-2"></i>Top 10 Familias</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr><th>Familia</th><th class="text-center">Total</th><th class="text-center">Rechazadas</th><th class="text-center">Tasa Rechazo</th></tr>
                        </thead>
                        <tbody id="tbodyFamilias"><tr><td colspan="4" class="text-center py-4">Cargando datos...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

        <!-- SKUs con más problemas -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="table-container">
                <h6 class="fw-bold mb-3"><i class="bi bi-exclamation-diamond me-2"></i>SKUs con Más Rechazos (Problemas Recurrentes)</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr><th>SKU</th><th>Descripción</th><th class="text-center">Veces Rechazado</th><th>Motivos de Rechazo</th></tr>
                        </thead>
                        <tbody id="tbodySKUsProblema"><tr><td colspan="4" class="text-center py-4">Cargando datos...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- NUEVA SECCIÓN: ANÁLISIS DE MOTIVOS DE RECHAZO GENERALIZADOS -->
    <!-- ========================================== -->
    <div class="row mb-4 mt-2">
        <div class="col-12">
            <div class="table-container">
                <h5 class="fw-bold mb-2"><i class="bi bi-funnel-fill me-2 text-danger"></i>Análisis de Motivos de Rechazo (Generalizado)</h5>
                <p class="text-muted mb-0"><small><i class="bi bi-info-circle me-1"></i>Los mensajes largos del árbol de decisión se han agrupado en categorías generales para facilitar el análisis de patrones.</small></p>
            </div>
        </div>
    </div>

    <!-- Gráfico 1 y 2: Motivos Generales y Distribución -->
    <div class="row mb-4">
        <div class="col-md-6 mb-3">
            <div class="chart-container">
                <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart-fill me-2 text-danger"></i>Motivos de Rechazo Arbol Desicion - Vista General</h6>
                <div id="chartMotivosGeneral" style="height: 350px;"></div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="chart-container">
                <h6 class="fw-bold mb-3"><i class="bi bi-pie-chart-fill me-2 text-danger"></i>Distribución Porcentual de Motivos</h6>
                <div id="chartMotivosDistribucion" style="height: 350px;"></div>
            </div>
        </div>
    </div>

    <!-- Gráfico 3: Motivos por Tienda -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="chart-container">
                <h6 class="fw-bold mb-3"><i class="bi bi-shop me-2 text-primary"></i>Motivos de Rechazo por Tienda - Arbol Desicion (Top 5 con más rechazos)</h6>
                <div id="chartMotivosTienda" style="height: 400px;"></div>
            </div>
        </div>
    </div>

    <!-- Gráfico 4: Motivos por Familia -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="chart-container">
                <h6 class="fw-bold mb-3"><i class="bi bi-box-seam me-2 text-success"></i>Motivos de Rechazo Arbol Desicion por Familia</h6>
                <div id="chartMotivosFamilia" style="height: 400px;"></div>
            </div>
        </div>
    </div>

</div> <!-- Cierre del container-fluid -->

<script src="https://code.highcharts.com/highcharts.js"></script>
<script src="https://code.highcharts.com/modules/exporting.js"></script>
<script src="https://code.highcharts.com/modules/export-data.js"></script>
<script src="https://code.highcharts.com/modules/accessibility.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/global.js"></script>
<script src="../assets/js/supervisor.js"></script>
</body>
</html>
