<?php
session_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// Validar que sea analista
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'analista') {
    header("Location: ../index.php");
    exit;
}

$id_solicitud = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_solicitud <= 0) {
    header("Location: gestionar_cargas.php");
    exit;
}

$nombre_analista = htmlspecialchars($_SESSION['nombre'] ?? 'Analista');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procesar Solicitud #<?= $id_solicitud ?> - Sistema de Cargas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; font-size: 13px; }
        .table th { background: #f8f9fa; font-weight: 600; font-size: 11px; white-space: nowrap; text-align: center; }
        .table td { vertical-align: middle; font-size: 12px; text-align: center; }
        .table td.text-start { text-align: left; }
        .bg-pv { background: #e0f2fe; }
        .bg-inv { background: #fef3c7; }
        .bg-sds { background: #d1fae5; }
        .form-select-sm { font-size: 11px; padding: 0.25rem 0.5rem; }
        .badge-md-default { font-size: 10px; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="gestionar_cargas.php">
            <i class="bi bi-arrow-left"></i> Volver a la Lista
        </a>
        <span class="text-white">Procesar Solicitud #<?= $id_solicitud ?></span>
        <span class="text-white">Analista: <?= $nombre_analista ?></span>
    </div>
</nav>

<div class="container-fluid px-4">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>SKUs de la Solicitud</h5>
                <button class="btn btn-outline-primary btn-sm" onclick="aplicarMDPorDefectoATodos()">
                    <i class="bi bi-magic me-1"></i>Aplicar MD sugerido a todos
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0" id="tablaSKUs">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th class="text-start">PRODUCTO</th>
                            <th colspan="4" class="bg-pv text-dark">PARÁMETROS DE VENTA</th>
                            <th colspan="4" class="bg-inv text-dark">INVENTARIO</th>
                            <th colspan="2" class="bg-sds text-dark">SEMANAS DE STOCK (SDS)</th>
                            <th>CANT. SOLICITADA</th>
                            <th>CANT. FINAL *</th>
                            <th>MD POR DEFECTO</th>
                            <th>MD *</th>
                            <th>NO CARGAR</th>
                            <th>MOTIVO</th>
                        </tr>
                        <tr>
                            <th></th><th></th>
                            <th class="bg-pv">PV6</th><th class="bg-pv">PV3</th><th class="bg-pv">MIN</th><th class="bg-pv">LT</th>
                            <th class="bg-inv">DISP TDA</th><th class="bg-inv">PEND TDA</th><th class="bg-inv">DISP BOD</th><th class="bg-inv">PEND BOD</th>
                            <th class="bg-sds">SDS ACTUAL</th><th class="bg-sds">SDS + CARGA</th>
                            <th></th><th></th><th></th><th></th><th></th><th></th>
                        </tr>
                    </thead>
                    <tbody id="tbodySKUs">
                        <tr>
                            <td colspan="18" class="text-center py-4">
                                <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Cargando...</span></div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <button class="btn btn-secondary" onclick="window.location.href='gestionar_cargas.php'">
                    <i class="bi bi-x-circle me-2"></i>Cancelar
                </button>
                
                <div class="d-flex gap-3">
                    <button type="button" class="btn btn-warning text-dark" onclick="guardarCambios(false)">
                        <i class="bi bi-save me-2"></i>Guardar sin procesar
                    </button>
                    <button type="button" class="btn btn-success btn-lg" onclick="guardarCambios(true)">
                        <i class="bi bi-check-circle me-2"></i>Guardar cambios y procesar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/procesar_carga.js"></script>
</body>
</html>