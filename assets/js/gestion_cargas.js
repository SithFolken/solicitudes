// ==========================================
// VARIABLES GLOBALES DE PAGINACIÓN
// ==========================================
let estadoPaginacion = {
    pagina: 1,
    por_pagina: 50
};

document.addEventListener('DOMContentLoaded', () => {
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
async function cargarSolicitudes() {
    const loading = document.getElementById('loadingTable');
    const tablaContainer = document.getElementById('tablaContainer');
    const sinRegistros = document.getElementById('sinRegistros');
    const btnActualizar = document.getElementById('btnActualizar');
    const icono = btnActualizar ? btnActualizar.querySelector('i') : null;
    
    loading.style.display = 'block';
    tablaContainer.style.display = 'none';
    sinRegistros.style.display = 'none';
    ocultarPaginacion();
    
    if (btnActualizar) {
        btnActualizar.disabled = true;
        if (icono) icono.classList.add('fa-spin');
    }

    try {
        const url = construirURLSolicitudes();
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
            tablaContainer.style.display = 'none';
        } else {
            tablaContainer.style.display = 'block';
            sinRegistros.style.display = 'none';

            data.solicitudes.forEach(sol => {
                const tr = document.createElement('tr');
                
                let badgeClass = 'bg-secondary';
                let estadoTexto = sol.estado_general || 'PENDIENTE';
                
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

                let alertaTiempo = '';
                if (estadoTexto === 'PENDIENTE' || estadoTexto === 'EN_PROCESO') {
                    if (sol.estado_tiempo === 'VENCIDA') {
                        alertaTiempo = `<span class="badge bg-danger ms-2" title="Vencida: Han pasado más de 48 horas"><i class="bi bi-exclamation-triangle-fill"></i> Vencida</span>`;
                    } else if (sol.estado_tiempo === 'POR_CUMPLIRSE') {
                        alertaTiempo = `<span class="badge bg-warning text-dark ms-2" title="Urgente: Quedan menos de 12 horas"><i class="bi bi-clock-history"></i> Por cumplir</span>`;
                    } else {
                        alertaTiempo = `<span class="badge bg-success ms-2" title="A tiempo"><i class="bi bi-check-circle"></i> A tiempo</span>`;
                    }
                }

                let badgeExcel = '';
                if (sol.fecha_generacion_excel) {
                    badgeExcel = `<span class="badge bg-success ms-2" title="Incluido en Excel el ${sol.fecha_generacion_excel}"><i class="bi bi-check-circle me-1"></i>Excel</span>`;
                }

                let btnAccion = '';
                if (estadoTexto === 'PENDIENTE') {
                    btnAccion = `<button class="btn btn-primary btn-sm" onclick="confirmarProcesamiento(${sol.id_solicitud}, '${nombreTienda}', ${sol.total_skus || 0})" title="Procesar solicitud"><i class="bi bi-gear"></i> Procesar</button>`;
                } else if (estadoTexto === 'EN_PROCESO' || estadoTexto === 'EN PROCESO') {
                    btnAccion = `<button class="btn btn-warning btn-sm text-dark" onclick="window.location.href='procesar_carga.php?id=${sol.id_solicitud}'" title="Continuar procesamiento"><i class="bi bi-hourglass-split"></i> Continuar</button>`;
                } else if (estadoTexto === 'RECHAZADA' || estadoTexto === 'RECHAZADO') {
                    btnAccion = `<button class="btn btn-info btn-sm" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle"><i class="bi bi-eye"></i></button>`;
                } else {
                    btnAccion = `<button class="btn btn-info btn-sm" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle completo"><i class="bi bi-eye"></i></button>`;
                }

                tr.innerHTML = `
                    <td><strong>#${sol.id_solicitud}</strong></td>
                    <td>${nombreTienda}<br><small class="text-muted">${idTienda}</small></td>
                    <td class="text-center">${badgeSkus}</td>
                    <td class="text-center fw-bold">${sol.total_cantidad_solicitada || 0}</td>
                    <td class="text-center"><span class="badge ${badgeClass}">${estadoTexto}</span>${badgeExcel}</td>
                    <td><small>${fechaFormateada}</small>${alertaTiempo}</td>
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
        loading.style.display = 'none';
        if (btnActualizar) {
            btnActualizar.disabled = false;
            if (icono) icono.classList.remove('fa-spin');
        }
        Swal.fire('Error', 'No se pudieron cargar las solicitudes', 'error');
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
    
    let url = '../api/obtener_solicitudes.php?';
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

    // Actualizar textos de rango
    const textoRango = `Mostrando ${desde}-${hasta} de ${total_registros.toLocaleString()} registros`;
    if (infoRango) infoRango.textContent = textoRango;
    if (infoRangoFooter) infoRangoFooter.textContent = textoRango;

    // Actualizar selector
    if (selectPorPagina) selectPorPagina.value = por_pagina;

    // Generar botones de navegación
    let html = '';

    // Primera y Anterior
    html += `<button class="btn btn-sm btn-outline-secondary" ${pagina_actual === 1 ? 'disabled' : ''} onclick="irAPagina(1)"><i class="bi bi-chevron-double-left"></i></button>`;
    html += `<button class="btn btn-sm btn-outline-secondary" ${pagina_actual === 1 ? 'disabled' : ''} onclick="irAPagina(${pagina_actual - 1})"><i class="bi bi-chevron-left"></i></button>`;

    // Números de página (lógica inteligente con ...)
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

    // Siguiente y Última
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
    const tabla = document.getElementById('tablaContainer');
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
// Exportar Excel del Ciclo (Sin cambios)
// ==========================================
function exportarExcel() {
    const hoy = new Date();
    const horaActual = hoy.getHours() + (hoy.getMinutes() / 60);
    const cicloCorte = hoy.toISOString().split('T')[0];
    const yaPasoCorte = horaActual >= 13;
    
    let mensajeHora = '';
    if (yaPasoCorte) {
        mensajeHora = `<div class="alert alert-warning mt-2 mb-0"><i class="bi bi-exclamation-triangle me-2"></i><strong>Ya pasó la hora de corte (13:00 hrs)</strong><br>Las solicitudes procesadas después de las 13:00 hrs se incluirán en el ciclo de mañana.</div>`;
    }
    
    Swal.fire({
        title: '¿Generar Excel del Ciclo?',
        html: `<p>Se generará el archivo Excel con todas las solicitudes procesadas del ciclo:</p>
               <div class="alert alert-info text-start">
                   <strong>📅 Ciclo de Corte:</strong> ${cicloCorte}<br>
                   <strong>⏰ Período:</strong> Desde ${cicloCorte} 13:00 hrs<br>
                   &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;hasta ${new Date(hoy.getTime() + 86400000).toISOString().split('T')[0]} 13:00 hrs<br>
                   <strong>🕐 Hora actual:</strong> ${hoy.toLocaleTimeString('es-CL', {hour: '2-digit', minute:'2-digit'})} hrs
                   ${mensajeHora}
               </div>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, generar Excel',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Verificando datos...', text: 'Buscando solicitudes procesadas en este ciclo', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            fetch(`../api/generar_excel_carga.php?ciclo=${cicloCorte}&validar=1`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.tiene_datos) {
                        Swal.close();
                        window.open(`../api/generar_excel_carga.php?ciclo=${cicloCorte}`, '_blank');
                        Swal.fire({ icon: 'success', title: '¡Excel Generado!', text: `Se exportaron ${data.total_solicitudes || 'las'} solicitudes del ciclo.`, timer: 3000, showConfirmButton: false });
                    } else {
                        Swal.close();
                        Swal.fire({ icon: 'warning', title: data.hora_corte ? 'Hora de corte superada' : 'Sin datos para exportar', html: data.message || 'No se encontraron solicitudes procesadas para este ciclo.', confirmButtonText: 'Entendido', confirmButtonColor: '#ffc107' });
                    }
                })
                .catch(error => { Swal.close(); Swal.fire('Error', 'No se pudo conectar con el servidor', 'error'); });
        }
    });
}

// ==========================================
// Ver Detalle Completo de Solicitud (Sin cambios)
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
                    ${esProvisional ? `<div class="alert alert-warning mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Atención:</strong> Esta solicitud está siendo procesada. Los estados mostrados son <strong>provisionales</strong>.</div>` : ''}
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

// ==========================================
// Confirmar antes de entrar a procesar (Sin cambios)
// ==========================================
function confirmarProcesamiento(idSolicitud, nombreTienda, totalSkus) {
    Swal.fire({
        title: '¿Iniciar procesamiento?',
        html: `<div class="text-start">
                    <p>Estás a punto de procesar la solicitud <strong>#${idSolicitud}</strong> de la tienda <strong>${nombreTienda}</strong>.</p>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i><strong>Atención:</strong>
                        <ul class="mb-0 mt-2">
                            <li>La solicitud pasará de estado <strong>PENDIENTE</strong> a <strong>EN_PROCESO</strong>.</li>
                            <li>Contiene <strong>${totalSkus} SKU(s)</strong> que serán evaluados.</li>
                            <li>Una vez en proceso, el usuario de la tienda ya no podrá modificarla.</li>
                        </ul>
                    </div>
               </div>`,
        icon: 'question', showCancelButton: true, confirmButtonColor: '#0056b3', cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, iniciar procesamiento', cancelButtonText: 'Cancelar'
    }).then(async (result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Cambiando estado...', text: 'Marcando solicitud como EN_PROCESO', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            try {
                const responseEstado = await fetch('../api/cambiar_estado.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id_solicitud: idSolicitud, estado: 'EN_PROCESO' }) });
                const dataEstado = await responseEstado.json();
                if (!dataEstado.success) { Swal.fire('Error', dataEstado.message || 'No se pudo cambiar el estado', 'error'); return; }
                
                const responseSesion = await fetch('../api/iniciar_procesamiento.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id_solicitud: idSolicitud }) });
                const dataSesion = await responseSesion.json();
                
                if (dataSesion.success) { Swal.close(); window.location.href = `procesar_carga.php?id=${idSolicitud}`; } 
                else { Swal.fire('Error', 'No se pudo iniciar la sesión de procesamiento', 'error'); }
            } catch (error) { console.error('Error:', error); Swal.fire('Error', 'No se pudo conectar con el servidor', 'error'); }
        }
    });
}