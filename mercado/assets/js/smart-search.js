/**
 * 🔍 SMART SEARCH PRO - OneMundo Mercado
 * Busca inteligente com: Autocomplete, Carrinho, Foto IA, Scanner de Código de Barras
 */

(function() {
    'use strict';
    
    const searchInput = document.getElementById('omSearchInput');
    const searchForm = searchInput?.closest('form');
    if (!searchInput) return;
    
    let dropdown = null;
    let debounceTimer = null;
    let currentQuery = '';
    let scannerActive = false;
    let videoStream = null;
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // CRIAR INTERFACE
    // ═══════════════════════════════════════════════════════════════════════════════
    
    function createSearchUI() {
        // Botões de ação (foto e scanner)
        const actionsHtml = `
            <div class="ss-actions">
                <button type="button" class="ss-action-btn" id="ssPhotoBtn" title="Buscar por foto">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>
                        <path d="M21 15l-5-5L5 21"/>
                    </svg>
                </button>
                <button type="button" class="ss-action-btn" id="ssScanBtn" title="Escanear código de barras">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/>
                        <path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
                        <path d="M7 8v8"/><path d="M12 8v8"/><path d="M17 8v8"/>
                    </svg>
                </button>
            </div>
        `;
        
        // Inserir botões
        const inputWrapper = searchInput.parentElement;
        inputWrapper.style.position = 'relative';
        inputWrapper.insertAdjacentHTML('beforeend', actionsHtml);
        
        // Criar dropdown
        dropdown = document.createElement('div');
        dropdown.className = 'ss-dropdown';
        dropdown.id = 'ssDropdown';
        searchForm.style.position = 'relative';
        searchForm.appendChild(dropdown);
        
        // Event listeners
        document.getElementById('ssPhotoBtn').addEventListener('click', openPhotoSearch);
        document.getElementById('ssScanBtn').addEventListener('click', openScanner);
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // AUTOCOMPLETE COM CARRINHO
    // ═══════════════════════════════════════════════════════════════════════════════
    
    function formatPrice(price) {
        return 'R$ ' + parseFloat(price).toFixed(2).replace('.', ',');
    }
    
    function renderResults(data) {
        if (!dropdown) return;
        
        if (!data.products || data.products.length === 0) {
            dropdown.innerHTML = `
                <div class="ss-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <span>Nenhum produto encontrado</span>
                    <small>Tente buscar por foto ou scanner</small>
                </div>
            `;
            return;
        }
        
        let html = '<div class="ss-results">';
        
        data.products.forEach(p => {
            const hasPromo = p.price_promo && p.price_promo < p.price;
            const discount = hasPromo ? Math.round((1 - p.price_promo / p.price) * 100) : 0;
            const finalPrice = hasPromo ? p.price_promo : p.price;
            
            html += `
                <div class="ss-item" data-id="${p.id}">
                    <div class="ss-item-link" onclick="ssHideDropdown(); openProductModal(${p.id})" style="cursor:pointer">
                        <div class="ss-img">
                            <img src="${p.image}" alt="${p.name}" onerror="this.src='/mercado/assets/img/no-image.png'">
                            ${discount > 0 ? `<span class="ss-badge">-${discount}%</span>` : ''}
                        </div>
                        <div class="ss-info">
                            ${p.brand ? `<div class="ss-brand">${p.brand}</div>` : ''}
                            <div class="ss-name">${p.name}</div>
                            <div class="ss-price">
                                ${hasPromo ? `<span class="ss-price-old">${formatPrice(p.price)}</span>` : ''}
                                <span class="ss-price-current">${formatPrice(finalPrice)}</span>
                            </div>
                        </div>
                    </div>
                    <div class="ss-cart-controls">
                        <button class="ss-cart-btn" onclick="event.preventDefault(); event.stopPropagation(); ssAddToCart(${p.id}, '${p.name.replace(/'/g, "\\'")}', ${finalPrice}, '${p.image}')">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                            </svg>
                            <span>Adicionar</span>
                        </button>
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        
        if (data.total > data.products.length) {
            html += `
                <a href="/mercado/?q=${encodeURIComponent(currentQuery)}" class="ss-view-all">
                    Ver todos os ${data.total} resultados →
                </a>
            `;
        }
        
        dropdown.innerHTML = html;
    }
    
    async function search(query) {
        if (query.length < 2) {
            hideDropdown();
            return;
        }
        
        currentQuery = query;
        showDropdown();
        dropdown.innerHTML = '<div class="ss-loading"><div class="ss-spinner"></div> Buscando...</div>';
        
        try {
            const response = await fetch(`/mercado/api/busca.php?q=${encodeURIComponent(query)}&limit=6`);
            const data = await response.json();
            
            if (query === currentQuery) {
                renderResults(data);
            }
        } catch (error) {
            dropdown.innerHTML = '<div class="ss-empty">Erro ao buscar. Tente novamente.</div>';
        }
    }
    
    function showDropdown() {
        if (dropdown) dropdown.classList.add('show');
    }
    
    function hideDropdown() {
        if (dropdown) dropdown.classList.remove('show');
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // BUSCA POR FOTO (CLAUDE VISION)
    // ═══════════════════════════════════════════════════════════════════════════════
    
    function openPhotoSearch() {
        const modal = document.createElement('div');
        modal.className = 'ss-modal';
        modal.id = 'ssPhotoModal';
        modal.innerHTML = `
            <div class="ss-modal-content">
                <div class="ss-modal-header">
                    <h3>📷 Buscar por Foto</h3>
                    <button class="ss-modal-close" onclick="ssCloseModal('ssPhotoModal')">✕</button>
                </div>
                <div class="ss-modal-body">
                    <div class="ss-photo-upload" id="ssPhotoUpload">
                        <input type="file" id="ssPhotoInput" accept="image/*" capture="environment" hidden>
                        <div class="ss-upload-area" onclick="document.getElementById('ssPhotoInput').click()">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="1.5">
                                <rect x="3" y="3" width="18" height="18" rx="2"/>
                                <circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>
                            </svg>
                            <span>Toque para tirar foto ou escolher imagem</span>
                            <small>A IA vai identificar o produto automaticamente</small>
                        </div>
                    </div>
                    <div class="ss-photo-preview" id="ssPhotoPreview" style="display:none;">
                        <img id="ssPreviewImg" src="">
                        <div class="ss-photo-analyzing" id="ssPhotoAnalyzing">
                            <div class="ss-spinner"></div>
                            <span>Analisando imagem com IA...</span>
                        </div>
                    </div>
                    <div class="ss-photo-results" id="ssPhotoResults"></div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        document.getElementById('ssPhotoInput').addEventListener('change', handlePhotoSelect);
    }
    
    async function handlePhotoSelect(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        const preview = document.getElementById('ssPhotoPreview');
        const upload = document.getElementById('ssPhotoUpload');
        const previewImg = document.getElementById('ssPreviewImg');
        const results = document.getElementById('ssPhotoResults');
        const analyzing = document.getElementById('ssPhotoAnalyzing');
        
        // Mostrar preview
        const reader = new FileReader();
        reader.onload = async function(e) {
            previewImg.src = e.target.result;
            upload.style.display = 'none';
            preview.style.display = 'block';
            analyzing.style.display = 'flex';
            results.innerHTML = '';
            
            // Enviar para API com Claude Vision
            try {
                const base64 = e.target.result.split(',')[1];
                const response = await fetch('/mercado/?api=search_by_image', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ image: base64 })
                });
                
                const data = await response.json();
                analyzing.style.display = 'none';
                
                if (data.success && data.products && data.products.length > 0) {
                    let html = `<p class="ss-photo-found">🎯 Encontramos ${data.products.length} produto(s):</p>`;
                    html += '<div class="ss-results">';
                    data.products.forEach(p => {
                        const price = p.price_promo && p.price_promo < p.price ? p.price_promo : p.price;
                        html += `
                            <div class="ss-item">
                                <div class="ss-item-link" onclick="ssCloseModal('ssPhotoModal'); openProductModal(${p.id})" style="cursor:pointer">
                                    <div class="ss-img"><img src="${p.image}" onerror="this.src='/mercado/assets/img/no-image.png'"></div>
                                    <div class="ss-info">
                                        ${p.brand ? `<div class="ss-brand">${p.brand}</div>` : ''}
                                        <div class="ss-name">${p.name}</div>
                                        <div class="ss-price"><span class="ss-price-current">${formatPrice(price)}</span></div>
                                    </div>
                                </div>
                                <button class="ss-cart-btn" onclick="ssAddToCart(${p.id}, '${p.name.replace(/'/g, "\\'")}', ${price}, '${p.image}')">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M12 5v14M5 12h14"/>
                                    </svg>
                                </button>
                            </div>
                        `;
                    });
                    html += '</div>';
                    if (data.identified) {
                        html += `<p class="ss-photo-identified">🤖 Identificado: <strong>${data.identified}</strong></p>`;
                    }
                    results.innerHTML = html;
                } else {
                    results.innerHTML = `
                        <div class="ss-empty">
                            <span>😕 Não consegui identificar o produto</span>
                            <small>${data.identified ? 'Detectei: ' + data.identified : 'Tente outra foto com melhor iluminação'}</small>
                            <button class="ss-btn-retry" onclick="ssRetryPhoto()">Tentar outra foto</button>
                        </div>
                    `;
                }
            } catch (err) {
                analyzing.style.display = 'none';
                results.innerHTML = '<div class="ss-empty"><span>Erro ao analisar imagem</span><button class="ss-btn-retry" onclick="ssRetryPhoto()">Tentar novamente</button></div>';
            }
        };
        reader.readAsDataURL(file);
    }
    
    window.ssRetryPhoto = function() {
        document.getElementById('ssPhotoUpload').style.display = 'block';
        document.getElementById('ssPhotoPreview').style.display = 'none';
        document.getElementById('ssPhotoResults').innerHTML = '';
        document.getElementById('ssPhotoInput').value = '';
    };
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // SCANNER DE CÓDIGO DE BARRAS
    // ═══════════════════════════════════════════════════════════════════════════════
    
    function openScanner() {
        const modal = document.createElement('div');
        modal.className = 'ss-modal';
        modal.id = 'ssScanModal';
        modal.innerHTML = `
            <div class="ss-modal-content ss-modal-scanner">
                <div class="ss-modal-header">
                    <h3>📱 Escanear Código de Barras</h3>
                    <button class="ss-modal-close" onclick="ssCloseScanner()">✕</button>
                </div>
                <div class="ss-modal-body">
                    <div class="ss-scanner-container" id="ssScannerContainer">
                        <video id="ssScannerVideo" autoplay playsinline></video>
                        <div class="ss-scanner-overlay">
                            <div class="ss-scanner-line"></div>
                        </div>
                        <canvas id="ssScannerCanvas" style="display:none;"></canvas>
                    </div>
                    <p class="ss-scanner-hint">Posicione o código de barras dentro da área</p>
                    <div class="ss-scanner-manual">
                        <span>ou digite o código:</span>
                        <div class="ss-barcode-input">
                            <input type="text" id="ssBarcodeInput" placeholder="0000000000000" maxlength="13" inputmode="numeric">
                            <button onclick="ssSearchBarcode()">Buscar</button>
                        </div>
                    </div>
                    <div class="ss-scanner-results" id="ssScannerResults"></div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Enter para buscar
        document.getElementById('ssBarcodeInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') ssSearchBarcode();
        });
        
        startScanner();
    }
    
    async function startScanner() {
        const container = document.getElementById('ssScannerContainer');
        const results = document.getElementById('ssScannerResults');
        
        try {
            videoStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
            });
            
            const video = document.getElementById('ssScannerVideo');
            video.srcObject = videoStream;
            scannerActive = true;
            
            // Tentar usar BarcodeDetector nativo (Chrome/Edge)
            if ('BarcodeDetector' in window) {
                const barcodeDetector = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128'] });
                
                const detectBarcode = async () => {
                    if (!scannerActive) return;
                    
                    try {
                        const barcodes = await barcodeDetector.detect(video);
                        if (barcodes.length > 0) {
                            const code = barcodes[0].rawValue;
                            scannerActive = false;
                            foundBarcode(code);
                            return;
                        }
                    } catch (e) {}
                    
                    if (scannerActive) requestAnimationFrame(detectBarcode);
                };
                
                video.onloadedmetadata = () => detectBarcode();
            } else {
                // Fallback: capturar frames e enviar para Claude Vision
                const canvas = document.getElementById('ssScannerCanvas');
                const ctx = canvas.getContext('2d');
                
                const scanFrame = async () => {
                    if (!scannerActive) return;
                    
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    ctx.drawImage(video, 0, 0);
                    
                    const base64 = canvas.toDataURL('image/jpeg', 0.8).split(',')[1];
                    
                    try {
                        const response = await fetch('/mercado/?api=detect_barcode', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ image: base64 })
                        });
                        const data = await response.json();
                        
                        if (data.success && data.barcode) {
                            scannerActive = false;
                            foundBarcode(data.barcode);
                            return;
                        }
                    } catch (e) {}
                    
                    if (scannerActive) setTimeout(scanFrame, 1500);
                };
                
                video.onloadedmetadata = () => setTimeout(scanFrame, 500);
            }
            
        } catch (err) {
            container.innerHTML = `
                <div class="ss-camera-error">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="1.5">
                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                        <circle cx="12" cy="13" r="4"/><line x1="1" y1="1" x2="23" y2="23"/>
                    </svg>
                    <span>Câmera não disponível</span>
                    <small>Digite o código manualmente</small>
                </div>
            `;
        }
    }
    
    async function foundBarcode(code) {
        const container = document.getElementById('ssScannerContainer');
        const results = document.getElementById('ssScannerResults');
        
        // Parar scanner
        scannerActive = false;
        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
        }
        
        container.innerHTML = `
            <div class="ss-barcode-found">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>
                </svg>
                <span>Código encontrado!</span>
                <strong>${code}</strong>
            </div>
        `;
        
        results.innerHTML = '<div class="ss-loading"><div class="ss-spinner"></div> Buscando produto...</div>';
        
        try {
            const response = await fetch(`/mercado/?api=search_barcode&code=${code}`);
            const data = await response.json();
            
            if (data.success && data.product) {
                const p = data.product;
                const price = p.price_promo && p.price_promo < p.price ? p.price_promo : p.price;
                results.innerHTML = `
                    <div class="ss-scan-found">
                        <p>🎯 Produto encontrado!</p>
                        <div class="ss-item ss-item-large">
                            <div class="ss-item-link" onclick="ssCloseScanner(); openProductModal(${p.id})" style="cursor:pointer">
                                <div class="ss-img"><img src="${p.image}" onerror="this.src='/mercado/assets/img/no-image.png'"></div>
                                <div class="ss-info">
                                    ${p.brand ? `<div class="ss-brand">${p.brand}</div>` : ''}
                                    <div class="ss-name">${p.name}</div>
                                    <div class="ss-price"><span class="ss-price-current">${formatPrice(price)}</span></div>
                                </div>
                            </div>
                        </div>
                        <button class="ss-cart-btn ss-cart-btn-large" onclick="ssAddToCart(${p.id}, '${p.name.replace(/'/g, "\\'")}', ${price}, '${p.image}'); setTimeout(ssCloseScanner, 1500);">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                            </svg>
                            Adicionar ao Carrinho
                        </button>
                    </div>
                `;
            } else {
                results.innerHTML = `
                    <div class="ss-empty">
                        <span>😕 Produto não encontrado</span>
                        <small>Este código não está em nosso catálogo</small>
                        <button class="ss-btn-retry" onclick="ssCloseScanner(); setTimeout(openScanner, 100);">Escanear outro</button>
                    </div>
                `;
            }
        } catch (err) {
            results.innerHTML = '<div class="ss-empty"><span>Erro ao buscar produto</span><button class="ss-btn-retry" onclick="ssCloseScanner(); setTimeout(openScanner, 100);">Tentar novamente</button></div>';
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // FUNÇÕES GLOBAIS
    // ═══════════════════════════════════════════════════════════════════════════════
    
    window.ssAddToCart = async function(productId, name, price, image) {
        const btn = event?.currentTarget;
        let originalHtml = '';
        
        if (btn) {
            originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<div class="ss-spinner-small"></div>';
        }
        
        try {
            const response = await fetch('/mercado/api/carrinho.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'add', product_id: productId, quantity: 1 })
            });
            
            const data = await response.json();
            
            if (data.success) {
                if (btn) {
                    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>';
                    btn.classList.add('ss-cart-btn-success');
                }
                
                // Atualizar contador do carrinho na página
                const cartBadge = document.querySelector('.om-cart-count, .cart-count, [data-cart-count]');
                if (cartBadge && data.total_items) {
                    cartBadge.textContent = data.total_items;
                }
                
                showToast(`${name.substring(0, 30)}... adicionado!`);
                
                if (btn) {
                    setTimeout(() => {
                        btn.innerHTML = originalHtml;
                        btn.classList.remove('ss-cart-btn-success');
                        btn.disabled = false;
                    }, 2000);
                }
            } else {
                throw new Error(data.message || 'Erro');
            }
        } catch (err) {
            if (btn) {
                btn.innerHTML = '❌';
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                }, 2000);
            }
            showToast('Erro ao adicionar', true);
        }
    };
    
    window.ssCloseModal = function(id) {
        document.getElementById(id)?.remove();
    };
    
    window.ssHideDropdown = hideDropdown;
    
    window.ssCloseScanner = function() {
        scannerActive = false;
        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
            videoStream = null;
        }
        document.getElementById('ssScanModal')?.remove();
    };
    
    window.ssSearchBarcode = function() {
        const code = document.getElementById('ssBarcodeInput')?.value.trim();
        if (code && code.length >= 8) {
            foundBarcode(code);
        }
    };
    
    window.openScanner = openScanner;
    
    function showToast(message, isError = false) {
        const existing = document.querySelector('.ss-toast');
        if (existing) existing.remove();
        
        const toast = document.createElement('div');
        toast.className = 'ss-toast' + (isError ? ' ss-toast-error' : '');
        toast.innerHTML = isError 
            ? `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>${message}`
            : `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>${message}`;
        document.body.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // EVENT LISTENERS
    // ═══════════════════════════════════════════════════════════════════════════════
    
    searchInput.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        clearTimeout(debounceTimer);
        
        if (query.length < 2) {
            hideDropdown();
            return;
        }
        
        debounceTimer = setTimeout(() => search(query), 300);
    });
    
    searchInput.addEventListener('focus', function() {
        if (this.value.trim().length >= 2 && dropdown?.innerHTML) {
            showDropdown();
        }
    });
    
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.om-search-form') && !e.target.closest('.ss-dropdown')) {
            hideDropdown();
        }
    });
    
    searchInput.addEventListener('keydown', function(e) {
        if (!dropdown || !dropdown.classList.contains('show')) return;
        
        const items = dropdown.querySelectorAll('.ss-item');
        const active = dropdown.querySelector('.ss-item.active');
        let index = Array.from(items).indexOf(active);
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (active) active.classList.remove('active');
            index = (index + 1) % items.length;
            items[index]?.classList.add('active');
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (active) active.classList.remove('active');
            index = index <= 0 ? items.length - 1 : index - 1;
            items[index]?.classList.add('active');
        } else if (e.key === 'Enter' && active) {
            e.preventDefault();
            const productId = active.dataset.id;
            if (productId) {
                hideDropdown();
                openProductModal(parseInt(productId));
            }
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // INICIALIZAR
    // ═══════════════════════════════════════════════════════════════════════════════
    
    createSearchUI();
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // ESTILOS
    // ═══════════════════════════════════════════════════════════════════════════════
    
    const styles = document.createElement('style');
    styles.textContent = `
        .ss-actions{position:absolute;right:60px;top:50%;transform:translateY(-50%);display:flex;gap:4px;z-index:10}
        .ss-action-btn{width:36px;height:36px;border:none;background:#f3f4f6;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;color:#666}
        .ss-action-btn:hover{background:#22c55e;color:#fff}
        .ss-dropdown{position:absolute;top:100%;left:0;right:0;background:#fff;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.15);margin-top:8px;z-index:1000;display:none;overflow:hidden}
        .ss-dropdown.show{display:block}
        .ss-loading,.ss-empty{padding:32px;text-align:center;color:#666;display:flex;flex-direction:column;align-items:center;gap:8px}
        .ss-empty small{color:#999;font-size:13px}
        .ss-spinner{width:24px;height:24px;border:3px solid #e5e7eb;border-top-color:#22c55e;border-radius:50%;animation:spin .8s linear infinite}
        .ss-spinner-small{width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}
        .ss-results{max-height:400px;overflow-y:auto}
        .ss-item{display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid #f3f4f6;transition:background .15s}
        .ss-item:hover,.ss-item.active{background:#f0fdf4}
        .ss-item-large{background:#f8fafc;border-radius:12px;margin-bottom:12px}
        .ss-item-link{display:flex;align-items:center;gap:12px;flex:1;text-decoration:none;color:inherit;min-width:0;cursor:pointer}
        .ss-img{width:56px;height:56px;border-radius:10px;overflow:hidden;background:#f9fafb;flex-shrink:0;position:relative}
        .ss-img img{width:100%;height:100%;object-fit:contain}
        .ss-badge{position:absolute;top:2px;left:2px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;padding:2px 5px;border-radius:4px}
        .ss-info{flex:1;min-width:0}
        .ss-brand{font-size:11px;color:#22c55e;font-weight:600;text-transform:uppercase}
        .ss-name{font-size:14px;font-weight:500;color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .ss-price{display:flex;align-items:baseline;gap:6px;margin-top:2px}
        .ss-price-old{font-size:12px;color:#999;text-decoration:line-through}
        .ss-price-current{font-size:15px;font-weight:700;color:#333}
        .ss-cart-controls{flex-shrink:0;margin-left:12px}
        .ss-cart-btn{display:flex;align-items:center;gap:6px;padding:8px 14px;background:#22c55e;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s}
        .ss-cart-btn:hover{background:#16a34a;transform:scale(1.05)}
        .ss-cart-btn:disabled{opacity:.7;cursor:not-allowed;transform:none}
        .ss-cart-btn-success{background:#16a34a!important}
        .ss-cart-btn-large{padding:14px 24px;font-size:15px;width:100%;justify-content:center}
        .ss-view-all{display:block;padding:14px;text-align:center;background:#f8fafc;color:#22c55e;font-weight:600;text-decoration:none;border-top:1px solid #e5e7eb}
        .ss-view-all:hover{background:#f0fdf4}
        .ss-modal{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px}
        .ss-modal-content{background:#fff;border-radius:20px;max-width:480px;width:100%;max-height:90vh;overflow:hidden;animation:modalSlide .3s ease}
        .ss-modal-scanner{max-width:400px}
        @keyframes modalSlide{from{transform:translateY(20px);opacity:0}}
        .ss-modal-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #e5e7eb}
        .ss-modal-header h3{margin:0;font-size:1.1rem}
        .ss-modal-close{width:32px;height:32px;border:none;background:#f3f4f6;border-radius:50%;font-size:16px;cursor:pointer;transition:all .2s}
        .ss-modal-close:hover{background:#e5e7eb}
        .ss-modal-body{padding:20px;overflow-y:auto;max-height:calc(90vh - 70px)}
        .ss-upload-area{border:2px dashed #d1d5db;border-radius:16px;padding:40px 20px;text-align:center;cursor:pointer;transition:all .2s}
        .ss-upload-area:hover{border-color:#22c55e;background:#f0fdf4}
        .ss-upload-area span{display:block;margin-top:12px;font-weight:500;color:#333}
        .ss-upload-area small{display:block;margin-top:6px;color:#999;font-size:13px}
        .ss-photo-preview{text-align:center}
        .ss-photo-preview img{max-width:100%;max-height:200px;border-radius:12px}
        .ss-photo-analyzing{margin-top:16px;display:flex;align-items:center;justify-content:center;gap:10px;color:#666}
        .ss-photo-found{color:#22c55e;font-weight:600;margin-bottom:12px}
        .ss-photo-identified{margin-top:12px;font-size:13px;color:#666;text-align:center}
        .ss-btn-retry{margin-top:12px;padding:10px 20px;background:#f3f4f6;border:none;border-radius:10px;cursor:pointer;font-weight:500}
        .ss-btn-retry:hover{background:#e5e7eb}
        .ss-scanner-container{position:relative;border-radius:16px;overflow:hidden;background:#000}
        .ss-scanner-container video{width:100%;display:block}
        .ss-scanner-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none}
        .ss-scanner-line{width:80%;height:2px;background:#22c55e;box-shadow:0 0 10px #22c55e;animation:scanLine 2s ease-in-out infinite}
        @keyframes scanLine{0%,100%{transform:translateY(-50px)}50%{transform:translateY(50px)}}
        .ss-scanner-hint{text-align:center;margin:12px 0;color:#666;font-size:14px}
        .ss-scanner-manual{text-align:center;margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb}
        .ss-scanner-manual>span{display:block;color:#999;font-size:13px;margin-bottom:10px}
        .ss-barcode-input{display:flex;gap:8px}
        .ss-barcode-input input{flex:1;padding:12px;border:2px solid #e5e7eb;border-radius:10px;font-size:16px;text-align:center;letter-spacing:2px}
        .ss-barcode-input input:focus{border-color:#22c55e;outline:none}
        .ss-barcode-input button{padding:12px 20px;background:#22c55e;color:#fff;border:none;border-radius:10px;font-weight:600;cursor:pointer}
        .ss-barcode-found{padding:40px 20px;text-align:center;background:#f0fdf4;border-radius:16px}
        .ss-barcode-found span{display:block;margin-top:12px;color:#333}
        .ss-barcode-found strong{display:block;margin-top:4px;font-size:18px;letter-spacing:2px;color:#22c55e}
        .ss-scan-found{text-align:center}
        .ss-scan-found>p{color:#22c55e;font-weight:600;margin-bottom:12px}
        .ss-camera-error{padding:40px 20px;text-align:center;background:#f8fafc;border-radius:16px}
        .ss-camera-error span{display:block;margin-top:12px;color:#666}
        .ss-camera-error small{display:block;margin-top:4px;color:#999}
        .ss-toast{position:fixed;bottom:100px;left:50%;transform:translateX(-50%) translateY(20px);background:#333;color:#fff;padding:12px 20px;border-radius:12px;display:flex;align-items:center;gap:10px;z-index:999999;opacity:0;transition:all .3s;box-shadow:0 4px 20px rgba(0,0,0,.2);font-size:14px}
        .ss-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
        .ss-toast svg{color:#22c55e;flex-shrink:0}
        .ss-toast-error{background:#dc2626}
        .ss-toast-error svg{color:#fff}
        @media(max-width:768px){.ss-actions{right:50px}.ss-action-btn{width:32px;height:32px}.ss-dropdown{position:fixed;top:120px;left:10px;right:10px;margin:0;max-height:calc(100vh - 140px)}.ss-cart-btn span{display:none}.ss-cart-btn{padding:10px}.ss-modal{padding:10px}.ss-modal-content{max-height:calc(100vh - 20px);border-radius:16px}}
    `;
    document.head.appendChild(styles);
    
})();
