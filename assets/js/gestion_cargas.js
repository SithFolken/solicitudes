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
                let iconoExtra = '';
                
                // ✅ DETECTAR SI ES FAMILIA DE PELIGROSOS (998 o 999)
                if (sol.id_familia == 998 || sol.id_familia == 999) {
                    badgeClass = 'bg-danger'; // Fondo rojo
                    iconoExtra = '<i class="bi bi-radiation me-1" title="Producto Peligroso"></i>';
                    estadoTexto = 'PELIGROSO - ' + estadoTexto;
                } else {
                    // Lógica normal de colores para el resto
                    switch(estadoTexto.toUpperCase()) {
                        case 'PENDIENTE': badgeClass = 'bg-secondary'; break;
                        case 'APROBADA': badgeClass = 'bg-info text-dark'; break;
                        case 'EN_PROCESO': badgeClass = 'bg-warning text-dark'; break;
                        case 'PROCESADA': badgeClass = 'bg-success'; break;
                        case 'PROCESADA_PARCIAL': badgeClass = 'bg-success'; break;
                        case 'RECHAZADA': badgeClass = 'bg-danger'; break;
                    }
                }

                const badgeSkus = `<span class="badge bg-light text-dark border">${sol.total_skus || 0} SKU(s)</span>`;
                const nombreTienda = sol.nombre_tienda || 'N/A';
                const idTienda = sol.id_tienda ? `(ID: ${sol.id_tienda})` : '';
                const fechaFormateada = sol.fecha_solicitud || '-';

                let alertaTiempo = '';
                if (estadoTexto.includes('PENDIENTE') || estadoTexto.includes('EN_PROCESO')) {
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
                    const esAtrasado = sol.tipo_exportacion === 'ATRASADO';
                    const colorClass = esAtrasado ? 'bg-warning text-dark' : 'bg-success';
                    const iconoExcel = esAtrasado ? 'bi-clock-history' : 'bi-check-circle';
                    const texto = esAtrasado ? 'Excel Atrasado' : 'Excel';
                    
                    badgeExcel = `<span class="badge ${colorClass} ms-2" title="Exportado el ${sol.fecha_generacion_excel}"><i class="bi ${iconoExcel} me-1"></i>${texto}</span>`;
                }

                // ✅ NUEVO: Badge de Reemplazo (DEBAJO de los otros badges)
                let badgeReemplazo = '';
                if (sol.es_reemplazo && sol.nombre_analista_original) {
                    badgeReemplazo = `<div class="mt-1"><span class="badge bg-warning text-dark" style="font-size: 0.7em; padding: 0.2em 0.5em;" title="Solicitud original de: ${sol.nombre_analista_original}">
                        <i class="bi bi-arrow-repeat me-1"></i>Reemplazo: ${sol.nombre_analista_original.split(' ')[0]}
                    </span></div>`;
                }

                let btnAccion = '';
                if (estadoTexto.includes('PENDIENTE')) {
                    btnAccion = `<button class="btn btn-primary btn-sm" onclick="confirmarProcesamiento(${sol.id_solicitud}, '${nombreTienda}', ${sol.total_skus || 0})" title="Procesar solicitud"><i class="bi bi-gear"></i> Procesar</button>`;
                } else if (estadoTexto.includes('EN_PROCESO')) {
                    btnAccion = `<button class="btn btn-warning btn-sm text-dark" onclick="window.location.href='procesar_carga.php?id=${sol.id_solicitud}'" title="Continuar procesamiento"><i class="bi bi-hourglass-split"></i> Continuar</button>`;
                } else if (estadoTexto.includes('RECHAZA')) {
                    btnAccion = `<button class="btn btn-info btn-sm" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle"><i class="bi bi-eye"></i></button>`;
                } else {
                    btnAccion = `<button class="btn btn-info btn-sm" onclick="verDetalleSolicitud(${sol.id_solicitud})" title="Ver detalle completo"><i class="bi bi-eye"></i></button>`;
                }

                tr.innerHTML = `
                    <td><strong>#${sol.id_solicitud}</strong></td>
                    <td>${nombreTienda}<br><small class="text-muted">${idTienda}</small></td>
                    <td class="text-center">${badgeSkus}</td>
                    <td class="text-center fw-bold">${sol.total_cantidad_solicitada || 0}</td>
                    <td class="text-center">
                        <div class="d-flex flex-wrap justify-content-center gap-1 mb-1">
                            <span class="badge ${badgeClass}">${iconoExtra}${estadoTexto}</span>
                            ${badgeExcel}
                        </div>
                        ${badgeReemplazo}
                    </td>
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
// Exportar Excel del Ciclo (CON EXCELJS - XLSX REAL CON COLORES)
// ==========================================
function exportarExcel() {
    const hoy = new Date();
    const year = hoy.getFullYear();
    const month = String(hoy.getMonth() + 1).padStart(2, '0');
    const day = String(hoy.getDate()).padStart(2, '0');
    const fechaHoyLocal = `${year}-${month}-${day}`;
    
    const ayer = new Date(hoy);
    ayer.setDate(ayer.getDate() - 1);
    const yearAyer = ayer.getFullYear();
    const monthAyer = String(ayer.getMonth() + 1).padStart(2, '0');
    const dayAyer = String(ayer.getDate()).padStart(2, '0');
    const fechaAyerLocal = `${yearAyer}-${monthAyer}-${dayAyer}`;
    
    const fechaInicio = `${fechaAyerLocal} 13:00:00`;
    const fechaFin = `${fechaHoyLocal} 13:00:00`;
    const cicloNombre = fechaHoyLocal;
    
    Swal.fire({
        title: '¿Generar Excel del Ciclo?',
        html: `
            <p>Se generará el archivo Excel con todas las solicitudes procesadas del ciclo:</p>
            <div class="alert alert-info text-start">
                <strong>📅 Ciclo de Corte:</strong> ${cicloNombre}<br>
                <strong>⏰ Período:</strong> Desde ${fechaInicio}<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;hasta ${fechaFin}
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
                title: 'Generando Excel...',
                text: 'Preparando datos con formato',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            const url = `../api/generar_excel_carga.php?ciclo=${cicloNombre}&fecha_inicio=${encodeURIComponent(fechaInicio)}&fecha_fin=${encodeURIComponent(fechaFin)}`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    Swal.close();
                    
                    if (!data.success || !data.datos) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Sin datos para exportar',
                            html: data.message || 'No se encontraron solicitudes procesadas para este ciclo.',
                            confirmButtonText: 'Entendido',
                            confirmButtonColor: '#ffc107'
                        });
                        return;
                    }
                    
                    // ✅ GENERAR XLSX CON EXCELJS
                    generarExcelConExcelJS(data.datos, data.nombre_archivo);
                    
                    // Marcar como exportadas en la BD
                    fetch(`../api/generar_excel_carga.php?ciclo=${cicloNombre}&fecha_inicio=${encodeURIComponent(fechaInicio)}&fecha_fin=${encodeURIComponent(fechaFin)}&marcar=1`)
                        .then(() => cargarSolicitudes())
                        .catch(err => console.error('Error marcando exportadas:', err));
                    
                    Swal.fire({
                        icon: 'success',
                        title: '¡Excel Generado!',
                        text: 'El archivo se descargó automáticamente.',
                        timer: 3000,
                        showConfirmButton: false
                    });
                })
                .catch(error => {
                    Swal.close();
                    Swal.fire('Error', 'No se pudo conectar con el servidor: ' + error.message, 'error');
                });
        }
    });
}

// ==========================================
// Generar XLSX con ExcelJS (con colores y múltiples hojas)
// ==========================================
async function generarExcelConExcelJS(datos, nombreArchivo) {
    const workbook = new ExcelJS.Workbook();
    
    // ==========================================
    // HOJA 1: TRANSFERENCIA (Verde)
    // ==========================================
    if (datos.TRANSFERENCIA && datos.TRANSFERENCIA.length > 0) {
        const ws1 = workbook.addWorksheet('TRANSFERENCIA');
        ws1.columns = [
            { header: 'TIENDA', key: 'tienda', width: 10 },
            { header: 'CODIGO', key: 'codigo', width: 12 },
            { header: 'DIGITO', key: 'digito', width: 8 },
            { header: 'CANTIDAD', key: 'cantidad', width: 10 },
            { header: 'MENSAJE', key: 'mensaje', width: 10 }
        ];
        
        // Aplicar estilo SOLO a las celdas del encabezado (A1:E1)
        const estiloVerde = {
            font: { bold: true, color: { argb: 'FFFFFFFF' }, size: 11 },
            fill: { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF28A745' } },
            alignment: { horizontal: 'center' }
        };
        
        for (let i = 1; i <= 5; i++) {
            ws1.getCell(1, i).font = estiloVerde.font;
            ws1.getCell(1, i).fill = estiloVerde.fill;
            ws1.getCell(1, i).alignment = estiloVerde.alignment;
        }
        
        // Agregar datos
        datos.TRANSFERENCIA.forEach(item => {
            const skuStr = String(item.sku);
            ws1.addRow({
                tienda: item.id_tienda,
                codigo: skuStr.slice(0, -1),
                digito: skuStr.slice(-1),
                cantidad: item.carga_final,
                mensaje: 'R:'
            });
        });
    }
    
    // ==========================================
    // HOJA 2: CROSS DOCKING (Azul)
    // ==========================================
    if (datos.CROSS_DOCKING && datos.CROSS_DOCKING.length > 0) {
        const ws2 = workbook.addWorksheet('CROSS DOCKING');
        ws2.columns = [
            { header: 'TIENDA', key: 'tienda', width: 10 },
            { header: 'SKU', key: 'sku', width: 12 },
            { header: 'CANTIDAD', key: 'cantidad', width: 10 }
        ];
        
        const estiloAzul = {
            font: { bold: true, color: { argb: 'FFFFFFFF' }, size: 11 },
            fill: { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF007BFF' } },
            alignment: { horizontal: 'center' }
        };
        
        for (let i = 1; i <= 3; i++) {
            ws2.getCell(1, i).font = estiloAzul.font;
            ws2.getCell(1, i).fill = estiloAzul.fill;
            ws2.getCell(1, i).alignment = estiloAzul.alignment;
        }
        
        datos.CROSS_DOCKING.forEach(item => {
            ws2.addRow({
                tienda: item.id_tienda,
                sku: item.sku,
                cantidad: item.carga_final
            });
        });
    }
    
    // ==========================================
    // HOJA 3: COMPRA LOCAL (Naranja)
    // ==========================================
    if (datos.COMPRA_LOCAL && datos.COMPRA_LOCAL.length > 0) {
        const ws3 = workbook.addWorksheet('COMPRA LOCAL');
        ws3.columns = [
            { header: 'SKU', key: 'sku', width: 12 },
            { header: 'CANTIDAD', key: 'cantidad', width: 10 },
            { header: 'FECHA SOLICITUD', key: 'f_solicitud', width: 15 },
            { header: 'FECHA RECEPCION', key: 'f_recepcion', width: 15 },
            { header: 'FECHA CANCELACION', key: 'f_cancel', width: 15 },
            { header: 'TIENDA', key: 'tienda', width: 10 },
            { header: 'ID PROVEEDOR', key: 'id_prov', width: 12 }
        ];
        
        const estiloNaranja = {
            font: { bold: true, color: { argb: 'FFFFFFFF' }, size: 11 },
            fill: { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFFD7E14' } },
            alignment: { horizontal: 'center' }
        };
        
        for (let i = 1; i <= 7; i++) {
            ws3.getCell(1, i).font = estiloNaranja.font;
            ws3.getCell(1, i).fill = estiloNaranja.fill;
            ws3.getCell(1, i).alignment = estiloNaranja.alignment;
        }
        
        const hoy = new Date();
        const fmtFecha = (d) => d.toISOString().split('T')[0];
        
        datos.COMPRA_LOCAL.forEach(item => {
            const lt = parseInt(item.LT) || 0;
            const fRecepcion = new Date();
            fRecepcion.setDate(fRecepcion.getDate() + lt);
            const fCancel = new Date(fRecepcion);
            fCancel.setDate(fCancel.getDate() + 3);
            
            ws3.addRow({
                sku: item.sku,
                cantidad: item.carga_final,
                f_solicitud: fmtFecha(hoy),
                f_recepcion: fmtFecha(fRecepcion),
                f_cancel: fmtFecha(fCancel),
                tienda: item.id_tienda,
                id_prov: item.id_proveedor
            });
        });
    }
    
    // Descargar el archivo
    const buffer = await workbook.xlsx.writeBuffer();
    const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = nombreArchivo;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
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
        
        // ✅ DETECTAR SI ES PELIGROSO PARA AGREGAR ALERTA EN EL MODAL
        const esPeligroso = data.skus.some(sku => sku.id_familia == 998 || sku.id_familia == 999);
        let alertaPeligroso = esPeligroso ? `
            <div class="alert alert-danger d-flex align-items-center mb-3">
                <i class="bi bi-radiation fs-4 me-3"></i>
                <div>
                    <strong>⚠️ SOLICITACIÓN DE PRODUCTOS PELIGROSOS</strong><br>
                    <small>Esta solicitud contiene materiales que requieren manejo especializado (Clase 2 o 8).</small>
                </div>
            </div>
        ` : '';

        // 1. Bloque de Información General
        let html = `<div class="text-start mb-3 p-3 bg-light rounded">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>📋 Solicitud:</strong> #${idSolicitud}</p>
                                <p class="mb-1"><strong>📅 Fecha:</strong> ${data.fecha_solicitud ? new Date(data.fecha_solicitud).toLocaleString('es-CL') : '-'}</p>
                                <p class="mb-1"><strong>📦 Total SKUs:</strong> ${data.skus.length}</p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>📊 Estado:</strong> <span class="badge ${getBadgeClass(data.estado_general)}">${data.estado_general || 'PENDIENTE'}</span></p>
                                <p class="mb-1"><strong>📋 Motivo:</strong> <span class="badge bg-info text-dark">${data.motivo_carga || 'No especificado'}</span></p>
                                ${data.observaciones_generales ? `<p class="mb-0"><strong>📝 Observaciones:</strong> ${data.observaciones_generales}</p>` : ''}
                            </div>
                        </div>
                    </div>`;
        
        // 2. Alerta de Peligrosos (si aplica)
        html += alertaPeligroso;

        // 3. Bloque para mostrar el PDF si existe
        if (data.archivo_venta_pdf) {
            html += `
                <div class="alert alert-success border-0 d-flex align-items-center shadow-sm mb-3">
                    <i class="bi bi-file-earmark-pdf-fill text-danger fs-1 me-3"></i>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-bold text-success">Documento de Venta Adjunto</h6>
                        <small class="text-muted">Esta solicitud incluye un respaldo en PDF que valida la carga.</small>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="../${data.archivo_venta_pdf}" target="_blank" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-eye me-1"></i> Ver PDF
                        </a>
                        <a href="../${data.archivo_venta_pdf}" download class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-download me-1"></i> Descargar
                        </a>
                    </div>
                </div>
            `;
        }
        
        // 4. Advertencia de estado provisional
        html += `${esProvisional ? `<div class="alert alert-warning mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Atención:</strong> Esta solicitud está siendo procesada. Los estados mostrados son <strong>provisionales</strong>.</div>` : ''}`;
        
        // 5. Tabla de SKUs
        html += `<div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
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
                        <td>${sku.pv6 || 0}</td><td>${sku.pv3 || 0}</td><td>${sku.capacity || 0}</td><td>${Math.round(sku.lt) || 0}</td>
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
    } catch (error) { 
        console.error('Error cargando detalle:', error); 
        Swal.fire('Error', 'No se pudo cargar el detalle', 'error'); 
    }
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
// Confirmar antes de entrar a procesar (VERSIÓN UNIFICADA)
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
        icon: 'question', 
        showCancelButton: true, 
        confirmButtonColor: '#0056b3', 
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, iniciar procesamiento', 
        cancelButtonText: 'Cancelar'
    }).then(async (result) => {
        if (result.isConfirmed) {
            Swal.fire({ 
                title: 'Validando y cambiando estado...', 
                text: 'Un momento por favor', 
                allowOutsideClick: false, 
                didOpen: () => { Swal.showLoading(); } 
            });
            
            try {
                // ✅ LLAMADA ÚNICA: Valida permisos Y cambia el estado en el backend
                const response = await fetch('../api/iniciar_procesamiento.php', { 
                    method: 'POST', 
                    headers: { 'Content-Type': 'application/json' }, 
                    body: JSON.stringify({ id_solicitud: idSolicitud }) 
                });
                const data = await response.json();
                
                if (data.success) { 
                    Swal.close(); 
                    // Recargar la lista para reflejar el nuevo estado antes de ir a la página de procesamiento
                    await cargarSolicitudes(); 
                    window.location.href = `procesar_carga.php?id=${idSolicitud}`; 
                } else { 
                    Swal.close();
                    Swal.fire('Acceso Denegado', data.message || 'No tienes permiso para procesar esta solicitud', 'error');
                    // Recargar la tabla por si acaso
                    cargarSolicitudes();
                }
            } catch (error) { 
                console.error('Error:', error); 
                Swal.close();
                Swal.fire('Error', 'No se pudo conectar con el servidor', 'error'); 
            }
        }
    });
}

// ==========================================
// Rescatar Solicitudes Atrasadas (VERSIÓN CORREGIDA)
// ==========================================
async function rescatarSolicitudesAtrasadas() {
    const hoy = new Date();
    const hace7Dias = new Date();
    hace7Dias.setDate(hoy.getDate() - 7);
    
    const fechaInicio = hace7Dias.toISOString().split('T')[0] + ' 00:00:00';
    const fechaFin = hoy.toISOString().split('T')[0] + ' 23:59:59';
    const cicloNombre = hoy.toISOString().split('T')[0];

    Swal.fire({
        title: '¿Rescatar Solicitudes Atrasadas?',
        html: `
            <p>Se buscarán solicitudes <strong>procesadas</strong> en los últimos 7 días que no hayan sido exportadas en el ciclo normal.</p>
            <div class="alert alert-warning text-start">
                <strong>📅 Período de procesamiento:</strong><br>
                Desde: ${fechaInicio}<br>
                Hasta: ${fechaFin}<br><br>
                <i class="bi bi-info-circle"></i> Estas solicitudes se marcarán como <strong>"Excel Atrasado"</strong>.
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, generar Excel de Rescate',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#fd7e14',
        cancelButtonColor: '#6c757d'
    }).then(async (result) => {
        if (!result.isConfirmed) return;
        
        try {
            // 1. Validar si hay datos
            Swal.fire({
                title: 'Buscando solicitudes...',
                text: 'Verificando solicitudes procesadas en el período de rescate',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            const urlValidar = `../api/generar_excel_carga.php?tipo=rescate&ciclo=${cicloNombre}&fecha_inicio=${encodeURIComponent(fechaInicio)}&fecha_fin=${encodeURIComponent(fechaFin)}&validar=1`;
            const responseValidar = await fetch(urlValidar);
            const dataValidar = await responseValidar.json();
            
            if (!dataValidar.success || !dataValidar.tiene_datos) {
                Swal.close();
                Swal.fire({
                    icon: 'info',
                    title: 'Sin datos para rescatar',
                    html: dataValidar.message || 'No se encontraron solicitudes procesadas en los últimos 7 días para rescatar.',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#fd7e14'
                });
                return;
            }
            
            // 2. Obtener los datos JSON para generar el Excel
            Swal.fire({
                title: 'Generando Excel de Rescate...',
                text: 'Preparando archivo con formato',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
            
            const urlDatos = `../api/generar_excel_carga.php?tipo=rescate&ciclo=${cicloNombre}&fecha_inicio=${encodeURIComponent(fechaInicio)}&fecha_fin=${encodeURIComponent(fechaFin)}`;
            const responseDatos = await fetch(urlDatos);
            const data = await responseDatos.json();
            
            if (!data.success || !data.datos) {
                Swal.close();
                Swal.fire('Error', 'No se pudieron obtener los datos para generar el Excel', 'error');
                return;
            }
            
            // 3. Generar el Excel con ExcelJS
            generarExcelConExcelJS(data.datos, data.nombre_archivo);
            
            // 4. ✅ Marcar como exportadas en la BD (IMPORTANTE)
            const urlMarcar = `../api/generar_excel_carga.php?tipo=rescate&ciclo=${cicloNombre}&fecha_inicio=${encodeURIComponent(fechaInicio)}&fecha_fin=${encodeURIComponent(fechaFin)}&marcar=1`;
            const responseMarcar = await fetch(urlMarcar);
            const dataMarcar = await responseMarcar.json();
            
            console.log("Resultado al marcar:", dataMarcar);
            
            // 5. Recargar la tabla
            await cargarSolicitudes();
            
            Swal.fire({
                icon: 'success',
                title: '¡Rescate Exitoso!',
                text: 'El archivo se descargó y las solicitudes fueron marcadas como exportadas.',
                timer: 3000,
                showConfirmButton: false
            });
            
        } catch (error) {
            Swal.close();
            console.error("Error en rescate:", error);
            Swal.fire('Error', 'No se pudo completar el rescate: ' + error.message, 'error');
        }
    });
}