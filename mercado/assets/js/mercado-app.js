/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO MERCADO - APP JS
 * Sistema unificado: Carrinho, Modais, Notificações
 * ══════════════════════════════════════════════════════════════════════════════
 */

(function() {
    'use strict';
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // CONFIGURAÇÃO
    // ═══════════════════════════════════════════════════════════════════════════════
    
    const CONFIG = {
        apiBase: '/mercado/api',
        currency: 'BRL',
        locale: 'pt-BR',
        cartKey: 'om_market_cart',
        toastDuration: 3000
    };
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // UTILITÁRIOS
    // ═══════════════════════════════════════════════════════════════════════════════
    
    const $ = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];
    
    function formatMoney(value) {
        return new Intl.NumberFormat(CONFIG.locale, {
            style: 'currency',
            currency: CONFIG.currency
        }).format(value);
    }
    
    function debounce(fn, delay = 300) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // TOAST / NOTIFICAÇÕES
    // ═══════════════════════════════════════════════════════════════════════════════
    
    const Toast = {
        container: null,
        
        init() {
            if (this.container) return;
            this.container = document.createElement('div');
            this.container.id = 'om-toast-container';
            this.container.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 10px;
                pointer-events: none;
            `;
            document.body.appendChild(this.container);
        },
        
        show(message, type = 'info', duration = CONFIG.toastDuration) {
            this.init();
            
            const colors = {
                success: { bg: '#10b981', icon: '✓' },
                error: { bg: '#ef4444', icon: '✕' },
                warning: { bg: '#f59e0b', icon: '⚠' },
                info: { bg: '#3b82f6', icon: 'ℹ' }
            };
            
            const { bg, icon } = colors[type] || colors.info;
            
            const toast = document.createElement('div');
            toast.style.cssText = `
                background: ${bg};
                color: white;
                padding: 14px 20px;
                border-radius: 12px;
                font-size: 14px;
                font-weight: 500;
                display: flex;
                align-items: center;
                gap: 10px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.2);
                transform: translateX(120%);
                transition: transform 0.3s ease;
                pointer-events: auto;
            `;
            toast.innerHTML = `<span style="font-size:18px">${icon}</span>${message}`;
            
            this.container.appendChild(toast);
            
            // Animar entrada
            requestAnimationFrame(() => {
                toast.style.transform = 'translateX(0)';
            });
            
            // Remover após duração
            setTimeout(() => {
                toast.style.transform = 'translateX(120%)';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        },
        
        success(msg) { this.show(msg, 'success'); },
        error(msg) { this.show(msg, 'error'); },
        warning(msg) { this.show(msg, 'warning'); },
        info(msg) { this.show(msg, 'info'); }
    };
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // CARRINHO
    // ═══════════════════════════════════════════════════════════════════════════════
    
    const Cart = {
        items: [],
        
        init() {
            this.load();
            this.renderFloat();
            this.bindEvents();
        },
        
        load() {
            try {
                const saved = localStorage.getItem(CONFIG.cartKey);
                this.items = saved ? JSON.parse(saved) : [];
            } catch (e) {
                this.items = [];
            }
        },
        
        save() {
            localStorage.setItem(CONFIG.cartKey, JSON.stringify(this.items));
            this.updateUI();
            
            // Sincronizar com backend
            this.sync();
        },
        
        async sync() {
            try {
                await fetch(CONFIG.apiBase + '/cart.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'sync', items: this.items })
                });
            } catch (e) {
                console.warn('Erro ao sincronizar carrinho:', e);
            }
        },
        
        add(product, qty = 1) {
            const existing = this.items.find(i => i.id === product.id);
            
            if (existing) {
                existing.qty += qty;
            } else {
                this.items.push({
                    id: product.id,
                    name: product.name,
                    price: product.price,
                    price_promo: product.price_promo || 0,
                    image: product.image,
                    weight: product.weight || '',
                    qty: qty
                });
            }
            
            this.save();
            Toast.success('Adicionado ao carrinho!');
            this.animateFloat();
        },
        
        remove(productId) {
            this.items = this.items.filter(i => i.id !== productId);
            this.save();
            Toast.info('Removido do carrinho');
        },
        
        updateQty(productId, qty) {
            const item = this.items.find(i => i.id === productId);
            if (item) {
                if (qty <= 0) {
                    this.remove(productId);
                } else {
                    item.qty = qty;
                    this.save();
                }
            }
        },
        
        clear() {
            this.items = [];
            this.save();
        },
        
        getTotal() {
            return this.items.reduce((sum, item) => {
                const price = item.price_promo > 0 ? item.price_promo : item.price;
                return sum + (price * item.qty);
            }, 0);
        },
        
        getCount() {
            return this.items.reduce((sum, item) => sum + item.qty, 0);
        },
        
        // UI
        renderFloat() {
            // Remover existente
            const existing = $('#om-cart-float');
            if (existing) existing.remove();
            
            const count = this.getCount();
            const total = this.getTotal();
            
            if (count === 0) return;
            
            const float = document.createElement('div');
            float.id = 'om-cart-float';
            float.className = 'om-cart-float';
            float.innerHTML = `
                <div class="om-cart-float-icon">
                    🛒
                    <span class="om-cart-float-badge">${count}</span>
                </div>
                <div class="om-cart-float-info">
                    <div class="om-cart-float-label">Ver carrinho</div>
                    <div class="om-cart-float-total">${formatMoney(total)}</div>
                </div>
                <span style="font-size:20px">→</span>
            `;
            
            float.onclick = () => this.openModal();
            document.body.appendChild(float);
        },
        
        animateFloat() {
            const float = $('#om-cart-float');
            if (float) {
                float.style.transform = 'translateX(-50%) scale(1.1)';
                setTimeout(() => {
                    float.style.transform = 'translateX(-50%) scale(1)';
                }, 200);
            }
        },
        
        updateUI() {
            this.renderFloat();
            this.renderModal();
            
            // Atualizar badges no header
            $$('.om-header-cart-count').forEach(el => {
                el.textContent = this.getCount();
                el.style.display = this.getCount() > 0 ? 'flex' : 'none';
            });
        },
        
        openModal() {
            this.renderModal();
            const modal = $('#om-cart-modal');
            const overlay = $('#om-cart-overlay');
            if (modal) modal.classList.add('open');
            if (overlay) overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
        },
        
        closeModal() {
            const modal = $('#om-cart-modal');
            const overlay = $('#om-cart-overlay');
            if (modal) modal.classList.remove('open');
            if (overlay) overlay.classList.remove('open');
            document.body.style.overflow = '';
        },
        
        renderModal() {
            // Remover existentes
            $('#om-cart-modal')?.remove();
            $('#om-cart-overlay')?.remove();
            
            const subtotal = this.getTotal();
            const delivery = subtotal >= 99 ? 0 : 9.90;
            const total = subtotal + delivery;
            
            const overlay = document.createElement('div');
            overlay.id = 'om-cart-overlay';
            overlay.className = 'om-cart-overlay';
            overlay.onclick = () => this.closeModal();
            
            const modal = document.createElement('div');
            modal.id = 'om-cart-modal';
            modal.className = 'om-cart-modal';
            modal.innerHTML = `
                <div class="om-cart-header">
                    <h2>🛒 Carrinho (${this.getCount()})</h2>
                    <button class="om-cart-close" onclick="OMApp.Cart.closeModal()">✕</button>
                </div>
                
                <div class="om-cart-items">
                    ${this.items.length === 0 ? `
                        <div style="text-align:center;padding:40px;color:#94a3b8">
                            <div style="font-size:48px;margin-bottom:16px">🛒</div>
                            <p>Seu carrinho está vazio</p>
                        </div>
                    ` : this.items.map(item => {
                        const price = item.price_promo > 0 ? item.price_promo : item.price;
                        return `
                            <div class="om-cart-item" data-id="${item.id}">
                                <div class="om-cart-item-image">
                                    <img src="${item.image || '/mercado/assets/img/no-image.png'}" alt="">
                                </div>
                                <div class="om-cart-item-info">
                                    <div class="om-cart-item-name">${item.name}</div>
                                    <div class="om-cart-item-price">${formatMoney(price)}</div>
                                    <div class="om-qty-stepper" style="margin-top:8px">
                                        <button class="om-qty-btn" onclick="OMApp.Cart.updateQty(${item.id}, ${item.qty - 1})">−</button>
                                        <span class="om-qty-value">${item.qty}</span>
                                        <button class="om-qty-btn" onclick="OMApp.Cart.updateQty(${item.id}, ${item.qty + 1})">+</button>
                                    </div>
                                </div>
                                <button onclick="OMApp.Cart.remove(${item.id})" style="background:none;border:none;font-size:20px;cursor:pointer;color:#ef4444">🗑</button>
                            </div>
                        `;
                    }).join('')}
                </div>
                
                ${this.items.length > 0 ? `
                    <div class="om-cart-footer">
                        <div class="om-cart-summary">
                            <div class="om-cart-summary-row">
                                <span>Subtotal</span>
                                <span>${formatMoney(subtotal)}</span>
                            </div>
                            <div class="om-cart-summary-row">
                                <span>Entrega</span>
                                <span>${delivery === 0 ? '<span style="color:#10b981">Grátis</span>' : formatMoney(delivery)}</span>
                            </div>
                            ${subtotal < 99 ? `
                                <div style="background:#fef3c7;color:#92400e;padding:8px 12px;border-radius:8px;font-size:13px;margin:8px 0">
                                    Faltam ${formatMoney(99 - subtotal)} para frete grátis!
                                </div>
                            ` : ''}
                            <div class="om-cart-summary-row om-cart-summary-total">
                                <span>Total</span>
                                <span>${formatMoney(total)}</span>
                            </div>
                        </div>
                        <a href="/mercado/checkout.php" class="om-btn om-btn-primary om-btn-block">
                            Finalizar Compra →
                        </a>
                    </div>
                ` : ''}
            `;
            
            document.body.appendChild(overlay);
            document.body.appendChild(modal);
        },
        
        bindEvents() {
            // Botões de adicionar ao carrinho
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-add-cart]');
                if (btn) {
                    e.preventDefault();
                    const product = JSON.parse(btn.dataset.addCart);
                    this.add(product);
                }
            });
        }
    };
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // BUSCA
    // ═══════════════════════════════════════════════════════════════════════════════
    
    const Search = {
        init() {
            const input = $('#om-search-input');
            if (!input) return;
            
            input.addEventListener('input', debounce((e) => {
                const query = e.target.value.trim();
                if (query.length >= 2) {
                    this.search(query);
                } else {
                    this.hideResults();
                }
            }, 300));
            
            // Fechar ao clicar fora
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.om-search-container')) {
                    this.hideResults();
                }
            });
        },
        
        async search(query) {
            try {
                const res = await fetch(`${CONFIG.apiBase}/busca.php?q=${encodeURIComponent(query)}`);
                const data = await res.json();
                
                if (data.success && data.products) {
                    this.showResults(data.products);
                }
            } catch (e) {
                console.error('Erro na busca:', e);
            }
        },
        
        showResults(products) {
            let container = $('#om-search-results');
            if (!container) {
                container = document.createElement('div');
                container.id = 'om-search-results';
                container.style.cssText = `
                    position: absolute;
                    top: 100%;
                    left: 0;
                    right: 0;
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                    max-height: 400px;
                    overflow-y: auto;
                    z-index: 1001;
                `;
                $('.om-search-container')?.appendChild(container);
            }
            
            container.innerHTML = products.slice(0, 8).map(p => `
                <a href="/mercado/produto.php?id=${p.id}" style="display:flex;gap:12px;padding:12px;border-bottom:1px solid #e2e8f0;text-decoration:none;color:inherit">
                    <img src="${p.image || '/mercado/assets/img/no-image.png'}" style="width:50px;height:50px;object-fit:cover;border-radius:8px">
                    <div>
                        <div style="font-weight:600;font-size:14px">${p.name}</div>
                        <div style="color:#f97316;font-weight:700">${formatMoney(p.price)}</div>
                    </div>
                </a>
            `).join('') || '<div style="padding:20px;text-align:center;color:#94a3b8">Nenhum produto encontrado</div>';
            
            container.style.display = 'block';
        },
        
        hideResults() {
            const container = $('#om-search-results');
            if (container) container.style.display = 'none';
        }
    };
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // MODAL GENÉRICO
    // ═══════════════════════════════════════════════════════════════════════════════
    
    const Modal = {
        open(content, options = {}) {
            const { title = '', width = '500px', onClose } = options;
            
            // Remover existente
            $('#om-modal-overlay')?.remove();
            
            const overlay = document.createElement('div');
            overlay.id = 'om-modal-overlay';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.6);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
                padding: 20px;
            `;
            
            overlay.innerHTML = `
                <div style="background:white;border-radius:20px;width:100%;max-width:${width};max-height:90vh;overflow:auto;position:relative">
                    ${title ? `
                        <div style="padding:20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
                            <h2 style="font-size:18px;margin:0">${title}</h2>
                            <button onclick="OMApp.Modal.close()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#94a3b8">✕</button>
                        </div>
                    ` : `
                        <button onclick="OMApp.Modal.close()" style="position:absolute;top:16px;right:16px;background:#f1f5f9;border:none;width:36px;height:36px;border-radius:50%;font-size:18px;cursor:pointer">✕</button>
                    `}
                    <div style="padding:20px">${content}</div>
                </div>
            `;
            
            overlay.onclick = (e) => {
                if (e.target === overlay) this.close();
            };
            
            document.body.appendChild(overlay);
            document.body.style.overflow = 'hidden';
            
            this._onClose = onClose;
        },
        
        close() {
            const overlay = $('#om-modal-overlay');
            if (overlay) {
                overlay.remove();
                document.body.style.overflow = '';
                if (this._onClose) this._onClose();
            }
        }
    };
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // INICIALIZAÇÃO
    // ═══════════════════════════════════════════════════════════════════════════════
    
    function init() {
        Cart.init();
        Search.init();
        
        console.log('🛒 OMApp Mercado carregado!');
    }
    
    // Iniciar quando DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // PREMIUM ANIMATIONS (Instacart/iFood Level)
    // ═══════════════════════════════════════════════════════════════════════════════

    const SBAnimations = {
        // Fly to Cart Animation
        flyToCart(element, cartButton) {
            if (!element || !cartButton) return;

            const elementRect = element.getBoundingClientRect();
            const cartRect = cartButton.getBoundingClientRect();

            // Create flying element
            const flyingEl = element.cloneNode(true);
            flyingEl.style.cssText = `
                position: fixed;
                top: ${elementRect.top}px;
                left: ${elementRect.left}px;
                width: ${elementRect.width}px;
                height: ${elementRect.height}px;
                z-index: 10000;
                pointer-events: none;
                transition: all 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94);
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(16, 185, 129, 0.4);
            `;

            document.body.appendChild(flyingEl);

            // Trigger animation
            requestAnimationFrame(() => {
                flyingEl.style.top = `${cartRect.top + cartRect.height / 2}px`;
                flyingEl.style.left = `${cartRect.left + cartRect.width / 2}px`;
                flyingEl.style.width = '20px';
                flyingEl.style.height = '20px';
                flyingEl.style.opacity = '0';
                flyingEl.style.transform = 'rotate(360deg)';
            });

            // Bounce cart
            setTimeout(() => {
                cartButton.classList.add('bounce');
                setTimeout(() => cartButton.classList.remove('bounce'), 500);
            }, 500);

            // Remove flying element
            setTimeout(() => flyingEl.remove(), 600);
        },

        // Ripple Effect
        createRipple(event, element) {
            if (!element) element = event.currentTarget;

            const ripple = document.createElement('span');
            const rect = element.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = event.clientX - rect.left - size / 2;
            const y = event.clientY - rect.top - size / 2;

            ripple.style.cssText = `
                position: absolute;
                width: ${size}px;
                height: ${size}px;
                left: ${x}px;
                top: ${y}px;
                background: rgba(255, 255, 255, 0.4);
                border-radius: 50%;
                transform: scale(0);
                animation: sb-ripple 0.6s linear;
                pointer-events: none;
            `;

            ripple.className = 'sb-ripple';
            element.appendChild(ripple);

            setTimeout(() => ripple.remove(), 600);
        },

        // Stagger Items Animation
        staggerItems(selector, delay = 50) {
            const items = document.querySelectorAll(selector);
            items.forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(20px)';

                setTimeout(() => {
                    item.style.transition = 'all 0.5s ease';
                    item.style.opacity = '1';
                    item.style.transform = 'translateY(0)';
                }, delay * index);
            });
        },

        // Parallax on Scroll
        initParallax() {
            const parallaxElements = document.querySelectorAll('[data-parallax]');

            if (parallaxElements.length === 0) return;

            const handleScroll = () => {
                const scrollY = window.scrollY;

                parallaxElements.forEach(el => {
                    const speed = parseFloat(el.dataset.parallax) || 0.5;
                    const rect = el.getBoundingClientRect();
                    const inView = rect.top < window.innerHeight && rect.bottom > 0;

                    if (inView) {
                        const yPos = -(scrollY * speed);
                        el.style.transform = `translate3d(0, ${yPos}px, 0)`;
                    }
                });
            };

            window.addEventListener('scroll', handleScroll, { passive: true });
            handleScroll();
        },

        // Intersection Observer for Animations
        initScrollAnimations() {
            const animatedElements = document.querySelectorAll('[data-animate]');

            if (animatedElements.length === 0) return;

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const animation = entry.target.dataset.animate;
                        entry.target.classList.add(`sb-animate-${animation}`);
                        observer.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '50px'
            });

            animatedElements.forEach(el => observer.observe(el));
        },

        // Header Scroll Effect
        initHeaderScroll() {
            const header = document.querySelector('.header, .sb-header-premium');
            if (!header) return;

            let lastScroll = 0;

            window.addEventListener('scroll', () => {
                const currentScroll = window.scrollY;

                if (currentScroll > 50) {
                    header.classList.add('scrolled');
                } else {
                    header.classList.remove('scrolled');
                }

                // Hide/show on scroll direction
                if (currentScroll > lastScroll && currentScroll > 200) {
                    header.style.transform = 'translateY(-100%)';
                } else {
                    header.style.transform = 'translateY(0)';
                }

                lastScroll = currentScroll;
            }, { passive: true });
        },

        // Skeleton Loading
        showSkeleton(container, count = 6) {
            const skeletonHTML = `
                <div class="sb-skeleton-card">
                    <div class="sb-skeleton-card__image"></div>
                    <div class="sb-skeleton-card__content">
                        <div class="sb-skeleton-card__line"></div>
                        <div class="sb-skeleton-card__line"></div>
                        <div class="sb-skeleton-card__line" style="width: 60%"></div>
                    </div>
                </div>
            `;

            if (typeof container === 'string') {
                container = document.querySelector(container);
            }

            if (container) {
                container.innerHTML = Array(count).fill(skeletonHTML).join('');
            }
        },

        // Number Counter Animation
        animateCounter(element, target, duration = 1000) {
            if (typeof element === 'string') {
                element = document.querySelector(element);
            }

            if (!element) return;

            const start = 0;
            const increment = target / (duration / 16);
            let current = start;

            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    current = target;
                    clearInterval(timer);
                }
                element.textContent = Math.floor(current).toLocaleString('pt-BR');
            }, 16);
        },

        // Shake Animation (for errors)
        shake(element) {
            if (typeof element === 'string') {
                element = document.querySelector(element);
            }

            if (!element) return;

            element.style.animation = 'sb-wiggle 0.5s ease';
            setTimeout(() => {
                element.style.animation = '';
            }, 500);
        },

        // Success Pulse
        successPulse(element) {
            if (typeof element === 'string') {
                element = document.querySelector(element);
            }

            if (!element) return;

            element.style.animation = 'sb-glow-pulse 0.5s ease';
            setTimeout(() => {
                element.style.animation = '';
            }, 500);
        },

        // Add to Cart Animation (Complete)
        addToCartAnimation(productCard, imageEl) {
            const cart = document.querySelector('.header__cart, .sb-header-premium__cart, #cartBtn');

            if (!cart) {
                console.warn('Cart button not found');
                return;
            }

            // Get image element
            let img = imageEl;
            if (!img && productCard) {
                img = productCard.querySelector('img');
            }

            if (img) {
                this.flyToCart(img, cart);
            }

            // Pulse effect on card
            if (productCard) {
                productCard.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    productCard.style.transform = '';
                }, 150);
            }
        }
    };

    // ═══════════════════════════════════════════════════════════════════════════════
    // PREMIUM PRODUCT CARD INTERACTIONS
    // ═══════════════════════════════════════════════════════════════════════════════

    const ProductCard = {
        init() {
            // Add ripple effect to all buttons
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('.sb-btn-premium, .sb-ripple-container');
                if (btn) {
                    SBAnimations.createRipple(e, btn);
                }
            });

            // Add button hover sound (optional, visual only by default)
            document.querySelectorAll('.sb-product-card-premium__add-btn').forEach(btn => {
                btn.addEventListener('mouseenter', () => {
                    btn.style.transform = 'scale(1.15) rotate(90deg)';
                });

                btn.addEventListener('mouseleave', () => {
                    btn.style.transform = '';
                });
            });
        },

        // Convert add button to quantity stepper
        showStepper(button, productId, initialQty = 1) {
            const container = button.parentElement;

            const stepper = document.createElement('div');
            stepper.className = 'sb-qty-stepper';
            stepper.innerHTML = `
                <button class="sb-qty-stepper__btn" onclick="OMApp.ProductCard.updateQty(${productId}, -1)">−</button>
                <span class="sb-qty-stepper__value">${initialQty}</span>
                <button class="sb-qty-stepper__btn" onclick="OMApp.ProductCard.updateQty(${productId}, 1)">+</button>
            `;

            stepper.style.animation = 'sb-pop-in 0.3s ease';
            button.replaceWith(stepper);
        },

        updateQty(productId, change) {
            // This would integrate with your cart system
            const stepper = document.querySelector(`[data-product-id="${productId}"] .sb-qty-stepper`);
            if (stepper) {
                const valueEl = stepper.querySelector('.sb-qty-stepper__value');
                let qty = parseInt(valueEl.textContent) + change;

                if (qty <= 0) {
                    // Animate removal and replace with add button
                    stepper.style.animation = 'sb-scale-in 0.3s ease reverse';
                    setTimeout(() => {
                        // Replace with add button
                        const addBtn = document.createElement('button');
                        addBtn.className = 'sb-product-card-premium__add-btn';
                        addBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>`;
                        stepper.replaceWith(addBtn);
                    }, 300);
                } else {
                    valueEl.textContent = qty;
                    valueEl.style.animation = 'sb-pop-in 0.2s ease';
                    setTimeout(() => valueEl.style.animation = '', 200);
                }
            }
        }
    };

    // ═══════════════════════════════════════════════════════════════════════════════
    // INITIALIZE PREMIUM FEATURES
    // ═══════════════════════════════════════════════════════════════════════════════

    function initPremiumFeatures() {
        // Initialize scroll animations
        SBAnimations.initScrollAnimations();

        // Initialize header scroll effect
        SBAnimations.initHeaderScroll();

        // Initialize parallax
        SBAnimations.initParallax();

        // Initialize product card interactions
        ProductCard.init();

        // Stagger product cards on page load
        setTimeout(() => {
            SBAnimations.staggerItems('.products-grid .product-card, .sb-product-card-premium');
        }, 100);

        console.log('Premium animations initialized');
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPremiumFeatures);
    } else {
        initPremiumFeatures();
    }

    // Expor API global
    window.OMApp = {
        Cart,
        Search,
        Modal,
        Toast,
        formatMoney,
        CONFIG,
        SBAnimations,
        ProductCard
    };

})();