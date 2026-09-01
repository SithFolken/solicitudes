<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// Validación de seguridad
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    header("Location: ../index.php");
    exit;
}

$nombre_tienda = htmlspecialchars($_SESSION['nombre'] ?? 'Tienda');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Solicitudes de Carga</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .sidebar { min-height: 100vh; }
        .card-counter { padding: 20px; border-radius: 10px; color: white; text-align: center; }
        .bg-pendiente { background-color: #6c757d; }
        .bg-aprobada { background-color: #0dcaf0; color: #000; }
        .bg-proceso { background-color: #ffc107; color: #000; }
        .bg-procesada { background-color: #198754; }
        .bg-rechazada { background-color: #dc3545; }
    </style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-dark bg-primary mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard_tienda.php">
                <i class="bi bi-arrow-left"></i> Panel Tienda
            </a>
            <span class="text-white">Tienda: <?= $nombre_tienda ?></span>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
           <nav class="col-md-2 d-none d-md-block bg-light sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard_tienda.php">
                                <i class="bi bi-speedometer2 me-2"></i> Panel Principal
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="solicitar_carga.php">
                                <i class="bi bi-cart-plus me-2"></i> Solicitar Carga
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="mis_solicitudes.php">
                                <i class="bi bi-card-checklist me-2"></i> Mis Solicitudes
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <main class="col-md-10 ms-sm-auto px-md-4 py-4">
                <div class="d-flex justify-content-between align-items-center pb-2 mb-4">
                    <h1 class="h2">Mis Solicitudes de Carga</h1>
                    <button class="btn btn-outline-primary" onclick="cargarSolicitudes()">
                        <i class="bi bi-arrow-clockwise"></i> Actualizar
                    </button>
                </div>

                <!-- Tarjetas de Resumen -->
                <div class="row mb-4">
                    <div class="col-md"><div class="card-counter bg-pendiente"><h3 id="countPendientes">0</h3><small>Pendientes</small></div></div>
                    <div class="col-md"><div class="card-counter bg-aprobada"><h3 id="countAprobadas">0</h3><small>Aprobadas</small></div></div>
                    <div class="col-md"><div class="card-counter bg-proceso"><h3 id="countProceso">0</h3><small>En Proceso</small></div></div>
                    <div class="col-md"><div class="card-counter bg-procesada"><h3 id="countProcesadas">0</h3><small>Procesadas</small></div></div>
                    <div class="col-md"><div class="card-counter bg-rechazada"><h3 id="countRechazadas">0</h3><small>Rechazadas</small></div></div>
                </div>

                <!-- Filtros -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Estado</label>
                                <select class="form-select" id="filtroEstado">
                                    <option value="TODOS">Todos los estados</option>
                                    <option value="PENDIENTE">Pendiente</option>
                                    <option value="EN_PROCESO">En Proceso</option>
                                    <option value="PROCESADA">Procesada</option>
                                    <option value="RECHAZADA">Rechazada</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Método de Compra (MD)</label>
                                <select class="form-select" id="filtroMD">
                                    <option value="TODOS">Todos</option>
                                    <option value="TRANSFERENCIA">Transferencia</option>
                                    <option value="CROSS_DOCKING">Cross Docking</option>
                                    <option value="COMPRA_LOCAL">Compra Local</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Fecha Desde</label>
                                <input type="date" class="form-control" id="fechaDesde">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Fecha Hasta</label>
                                <input type="date" class="form-control" id="fechaHasta">
                            </div>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-primary" onclick="aplicarFiltros()"><i class="bi bi-funnel"></i> Aplicar Filtros</button>
                            <button class="btn btn-outline-secondary" onclick="limpiarFiltros()"><i class="bi bi-x-circle"></i> Limpiar</button>
                        </div>
                    </div>
                </div>

                <!-- Tabla de Solicitudes -->
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Listado Detallado</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>SKUs</th>
                                        <th>Cant. Total</th>
                                        <th>Estado</th>
                                        <th>Fecha</th>
                                        <th>Observaciones</th>
                                        <th class="text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodySolicitudes">
                                    <tr><td colspan="7" class="text-center py-4">Cargando solicitudes...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="sinRegistros" class="text-center py-4 text-muted" style="display: none;">
                            No se encontraron solicitudes con los filtros seleccionados.
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/mis_solicitudes.js"></script>
</body>
</html>