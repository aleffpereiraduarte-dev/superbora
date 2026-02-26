/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO CORE JS v2.0
 * Utilitários e Helpers
 * ══════════════════════════════════════════════════════════════════════════════
 */

const OneMundo = {
    // ═══════════════════════════════════════════════════════════════════════════
    // CONFIGURAÇÃO
    // ═══════════════════════════════════════════════════════════════════════════
    config: {
        apiBase: '/mercado/api',
        pollingInterval: 5000,
        toastDuration: 4000,
        debug: false
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // FORMATADORES
    // ═══════════════════════════════════════════════════════════════════════════
    format: {
        money(value) {
            return new Intl.NumberFormat('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            }).format(value || 0);
        },

        number(value, decimals = 0) {
            return new Intl.NumberFormat('pt-BR', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            }).format(value || 0);
        },

        percent(value) {
            return new Intl.NumberFormat('pt-BR', {
                style: 'percent',
                minimumFractionDigits: 1
            }).format((value || 0) / 100);
        },

        date(dateStr, format = 'short') {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            const options = format === 'full'
                ? { dateStyle: 'long', timeStyle: 'short' }
                : format === 'time'
                ? { timeStyle: 'short' }
                : { dateStyle: 'short' };
            return new Intl.DateTimeFormat('pt-BR', options).format(date);
        },

        timeAgo(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            const now = new Date();
            const seconds = Math.floor((now - date) / 1000);

            if (seconds < 60) return 'agora';
            if (seconds < 3600) return `${Math.floor(seconds / 60)}min`;
            if (seconds < 86400) return `${Math.floor(seconds / 3600)}h`;
            if (seconds < 604800) return `${Math.floor(seconds / 86400)}d`;
            return this.date(dateStr);
        },

        phone(phone) {
            if (!phone) return '-';
            const cleaned = phone.replace(/\D/g, '');
            if (cleaned.length === 11) {
                return `(${cleaned.slice(0,2)}) ${cleaned.slice(2,7)}-${cleaned.slice(7)}`;
            }
            return phone;
        },

        cpf(cpf) {
            if (!cpf) return '-';
            const cleaned = cpf.replace(/\D/g, '');
            return cleaned.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
        },

        truncate(str, length = 50) {
            if (!str) return '';
            return str.length > length ? str.substring(0, length) + '...' : str;
        },

        orderStatus(status) {
            const map = {
                'pending': 'Pendente',
                'pendente': 'Pendente',
                'pago': 'Pago',
                'aceito': 'Aceito',
                'accepted': 'Aceito',
                'shopping': 'Comprando',
                'coletando': 'Coletando',
                'coleta_finalizada': 'Coleta OK',
                'em_entrega': 'Em Entrega',
                'delivering': 'Em Entrega',
                'delivered': 'Entregue',
                'entregue': 'Entregue',
                'finalizado': 'Finalizado',
                'cancelado': 'Cancelado',
                'cancelled': 'Cancelado'
            };
            return map[status?.toLowerCase()] || status || '-';
        },

        statusColor(status) {
            const colors = {
                'pending': 'warning',
                'pendente': 'warning',
                'pago': 'info',
                'aceito': 'info',
                'accepted': 'info',
                'shopping': 'info',
                'coletando': 'info',
                'coleta_finalizada': 'info',
                'em_entrega': 'info',
                'delivering': 'info',
                'delivered': 'success',
                'entregue': 'success',
                'finalizado': 'success',
                'cancelado': 'error',
                'cancelled': 'error'
            };
            return colors[status?.toLowerCase()] || 'neutral';
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // TOASTS & NOTIFICAÇÕES
    // ═══════════════════════════════════════════════════════════════════════════
    toast: {
        container: null,

        init() {
            if (this.container) return;
            this.container = document.createElement('div');
            this.container.className = 'om-toast-container';
            document.body.appendChild(this.container);
        },

        show(message, type = 'info', title = null) {
            this.init();

            const icons = {
                success: '✓',
                warning: '⚠',
                error: '✕',
                info: 'ℹ'
            };

            const toast = document.createElement('div');
            toast.className = `om-toast om-toast-${type}`;
            toast.innerHTML = `
                <span class="om-toast-icon">${icons[type] || icons.info}</span>
                <div class="om-toast-content">
                    ${title ? `<div class="om-toast-title">${title}</div>` : ''}
                    <div class="om-toast-message">${message}</div>
                </div>
            `;

            this.container.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100px)';
                setTimeout(() => toast.remove(), 300);
            }, OneMundo.config.toastDuration);
        },

        success(message, title = null) { this.show(message, 'success', title); },
        warning(message, title = null) { this.show(message, 'warning', title); },
        error(message, title = null) { this.show(message, 'error', title); },
        info(message, title = null) { this.show(message, 'info', title); }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // MODAIS
    // ═══════════════════════════════════════════════════════════════════════════
    modal: {
        current: null,
        backdrop: null,

        open(content, options = {}) {
            // Criar backdrop se não existir
            if (!this.backdrop) {
                this.backdrop = document.createElement('div');
                this.backdrop.className = 'om-modal-backdrop';
                this.backdrop.onclick = () => options.closeable !== false && this.close();
                document.body.appendChild(this.backdrop);
            }

            // Criar modal
            this.current = document.createElement('div');
            this.current.className = 'om-modal';

            if (typeof content === 'string') {
                this.current.innerHTML = content;
            } else {
                this.current.appendChild(content);
            }

            document.body.appendChild(this.current);
            document.body.style.overflow = 'hidden';

            // Animar entrada
            requestAnimationFrame(() => {
                this.backdrop.classList.add('active');
                this.current.classList.add('active');
            });

            // ESC para fechar
            this._escHandler = (e) => {
                if (e.key === 'Escape' && options.closeable !== false) this.close();
            };
            document.addEventListener('keydown', this._escHandler);

            return this.current;
        },

        close() {
            if (!this.current) return;

            this.backdrop?.classList.remove('active');
            this.current.classList.remove('active');

            setTimeout(() => {
                this.current?.remove();
                this.current = null;
                document.body.style.overflow = '';
            }, 200);

            document.removeEventListener('keydown', this._escHandler);
        },

        confirm(message, options = {}) {
            return new Promise((resolve) => {
                const content = `
                    <div class="om-modal-header">
                        <h3 class="om-modal-title">${options.title || 'Confirmar'}</h3>
                    </div>
                    <div class="om-modal-body">
                        <p>${message}</p>
                    </div>
                    <div class="om-modal-footer">
                        <button class="om-btn om-btn-secondary" data-action="cancel">
                            ${options.cancelText || 'Cancelar'}
                        </button>
                        <button class="om-btn ${options.danger ? 'om-btn-danger' : 'om-btn-primary'}" data-action="confirm">
                            ${options.confirmText || 'Confirmar'}
                        </button>
                    </div>
                `;

                const modal = this.open(content, { closeable: false });

                modal.querySelector('[data-action="cancel"]').onclick = () => {
                    this.close();
                    resolve(false);
                };

                modal.querySelector('[data-action="confirm"]').onclick = () => {
                    this.close();
                    resolve(true);
                };
            });
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // API & FETCH
    // ═══════════════════════════════════════════════════════════════════════════
    api: {
        async request(endpoint, options = {}) {
            const url = endpoint.startsWith('http') ? endpoint : OneMundo.config.apiBase + endpoint;

            const config = {
                method: options.method || 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...options.headers
                },
                ...options
            };

            if (options.body && typeof options.body === 'object') {
                config.body = JSON.stringify(options.body);
            }

            try {
                const response = await fetch(url, config);
                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || data.error || 'Erro na requisição');
                }

                return data;
            } catch (error) {
                if (OneMundo.config.debug) console.error('API Error:', error);
                throw error;
            }
        },

        get(endpoint) {
            return this.request(endpoint);
        },

        post(endpoint, data) {
            return this.request(endpoint, { method: 'POST', body: data });
        },

        put(endpoint, data) {
            return this.request(endpoint, { method: 'PUT', body: data });
        },

        delete(endpoint) {
            return this.request(endpoint, { method: 'DELETE' });
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // LOADING STATES
    // ═══════════════════════════════════════════════════════════════════════════
    loading: {
        show(element) {
            if (typeof element === 'string') {
                element = document.querySelector(element);
            }
            if (!element) return;

            element.classList.add('om-loading');
            element.dataset.originalContent = element.innerHTML;
            element.innerHTML = '<div class="om-spinner"></div>';
            element.disabled = true;
        },

        hide(element) {
            if (typeof element === 'string') {
                element = document.querySelector(element);
            }
            if (!element) return;

            element.classList.remove('om-loading');
            if (element.dataset.originalContent) {
                element.innerHTML = element.dataset.originalContent;
                delete element.dataset.originalContent;
            }
            element.disabled = false;
        },

        skeleton(container, count = 3) {
            if (typeof container === 'string') {
                container = document.querySelector(container);
            }
            if (!container) return;

            container.innerHTML = Array(count).fill(`
                <div class="om-list-item">
                    <div class="om-skeleton om-skeleton-avatar"></div>
                    <div class="om-list-item-content">
                        <div class="om-skeleton om-skeleton-text" style="width: 60%"></div>
                        <div class="om-skeleton om-skeleton-text" style="width: 40%"></div>
                    </div>
                </div>
            `).join('');
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // STORAGE
    // ═══════════════════════════════════════════════════════════════════════════
    storage: {
        get(key, defaultValue = null) {
            try {
                const item = localStorage.getItem(`om_${key}`);
                return item ? JSON.parse(item) : defaultValue;
            } catch {
                return defaultValue;
            }
        },

        set(key, value) {
            try {
                localStorage.setItem(`om_${key}`, JSON.stringify(value));
                return true;
            } catch {
                return false;
            }
        },

        remove(key) {
            localStorage.removeItem(`om_${key}`);
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // GEOLOCATION
    // ═══════════════════════════════════════════════════════════════════════════
    geo: {
        watchId: null,

        async getCurrentPosition() {
            return new Promise((resolve, reject) => {
                if (!navigator.geolocation) {
                    reject(new Error('Geolocalização não suportada'));
                    return;
                }

                navigator.geolocation.getCurrentPosition(
                    (pos) => resolve({
                        lat: pos.coords.latitude,
                        lng: pos.coords.longitude,
                        accuracy: pos.coords.accuracy
                    }),
                    (err) => reject(err),
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            });
        },

        startTracking(callback, interval = 30000) {
            this.watchId = navigator.geolocation.watchPosition(
                (pos) => callback({
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    accuracy: pos.coords.accuracy
                }),
                (err) => console.error('Geo error:', err),
                { enableHighAccuracy: true }
            );
        },

        stopTracking() {
            if (this.watchId) {
                navigator.geolocation.clearWatch(this.watchId);
                this.watchId = null;
            }
        },

        calculateDistance(lat1, lng1, lat2, lng2) {
            const R = 6371; // km
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLng = (lng2 - lng1) * Math.PI / 180;
            const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng/2) * Math.sin(dLng/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c;
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // PUSH NOTIFICATIONS
    // ═══════════════════════════════════════════════════════════════════════════
    notifications: {
        async requestPermission() {
            if (!('Notification' in window)) return false;
            const permission = await Notification.requestPermission();
            return permission === 'granted';
        },

        async show(title, options = {}) {
            if (Notification.permission !== 'granted') return;

            return new Notification(title, {
                icon: '/mercado/assets/img/icon-192.png',
                badge: '/mercado/assets/img/badge.png',
                vibrate: [200, 100, 200],
                ...options
            });
        },

        playSound(type = 'notification') {
            const sounds = {
                notification: '/mercado/assets/sounds/notification.mp3',
                success: '/mercado/assets/sounds/success.mp3',
                alert: '/mercado/assets/sounds/alert.mp3'
            };

            const audio = new Audio(sounds[type] || sounds.notification);
            audio.volume = 0.5;
            audio.play().catch(() => {});
        },

        vibrate(pattern = [200]) {
            if ('vibrate' in navigator) {
                navigator.vibrate(pattern);
            }
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // DEBOUNCE & THROTTLE
    // ═══════════════════════════════════════════════════════════════════════════
    debounce(func, wait = 300) {
        let timeout;
        return function executedFunction(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    },

    throttle(func, limit = 100) {
        let inThrottle;
        return function executedFunction(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // POLLING
    // ═══════════════════════════════════════════════════════════════════════════
    polling: {
        intervals: {},

        start(id, callback, interval = 5000) {
            this.stop(id);
            callback(); // Executar imediatamente
            this.intervals[id] = setInterval(callback, interval);
        },

        stop(id) {
            if (this.intervals[id]) {
                clearInterval(this.intervals[id]);
                delete this.intervals[id];
            }
        },

        stopAll() {
            Object.keys(this.intervals).forEach(id => this.stop(id));
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // INIT
    // ═══════════════════════════════════════════════════════════════════════════
    init() {
        // Inicializar toast container
        this.toast.init();

        // Service Worker para PWA
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/mercado/sw.js').catch(() => {});
        }

        // Detectar conexão
        window.addEventListener('online', () => this.toast.success('Conexão restaurada'));
        window.addEventListener('offline', () => this.toast.warning('Você está offline'));

        console.log('OneMundo Core v2.0 initialized');
    }
};

// Auto-init quando o DOM estiver pronto
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => OneMundo.init());
} else {
    OneMundo.init();
}

// Exportar globalmente
window.OneMundo = OneMundo;
window.OM = OneMundo; // Alias curto
