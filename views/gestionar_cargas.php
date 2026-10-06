<?php
session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Validación de seguridad
if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] !== 'analista' && $_SESSION['rol'] !== 'ejecutor')) {
    header("Location: ../index.php");
    exit;
}

$nombre_analista = htmlspecialchars($_SESSION['nombre'] ?? 'Analista');

// 2. Manejo de errores de redirección
if (isset($_GET['error'])) {
    $mensaje = '';
    $icono = 'warning';
    
    if ($_GET['error'] === 'solicitud_cerrada') {
        $estado = $_GET['estado'] ?? 'desconocido';
        $mensaje = "No puedes acceder a esta solicitud porque ya se encuentra en estado: <strong>$estado</strong>.";
    } elseif ($_GET['error'] === 'no_existe') {
        $mensaje = "La solicitud que intentas buscar no existe o fue eliminada.";
    } elseif ($_GET['error'] === 'sin_permiso') {
        $mensaje = "No tienes permisos asignados para procesar la familia de productos de esta solicitud.";
    } elseif ($_GET['error'] === 'no_solicitud') {
        $mensaje = "No se especificó una solicitud válida. Por favor, selecciona una de la lista.";
    }
    
    if ($mensaje) {
        echo "<script>
            document.addEventListener('DOMContentLoaded', () => {
                Swal.fire({
                    icon: '$icono',
                    title: 'Acceso Restringido',
                    html: '$mensaje',
                    confirmButtonColor: '#0056b3'
                });
            });
        </script>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Cargas - Analista</title>
    
    <!-- Fuentes e Iconos -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- CSS Personalizado Moderno -->
    <link rel="stylesheet" href="../assets/css/gestionar_cargas.css">
</head>
<body>

    <!-- ✅ Navbar Moderno Corporativo -->
    <nav class="navbar navbar-dark navbar-custom">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-box-seam me-2"></i>Sistema de Cargas
            </a>
            <div class="d-flex align-items-center gap-3">
                <div class="user-info text-white">
                    <span class="badge badge-conectado d-flex align-items-center gap-1">
                        <span class="dot-pulse"></span>
                        Conectado
                    </span>
                    <span class="analista-nombre">
                        <i class="bi bi-person-circle me-1"></i><?= $nombre_analista ?>
                    </span>
                    <span class="badge bg-light text-primary">ANALISTA</span>
                </div>
                <a href="#" class="btn btn-outline-light btn-sm" onclick="confirmarSalida(event)" title="Cerrar sesión">
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
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="gestionar_cargas.php">
                                <i class="bi bi-clipboard-check"></i> Gestionar Cargas
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-10 ms-sm-auto">
                
                <!-- Page Header -->
                <div class="page-header d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center">
                    <div>
                        <h1 class="h2 mb-1"><i class="bi bi-clipboard-check me-2"></i>Gestión de Cargas</h1>
                        <p class="text-muted mb-0">Administra y procesa las solicitudes de carga de las tiendas</p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
                        <button type="button" class="btn btn-success" onclick="exportarExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Exportar a Excel
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-actualizar" id="btnActualizar" onclick="cargarSolicitudes()">
                            <i class="bi bi-arrow-clockwise me-1"></i> Actualizar
                        </button>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filtros de Búsqueda</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Estado</label>
                                <select class="form-select" id="filtroEstado">
                                    <option value="TODOS">Todos los estados</option>
                                    <option value="PENDIENTE">Pendiente</option>
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
                        <div class="mt-3 d-flex gap-2">
                            <button type="button" class="btn btn-primary" onclick="aplicarFiltros()">
                                <i class="bi bi-search me-1"></i> Aplicar Filtros
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="limpiarFiltros()">
                                <i class="bi bi-x-circle me-1"></i> Limpiar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tabla de Solicitudes CON PAGINACIÓN -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Solicitudes de Carga</h5>
                        
                        <!-- Selector de registros por página -->
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0" style="font-size: 0.85rem;">Mostrar:</label>
                            <select class="form-select form-select-sm" id="selectPorPagina" style="width: auto;" onchange="cambiarPorPagina(this.value)">
                                <option value="25">25</option>
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                                <option value="200">200</option>
                            </select>
                            <span class="text-muted small" id="infoRango"></span>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <!-- Loading State -->
                        <div id="loadingTable" class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                            <p class="mt-3 text-muted fw-medium">Cargando solicitudes...</p>
                        </div>
                        
                        <!-- Tabla -->
                        <div class="table-responsive" id="tablaContainer" style="display: none;">
                            <table class="table table-hover align-middle mb-0" id="tablaSolicitudes">
                                <thead>
                                    <tr>
                                        <th class="text-center">ID</th>
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

                        <!-- Empty State -->
                        <div id="sinRegistros" class="text-center py-5" style="display: none;">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                            <p class="text-muted mt-3 fs-5 fw-medium">No se encontraron solicitudes con los filtros actuales</p>
                            <button class="btn btn-outline-primary btn-sm mt-2" onclick="limpiarFiltros()">Limpiar filtros</button>
                        </div>
                    </div>
                    
                    <!-- ✅ NUEVO: Contenedor de Paginación en el Footer -->
                    <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div id="infoRangoFooter" class="text-muted small"></div>
                        <div id="contenedorPaginacion" class="d-flex gap-1 flex-wrap"></div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
     <script src="../assets/js/global.js"></script>
    <script src="../assets/js/gestion_cargas.js"></script>
    <script src="../assets/js/sku-tooltip.js"></script>
</body>
</html>