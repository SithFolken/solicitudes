// ==========================================
// procesar_carga.js - Sistema de Cargas
// ==========================================

// Obtener ID de la solicitud (ya viene de la sesión vía PHP)
const urlParams = new URLSearchParams(window.location.search);
const idSolicitud = urlParams.get('id') || 0;

document.addEventListener('DOMContentLoaded', () => {
    if (idSolicitud > 0) {
        cargarSKUs();
    } else {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'No se especificó una solicitud válida',
            confirmButtonColor: '#0056b3'
        }).then(() => {
            window.location.href = 'gestionar_cargas.php';
        });
    }
});

// ==========================================
// Cargar SKUs de la Solicitud
// ==========================================
async function cargarSKUs() {
    const tbody = document.getElementById('tbodySKUs');
    
    try {
        const response = await fetch(`../api/obtener_detalle_solicitud.php?id=${idSolicitud}`);
        const data = await response.json();
        
        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="24" class="text-center py-4 text-danger">${data.message || 'Error al cargar'}</td></tr>`;
            return;
        }
        
        tbody.innerHTML = '';
        
        if (!data.skus || data.skus.length === 0) {
            tbody.innerHTML = '<tr><td colspan="24" class="text-center py-4 text-muted">No hay SKUs en esta solicitud</td></tr>';
            return;
        }
        
        data.skus.forEach(item => {
            const tr = document.createElement('tr');
            
            // ✅ CALCULAR PALLETS (con fracción exacta)
            const unidPallet = parseFloat(item.unid_pallet) || 1;
            const cantSolicitada = parseFloat(item.carga_solicitada) || 0;

            // Calcular pallets exactos (con decimales)
            const palletsExactos = cantSolicitada > 0 ? (cantSolicitada / unidPallet) : 0;
            const palletsRedondeados = Math.ceil(palletsExactos); // Para referencia
            
            tr.innerHTML = `
                <td><strong>${item.sku}</strong></td>
                
                <!-- TIENDA -->
                <td class="tienda-cell">
                    <div class="text-center">
                        <div class="tienda-nombre" style="font-size: 11px; font-weight: 700; color: #0056b3;">
                            ${item.nombre_tienda || 'N/A'}
                        </div>
                        <div class="tienda-id" style="font-size: 9px; color: #6c757d;">
                            ID: ${item.id_tienda || '-'}
                        </div>
                    </div>
                </td>
                
                <td class="text-start">${item.descripcion_producto || '-'}</td>
                
                <td class="bg-pv">${item.pv6 || '0'}</td>
                <td class="bg-pv">${item.pv3 || '0'}</td>
                <td class="bg-pv">${item.min || '0'}</td>
                <td class="bg-pv">${item.lt || '0'}</td>
                
                <td class="bg-inv">${item.disp_tda || '0'}</td>
                <td class="bg-inv">${item.pend_tda || '0'}</td>
                <td class="bg-inv">${item.disp_bod || '0'}</td>
                <td class="bg-inv">${item.pend_bod || '0'}</td>
                
                <td class="bg-ventas">${item.v6 || '0'}</td>
                <td class="bg-ventas">${item.v5 || '0'}</td>
                <td class="bg-ventas">${item.v4 || '0'}</td>
                <td class="bg-ventas">${item.v3 || '0'}</td>
                <td class="bg-ventas">${item.v2 || '0'}</td>
                <td class="bg-ventas">${item.v1 || '0'}</td>
                
                <td class="bg-sds"><strong>${item.sds_actual || '0'}</strong></td>
                <td class="bg-sds"><strong>${item.sds_carga || '0'}</strong></td>
                
                <td><strong>${item.carga_solicitada || '0'}</strong></td>
                <td>
                    <input type="number" class="form-control form-control-sm input-cantidad" 
                           data-id="${item.id_detalle}" value="${item.carga_solicitada || '0'}" min="0" step="1">
                </td>
                
                <!-- PALLETS -->
                <td>
                    <div class="text-center">
                        <strong class="text-primary" style="font-size: 13px;">
                            ${palletsExactos.toFixed(3)}
                        </strong>
                        <br>
                        <small class="text-muted" style="font-size: 9px;">
                            (${cantSolicitada}/${unidPallet})
                        </small>
                        ${palletsExactos < 1 ? `
                            <br>
                            <small class="text-info" style="font-size: 8px;">
                                ${Math.round(palletsExactos * 100)}% de un pallet
                            </small>
                        ` : ''}
                    </div>
                </td>
                            
                <td>
                    <span class="badge bg-info text-dark badge-md-default">${item.md_defecto_sku || 'N/A'}</span>
                </td>
                
                <td>
                    <select class="form-select form-select-sm select-md" data-id="${item.id_detalle}" data-md-defecto="${item.md_defecto_sku || ''}">
                        <option value="">Seleccione...</option>
                        <option value="TRANSFERENCIA" ${item.md_defecto_sku === 'TRANSFERENCIA' ? 'selected' : ''}>Transferencia</option>
                        <option value="CROSS_DOCKING" ${item.md_defecto_sku === 'CROSS_DOCKING' ? 'selected' : ''}>Cross Docking</option>
                        <option value="COMPRA_LOCAL" ${item.md_defecto_sku === 'COMPRA_LOCAL' ? 'selected' : ''}>Compra Local</option>
                    </select>
                </td>
                
                <td><input type="checkbox" class="form-check-input checkbox-no-cargar" data-id="${item.id_detalle}"></td>
                <td><input type="text" class="form-control form-control-sm input-motivo" data-id="${item.id_detalle}" placeholder="Motivo..." value="${item.campo_cambios || ''}"></td>
            `;
            tbody.appendChild(tr);
        });
    } catch (error) {
        console.error('Error cargando SKUs:', error);
        tbody.innerHTML = '<tr><td colspan="24" class="text-center py-4 text-danger">Error de conexión</td></tr>';
    }
}

// ==========================================
// Limpiar Sesión y Volver (Cancelar)
// ==========================================
async function limpiarYVolver() {
    const result = await Swal.fire({
        title: '¿Cancelar procesamiento?',
        text: 'Los cambios no guardados se perderán y volverás a la lista de solicitudes.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#6c757d',
        cancelButtonColor: '#0056b3',
        confirmButtonText: 'Sí, cancelar',
        cancelButtonText: 'Continuar editando'
    });

    if (result.isConfirmed) {
        try {
            // Llamar a API para limpiar la sesión
            await fetch('../api/limpiar_sesion_procesamiento.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            
            // Redirigir a gestionar_cargas.php
            window.location.href = 'gestionar_cargas.php';
        } catch (error) {
            console.error('Error al limpiar sesión:', error);
            // Aunque falle, redirigir igual
            window.location.href = 'gestionar_cargas.php';
        }
    }
}

// ==========================================
// Rechazar Toda la Solicitud
// ==========================================
async function rechazarTodaSolicitud() {
    const confirm = await Swal.fire({
        title: '¿Rechazar TODA la solicitud?',
        html: `
            <div class="alert alert-danger text-start">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Atención:</strong> Esta acción rechazará <strong>todos los SKUs</strong> de la solicitud #${idSolicitud} de una sola vez.
            </div>
            <p class="mt-2">Por favor, ingresa el motivo del rechazo:</p>
        `,
        input: 'textarea',
        inputPlaceholder: 'Ej: No cumple con los parámetros de carga, exceso de inventario...',
        inputAttributes: { rows: 3, required: true },
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, rechazar toda',
        cancelButtonText: 'Cancelar',
        inputValidator: (value) => {
            if (!value || value.trim().length < 5) return 'Debes ingresar un motivo de al menos 5 caracteres';
        }
    });

    if (!confirm.isConfirmed) return;
    const motivo = confirm.value.trim();

    Swal.fire({ 
        title: 'Rechazando...', 
        text: 'Marcando todos los SKUs como RECHAZADOS', 
        allowOutsideClick: false, 
        didOpen: () => { Swal.showLoading(); } 
    });

    try {
        const response = await fetch('../api/rechazar_toda_solicitud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_solicitud: parseInt(idSolicitud), motivo: motivo })
        });
        const data = await response.json();

        if (data.success) {
            Swal.fire({ 
                icon: 'success', 
                title: 'Solicitud rechazada', 
                text: data.message, 
                timer: 3000, 
                showConfirmButton: false 
            }).then(() => { 
                window.location.href = 'gestionar_cargas.php?actualizado=1'; 
            });
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire('Error', 'No se pudo conectar con el servidor', 'error');
    }
}

// ==========================================
// Guardar Cambios (con o sin procesar)
// ==========================================
async function guardarCambios(procesar = true) {
    const filas = document.querySelectorAll('#tbodySKUs tr');
    const items = [];
    let tieneErrores = false;
    
    filas.forEach(fila => {
        const idDetalle = fila.querySelector('.input-cantidad')?.getAttribute('data-id');
        if (!idDetalle) return;
        
        const cantidad = parseInt(fila.querySelector('.input-cantidad').value) || 0;
        const mdSeleccionado = fila.querySelector('.select-md').value;
        const mdDefecto = fila.querySelector('.select-md').getAttribute('data-md-defecto');
        const noCargar = fila.querySelector('.checkbox-no-cargar').checked;
        const motivo = fila.querySelector('.input-motivo').value.trim();
        
        const mdFinal = mdSeleccionado || mdDefecto;
        
        if (!mdFinal && !noCargar) {
            Swal.fire({
                icon: 'warning',
                title: 'Método de Compra requerido',
                text: `El SKU de la fila ${idDetalle} requiere un Método de Compra (MD)`,
                confirmButtonColor: '#0056b3'
            });
            tieneErrores = true; 
            return;
        }
        
        items.push({ 
            id_detalle: idDetalle, 
            cantidad_final: cantidad, 
            md: mdFinal, 
            no_cargar: noCargar ? 1 : 0, 
            motivo: motivo 
        });
    });
    
    if (tieneErrores) return;
    if (items.length === 0) { 
        Swal.fire({
            icon: 'warning',
            title: 'Sin datos',
            text: 'No hay items para guardar',
            confirmButtonColor: '#0056b3'
        }); 
        return; 
    }
    
    const titulo = procesar ? '¿Guardar cambios y PROCESAR?' : '¿Guardar cambios sin procesar?';
    const alerta = procesar 
        ? '<div class="alert alert-warning mt-3 mb-0"><i class="bi bi-exclamation-triangle me-2"></i>La solicitud pasará a <strong>EN_PROCESO</strong> o <strong>PROCESADA</strong>.</div>'
        : '<div class="alert alert-info mt-3 mb-0"><i class="bi bi-info-circle me-2"></i>La solicitud permanecerá en estado actual y podrás procesarla más tarde.</div>';

    const confirm = await Swal.fire({
        title: titulo, 
        html: `<p>Se guardarán <strong>${items.length} SKU(s)</strong>.</p>${alerta}`,
        icon: 'question', 
        showCancelButton: true, 
        confirmButtonColor: procesar ? '#198754' : '#ffc107',
        cancelButtonColor: '#6c757d', 
        confirmButtonText: procesar ? 'Sí, guardar y procesar' : 'Sí, guardar sin procesar', 
        cancelButtonText: 'Cancelar'
    });
    
    if (!confirm.isConfirmed) return;
    
    Swal.fire({ 
        title: procesar ? 'Procesando...' : 'Guardando...', 
        allowOutsideClick: false, 
        didOpen: () => { Swal.showLoading(); } 
    });
    
    try {
        const response = await fetch('../api/guardar_procesamiento.php', {
            method: 'POST', 
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                id_solicitud: parseInt(idSolicitud), 
                items: items, 
                procesar: procesar 
            })
        });
        const data = await response.json();
        
        if (data.success) {
            Swal.fire({ 
                icon: 'success', 
                title: '¡Éxito!', 
                text: data.message, 
                timer: 3000, 
                showConfirmButton: false 
            }).then(() => {
                if (procesar) {
                    window.location.href = 'gestionar_cargas.php?actualizado=1';
                } else {
                    window.location.reload();
                }
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message,
                confirmButtonColor: '#0056b3'
            });
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'No se pudo conectar con el servidor',
            confirmButtonColor: '#0056b3'
        });
    }
}