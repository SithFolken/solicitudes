// assets/js/procesar_carga.js

// Obtener ID de solicitud de la URL
const urlParams = new URLSearchParams(window.location.search);
const idSolicitud = urlParams.get('id');

document.addEventListener('DOMContentLoaded', () => {
    if (idSolicitud) {
        cargarSKUs();
    } else {
        Swal.fire('Error', 'No se especificó una solicitud', 'error').then(() => {
            window.location.href = 'gestion_cargas.php';
        });
    }
});

// ==========================================
// Cargar SKUs de la solicitud
// ==========================================
async function cargarSKUs() {
    const tbody = document.getElementById('tbodySKUs');
    
    try {
        const response = await fetch(`../api/obtener_detalle_solicitud.php?id=${idSolicitud}`);
        const data = await response.json();
        
        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="18" class="text-center py-4 text-danger">${data.message || 'Error al cargar'}</td></tr>`;
            return;
        }
        
        tbody.innerHTML = ''; // Limpiar el spinner de carga
        
        if (!data.skus || data.skus.length === 0) {
            tbody.innerHTML = '<tr><td colspan="18" class="text-center py-4 text-muted">No hay SKUs en esta solicitud</td></tr>';
            return;
        }
        
        data.skus.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${item.sku}</strong></td>
                <td class="text-start">${item.descripcion_producto || '-'}</td>
                
                <!-- Parámetros de Venta -->
                <td class="bg-pv">${item.pv6 || '0'}</td>
                <td class="bg-pv">${item.pv3 || '0'}</td>
                <td class="bg-pv">${item.min || '0'}</td>
                <td class="bg-pv">${item.lt || '0'}</td>
                
                <!-- Inventario -->
                <td class="bg-inv">${item.disp_tda || '0'}</td>
                <td class="bg-inv">${item.pend_tda || '0'}</td>
                <td class="bg-inv">${item.disp_bod || '0'}</td>
                <td class="bg-inv">${item.pend_bod || '0'}</td>
                
                <!-- SDS -->
                <td class="bg-sds"><strong>${item.sds_actual || '0'}</strong></td>
                <td class="bg-sds"><strong>${item.sds_carga || '0'}</strong></td>
                
                <!-- Cantidades -->
                <td><strong>${item.carga_solicitada || '0'}</strong></td>
                <td>
                    <input type="number" class="form-control form-control-sm input-cantidad" 
                           data-id="${item.id_detalle}" value="${item.carga_solicitada || '0'}" min="0" step="1">
                </td>
                
                <!-- ✅ MD por Defecto -->
                <td>
                    <span class="badge bg-info text-dark badge-md-default">
                        ${item.md_defecto_sku || 'N/A'}
                    </span>
                </td>
                
                <!-- ✅ MD Select (Preseleccionado con el defecto) -->
                <td>
                    <select class="form-select form-select-sm select-md" 
                            data-id="${item.id_detalle}" 
                            data-md-defecto="${item.md_defecto_sku || ''}">
                        <option value="">Seleccione...</option>
                        <option value="TRANSFERENCIA" ${item.md_defecto_sku === 'TRANSFERENCIA' ? 'selected' : ''}>Transferencia</option>
                        <option value="CROSS_DOCKING" ${item.md_defecto_sku === 'CROSS_DOCKING' ? 'selected' : ''}>Cross Docking</option>
                        <option value="COMPRA_LOCAL" ${item.md_defecto_sku === 'COMPRA_LOCAL' ? 'selected' : ''}>Compra Local</option>
                    </select>
                </td>
                
                <!-- No Cargar -->
                <td>
                    <input type="checkbox" class="form-check-input checkbox-no-cargar" data-id="${item.id_detalle}">
                </td>
                
                <!-- Motivo -->
                <td>
                    <input type="text" class="form-control form-control-sm input-motivo" 
                           data-id="${item.id_detalle}" placeholder="Motivo..." value="${item.motivo || ''}">
                </td>
            `;
            tbody.appendChild(tr);
        });
        
    } catch (error) {
        console.error('Error cargando SKUs:', error);
        tbody.innerHTML = '<tr><td colspan="18" class="text-center py-4 text-danger">Error de conexión al cargar los datos</td></tr>';
    }
}

// ==========================================
// Aplicar MD por defecto a todos
// ==========================================
function aplicarMDPorDefectoATodos() {
    const selects = document.querySelectorAll('.select-md');
    let count = 0;
    
    selects.forEach(select => {
        const mdDefecto = select.getAttribute('data-md-defecto');
        if (mdDefecto) {
            select.value = mdDefecto;
            count++;
        }
    });
    
    Swal.fire({
        icon: 'success',
        title: 'MD aplicado',
        text: `Se aplicó el MD por defecto a ${count} SKU(s)`,
        timer: 2000,
        showConfirmButton: false
    });
}

/// ==========================================
// Guardar Cambios (con modo dual: solo guardar o procesar)
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
        
        // Si no se seleccionó MD, usar el por defecto
        const mdFinal = mdSeleccionado || mdDefecto;
        
        if (!mdFinal && !noCargar) {
            Swal.fire('Error', `El SKU de la fila ${idDetalle} requiere un Método de Compra (MD)`, 'warning');
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
        Swal.fire('Error', 'No hay items para guardar', 'warning');
        return;
    }
    
    // ✅ CONFIRMACIÓN SEGÚN EL MODO
    if (procesar) {
        // Modo: Guardar y Procesar
        const confirm = await Swal.fire({
            title: '¿Guardar cambios y PROCESAR la solicitud?',
            html: `
                <p>Se procesarán <strong>${items.length} SKU(s)</strong>.</p>
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Atención:</strong> La solicitud pasará de estado <strong>PENDIENTE</strong> a <strong>EN_PROCESO</strong> y ya no podrá ser modificada.
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, guardar y procesar',
            cancelButtonText: 'Cancelar'
        });
        
        if (!confirm.isConfirmed) return;
        
        Swal.fire({
            title: 'Procesando...',
            text: 'Guardando cambios y cambiando estado a EN_PROCESO',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });
    } else {
        // Modo: Solo Guardar (sin procesar)
        const confirm = await Swal.fire({
            title: '¿Guardar cambios sin procesar?',
            html: `
                <p>Se guardarán los cambios de <strong>${items.length} SKU(s)</strong>.</p>
                <div class="alert alert-info mt-3 mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    La solicitud permanecerá en estado <strong>PENDIENTE</strong> y podrás procesarla más tarde.
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, guardar sin procesar',
            cancelButtonText: 'Cancelar'
        });
        
        if (!confirm.isConfirmed) return;
        
        Swal.fire({
            title: 'Guardando...',
            text: 'Guardando cambios (sin procesar)',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });
    }
    
    try {
        const response = await fetch('../api/guardar_procesamiento.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_solicitud: parseInt(idSolicitud),
                items: items,
                procesar: procesar  // ✅ Enviar flag de si debe procesar o solo guardar
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            const mensaje = procesar 
                ? '¡Solicitud procesada correctamente!' 
                : 'Cambios guardados. La solicitud sigue en estado PENDIENTE.';
            
            Swal.fire({
                icon: 'success',
                title: '¡Éxito!',
                text: data.message || mensaje,
                timer: 3000,
                showConfirmButton: false
            }).then(() => {
                if (procesar) {
                    // Si procesó, volver a gestionar_cargas
                    window.location.href = 'gestionar_cargas.php?actualizado=1';
                } else {
                    // Si solo guardó, recargar la misma página para ver los cambios
                    window.location.reload();
                }
            });
        } else {
            Swal.fire('Error', data.message, 'error');
        }
        
    } catch (error) {
        console.error('Error:', error);
        Swal.fire('Error', 'No se pudo conectar con el servidor', 'error');
    }
}