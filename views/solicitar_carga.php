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

$nombre_tienda = htmlspecialchars($_SESSION['nombre'] ?? 'Tienda');

// 2. Detectar si estamos en modo edición
$id_edicion = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$titulo_pagina = $id_edicion > 0 ? "Editar Solicitud #$id_edicion" : "Nueva Solicitud de Carga";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $titulo_pagina ?> - Panel Tienda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        #sugerenciasSKU {
            top: 100%; left: 0; margin-top: 2px;
            border: 1px solid rgba(0,0,0,.125); border-radius: 0.375rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            background-color: white; z-index: 1000; max-height: 300px; overflow-y: auto;
        }
        #sugerenciasSKU .list-group-item {
            padding: 0.75rem 1rem; border: none; border-bottom: 1px solid rgba(0,0,0,.05); cursor: pointer;
        }
        #sugerenciasSKU .list-group-item:hover { background-color: #f8f9fa; }
    </style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-dark bg-primary mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard_tienda.php"><i class="bi bi-arrow-left"></i> Volver al Panel</a>
            <span class="text-white">Tienda: <?= $nombre_tienda ?></span>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-2 d-none d-md-block bg-light sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item"><a class="nav-link" href="dashboard_tienda.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link active" href="solicitar_carga.php"><i class="bi bi-cart-plus me-2"></i> Solicitar Carga</a></li>
                        <li class="nav-item"><a class="nav-link" href="mis_solicitudes.php"><i class="bi bi-clipboard-list me-2"></i> Mis Solicitudes</a></li>
                    </ul>
                </div>
            </nav>

            <main class="col-md-10 ms-sm-auto px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
                    <h1 class="h2"><?= $titulo_pagina ?></h1>
                </div>

                <?php if ($id_edicion > 0): ?>
                    <!-- ========================================== -->
                    <!-- MODO EDICIÓN: Solo formulario manual -->
                    <!-- ========================================== -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <!-- El JS inyectará aquí la alerta amarilla de "Modo Edición" -->
                            <form id="formSolicitudManual">
                                <div class="mb-3">
                                    <label for="idFamilia" class="form-label">Familia del Producto <span class="text-danger">*</span></label>
                                    <select class="form-select" id="idFamilia" name="id_familia" required>
                                        <option value="">Cargando familias...</option>
                                    </select>
                                </div>
                                <div class="mb-3 position-relative">
                                    <label for="sku" class="form-label">SKU o Descripción <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="sku" name="sku" required placeholder="Ej: 123456" autocomplete="off">
                                    <div id="sugerenciasSKU" class="list-group position-absolute w-100" style="display: none;"></div>
                                </div>
                                <div class="mb-3">
                                    <label for="descripcion" class="form-label">Descripción del Producto</label>
                                    <input type="text" class="form-control" id="descripcion" name="descripcion" readonly>
                                </div>
                                <div class="mb-3">
                                    <label for="cantidad" class="form-label">Cantidad <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="cantidad" name="cantidad" min="1" required>
                                </div>
                                <div class="mb-3">
                                    <label for="observaciones" class="form-label">Observaciones Generales (Opcional)</label>
                                    <textarea class="form-control" id="observaciones" name="observaciones" rows="3"></textarea>
                                </div>
                                <!-- El JS cambiará el texto y color de este botón automáticamente -->
                                <button type="submit" class="btn btn-primary w-100" id="btnEnviarManual" data-id-editar="<?= $id_edicion ?>">
                                    <i class="bi bi-send me-2"></i>Enviar Solicitud
                                </button>
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
                            <div class="card border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <form id="formSolicitudManual">
                                        <div class="mb-3">
                                            <label for="idFamilia" class="form-label">Familia del Producto <span class="text-danger">*</span></label>
                                            <select class="form-select" id="idFamilia" name="id_familia" required>
                                                <option value="">Cargando familias...</option>
                                            </select>
                                        </div>
                                        <div class="mb-3 position-relative">
                                            <label for="sku" class="form-label">SKU o Descripción <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="sku" name="sku" required placeholder="Ej: 123456" autocomplete="off">
                                            <div id="sugerenciasSKU" class="list-group position-absolute w-100" style="display: none;"></div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="descripcion" class="form-label">Descripción del Producto</label>
                                            <input type="text" class="form-control" id="descripcion" name="descripcion" readonly>
                                        </div>
                                        <div class="mb-3">
                                            <label for="cantidad" class="form-label">Cantidad <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" id="cantidad" name="cantidad" min="1" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="observaciones" class="form-label">Observaciones Generales (Opcional)</label>
                                            <textarea class="form-control" id="observaciones" name="observaciones" rows="3"></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100" id="btnEnviarManual" data-id-editar="0">
                                            <i class="bi bi-send me-2"></i>Enviar Solicitud
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Carga por Archivo -->
                        <div class="tab-pane fade" id="archivo" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-2"></i>
                                        <strong>Formato aceptado:</strong> CSV (.csv)<br>
                                        <strong>Columnas requeridas:</strong> SKU, Cantidad
                                    </div>
                                    <form id="formCargaArchivo" enctype="multipart/form-data">
                                        <div class="mb-3">
                                            <label for="idFamiliaArchivo" class="form-label">Familia del Producto <span class="text-danger">*</span></label>
                                            <select class="form-select" id="idFamiliaArchivo" name="id_familia" required>
                                                <option value="">Cargando familias...</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label for="archivo" class="form-label">Seleccionar Archivo <span class="text-danger">*</span></label>
                                            <input type="file" class="form-control" id="archivo" name="archivo" accept=".csv" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="observacionesArchivo" class="form-label">Observaciones Generales (Opcional)</label>
                                            <textarea class="form-control" id="observacionesArchivo" name="observaciones" rows="3"></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-success w-100" id="btnProcesarArchivo">
                                            <i class="bi bi-upload me-2"></i>Procesar Archivo de Carga
                                        </button>
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
                    <div class="alert alert-info">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/solicitar_carga.js"></script>
</body>
</html>