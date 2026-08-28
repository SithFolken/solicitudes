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

                const puedeProcesar = (estadoTexto !== 'PROCESADA' && estadoTexto !== 'PROCESADA_PARCIAL' && estadoTexto !== 'RECHAZADA');

                // ✅ Badge de Excel (solo si ya fue generado)
                let badgeExcel = '';
                if (sol.fecha_generacion_excel) {
                    badgeExcel = `<span class="badge bg-success ms-2" title="Incluido en Excel el ${sol.fecha_generacion_excel}"><i class="bi bi-check-circle me-1"></i>Excel</span>`;
                }

                // ✅ Botón de acción
                let btnAccion = '';
                if (puedeProcesar) {
                    btnAccion = `<button class="btn btn-primary btn-sm" onclick="irAProcesar(${sol.id_solicitud})">
                                    <i class="bi bi-gear me-1"></i> Procesar
                                 </button>`;
                } else {
                    btnAccion = `<button class="btn btn-secondary btn-sm" disabled>
                                    <i class="bi bi-check-circle me-1"></i> ${estadoTexto === 'RECHAZADA' ? 'Rechazada' : 'Procesada'}
                                 </button>`;
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
// Acciones
// ==========================================
function irAProcesar(idSolicitud) {
    window.location.href = `procesar_carga.php?id=${idSolicitud}`;
}

// ✅ FUNCIÓN PRINCIPAL: Exportar Excel del Ciclo (Asignada al botón de arriba)
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
            // 1. Mostrar cargando
            Swal.fire({
                title: 'Verificando datos...',
                text: 'Buscando solicitudes procesadas en este ciclo',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            // 2. Validar primero con fetch (sin descargar aún)
            fetch(`../api/generar_excel_carga.php?ciclo=${cicloCorte}&validar=1`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.tiene_datos) {
                        // 3. Si hay datos, cerrar loading y descargar
                        Swal.close();
                        window.open(`../api/generar_excel_carga.php?ciclo=${cicloCorte}`, '_blank');
                        
                        Swal.fire({
                            icon: 'success',
                            title: '¡Excel Generado!',
                            text: 'Las solicitudes procesadas han sido marcadas como incluidas en Excel.',
                            timer: 3000,
                            showConfirmButton: false
                        }).then(() => {
                            cargarSolicitudes(); // Recargar tabla para mostrar badges verdes
                        });
                    } else {
                        // 4. Si NO hay datos, mostrar el error claramente
                        Swal.close();
                        Swal.fire({
                            icon: 'warning',
                            title: 'Sin datos para exportar',
                            html: data.message || 'No se encontraron solicitudes procesadas para este ciclo.<br><br><strong>Posibles causas:</strong><br>1. No has procesado ninguna solicitud aún.<br>2. El estado de la solicitud no es PROCESADA o PROCESADA_PARCIAL.<br>3. Falta actualizar el archivo <code>guardar_procesamiento.php</code> para guardar el <code>ciclo_corte</code>.',
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