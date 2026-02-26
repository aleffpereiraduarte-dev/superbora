<?php

// Central Ultra Pagar.me
require_once $_SERVER['DOCUMENT_ROOT'] . '/system/library/PagarmeCenterUltra.php';
$pagarme = PagarmeCenterUltra::getInstance();

/**
 * 💳 CHECKOUT MERCADO - PAGAR.ME
 */

$oc_root = dirname(__DIR__);
require_once($oc_root . '/config.php');

$pdo = new PDO(
    "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
    DB_USERNAME, DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$order_id = (int)($_GET['id'] ?? 0);

if (!$order_id) {
    header('Location: /mercado/');
    exit;
}

// Buscar pedido
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: /mercado/');
    exit;
}

// Se já foi pago
if ($order['payment_status'] === 'paid') {
    header('Location: /mercado/tracking.php?id=' . $order_id);
    exit;
}

// Buscar itens
$stmt = $pdo->prepare("SELECT oi.*, p.image FROM om_market_order_items oi LEFT JOIN om_products p ON oi.product_id = p.product_id WHERE oi.order_id = ?");
$stmt->execute([$order_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar cartões salvos
$saved_cards = [];
if ($order['customer_id'] && is_numeric($order['customer_id']) && $order['customer_id'] > 0) {
    $stmt = $pdo->prepare("SELECT pagarme_customer_id FROM oc_customer WHERE customer_id = ?");
    $stmt->execute([(int)$order['customer_id']]);
    $pagarme_id = $stmt->fetchColumn();
    
    if ($pagarme_id) {
        // Buscar via API
        $ch = curl_init("https://api.pagar.me/core/v5/customers/{$pagarme_id}/cards");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode('sk_e95918f47a414bffaaf2848cd691783e:')
            ]
        ]);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($response['data'])) {
            $saved_cards = $response['data'];
        }
    }
}

$pagarme_public_key = PAGARME_PUBLIC_KEY;
?>
<?php
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💳 Pagamento - Pedido #<?= $order['order_number'] ?></title>
    <script src="https://js.pagar.me/v5/pagarme.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; }
        .container { max-width: 500px; margin: 0 auto; padding: 20px; padding-bottom: 100px; }
        
        .header { text-align: center; margin-bottom: 24px; }
        .header h1 { font-size: 1.3rem; margin-bottom: 8px; }
        .order-number { color: #64748b; font-size: 14px; }
        
        .summary { background: #1e293b; border-radius: 12px; padding: 16px; margin-bottom: 20px; }
        .summary-title { font-weight: 600; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .summary-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; }
        .summary-row.total { border-top: 1px solid #334155; margin-top: 8px; padding-top: 12px; font-weight: 600; font-size: 16px; }
        .summary-row.total .value { color: #10b981; }
        
        .payment-methods { margin-bottom: 20px; }
        .method-tabs { display: flex; gap: 10px; margin-bottom: 16px; }
        .method-tab { flex: 1; padding: 14px; border: 2px solid #334155; border-radius: 10px; background: transparent; color: #94a3b8; font-size: 14px; font-weight: 600; cursor: pointer; text-align: center; transition: all 0.2s; }
        .method-tab:hover { border-color: #475569; }
        .method-tab.active { border-color: #10b981; color: #10b981; background: #10b98110; }
        .method-tab .icon { font-size: 24px; display: block; margin-bottom: 4px; }
        
        .method-content { display: none; }
        .method-content.active { display: block; }
        
        .card { background: #1e293b; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        .card-title { font-weight: 600; margin-bottom: 16px; }
        
        .saved-cards { margin-bottom: 16px; }
        .saved-card { display: flex; align-items: center; gap: 12px; padding: 12px; background: #0f172a; border-radius: 8px; margin-bottom: 8px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s; }
        .saved-card:hover { border-color: #475569; }
        .saved-card.selected { border-color: #10b981; background: #10b98110; }
        .card-brand { width: 40px; height: 28px; background: #334155; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; }
        .card-info { flex: 1; }
        .card-number { font-size: 14px; font-weight: 500; }
        .card-name { font-size: 12px; color: #64748b; }
        .card-check { width: 20px; height: 20px; border-radius: 50%; border: 2px solid #334155; }
        .saved-card.selected .card-check { background: #10b981; border-color: #10b981; }
        
        .new-card-btn { width: 100%; padding: 12px; border: 2px dashed #334155; border-radius: 8px; background: transparent; color: #94a3b8; font-size: 14px; cursor: pointer; transition: all 0.2s; }
        .new-card-btn:hover { border-color: #475569; color: #e2e8f0; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 6px; }
        .form-group input { width: 100%; padding: 12px 14px; border: 1px solid #334155; border-radius: 8px; background: #0f172a; color: #e2e8f0; font-size: 15px; }
        .form-group input:focus { outline: none; border-color: #10b981; }
        .form-row { display: flex; gap: 12px; }
        .form-row .form-group { flex: 1; }
        
        .checkbox-group { display: flex; align-items: center; gap: 10px; margin-top: 12px; }
        .checkbox-group input { width: 18px; height: 18px; }
        .checkbox-group label { font-size: 13px; color: #94a3b8; }
        
        .pix-container { text-align: center; padding: 20px; }
        .pix-qr { width: 200px; height: 200px; background: white; border-radius: 12px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; }
        .pix-qr img { max-width: 180px; max-height: 180px; }
        .pix-code { background: #0f172a; padding: 12px; border-radius: 8px; font-family: monospace; font-size: 11px; word-break: break-all; margin-bottom: 12px; }
        .pix-copy-btn { padding: 10px 20px; background: #334155; border: none; border-radius: 8px; color: #e2e8f0; font-size: 14px; cursor: pointer; }
        .pix-status { margin-top: 16px; padding: 12px; border-radius: 8px; }
        .pix-status.pending { background: #f59e0b20; color: #f59e0b; }
        .pix-status.paid { background: #10b98120; color: #10b981; }
        
        .pay-btn { width: 100%; padding: 16px; border: none; border-radius: 12px; background: linear-gradient(135deg, #10b981, #059669); color: white; font-size: 16px; font-weight: 600; cursor: pointer; margin-top: 20px; transition: all 0.2s; }
        .pay-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3); }
        .pay-btn:disabled { background: #334155; cursor: not-allowed; transform: none; box-shadow: none; }
        
        .secure-badge { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px; color: #64748b; font-size: 12px; }
        
        .loading { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.9); z-index: 1000; align-items: center; justify-content: center; flex-direction: column; gap: 16px; }
        .loading.active { display: flex; }
        .spinner { width: 48px; height: 48px; border: 4px solid #334155; border-top-color: #10b981; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .error-msg { background: #ef444420; border: 1px solid #ef4444; color: #ef4444; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; display: none; }
    </style>

<!-- HEADER PREMIUM v3.0 -->
<style>

/* ══════════════════════════════════════════════════════════════════════════════
   🎨 HEADER PREMIUM v3.0 - OneMundo Mercado
   ══════════════════════════════════════════════════════════════════════════════ */

/* Variáveis do Header */
:root {
    --header-bg: rgba(255, 255, 255, 0.92);
    --header-bg-scrolled: rgba(255, 255, 255, 0.98);
    --header-blur: 20px;
    --header-shadow: 0 4px 30px rgba(0, 0, 0, 0.08);
    --header-border: rgba(0, 0, 0, 0.04);
    --header-height: 72px;
    --header-height-mobile: 64px;
}

/* Header Principal */
.header, .site-header, [class*="header-main"] {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 1000 !important;
    background: var(--header-bg) !important;
    backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    -webkit-backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    border-bottom: 1px solid var(--header-border) !important;
    box-shadow: none !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    height: auto !important;
    min-height: var(--header-height) !important;
}

.header.scrolled, .site-header.scrolled {
    background: var(--header-bg-scrolled) !important;
    box-shadow: var(--header-shadow) !important;
}

/* Container do Header */
.header-inner, .header-content, .header > div:first-child {
    max-width: 1400px !important;
    margin: 0 auto !important;
    padding: 12px 24px !important;
    display: flex !important;
    align-items: center !important;
    gap: 20px !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOCALIZAÇÃO - Estilo Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.location-btn, .endereco, [class*="location"], [class*="endereco"], [class*="address"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 10px 18px !important;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(16, 185, 129, 0.04)) !important;
    border: 1px solid rgba(16, 185, 129, 0.15) !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    min-width: 200px !important;
    max-width: 320px !important;
}

.location-btn:hover, .endereco:hover, [class*="location"]:hover {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.06)) !important;
    border-color: rgba(16, 185, 129, 0.25) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15) !important;
}

/* Ícone de localização */
.location-btn svg, .location-btn i, [class*="location"] svg {
    width: 22px !important;
    height: 22px !important;
    color: #10b981 !important;
    flex-shrink: 0 !important;
}

/* Texto da localização */
.location-text, .endereco-text {
    flex: 1 !important;
    min-width: 0 !important;
}

.location-label, .entregar-em {
    font-size: 11px !important;
    font-weight: 500 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin-bottom: 2px !important;
}

.location-address, .endereco-rua {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Seta da localização */
.location-arrow, .location-btn > svg:last-child {
    width: 16px !important;
    height: 16px !important;
    color: #94a3b8 !important;
    transition: transform 0.2s ease !important;
}

.location-btn:hover .location-arrow {
    transform: translateX(3px) !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   TEMPO DE ENTREGA - Badge Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.delivery-time, .tempo-entrega, [class*="delivery-time"], [class*="tempo"] {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 10px 16px !important;
    background: linear-gradient(135deg, #0f172a, #1e293b) !important;
    border-radius: 12px !important;
    color: white !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2) !important;
    transition: all 0.3s ease !important;
}

.delivery-time:hover, .tempo-entrega:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.25) !important;
}

.delivery-time svg, .tempo-entrega svg, .delivery-time i {
    width: 18px !important;
    height: 18px !important;
    color: #10b981 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOGO - Design Moderno
   ══════════════════════════════════════════════════════════════════════════════ */

.logo, .site-logo, [class*="logo"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    text-decoration: none !important;
    transition: transform 0.3s ease !important;
}

.logo:hover {
    transform: scale(1.02) !important;
}

.logo-icon, .logo img, .logo svg {
    width: 48px !important;
    height: 48px !important;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    border-radius: 14px !important;
    padding: 10px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.logo:hover .logo-icon, .logo:hover img {
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
    transform: rotate(-3deg) !important;
}

.logo-text, .logo span, .site-title {
    font-size: 1.5rem !important;
    font-weight: 800 !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
    background-clip: text !important;
    letter-spacing: -0.02em !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   BUSCA - Search Bar Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.search-container, .search-box, [class*="search"], .busca {
    flex: 1 !important;
    max-width: 600px !important;
    position: relative !important;
}

.search-input, input[type="search"], input[name*="search"], input[name*="busca"], .busca input {
    width: 100% !important;
    padding: 14px 20px 14px 52px !important;
    background: #f1f5f9 !important;
    border: 2px solid transparent !important;
    border-radius: 16px !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
}

.search-input:hover, input[type="search"]:hover {
    background: #e2e8f0 !important;
}

.search-input:focus, input[type="search"]:focus {
    background: #ffffff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12), inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
    outline: none !important;
}

.search-input::placeholder {
    color: #94a3b8 !important;
    font-weight: 400 !important;
}

/* Ícone da busca */
.search-icon, .search-container svg, .busca svg {
    position: absolute !important;
    left: 18px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 22px !important;
    height: 22px !important;
    color: #94a3b8 !important;
    pointer-events: none !important;
    transition: color 0.3s ease !important;
}

.search-input:focus + .search-icon,
.search-container:focus-within svg {
    color: #10b981 !important;
}

/* Botão de busca por voz (opcional) */
.search-voice-btn {
    position: absolute !important;
    right: 12px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 36px !important;
    height: 36px !important;
    background: transparent !important;
    border: none !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.search-voice-btn:hover {
    background: rgba(16, 185, 129, 0.1) !important;
}

.search-voice-btn svg {
    width: 20px !important;
    height: 20px !important;
    color: #64748b !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   CARRINHO - Cart Button Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.cart-btn, .carrinho-btn, [class*="cart"], [class*="carrinho"], a[href*="cart"], a[href*="carrinho"] {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 52px !important;
    height: 52px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border: none !important;
    border-radius: 16px !important;
    cursor: pointer !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
}

.cart-btn:hover, .carrinho-btn:hover, [class*="cart"]:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4) !important;
}

.cart-btn:active {
    transform: translateY(-1px) scale(0.98) !important;
}

.cart-btn svg, .carrinho-btn svg, [class*="cart"] svg {
    width: 26px !important;
    height: 26px !important;
    color: white !important;
}

/* Badge do carrinho */
.cart-badge, .carrinho-badge, [class*="cart-count"], [class*="badge"] {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 24px !important;
    height: 24px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: white !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 6px !important;
    border: 3px solid white !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: badge-pulse 2s ease-in-out infinite !important;
}

@keyframes badge-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* ══════════════════════════════════════════════════════════════════════════════
   MENU MOBILE
   ══════════════════════════════════════════════════════════════════════════════ */

.menu-btn, .hamburger, [class*="menu-toggle"] {
    display: none !important;
    width: 44px !important;
    height: 44px !important;
    background: #f1f5f9 !important;
    border: none !important;
    border-radius: 12px !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.menu-btn:hover {
    background: #e2e8f0 !important;
}

.menu-btn svg {
    width: 24px !important;
    height: 24px !important;
    color: #475569 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   RESPONSIVO
   ══════════════════════════════════════════════════════════════════════════════ */

@media (max-width: 1024px) {
    .search-container, .search-box {
        max-width: 400px !important;
    }
    
    .location-btn, .endereco {
        max-width: 250px !important;
    }
}

@media (max-width: 768px) {
    :root {
        --header-height: var(--header-height-mobile);
    }
    
    .header-inner, .header-content {
        padding: 10px 16px !important;
        gap: 12px !important;
    }
    
    /* Esconder busca no header mobile - mover para baixo */
    .search-container, .search-box, [class*="search"]:not(.search-icon) {
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        right: 0 !important;
        max-width: 100% !important;
        padding: 12px 16px !important;
        background: white !important;
        border-top: 1px solid #e2e8f0 !important;
        display: none !important;
    }
    
    .search-container.active {
        display: block !important;
    }
    
    /* Logo menor */
    .logo-icon, .logo img {
        width: 42px !important;
        height: 42px !important;
        border-radius: 12px !important;
    }
    
    .logo-text {
        display: none !important;
    }
    
    /* Localização compacta */
    .location-btn, .endereco {
        min-width: auto !important;
        max-width: 180px !important;
        padding: 8px 12px !important;
    }
    
    .location-label, .entregar-em {
        display: none !important;
    }
    
    .location-address {
        font-size: 13px !important;
    }
    
    /* Tempo de entrega menor */
    .delivery-time, .tempo-entrega {
        padding: 8px 12px !important;
        font-size: 12px !important;
    }
    
    /* Carrinho menor */
    .cart-btn, .carrinho-btn {
        width: 46px !important;
        height: 46px !important;
        border-radius: 14px !important;
    }
    
    .cart-btn svg {
        width: 22px !important;
        height: 22px !important;
    }
    
    /* Mostrar menu button */
    .menu-btn, .hamburger {
        display: flex !important;
    }
}

@media (max-width: 480px) {
    .location-btn, .endereco {
        max-width: 140px !important;
    }
    
    .delivery-time, .tempo-entrega {
        display: none !important;
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   ANIMAÇÕES DE ENTRADA
   ══════════════════════════════════════════════════════════════════════════════ */

@keyframes headerSlideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.header, .site-header {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *, .header-content > * {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *:nth-child(1) { animation-delay: 0.05s !important; }
.header-inner > *:nth-child(2) { animation-delay: 0.1s !important; }
.header-inner > *:nth-child(3) { animation-delay: 0.15s !important; }
.header-inner > *:nth-child(4) { animation-delay: 0.2s !important; }
.header-inner > *:nth-child(5) { animation-delay: 0.25s !important; }

/* ══════════════════════════════════════════════════════════════════════════════
   AJUSTES DE BODY PARA HEADER FIXED
   ══════════════════════════════════════════════════════════════════════════════ */

body {
    padding-top: calc(var(--header-height) + 10px) !important;
}

@media (max-width: 768px) {
    body {
        padding-top: calc(var(--header-height-mobile) + 10px) !important;
    }
}

</style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>💳 Pagamento</h1>
        <div class="order-number">Pedido #<?= htmlspecialchars($order['order_number']) ?></div>
    </div>
    
    <!-- Resumo -->
    <div class="summary">
        <div class="summary-title">🛒 Resumo</div>
        <?php foreach ($items as $item): ?>
        <div class="summary-row">
            <span><?= (int)$item['quantity'] ?>x <?= htmlspecialchars($item['name']) ?></span>
            <span>R$ <?= number_format($item['total'], 2, ',', '.') ?></span>
        </div>
        <?php endforeach; ?>
        <?php if ($order['delivery_fee'] > 0): ?>
        <div class="summary-row">
            <span>🚴 Entrega</span>
            <span>R$ <?= number_format($order['delivery_fee'], 2, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <div class="summary-row total">
            <span>Total</span>
            <span class="value">R$ <?= number_format($order['total'], 2, ',', '.') ?></span>
        </div>
    </div>
    
    <div class="error-msg" id="error-msg"></div>
    
    <!-- Métodos de Pagamento -->
    <div class="payment-methods">
        <div class="method-tabs">
            <button class="method-tab active" data-method="card">
                <span class="icon">💳</span>
                Cartão
            </button>
            <button class="method-tab" data-method="pix">
                <span class="icon">📱</span>
                PIX
            </button>
        </div>
        
        <!-- CARTÃO -->
        <div class="method-content active" id="method-card">
            <?php if (!empty($saved_cards)): ?>
            <div class="card">
                <div class="card-title">💳 Cartões Salvos</div>
                <div class="saved-cards">
                    <?php foreach ($saved_cards as $card): ?>
                    <div class="saved-card" data-card-id="<?= $card['id'] ?>">
                        <div class="card-brand"><?= strtoupper(substr($card['brand'], 0, 4)) ?></div>
                        <div class="card-info">
                            <div class="card-number">•••• •••• •••• <?= $card['last_four_digits'] ?></div>
                            <div class="card-name"><?= htmlspecialchars($card['holder_name']) ?></div>
                        </div>
                        <div class="card-check"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="new-card-btn" id="new-card-btn">+ Usar outro cartão</button>
            </div>
            <?php endif; ?>
            
            <div class="card" id="new-card-form" style="<?= empty($saved_cards) ? '' : 'display:none;' ?>">
                <div class="card-title">💳 <?= empty($saved_cards) ? 'Dados do Cartão' : 'Novo Cartão' ?></div>
                
                <div class="form-group">
                    <label>Número do Cartão</label>
                    <input type="text" id="card-number" placeholder="0000 0000 0000 0000" maxlength="19">
                </div>
                
                <div class="form-group">
                    <label>Nome no Cartão</label>
                    <input type="text" id="card-holder" placeholder="NOME COMO ESTÁ NO CARTÃO">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Validade</label>
                        <input type="text" id="card-expiry" placeholder="MM/AA" maxlength="5">
                    </div>
                    <div class="form-group">
                        <label>CVV</label>
                        <input type="text" id="card-cvv" placeholder="123" maxlength="4">
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="save-card" checked>
                    <label for="save-card">Salvar cartão para compras futuras</label>
                </div>
            </div>
            
            <button class="pay-btn" id="pay-card-btn">
                Pagar R$ <?= number_format($order['total'], 2, ',', '.') ?>
            </button>
        </div>
        
        <!-- PIX -->
        <div class="method-content" id="method-pix">
            <div class="card">
                <div class="pix-container" id="pix-container">
                    <div style="color:#64748b;padding:40px;">
                        Clique abaixo para gerar o QR Code PIX
                    </div>
                </div>
            </div>
            
            <button class="pay-btn" id="pay-pix-btn">
                Gerar QR Code PIX
            </button>
        </div>
    </div>
    
    <div class="secure-badge">
        🔒 Pagamento seguro via Pagar.me
    </div>
</div>

<!-- Loading -->
<div class="loading" id="loading">
    <div class="spinner"></div>
    <div>Processando pagamento...</div>
</div>

<script>
const ORDER_ID = <?= (int)$order_id ?>;
const TOTAL = <?= (float)$order['total'] ?>;
const PUBLIC_KEY = <?= json_encode($pagarme_public_key) ?>;

let selectedCardId = null;
let pixPolling = null;

// Tabs
document.querySelectorAll('.method-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.method-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.method-content').forEach(c => c.classList.remove('active'));
        
        tab.classList.add('active');
        document.getElementById('method-' + tab.dataset.method).classList.add('active');
    });
});

// Cartões salvos
document.querySelectorAll('.saved-card').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('.saved-card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        selectedCardId = card.dataset.cardId;
        document.getElementById('new-card-form').style.display = 'none';
    });
});

// Novo cartão
document.getElementById('new-card-btn')?.addEventListener('click', () => {
    document.querySelectorAll('.saved-card').forEach(c => c.classList.remove('selected'));
    selectedCardId = null;
    document.getElementById('new-card-form').style.display = 'block';
});

// Máscaras
document.getElementById('card-number')?.addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '');
    v = v.replace(/(\d{4})/g, '$1 ').trim();
    e.target.value = v.substring(0, 19);
});

document.getElementById('card-expiry')?.addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '');
    if (v.length >= 2) v = v.substring(0,2) + '/' + v.substring(2);
    e.target.value = v.substring(0, 5);
});

document.getElementById('card-cvv')?.addEventListener('input', function(e) {
    e.target.value = e.target.value.replace(/\D/g, '').substring(0, 4);
});

// Erro
function showError(msg) {
    const el = document.getElementById('error-msg');
    el.textContent = msg;
    el.style.display = 'block';
    setTimeout(() => el.style.display = 'none', 5000);
}

// Loading
function setLoading(show) {
    document.getElementById('loading').classList.toggle('active', show);
}

// Pagar com cartão
document.getElementById('pay-card-btn').addEventListener('click', async () => {
    setLoading(true);
    
    // Verificar se ainda na mesma sessão
    if (Date.now() - pageLoadTime > 1800000) { // 30 min
        showError('Sessão expirada. Recarregue a página.');
        setLoading(false);
        return;
    }
    
    try {
        let payload = { action: 'pagar-cartao', order_id: ORDER_ID };
        
        if (selectedCardId) {
            // Cartão salvo
            payload.card_id = selectedCardId;
        } else {
            // Novo cartão - tokenizar
            const cardNumber = document.getElementById('card-number').value.replace(/\s/g, '');
            const cardHolder = document.getElementById('card-holder').value.toUpperCase();
            const [expMonth, expYear] = document.getElementById('card-expiry').value.split('/');
            const cvv = document.getElementById('card-cvv').value;
            
            if (!cardNumber || !cardHolder || !expMonth || !expYear || !cvv) {
                showError('Preencha todos os campos do cartão');
                setLoading(false);
                return;
            }
            
            // Criar token via Pagar.me JS
            const card = {
                number: cardNumber,
                holder_name: cardHolder,
                exp_month: parseInt(expMonth),
                exp_year: parseInt('20' + expYear),
                cvv: cvv
            };
            
            // Tokenizar (usando API direta por simplicidade)
            const tokenRes = await fetch('https://api.pagar.me/core/v5/tokens?appId=' + PUBLIC_KEY, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: 'card', card: card })
            });
            
            const tokenData = await tokenRes.json();
            
            if (!tokenData.id) {
                showError(tokenData.message || 'Erro ao tokenizar cartão');
                setLoading(false);
                return;
            }
            
            payload.card_token = tokenData.id;
            payload.save_card = document.getElementById('save-card').checked;
        }
        
        // Processar pagamento
        const res = await fetch('/mercado/api/pagamento.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        const data = await res.json();
        
        if (data.success) {
            if (data.status === 'paid') {
                window.location.href = '/mercado/tracking.php?id=' + ORDER_ID + '&payment=success';
            } else {
                showError('Pagamento em análise. Aguarde.');
            }
        } else {
            showError(data.error || 'Erro ao processar pagamento');
        }
        
    } catch (e) {
        showError('Erro de conexão: ' + e.message);
    }
    
    setLoading(false);
});

// Gerar PIX
document.getElementById('pay-pix-btn').addEventListener('click', async () => {
    setLoading(true);
    
    try {
        const res = await fetch('/mercado/api/pagamento.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'pagar-pix', order_id: ORDER_ID })
        });
        
        const data = await res.json();
        
        if (data.success && data.pix) {
            const container = document.getElementById('pix-container');
            container.innerHTML = `
                <div class="pix-qr">
                    <img src="${data.pix.qr_code_url}" alt="QR Code PIX">
                </div>
                <div class="pix-code" id="pix-code">${data.pix.qr_code}</div>
                <button class="pix-copy-btn" onclick="copyPix()">📋 Copiar código</button>
                <div class="pix-status pending" id="pix-status">
                    ⏳ Aguardando pagamento...
                </div>
            `;
            
            document.getElementById('pay-pix-btn').style.display = 'none';
            
            // Polling status
            pixPolling = setInterval(checkPixStatus, 5000);
            
        } else {
            showError(data.error || 'Erro ao gerar PIX');
        }
        
    } catch (e) {
        showError('Erro de conexão: ' + e.message);
    }
    
    setLoading(false);
});

// Copiar PIX
function copyPix() {
    const code = document.getElementById('pix-code').textContent;
    navigator.clipboard.writeText(code);
    alert('Código PIX copiado!');
}

// Verificar status PIX
async function checkPixStatus() {
    try {
        const res = await fetch('/mercado/api/pagamento.php?action=status-pagamento&order_id=' + ORDER_ID);
        const data = await res.json();
        
        if (data.paid) {
            clearInterval(pixPolling);
            document.getElementById('pix-status').className = 'pix-status paid';
            document.getElementById('pix-status').innerHTML = '✅ Pagamento confirmado!';
            
            setTimeout(() => {
                window.location.href = '/mercado/tracking.php?id=' + ORDER_ID + '&payment=success';
            }, 2000);
        }
    } catch (e) {}
}
</script>


<script>
// Header scroll effect
(function() {
    const header = document.querySelector('.header, .site-header, [class*="header-main"]');
    if (!header) return;
    
    let lastScroll = 0;
    let ticking = false;
    
    function updateHeader() {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
        
        // Hide/show on scroll (opcional)
        /*
        if (currentScroll > lastScroll && currentScroll > 100) {
            header.style.transform = 'translateY(-100%)';
        } else {
            header.style.transform = 'translateY(0)';
        }
        */
        
        lastScroll = currentScroll;
        ticking = false;
    }
    
    window.addEventListener('scroll', function() {
        if (!ticking) {
            requestAnimationFrame(updateHeader);
            ticking = true;
        }
    });
    
    // Cart badge animation
    window.animateCartBadge = function() {
        const badge = document.querySelector('.cart-badge, .carrinho-badge, [class*="cart-count"]');
        if (badge) {
            badge.style.transform = 'scale(1.3)';
            setTimeout(() => {
                badge.style.transform = 'scale(1)';
            }, 200);
        }
    };
    
    // Mobile search toggle
    const searchToggle = document.querySelector('.search-toggle, [class*="search-btn"]');
    const searchContainer = document.querySelector('.search-container, .search-box');
    
    if (searchToggle && searchContainer) {
        searchToggle.addEventListener('click', function() {
            searchContainer.classList.toggle('active');
        });
    }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     🎨 ONEMUNDO HEADER PREMIUM v3.0 - CSS FINAL UNIFICADO
═══════════════════════════════════════════════════════════════════════════════ -->
<style id="om-header-final">
/* RESET */
.mkt-header, .mkt-header-row, .mkt-logo, .mkt-logo-box, .mkt-logo-text,
.mkt-user, .mkt-user-avatar, .mkt-guest, .mkt-cart, .mkt-cart-count, .mkt-search,
.om-topbar, .om-topbar-main, .om-topbar-icon, .om-topbar-content,
.om-topbar-label, .om-topbar-address, .om-topbar-arrow, .om-topbar-time {
    all: revert;
}

/* TOPBAR VERDE */
.om-topbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 14px 20px !important;
    background: linear-gradient(135deg, #047857 0%, #059669 40%, #10b981 100%) !important;
    color: #fff !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    position: relative !important;
    overflow: hidden !important;
}

.om-topbar::before {
    content: '' !important;
    position: absolute !important;
    top: 0 !important;
    left: -100% !important;
    width: 100% !important;
    height: 100% !important;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent) !important;
    transition: left 0.6s ease !important;
}

.om-topbar:hover::before { left: 100% !important; }
.om-topbar:hover { background: linear-gradient(135deg, #065f46 0%, #047857 40%, #059669 100%) !important; }

.om-topbar-main {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.om-topbar-icon {
    width: 40px !important;
    height: 40px !important;
    background: rgba(255,255,255,0.18) !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar:hover .om-topbar-icon {
    background: rgba(255,255,255,0.25) !important;
    transform: scale(1.05) !important;
}

.om-topbar-icon svg { width: 20px !important; height: 20px !important; color: #fff !important; }

.om-topbar-content { flex: 1 !important; min-width: 0 !important; }

.om-topbar-label {
    font-size: 11px !important;
    font-weight: 500 !important;
    opacity: 0.85 !important;
    margin-bottom: 2px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: block !important;
}

.om-topbar-address {
    font-size: 14px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: 220px !important;
}

.om-topbar-arrow {
    width: 32px !important;
    height: 32px !important;
    background: rgba(255,255,255,0.12) !important;
    border-radius: 8px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    transition: all 0.3s ease !important;
    margin-right: 12px !important;
}

.om-topbar:hover .om-topbar-arrow {
    background: rgba(255,255,255,0.2) !important;
    transform: translateX(3px) !important;
}

.om-topbar-arrow svg { width: 16px !important; height: 16px !important; color: #fff !important; }

.om-topbar-time {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 8px 14px !important;
    background: rgba(0,0,0,0.2) !important;
    border-radius: 50px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar-time:hover { background: rgba(0,0,0,0.3) !important; transform: scale(1.02) !important; }
.om-topbar-time svg { width: 16px !important; height: 16px !important; color: #34d399 !important; }

/* HEADER BRANCO */
.mkt-header {
    background: #ffffff !important;
    padding: 0 !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 9999 !important;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08) !important;
    border-bottom: none !important;
}

.mkt-header-row {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 14px 20px !important;
    margin-bottom: 0 !important;
    background: #fff !important;
    border-bottom: 1px solid rgba(0,0,0,0.06) !important;
}

/* LOGO */
.mkt-logo {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-decoration: none !important;
    flex-shrink: 0 !important;
}

.mkt-logo-box {
    width: 44px !important;
    height: 44px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 14px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 22px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-logo:hover .mkt-logo-box {
    transform: scale(1.05) rotate(-3deg) !important;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45) !important;
}

.mkt-logo-text {
    font-size: 20px !important;
    font-weight: 800 !important;
    color: #10b981 !important;
    letter-spacing: -0.02em !important;
}

/* USER */
.mkt-user { margin-left: auto !important; text-decoration: none !important; }

.mkt-user-avatar {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 50% !important;
    color: #fff !important;
    font-weight: 700 !important;
    font-size: 16px !important;
    box-shadow: 0 3px 12px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.mkt-user-avatar:hover {
    transform: scale(1.08) !important;
    box-shadow: 0 5px 18px rgba(16, 185, 129, 0.4) !important;
}

.mkt-user.mkt-guest {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: #f1f5f9 !important;
    border-radius: 12px !important;
    transition: all 0.3s ease !important;
}

.mkt-user.mkt-guest:hover { background: #e2e8f0 !important; }
.mkt-user.mkt-guest svg { width: 24px !important; height: 24px !important; color: #64748b !important; }

/* CARRINHO */
.mkt-cart {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 46px !important;
    height: 46px !important;
    background: linear-gradient(135deg, #1e293b, #0f172a) !important;
    border: none !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    box-shadow: 0 4px 15px rgba(15, 23, 42, 0.25) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-cart:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.3) !important;
}

.mkt-cart:active { transform: translateY(-1px) scale(0.98) !important; }
.mkt-cart svg { width: 22px !important; height: 22px !important; color: #fff !important; }

.mkt-cart-count {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 22px !important;
    height: 22px !important;
    padding: 0 6px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border-radius: 11px !important;
    color: #fff !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 2px solid #fff !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: cartPulse 2s ease-in-out infinite !important;
}

@keyframes cartPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

/* BUSCA */
.mkt-search {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    background: #f1f5f9 !important;
    border-radius: 14px !important;
    padding: 0 16px !important;
    margin: 0 16px 16px !important;
    border: 2px solid transparent !important;
    transition: all 0.3s ease !important;
}

.mkt-search:focus-within {
    background: #fff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1) !important;
}

.mkt-search svg {
    width: 20px !important;
    height: 20px !important;
    color: #94a3b8 !important;
    flex-shrink: 0 !important;
    transition: color 0.3s ease !important;
}

.mkt-search:focus-within svg { color: #10b981 !important; }

.mkt-search input {
    flex: 1 !important;
    border: none !important;
    background: transparent !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    outline: none !important;
    padding: 14px 0 !important;
    width: 100% !important;
}

.mkt-search input::placeholder { color: #94a3b8 !important; }

/* RESPONSIVO */
@media (max-width: 480px) {
    .om-topbar { padding: 12px 16px !important; }
    .om-topbar-icon { width: 36px !important; height: 36px !important; }
    .om-topbar-address { max-width: 150px !important; font-size: 13px !important; }
    .om-topbar-arrow { display: none !important; }
    .om-topbar-time { padding: 6px 10px !important; font-size: 11px !important; }
    .mkt-header-row { padding: 12px 16px !important; }
    .mkt-logo-box { width: 40px !important; height: 40px !important; font-size: 18px !important; }
    .mkt-logo-text { font-size: 18px !important; }
    .mkt-cart { width: 42px !important; height: 42px !important; }
    .mkt-search { margin: 0 12px 12px !important; }
    .mkt-search input { font-size: 14px !important; padding: 12px 0 !important; }
}

/* ANIMAÇÕES */
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.mkt-header { animation: slideDown 0.4s ease !important; }

::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
::selection { background: rgba(16, 185, 129, 0.2); color: #047857; }
</style>

<script>
(function() {
    var h = document.querySelector('.mkt-header');
    if (h && !document.querySelector('.om-topbar')) {
        var t = document.createElement('div');
        t.className = 'om-topbar';
        t.innerHTML = '<div class="om-topbar-main"><div class="om-topbar-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><div class="om-topbar-content"><div class="om-topbar-label">Entregar em</div><div class="om-topbar-address" id="omAddrFinal">Carregando...</div></div><div class="om-topbar-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div></div><div class="om-topbar-time"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>25-35 min</div>';
        h.insertBefore(t, h.firstChild);
        fetch('/mercado/api/address.php?action=list').then(r=>r.json()).then(d=>{var el=document.getElementById('omAddrFinal');if(el&&d.current)el.textContent=d.current.address_1||'Selecionar';}).catch(()=>{});
    }
    var l = document.querySelector('.mkt-logo');
    if (l && !l.querySelector('.mkt-logo-text')) {
        var s = document.createElement('span');
        s.className = 'mkt-logo-text';
        s.textContent = 'Mercado';
        l.appendChild(s);
    }
})();
</script>
</body>
</html>
