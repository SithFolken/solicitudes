<?php
session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Validar rol
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'analista') {
    header("Location: ../index.php");
    exit;
}

// 2. Obtener el ID de forma segura (primero de GET, luego de sesión)
$id_solicitud = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_SESSION['solicitud_activa']) ? (int)$_SESSION['solicitud_activa'] : 0);

// 3. Si no hay ID, redirigir con error
if ($id_solicitud <= 0) {
    error_log("ERROR: No hay ID válido. GET: " . print_r($_GET, true) . " | SESSION: " . print_r($_SESSION, true));
    header("Location: gestionar_cargas.php?error=no_solicitud");
    exit;
}

require_once '../config/database.php';

try {
    // 4. VALIDACIÓN CRÍTICA: Verificar que la solicitud exista
    $sql_validacion = "SELECT estado_general, id_familia FROM Analisis_Procesos.solicitudes WHERE id_solicitud = :id_solicitud";
    $stmt_val = $pdo->prepare($sql_validacion);
    $stmt_val->execute(['id_solicitud' => $id_solicitud]);
    $info_solicitud = $stmt_val->fetch(PDO::FETCH_ASSOC);

    if (!$info_solicitud) {
        unset($_SESSION['solicitud_activa']);
        header("Location: gestionar_cargas.php?error=no_existe");
        exit;
    }

    // 5. Bloquear si ya fue procesada
    $estado = $info_solicitud['estado_general'];
    if (in_array($estado, ['PROCESADA', 'PROCESADA_PARCIAL', 'RECHAZADA'])) {
        unset($_SESSION['solicitud_activa']);
        header("Location: gestionar_cargas.php?error=solicitud_cerrada&estado=" . urlencode($estado));
        exit;
    }

    $nombre_analista = htmlspecialchars($_SESSION['nombre'] ?? 'Analista');

} catch (PDOException $e) {
    error_log("Error validando solicitud: " . $e->getMessage());
    header("Location: gestionar_cargas.php?error=database");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procesar Solicitud #<?= $id_solicitud ?> - Sistema de Cargas</title>
    
    <!-- Fuente Moderna e Iconos -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- CSS Moderno Unificado -->
    <link rel="stylesheet" href="../assets/css/procesar_carga.css">
</head>
<body>

    <!-- Navbar Moderno Corporativo -->
    <nav class="navbar navbar-dark navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="gestionar_cargas.php">
                <i class="bi bi-arrow-left me-2"></i> Volver a la Lista
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
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>SKUs de la Solicitud #<?= $id_solicitud ?></h5>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-danger btn-sm" onclick="rechazarTodaSolicitud()">
                            <i class="bi bi-x-circle me-1"></i>Rechazar toda la solicitud
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaSKUs">
                        <thead>
                            <tr>
                                <th rowspan="2">SKU</th>
                                <th rowspan="2">TIENDA</th>
                                <th rowspan="2" class="text-start">PRODUCTO</th>
                                <th colspan="4" class="bg-pv">PARÁMETROS</th>
                                <th colspan="4" class="bg-inv">INVENTARIO</th>
                                <th colspan="6" class="bg-ventas">VENTAS SEMANALES</th>
                                <th colspan="2" class="bg-sds">SDS</th>
                                <th rowspan="2">CANT. SOL.</th>
                                <th rowspan="2">CANT. FIN *</th>
                                <th rowspan="2">PALLETS</th>
                                <th rowspan="2">MD DEFECTO</th>
                                <th rowspan="2">MD *</th>
                                <th rowspan="2">NO CARGAR</th>
                                <th rowspan="2">MOTIVO</th>
                            </tr>
                            <tr>
                                <th class="bg-pv">PV6</th>
                                <th class="bg-pv">PV3</th>
                                <th class="bg-pv">MIN</th>
                                <th class="bg-pv">LT</th>
                                <th class="bg-inv">DISP TDA</th>
                                <th class="bg-inv">PEND TDA</th>
                                <th class="bg-inv">DISP BOD</th>
                                <th class="bg-inv">PEND BOD</th>
                                <th class="bg-ventas">V6</th>
                                <th class="bg-ventas">V5</th>
                                <th class="bg-ventas">V4</th>
                                <th class="bg-ventas">V3</th>
                                <th class="bg-ventas">V2</th>
                                <th class="bg-ventas">V1</th>
                                <th class="bg-sds">ACTUAL</th>
                                <th class="bg-sds">+CARGA</th>
                            </tr>
                        </thead>
                        <tbody id="tbodySKUs">
                            <tr>
                                <td colspan="24" class="text-center py-5">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Cargando...</span>
                                    </div>
                                    <p class="mt-3 text-muted fw-medium">Cargando detalles de la solicitud...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card-footer bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <button class="btn btn-secondary" onclick="limpiarYVolver()">
                        <i class="bi bi-x-circle me-2"></i>Cancelar
                    </button>
                    <div class="d-flex gap-3">
                        <button type="button" class="btn btn-warning" onclick="guardarCambios(false)">
                            <i class="bi bi-save me-2"></i>Guardar sin procesar
                        </button>
                        <button type="button" class="btn btn-success btn-lg" onclick="guardarCambios(true)">
                            <i class="bi bi-check-circle me-2"></i>Guardar y procesar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
     <script src="../assets/js/global.js"></script>
    <script src="../assets/js/procesar_carga.js"></script>
    <script src="../assets/js/sku-tooltip.js"></script>
</body>
</html>