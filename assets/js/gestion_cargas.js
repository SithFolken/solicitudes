// assets/js/gestion_cargas.js

document.addEventListener('DOMContentLoaded', () => {
    // Verificar si viene de una actualización
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('actualizado') === '1') {
        window.history.replaceState({}, document.title, window.location.pathname);
        location.reload();
    } else {
        cargarSolicitudes();
    }
});

// ==========================================
// Cargar Solicitudes en la Tabla
// ==========================================
async function cargarSolicitudes(urlPersonalizada = null) {
    const loading = document.getElementById('loadingTable');
    const tablaContainer = document.getElementById('tablaContainer');
    const sinRegistros = document.getElementById('sinRegistros');
    const btnActualizar = document.getElementById('btnActualizar');
    const icono = btnActualizar ? btnActualizar.querySelector('i') : null;
    
    loading.style.display = 'block';
    tablaContainer.style.display = 'none';
    sinRegistros.style.display = 'none';
    
    if (btnActualizar) {
        btnActualizar.disabled = true;
        if (icono) icono.classList.add('fa-spin');
    }

    try {
        const url = urlPersonalizada || '../api/obtener_solicitudes.php';
        const response = await fetch(url);
        const data = await response.json();

        loading.style.display = 'none';
        
        if (btnActualizar) {
            btnActualizar.disabled = false;
            if (icono) icono.classList.remove('fa-spin');
        }

        if (!data.success) {
            Swal.fire('Error', data.message, 'error');
            return;
        }

        const tbody = document.getElementById('tbodySolicitudes');
        tbody.innerHTML = '';

        if (data.solicitudes.length === 0) {
            sinRegistros.style.display = 'block';
        } else {
            tablaContainer.style.display = 'block';

            data.solicitudes.forEach(sol => {
                const tr = document.createElement('tr');
                
                let badgeClass = 'bg-secondary';
                let estadoTexto = sol.estado_actual || sol.estado_solicitud || 'PENDIENTE';
                
                switch(estadoTexto.toUpperCase()) {
                    case 'PENDIENTE': badgeClass = 'bg-secondary'; break;
                    case 'APROBADA': badgeClass = 'bg-info text-dark'; break;
                    case 'EN_PROCESO': badgeClass = 'bg-warning text-dark'; break;
                    case 'PROCESADA': badgeClass = 'bg-success'; break;
                    case 'PROCESADA_PARCIAL': badgeClass = 'bg-success'; break;
                    case 'RECHAZADA': badgeClass = 'bg-danger'; break;
                }

                const badgeSkus = `<span class="badge bg-light text-dark border">${sol.total_skus || 0} SKU(s)</span>`;
                const nombreTienda = sol.nombre_tienda || 'N/A';
                const idTienda = sol.id_tienda ? `(ID: ${sol.id_tienda})` : '';
                const fechaFormateada = sol.fecha_solicitud || '-';

                // ✅ Badge de Excel (solo si ya fue generado)
                let badgeExcel = '';
                if (sol.fecha_generacion_excel) {
                    badgeExcel = `<span class="badge bg-success ms-2" title="Incluido en Excel el ${sol.fecha_generacion_excel}"><i class="bi bi-check-circle me-1"></i>Excel</span>`;
                }

               // ✅ Botones de acción según estado (SIMPLIFICADO)
                let btnAccion = '';

                if (estadoTexto === 'PENDIENTE') {
                    // Solo botón PROCESAR (que lleva a editar y procesar)
                    btnAccion = `
                        <button class="btn btn-primary btn-sm" onclick="confirmarProcesamiento(${sol.id_solicitud}, '${nombreTienda}', ${sol.total_skus || 0})" title="Procesar solicitud">
                            <i class="bi bi-gear"></i> Procesar
                        </button>
                    `;
                } else if (estadoTexto === 'EN_PROCESO' || estadoTexto === 'EN PROCESO') {
                    btnAccion = `
                        <button class="btn btn-warning btn-sm text-dark" onclick="window.location.href='procesar_carga.php?id=${sol.id_solicitud}'" title="Continuar procesamiento">
                            <i class="bi bi-hourglass-split"></i> Continuar Procesando
                        </button>
                    `;
                } else if (estadoTexto === 'RECHAZADA' || estadoTexto === 'RECHAZADO') {
                    // Ver Detalle + Badge Rechazada
                    btnAccion = `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-info" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-secondary" disabled>
                                <i class="bi bi-x-circle"></i> Rechazada
                            </button>
                        </div>
                    `;
                } else {
                    // PROCESADA o PROCESADA_PARCIAL: Ver Detalle + Badge
                    btnAccion = `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-info" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle completo">
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
                    <td>
                        ${nombreTienda}<br>
                        <small class="text-muted">${idTienda}</small>
                    </td>
                    <td class="text-center">${badgeSkus}</td>
                    <td class="text-center fw-bold">${sol.total_cantidad_solicitada || 0}</td>
                    <td class="text-center">
                        <span class="badge ${badgeClass}">${estadoTexto}</span>
                        ${badgeExcel}
                    </td>
                    <td><small>${fechaFormateada}</small></td>
                    <td class="text-center">
                        ${btnAccion}
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

    } catch (error) {
        console.error('Error:', error);
        loading.style.display = 'none';
        
        if (btnActualizar) {
            btnActualizar.disabled = false;
            if (icono) icono.classList.remove('fa-spin');
        }
        
        Swal.fire('Error', 'No se pudieron cargar las solicitudes', 'error');
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
    
    let url = '../api/obtener_solicitudes.php?';
    
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
// Exportar Excel del Ciclo
// ==========================================
function exportarExcel() {
    const hoy = new Date().toISOString().split('T')[0];
    const horaActual = new Date().getHours();
    
    let cicloCorte = hoy;
    if (horaActual >= 13) {
        const manana = new Date();
        manana.setDate(manana.getDate() + 1);
        cicloCorte = manana.toISOString().split('T')[0];
    }
    
    Swal.fire({
        title: '¿Generar Excel del Ciclo?',
        html: `
            <p>Se generará el archivo Excel con todas las solicitudes procesadas del ciclo:</p>
            <div class="alert alert-info text-start">
                <strong>📅 Ciclo de Corte:</strong> ${cicloCorte}<br>
                <strong>⏰ Hora actual:</strong> ${new Date().toLocaleTimeString('es-CL', {hour: '2-digit', minute:'2-digit'})} hrs<br>
                <strong>📋 Estado:</strong> ${horaActual >= 13 ? 'Ciclo de MAÑANA' : 'Ciclo de HOY'}
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, generar Excel',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Verificando datos...',
                text: 'Buscando solicitudes procesadas en este ciclo',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            fetch(`../api/generar_excel_carga.php?ciclo=${cicloCorte}&validar=1`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.tiene_datos) {
                        Swal.close();
                        window.open(`../api/generar_excel_carga.php?ciclo=${cicloCorte}`, '_blank');
                        
                        Swal.fire({
                            icon: 'success',
                            title: '¡Excel Generado!',
                            text: 'Las solicitudes procesadas han sido marcadas como incluidas en Excel.',
                            timer: 3000,
                            showConfirmButton: false
                        }).then(() => {
                            cargarSolicitudes();
                        });
                    } else {
                        Swal.close();
                        Swal.fire({
                            icon: 'warning',
                            title: 'Sin datos para exportar',
                            html: data.message || 'No se encontraron solicitudes procesadas para este ciclo.',
                            confirmButtonText: 'Entendido',
                            confirmButtonColor: '#ffc107'
                        });
                    }
                })
                .catch(error => {
                    Swal.close();
                    Swal.fire('Error', 'No se pudo conectar con el servidor', 'error');
                });
        }
    });
}

// ==========================================
// Eliminar Solicitud
// ==========================================
async function eliminarSolicitud(idSolicitud) {
    const result = await Swal.fire({
        title: '¿Eliminar solicitud?',
        text: "Esta acción no se puede deshacer. Solo puedes eliminar solicitudes en estado PENDIENTE.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    });

    if (!result.isConfirmed) return;

    try {
        const response = await fetch('../api/eliminar_solicitud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_solicitud: idSolicitud })
        });
        const data = await response.json();

        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Eliminada',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                cargarSolicitudes();
            });
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    } catch (error) {
        Swal.fire('Error', 'No se pudo conectar con el servidor', 'error');
    }
}

// ==========================================
// Editar Solicitud
// ==========================================
function editarSolicitud(idSolicitud) {
    window.location.href = `solicitar_carga.php?editar=${idSolicitud}`;
}

// ==========================================
// Confirmar antes de entrar a procesar
// ==========================================
function confirmarProcesamiento(idSolicitud, nombreTienda, totalSkus) {
    Swal.fire({
        title: '¿Iniciar procesamiento?',
        html: `
            <div class="text-start">
                <p>Estás a punto de procesar la solicitud <strong>#${idSolicitud}</strong> de la tienda <strong>${nombreTienda}</strong>.</p>
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Atención:</strong>
                    <ul class="mb-0 mt-2">
                        <li>La solicitud pasará de estado <strong>PENDIENTE</strong> a <strong>EN_PROCESO</strong>.</li>
                        <li>Contiene <strong>${totalSkus} SKU(s)</strong> que serán evaluados.</li>
                        <li>Una vez en proceso, <strong>el usuario de la tienda ya no podrá modificarla</strong>.</li>
                    </ul>
                </div>
                <p class="mt-3 text-muted small">Podrás guardar los cambios sin procesar si necesitas revisar algo más tarde.</p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, iniciar procesamiento',
        cancelButtonText: 'Cancelar',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            cambiarEstadoAEnProceso(idSolicitud).then((success) => {
                if (success) {
                    window.location.href = `procesar_carga.php?id=${idSolicitud}`;
                }
            });
        }
    });
}

// ==========================================
// Cambiar estado a EN_PROCESO (llamada AJAX)
// ==========================================
// ==========================================
// Cambiar estado a EN_PROCESO (llamada AJAX)
// ==========================================
async function cambiarEstadoAEnProceso(idSolicitud) {
    Swal.fire({
        title: 'Cambiando estado...',
        text: 'Marcando solicitud como EN_PROCESO',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    
    try {
        // ✅ Asegúrate de que el nombre del archivo aquí sea el que tú tienes
        const response = await fetch('../api/cambiar_estado.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                id_solicitud: idSolicitud,
                estado: 'EN_PROCESO'  // ✅ Coincide con lo que espera tu PHP
            })
        });
        
        const data = await response.json();
        Swal.close();
        
        if (!data.success) {
            Swal.fire('Error', data.message || 'No se pudo cambiar el estado', 'error');
            return false;
        }
        
        return true;
        
    } catch (error) {
        console.error('Error:', error);
        Swal.close();
        Swal.fire('Error', 'No se pudo conectar con el servidor', 'error');
        return false;
    }
}

// ==========================================
// Ver Detalle Completo de Solicitud
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
            const sdsClass = sdsActual > 20 ? 'text-danger fw-bold' : '';
            
            html += `
                <tr>
                    <td><strong>${sku.sku}</strong></td>
                    <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;" title="${sku.descripcion_producto || ''}">${sku.descripcion_producto || '-'}</td>
                    <td>${sku.v6 || 0}</td>
                    <td>${sku.v5 || 0}</td>
                    <td>${sku.v4 || 0}</td>
                    <td>${sku.v3 || 0}</td>
                    <td>${sku.v2 || 0}</td>
                    <td>${sku.v1 || 0}</td>
                    <td>${sku.pv6 || 0}</td>
                    <td>${sku.pv3 || 0}</td>
                    <td>${sku.capacity || 0}</td>
                    <td>${sku.lt || 0}</td>
                    <td>${sku.disp_tda || 0}</td>
                    <td>${sku.pend_tda || 0}</td>
                    <td class="${sdsClass}">${sdsActual}</td>
                    <td><strong>${sku.carga_solicitada}</strong></td>
                    <td><strong>${sku.carga_final || '-'}</strong></td>
                    <td>${sku.md || sku.md_defecto_sku || '-'}</td>
                    <td><span class="badge ${estadoBadge}">${sku.estado_item || 'PENDIENTE'}</span></td>
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
        
        Swal.fire({
            title: `Detalle Solicitud #${idSolicitud}`,
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