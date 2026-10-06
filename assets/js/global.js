// assets/js/global.js

/**
 * Función global para confirmar el cierre de sesión
 */
function confirmarSalida(event) {
    if (event) event.preventDefault();
    
    // Verificar que SweetAlert esté cargado
    if (typeof Swal === 'undefined') {
        window.location.href = '../logout.php';
        return;
    }

    Swal.fire({
        title: '¿Cerrar sesión?',
        text: "¿Realmente deseas salir del sistema?",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, salir',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
        focusCancel: true
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                icon: 'success',
                title: 'Saliendo...',
                timer: 1000,
                showConfirmButton: false
            }).then(() => {
                window.location.href = '../logout.php';
            });
        }
    });
}