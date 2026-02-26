/**
 * ═══════════════════════════════════════════════════════════════════════════
 * 🎨 ONEMUNDO - PREMIUM MICRO-INTERACTIONS
 * 
 * Efeitos modernos adicionados:
 * - Parallax suave
 * - Cursor customizado
 * - Partículas ao adicionar no carrinho
 * - Smooth scroll animado
 * - Loading states premium
 * - Hover 3D effects
 * - Confetti celebrations
 * - Toast notifications melhoradas
 * ═══════════════════════════════════════════════════════════════════════════
 */

// ═══════════════════════════════════════════════════════════════════════════
// 🎯 CURSOR CUSTOMIZADO COM EFEITO MAGNÉTICO
// ═══════════════════════════════════════════════════════════════════════════
(function initMagneticCursor() {
    // Verificar se o usuário tem preferência por movimentos reduzidos
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }
    
    // Verificar se já existe um cursor customizado
    if (document.querySelector('.custom-cursor')) {
        return;
    }
    
    const cursor = document.createElement('div');
    cursor.className = 'custom-cursor';
    
    const cursorDot = document.createElement('div');
    cursorDot.className = 'cursor-dot';
    const cursorRing = document.createElement('div');
    cursorRing.className = 'cursor-ring';
    
    cursor.appendChild(cursorDot);
    cursor.appendChild(cursorRing);
    document.body.appendChild(cursor);

    const cursorDot = cursor.querySelector('.cursor-dot');
    const cursorRing = cursor.querySelector('.cursor-ring');
    
    let mouseX = 0, mouseY = 0;
    let cursorX = 0, cursorY = 0;
    let ringX = 0, ringY = 0;

    document.addEventListener('mousemove', (e) => {
        mouseX = e.clientX;
        mouseY = e.clientY;
    });

    // Smooth follow
    function animateCursor() {
        cursorX += (mouseX - cursorX) * 0.2;
        cursorY += (mouseY - cursorY) * 0.2;
        
        ringX += (mouseX - ringX) * 0.15;
        ringY += (mouseY - ringY) * 0.15;

        cursorDot.style.transform = `translate(${cursorX}px, ${cursorY}px)`;
        cursorRing.style.transform = `translate(${ringX}px, ${ringY}px)`;

        requestAnimationFrame(animateCursor);
    }
    animateCursor();

    // Hover effects
    const hoverElements = document.querySelectorAll('a, button, .product-card, .category-card');
    hoverElements.forEach(el => {
        el.addEventListener('mouseenter', () => {
            cursor.classList.add('hover');
        });
        el.addEventListener('mouseleave', () => {
            cursor.classList.remove('hover');
        });
    });
})();

// CSS para o cursor (adicione ao seu CSS)
const cursorStyles = `
.custom-cursor {
    position: fixed;
    top: 0;
    left: 0;
    pointer-events: none;
    z-index: 9999;
}

.cursor-dot {
    width: 8px;
    height: 8px;
    background: var(--primary);
    border-radius: 50%;
    position: absolute;
    transform: translate(-50%, -50%);
    transition: width 0.3s, height 0.3s;
}

.cursor-ring {
    width: 40px;
    height: 40px;
    border: 2px solid var(--primary);
    border-radius: 50%;
    position: absolute;
    transform: translate(-50%, -50%);
    opacity: 0.5;
    transition: width 0.3s, height 0.3s, opacity 0.3s;
}

.custom-cursor.hover .cursor-dot {
    width: 16px;
    height: 16px;
}

.custom-cursor.hover .cursor-ring {
    width: 60px;
    height: 60px;
    opacity: 0.8;
}

@media (max-width: 768px) {
    .custom-cursor { display: none; }
}
`;

// ═══════════════════════════════════════════════════════════════════════════
// 🌊 PARALLAX SCROLL SUAVE
// ═══════════════════════════════════════════════════════════════════════════
function initParallax() {
    const parallaxElements = document.querySelectorAll('.hero-cards-container, .hero-image');
    
    let ticking = false;

function updateParallax() {
    const scrolled = window.pageYOffset;
    
    parallaxElements.forEach((el, index) => {
        const speed = 0.3 + (index * 0.1);
        const yPos = -(scrolled * speed);
        el.style.transform = `translateY(${yPos}px)`;
    });
    
    ticking = false;
}

window.addEventListener('scroll', () => {
    if (!ticking) {
        requestAnimationFrame(updateParallax);
        ticking = true;
    }
});
}

// ═══════════════════════════════════════════════════════════════════════════
// ✨ PARTÍCULAS AO ADICIONAR NO CARRINHO
// ═══════════════════════════════════════════════════════════════════════════
let activeParticles = 0;
const MAX_PARTICLES = 100;

function createParticles(x, y) {
    if (activeParticles >= MAX_PARTICLES) return;
    
    const colors = ['#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b'];
    const particleCount = Math.min(15, MAX_PARTICLES - activeParticles);
    
    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        activeParticles++;
        particle.className = 'particle';
        particle.style.cssText = `
            position: fixed;
            width: 8px;
            height: 8px;
            background: ${colors[Math.floor(Math.random() * colors.length)]};
            border-radius: 50%;
            pointer-events: none;
            z-index: 9999;
            left: ${x}px;
            top: ${y}px;
        `;
        
        document.body.appendChild(particle);
        
        const angle = (Math.PI * 2 * i) / particleCount;
        const velocity = 100 + Math.random() * 100;
        const vx = Math.cos(angle) * velocity;
        const vy = Math.sin(angle) * velocity;
        
        particle.animate([
            { 
                transform: 'translate(0, 0) scale(1)',
                opacity: 1 
            },
            { 
                transform: `translate(${vx}px, ${vy}px) scale(0)`,
                opacity: 0 
            }
        ], {
            duration: 1000,
            easing: 'cubic-bezier(0, 0.55, 0.45, 1)'
        }).onfinish = () => particle.remove();
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 🎊 CONFETTI CELEBRATION MELHORADO
// ═══════════════════════════════════════════════════════════════════════════
function celebrateWithConfetti(element) {
    const rect = element.getBoundingClientRect();
    const centerX = rect.left + rect.width / 2;
    const centerY = rect.top + rect.height / 2;
    
    // Partículas coloridas
    createParticles(centerX, centerY);
    
    // Emoji celebration
    const emojis = ['🎉', '✨', '⭐', '🌟', '💫'];
    const emojiCount = 5;
    
    for (let i = 0; i < emojiCount; i++) {
        const emoji = document.createElement('div');
        emoji.textContent = emojis[Math.floor(Math.random() * emojis.length)];
        emoji.style.cssText = `
            position: fixed;
            font-size: 24px;
            pointer-events: none;
            z-index: 9999;
            left: ${centerX}px;
            top: ${centerY}px;
        `;
        
        document.body.appendChild(emoji);
        
        const randomX = (Math.random() - 0.5) * 200;
        const randomY = -100 - Math.random() * 100;
        const randomRotation = (Math.random() - 0.5) * 360;
        
        emoji.animate([
            { 
                transform: 'translate(0, 0) rotate(0deg) scale(1)',
                opacity: 1 
            },
            { 
                transform: `translate(${randomX}px, ${randomY}px) rotate(${randomRotation}deg) scale(0)`,
                opacity: 0 
            }
        ], {
            duration: 1500,
            easing: 'cubic-bezier(0.25, 0.46, 0.45, 0.94)'
        }).onfinish = () => emoji.remove();
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 🎨 SMOOTH SCROLL COM EASING
// ═══════════════════════════════════════════════════════════════════════════
function smoothScrollTo(target, duration = 800) {
    const targetElement = typeof target === 'string' ? document.querySelector(target) : target;
    if (!targetElement) return;
    
    const targetPosition = targetElement.getBoundingClientRect().top + window.pageYOffset - 80;
    const startPosition = window.pageYOffset;
    const distance = targetPosition - startPosition;
    let startTime = null;
    
    function animation(currentTime) {
        if (startTime === null) startTime = currentTime;
        const timeElapsed = currentTime - startTime;
        const progress = Math.min(timeElapsed / duration, 1);
        
        // Easing function (easeInOutCubic)
        const ease = progress < 0.5 
            ? 4 * progress * progress * progress 
            : 1 - Math.pow(-2 * progress + 2, 3) / 2;
        
        window.scrollTo(0, startPosition + distance * ease);
        
        if (timeElapsed < duration) {
            requestAnimationFrame(animation);
        }
    }
    
    requestAnimationFrame(animation);
}

// ═══════════════════════════════════════════════════════════════════════════
// 💎 LOADING STATES PREMIUM
// ═══════════════════════════════════════════════════════════════════════════
function showLoadingState(element) {
    const loader = document.createElement('div');
    loader.className = 'premium-loader';
    loader.innerHTML = `
        <div class="loader-spinner"></div>
        <div class="loader-text">Carregando...</div>
    `;
    element.appendChild(loader);
    element.classList.add('is-loading');
}

function hideLoadingState(element) {
    const loader = element.querySelector('.premium-loader');
    if (loader) {
        loader.style.opacity = '0';
        setTimeout(() => {
            loader.remove();
            element.classList.remove('is-loading');
        }, 300);
    }
}

// CSS para o loader
const loaderStyles = `
.premium-loader {
    position: absolute;
    inset: 0;
    background: rgba(255, 255, 255, 0.98);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    z-index: 100;
    transition: opacity 0.3s;
}

.loader-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid var(--gray-200);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

.loader-text {
    font-size: 14px;
    font-weight: 600;
    color: var(--gray-600);
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
`;

// ═══════════════════════════════════════════════════════════════════════════
// 🎭 TOAST NOTIFICATIONS MELHORADAS
// ═══════════════════════════════════════════════════════════════════════════
function showPremiumToast(message, type = 'success', duration = 3000) {
    const toast = document.createElement('div');
    toast.className = `premium-toast toast-${type}`;
    
    const icons = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };
    
    const iconElement = document.createElement('div');
iconElement.className = 'toast-icon';
iconElement.textContent = icons[type];

const messageElement = document.createElement('div');
messageElement.className = 'toast-message';
messageElement.textContent = message;

const progressElement = document.createElement('div');
progressElement.className = 'toast-progress';

toast.appendChild(iconElement);
toast.appendChild(messageElement);
toast.appendChild(progressElement);
    
    document.body.appendChild(toast);
    
    // Animar entrada
    setTimeout(() => toast.classList.add('show'), 10);
    
    // Animar progresso
    const progress = toast.querySelector('.toast-progress');
    progress.style.animation = `toast-progress ${duration}ms linear`;
    
    // Remover após duração
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, duration);
    
    // Fechar ao clicar
    toast.addEventListener('click', () => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    });
}

// CSS para o toast
const toastStyles = `
.premium-toast {
    position: fixed;
    top: 24px;
    right: 24px;
    min-width: 320px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(16px);
    border-radius: 12px;
    padding: 16px 20px;
    box-shadow: 
        0 16px 48px rgba(0, 0, 0, 0.15),
        0 0 0 1px rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    transform: translateX(400px);
    opacity: 0;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 10000;
    overflow: hidden;
}

.premium-toast.show {
    transform: translateX(0);
    opacity: 1;
}

.toast-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: bold;
    flex-shrink: 0;
}

.toast-success .toast-icon {
    background: #d1fae5;
    color: #059669;
}

.toast-error .toast-icon {
    background: #fee2e2;
    color: #dc2626;
}

.toast-warning .toast-icon {
    background: #fef3c7;
    color: #f59e0b;
}

.toast-info .toast-icon {
    background: #dbeafe;
    color: #3b82f6;
}

.toast-message {
    flex: 1;
    font-size: 14px;
    font-weight: 500;
    color: var(--gray-900);
}

.toast-progress {
    position: absolute;
    bottom: 0;
    left: 0;
    height: 3px;
    background: var(--primary);
    width: 100%;
}

@keyframes toast-progress {
    from { transform: scaleX(1); }
    to { transform: scaleX(0); }
}

@media (max-width: 640px) {
    .premium-toast {
        right: 12px;
        left: 12px;
        min-width: auto;
    }
}
`;

// ═══════════════════════════════════════════════════════════════════════════
// 🎯 HOVER 3D EFFECT NOS CARDS
// ═══════════════════════════════════════════════════════════════════════════
// Cache global para elementos
const elementCache = new Map();

function getCachedElements(selector) {
    if (!elementCache.has(selector)) {
        elementCache.set(selector, document.querySelectorAll(selector));
    }
    return elementCache.get(selector);
}

function init3DHoverEffect() {
    const cards = getCachedElements('.product-card, .category-card');
    
    cards.forEach(card => {
        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            
            const rotateX = (y - centerY) / 10;
            const rotateY = (centerX - x) / 10;
            
            card.style.transform = `
                perspective(1000px) 
                rotateX(${rotateX}deg) 
                rotateY(${rotateY}deg) 
                scale(1.05)
            `;
        });
        
        card.addEventListener('mouseleave', () => {
            card.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) scale(1)';
        });
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// 🌟 LAZY LOADING COM FADE IN
// ═══════════════════════════════════════════════════════════════════════════
function initLazyLoading() {
    const images = document.querySelectorAll('img[loading="lazy"]');
    
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src || img.src;
                img.classList.add('loaded');
                observer.unobserve(img);
            }
        });
    }, {
        rootMargin: '50px'
    });
    
    images.forEach(img => imageObserver.observe(img));
}

// ═══════════════════════════════════════════════════════════════════════════
// 🎪 SCROLL ANIMATIONS
// ═══════════════════════════════════════════════════════════════════════════
function initScrollAnimations() {
    const animateElements = document.querySelectorAll('.animate-on-scroll');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animated');
            }
        });
    }, {
        threshold: 0.1
    });
    
    animateElements.forEach(el => observer.observe(el));
}

// ═══════════════════════════════════════════════════════════════════════════
// 🚀 INICIALIZAÇÃO
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    // Verificar se os estilos já foram adicionados
    if (document.querySelector('#onemundo-premium-styles')) {
        return;
    }
    
    // Adicionar estilos de forma otimizada
    const style = document.createElement('style');
    style.id = 'onemundo-premium-styles';
    
    // Minificar CSS removendo espaços desnecessários
    const minifiedStyles = (cursorStyles + loaderStyles + toastStyles)
        .replace(/\s+/g, ' ')
        .replace(/;\s*}/g, '}')
        .trim();
    
    style.textContent = minifiedStyles;
    document.head.appendChild(style);
    
    // Inicializar efeitos
    initParallax();
    init3DHoverEffect();
    initLazyLoading();
    initScrollAnimations();
    
    console.log('✨ OneMundo Premium Effects Loaded');
});

// ═══════════════════════════════════════════════════════════════════════════
// 📦 EXPORTAR FUNÇÕES ÚTEIS
// ═══════════════════════════════════════════════════════════════════════════
// Verificar se o namespace já existe
if (typeof window.OneMundoPremium === 'undefined') {
    window.OneMundoPremium = {
        toast: showPremiumToast,
        confetti: celebrateWithConfetti,
        particles: createParticles,
        smoothScroll: smoothScrollTo,
        showLoading: showLoadingState,
        hideLoading: hideLoadingState,
        version: '1.0.0'
    };
} else {
    console.warn('OneMundoPremium namespace já existe. Pulando inicialização.');
}

// EXEMPLOS DE USO:
// OneMundoPremium.toast('Produto adicionado!', 'success');
// OneMundoPremium.confetti(buttonElement);
// OneMundoPremium.smoothScroll('#produtos');
