// assets/js/mis_solicitudes.js

document.addEventListener('DOMContentLoaded', () => {
    console.log("✅ mis_solicitudes.js cargado correctamente");
    cargarSolicitudes();
});

// ==========================================
// Cargar Solicitudes del Usuario
// ==========================================
async function cargarSolicitudes(urlPersonalizada = null) {
    const tbody = document.getElementById('tbodySolicitudes');
    const sinRegistros = document.getElementById('sinRegistros');
    
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><div class="spinner-border text-primary"></div></td></tr>';
    sinRegistros.style.display = 'none';

    try {
        const url = urlPersonalizada || '../api/obtener_mis_solicitudes.php'; 
        const response = await fetch(url);
        const data = await response.json();

        if (!data.success) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error al cargar datos</td></tr>';
            return;
        }

        // Actualizar contadores
        if (data.contadores) {
            document.getElementById('countPendientes').textContent = data.contadores.PENDIENTE || 0;
            document.getElementById('countAprobadas').textContent = data.contadores.APROBADA || 0;
            document.getElementById('countProceso').textContent = data.contadores.EN_PROCESO || 0;
            document.getElementById('countProcesadas').textContent = data.contadores.PROCESADA || 0;
            document.getElementById('countRechazadas').textContent = data.contadores.RECHAZADA || 0;
        }

        const solicitudes = data.solicitudes || [];
        tbody.innerHTML = '';

        console.log(`📦 Total solicitudes recibidas: ${solicitudes.length}`);

        if (solicitudes.length === 0) {
            sinRegistros.style.display = 'block';
        } else {
            solicitudes.forEach((sol, index) => {
                const tr = document.createElement('tr');
                
                // ✅ Manejo robusto del estado (probando diferentes campos)
                let estadoTexto = '';
                
                // Intentar obtener el estado de diferentes posibles campos
                if (sol.estado_general) {
                    estadoTexto = sol.estado_general;
                } else if (sol.estado_actual) {
                    estadoTexto = sol.estado_actual;
                } else if (sol.estado_solicitud) {
                    estadoTexto = sol.estado_solicitud;
                } else if (sol.estado) {
                    estadoTexto = sol.estado;
                } else {
                    estadoTexto = 'PENDIENTE'; // Valor por defecto
                }
                
                // Limpiar el estado (trim y mayúsculas)
                estadoTexto = estadoTexto.trim().toUpperCase();
                
                console.log(`Solicitud #${sol.id_solicitud}: Estado = [${estadoTexto}]`);

                // Determinar clase del badge según estado
                let badgeClass = 'bg-secondary';
                switch(estadoTexto) {
                    case 'PENDIENTE': 
                        badgeClass = 'bg-secondary'; 
                        break;
                    case 'APROBADA': 
                    case 'APROBADO':
                        badgeClass = 'bg-info text-dark'; 
                        break;
                    case 'EN_PROCESO': 
                    case 'EN PROCESO':
                        badgeClass = 'bg-warning text-dark'; 
                        break;
                    case 'PROCESADA': 
                    case 'PROCESADO':
                        badgeClass = 'bg-success'; 
                        break;
                    case 'PROCESADA_PARCIAL':
                        badgeClass = 'bg-success'; 
                        break;
                    case 'RECHAZADA': 
                    case 'RECHAZADO':
                        badgeClass = 'bg-danger'; 
                        break;
                    default:
                        badgeClass = 'bg-secondary';
                }

                const badgeSkus = `<span class="badge bg-light text-dark border">${sol.total_skus || 0} SKU(s)</span>`;
                const fechaFormateada = sol.fecha_solicitud || '-';

                // ✅ LÓGICA DE BOTONES DE ACCIÓN (con Ver Detalle para TODOS los estados)
                               let btnAccion = '';

                if (estadoTexto === 'PENDIENTE') {
                    btnAccion = `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-info" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle completo">
                                <i class="bi bi-eye"></i>
                            </button>
                            <a href="solicitar_carga.php?editar=${sol.id_solicitud}" class="btn btn-warning text-dark" title="Modificar solicitud">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button class="btn btn-danger" onclick="eliminarSolicitudUsuario(${sol.id_solicitud})" title="Eliminar solicitud">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    `;
                } else if (estadoTexto === 'EN_PROCESO' || estadoTexto === 'EN PROCESO') {
                    btnAccion = `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-info" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-warning text-dark" disabled>
                                <i class="bi bi-hourglass-split"></i> En Proceso
                            </button>
                        </div>
                    `;
                } else if (estadoTexto === 'RECHAZADA' || estadoTexto === 'RECHAZADO') {
                    btnAccion = `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-info" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver motivo de rechazo">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-secondary" disabled>
                                <i class="bi bi-x-circle"></i> Rechazada
                            </button>
                        </div>
                    `;
                } else {
                    // Para PROCESADA, PROCESADA_PARCIAL, APROBADA
                    btnAccion = `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-info" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle de lo procesado">
                                <i class="bi bi-eye"></i> 
                            </button>
                            <button class="btn btn-success" disabled>
                                <i class="bi bi-check-circle"></i> Procesada
                            </button>
                        </div>
                    `;
                }
                                tr.innerHTML = `
                    <td><strong>#${sol.id_solicitud}</strong></td>
                    <td class="text-center">${badgeSkus}</td>
                    <td class="text-center fw-bold">${sol.total_cantidad_solicitada || 0}</td>
                    <td class="text-center"><span class="badge ${badgeClass}">${estadoTexto}</span></td>
                    <td><small>${fechaFormateada}</small></td>
                    <td><small class="text-muted">${sol.observaciones || '-'}</small></td>
                    <td class="text-center">${btnAccion}</td>
                `;
                tbody.appendChild(tr);
            });
        }

    } catch (error) {
        console.error('Error:', error);
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error de conexión</td></tr>';
    }
}

// ==========================================
// Filtros
// ==========================================
function aplicarFiltros() {
    const estado = document.getElementById('filtroEstado').value;
    const md = document.getElementById('filtroMD').value;
    const fechaDesde = document.getElementById('fechaDesde').value;
    const fechaHasta = document.getElementById('fechaHasta').value;
    
    let url = '../api/obtener_mis_solicitudes.php?';
    if (estado !== 'TODOS') url += `estado=${encodeURIComponent(estado)}&`;
    if (md !== 'TODOS') url += `md=${encodeURIComponent(md)}&`;
    if (fechaDesde) url += `fecha_desde=${encodeURIComponent(fechaDesde)}&`;
    if (fechaHasta) url += `fecha_hasta=${encodeURIComponent(fechaHasta)}&`;
    
    cargarSolicitudes(url);
}

function limpiarFiltros() {
    document.getElementById('filtroEstado').value = 'TODOS';
    document.getElementById('filtroMD').value = 'TODOS';
    document.getElementById('fechaDesde').value = '';
    document.getElementById('fechaHasta').value = '';
    cargarSolicitudes();
}

// ==========================================
// Eliminar Solicitud (Usuario Tienda)
// ==========================================
async function eliminarSolicitudUsuario(idSolicitud) {
    const result = await Swal.fire({
        title: '¿Eliminar esta solicitud?',
        html: `
            <p>Esta acción <strong>no se puede deshacer</strong>.</p>
            <p class="text-muted small">Solo puedes eliminar solicitudes en estado <strong>PENDIENTE</strong>.</p>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    });

    if (!result.isConfirmed) return;

    Swal.fire({
        title: 'Eliminando...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    try {
        const response = await fetch('../api/eliminar_solicitud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_solicitud: idSolicitud })
        });
        
        const texto = await response.text();
        let data;
        try {
            data = JSON.parse(texto);
        } catch (e) {
            throw new Error("Respuesta inválida del servidor");
        }

        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Solicitud Eliminada',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                cargarSolicitudes();
            });
        } else {
            Swal.fire('Error', data.message || 'No se pudo eliminar la solicitud', 'error');
        }
    } catch (error) {
        console.error('Error al eliminar:', error);
        Swal.fire('Error', 'No se pudo conectar con el servidor', 'error');
    }
}

// ==========================================
// Ver Detalle Completo de Solicitud (RECUPERADA)
// ==========================================
async function verDetalleSolicitud(idSolicitud) {
    Swal.fire({
        title: `Cargando detalle #${idSolicitud}...`,
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    
    try {
        const response = await fetch(`../api/obtener_detalle_completo.php?id=${idSolicitud}`);
        const data = await response.json();
        
        Swal.close();
        
        if (!data.success) {
            Swal.fire('Error', data.message, 'error');
            return;
        }
        
        if (!data.skus || data.skus.length === 0) {
            Swal.fire('Información', 'No hay detalles para esta solicitud', 'info');
            return;
        }
        
        // ✅ Construir HTML completo con todos los datos
        let html = `
            <div class="text-start mb-3 p-3 bg-light rounded">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>📋 Solicitud:</strong> #${idSolicitud}</p>
                        <p class="mb-1"><strong>📅 Fecha:</strong> ${data.fecha_solicitud ? new Date(data.fecha_solicitud).toLocaleString('es-CL') : '-'}</p>
                        <p class="mb-1"><strong>📦 Total SKUs:</strong> ${data.skus.length}</p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>📊 Estado General:</strong> <span class="badge ${getBadgeClass(data.estado_general)}">${data.estado_general || 'PENDIENTE'}</span></p>
                        ${data.observaciones_generales ? `<p class="mb-0"><strong>📝 Observaciones:</strong> ${data.observaciones_generales}</p>` : ''}
                    </div>
                </div>
            </div>
            
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-sm table-hover table-bordered" style="font-size: 11px;">
                    <thead class="table-dark sticky-top">
                        <tr>
                            <th rowspan="2">SKU</th>
                            <th rowspan="2">Producto</th>
                            <th colspan="6" class="text-center bg-info">VENTAS (Últimas 6 Sem)</th>
                            <th rowspan="2">PV6</th>
                            <th rowspan="2">PV3</th>
                            <th rowspan="2">Cap.</th>
                            <th rowspan="2">LT</th>
                            <th rowspan="2">Disp TDA</th>
                            <th rowspan="2">Pend TDA</th>
                            <th rowspan="2">SDS Actual</th>
                            <th rowspan="2">Cant. Sol.</th>
                            <th rowspan="2">Cant. Final</th>
                            <th rowspan="2">MD</th>
                            <th rowspan="2">Estado</th>
                            <th rowspan="2">Motivo</th>
                        </tr>
                        <tr class="bg-info">
                            <th>v6</th><th>v5</th><th>v4</th><th>v3</th><th>v2</th><th>v1</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        data.skus.forEach(sku => {
            const estadoBadge = getBadgeClass(sku.estado_item);
            const sdsActual = parseFloat(sku.sds_actual) || 0;
            const sdsCarga = parseFloat(sku.sds_carga) || 0;
            
            // Resaltar SDS altas en rojo
            const sdsClass = sdsActual > 20 ? 'text-danger fw-bold' : '';
            
            html += `
                <tr>
                    <td><strong>${sku.sku}</strong></td>
                    <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;" title="${sku.descripcion_producto || ''}">${sku.descripcion_producto || '-'}</td>
                    
                    <!-- Ventas 6 semanas -->
                    <td>${sku.v6 || 0}</td>
                    <td>${sku.v5 || 0}</td>
                    <td>${sku.v4 || 0}</td>
                    <td>${sku.v3 || 0}</td>
                    <td>${sku.v2 || 0}</td>
                    <td>${sku.v1 || 0}</td>
                    
                    <!-- Parámetros -->
                    <td>${sku.pv6 || 0}</td>
                    <td>${sku.pv3 || 0}</td>
                    <td>${sku.capacity || 0}</td>
                    <td>${sku.lt || 0}</td>
                    
                    <!-- Inventario -->
                    <td>${sku.disp_tda || 0}</td>
                    <td>${sku.pend_tda || 0}</td>
                    
                    <!-- SDS -->
                    <td class="${sdsClass}">${sdsActual}</td>
                    
                    <!-- Cantidades -->
                    <td><strong>${sku.carga_solicitada}</strong></td>
                    <td><strong>${sku.carga_final || '-'}</strong></td>
                    
                    <!-- MD -->
                    <td>${sku.md || sku.md_defecto_sku || '-'}</td>
                    
                    <!-- Estado -->
                    <td><span class="badge ${estadoBadge}">${sku.estado_item || 'PENDIENTE'}</span></td>
                    
                    <!-- Motivo -->
                    <td style="max-width: 200px;" title="${sku.campo_cambios || ''}">
                        <small>${sku.campo_cambios || '-'}</small>
                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
            
            <div class="alert alert-info mt-3 mb-0">
                <small>
                    <strong>💡 Leyenda:</strong><br>
                    • <strong>PV6/PV3:</strong> Promedio de ventas últimas 6/3 semanas<br>
                    • <strong>SDS:</strong> Semanas de Stock (si es muy alta, no se carga)<br>
                    • <strong>Disp TDA:</strong> Disponible en tienda | <strong>Pend TDA:</strong> Pendiente en tienda<br>
                    • <strong>LT:</strong> Lead Time (tiempo de reposición)
                </small>
            </div>
        `;
        
        // Mostrar modal completo
        Swal.fire({
            title: ` Detalle Solicitud #${idSolicitud}`,
            html: html,
            width: '95%',
            confirmButtonText: 'Cerrar',
            confirmButtonColor: '#0d6efd',
            showCancelButton: false
        });
        
    } catch (error) {
        console.error('Error cargando detalle:', error);
        Swal.fire('Error', 'No se pudo cargar el detalle', 'error');
    }
}

// ==========================================
// Helper: Clase de badge según estado
// ==========================================
function getBadgeClass(estado) {
    if (!estado) return 'bg-secondary';
    
    const estadoUpper = estado.toUpperCase();
    
    switch(estadoUpper) {
        case 'PENDIENTE':
        case 'EN_PROCESO':
            return 'bg-secondary';
        case 'APROBADA':
        case 'APROBADO':
            return 'bg-info text-dark';
        case 'PROCESADA':
        case 'PROCESADA_PARCIAL':
            return 'bg-success';
        case 'RECHAZADA':
        case 'RECHAZADO':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}