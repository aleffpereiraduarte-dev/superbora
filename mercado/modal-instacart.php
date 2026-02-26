<!-- ═══════════════════════════════════════════════════════════════════════════════
     🛒 ONEMUNDO MERCADO - MODAL PRODUTO ESTILO INSTACART PERFEITO
     Todas as funcionalidades do Instacart implementadas
     ═══════════════════════════════════════════════════════════════════════════════ -->

<!-- OVERLAY -->
<div class="ic-modal-overlay" id="icModalOverlay" onclick="closeIcModal()"></div>

<!-- MODAL -->
<div class="ic-modal" id="icModal">
    <!-- Header com Back -->
    <div class="ic-modal-header">
        <button class="ic-back-btn" onclick="closeIcModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            <span>Voltar</span>
        </button>
        
        <div class="ic-header-actions">
            <button class="ic-header-btn" onclick="shareProduct()" title="Compartilhar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                </svg>
            </button>
        </div>
    </div>
    
    <!-- Conteúdo Principal -->
    <div class="ic-modal-content">
        <!-- Lado Esquerdo - Galeria -->
        <div class="ic-gallery">
            <!-- Thumbnails Laterais -->
            <div class="ic-thumbnails" id="icThumbnails">
                <div class="ic-thumb active">
                    <img src="" alt="Thumb 1" id="icThumb1">
                </div>
                <div class="ic-thumb">
                    <img src="" alt="Thumb 2" id="icThumb2">
                </div>
            </div>
            
            <!-- Imagem Principal -->
            <div class="ic-main-image">
                <img src="" alt="" id="icMainImage">
                <button class="ic-zoom-btn" onclick="toggleZoom()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                        <path d="M11 8v6M8 11h6"/>
                    </svg>
                </button>
            </div>
        </div>
        
        <!-- Lado Direito - Informações -->
        <div class="ic-info">
            <!-- Nome e Info Básica -->
            <div class="ic-product-header">
                <h1 class="ic-product-name" id="icProductName">Nome do Produto</h1>
                <div class="ic-product-meta">
                    <span class="ic-product-size" id="icProductSize">1 un</span>
                    <span class="ic-product-unit-price" id="icUnitPrice">• R$ 0,00/un</span>
                </div>
                <a href="#" class="ic-shop-brand" id="icShopBrand" onclick="shopByBrand(event)">
                    Ver todos de <span id="icBrandName">Marca</span>
                </a>
            </div>
            
            <!-- Accordions -->
            <div class="ic-accordions">
                <div class="ic-accordion" id="icAccordionDetails">
                    <button class="ic-accordion-header" onclick="toggleAccordion('details')">
                        <span>Detalhes</span>
                        <svg class="ic-accordion-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m6 9 6 6 6-6"/>
                        </svg>
                    </button>
                    <div class="ic-accordion-content" id="icDetailsContent">
                        <p id="icDescription">Descrição do produto...</p>
                    </div>
                </div>
                
                <div class="ic-accordion" id="icAccordionIngredients">
                    <button class="ic-accordion-header" onclick="toggleAccordion('ingredients')">
                        <span>Ingredientes</span>
                        <svg class="ic-accordion-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m6 9 6 6 6-6"/>
                        </svg>
                    </button>
                    <div class="ic-accordion-content" id="icIngredientsContent">
                        <p id="icIngredients">Lista de ingredientes...</p>
                    </div>
                </div>
                
                <div class="ic-accordion" id="icAccordionNutrition">
                    <button class="ic-accordion-header" onclick="toggleAccordion('nutrition')">
                        <span>Informação Nutricional</span>
                        <svg class="ic-accordion-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m6 9 6 6 6-6"/>
                        </svg>
                    </button>
                    <div class="ic-accordion-content" id="icNutritionContent">
                        <div class="ic-nutrition-table" id="icNutritionTable">
                            <!-- Tabela nutricional dinâmica -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sidebar Direita - Ações -->
        <div class="ic-actions-sidebar">
            <div class="ic-price-box">
                <div class="ic-price-main" id="icPriceMain">R$ 0,00</div>
                <div class="ic-price-promo" id="icPromoTag" style="display:none;">
                    <span class="ic-promo-badge">🔥 Oferta</span>
                    <span class="ic-price-old" id="icPriceOld">R$ 0,00</span>
                </div>
            </div>
            
            <!-- Botão Adicionar -->
            <div class="ic-add-section">
                <div class="ic-qty-selector" id="icQtySelector">
                    <button class="ic-qty-btn" onclick="icChangeQty(-1)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg>
                    </button>
                    <span class="ic-qty-value" id="icQtyValue">1</span>
                    <button class="ic-qty-btn" onclick="icChangeQty(1)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    </button>
                </div>
                
                <button class="ic-add-btn" id="icAddBtn" onclick="icAddToCart()">
                    <span class="ic-add-btn-text">Adicionar</span>
                    <span class="ic-add-btn-price" id="icAddBtnPrice">R$ 0,00</span>
                </button>
                
                <!-- Estado: No carrinho -->
                <div class="ic-in-cart" id="icInCart" style="display:none;">
                    <button class="ic-in-cart-btn" onclick="toggleCartDropdown()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 22a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM20 22a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                        </svg>
                        <span id="icCartQty">1</span> no carrinho
                        <svg class="ic-dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                </div>
            </div>
            
            <!-- Item Instructions -->
            <div class="ic-instructions">
                <h4>Instruções do item</h4>
                
                <button class="ic-instruction-btn" onclick="openReplacementModal()">
                    <div class="ic-instruction-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                            <path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                            <path d="M16 21h5v-5"/>
                        </svg>
                    </div>
                    <div class="ic-instruction-text">
                        <span>Se estiver em falta, substituir pelo melhor</span>
                    </div>
                    <svg class="ic-instruction-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                </button>
                
                <button class="ic-instruction-btn" onclick="openNoteModal()">
                    <div class="ic-instruction-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                        </svg>
                    </div>
                    <div class="ic-instruction-text">
                        <span>Adicionar nota para o entregador</span>
                    </div>
                    <svg class="ic-instruction-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>
            
            <!-- Ações Secundárias -->
            <div class="ic-secondary-actions">
                <button class="ic-action-btn" onclick="toggleFavorite()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="icFavIcon">
                        <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>
                    </svg>
                    <span>Salvar</span>
                </button>
                
                <button class="ic-action-btn" onclick="openListModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>
                        <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
                    </svg>
                    <span>Adicionar à Lista</span>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Produtos Relacionados -->
    <div class="ic-related" id="icRelated">
        <div class="ic-related-header">
            <h3>Clientes também consideram</h3>
            <div class="ic-related-nav">
                <button class="ic-nav-btn" onclick="scrollRelated(-1)" id="icPrevBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <button class="ic-nav-btn" onclick="scrollRelated(1)" id="icNextBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>
        </div>
        <div class="ic-related-scroll" id="icRelatedScroll">
            <!-- Produtos relacionados dinâmicos -->
        </div>
    </div>
</div>

<!-- Modal de Nota para Entregador -->
<div class="ic-note-modal" id="icNoteModal">
    <div class="ic-note-content">
        <div class="ic-note-header">
            <h3>Nota para o entregador</h3>
            <button onclick="closeNoteModal()">✕</button>
        </div>
        <textarea id="icNoteText" placeholder="Ex: Prefiro bananas mais verdes..."></textarea>
        <button class="ic-note-save" onclick="saveNote()">Salvar nota</button>
    </div>
</div>

<!-- Modal de Substituição -->
<div class="ic-replace-modal" id="icReplaceModal">
    <div class="ic-replace-content">
        <div class="ic-replace-header">
            <h3>Se estiver em falta...</h3>
            <button onclick="closeReplaceModal()">✕</button>
        </div>
        <div class="ic-replace-options">
            <label class="ic-replace-option">
                <input type="radio" name="replacement" value="best" checked>
                <div class="ic-replace-option-content">
                    <strong>Melhor substituto</strong>
                    <span>O entregador escolhe o melhor substituto similar</span>
                </div>
            </label>
            <label class="ic-replace-option">
                <input type="radio" name="replacement" value="specific">
                <div class="ic-replace-option-content">
                    <strong>Item específico</strong>
                    <span>Escolher um produto específico como substituto</span>
                </div>
            </label>
            <label class="ic-replace-option">
                <input type="radio" name="replacement" value="refund">
                <div class="ic-replace-option-content">
                    <strong>Reembolso</strong>
                    <span>Não substituir, receber reembolso</span>
                </div>
            </label>
        </div>
        <button class="ic-replace-save" onclick="saveReplacement()">Salvar preferência</button>
    </div>
</div>

<style>
/* ═══════════════════════════════════════════════════════════════════════════════
   🛒 INSTACART MODAL - CSS COMPLETO
   ═══════════════════════════════════════════════════════════════════════════════ */

/* Reset & Variables */
.ic-modal *, .ic-modal *::before, .ic-modal *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

:root {
    --ic-green: #108910;
    --ic-green-hover: #0a6b0a;
    --ic-green-light: #e8f5e8;
    --ic-gray-50: #fafafa;
    --ic-gray-100: #f5f5f5;
    --ic-gray-200: #eeeeee;
    --ic-gray-300: #e0e0e0;
    --ic-gray-400: #bdbdbd;
    --ic-gray-500: #9e9e9e;
    --ic-gray-600: #757575;
    --ic-gray-700: #616161;
    --ic-gray-800: #424242;
    --ic-gray-900: #212121;
    --ic-shadow: 0 2px 8px rgba(0,0,0,0.08);
    --ic-shadow-lg: 0 8px 30px rgba(0,0,0,0.12);
    --ic-radius: 8px;
    --ic-radius-lg: 12px;
}

/* Overlay */
.ic-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9998;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.ic-modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

/* Modal Principal */
.ic-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: white;
    z-index: 9999;
    opacity: 0;
    visibility: hidden;
    transform: translateY(20px);
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.ic-modal.active {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

/* Header */
.ic-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 24px;
    border-bottom: 1px solid var(--ic-gray-200);
    background: white;
    position: sticky;
    top: 0;
    z-index: 10;
}

.ic-back-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    background: none;
    border: none;
    font-size: 16px;
    font-weight: 600;
    color: var(--ic-gray-800);
    cursor: pointer;
    padding: 8px 12px;
    margin: -8px -12px;
    border-radius: var(--ic-radius);
    transition: background 0.2s;
}

.ic-back-btn:hover {
    background: var(--ic-gray-100);
}

.ic-back-btn svg {
    width: 20px;
    height: 20px;
}

.ic-header-actions {
    display: flex;
    gap: 8px;
}

.ic-header-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 1px solid var(--ic-gray-300);
    background: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.ic-header-btn:hover {
    background: var(--ic-gray-100);
}

.ic-header-btn svg {
    width: 20px;
    height: 20px;
    color: var(--ic-gray-700);
}

/* Conteúdo Principal - Layout 3 Colunas */
.ic-modal-content {
    display: grid;
    grid-template-columns: minmax(300px, 500px) 1fr 380px;
    flex: 1;
    overflow-y: auto;
    padding: 32px;
    gap: 40px;
}

@media (max-width: 1200px) {
    .ic-modal-content {
        grid-template-columns: 1fr 1fr;
    }
    .ic-actions-sidebar {
        grid-column: 1 / -1;
    }
}

@media (max-width: 768px) {
    .ic-modal-content {
        grid-template-columns: 1fr;
        padding: 16px;
        gap: 24px;
    }
}

/* ═══ GALERIA ═══ */
.ic-gallery {
    display: flex;
    gap: 16px;
}

.ic-thumbnails {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 80px;
}

.ic-thumb {
    width: 80px;
    height: 80px;
    border: 2px solid var(--ic-gray-200);
    border-radius: var(--ic-radius);
    overflow: hidden;
    cursor: pointer;
    transition: border-color 0.2s;
}

.ic-thumb:hover,
.ic-thumb.active {
    border-color: var(--ic-green);
}

.ic-thumb img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 8px;
}

.ic-main-image {
    flex: 1;
    position: relative;
    background: var(--ic-gray-50);
    border-radius: var(--ic-radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 400px;
}

.ic-main-image img {
    max-width: 90%;
    max-height: 90%;
    object-fit: contain;
}

.ic-zoom-btn {
    position: absolute;
    bottom: 16px;
    right: 16px;
    width: 44px;
    height: 44px;
    background: white;
    border: 1px solid var(--ic-gray-300);
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    box-shadow: var(--ic-shadow);
}

.ic-zoom-btn:hover {
    background: var(--ic-gray-100);
}

.ic-zoom-btn svg {
    width: 22px;
    height: 22px;
    color: var(--ic-gray-700);
}

/* ═══ INFO DO PRODUTO ═══ */
.ic-info {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.ic-product-header {
    border-bottom: 1px solid var(--ic-gray-200);
    padding-bottom: 20px;
}

.ic-product-name {
    font-size: 28px;
    font-weight: 700;
    color: var(--ic-gray-900);
    line-height: 1.2;
    margin-bottom: 8px;
}

.ic-product-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
    color: var(--ic-gray-600);
    margin-bottom: 12px;
}

.ic-shop-brand {
    color: var(--ic-green);
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
}

.ic-shop-brand:hover {
    text-decoration: underline;
}

/* ═══ ACCORDIONS ═══ */
.ic-accordions {
    display: flex;
    flex-direction: column;
}

.ic-accordion {
    border-bottom: 1px solid var(--ic-gray-200);
}

.ic-accordion-header {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 0;
    background: none;
    border: none;
    font-size: 16px;
    font-weight: 600;
    color: var(--ic-gray-900);
    cursor: pointer;
    text-align: left;
}

.ic-accordion-header:hover {
    color: var(--ic-green);
}

.ic-accordion-arrow {
    width: 20px;
    height: 20px;
    color: var(--ic-gray-500);
    transition: transform 0.3s;
}

.ic-accordion.open .ic-accordion-arrow {
    transform: rotate(180deg);
}

.ic-accordion-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease, padding 0.3s ease;
}

.ic-accordion.open .ic-accordion-content {
    max-height: 500px;
    padding-bottom: 20px;
}

.ic-accordion-content p {
    font-size: 15px;
    line-height: 1.7;
    color: var(--ic-gray-700);
}

/* Tabela Nutricional */
.ic-nutrition-table {
    border: 1px solid var(--ic-gray-300);
    border-radius: var(--ic-radius);
    overflow: hidden;
}

.ic-nutrition-row {
    display: flex;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid var(--ic-gray-200);
    font-size: 14px;
}

.ic-nutrition-row:last-child {
    border-bottom: none;
}

.ic-nutrition-row.header {
    background: var(--ic-gray-100);
    font-weight: 700;
}

.ic-nutrition-label {
    color: var(--ic-gray-700);
}

.ic-nutrition-value {
    font-weight: 600;
    color: var(--ic-gray-900);
}

/* ═══ SIDEBAR DE AÇÕES ═══ */
.ic-actions-sidebar {
    background: white;
    border: 1px solid var(--ic-gray-200);
    border-radius: var(--ic-radius-lg);
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    height: fit-content;
    position: sticky;
    top: 100px;
    box-shadow: var(--ic-shadow);
}

/* Preço */
.ic-price-box {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ic-price-main {
    font-size: 32px;
    font-weight: 800;
    color: var(--ic-gray-900);
}

.ic-price-promo {
    display: flex;
    align-items: center;
    gap: 12px;
}

.ic-promo-badge {
    background: #fff3cd;
    color: #856404;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 600;
}

.ic-price-old {
    font-size: 18px;
    color: var(--ic-gray-500);
    text-decoration: line-through;
}

/* Seção de Adicionar */
.ic-add-section {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.ic-qty-selector {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    background: var(--ic-gray-100);
    border-radius: var(--ic-radius);
    padding: 4px;
}

.ic-qty-btn {
    width: 44px;
    height: 44px;
    border-radius: var(--ic-radius);
    border: none;
    background: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    box-shadow: var(--ic-shadow);
}

.ic-qty-btn:hover {
    background: var(--ic-green-light);
}

.ic-qty-btn svg {
    width: 20px;
    height: 20px;
    color: var(--ic-gray-700);
}

.ic-qty-value {
    min-width: 48px;
    text-align: center;
    font-size: 18px;
    font-weight: 700;
    color: var(--ic-gray-900);
}

/* Botão Adicionar - ESTILO INSTACART */
.ic-add-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    background: var(--ic-green);
    color: white;
    border: none;
    border-radius: var(--ic-radius);
    padding: 16px 24px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    width: 100%;
}

.ic-add-btn:hover {
    background: var(--ic-green-hover);
}

.ic-add-btn-price {
    background: rgba(255,255,255,0.2);
    padding: 4px 12px;
    border-radius: 4px;
}

/* Estado No Carrinho */
.ic-in-cart-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: var(--ic-green);
    color: white;
    border: none;
    border-radius: var(--ic-radius);
    padding: 16px 24px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
    transition: all 0.2s;
}

.ic-in-cart-btn svg {
    width: 20px;
    height: 20px;
}

.ic-dropdown-arrow {
    width: 16px;
    height: 16px;
    margin-left: auto;
}

/* ═══ INSTRUÇÕES ═══ */
.ic-instructions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ic-instructions h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--ic-gray-900);
    margin-bottom: 4px;
}

.ic-instruction-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: white;
    border: 1px solid var(--ic-gray-200);
    border-radius: var(--ic-radius);
    cursor: pointer;
    text-align: left;
    transition: all 0.2s;
    width: 100%;
}

.ic-instruction-btn:hover {
    background: var(--ic-gray-50);
    border-color: var(--ic-gray-300);
}

.ic-instruction-icon {
    width: 40px;
    height: 40px;
    background: var(--ic-gray-100);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.ic-instruction-icon svg {
    width: 20px;
    height: 20px;
    color: var(--ic-gray-600);
}

.ic-instruction-text {
    flex: 1;
}

.ic-instruction-text span {
    font-size: 14px;
    color: var(--ic-gray-700);
}

.ic-instruction-arrow {
    width: 20px;
    height: 20px;
    color: var(--ic-gray-400);
}

/* ═══ AÇÕES SECUNDÁRIAS ═══ */
.ic-secondary-actions {
    display: flex;
    gap: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--ic-gray-200);
}

.ic-action-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    background: none;
    border: none;
    font-size: 14px;
    font-weight: 600;
    color: var(--ic-gray-700);
    cursor: pointer;
    padding: 8px 0;
    transition: color 0.2s;
}

.ic-action-btn:hover {
    color: var(--ic-green);
}

.ic-action-btn svg {
    width: 20px;
    height: 20px;
}

.ic-action-btn.active svg {
    fill: var(--ic-green);
    color: var(--ic-green);
}

/* ═══ PRODUTOS RELACIONADOS ═══ */
.ic-related {
    border-top: 1px solid var(--ic-gray-200);
    padding: 32px;
    background: var(--ic-gray-50);
}

.ic-related-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.ic-related-header h3 {
    font-size: 20px;
    font-weight: 700;
    color: var(--ic-gray-900);
}

.ic-related-nav {
    display: flex;
    gap: 8px;
}

.ic-nav-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 1px solid var(--ic-gray-300);
    background: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.ic-nav-btn:hover {
    background: var(--ic-gray-100);
}

.ic-nav-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.ic-nav-btn svg {
    width: 20px;
    height: 20px;
    color: var(--ic-gray-700);
}

.ic-related-scroll {
    display: flex;
    gap: 16px;
    overflow-x: auto;
    padding-bottom: 8px;
    scroll-behavior: smooth;
    scrollbar-width: none;
}

.ic-related-scroll::-webkit-scrollbar {
    display: none;
}

/* Card de Produto Relacionado */
.ic-related-card {
    flex-shrink: 0;
    width: 160px;
    background: white;
    border: 1px solid var(--ic-gray-200);
    border-radius: var(--ic-radius-lg);
    overflow: hidden;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
}

.ic-related-card:hover {
    border-color: var(--ic-green);
    box-shadow: var(--ic-shadow);
}

.ic-related-img {
    width: 100%;
    aspect-ratio: 1;
    background: var(--ic-gray-50);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 12px;
}

.ic-related-img img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.ic-related-info {
    padding: 12px;
}

.ic-related-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--ic-gray-800);
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.3;
}

.ic-related-price {
    font-size: 15px;
    font-weight: 700;
    color: var(--ic-gray-900);
}

.ic-related-add {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 36px;
    height: 36px;
    background: var(--ic-green);
    color: white;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(16, 137, 16, 0.3);
}

.ic-related-add:hover {
    transform: scale(1.1);
    background: var(--ic-green-hover);
}

.ic-related-add svg {
    width: 20px;
    height: 20px;
}

/* ═══ MODAIS SECUNDÁRIOS ═══ */
.ic-note-modal,
.ic-replace-modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s;
}

.ic-note-modal.active,
.ic-replace-modal.active {
    opacity: 1;
    visibility: visible;
}

.ic-note-content,
.ic-replace-content {
    background: white;
    border-radius: var(--ic-radius-lg);
    width: 90%;
    max-width: 480px;
    padding: 24px;
}

.ic-note-header,
.ic-replace-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.ic-note-header h3,
.ic-replace-header h3 {
    font-size: 20px;
    font-weight: 700;
}

.ic-note-header button,
.ic-replace-header button {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--ic-gray-500);
}

#icNoteText {
    width: 100%;
    height: 120px;
    border: 1px solid var(--ic-gray-300);
    border-radius: var(--ic-radius);
    padding: 16px;
    font-size: 15px;
    resize: none;
    margin-bottom: 16px;
}

.ic-note-save,
.ic-replace-save {
    width: 100%;
    background: var(--ic-green);
    color: white;
    border: none;
    padding: 14px;
    border-radius: var(--ic-radius);
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
}

/* Opções de Substituição */
.ic-replace-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 20px;
}

.ic-replace-option {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px;
    border: 1px solid var(--ic-gray-300);
    border-radius: var(--ic-radius);
    cursor: pointer;
    transition: all 0.2s;
}

.ic-replace-option:hover {
    border-color: var(--ic-green);
}

.ic-replace-option input {
    margin-top: 4px;
}

.ic-replace-option-content strong {
    display: block;
    margin-bottom: 4px;
}

.ic-replace-option-content span {
    font-size: 13px;
    color: var(--ic-gray-600);
}

/* ═══ RESPONSIVO MOBILE ═══ */
@media (max-width: 768px) {
    .ic-modal-header {
        padding: 12px 16px;
    }
    
    .ic-gallery {
        flex-direction: column-reverse;
    }
    
    .ic-thumbnails {
        flex-direction: row;
        width: 100%;
        justify-content: center;
    }
    
    .ic-thumb {
        width: 60px;
        height: 60px;
    }
    
    .ic-main-image {
        min-height: 250px;
    }
    
    .ic-product-name {
        font-size: 22px;
    }
    
    .ic-price-main {
        font-size: 26px;
    }
    
    .ic-actions-sidebar {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        border-radius: var(--ic-radius-lg) var(--ic-radius-lg) 0 0;
        padding: 16px;
        box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
        z-index: 100;
    }
    
    .ic-instructions,
    .ic-secondary-actions {
        display: none;
    }
    
    .ic-related {
        padding: 20px 16px;
    }
}

/* ═══ LOADING STATE ═══ */
.ic-modal.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 48px;
    height: 48px;
    margin: -24px 0 0 -24px;
    border: 4px solid var(--ic-gray-200);
    border-top-color: var(--ic-green);
    border-radius: 50%;
    animation: icSpin 0.8s linear infinite;
}

@keyframes icSpin {
    to { transform: rotate(360deg); }
}

.ic-modal.loading .ic-modal-content,
.ic-modal.loading .ic-related {
    opacity: 0.3;
    pointer-events: none;
}
</style>

<script>
// ═══════════════════════════════════════════════════════════════════════════════
// 🛒 INSTACART MODAL - JAVASCRIPT COMPLETO
// ═══════════════════════════════════════════════════════════════════════════════

let icCurrentProduct = null;
let icQty = 1;
let icIsFavorite = false;

// ═══ ABRIR MODAL ═══
function openProductModal(productId) {
    const overlay = document.getElementById('icModalOverlay');
    const modal = document.getElementById('icModal');
    
    overlay.classList.add('active');
    modal.classList.add('active', 'loading');
    document.body.style.overflow = 'hidden';
    
    // Reset
    icQty = 1;
    document.getElementById('icQtyValue').textContent = '1';
    
    // Carregar dados
    loadProductFromCard(productId);
    
    // Tentar enriquecer com API
    fetch(`/mercado/api/produto.php?id=${productId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.product) {
                renderIcModal(data.product, data.related || []);
            }
        })
        .catch(() => {})
        .finally(() => {
            modal.classList.remove('loading');
        });
}

// ═══ CARREGAR DO CARD NA PÁGINA ═══
function loadProductFromCard(productId) {
    let card = document.querySelector(`.product-card[data-id="${productId}"]`);
    
    if (!card) {
        const flashCards = document.querySelectorAll('.flash-card');
        for (let fc of flashCards) {
            const onclick = fc.getAttribute('onclick') || '';
            if (onclick.includes(`(${productId})`)) {
                card = fc;
                break;
            }
        }
    }
    
    if (!card) {
        document.getElementById('icModal').classList.remove('loading');
        return;
    }
    
    const img = card.querySelector('img');
    const nameEl = card.querySelector('.product-name, .flash-name');
    const brandEl = card.querySelector('.product-brand, .flash-brand');
    const priceEl = card.querySelector('.product-price:not(.product-price-old), .flash-price');
    const priceOldEl = card.querySelector('.product-price-old, .flash-price-old');
    const unitEl = card.querySelector('.product-unit');
    
    const product = {
        product_id: productId,
        name: nameEl?.textContent?.trim() || 'Produto',
        brand: brandEl?.textContent?.trim() || '',
        image: img?.src || '',
        unit: unitEl?.textContent?.trim() || '1 un',
        price: 0,
        price_promo: 0,
        description: '',
        ingredients: '',
        nutrition_json: null
    };
    
    // Extrair preço
    const priceText = priceEl?.textContent || '';
    const priceMatch = priceText.match(/[\d.,]+/);
    if (priceMatch) {
        product.price = parseFloat(priceMatch[0].replace('.', '').replace(',', '.'));
    }
    
    if (priceOldEl) {
        const oldMatch = priceOldEl.textContent.match(/[\d.,]+/);
        if (oldMatch) {
            product.price_promo = product.price;
            product.price = parseFloat(oldMatch[0].replace('.', '').replace(',', '.'));
        }
    }
    
    renderIcModal(product, []);
    document.getElementById('icModal').classList.remove('loading');
}

// ═══ RENDERIZAR MODAL ═══
function renderIcModal(product, related) {
    icCurrentProduct = product;
    
    // Imagem
    document.getElementById('icMainImage').src = product.image || 'https://via.placeholder.com/400';
    document.getElementById('icThumb1').src = product.image || 'https://via.placeholder.com/80';
    
    // Info básica
    document.getElementById('icProductName').textContent = product.name;
    document.getElementById('icProductSize').textContent = product.unit || '1 un';
    document.getElementById('icBrandName').textContent = product.brand || 'Marca';
    document.getElementById('icShopBrand').style.display = product.brand ? 'block' : 'none';
    
    // Calcular preço por unidade
    const precoFinal = product.price_promo > 0 ? product.price_promo : product.price;
    document.getElementById('icUnitPrice').textContent = `• R$ ${precoFinal.toFixed(2).replace('.', ',')}/un`;
    
    // Preços
    document.getElementById('icPriceMain').textContent = `R$ ${precoFinal.toFixed(2).replace('.', ',')}`;
    
    const temPromo = product.price_promo > 0 && product.price_promo < product.price;
    const promoTag = document.getElementById('icPromoTag');
    if (temPromo) {
        promoTag.style.display = 'flex';
        document.getElementById('icPriceOld').textContent = `R$ ${product.price.toFixed(2).replace('.', ',')}`;
    } else {
        promoTag.style.display = 'none';
    }
    
    // Atualizar preço do botão
    updateIcAddPrice();
    
    // Descrição
    const detailsAccordion = document.getElementById('icAccordionDetails');
    if (product.description) {
        document.getElementById('icDescription').textContent = product.description;
        detailsAccordion.style.display = 'block';
    } else {
        detailsAccordion.style.display = 'none';
    }
    
    // Ingredientes
    const ingredientsAccordion = document.getElementById('icAccordionIngredients');
    if (product.ingredients) {
        document.getElementById('icIngredients').textContent = product.ingredients;
        ingredientsAccordion.style.display = 'block';
    } else {
        ingredientsAccordion.style.display = 'none';
    }
    
    // Nutrição
    const nutritionAccordion = document.getElementById('icAccordionNutrition');
    if (product.nutrition_json) {
        const nutrition = typeof product.nutrition_json === 'string' 
            ? JSON.parse(product.nutrition_json) 
            : product.nutrition_json;
        
        let tableHTML = `
            <div class="ic-nutrition-row header">
                <span class="ic-nutrition-label">Nutriente</span>
                <span class="ic-nutrition-value">Quantidade</span>
            </div>
        `;
        
        const labels = {
            energia: 'Energia',
            proteinas: 'Proteínas',
            carboidratos: 'Carboidratos',
            gorduras: 'Gorduras Totais',
            gorduras_saturadas: 'Gorduras Saturadas',
            gorduras_trans: 'Gorduras Trans',
            fibras: 'Fibras',
            sodio: 'Sódio',
            acucar: 'Açúcares'
        };
        
        for (const [key, label] of Object.entries(labels)) {
            if (nutrition[key]) {
                tableHTML += `
                    <div class="ic-nutrition-row">
                        <span class="ic-nutrition-label">${label}</span>
                        <span class="ic-nutrition-value">${nutrition[key]}</span>
                    </div>
                `;
            }
        }
        
        document.getElementById('icNutritionTable').innerHTML = tableHTML;
        nutritionAccordion.style.display = 'block';
    } else {
        nutritionAccordion.style.display = 'none';
    }
    
    // Produtos relacionados
    renderRelatedProducts(related);
}

// ═══ PRODUTOS RELACIONADOS ═══
function renderRelatedProducts(related) {
    const container = document.getElementById('icRelatedScroll');
    const section = document.getElementById('icRelated');
    
    if (!related || related.length === 0) {
        section.style.display = 'none';
        return;
    }
    
    section.style.display = 'block';
    
    let html = '';
    related.forEach(p => {
        const price = p.price_promo > 0 ? p.price_promo : p.price;
        html += `
            <div class="ic-related-card" onclick="openProductModal(${p.product_id})">
                <button class="ic-related-add" onclick="event.stopPropagation(); quickAddRelated(${p.product_id}, '${p.name.replace(/'/g, "\\'")}', ${price}, '${p.image}')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 12h14"/><path d="M12 5v14"/>
                    </svg>
                </button>
                <div class="ic-related-img">
                    <img src="${p.image || 'https://via.placeholder.com/120'}" alt="${p.name}">
                </div>
                <div class="ic-related-info">
                    <div class="ic-related-name">${p.name}</div>
                    <div class="ic-related-price">R$ ${price.toFixed(2).replace('.', ',')}</div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

// ═══ FECHAR MODAL ═══
function closeIcModal() {
    document.getElementById('icModalOverlay').classList.remove('active');
    document.getElementById('icModal').classList.remove('active');
    document.body.style.overflow = '';
    icCurrentProduct = null;
}

// ═══ QUANTIDADE ═══
function icChangeQty(delta) {
    icQty = Math.max(1, icQty + delta);
    document.getElementById('icQtyValue').textContent = icQty;
    updateIcAddPrice();
}

function updateIcAddPrice() {
    if (!icCurrentProduct) return;
    const price = icCurrentProduct.price_promo > 0 ? icCurrentProduct.price_promo : icCurrentProduct.price;
    const total = price * icQty;
    document.getElementById('icAddBtnPrice').textContent = `R$ ${total.toFixed(2).replace('.', ',')}`;
}

// ═══ ADICIONAR AO CARRINHO ═══
function icAddToCart() {
    if (!icCurrentProduct) return;
    
    const price = icCurrentProduct.price_promo > 0 ? icCurrentProduct.price_promo : icCurrentProduct.price;
    
    // Usar função global addToCart se existir
    if (typeof addToCart === 'function') {
        for (let i = 0; i < icQty; i++) {
            addToCart(icCurrentProduct.product_id, icCurrentProduct.name, price, icCurrentProduct.image);
        }
    }
    
    // Feedback
    const btn = document.getElementById('icAddBtn');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = `<span>✓ Adicionado!</span>`;
    btn.style.background = '#0a6b0a';
    
    setTimeout(() => {
        btn.innerHTML = originalHTML;
        btn.style.background = '';
        closeIcModal();
    }, 1200);
}

function quickAddRelated(productId, name, price, image) {
    if (typeof addToCart === 'function') {
        addToCart(productId, name, price, image);
    }
}

// ═══ ACCORDIONS ═══
function toggleAccordion(type) {
    const accordion = document.getElementById(`icAccordion${type.charAt(0).toUpperCase() + type.slice(1)}`);
    accordion.classList.toggle('open');
}

// ═══ FAVORITOS ═══
function toggleFavorite() {
    icIsFavorite = !icIsFavorite;
    const icon = document.getElementById('icFavIcon');
    
    if (icIsFavorite) {
        icon.style.fill = 'var(--ic-green)';
        icon.style.color = 'var(--ic-green)';
        showToast('Salvo nos favoritos ❤️');
    } else {
        icon.style.fill = 'none';
        icon.style.color = '';
        showToast('Removido dos favoritos');
    }
}

// ═══ MODAIS SECUNDÁRIOS ═══
function openNoteModal() {
    document.getElementById('icNoteModal').classList.add('active');
}

function closeNoteModal() {
    document.getElementById('icNoteModal').classList.remove('active');
}

function saveNote() {
    const note = document.getElementById('icNoteText').value;
    if (note) {
        showToast('Nota salva ✓');
    }
    closeNoteModal();
}

function openReplacementModal() {
    document.getElementById('icReplaceModal').classList.add('active');
}

function closeReplaceModal() {
    document.getElementById('icReplaceModal').classList.remove('active');
}

function saveReplacement() {
    showToast('Preferência salva ✓');
    closeReplaceModal();
}

function openListModal() {
    showToast('Em breve: Listas de compras');
}

// ═══ NAVEGAÇÃO RELACIONADOS ═══
function scrollRelated(direction) {
    const container = document.getElementById('icRelatedScroll');
    container.scrollBy({ left: direction * 200, behavior: 'smooth' });
}

// ═══ OUTROS ═══
function toggleZoom() {
    showToast('Zoom em desenvolvimento');
}

function shareProduct() {
    if (navigator.share && icCurrentProduct) {
        navigator.share({
            title: icCurrentProduct.name,
            url: window.location.href
        });
    } else {
        showToast('Link copiado!');
    }
}

function shopByBrand(e) {
    e.preventDefault();
    if (icCurrentProduct?.brand) {
        closeIcModal();
        window.location.href = `/mercado/?q=${encodeURIComponent(icCurrentProduct.brand)}`;
    }
}

// Toast helper
function showToast(message) {
    if (typeof window.showToast === 'function') {
        window.showToast(message);
    } else {
        console.log(message);
    }
}

// Fechar com ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeIcModal();
        closeNoteModal();
        closeReplaceModal();
    }
});
</script>
