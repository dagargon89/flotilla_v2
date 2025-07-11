/**
 * Funcionalidad común para filtros y paginación de tablas
 * Reutilizable en todas las páginas del proyecto
 */

class TableFilters {
    constructor() {
        this.initializeFilters();
        this.initializeAutoSubmit();
        this.initializeClearFilters();
    }

    /**
     * Inicializa los filtros y mantiene los valores en la URL
     */
    initializeFilters() {
        // Restaurar valores de filtros desde la URL
        const urlParams = new URLSearchParams(window.location.search);
        
        // Restaurar valores de inputs de texto
        document.querySelectorAll('input[type="text"], input[type="date"]').forEach(input => {
            const paramName = input.name;
            if (urlParams.has(paramName)) {
                input.value = urlParams.get(paramName);
            }
        });

        // Restaurar valores de selects
        document.querySelectorAll('select').forEach(select => {
            const paramName = select.name;
            if (urlParams.has(paramName)) {
                select.value = urlParams.get(paramName);
            }
        });
    }

    /**
     * Auto-submit del formulario cuando cambian ciertos filtros
     */
    initializeAutoSubmit() {
        // Auto-submit para selects de estatus y registros por página
        document.querySelectorAll('select[name="filtro_estatus"], select[name="registros_por_pagina"]').forEach(select => {
            select.addEventListener('change', () => {
                // Resetear a página 1 cuando se cambia un filtro
                const form = select.closest('form');
                const paginaInput = form.querySelector('input[name="pagina"]');
                if (paginaInput) {
                    paginaInput.value = '1';
                }
                form.submit();
            });
        });

        // Auto-submit para fechas con delay
        document.querySelectorAll('input[type="date"]').forEach(input => {
            let timeout;
            input.addEventListener('change', () => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    const form = input.closest('form');
                    const paginaInput = form.querySelector('input[name="pagina"]');
                    if (paginaInput) {
                        paginaInput.value = '1';
                    }
                    form.submit();
                }, 500);
            });
        });
    }

    /**
     * Inicializa el botón de limpiar filtros
     */
    initializeClearFilters() {
        const clearButton = document.querySelector('a[href*="limpiar"], button[data-clear-filters]');
        if (clearButton) {
            clearButton.addEventListener('click', (e) => {
                e.preventDefault();
                this.clearAllFilters();
            });
        }
    }

    /**
     * Limpia todos los filtros y redirige a la página base
     */
    clearAllFilters() {
        const currentUrl = new URL(window.location.href);
        const baseUrl = currentUrl.pathname;
        window.location.href = baseUrl;
    }

    /**
     * Actualiza la URL con los parámetros de filtro sin recargar la página
     */
    updateUrl(params) {
        const url = new URL(window.location);
        Object.keys(params).forEach(key => {
            if (params[key] !== '' && params[key] !== null) {
                url.searchParams.set(key, params[key]);
            } else {
                url.searchParams.delete(key);
            }
        });
        window.history.pushState({}, '', url);
    }

    /**
     * Muestra/oculta el indicador de carga
     */
    showLoading(show = true) {
        const loadingIndicator = document.getElementById('loading-indicator');
        if (loadingIndicator) {
            loadingIndicator.style.display = show ? 'block' : 'none';
        }
    }

    /**
     * Valida fechas para asegurar que fecha_desde <= fecha_hasta
     */
    validateDateRange() {
        const fechaDesde = document.getElementById('filtro_fecha_desde');
        const fechaHasta = document.getElementById('filtro_fecha_hasta');
        
        if (fechaDesde && fechaHasta && fechaDesde.value && fechaHasta.value) {
            if (fechaDesde.value > fechaHasta.value) {
                alert('La fecha "Desde" no puede ser posterior a la fecha "Hasta"');
                fechaDesde.focus();
                return false;
            }
        }
        return true;
    }

    /**
     * Exporta los datos filtrados (funcionalidad futura)
     */
    exportFilteredData(format = 'csv') {
        const form = document.querySelector('form[method="GET"]');
        if (form) {
            const formData = new FormData(form);
            formData.append('export', format);
            
            // Crear un formulario temporal para exportar
            const tempForm = document.createElement('form');
            tempForm.method = 'POST';
            tempForm.action = window.location.pathname;
            
            for (let [key, value] of formData.entries()) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                tempForm.appendChild(input);
            }
            
            document.body.appendChild(tempForm);
            tempForm.submit();
            document.body.removeChild(tempForm);
        }
    }
}

/**
 * Funcionalidad específica para paginación
 */
class TablePagination {
    constructor() {
        this.initializePagination();
    }

    /**
     * Inicializa la funcionalidad de paginación
     */
    initializePagination() {
        // Agregar clases de hover a los enlaces de paginación
        document.querySelectorAll('.pagination a').forEach(link => {
            link.addEventListener('mouseenter', () => {
                link.classList.add('hover:bg-gray-300');
            });
            
            link.addEventListener('mouseleave', () => {
                link.classList.remove('hover:bg-gray-300');
            });
        });
    }

    /**
     * Navega a una página específica
     */
    goToPage(page) {
        const url = new URL(window.location);
        url.searchParams.set('pagina', page);
        window.location.href = url.toString();
    }

    /**
     * Actualiza la URL con la página actual
     */
    updatePageInUrl(page) {
        const url = new URL(window.location);
        url.searchParams.set('pagina', page);
        window.history.pushState({}, '', url);
    }
}

/**
 * Funcionalidad para búsqueda en tiempo real (opcional)
 */
class TableSearch {
    constructor() {
        this.initializeSearch();
    }

    /**
     * Inicializa la búsqueda en tiempo real
     */
    initializeSearch() {
        const searchInputs = document.querySelectorAll('input[data-search]');
        
        searchInputs.forEach(input => {
            let timeout;
            input.addEventListener('input', () => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    this.performSearch(input.value, input.dataset.search);
                }, 300);
            });
        });
    }

    /**
     * Realiza la búsqueda
     */
    performSearch(query, searchType) {
        const url = new URL(window.location);
        url.searchParams.set(`filtro_${searchType}`, query);
        url.searchParams.set('pagina', '1'); // Resetear a página 1
        window.location.href = url.toString();
    }
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar filtros
    if (document.querySelector('form[method="GET"]')) {
        window.tableFilters = new TableFilters();
    }

    // Inicializar paginación
    if (document.querySelector('.pagination')) {
        window.tablePagination = new TablePagination();
    }

    // Inicializar búsqueda (opcional)
    if (document.querySelector('input[data-search]')) {
        window.tableSearch = new TableSearch();
    }

    // Validación de fechas en formularios
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (window.tableFilters && !window.tableFilters.validateDateRange()) {
                e.preventDefault();
                return false;
            }
        });
    });
});

// Funciones globales para uso desde HTML
window.clearTableFilters = function() {
    if (window.tableFilters) {
        window.tableFilters.clearAllFilters();
    }
};

window.exportTableData = function(format) {
    if (window.tableFilters) {
        window.tableFilters.exportFilteredData(format);
    }
}; 