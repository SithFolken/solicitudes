// ==========================================
// VARIABLES GLOBALES DE PAGINACIÓN
// ==========================================
let estadoPaginacion = {
    pagina: 1,
    por_pagina: 50
};

document.addEventListener('DOMContentLoaded', () => {
    console.log("✅ mis_solicitudes.js cargado correctamente");
    cargarSolicitudes();
});

// ==========================================
// Cargar Solicitudes del Usuario
// ==========================================
async function cargarSolicitudes() {
    const tbody = document.getElementById('tbodySolicitudes');
    const sinRegistros = document.getElementById('sinRegistros');
    
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-3 text-muted fw-medium">Cargando solicitudes...</p></td></tr>';
    sinRegistros.style.display = 'none';
    ocultarPaginacion();

    try {
        const url = construirURLSolicitudes();
        const response = await fetch(url);
        const data = await response.json();

        if (!data.success) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Error al cargar datos</td></tr>';
            return;
        }

        // Actualizar contadores
        if (data.contadores) {
            document.getElementById('countPendientes').textContent = data.contadores.PENDIENTE || 0;
            //document.getElementById('countAprobadas').textContent = data.contadores.APROBADA || 0;
            document.getElementById('countProceso').textContent = data.contadores.EN_PROCESO || 0;
            document.getElementById('countProcesadas').textContent = data.contadores.PROCESADA || 0;
            document.getElementById('countRechazadas').textContent = data.contadores.RECHAZADA || 0;
        }

        const solicitudes = data.solicitudes || [];
        tbody.innerHTML = '';

        if (solicitudes.length === 0) {
            sinRegistros.style.display = 'block';
        } else {
            solicitudes.forEach((sol) => {
                const tr = document.createElement('tr');
                
                let estadoTexto = (sol.estado_general || sol.estado_actual || sol.estado_solicitud || sol.estado || 'PENDIENTE').trim().toUpperCase();

                let badgeClass = 'bg-secondary';
                switch(estadoTexto) {
                    case 'PENDIENTE': badgeClass = 'bg-secondary'; break;
                    case 'APROBADA': case 'APROBADO': badgeClass = 'bg-info text-dark'; break;
                    case 'EN_PROCESO': case 'EN PROCESO': badgeClass = 'bg-warning text-dark'; break;
                    case 'PROCESADA': case 'PROCESADO': badgeClass = 'bg-success'; break;
                    case 'PROCESADA_PARCIAL': badgeClass = 'bg-success'; break;
                    case 'RECHAZADA': case 'RECHAZADO': badgeClass = 'bg-danger'; break;
                }

                const badgeSkus = `<span class="badge bg-light text-dark border">${sol.total_skus || 0} SKU(s)</span>`;
                const fechaFormateada = sol.fecha_solicitud || '-';

                let btnAccion = '';
                if (estadoTexto === 'PENDIENTE') {
                    btnAccion = `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-info" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle"><i class="bi bi-eye"></i></button>
                            <a href="solicitar_carga.php?editar=${sol.id_solicitud}" class="btn btn-warning text-dark" title="Modificar"><i class="bi bi-pencil"></i></a>
                            <button class="btn btn-danger" onclick="eliminarSolicitudUsuario(${sol.id_solicitud})" title="Eliminar"><i class="bi bi-trash"></i></button>
                        </div>`;
                } else if (estadoTexto === 'EN_PROCESO' || estadoTexto === 'EN PROCESO') {
                    btnAccion = `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-info" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle"><i class="bi bi-eye"></i></button>
                            <button class="btn btn-warning text-dark" disabled><i class="bi bi-hourglass-split"></i> En Proceso</button>
                        </div>`;
                } else if (estadoTexto === 'RECHAZADA' || estadoTexto === 'RECHAZADO') {
                    btnAccion = `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-info" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver motivo"><i class="bi bi-eye"></i></button>
                            <button class="btn btn-secondary" disabled><i class="bi bi-x-circle"></i> Rechazada</button>
                        </div>`;
                } else {
                    btnAccion = `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-info" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle"><i class="bi bi-eye"></i></button>
                            <button class="btn btn-success" disabled><i class="bi bi-check-circle"></i> Procesada</button>
                        </div>`;
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

            // Renderizar controles de paginación
            if (data.paginacion) {
                renderizarPaginacion(data.paginacion);
            }
        }

    } catch (error) {
        console.error('Error:', error);
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Error de conexión</td></tr>';
    }
}

// ==========================================
// Construir URL con filtros y paginación
// ==========================================
function construirURLSolicitudes() {
    const estado = document.getElementById('filtroEstado')?.value || 'TODOS';
    const md = document.getElementById('filtroMD')?.value || 'TODOS';
    const fechaDesde = document.getElementById('fechaDesde')?.value || '';
    const fechaHasta = document.getElementById('fechaHasta')?.value || '';
    
    let url = '../api/obtener_mis_solicitudes.php?';
    url += `pagina=${estadoPaginacion.pagina}&`;
    url += `por_pagina=${estadoPaginacion.por_pagina}&`;
    if (estado !== 'TODOS') url += `estado=${encodeURIComponent(estado)}&`;
    if (md !== 'TODOS') url += `md=${encodeURIComponent(md)}&`;
    if (fechaDesde) url += `fecha_desde=${encodeURIComponent(fechaDesde)}&`;
    if (fechaHasta) url += `fecha_hasta=${encodeURIComponent(fechaHasta)}&`;
    
    return url;
}

// ==========================================
// Renderizar Controles de Paginación
// ==========================================
function renderizarPaginacion(paginacion) {
    const contenedor = document.getElementById('contenedorPaginacion');
    const infoRango = document.getElementById('infoRango');
    const infoRangoFooter = document.getElementById('infoRangoFooter');
    const selectPorPagina = document.getElementById('selectPorPagina');
    
    if (!contenedor) return;

    const { pagina_actual, por_pagina, total_registros, total_paginas, desde, hasta } = paginacion;

    const textoRango = `Mostrando ${desde}-${hasta} de ${total_registros.toLocaleString()} registros`;
    if (infoRango) infoRango.textContent = textoRango;
    if (infoRangoFooter) infoRangoFooter.textContent = textoRango;

    if (selectPorPagina) selectPorPagina.value = por_pagina;

    let html = '';
    html += `<button class="btn btn-sm btn-outline-secondary" ${pagina_actual === 1 ? 'disabled' : ''} onclick="irAPagina(1)"><i class="bi bi-chevron-double-left"></i></button>`;
    html += `<button class="btn btn-sm btn-outline-secondary" ${pagina_actual === 1 ? 'disabled' : ''} onclick="irAPagina(${pagina_actual - 1})"><i class="bi bi-chevron-left"></i></button>`;

    const rangoVisible = 2;
    let paginaInicio = Math.max(1, pagina_actual - rangoVisible);
    let paginaFin = Math.min(total_paginas, pagina_actual + rangoVisible);

    if (pagina_actual - rangoVisible < 1) paginaFin = Math.min(total_paginas, paginaInicio + (rangoVisible * 2));
    if (pagina_actual + rangoVisible > total_paginas) paginaInicio = Math.max(1, paginaFin - (rangoVisible * 2));

    if (paginaInicio > 1) {
        html += `<button class="btn btn-sm btn-outline-secondary" onclick="irAPagina(1)">1</button>`;
        if (paginaInicio > 2) html += `<span class="px-2 text-muted d-flex align-items-center">...</span>`;
    }

    for (let i = paginaInicio; i <= paginaFin; i++) {
        const claseActiva = i === pagina_actual ? 'btn-primary' : 'btn-outline-secondary';
        html += `<button class="btn btn-sm ${claseActiva}" onclick="irAPagina(${i})">${i}</button>`;
    }

    if (paginaFin < total_paginas) {
        if (paginaFin < total_paginas - 1) html += `<span class="px-2 text-muted d-flex align-items-center">...</span>`;
        html += `<button class="btn btn-sm btn-outline-secondary" onclick="irAPagina(${total_paginas})">${total_paginas}</button>`;
    }

    html += `<button class="btn btn-sm btn-outline-secondary" ${pagina_actual === total_paginas ? 'disabled' : ''} onclick="irAPagina(${pagina_actual + 1})"><i class="bi bi-chevron-right"></i></button>`;
    html += `<button class="btn btn-sm btn-outline-secondary" ${pagina_actual === total_paginas ? 'disabled' : ''} onclick="irAPagina(${total_paginas})"><i class="bi bi-chevron-double-right"></i></button>`;

    contenedor.innerHTML = html;
}

function ocultarPaginacion() {
    const contenedor = document.getElementById('contenedorPaginacion');
    const infoRango = document.getElementById('infoRango');
    const infoRangoFooter = document.getElementById('infoRangoFooter');
    if (contenedor) contenedor.innerHTML = '';
    if (infoRango) infoRango.textContent = '';
    if (infoRangoFooter) infoRangoFooter.textContent = '';
}

// ==========================================
// Acciones de Paginación y Filtros
// ==========================================
function irAPagina(pagina) {
    estadoPaginacion.pagina = pagina;
    cargarSolicitudes();
    const tabla = document.getElementById('tablaSolicitudes');
    if (tabla) tabla.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function cambiarPorPagina(nuevoValor) {
    estadoPaginacion.por_pagina = parseInt(nuevoValor);
    estadoPaginacion.pagina = 1;
    cargarSolicitudes();
}

function aplicarFiltros() {
    estadoPaginacion.pagina = 1;
    cargarSolicitudes();
}

function limpiarFiltros() {
    document.getElementById('filtroEstado').value = 'TODOS';
    document.getElementById('filtroMD').value = 'TODOS';
    document.getElementById('fechaDesde').value = '';
    document.getElementById('fechaHasta').value = '';
    estadoPaginacion.pagina = 1;
    cargarSolicitudes();
}

// ==========================================
// Eliminar Solicitud (Usuario Tienda)
// ==========================================
async function eliminarSolicitudUsuario(idSolicitud) {
    const result = await Swal.fire({
        title: '¿Eliminar esta solicitud?',
        html: `<p>Esta acción <strong>no se puede deshacer</strong>.</p><p class="text-muted small">Solo puedes eliminar solicitudes en estado <strong>PENDIENTE</strong>.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    });

    if (!result.isConfirmed) return;

    Swal.fire({ title: 'Eliminando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

    try {
        const response = await fetch('../api/eliminar_solicitud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_solicitud: idSolicitud })
        });
        
        const texto = await response.text();
        let data;
        try { data = JSON.parse(texto); } catch (e) { throw new Error("Respuesta inválida del servidor"); }

        if (data.success) {
            Swal.fire({ icon: 'success', title: 'Solicitud Eliminada', text: data.message, timer: 2000, showConfirmButton: false }).then(() => { cargarSolicitudes(); });
        } else {
            Swal.fire('Error', data.message || 'No se pudo eliminar la solicitud', 'error');
        }
    } catch (error) {
        console.error('Error al eliminar:', error);
        Swal.fire('Error', 'No se pudo conectar con el servidor', 'error');
    }
}

// ==========================================
// Ver Detalle Completo de Solicitud
// ==========================================
async function verDetalleSolicitud(idSolicitud) {
    Swal.fire({ title: `Cargando detalle #${idSolicitud}...`, allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    try {
        const response = await fetch(`../api/obtener_detalle_completo.php?id=${idSolicitud}`);
        const data = await response.json();
        Swal.close();
        
        if (!data.success) { Swal.fire('Error', data.message, 'error'); return; }
        if (!data.skus || data.skus.length === 0) { Swal.fire('Información', 'No hay detalles para esta solicitud', 'info'); return; }
        
        const estadoGeneral = (data.estado_general || 'PENDIENTE').toUpperCase();
        const esProvisional = estadoGeneral === 'EN_PROCESO' || estadoGeneral === 'PENDIENTE';
        
        let html = `<div class="text-start mb-3 p-3 bg-light rounded">
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
                    ${esProvisional ? `<div class="alert alert-warning mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Atención:</strong> Esta solicitud está siendo procesada por el analista. Los estados mostrados son <strong>provisionales</strong>.</div>` : ''}
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-sm table-hover table-bordered" style="font-size: 11px;">
                            <thead class="table-dark sticky-top">
                                <tr>
                                    <th rowspan="2">SKU</th><th rowspan="2">Producto</th>
                                    <th colspan="6" class="text-center bg-info">VENTAS (Últimas 6 Sem)</th>
                                    <th rowspan="2">PV6</th><th rowspan="2">PV3</th><th rowspan="2">Cap.</th><th rowspan="2">LT</th>
                                    <th rowspan="2">Disp TDA</th><th rowspan="2">Pend TDA</th><th rowspan="2">SDS Actual</th>
                                    <th rowspan="2">Cant. Sol.</th><th rowspan="2">Cant. Final</th><th rowspan="2">Pallets</th>
                                    <th rowspan="2">MD</th><th rowspan="2">Estado</th><th rowspan="2">Motivo</th>
                                </tr>
                                <tr class="bg-info"><th>v6</th><th>v5</th><th>v4</th><th>v3</th><th>v2</th><th>v1</th></tr>
                            </thead><tbody>`;
        
        data.skus.forEach(sku => {
            const sdsActual = parseFloat(sku.sds_actual) || 0;
            const sdsClass = sdsActual > 20 ? 'text-danger fw-bold' : '';
            const unidPallet = parseFloat(sku.unid_pallet) || 1;
            const cantSolicitada = parseFloat(sku.carga_solicitada) || 0;
            const palletsExactos = cantSolicitada > 0 ? (cantSolicitada / unidPallet) : 0;
            let estadoTexto = sku.estado_item || 'PENDIENTE';
            let badgeClase = getBadgeClass(sku.estado_item);
            
            if (esProvisional && estadoTexto !== 'PENDIENTE') { estadoTexto = estadoTexto + ' (Pendiente)'; badgeClase = 'bg-warning text-dark'; }
            
            html += `<tr>
                        <td><strong>${sku.sku}</strong></td>
                        <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;" title="${sku.descripcion_producto || ''}">${sku.descripcion_producto || '-'}</td>
                        <td>${sku.v6 || 0}</td><td>${sku.v5 || 0}</td><td>${sku.v4 || 0}</td>
                        <td>${sku.v3 || 0}</td><td>${sku.v2 || 0}</td><td>${sku.v1 || 0}</td>
                        <td>${sku.pv6 || 0}</td><td>${sku.pv3 || 0}</td><td>${sku.capacity || 0}</td><td>${sku.lt || 0}</td>
                        <td>${sku.disp_tda || 0}</td><td>${sku.pend_tda || 0}</td>
                        <td class="${sdsClass}">${sdsActual}</td>
                        <td><strong>${sku.carga_solicitada}</strong></td>
                        <td><strong>${sku.carga_final || '-'}</strong></td>
                        <td><strong class="text-primary">${palletsExactos.toFixed(3)}</strong><br><small class="text-muted">(${cantSolicitada}/${unidPallet})</small></td>
                        <td>${sku.md || sku.md_defecto_sku || '-'}</td>
                        <td><span class="badge ${badgeClase}">${estadoTexto}</span></td>
                        <td style="max-width: 200px;" title="${sku.campo_cambios || ''}"><small>${sku.campo_cambios || '-'}</small></td>
                    </tr>`;
        });
        
        html += `</tbody></table></div>
                 <div class="alert alert-info mt-3 mb-0"><small><strong>💡 Leyenda:</strong><br>• <strong>PV6/PV3:</strong> Promedio de ventas últimas 6/3 semanas<br>• <strong>SDS:</strong> Semanas de Stock<br>• <strong>Disp TDA:</strong> Disponible en tienda | <strong>Pend TDA:</strong> Pendiente en tienda<br>• <strong>LT:</strong> Lead Time</small></div>`;
        
        Swal.fire({ title: `Detalle Solicitud #${idSolicitud}`, html: html, width: '95%', confirmButtonText: 'Cerrar', confirmButtonColor: '#0d6efd' });
    } catch (error) { console.error('Error cargando detalle:', error); Swal.fire('Error', 'No se pudo cargar el detalle', 'error'); }
}

function getBadgeClass(estado) {
    if (!estado) return 'bg-secondary';
    const estadoUpper = estado.toUpperCase();
    switch(estadoUpper) {
        case 'PENDIENTE': case 'EN_PROCESO': return 'bg-secondary';
        case 'APROBADA': case 'APROBADO': return 'bg-info text-dark';
        case 'PROCESADA': case 'PROCESADA_PARCIAL': return 'bg-success';
        case 'RECHAZADA': case 'RECHAZADO': return 'bg-danger';
        default: return 'bg-secondary';
    }
}