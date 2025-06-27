// ... existing code ...

// Manejo del formulario de observaciones
document.addEventListener('DOMContentLoaded', function() {
    const tieneObservaciones = document.getElementById('tiene_observaciones');
    const seccionObservaciones = document.getElementById('seccion_observaciones');
    const observacionesTextarea = document.getElementById('observaciones');

    if (tieneObservaciones && seccionObservaciones && observacionesTextarea) {
        tieneObservaciones.addEventListener('change', function() {
            if (this.value === 'si') {
                seccionObservaciones.style.display = 'block';
                observacionesTextarea.value = ''; // Limpiar el valor anterior
                observacionesTextarea.required = true;
            } else {
                seccionObservaciones.style.display = 'none';
                observacionesTextarea.value = 'Ninguna observación';
                observacionesTextarea.required = false;
            }
        });

        // Establecer valor inicial
        if (tieneObservaciones.value === 'no') {
            observacionesTextarea.value = 'Ninguna observación';
            seccionObservaciones.style.display = 'none';
            observacionesTextarea.required = false;
        }
    }
});

// ... existing code ...