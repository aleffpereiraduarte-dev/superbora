/**
 * Page Builder JavaScript - Mercado OneMundo
 * Funcionalidades interativas do frontend
 */

(function() {
    'use strict';
    
    // ==================== INICIALIZAÇÃO ====================
    document.addEventListener('DOMContentLoaded', function() {
        initCountdowns();
        initCarousels();
        initAnimations();
        initGalleryLightbox();
        initNewsletterForms();
        initLazyLoading();
    });
    
    // ==================== COUNTDOWN ====================
    function initCountdowns() {
        const countdowns = document.querySelectorAll('.pb-countdown[data-end]');
        
        countdowns.forEach(countdown => {
            const endDate = new Date(countdown.dataset.end).getTime();
            
            function updateCountdown() {
                const now = new Date().getTime();
                const distance = endDate - now;
                
                if (distance < 0) {
                    countdown.querySelector('[data-days]').textContent = '00';
                    countdown.querySelector('[data-hours]').textContent = '00';
                    countdown.querySelector('[data-minutes]').textContent = '00';
                    countdown.querySelector('[data-seconds]').textContent = '00';
                    return;
                }
                
                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                
                countdown.querySelector('[data-days]').textContent = String(days).padStart(2, '0');
                countdown.querySelector('[data-hours]').textContent = String(hours).padStart(2, '0');
                countdown.querySelector('[data-minutes]').textContent = String(minutes).padStart(2, '0');
                countdown.querySelector('[data-seconds]').textContent = String(seconds).padStart(2, '0');
            }
            
            updateCountdown();
            setInterval(updateCountdown, 1000);
        });
    }
    
    // ==================== CARROSSEL ====================
    function initCarousels() {
        const carousels = document.querySelectorAll('.pb-carousel');
        
        carousels.forEach(carousel => {
            const track = carousel.querySelector('.pb-carousel__track');
            const slides = carousel.querySelectorAll('.pb-carousel__slide');
            const prevBtn = carousel.querySelector('.pb-carousel__arrow--prev');
            const nextBtn = carousel.querySelector('.pb-carousel__arrow--next');
            const dotsContainer = carousel.querySelector('.pb-carousel__dots');
            
            if (slides.length <= 1) return;
            
            let currentIndex = 0;
            const autoplay = carousel.dataset.autoplay === 'true';
            const interval = parseInt(carousel.dataset.interval) || 5000;
            let autoplayTimer = null;
            
            // Criar dots
            if (dotsContainer) {
                slides.forEach((_, index) => {
                    const dot = document.createElement('span');
                    dot.className = 'pb-carousel__dot' + (index === 0 ? ' active' : '');
                    dot.addEventListener('click', () => goToSlide(index));
                    dotsContainer.appendChild(dot);
                });
            }
            
            function updateCarousel() {
                track.style.transform = `translateX(-${currentIndex * 100}%)`;
                
                // Atualizar dots
                if (dotsContainer) {
                    dotsContainer.querySelectorAll('.pb-carousel__dot').forEach((dot, index) => {
                        dot.classList.toggle('active', index === currentIndex);
                    });
                }
            }
            
            function goToSlide(index) {
                currentIndex = index;
                if (currentIndex < 0) currentIndex = slides.length - 1;
                if (currentIndex >= slides.length) currentIndex = 0;
                updateCarousel();
                resetAutoplay();
            }
            
            function nextSlide() {
                goToSlide(currentIndex + 1);
            }
            
            function prevSlide() {
                goToSlide(currentIndex - 1);
            }
            
            function resetAutoplay() {
                if (autoplay) {
                    clearInterval(autoplayTimer);
                    autoplayTimer = setInterval(nextSlide, interval);
                }
            }
            
            // Event listeners
            if (prevBtn) prevBtn.addEventListener('click', prevSlide);
            if (nextBtn) nextBtn.addEventListener('click', nextSlide);
            
            // Touch/Swipe
            let touchStartX = 0;
            let touchEndX = 0;
            
            carousel.addEventListener('touchstart', e => {
                touchStartX = e.changedTouches[0].screenX;
            }, { passive: true });
            
            carousel.addEventListener('touchend', e => {
                touchEndX = e.changedTouches[0].screenX;
                handleSwipe();
            }, { passive: true });
            
            function handleSwipe() {
                const diff = touchStartX - touchEndX;
                if (Math.abs(diff) > 50) {
                    if (diff > 0) {
                        nextSlide();
                    } else {
                        prevSlide();
                    }
                }
            }
            
            // Iniciar autoplay
            if (autoplay) {
                autoplayTimer = setInterval(nextSlide, interval);
                
                // Pausar no hover
                carousel.addEventListener('mouseenter', () => clearInterval(autoplayTimer));
                carousel.addEventListener('mouseleave', resetAutoplay);
            }
        });
    }
    
    // ==================== ANIMAÇÕES (Scroll) ====================
    function initAnimations() {
        const animatedElements = document.querySelectorAll('[data-animation]');
        
        if (!animatedElements.length) return;
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const element = entry.target;
                    const delay = parseInt(element.dataset.delay) || 0;
                    
                    setTimeout(() => {
                        element.classList.add('animated');
                    }, delay);
                    
                    observer.unobserve(element);
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        });
        
        animatedElements.forEach(element => {
            observer.observe(element);
        });
    }
    
    // ==================== GALERIA LIGHTBOX ====================
    function initGalleryLightbox() {
        const galleryItems = document.querySelectorAll('.pb-gallery__item');
        
        if (!galleryItems.length) return;
        
        // Criar overlay
        const overlay = document.createElement('div');
        overlay.className = 'pb-lightbox-overlay';
        overlay.innerHTML = `
            <button class="pb-lightbox-close">&times;</button>
            <button class="pb-lightbox-prev">&lsaquo;</button>
            <button class="pb-lightbox-next">&rsaquo;</button>
            <div class="pb-lightbox-content">
                <img src="" alt="">
            </div>
        `;
        document.body.appendChild(overlay);
        
        const lightboxImg = overlay.querySelector('img');
        const closeBtn = overlay.querySelector('.pb-lightbox-close');
        const prevBtn = overlay.querySelector('.pb-lightbox-prev');
        const nextBtn = overlay.querySelector('.pb-lightbox-next');
        
        let currentImages = [];
        let currentIndex = 0;
        
        // Estilo do lightbox
        const style = document.createElement('style');
        style.textContent = `
            .pb-lightbox-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.95);
                z-index: 9999;
                display: none;
                align-items: center;
                justify-content: center;
            }
            .pb-lightbox-overlay.active {
                display: flex;
            }
            .pb-lightbox-content {
                max-width: 90%;
                max-height: 90%;
            }
            .pb-lightbox-content img {
                max-width: 100%;
                max-height: 90vh;
                object-fit: contain;
            }
            .pb-lightbox-close,
            .pb-lightbox-prev,
            .pb-lightbox-next {
                position: absolute;
                background: none;
                border: none;
                color: white;
                font-size: 40px;
                cursor: pointer;
                padding: 20px;
                transition: opacity 0.3s;
            }
            .pb-lightbox-close:hover,
            .pb-lightbox-prev:hover,
            .pb-lightbox-next:hover {
                opacity: 0.7;
            }
            .pb-lightbox-close {
                top: 10px;
                right: 20px;
            }
            .pb-lightbox-prev {
                left: 20px;
                top: 50%;
                transform: translateY(-50%);
            }
            .pb-lightbox-next {
                right: 20px;
                top: 50%;
                transform: translateY(-50%);
            }
        `;
        document.head.appendChild(style);
        
        function openLightbox(images, index) {
            currentImages = images;
            currentIndex = index;
            updateLightbox();
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeLightbox() {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        function updateLightbox() {
            lightboxImg.src = currentImages[currentIndex];
        }
        
        function nextImage() {
            currentIndex = (currentIndex + 1) % currentImages.length;
            updateLightbox();
        }
        
        function prevImage() {
            currentIndex = (currentIndex - 1 + currentImages.length) % currentImages.length;
            updateLightbox();
        }
        
        // Agrupar imagens por galeria
        const galleries = document.querySelectorAll('.pb-gallery');
        galleries.forEach(gallery => {
            const items = gallery.querySelectorAll('.pb-gallery__item img');
            const images = Array.from(items).map(img => img.src);
            
            items.forEach((img, index) => {
                img.parentElement.addEventListener('click', () => {
                    openLightbox(images, index);
                });
            });
        });
        
        // Event listeners
        closeBtn.addEventListener('click', closeLightbox);
        prevBtn.addEventListener('click', prevImage);
        nextBtn.addEventListener('click', nextImage);
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeLightbox();
        });
        
        document.addEventListener('keydown', (e) => {
            if (!overlay.classList.contains('active')) return;
            
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowRight') nextImage();
            if (e.key === 'ArrowLeft') prevImage();
        });
    }
    
    // ==================== NEWSLETTER FORMS ====================
    function initNewsletterForms() {
        const forms = document.querySelectorAll('.pb-newsletter__form');
        
        forms.forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const email = form.querySelector('input[type="email"]').value;
                const button = form.querySelector('button');
                const originalText = button.textContent;
                
                button.textContent = 'Enviando...';
                button.disabled = true;
                
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'email=' + encodeURIComponent(email)
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        form.innerHTML = '<p style="color:#10b981;font-size:18px;">✓ Inscrição realizada com sucesso!</p>';
                    } else {
                        throw new Error(data.error || 'Erro ao se inscrever');
                    }
                } catch (error) {
                    button.textContent = originalText;
                    button.disabled = false;
                    alert(error.message || 'Erro ao se inscrever. Tente novamente.');
                }
            });
        });
    }
    
    // ==================== LAZY LOADING ====================
    function initLazyLoading() {
        const lazyImages = document.querySelectorAll('img[loading="lazy"]');
        
        if ('loading' in HTMLImageElement.prototype) {
            // Browser suporta lazy loading nativo
            return;
        }
        
        // Fallback com IntersectionObserver
        const imageObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                    }
                    imageObserver.unobserve(img);
                }
            });
        });
        
        lazyImages.forEach(img => {
            if (img.dataset.src) {
                imageObserver.observe(img);
            }
        });
    }
    
    // ==================== UTILITÁRIOS ====================
    
    // Smooth scroll para links internos
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Parallax suave para banners
    window.addEventListener('scroll', () => {
        const banners = document.querySelectorAll('.pb-banner[data-parallax="true"]');
        banners.forEach(banner => {
            const scrolled = window.pageYOffset;
            const rect = banner.getBoundingClientRect();
            const visible = rect.top < window.innerHeight && rect.bottom > 0;
            
            if (visible) {
                const speed = parseFloat(banner.dataset.parallaxSpeed) || 0.5;
                banner.style.backgroundPositionY = (scrolled * speed) + 'px';
            }
        });
    }, { passive: true });
    
})();
