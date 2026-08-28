<?php
session_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] !== 'analista' && $_SESSION['rol'] !== 'ejecutor')) {
    header("Location: ../index.php");
    exit;
}

$nombre_analista = htmlspecialchars($_SESSION['nombre']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Cargas - Analista</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/gestionar_cargas.css">
</head>
<body class="bg-light">

    <!-- Navbar -->
    <nav class="navbar navbar-dark bg-success mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="bi bi-box-seam me-2"></i>Sistema de Cargas
            </a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3">
                    <i class="bi bi-person-circle me-1"></i>
                    <?= $nombre_analista ?> 
                    <span class="badge bg-light text-success">ANALISTA</span>
                </span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Salir
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-2 d-none d-md-block bg-light sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="bi bi-speedometer2 me-2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="gestionar_cargas.php">
                                <i class="bi bi-clipboard-check me-2"></i> Gestionar Cargas
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-10 ms-sm-auto px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestión de Cargas</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-success btn-sm me-2" onclick="exportarExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Exportar a Excel
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm btn-actualizar" id="btnActualizar" onclick="cargarSolicitudes()">
                            <i class="bi bi-arrow-clockwise me-1"></i> Actualizar
                        </button>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card mb-4 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Estado</label>
                                <select class="form-select" id="filtroEstado">
                                    <option value="TODOS">Todos</option>
                                    <option value="PENDIENTE">Pendiente</option>
                                    <option value="APROBADA">Aprobada</option>
                                    <option value="EN_PROCESO">En Proceso</option>
                                    <option value="PROCESADA">Procesada</option>
                                    <option value="PROCESADA_PARCIAL">Procesada Parcial</option>
                                    <option value="RECHAZADA">Rechazada</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Método de Compra</label>
                                <select class="form-select" id="filtroMD">
                                    <option value="TODOS">Todos</option>
                                    <option value="TRANSFERENCIA">Transferencia</option>
                                    <option value="CROSS_DOCKING">Cross Docking</option>
                                    <option value="COMPRA_LOCAL">Compra Local</option>
                                    <option value="NO_CARGAR">No Cargar</option>
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
                            <button type="button" class="btn btn-primary btn-sm" onclick="aplicarFiltros()">
                                <i class="bi bi-funnel me-1"></i> Aplicar Filtros
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="limpiarFiltros()">
                                <i class="bi bi-x-circle me-1"></i> Limpiar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tabla de solicitudes -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Solicitudes de Carga</h5>
                    </div>
                    <div class="card-body">
                        <div id="loadingTable" class="text-center py-5">
                            <div class="spinner-border text-success" role="status" style="width: 3rem; height: 3rem;">
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                            <p class="mt-3 text-muted">Cargando solicitudes...</p>
                        </div>
                        
                        <div class="table-responsive" id="tablaContainer" style="display: none;">
                            <table class="table table-hover align-middle" id="tablaSolicitudes">
                                <thead class="table-success">
                                    <tr>
                                        <th>ID</th>
                                        <th>TIENDA</th>
                                        <th class="text-center">SKUs</th>
                                        <th class="text-center">Cant. Total</th>
                                        <th class="text-center">ESTADO</th>
                                        <th>FECHA</th>
                                        <th class="text-center">ACCIONES</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodySolicitudes">
                                    <!-- Se llena dinámicamente con gestion_cargas.js -->
                                </tbody>
                            </table>
                        </div>

                        <div id="sinRegistros" class="text-center py-5" style="display: none;">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                            <p class="text-muted mt-3 fs-5">No se encontraron solicitudes</p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/gestion_cargas.js"></script>
</body>
</html>