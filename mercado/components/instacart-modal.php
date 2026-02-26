<!-- INSTACART MODAL + CART -->
<div class="ic-modal-overlay" id="icModalOverlay" onclick="icCloseProductModal()"></div>
<div class="ic-product-modal" id="icProductModal"></div>

<div class="ic-cart-overlay" id="icCartOverlay" onclick="icCloseCart()"></div>
<div class="ic-cart-sidebar" id="icCartSidebar">
    <div class="ic-cart-header">
        <span class="ic-cart-title">Seu Carrinho</span>
        <button class="ic-modal-close" onclick="icCloseCart()">X</button>
    </div>
    <div class="ic-cart-items" id="icCartItems"></div>
    <div class="ic-cart-footer">
        <button class="ic-checkout-btn" onclick="location.href='/mercado/checkout.php'">
            <span>Ir para checkout</span>
            <span id="icCartTotal">R$ 0,00</span>
        </button>
    </div>
</div>

<div class="ic-float-btn" onclick="icOpenCart()">
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
        <circle cx="9" cy="21" r="1"/>
        <circle cx="20" cy="21" r="1"/>
        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
    </svg>
    <span class="ic-float-badge">0</span>
</div>