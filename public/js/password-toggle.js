// Funcionalidad para mostrar/ocultar contraseñas
document.addEventListener('DOMContentLoaded', function() {
    // Función para crear el botón de toggle
    function createToggleButton(inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;

        // Crear contenedor para el input y el botón
        const container = document.createElement('div');
        container.className = 'password-toggle-container';
        
        // Mover el input al contenedor
        input.parentNode.insertBefore(container, input);
        container.appendChild(input);
        
        // Crear el botón de toggle
        const toggleButton = document.createElement('button');
        toggleButton.type = 'button';
        toggleButton.className = 'password-toggle-button';
        toggleButton.setAttribute('aria-label', 'Mostrar/ocultar contraseña');
        toggleButton.innerHTML = `
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
        `;
        
        // Agregar el botón al contenedor
        container.appendChild(toggleButton);
        
        // Agregar clases al input
        input.classList.add('has-toggle');
        
        // Funcionalidad del toggle
        toggleButton.addEventListener('click', function() {
            if (input.type === 'password') {
                input.type = 'text';
                toggleButton.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"></path>
                    </svg>
                `;
                toggleButton.setAttribute('aria-label', 'Ocultar contraseña');
            } else {
                input.type = 'password';
                toggleButton.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                `;
                toggleButton.setAttribute('aria-label', 'Mostrar contraseña');
            }
        });

        // Prevenir que el botón interfiera con el foco del input
        toggleButton.addEventListener('mousedown', function(e) {
            e.preventDefault();
        });
    }

    // Aplicar a todos los campos de contraseña
    const passwordFields = [
        'password_actual',
        'nueva_password', 
        'confirmar_password',
        'password',
        'confirm_password'
    ];

    passwordFields.forEach(fieldId => {
        createToggleButton(fieldId);
    });
}); 