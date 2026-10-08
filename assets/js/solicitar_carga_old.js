// assets/js/solicitar_carga.js

document.addEventListener('DOMContentLoaded', async () => {
    console.log('✅ DOM cargado, inicializando...');
    
    // 1. Cargar familias (siempre)
    await cargarFamilias();
    
    // 2. Detectar modo edición
    const urlParams = new URLSearchParams(window.location.search);
    const idEditar = urlParams.get('editar');
    const esEdicion = idEditar && parseInt(idEditar) > 0;
    
    if (esEdicion) {
        console.log("📝 Modo edición activado para solicitud #" + idEditar);
        await inicializarModoEdicion(parseInt(idEditar));
    }
    
    // 3. Event listeners del formulario manual
    const formManual = document.getElementById('formSolicitudManual');
    if (formManual) {
        // ✅ ELIMINADO: El listener 'submit' porque ahora usamos onclick="mostrarPreviewSolicitud()" en los botones
        
        // Autocomplete dinámico (formulario normal)
        const inputSku = document.getElementById('sku');
        if (inputSku) {
            let timeoutId = null;
            
            inputSku.addEventListener('input', (e) => {
                const valor = e.target.value.trim();
                if (timeoutId) clearTimeout(timeoutId);
                
                if (valor.length < 2) {
                    const contenedor = document.getElementById('sugerenciasSKU');
                    if (contenedor) contenedor.style.display = 'none';
                    return;
                }
                
                timeoutId = setTimeout(() => {
                    buscarSugerenciasSKU(valor);
                }, 300);
            });
            
            document.addEventListener('click', (e) => {
                const contenedor = document.getElementById('sugerenciasSKU');
                if (contenedor && !inputSku.contains(e.target) && !contenedor.contains(e.target)) {
                    contenedor.style.display = 'none';
                }
            });
        }
    }
    
    // 4. Event listener del formulario de archivo
    const formArchivo = document.getElementById('formCargaArchivo');
    if (formArchivo) {
        formArchivo.addEventListener('submit', async (e) => {
            e.preventDefault();
            await procesarArchivoCarga();
        });
    }
});

// ==========================================
// Cargar familias
// ==========================================
async function cargarFamilias() {
    try {
        const response = await fetch('../api/obtener_familias.php');
        const data = await response.json();
        
        if (data.success && data.data) {
            const selectManual = document.getElementById('idFamilia');
            const selectArchivo = document.getElementById('idFamiliaArchivo');
            
            if (selectManual) selectManual.innerHTML = '<option value="">Seleccione la familia...</option>';
            if (selectArchivo) selectArchivo.innerHTML = '<option value="">Seleccione la familia...</option>';
            
            data.data.forEach(familia => {
                const texto = `${familia.id_familia} - ${familia.descripcion_familia}`;
                
                if (selectManual) {
                    const option = document.createElement('option');
                    option.value = familia.id_familia;
                    option.textContent = texto;
                    selectManual.appendChild(option);
                }
                
                if (selectArchivo) {
                    const option = document.createElement('option');
                    option.value = familia.id_familia;
                    option.textContent = texto;
                    selectArchivo.appendChild(option);
                }
            });
        }
    } catch (error) {
        console.error('Error cargando familias:', error);
    }
}

// ==========================================
// Inicializar Modo Edición
// ==========================================
async function inicializarModoEdicion(idEditar) {
    const titulo = document.querySelector('h1.h2, h2');
    if (titulo) titulo.textContent = `Editar Solicitud #${idEditar}`;
    
    const btnEnviar = document.getElementById('btnEnviarManual');
    if (btnEnviar) {
        btnEnviar.setAttribute('data-id-editar', idEditar);
        btnEnviar.classList.remove('btn-primary');
        btnEnviar.classList.add('btn-warning', 'text-dark');
        btnEnviar.innerHTML = '<i class="bi bi-eye me-2"></i>Revisar y Actualizar Solicitud';
    }
    
    try {
        const res = await fetch(`../api/obtener_detalle_solicitud.php?id=${idEditar}`);
        const texto = await res.text();
        
        let data;
        try {
            data = JSON.parse(texto);
        } catch (e) {
            console.error("JSON inválido:", texto);
            throw new Error("Respuesta inválida del servidor");
        }
        
        if (!data.success || !data.solicitud) {
            Swal.fire({
                icon: 'error',
                title: 'No se pudo cargar',
                text: data.message || 'La solicitud no existe o ya no está en estado pendiente.',
                confirmButtonText: 'Volver'
            }).then(() => {
                window.location.href = 'mis_solicitudes.php';
            });
            return;
        }
        
        const selectFamilia = document.getElementById('idFamilia');
        if (selectFamilia && data.solicitud.id_familia) {
            selectFamilia.value = data.solicitud.id_familia;
        }
        
        const obsInput = document.getElementById('observaciones');
        if (obsInput && data.solicitud.observaciones_generales) {
            obsInput.value = data.solicitud.observaciones_generales;
        }
        
        const skus = data.skus || [];
        const totalSKUs = skus.length;
        
        const mainContent = document.querySelector('main');
        if (mainContent) {
            const alertaHTML = `
                <div class="alert alert-warning border-start border-4 border-warning mb-3">
                    <h6 class="alert-heading mb-1"><i class="bi bi-pencil-square me-2"></i>Modo Edición</h6>
                    <p class="mb-0">Estás modificando la solicitud <strong>#${idEditar}</strong> que tiene <strong>${totalSKUs} SKU(s)</strong>.</p>
                    <small class="text-muted">Al guardar, los items anteriores se reemplazarán y volverán a evaluarse con el Árbol de Decisión.</small>
                </div>
            `;
            mainContent.insertAdjacentHTML('afterbegin', alertaHTML);
        }
        
        if (totalSKUs > 1) {
            construirTablaEdicionMultiple(skus, idEditar);
        } else if (totalSKUs === 1) {
            const primerSku = skus[0];
            const skuInput = document.getElementById('sku');
            const descInput = document.getElementById('descripcion');
            const cantInput = document.getElementById('cantidad');
            
            if (skuInput) skuInput.value = primerSku.sku || '';
            if (descInput) descInput.value = primerSku.descripcion_producto || '';
            if (cantInput) cantInput.value = primerSku.carga_solicitada || 1;
        }
        
    } catch (error) {
        console.error("Error cargando datos de edición:", error);
        Swal.fire('Error', 'No se pudieron cargar los datos de la solicitud', 'error');
    }
}

// ==========================================
// Construir Tabla de Edición Múltiple
// ==========================================
function construirTablaEdicionMultiple(skus, idEditar) {
    const formManual = document.getElementById('formSolicitudManual');
    if (formManual) formManual.style.display = 'none';
    
    const tabs = document.getElementById('myTab');
    if (tabs) tabs.style.display = 'none';
    const tabContent = document.getElementById('myTabContent');
    if (tabContent) tabContent.style.display = 'none';
    
    const contenedor = document.createElement('div');
    contenedor.className = 'card border-0 shadow-sm mb-4';
    contenedor.id = 'contenedorEdicionMultiple';
    
    let html = `
        <div class="card-body p-4">
            <div class="alert alert-info mb-3">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Modo Edición:</strong> Puedes modificar las cantidades o cambiar los SKUs. 
                Al escribir un nuevo SKU, la descripción se completará automáticamente.
            </div>
            <h5 class="mb-3"><i class="bi bi-list-ul me-2"></i>SKUs de la Solicitud</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 20%;">SKU</th>
                            <th style="width: 35%;">Descripción</th>
                            <th style="width: 20%;">Cantidad</th>
                            <th style="width: 10%;">Acción</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyEdicionSKUs">
    `;
    
    skus.forEach((sku, index) => {
        html += `
            <tr data-index="${index}">
                <td class="position-relative">
                    <input type="text" class="form-control form-control-sm sku-editable" 
                           value="${sku.sku || ''}" placeholder="Buscar SKU..." autocomplete="off">
                    <div class="sugerencias-sku position-absolute w-100" style="display: none; z-index: 1000; background: white; border: 1px solid #ddd; border-radius: 0 0 0.375rem 0.375rem; max-height: 200px; overflow-y: auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1);"></div>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm desc-editable" 
                           value="${sku.descripcion_producto || ''}" placeholder="Descripción">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm cant-editable" 
                           value="${sku.carga_solicitada || 1}" min="1" required>
                </td>
                <td>
                    <button type="button" class="btn btn-danger btn-sm" onclick="eliminarFilaSKU(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    html += `
                    </tbody>
                </table>
            </div>
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-outline-primary" onclick="agregarNuevaFilaSKU()">
                    <i class="bi bi-plus-circle me-2"></i>Agregar SKU
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="cancelarEdicion()">
                    <i class="bi bi-x-circle me-2"></i>Cancelar
                </button>
            </div>
            <button type="button" class="btn btn-warning w-100 text-dark" onclick="guardarEdicionMultiple(${idEditar})">
                <i class="bi bi-save me-2"></i>Guardar Cambios
            </button>
        </div>
    `;
    
    contenedor.innerHTML = html;
    
    const mainContent = document.querySelector('main');
    if (mainContent) {
        mainContent.appendChild(contenedor);
    }
    
    setTimeout(() => {
        inicializarAutocompleteEnTabla();
    }, 100);
}

// ==========================================
// Inicializar Autocomplete en la Tabla
// ==========================================
function inicializarAutocompleteEnTabla() {
    const inputsSKU = document.querySelectorAll('.sku-editable');
    inputsSKU.forEach(input => {
        inicializarAutocompleteEnInput(input);
    });
}

// ==========================================
// Inicializar Autocomplete en un Input Específico
// ==========================================
function inicializarAutocompleteEnInput(input) {
    let timeoutId = null;
    
    input.addEventListener('input', (e) => {
        const valor = e.target.value.trim();
        const fila = input.closest('tr');
        const descInput = fila.querySelector('.desc-editable, .desc-nueva');
        const sugerenciasDiv = fila.querySelector('.sugerencias-sku');
        
        if (timeoutId) clearTimeout(timeoutId);
        
        if (valor.length < 2) {
            if (sugerenciasDiv) sugerenciasDiv.style.display = 'none';
            return;
        }
        
        timeoutId = setTimeout(async () => {
            try {
                const response = await fetch(`../api/buscar_sku.php?q=${encodeURIComponent(valor)}`);
                const data = await response.json();
                
                if (sugerenciasDiv) {
                    sugerenciasDiv.innerHTML = '';
                    sugerenciasDiv.style.display = 'none';
                    
                    if (data.success && data.data && data.data.length > 0) {
                        data.data.forEach(producto => {
                            const item = document.createElement('div');
                            item.className = 'list-group-item list-group-item-action';
                            item.style.cssText = 'padding: 0.5rem 0.75rem; cursor: pointer; border: none; border-bottom: 1px solid #eee; background: white;';
                            item.innerHTML = `
                                <strong>${producto.sku}</strong><br>
                                <small class="text-muted">${producto.descripcion_producto}</small>
                            `;
                            
                            item.addEventListener('mouseenter', () => { item.style.backgroundColor = '#f8f9fa'; });
                            item.addEventListener('mouseleave', () => { item.style.backgroundColor = 'white'; });
                            
                            item.addEventListener('click', () => {
                                input.value = producto.sku;
                                if (descInput) descInput.value = producto.descripcion_producto || '';
                                sugerenciasDiv.style.display = 'none';
                            });
                            
                            sugerenciasDiv.appendChild(item);
                        });
                        sugerenciasDiv.style.display = 'block';
                    }
                }
            } catch (error) {
                console.error('Error buscando SKU:', error);
            }
        }, 300);
    });
    
    document.addEventListener('click', (e) => {
        if (!input.contains(e.target)) {
            const sugerenciasDiv = input.parentElement.querySelector('.sugerencias-sku');
            if (sugerenciasDiv) sugerenciasDiv.style.display = 'none';
        }
    });
}

// ==========================================
// Agregar Nueva Fila SKU
// ==========================================
function agregarNuevaFilaSKU() {
    const tbody = document.getElementById('tbodyEdicionSKUs');
    if (!tbody) return;
    
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="position-relative">
            <input type="text" class="form-control form-control-sm sku-nueva" placeholder="Buscar SKU..." autocomplete="off">
            <div class="sugerencias-sku position-absolute w-100" style="display: none; z-index: 1000; background: white; border: 1px solid #ddd; border-radius: 0 0 0.375rem 0.375rem; max-height: 200px; overflow-y: auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1);"></div>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm desc-nueva" placeholder="Descripción (se completa automáticamente)">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm cant-nueva" value="1" min="1" required>
        </td>
        <td>
            <button type="button" class="btn btn-danger btn-sm" onclick="eliminarFilaSKU(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(tr);
    
    setTimeout(() => {
        const nuevoInput = tr.querySelector('.sku-nueva');
        if (nuevoInput) {
            inicializarAutocompleteEnInput(nuevoInput);
        }
    }, 100);
}

// ==========================================
// Funciones auxiliares de la tabla
// ==========================================
function eliminarFilaSKU(boton) {
    const fila = boton.closest('tr');
    if (fila) fila.remove();
}

function cancelarEdicion() {
    window.location.href = 'mis_solicitudes.php';
}

// ==========================================
// Guardar Edición Múltiple
// ==========================================
async function guardarEdicionMultiple(idSolicitud) {
    const idFamilia = document.getElementById('idFamilia').value;
    const observaciones = document.getElementById('observaciones').value;
    
    if (!idFamilia) {
        Swal.fire('Campo requerido', 'Debe seleccionar la familia', 'warning');
        return;
    }
    
    const skus = [];
    const filas = document.querySelectorAll('#tbodyEdicionSKUs tr');
    
    filas.forEach(fila => {
        const skuInput = fila.querySelector('.sku-editable') || fila.querySelector('.sku-nueva');
        const descInput = fila.querySelector('.desc-editable') || fila.querySelector('.desc-nueva');
        const cantInput = fila.querySelector('.cant-editable') || fila.querySelector('.cant-nueva');
        
        if (skuInput && cantInput) {
            const sku = skuInput.value.trim();
            const cantidad = parseInt(cantInput.value) || 0;
            
            if (sku && cantidad > 0) {
                skus.push({ sku: sku, descripcion: descInput ? descInput.value.trim() : '', cantidad: cantidad });
            }
        }
    });
    
    if (skus.length === 0) {
        Swal.fire('Error', 'Debe haber al menos un SKU válido', 'warning');
        return;
    }
    
    const confirm = await Swal.fire({
        title: '¿Guardar cambios?',
        html: `Se actualizarán <strong>${skus.length} SKU(s)</strong>. Los items anteriores se reemplazarán y se re-evaluarán.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#ffc107',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, guardar',
        cancelButtonText: 'Cancelar'
    });
    
    if (!confirm.isConfirmed) return;
    
    Swal.fire({ title: 'Guardando...', text: 'Evaluando SKUs con el Árbol de Decisión', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    
    try {
        const response = await fetch('../api/actualizar_solicitud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_solicitud: parseInt(idSolicitud), observaciones: observaciones, skus: skus })
        });
        
        const texto = await response.text();
        let data;
        try { data = JSON.parse(texto); } catch (e) { throw new Error("Respuesta inválida del servidor"); }
        
        if (data.success) {
            Swal.fire({ icon: 'success', title: '¡Solicitud actualizada!', text: data.message, timer: 2500, showConfirmButton: false })
                .then(() => { window.location.href = 'mis_solicitudes.php?actualizado=1'; });
        } else {
            Swal.fire('Error', data.message || 'No se pudo actualizar', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire('Error', error.message || 'Error del servidor', 'error');
    }
}

// ==========================================
// Buscar sugerencias de SKU (formulario normal)
// ==========================================
async function buscarSugerenciasSKU(termino) {
    const contenedor = document.getElementById('sugerenciasSKU');
    if (!contenedor) return;
    
    try {
        const response = await fetch(`../api/buscar_sku.php?q=${encodeURIComponent(termino)}`);
        const data = await response.json();
        
        contenedor.innerHTML = '';
        
        if (data.success && data.data && data.data.length > 0) {
            data.data.forEach(producto => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action';
                item.innerHTML = `<div class="d-flex w-100 justify-content-between"><strong class="mb-1">${producto.sku}</strong></div><small class="text-muted">${producto.descripcion_producto}</small>`;
                
                item.addEventListener('click', () => {
                    document.getElementById('sku').value = producto.sku;
                    document.getElementById('descripcion').value = producto.descripcion_producto || '';
                    contenedor.style.display = 'none';
                });
                
                contenedor.appendChild(item);
            });
            contenedor.style.display = 'block';
        } else {
            contenedor.style.display = 'none';
        }
    } catch (error) {
        console.error('Error buscando sugerencias:', error);
        contenedor.style.display = 'none';
    }
}

// ==========================================
// 1. Mostrar Vista Previa antes de Enviar
// ==========================================
async function mostrarPreviewSolicitud() {
    const familiaSelect = document.getElementById('idFamilia'); 
    const familiaTexto = familiaSelect ? familiaSelect.options[familiaSelect.selectedIndex].text : 'No seleccionada';
    
    const sku = document.getElementById('sku')?.value || '';
    const descripcion = document.getElementById('descripcion')?.value || 'Sin descripción';
    const cantidad = document.getElementById('cantidad')?.value || '0';
    const observaciones = document.getElementById('observaciones')?.value || '';

    if (!sku || parseInt(cantidad) <= 0) {
        Swal.fire({ 
            icon: 'warning', 
            title: 'Campos incompletos', 
            text: 'Por favor ingresa un SKU válido y una cantidad mayor a 0.',
            confirmButtonColor: '#0d6efd'
        });
        return;
    }

    // Mostrar loading
    Swal.fire({
        title: 'Cargando detalles...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    try {
        // Obtener detalles completos del producto
        const response = await fetch(`../api/obtener_detalle_producto.php?sku=${encodeURIComponent(sku)}`);
        const data = await response.json();
        
        Swal.close();
        
        if (!data.success) {
            Swal.fire({
                icon: 'error',
                title: 'Producto Fuera de Mix',
                html: 'Se debe solicitar conexión al <strong>Jefe de Línea</strong> para poder generar la carga de este producto.',
                confirmButtonText: 'Aceptar',
                confirmButtonColor: '#dc3545'
            });
            return;
        }

        const producto = data.producto;
        
        // Calcular pallets
        const unidPallet = parseFloat(producto.unid_pallet) || 1;
        const palletsExactos = parseFloat(cantidad) > 0 ? (parseFloat(cantidad) / unidPallet) : 0;
        
        // ✅ NUEVO: Calcular SDS Proyectado para mostrar en la tabla
        const stockActual = (parseFloat(producto.disp_tda) || 0) + (parseFloat(producto.pend_tda) || 0);
        const pv6 = parseFloat(producto.PV6) || 0;
        const cantidadSolicitada = parseFloat(cantidad) || 0;
        const stockProyectado = stockActual + cantidadSolicitada;
        const sdsProyectada = pv6 > 0 ? (stockProyectado / pv6) : 999;
        
        // Llenar datos básicos
        document.getElementById('confirm_familia').textContent = familiaTexto;
        document.getElementById('confirm_tienda').textContent = (typeof NOMBRE_TIENDA !== 'undefined' ? NOMBRE_TIENDA : 'Tienda') + (typeof ID_TIENDA !== 'undefined' && ID_TIENDA ? ` (ID: ${ID_TIENDA})` : '');
        
        // Construir tabla con TODOS los detalles 
        const tbody = document.getElementById('confirm_tabla_detalle');
        tbody.innerHTML = `
            <tr>
                <td class="text-center"><strong>${producto.sku}</strong></td>
                <td class="text-truncate" title="${producto.descripcion_producto || descripcion}">${producto.descripcion_producto || descripcion}</td>
                <td class="text-center"><strong>${producto.v6 || 0}</strong></td>
                <td class="text-center"><strong>${producto.v5 || 0}</strong></td>
                <td class="text-center"><strong>${producto.v4 || 0}</strong></td>
                <td class="text-center"><strong>${producto.v3 || 0}</strong></td>
                <td class="text-center"><strong>${producto.v2 || 0}</strong></td>
                <td class="text-center"><strong>${producto.v1 || 0}</strong></td>
                <td class="text-center"><strong>${producto.PV6 || 0}</strong></td>
                <td class="text-center">${producto.PV3 || 0}</td>
                <td class="text-center">${producto.capacity || 0}</td>
                <td class="text-center">${producto.lt || 0}</td>
                <td class="text-center">${producto.disp_tda || 0}</td>
                <td class="text-center">${producto.pend_tda || 0}</td>
                
                <!-- ✅ AQUÍ SE MUESTRA EL SDS PROYECTADO (Rojo si supera 12) -->
                <td class="text-center">
                    <strong class="${sdsProyectada > 12 ? 'text-danger' : ''}">${sdsProyectada.toFixed(1)}</strong>
                </td>
                
                <td class="text-center"><strong class="text-primary fs-6">${cantidad}</strong></td>
                <td class="text-center">
                    <strong class="text-primary">${palletsExactos.toFixed(3)}</strong><br>
                    <small class="text-muted" style="font-size: 10px;">(${unidPallet} u/pl)</small>
                </td>
                <td class="text-center">${producto.md_defecto || '-'}</td>
            </tr>
        `;

        // Mostrar u ocultar observaciones
        if (observaciones.trim()) {
            document.getElementById('confirm_observaciones_container').style.display = 'block';
            document.getElementById('confirm_observaciones').textContent = observaciones;
        } else {
            document.getElementById('confirm_observaciones_container').style.display = 'none';
        }

        // Mostrar el modal
        const modal = new bootstrap.Modal(document.getElementById('modalConfirmacion'));
        modal.show();
        
    } catch (error) {
        console.error('Error:', error);
        Swal.close();
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'No se pudieron cargar los detalles del producto'
        });
    }
}

// Función auxiliar para mostrar modal sin detalles (cuando no hay datos en sugerido)
function mostrarModalSinDetalles(familiaTexto, sku, descripcion, cantidad, observaciones) {
    document.getElementById('confirm_familia').textContent = familiaTexto;
    document.getElementById('confirm_tienda').textContent = NOMBRE_TIENDA + (ID_TIENDA ? ` (ID: ${ID_TIENDA})` : '');
    
    const tbody = document.getElementById('confirm_tabla_detalle');
    tbody.innerHTML = `
        <tr>
            <td><strong>${sku}</strong></td>
            <td>${descripcion}</td>
            <td colspan="11" class="text-center text-muted">
                <i class="bi bi-info-circle me-2"></i>Sin datos disponibles en el sugerido diario
            </td>
        </tr>
    `;

    if (observaciones.trim()) {
        document.getElementById('confirm_observaciones_container').style.display = 'block';
        document.getElementById('confirm_observaciones').textContent = observaciones;
    } else {
        document.getElementById('confirm_observaciones_container').style.display = 'none';
    }

    const modal = new bootstrap.Modal(document.getElementById('modalConfirmacion'));
    modal.show();
}

// ==========================================
// 2. Confirmar y Enviar al Servidor (AJAX) - ACTUALIZADO PARA SOPORTAR EDICIÓN
// ==========================================
async function confirmarYEnviarSolicitud() {
    const modalEl = document.getElementById('modalConfirmacion');
    const modalInstance = bootstrap.Modal.getInstance(modalEl);
    if (modalInstance) modalInstance.hide();
    
    Swal.fire({ title: 'Procesando...', text: 'Por favor espera un momento', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

    // Detectar si es modo edición
    const btn = document.getElementById('btnEnviarManual');
    const idEditar = btn ? btn.getAttribute('data-id-editar') : "0";
    const esEdicion = idEditar && parseInt(idEditar) > 0;

    const formData = {
        id_familia: document.getElementById('idFamilia')?.value || '',
        sku: document.getElementById('sku')?.value || '',
        descripcion: document.getElementById('descripcion')?.value || '',
        cantidad: parseInt(document.getElementById('cantidad')?.value || 0),
        observaciones: document.getElementById('observaciones')?.value || ''
    };

    // Determinar URL y payload según si es edición o nuevo
    let url = '../api/solicitar_carga.php';
    let payload = formData;

    if (esEdicion) {
        url = '../api/actualizar_solicitud.php';
        payload = {
            id_solicitud: parseInt(idEditar),
            observaciones: formData.observaciones,
            skus: [{
                sku: formData.sku,
                cantidad: formData.cantidad,
                descripcion: formData.descripcion
            }]
        };
    }

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        const data = await response.json();

        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: esEdicion ? '¡Solicitud Actualizada!' : '¡Solicitud Creada!',
                html: esEdicion ? 'Los cambios han sido guardados y re-evaluados.' : `La solicitud <strong>#${data.id_solicitud}</strong> ha sido registrada exitosamente.`,
                confirmButtonColor: '#198754',
                timer: 3000,
                timerProgressBar: true
            }).then(() => {
                window.location.href = 'mis_solicitudes.php?actualizado=1';
            });
        } else {
            Swal.fire({ icon: 'error', title: 'Error', html: data.message || 'Ocurrió un error inesperado.', confirmButtonColor: '#dc3545' });
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({ icon: 'error', title: 'Error de conexión', text: 'No se pudo comunicar con el servidor. Intenta nuevamente.', confirmButtonColor: '#dc3545' });
    }
}

// ==========================================
// Procesar archivo CSV
// ==========================================
async function procesarArchivoCarga() {
    console.log('🔄 Iniciando procesamiento de archivo...');
    const form = document.getElementById('formCargaArchivo');
    const formData = new FormData(form);
    
    const idFamilia = formData.get('id_familia');
    if (!idFamilia) {
        Swal.fire('Campo requerido', 'Debe seleccionar la familia', 'warning');
        return;
    }
    
    const btn = document.getElementById('btnProcesarArchivo');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Procesando...';
    
    try {
        const response = await fetch('../api/procesar_archivo_carga.php', { method: 'POST', body: formData });
        const texto = await response.text();
        
        let data;
        try { data = JSON.parse(texto); } catch (e) { throw new Error("El servidor devolvió una respuesta inválida."); }
        
        if (data.success) {
            mostrarResumenProcesamiento(data);
        } else {
            Swal.fire('Error', data.message || 'Ocurrió un error inesperado.', 'error');
        }
    } catch (error) {
        console.error('💥 Error en procesarArchivoCarga:', error);
        Swal.fire({ icon: 'error', title: 'Error de conexión o formato', text: error.message, confirmButtonColor: '#dc3545' });
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-upload me-2"></i>Procesar Archivo de Carga';
    }
}

// ==========================================
// Mostrar Resumen de Procesamiento (MODAL)
// ==========================================
function mostrarResumenProcesamiento(data) {
    const modalElement = document.getElementById('modalResumenProcesamiento');
    if (!modalElement) {
        Swal.fire('Error', 'No se pudo mostrar el resumen. Recarga la página.', 'error');
        return;
    }
    
    const detalle = data.detalle || [];
    const aprobados = detalle.filter(item => item.estado === 'GUARDADO');
    const rechazados = detalle.filter(item => item.estado === 'RECHAZADO');
    
    document.getElementById('totalProcesados').textContent = detalle.length;
    document.getElementById('countAprobados').textContent = aprobados.length;
    document.getElementById('countRechazados').textContent = rechazados.length;
    
    const tbodyAprobados = document.getElementById('tbodyAprobados');
    tbodyAprobados.innerHTML = '';
    if (aprobados.length === 0) {
        tbodyAprobados.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">No hay SKUs aprobados</td></tr>';
    } else {
        aprobados.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td><code>${item.sku}</code></td><td>${item.descripcion || 'Sin descripción'}</td><td class="text-end"><strong>${item.cantidad}</strong></td>`;
            tbodyAprobados.appendChild(tr);
        });
    }
    
    const tbodyRechazados = document.getElementById('tbodyRechazados');
    tbodyRechazados.innerHTML = '';
    if (rechazados.length === 0) {
        tbodyRechazados.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">¡Todos los SKUs fueron aprobados!</td></tr>';
    } else {
        rechazados.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td><code>${item.sku}</code></td><td>${item.descripcion || 'Sin descripción'}</td><td class="text-end">${item.cantidad}</td><td><small class="text-danger">${item.motivo || 'No especificado'}</small></td>`;
            tbodyRechazados.appendChild(tr);
        });
    }
    
    try {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    } catch (error) {
        console.error('❌ Error al mostrar el modal:', error);
        Swal.fire('Error', 'No se pudo mostrar el modal: ' + error.message, 'error');
    }
}