<?php
session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Validación de seguridad
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'tienda') {
    header("Location: ../index.php");
    exit;
}

// 2. Obtener datos directamente de la sesión
$nombre_tienda_real = htmlspecialchars($_SESSION['nombre_tienda'] ?? "Tienda ID: " . ($_SESSION['id_tienda'] ?? '0'));
$id_tienda = $_SESSION['id_tienda'] ?? 0;
$nombre_usuario = htmlspecialchars($_SESSION['nombre_usuario'] ?? $_SESSION['nombre'] ?? 'Usuario');

// 3. Detectar si estamos en modo edición
$id_edicion = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$titulo_pagina = $id_edicion > 0 ? "Editar Solicitud #$id_edicion" : "Nueva Solicitud de Carga";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $titulo_pagina ?> - Panel Tienda</title>
    
    <!-- Fuente Moderna e Iconos -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- CSS Moderno Unificado -->
    <link rel="stylesheet" href="../assets/css/solicitar_carga.css">
    
    <!-- Variables globales para JS -->
    <script>
        window.NOMBRE_TIENDA = '<?= $nombre_tienda_real ?>';
        window.ID_TIENDA = <?= $id_tienda ?>;
        window.NOMBRE_USUARIO = '<?= $nombre_usuario ?>';
    </script>
</head>
<body>

    <!-- ✅ Navbar Corporativo Moderno -->
    <nav class="navbar navbar-dark navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard_tienda.php">
                <i class="bi bi-arrow-left me-2"></i> Panel Tienda
            </a>
            
            <div class="d-flex align-items-center gap-3">
                <div class="user-info text-white">
                    <span class="badge badge-conectado d-flex align-items-center gap-1">
                        <span class="dot-pulse"></span>
                        Conectado
                    </span>
                    <span class="tienda-nombre">
                        <i class="bi bi-shop me-1"></i><?= $nombre_tienda_real ?>
                    </span>
                    <span class="tienda-id">(ID: <?= $id_tienda ?>)</span>
                    <span class="separator">|</span>
                    <span class="usuario-nombre">
                        <i class="bi bi-person-circle me-1"></i><?= $nombre_usuario ?>
                    </span>
                </div>
                
                <a href="../logout.php" class="btn btn-outline-light btn-sm" title="Cerrar sesión">
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
                            <a class="nav-link" href="dashboard_tienda.php">
                                <i class="bi bi-speedometer2"></i> Panel Principal
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="solicitar_carga.php">
                                <i class="bi bi-cart-plus"></i> Solicitar Carga
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="mis_solicitudes.php">
                                <i class="bi bi-card-checklist"></i> Mis Solicitudes
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-10 ms-sm-auto">
                
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="h2 mb-1"><i class="bi bi-cart-plus me-2"></i><?= $titulo_pagina ?></h1>
                    <p class="text-muted mb-0">Complete los datos para generar una nueva solicitud de carga</p>
                </div>

                <?php if ($id_edicion > 0): ?>
                    <!-- ========================================== -->
                    <!-- MODO EDICIÓN: Solo formulario manual -->
                    <!-- ========================================== -->
                    <div class="card">
                        <div class="card-body p-4">
                            <form id="formSolicitudManual">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="idFamilia" class="form-label">Familia del Producto <span class="text-danger">*</span></label>
                                        <select class="form-select" id="idFamilia" name="id_familia" required>
                                            <option value="">Cargando familias...</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 position-relative">
                                        <label for="sku" class="form-label">SKU o Descripción <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="sku" name="sku" required placeholder="Ej: 123456" autocomplete="off">
                                        <div id="sugerenciasSKU" class="list-group position-absolute w-100" style="display: none; z-index: 1050;"></div>
                                    </div>
                                    <div class="col-md-12">
                                        <label for="descripcion" class="form-label">Descripción del Producto</label>
                                        <input type="text" class="form-control bg-light" id="descripcion" name="descripcion" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="cantidad" class="form-label">Cantidad <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="cantidad" name="cantidad" min="1" required>
                                    </div>
                                    <div class="col-md-12">
                                        <label for="observaciones" class="form-label">Observaciones Generales (Opcional)</label>
                                        <textarea class="form-control" id="observaciones" name="observaciones" rows="3"></textarea>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <button type="button" class="btn btn-warning w-100" id="btnEnviarManual" data-id-editar="<?= $id_edicion ?>" onclick="mostrarPreviewSolicitud()">
                                        <i class="bi bi-eye me-2"></i>Revisar y Actualizar Solicitud
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- ========================================== -->
                    <!-- MODO NORMAL: Pestañas Manual y Archivo -->
                    <!-- ========================================== -->
                    <ul class="nav nav-tabs mb-4" id="myTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="manual-tab" data-bs-toggle="tab" data-bs-target="#manual" type="button" role="tab">
                                <i class="bi bi-keyboard me-2"></i>Ingreso Manual
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="archivo-tab" data-bs-toggle="tab" data-bs-target="#archivo" type="button" role="tab">
                                <i class="bi bi-file-earmark-text me-2"></i>Carga por Archivo
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="myTabContent">
                        <!-- Ingreso Manual -->
                        <div class="tab-pane fade show active" id="manual" role="tabpanel">
                            <div class="card">
                                <div class="card-body p-4">
                                    <form id="formSolicitudManual">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="idFamilia" class="form-label">Familia del Producto <span class="text-danger">*</span></label>
                                                <select class="form-select" id="idFamilia" name="id_familia" required>
                                                    <option value="">Cargando familias...</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 position-relative">
                                                <label for="sku" class="form-label">SKU o Descripción <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="sku" name="sku" required placeholder="Ej: 123456" autocomplete="off">
                                                <div id="sugerenciasSKU" class="list-group position-absolute w-100" style="display: none; z-index: 1050;"></div>
                                            </div>
                                            <div class="col-md-12">
                                                <label for="descripcion" class="form-label">Descripción del Producto</label>
                                                <input type="text" class="form-control bg-light" id="descripcion" name="descripcion" readonly>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="cantidad" class="form-label">Cantidad <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control" id="cantidad" name="cantidad" min="1" required>
                                            </div>
                                            <div class="col-md-12">
                                                <label for="observaciones" class="form-label">Observaciones Generales (Opcional)</label>
                                                <textarea class="form-control" id="observaciones" name="observaciones" rows="3"></textarea>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-4">
                                            <button type="button" class="btn btn-primary w-100" id="btnEnviarManual" data-id-editar="0" onclick="mostrarPreviewSolicitud()">
                                                <i class="bi bi-eye me-2"></i>Revisar y Enviar Solicitud
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Carga por Archivo -->
                        <div class="tab-pane fade" id="archivo" role="tabpanel">
                            <div class="card">
                                <div class="card-body p-4">
                                    <div class="alert alert-info border-0">
                                        <i class="bi bi-info-circle me-2"></i>
                                        <strong>Formato aceptado:</strong> CSV (.csv)<br>
                                        <strong>Columnas requeridas:</strong> SKU, Cantidad
                                    </div>
                                    <form id="formCargaArchivo" enctype="multipart/form-data">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="idFamiliaArchivo" class="form-label">Familia del Producto <span class="text-danger">*</span></label>
                                                <select class="form-select" id="idFamiliaArchivo" name="id_familia" required>
                                                    <option value="">Cargando familias...</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="archivo" class="form-label">Seleccionar Archivo <span class="text-danger">*</span></label>
                                                <input type="file" class="form-control" id="archivo" name="archivo" accept=".csv" required>
                                            </div>
                                            <div class="col-md-12">
                                                <label for="observacionesArchivo" class="form-label">Observaciones Generales (Opcional)</label>
                                                <textarea class="form-control" id="observacionesArchivo" name="observaciones" rows="3"></textarea>
                                            </div>
                                        </div>
                                        <div class="mt-4">
                                            <button type="submit" class="btn btn-success w-100" id="btnProcesarArchivo">
                                                <i class="bi bi-upload me-2"></i>Procesar Archivo de Carga
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Modal de Resumen (Solo para carga por archivo) -->
    <div class="modal fade" id="modalResumenProcesamiento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Resumen de Procesamiento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info border-0">
                        <i class="bi bi-info-circle me-2"></i><strong>Total procesados:</strong> <span id="totalProcesados">0</span>
                    </div>
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#aprobados" type="button">
                                <i class="bi bi-check-circle text-success me-1"></i>Aprobados <span class="badge bg-success" id="countAprobados">0</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#rechazados" type="button">
                                <i class="bi bi-x-circle text-danger me-1"></i>Rechazados <span class="badge bg-danger" id="countRechazados">0</span>
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="aprobados">
                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                <table class="table table-sm table-hover">
                                    <thead class="table-success"><tr><th>SKU</th><th>Descripción</th><th class="text-end">Cantidad</th></tr></thead>
                                    <tbody id="tbodyAprobados"></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="rechazados">
                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                <table class="table table-sm table-hover">
                                    <thead class="table-danger"><tr><th>SKU</th><th>Descripción</th><th class="text-end">Cantidad</th><th>Motivo</th></tr></thead>
                                    <tbody id="tbodyRechazados"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" onclick="window.location.href='mis_solicitudes.php'"><i class="bi bi-eye me-2"></i>Ver Mis Solicitudes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmación (Ingreso Manual) -->
    <div class="modal fade" id="modalConfirmacion" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-check-circle me-2"></i>Confirmar Solicitud de Carga
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info border-0">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Revisa la información antes de enviar:</strong>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted text-uppercase fw-bold">📦 Familia:</small>
                            <p id="confirm_familia" class="fs-6 mb-0"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted text-uppercase fw-bold">🏪 Tienda:</small>
                            <p id="confirm_tienda" class="fs-6 mb-0"></p>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h6 class="fw-bold mb-3"><i class="bi bi-box-seam me-2"></i>Detalle del Producto</h6>
                    
                    <div class="table-responsive mb-3">
                        <table class="table table-bordered table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 8%;">SKU</th>
                                    <th style="width: 20%;">Producto</th>
                                    <th class="text-center bg-info" style="width: 5%;">v6</th>
                                    <th class="text-center bg-info" style="width: 5%;">v5</th>
                                    <th class="text-center bg-info" style="width: 5%;">v4</th>
                                    <th class="text-center bg-info" style="width: 5%;">v3</th>
                                    <th class="text-center bg-info" style="width: 5%;">v2</th>
                                    <th class="text-center bg-info" style="width: 5%;">v1</th>
                                    <th class="text-center" style="width: 5%;">PV6</th>
                                    <th class="text-center" style="width: 5%;">PV3</th>
                                    <th class="text-center" style="width: 5%;">Cap.</th>
                                    <th class="text-center" style="width: 5%;">LT</th>
                                    <th class="text-center" style="width: 6%;">Disp</th>
                                    <th class="text-center" style="width: 6%;">Pend</th>
                                    <th class="text-center" style="width: 5%;">SDS</th>
                                    <th class="text-center" style="width: 6%;">Cant.</th>
                                    <th class="text-center" style="width: 8%;">Pallets</th>
                                    <th class="text-center" style="width: 7%;">MD</th>
                                </tr>
                            </thead>
                            <tbody id="confirm_tabla_detalle">
                                <!-- Se llena dinámicamente con JS -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div id="confirm_observaciones_container" style="display: none;">
                        <hr>
                        <h6 class="fw-bold mb-2"><i class="bi bi-chat-left-text me-2"></i>Observaciones</h6>
                        <div class="bg-light p-3 rounded border">
                            <p id="confirm_observaciones" class="mb-0 text-muted"></p>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning mt-3 mb-0 border-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Importante:</strong> Al confirmar, la solicitud será evaluada con el Árbol de Decisión y podrá ser aprobada o rechazada según los parámetros.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-pencil me-1"></i>Corregir
                    </button>
                    <button type="button" class="btn btn-success btn-lg px-4" onclick="confirmarYEnviarSolicitud()">
                        <i class="bi bi-send-fill me-2"></i>Confirmar y Enviar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/solicitar_carga.js"></script>
</body>
</html>