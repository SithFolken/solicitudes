document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('loginForm');
    
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const btn = document.getElementById('btnLogin');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('loadingSpinner');
            const msgBox = document.getElementById('loginMessage');
            const messageText = document.getElementById('messageText');
            const passwordInput = document.getElementById('password');

            // 1. Activar estado de carga
            btn.disabled = true;
            spinner.classList.remove('d-none');
            btnText.textContent = 'Validando...';
            msgBox.classList.add('d-none');

            const formData = new FormData(loginForm);

            try {
                const response = await fetch('api/login.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    // ✅ Éxito: Redirigir según lo que devuelva el servidor
                    btnText.textContent = '¡Éxito! Redirigiendo...';
                    setTimeout(() => {
                        window.location.href = data.redirect || 'views/dashboard_tienda.php';
                    }, 500);
                } else {
                    // ❌ Error: Mostrar mensaje y limpiar contraseña
                    messageText.textContent = data.message || 'Usuario o contraseña incorrectos.';
                    msgBox.className = 'alert alert-danger d-flex align-items-center';
                    msgBox.classList.remove('d-none');
                    
                    passwordInput.value = '';
                    passwordInput.focus();
                    
                    // Efecto de sacudida en la tarjeta
                    const card = document.querySelector('.login-card');
                    card.style.animation = 'none';
                    card.offsetHeight; // Trigger reflow
                    card.style.animation = 'shake 0.5s ease-in-out';
                }
            } catch (error) {
                console.error('Error de conexión:', error);
                messageText.textContent = 'Error de conexión con el servidor. Intenta nuevamente.';
                msgBox.className = 'alert alert-danger d-flex align-items-center';
                msgBox.classList.remove('d-none');
            } finally {
                // 3. Restaurar estado del botón (solo si hubo error)
                if (!msgBox.classList.contains('d-none') && msgBox.classList.contains('alert-danger')) {
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