/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * JavaScript Principal
 * Desarrollado por: Shalom Software
 */

// Configuración global
const App = {
    baseUrl: '',
    csrfToken: '',
    scriptPromises: {},
    
    init() {
        this.baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        
        this.initSidebar();
        this.initAlerts();
        this.initModals();
        this.initForms();
        this.initTooltips();
    },
    
    // Sidebar toggle para móvil
    initSidebar() {
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const sidebarNav = sidebar?.querySelector('.sidebar-nav');

        // Fallback: en algunos navegadores/trackpads el scroll sobre un sidebar fijo no se propaga bien.
        // Redirigimos la rueda al contenedor scrolleable del menú para garantizar acceso a todas las opciones.
        if (sidebar && sidebarNav) {
            sidebar.addEventListener('wheel', (event) => {
                if (sidebarNav.scrollHeight <= sidebarNav.clientHeight) {
                    return;
                }
                if (sidebarNav.contains(event.target)) {
                    return;
                }

                event.preventDefault();
                sidebarNav.scrollTop += event.deltaY;
            }, { passive: false });
        }
        
        if (menuToggle && sidebar) {
            const closeSidebar = () => {
                sidebar.classList.remove('open');
                overlay?.classList.remove('active');
                document.body.style.overflow = '';
            };

            menuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('open');
                overlay?.classList.toggle('active', isOpen);
                document.body.style.overflow = isOpen ? 'hidden' : '';
            });
            
            overlay?.addEventListener('click', () => {
                closeSidebar();
            });

            sidebar.querySelectorAll('.sidebar-link').forEach((link) => {
                link.addEventListener('click', () => {
                    if (window.innerWidth <= 1024) {
                        closeSidebar();
                    }
                });
            });

            window.addEventListener('resize', () => {
                if (window.innerWidth > 1024) {
                    closeSidebar();
                }
            });
        }
    },
    
    // Auto-cerrar alertas
    initAlerts() {
        document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
    },
    
    // Sistema de modales
    initModals() {
        document.querySelectorAll('[data-modal-target]').forEach(trigger => {
            trigger.addEventListener('click', (e) => {
                e.preventDefault();
                const modalId = trigger.dataset.modalTarget;
                this.openModal(modalId);
            });
        });
        
        document.querySelectorAll('[data-modal-close]').forEach(closeBtn => {
            closeBtn.addEventListener('click', () => {
                const modal = closeBtn.closest('.modal-overlay');
                this.closeModal(modal);
            });
        });
        
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    this.closeModal(overlay);
                }
            });
        });
    },
    
    openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    },
    
    closeModal(modal) {
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    },
    
    // Validación de formularios
    initForms() {
        document.querySelectorAll('form[data-validate]').forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!this.validateForm(form)) {
                    e.preventDefault();
                }
            });
        });
        
        // Auto-format para campos numéricos
        document.querySelectorAll('input[data-format="number"]').forEach(input => {
            input.addEventListener('blur', () => {
                const value = parseFloat(input.value);
                if (!isNaN(value)) {
                    input.value = value.toFixed(input.dataset.decimals || 2);
                }
            });
        });
    },
    
    validateForm(form) {
        let isValid = true;
        
        // Limpiar errores previos
        form.querySelectorAll('.is-invalid').forEach(el => {
            el.classList.remove('is-invalid');
        });
        form.querySelectorAll('.invalid-feedback').forEach(el => {
            el.remove();
        });
        
        // Validar campos requeridos
        form.querySelectorAll('[required]').forEach(field => {
            if (!field.value.trim()) {
                this.showFieldError(field, 'Este campo es requerido');
                isValid = false;
            }
        });
        
        // Validar emails
        form.querySelectorAll('input[type="email"]').forEach(field => {
            if (field.value && !this.isValidEmail(field.value)) {
                this.showFieldError(field, 'Email inválido');
                isValid = false;
            }
        });
        
        // Validar números
        form.querySelectorAll('input[type="number"]').forEach(field => {
            const min = parseFloat(field.min);
            const max = parseFloat(field.max);
            const value = parseFloat(field.value);
            
            if (field.value && isNaN(value)) {
                this.showFieldError(field, 'Debe ser un número válido');
                isValid = false;
            } else if (!isNaN(min) && value < min) {
                this.showFieldError(field, `El valor mínimo es ${min}`);
                isValid = false;
            } else if (!isNaN(max) && value > max) {
                this.showFieldError(field, `El valor máximo es ${max}`);
                isValid = false;
            }
        });
        
        return isValid;
    },
    
    showFieldError(field, message) {
        field.classList.add('is-invalid');
        const feedback = document.createElement('div');
        feedback.className = 'invalid-feedback';
        feedback.textContent = message;
        field.parentNode.appendChild(feedback);
    },
    
    isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    },
    
    // Tooltips
    initTooltips() {
        // Los tooltips se manejan con CSS
    },
    
    // API Helpers
    async fetch(url, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrfToken
            }
        };

        const isAbsoluteUrl = /^https?:\/\//i.test(url);
        const requestUrl = isAbsoluteUrl ? url : (this.baseUrl + url);

        const response = await fetch(requestUrl, {...defaultOptions, ...options});
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return response.json();
    },
    
    async get(url) {
        return this.fetch(url, { method: 'GET' });
    },
    
    async post(url, data) {
        return this.fetch(url, {
            method: 'POST',
            body: JSON.stringify(data)
        });
    },
    
    async put(url, data) {
        return this.fetch(url, {
            method: 'PUT',
            body: JSON.stringify(data)
        });
    },
    
    async delete(url) {
        return this.fetch(url, { method: 'DELETE' });
    },
    
    // Notificaciones toast
    toast(message, type = 'info', duration = 4000) {
        const alertType = type === 'error' ? 'danger' : type;
        const container = document.getElementById('toastContainer') || this.createToastContainer();
        
        const toast = document.createElement('div');
        toast.className = `alert alert-${alertType} fade-in`;
        toast.setAttribute('role', 'status');
        toast.style.marginBottom = '0.5rem';
        toast.innerHTML = `
            <span>${message}</span>
            <button type="button" class="ml-4 text-lg leading-none opacity-75 hover:opacity-100" aria-label="Cerrar mensaje" onclick="this.parentElement.remove()">×</button>
        `;
        
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },
    
    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.setAttribute('aria-live', 'polite');
        container.setAttribute('aria-atomic', 'true');
        container.style.cssText = 'position: fixed; top: 1rem; right: 1rem; z-index: 100; max-width: 400px;';
        document.body.appendChild(container);
        return container;
    },
    
    // Confirmación
    confirm(message, title = 'Confirmar') {
        return new Promise((resolve) => {
            const modalHtml = `
                <div class="modal-overlay active" id="confirmModal">
                    <div class="modal" style="width: 400px;">
                        <div class="modal-header">
                            <h3 class="modal-title">${title}</h3>
                            <button class="modal-close" onclick="App.resolveConfirm(false)">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 6L6 18M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p>${message}</p>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline" onclick="App.resolveConfirm(false)">Cancelar</button>
                            <button class="btn btn-primary" onclick="App.resolveConfirm(true)">Confirmar</button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            document.body.style.overflow = 'hidden';
            const modal = document.getElementById('confirmModal');
            const cancelButton = modal?.querySelector('.btn.btn-outline');
            cancelButton?.focus();

            const keydownHandler = (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    this.resolveConfirm(false);
                }
            };
            document.addEventListener('keydown', keydownHandler);
            
            this.confirmResolve = (result) => {
                document.removeEventListener('keydown', keydownHandler);
                document.getElementById('confirmModal')?.remove();
                document.body.style.overflow = '';
                resolve(result);
            };
        });
    },
    
    resolveConfirm(result) {
        if (this.confirmResolve) {
            this.confirmResolve(result);
        }
    },
    
    // Formateo
    formatNumber(number, decimals = 2) {
        return new Intl.NumberFormat('es-EC', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(number);
    },
    
    formatDate(date) {
        return new Intl.DateTimeFormat('es-EC', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        }).format(new Date(date));
    },
    
    formatDateTime(date) {
        return new Intl.DateTimeFormat('es-EC', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).format(new Date(date));
    },
    
    // Generador de código de lote
    generateLoteCode(proveedor, fecha, estado, fermentado) {
        const d = new Date(fecha);
        const dia = String(d.getDate()).padStart(2, '0');
        const mes = String(d.getMonth() + 1).padStart(2, '0');
        const anio = String(d.getFullYear()).slice(-2);
        
        return `${proveedor}-${dia}-${mes}-${anio}-${estado}-${fermentado}`.toUpperCase();
    },
    
    // Imprimir
    print() {
        window.print();
    },

    async loadScript(url) {
        if (this.scriptPromises[url]) {
            return this.scriptPromises[url];
        }

        this.scriptPromises[url] = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = url;
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error(`No se pudo cargar el script: ${url}`));
            document.head.appendChild(script);
        });

        return this.scriptPromises[url];
    },
    
    // Exportar a Excel (básico)
    async exportToExcel(tableId, filename = 'export') {
        const table = document.getElementById(tableId);
        if (!table) return;

        if (typeof XLSX === 'undefined') {
            try {
                await this.loadScript('https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js');
            } catch (error) {
                this.toast('No se pudo cargar el módulo de exportación.', 'error');
                return;
            }
        }

        const wb = XLSX.utils.table_to_book(table, {sheet: "Datos"});
        XLSX.writeFile(wb, `${filename}.xlsx`);
    }
};

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => App.init());

// Funciones globales para usar en onclick
function openModal(modalId) {
    App.openModal(modalId);
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    App.closeModal(modal);
}

async function confirmDelete(message) {
    return App.confirm(message || '¿Está seguro de eliminar este registro?', 'Eliminar');
}

function inlineConfirm(event, message, title = 'Confirmar') {
    const form = event?.target?.closest('form') || event?.target;
    if (!form) {
        return false;
    }

    event.preventDefault();
    App.confirm(message || '¿Está seguro de continuar?', title).then((confirmed) => {
        if (confirmed) {
            form.submit();
        }
    });
    return false;
}

// Handsontable helpers
const HandsontableHelpers = {
    // Configuración común
    getCommonConfig() {
        return {
            licenseKey: 'non-commercial-and-evaluation',
            rowHeaders: true,
            colHeaders: true,
            contextMenu: true,
            manualColumnResize: true,
            manualRowResize: true,
            stretchH: 'all',
            autoWrapRow: true,
            height: 'auto',
            className: 'htMiddle',
            outsideClickDeselects: false
        };
    },
    
    // Renderizador para checkbox
    checkboxRenderer(instance, td, row, col, prop, value) {
        const checkbox = value ? '✓' : '';
        td.innerHTML = `<span style="color: ${value ? '#16a34a' : '#d1d5db'}; font-size: 1.25em;">${checkbox || '○'}</span>`;
        td.style.textAlign = 'center';
        return td;
    },
    
    // Renderizador para números con color según rango
    rangeRenderer(min, max) {
        return function(instance, td, row, col, prop, value) {
            Handsontable.renderers.NumericRenderer.apply(this, arguments);
            
            const numValue = parseFloat(value);
            if (!isNaN(numValue)) {
                if (numValue < min) {
                    td.style.backgroundColor = '#fef2f2';
                    td.style.color = '#991b1b';
                } else if (numValue > max) {
                    td.style.backgroundColor = '#fef3c7';
                    td.style.color = '#92400e';
                } else {
                    td.style.backgroundColor = '#f0fdf4';
                    td.style.color = '#166534';
                }
            }
            return td;
        };
    },
    
    // Validador numérico con rango
    rangeValidator(min, max) {
        return function(value, callback) {
            const num = parseFloat(value);
            if (value === '' || value === null) {
                callback(true);
            } else if (isNaN(num)) {
                callback(false);
            } else {
                callback(num >= min && num <= max);
            }
        };
    }
};

// Export para módulos
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { App, HandsontableHelpers };
}
