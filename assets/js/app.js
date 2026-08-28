document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('loginForm');
    
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const btn = document.getElementById('btnLogin');
            const spinner = document.getElementById('loadingSpinner');
            const msgBox = document.getElementById('loginMessage');

            btn.disabled = true;
            spinner.classList.remove('d-none');
            msgBox.classList.add('d-none');

            const formData = new FormData(loginForm);

            try {
                const response = await fetch('api/login.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    // ✅ CORRECCIÓN: Usar la redirección que devuelve el servidor
                    window.location.href = data.redirect;
                } else {
                    msgBox.textContent = data.message;
                    msgBox.className = 'alert alert-danger';
                    msgBox.classList.remove('d-none');
                }
            } catch (error) {
                console.error('Error:', error);
                msgBox.textContent = 'Error de conexión con el servidor.';
                msgBox.className = 'alert alert-danger';
                msgBox.classList.remove('d-none');
            } finally {
                btn.disabled = false;
                spinner.classList.add('d-none');
            }
        });
    }
});