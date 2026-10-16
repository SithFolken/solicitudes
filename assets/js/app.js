document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('loginForm');
    
    // ✅ LIMPIEZA DE BACKDROP RESIDUAL (solución al problema de pantalla bloqueada)
    document.addEventListener('hidden.bs.modal', function (event) {
        // Eliminar cualquier backdrop residual
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    });

    // ✅ Botón "Entendido" del modal piloto
    const btnCerrarModal = document.getElementById('btnCerrarModalPiloto');
    if (btnCerrarModal) {
        btnCerrarModal.addEventListener('click', () => {
            const modalEl = document.getElementById('modalPiloto');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) {
                modalInstance.hide();
            }
        });
    }
    
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const btn = document.getElementById('btnLogin');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('loadingSpinner');
            const msgBox = document.getElementById('loginMessage');
            const messageText = document.getElementById('messageText');
            const passwordInput = document.getElementById('password');

            // Activar carga
            btn.disabled = true;
            spinner.classList.remove('d-none');
            btnText.textContent = 'Validando...';
            msgBox.classList.add('d-none');

            const formData = new FormData(loginForm);
            let fueExitoso = false;

            try {
                const response = await fetch('api/login.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    fueExitoso = true;
                    btnText.textContent = '¡Éxito! Redirigiendo...';
                    setTimeout(() => {
                        window.location.href = data.redirect || 'views/dashboard_tienda.php';
                    }, 500);
                } else {
                    if (data.error_type === 'PILOTO_NO_HABILITADO') {
                        // ✅ Mostrar Modal Profesional con limpieza garantizada
                        document.getElementById('mensajePiloto').textContent = data.message;
                        
                        const modalEl = document.getElementById('modalPiloto');
                        
                        // Destruir instancia anterior si existe (evita conflictos)
                        const existingModal = bootstrap.Modal.getInstance(modalEl);
                        if (existingModal) {
                            existingModal.dispose();
                        }
                        
                        // Crear nueva instancia y mostrar
                        const modalPiloto = new bootstrap.Modal(modalEl);
                        modalPiloto.show();
                    } else {
                        // Mostrar Alerta Roja y Sacudir
                        messageText.textContent = data.message || 'Usuario o contraseña incorrectos.';
                        msgBox.className = 'alert alert-danger d-flex align-items-center';
                        msgBox.classList.remove('d-none');
                        
                        passwordInput.value = '';
                        passwordInput.focus();
                        
                        const card = document.querySelector('.login-card');
                        card.style.animation = 'none';
                        card.offsetHeight; 
                        card.style.animation = 'shake 0.5s ease-in-out';
                    }
                }
            } catch (error) {
                console.error('Error de conexión:', error);
                messageText.textContent = 'Error de conexión con el servidor.';
                msgBox.className = 'alert alert-danger d-flex align-items-center';
                msgBox.classList.remove('d-none');
            } finally {
                if (!fueExitoso) {
                    btn.disabled = false;
                    spinner.classList.add('d-none');
                    btnText.textContent = 'Ingresar';
                }
            }
        });
    }
});

// Agregar animación de shake al CSS dinámicamente si no existe
if (!document.getElementById('shake-style')) {
    const style = document.createElement('style');
    style.id = 'shake-style';
    style.textContent = `
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
    `;
    document.head.appendChild(style);
}