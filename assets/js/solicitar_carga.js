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
    
    // 3. Event listeners del formulario manual (solo si NO estamos en edición múltiple)
    const formManual = document.getElementById('formSolicitudManual');
    if (formManual) {
        formManual.addEventListener('submit', async (e) => {
            e.preventDefault();
            await enviarSolicitudManual();
        });
        
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
    // 1. Cambiar título
    const titulo = document.querySelector('h1.h2, h2');
    if (titulo) titulo.textContent = `Editar Solicitud #${idEditar}`;
    
    // 2. Cambiar botón del formulario manual (por si acaso se usa)
    const btnEnviar = document.getElementById('btnEnviarManual');
    if (btnEnviar) {
        btnEnviar.setAttribute('data-id-editar', idEditar);
        btnEnviar.classList.remove('btn-primary');
        btnEnviar.classList.add('btn-warning', 'text-dark');
        btnEnviar.innerHTML = '<i class="bi bi-save me-2"></i>Actualizar Solicitud';
    }
    
    // 3. Cargar datos
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
        
        // Precargar familia
        const selectFamilia = document.getElementById('idFamilia');
        if (selectFamilia && data.solicitud.id_familia) {
            selectFamilia.value = data.solicitud.id_familia;
        }
        
        // Precargar observaciones
        const obsInput = document.getElementById('observaciones');
        if (obsInput && data.solicitud.observaciones_generales) {
            obsInput.value = data.solicitud.observaciones_generales;
        }
        
        const skus = data.skus || [];
        const totalSKUs = skus.length;
        
        // Mostrar alerta de modo edición
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
        
        // Si hay múltiples SKUs, mostrar tabla de edición
        if (totalSKUs > 1) {
            construirTablaEdicionMultiple(skus, idEditar);
        } else if (totalSKUs === 1) {
            // Edición simple: precargar el único SKU en el formulario
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
    // Ocultar formulario manual simple
    const formManual = document.getElementById('formSolicitudManual');
    if (formManual) formManual.style.display = 'none';
    
    // Ocultar pestañas si existen
    const tabs = document.getElementById('myTab');
    if (tabs) tabs.style.display = 'none';
    const tabContent = document.getElementById('myTabContent');
    if (tabContent) tabContent.style.display = 'none';
    
    // Crear contenedor de tabla
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
    
    // ✅ AGREGAR AUTOCOMPLETE A TODOS LOS INPUTS DE SKU
    setTimeout(() => {
        inicializarAutocompleteEnTabla();
    }, 100);
}

// ==========================================
// Inicializar Autocomplete en la Tabla (REUTILIZA buscar_sku.php)
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
                // ✅ REUTILIZAMOS LA MISMA API buscar_sku.php
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
                            
                            item.addEventListener('mouseenter', () => {
                                item.style.backgroundColor = '#f8f9fa';
                            });
                            item.addEventListener('mouseleave', () => {
                                item.style.backgroundColor = 'white';
                            });
                            
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
// Agregar Nueva Fila SKU (con autocomplete)
// ==========================================
function agregarNuevaFilaSKU() {
    const tbody = document.getElementById('tbodyEdicionSKUs');
    if (!tbody) return;
    
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="position-relative">
            <input type="text" class="form-control form-control-sm sku-nueva" 
                   placeholder="Buscar SKU..." autocomplete="off">
            <div class="sugerencias-sku position-absolute w-100" style="display: none; z-index: 1000; background: white; border: 1px solid #ddd; border-radius: 0 0 0.375rem 0.375rem; max-height: 200px; overflow-y: auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1);"></div>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm desc-nueva" 
                   placeholder="Descripción (se completa automáticamente)">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm cant-nueva" 
                   value="1" min="1" required>
        </td>
        <td>
            <button type="button" class="btn btn-danger btn-sm" onclick="eliminarFilaSKU(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(tr);
    
    // ✅ Inicializar autocomplete en la nueva fila
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
    
    // Recopilar todos los SKUs de la tabla
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
                skus.push({
                    sku: sku,
                    descripcion: descInput ? descInput.value.trim() : '',
                    cantidad: cantidad
                });
            }
        }
    });
    
    if (skus.length === 0) {
        Swal.fire('Error', 'Debe haber al menos un SKU válido', 'warning');
        return;
    }
    
    // Confirmar
    const confirm = await Swal.fire({
        title: '¿Guardar cambios?',
        html: `Se actualizarán <strong>${skus.length} SKU(s)</strong>. Los items anteriores se reemplazarán y se re-evaluarán con el Árbol de Decisión.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#ffc107',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, guardar',
        cancelButtonText: 'Cancelar'
    });
    
    if (!confirm.isConfirmed) return;
    
    Swal.fire({
        title: 'Guardando...',
        text: 'Evaluando SKUs con el Árbol de Decisión',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    
    try {
        const response = await fetch('../api/actualizar_solicitud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_solicitud: parseInt(idSolicitud),
                observaciones: observaciones,
                skus: skus
            })
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
                title: '¡Solicitud actualizada!',
                text: data.message,
                timer: 2500,
                showConfirmButton: false
            }).then(() => {
                window.location.href = 'mis_solicitudes.php?actualizado=1';
            });
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
                item.innerHTML = `
                    <div class="d-flex w-100 justify-content-between">
                        <strong class="mb-1">${producto.sku}</strong>
                    </div>
                    <small class="text-muted">${producto.descripcion_producto}</small>
                `;
                
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
// Enviar solicitud manual (modo normal o edición simple)
// ==========================================
async function enviarSolicitudManual() {
    const btn = document.getElementById('btnEnviarManual');
    
    // Detectar si es modo edición
    const idEditar = btn.getAttribute('data-id-editar');
    const esEdicion = idEditar && parseInt(idEditar) > 0;

    const idFamilia = document.getElementById('idFamilia').value;
    const sku = document.getElementById('sku').value.trim();
    const cantidad = parseInt(document.getElementById('cantidad').value) || 0;
    const descripcion = document.getElementById('descripcion').value.trim();
    const observaciones = document.getElementById('observaciones').value.trim();

    if (!idFamilia) {
        Swal.fire('Campo requerido', 'Debe seleccionar la familia', 'warning');
        return;
    }
    if (!sku || cantidad <= 0) {
        Swal.fire('Campos incompletos', 'Verifica el SKU y la cantidad', 'warning');
        return;
    }

    // Confirmación
    const tituloConfirm = esEdicion ? '¿Actualizar solicitud?' : '¿Enviar solicitud?';
    const textoConfirm = esEdicion 
        ? 'El item actual se reemplazará y se re-evaluará con el Árbol de Decisión.' 
        : `Se enviará la solicitud de ${cantidad} unidad(es) del SKU ${sku}.`;

    const confirm = await Swal.fire({
        title: tituloConfirm,
        text: textoConfirm,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: esEdicion ? '#ffc107' : '#0d6efd',
        cancelButtonColor: '#6c757d',
        confirmButtonText: esEdicion ? 'Sí, actualizar' : 'Sí, enviar',
        cancelButtonText: 'Cancelar'
    });

    if (!confirm.isConfirmed) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Procesando...';

    try {
        let url, payload;

        if (esEdicion) {
            url = '../api/actualizar_solicitud.php';
            payload = {
                id_solicitud: parseInt(idEditar),
                observaciones: observaciones,
                skus: [{
                    sku: sku,
                    cantidad: cantidad,
                    descripcion: descripcion
                }]
            };
        } else {
            url = '../api/solicitar_carga.php';
            payload = {
                sku: sku,
                cantidad: cantidad,
                descripcion: descripcion,
                id_familia: idFamilia,
                observaciones: observaciones
            };
        }

        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
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
                title: esEdicion ? '¡Solicitud actualizada!' : '¡Solicitud creada!',
                text: data.message,
                timer: 2500,
                showConfirmButton: false
            }).then(() => {
                window.location.href = 'mis_solicitudes.php?actualizado=1';
            });
        } else {
            if (data.tipo_error === 'SOLICITUD_PENDIENTE' || data.tipo_error === 'SKU_PENDIENTE') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Solicitud en proceso',
                    html: `<div class="text-start"><p>${data.message}</p></div>`,
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#ffc107'
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire('Error', error.message || 'Error del servidor', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = esEdicion 
            ? '<i class="bi bi-save me-2"></i>Actualizar Solicitud' 
            : '<i class="bi bi-send me-2"></i>Enviar Solicitud';
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
        const response = await fetch('../api/procesar_archivo_carga.php', {
            method: 'POST',
            body: formData
        });
        
        const texto = await response.text();
        
        let data;
        try {
            data = JSON.parse(texto);
        } catch (e) {
            throw new Error("El servidor devolvió una respuesta inválida. Revisa la consola.");
        }
        
        if (data.success) {
            mostrarResumenProcesamiento(data);
        } else {
            if (data.tipo_error === 'SOLICITUD_PENDIENTE' || data.tipo_error === 'SKU_PENDIENTE') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Ya tienes una solicitud en proceso',
                    html: `<div class="text-start"><p>${data.message}</p></div>`,
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#ffc107'
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        }
    } catch (error) {
        console.error('💥 Error en procesarArchivoCarga:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión o formato',
            text: error.message,
            confirmButtonColor: '#dc3545'
        });
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
        console.error('❌ El modal no existe en el DOM');
        Swal.fire('Error', 'No se pudo mostrar el resumen. Recarga la página.', 'error');
        return;
    }
    
    const detalle = data.detalle || [];
    const aprobados = detalle.filter(item => item.estado === 'GUARDADO');
    const rechazados = detalle.filter(item => item.estado === 'RECHAZADO');
    
    document.getElementById('totalProcesados').textContent = detalle.length;
    document.getElementById('countAprobados').textContent = aprobados.length;
    document.getElementById('countRechazados').textContent = rechazados.length;
    
    // Llenar tabla de aprobados
    const tbodyAprobados = document.getElementById('tbodyAprobados');
    tbodyAprobados.innerHTML = '';
    if (aprobados.length === 0) {
        tbodyAprobados.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">No hay SKUs aprobados</td></tr>';
    } else {
        aprobados.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><code>${item.sku}</code></td>
                <td>${item.descripcion || 'Sin descripción'}</td>
                <td class="text-end"><strong>${item.cantidad}</strong></td>
            `;
            tbodyAprobados.appendChild(tr);
        });
    }
    
    // Llenar tabla de rechazados
    const tbodyRechazados = document.getElementById('tbodyRechazados');
    tbodyRechazados.innerHTML = '';
    if (rechazados.length === 0) {
        tbodyRechazados.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">¡Todos los SKUs fueron aprobados!</td></tr>';
    } else {
        rechazados.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><code>${item.sku}</code></td>
                <td>${item.descripcion || 'Sin descripción'}</td>
                <td class="text-end">${item.cantidad}</td>
                <td><small class="text-danger">${item.motivo || 'No especificado'}</small></td>
            `;
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