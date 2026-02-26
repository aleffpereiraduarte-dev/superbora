<?php
/**
 * 🟢 HEADER VERDE - ONEMUNDO MERCADO
 */
?>
<style>
.om-header {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: white;
    position: sticky;
    top: 0;
    z-index: 1000;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
.om-header-top {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    gap: 12px;
    max-width: 1400px;
    margin: 0 auto;
}
.om-logo {
    font-size: 22px;
    font-weight: 800;
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
}
.om-logo span:last-child {
    font-weight: 400;
    font-size: 14px;
    opacity: 0.9;
}
.om-search {
    flex: 1;
    max-width: 600px;
    position: relative;
}
.om-search input {
    width: 100%;
    padding: 10px 16px 10px 42px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    background: rgba(255,255,255,0.95);
    color: #1f2937;
}
.om-search input:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(255,255,255,0.3);
}
.om-search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
}
.om-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}
.om-header-btn {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    padding: 8px 14px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    transition: all 0.2s;
    text-decoration: none;
}
.om-header-btn:hover {
    background: rgba(255,255,255,0.25);
}
.om-cart-badge {
    background: #fbbf24;
    color: #1f2937;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 10px;
}
.om-location {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(0,0,0,0.1);
    font-size: 13px;
}
.hide-mobile { display: inline; }
@media (max-width: 768px) {
    .om-header-top { flex-wrap: wrap; }
    .om-search { order: 3; width: 100%; margin-top: 10px; max-width: none; }
    .om-header-actions { margin-left: auto; }
    .hide-mobile { display: none; }
}
</style>

<header class="om-header">
    <div class="om-header-top">
        <a href="/mercado/" class="om-logo">
            <span>🛒</span>
            <span style="font-weight:700">OneMundo</span>
            <span>Mercado</span>
        </a>
        
        <div class="om-search">
            <span class="om-search-icon">🔍</span>
            <input type="text" id="headerSearch" placeholder="Buscar produtos..." 
                   onkeyup="if(event.key==='Enter')window.location='/mercado/?busca='+encodeURIComponent(this.value)">
        </div>
        
        <div class="om-header-actions">
            <a href="/mercado/estabelecimentos.php" class="om-header-btn">
                <span>🏪</span>
                <span class="hide-mobile">Estabelecimentos</span>
            </a>
            <?php if (isset($customer_id) && $customer_id): ?>
            <a href="/mercado/conta.php" class="om-header-btn">
                <span>👤</span>
                <span class="hide-mobile"><?= htmlspecialchars($customer_primeiro_nome ?? 'Conta') ?></span>
            </a>
            <?php else: ?>
            <a href="/mercado/login.php" class="om-header-btn">
                <span>👤</span>
                <span class="hide-mobile">Entrar</span>
            </a>
            <?php endif; ?>
            
            <a href="/mercado/carrinho.php" class="om-header-btn">
                <span>🛒</span>
                <span class="hide-mobile">Carrinho</span>
                <?php if (isset($cart_count) && $cart_count > 0): ?>
                <span class="om-cart-badge"><?= $cart_count ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>
    
    <?php if (isset($customer_bairro) && $customer_bairro): ?>
    <div class="om-location">
        <span>📍</span>
        <span>Entregar em <strong><?= htmlspecialchars($customer_bairro) ?></strong></span>
        <a href="/mercado/conta.php#enderecos" style="color:#fbbf24;margin-left:8px;">Alterar</a>
    </div>
    <?php endif; ?>
</header>