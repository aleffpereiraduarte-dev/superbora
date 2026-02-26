<?php
session_start();
if (!isset($_SESSION['worker_id'])) { header('Location: login.php'); exit; }

$orderId = $_GET['id'] ?? 'OM-45893';
$myId = $_SESSION['worker_id'];
$myName = $_SESSION['worker_name'] ?? 'Shopper';
$myPhoto = $_SESSION['worker_photo'] ?? 'https://randomuser.me/api/portraits/women/44.jpg';
$workerRole = $_SESSION['worker_role'] ?? 'both';
$store = $_SESSION['active_order']['store'] ?? 'Pão de Açúcar';

// Dados do pedido
$orderData = [
    'id' => $orderId,
    'store' => $store,
    'items_count' => 10,
    'bags' => 3,
    'total' => 156.80,
    'earnings_shopping' => 18.00,
    'earnings_delivery' => 12.00,
    'client_name' => 'Marina Costa',
    'client_address' => 'Rua Augusta, 1200 - Apto 45',
    'client_district' => 'Consolação',
    'distance' => '2.3 km',
    'estimated_time' => '12 min'
];

// Dados do delivery
$deliveryData = [
    'id' => 847,
    'name' => 'Carlos Silva',
    'photo' => 'https://randomuser.me/api/portraits/men/32.jpg',
    'rating' => 4.9,
    'deliveries' => 1243,
    'vehicle' => 'Moto Honda CG 160',
    'plate' => 'ABC-1234',
    'phone' => '11999887766',
    'eta' => 8
];

$qrCode = strtoupper(substr(md5($orderId . $myId), 0, 8));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<title>Coleta Finalizada</title>
<style>
:root{--bg:#fff;--bg2:#f5f5f5;--border:#e0e0e0;--txt:#1a1a1a;--txt2:#666;--txt3:#999;--brand:#00a650;--brand-lt:#e8f5e9;--blue:#1a73e8;--blue-lt:#e3f2fd;--orange:#f57c00;--orange-lt:#fff3e0;--red:#d32f2f;--safe-t:env(safe-area-inset-top);--safe-b:env(safe-area-inset-bottom)}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{font-family:-apple-system,system-ui,sans-serif;background:var(--bg);color:var(--txt)}
.app{max-width:430px;margin:0 auto;min-height:100vh;display:flex;flex-direction:column}

.screen{flex:1;display:flex;flex-direction:column;padding:20px;padding-top:calc(30px + var(--safe-t));padding-bottom:calc(20px + var(--safe-b))}

.header{text-align:center;margin-bottom:20px}
.header-icon{width:70px;height:70px;background:var(--brand);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;animation:pop .4s}
@keyframes pop{0%{transform:scale(0)}100%{transform:scale(1)}}
.header-icon svg{width:35px;height:35px;color:#fff}
.header h1{font-size:20px;font-weight:700}
.header p{font-size:13px;color:var(--txt2)}

.summary{display:flex;align-items:center;gap:12px;padding:14px;background:var(--bg2);border-radius:12px;margin-bottom:16px}
.summary-icon{width:40px;height:40px;background:var(--brand);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700}
.summary-info{flex:1}
.summary-info h3{font-size:14px;font-weight:600}
.summary-info p{font-size:12px;color:var(--txt2)}
.summary-stats{display:flex;gap:10px}
.summary-stat{text-align:center}
.summary-stat span{font-size:15px;font-weight:700;color:var(--brand)}
.summary-stat small{display:block;font-size:9px;color:var(--txt3)}

/* Buscando */
.searching{background:var(--orange-lt);border-radius:14px;padding:20px;text-align:center;margin-bottom:16px}
.searching-spinner{width:40px;height:40px;border:3px solid var(--orange);border-top-color:transparent;border-radius:50%;margin:0 auto 12px;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.searching h3{font-size:15px;font-weight:600;color:var(--orange)}
.searching p{font-size:12px;color:var(--txt2)}

/* Card delivery */
.delivery-card{background:var(--bg);border:2px solid var(--brand);border-radius:14px;padding:16px;margin-bottom:16px;display:none}
.delivery-card.show{display:block;animation:slideIn .3s}
@keyframes slideIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
.delivery-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;background:var(--brand-lt);border-radius:20px;font-size:11px;font-weight:600;color:var(--brand);margin-bottom:12px}
.delivery-badge::before{content:'';width:6px;height:6px;background:var(--brand);border-radius:50%;animation:pulse 1.5s infinite}
@keyframes pulse{50%{opacity:.4}}
.delivery-profile{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.delivery-photo{width:50px;height:50px;border-radius:50%;object-fit:cover;border:2px solid var(--brand)}
.delivery-info{flex:1}
.delivery-name{font-size:15px;font-weight:600}
.delivery-vehicle{font-size:11px;color:var(--txt2)}
.delivery-rating{display:flex;align-items:center;gap:3px;margin-top:3px;font-size:12px}
.delivery-rating svg{width:12px;height:12px;fill:#ffc107}
.delivery-eta{display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;background:var(--blue-lt);border-radius:10px;margin-bottom:12px;font-size:13px;font-weight:600;color:var(--blue)}
.delivery-eta svg{width:18px;height:18px}
.delivery-actions{display:flex;gap:10px}
.delivery-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:11px;border-radius:10px;font-size:12px;font-weight:600;border:0;cursor:pointer;font-family:inherit}
.delivery-btn.chat{background:var(--brand);color:#fff}
.delivery-btn.call{background:var(--bg2);color:var(--txt)}
.delivery-btn svg{width:16px;height:16px}

/* QR */
.qr-box{background:var(--bg2);border-radius:14px;padding:20px;text-align:center;margin-bottom:16px}
.qr-title{font-size:14px;font-weight:600;margin-bottom:3px}
.qr-subtitle{font-size:11px;color:var(--txt2);margin-bottom:14px}
.qr-display{background:#fff;border-radius:10px;padding:16px;display:inline-block;margin-bottom:10px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.qr-code{font-size:28px;font-weight:700;letter-spacing:5px;color:var(--brand);font-family:monospace}
.qr-status{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;background:var(--orange-lt);border-radius:8px;font-size:11px;font-weight:600;color:var(--orange)}
.qr-status svg{width:14px;height:14px}
.qr-steps{text-align:left;margin-top:14px;padding-top:14px;border-top:1px solid var(--border)}
.qr-steps h4{font-size:12px;font-weight:600;margin-bottom:8px}
.qr-step{display:flex;gap:8px;margin-bottom:6px;font-size:11px;color:var(--txt2)}
.qr-step b{color:var(--brand)}

/* Earnings */
.earnings{background:var(--brand);border-radius:12px;padding:14px;color:#fff;text-align:center;margin-bottom:16px}
.earnings small{font-size:11px;opacity:.85}
.earnings strong{display:block;font-size:24px;font-weight:700}

/* Actions */
.actions{margin-top:auto}
.btn{width:100%;padding:14px;border:0;border-radius:12px;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;font-family:inherit;margin-bottom:10px}
.btn:active{transform:scale(.98)}
.btn.primary{background:var(--brand);color:#fff}
.btn.secondary{background:var(--bg2);color:var(--txt)}
.btn svg{width:18px;height:18px}

/* Chat */
.chat-modal{display:none;position:fixed;inset:0;background:var(--bg);z-index:200;flex-direction:column}
.chat-modal.show{display:flex}
.chat-header{display:flex;align-items:center;gap:10px;padding:calc(10px + var(--safe-t)) 14px 10px;background:var(--brand);color:#fff}
.chat-back{width:34px;height:34px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;border:0;cursor:pointer}
.chat-back svg{width:18px;height:18px;color:#fff}
.chat-photo{width:36px;height:36px;border-radius:50%;object-fit:cover}
.chat-info{flex:1}
.chat-info h4{font-size:14px;font-weight:600}
.chat-info p{font-size:10px;opacity:.8}
.chat-body{flex:1;padding:14px;overflow-y:auto;background:var(--bg2)}
.msg{max-width:75%;margin-bottom:10px}
.msg.sent{margin-left:auto}
.msg-bubble{padding:10px 12px;border-radius:14px;font-size:13px;line-height:1.4}
.msg.sent .msg-bubble{background:var(--brand);color:#fff;border-bottom-right-radius:4px}
.msg.recv .msg-bubble{background:#fff;border-bottom-left-radius:4px}
.msg-time{font-size:9px;color:var(--txt3);margin-top:3px}
.msg.sent .msg-time{text-align:right}
.chat-input-row{display:flex;gap:8px;padding:10px 14px calc(10px + var(--safe-b));background:var(--bg);border-top:1px solid var(--border)}
.chat-input{flex:1;padding:10px 14px;background:var(--bg2);border:0;border-radius:20px;font-size:13px;font-family:inherit}
.chat-send{width:40px;height:40px;background:var(--brand);border:0;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer}
.chat-send svg{width:18px;height:18px;color:#fff}

/* Toast */
.toast{position:fixed;top:calc(16px + var(--safe-t));left:14px;right:14px;max-width:402px;margin:0 auto;background:#333;color:#fff;padding:12px;border-radius:12px;display:none;align-items:center;gap:10px;z-index:250}
.toast.show{display:flex;animation:toastIn .3s}
@keyframes toastIn{from{opacity:0;transform:translateY(-20px)}to{opacity:1;transform:translateY(0)}}
.toast img{width:36px;height:36px;border-radius:50%}
.toast-text h5{font-size:13px;font-weight:600}
.toast-text p{font-size:11px;opacity:.8}

/* Transition */
.transition{position:fixed;inset:0;background:var(--brand);z-index:300;display:none;flex-direction:column;align-items:center;justify-content:center;color:#fff}
.transition.show{display:flex}
.transition-icon{width:60px;height:60px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:16px;animation:bounce 1s infinite}
@keyframes bounce{50%{transform:translateY(-8px)}}
.transition-icon svg{width:30px;height:30px}
.transition h2{font-size:18px;font-weight:700}
.transition p{font-size:13px;opacity:.9}
.transition-bar{width:160px;height:4px;background:rgba(255,255,255,.3);border-radius:2px;margin-top:24px;overflow:hidden}
.transition-bar span{display:block;height:100%;background:#fff;animation:progress 2s forwards}
@keyframes progress{to{width:100%}}
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
<div class="app">
    <div class="screen">
        <div class="header">
            <div class="header-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg></div>
            <h1>Coleta Finalizada!</h1>
            <p>Todos os itens foram coletados</p>
        </div>
        
        <div class="summary">
            <div class="summary-icon">P</div>
            <div class="summary-info">
                <h3><?= $orderData['store'] ?></h3>
                <p><?= $orderId ?></p>
            </div>
            <div class="summary-stats">
                <div class="summary-stat"><span><?= $orderData['items_count'] ?></span><small>itens</small></div>
                <div class="summary-stat"><span><?= $orderData['bags'] ?></span><small>sacolas</small></div>
            </div>
        </div>
        
        <?php if ($workerRole === 'both'): ?>
        <!-- SHOPPER + DELIVERY -->
        <div class="delivery-card show" style="border-color:var(--blue)">
            <div class="delivery-badge" style="background:var(--blue-lt);color:var(--blue)">Você vai entregar</div>
            <div style="display:flex;align-items:center;gap:10px;padding:10px;background:var(--blue-lt);border-radius:10px">
                <svg width="20" height="20" fill="none" stroke="var(--blue)" stroke-width="2"><path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="3"/></svg>
                <div style="flex:1">
                    <div style="font-size:13px;font-weight:600"><?= $orderData['client_name'] ?></div>
                    <div style="font-size:11px;color:var(--txt2)"><?= $orderData['client_address'] ?></div>
                </div>
                <div style="text-align:right">
                    <div style="font-size:13px;font-weight:700;color:var(--blue)"><?= $orderData['distance'] ?></div>
                    <div style="font-size:10px;color:var(--txt2)">~<?= $orderData['estimated_time'] ?></div>
                </div>
            </div>
        </div>
        <div class="earnings">
            <small>Ganho total</small>
            <strong>R$ <?= number_format($orderData['earnings_shopping'] + $orderData['earnings_delivery'], 2, ',', '.') ?></strong>
        </div>
        <div class="actions">
            <button class="btn primary" onclick="startDelivery()"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/></svg>Iniciar Entrega</button>
            <button class="btn secondary" onclick="openMaps()"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>Ver Rota</button>
        </div>
        
        <?php else: ?>
        <!-- SÓ SHOPPER -->
        <div class="searching" id="searching">
            <div class="searching-spinner"></div>
            <h3>Buscando entregador...</h3>
            <p>Procurando o mais próximo do mercado</p>
        </div>
        
        <div class="delivery-card" id="delivery-card">
            <div class="delivery-badge" id="delivery-badge">A caminho do mercado</div>
            <div class="delivery-profile">
                <img class="delivery-photo" src="<?= $deliveryData['photo'] ?>">
                <div class="delivery-info">
                    <div class="delivery-name"><?= $deliveryData['name'] ?></div>
                    <div class="delivery-vehicle"><?= $deliveryData['vehicle'] ?> • <?= $deliveryData['plate'] ?></div>
                    <div class="delivery-rating"><svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg><?= $deliveryData['rating'] ?> <span style="color:var(--txt3)">• <?= number_format($deliveryData['deliveries']) ?> entregas</span></div>
                </div>
            </div>
            <div class="delivery-eta" id="delivery-eta">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Chega em <strong id="eta"><?= $deliveryData['eta'] ?></strong> min
            </div>
            <div class="delivery-actions">
                <button class="delivery-btn chat" onclick="openChat()"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>Conversar</button>
                <button class="delivery-btn call" onclick="location.href='tel:<?= $deliveryData['phone'] ?>'"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>Ligar</button>
            </div>
        </div>
        
        <div class="qr-box">
            <div class="qr-title">Código de Entrega</div>
            <div class="qr-subtitle">Mostre para o entregador escanear</div>
            <div class="qr-display"><div class="qr-code"><?= $qrCode ?></div></div>
            <div class="qr-status" id="qr-status"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Aguardando...</div>
            <div class="qr-steps">
                <h4>Como funciona:</h4>
                <div class="qr-step"><b>1.</b> Deixe sacolas no ponto de coleta</div>
                <div class="qr-step"><b>2.</b> Entregador escaneia o código</div>
                <div class="qr-step"><b>3.</b> Seu pagamento é liberado! 💰</div>
            </div>
        </div>
        
        <div class="earnings">
            <small>Seu ganho (coleta)</small>
            <strong>R$ <?= number_format($orderData['earnings_shopping'], 2, ',', '.') ?></strong>
        </div>
        
        <div class="actions">
            <button class="btn primary" onclick="confirmHandoff()"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Confirmar Entrega no Ponto</button>
            <button class="btn secondary" onclick="location.href='ajuda.php'"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536"/></svg>Preciso de Ajuda</button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
    <img id="toast-img" src="">
    <div class="toast-text">
        <h5 id="toast-title"></h5>
        <p id="toast-subtitle"></p>
    </div>
</div>

<!-- Chat -->
<div class="chat-modal" id="chat-modal">
    <div class="chat-header">
        <button class="chat-back" onclick="closeChat()"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></button>
        <img class="chat-photo" src="<?= $deliveryData['photo'] ?>">
        <div class="chat-info">
            <h4><?= $deliveryData['name'] ?></h4>
            <p>Online</p>
        </div>
    </div>
    <div class="chat-body" id="chat-body"></div>
    <div class="chat-input-row">
        <input type="text" class="chat-input" id="chat-input" placeholder="Digite..." onkeypress="if(event.key==='Enter')sendMsg()">
        <button class="chat-send" onclick="sendMsg()"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg></button>
    </div>
</div>

<!-- Transition -->
<div class="transition" id="transition">
    <div class="transition-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div>
    <h2>Preparando Entrega</h2>
    <p>Calculando melhor rota...</p>
    <div class="transition-bar"><span></span></div>
</div>

<script>
const orderId = '<?= $orderId ?>';
const qrCode = '<?= $qrCode ?>';
const deliveryData = {id:<?= $deliveryData['id'] ?>,name:'<?= $deliveryData['name'] ?>',photo:'<?= $deliveryData['photo'] ?>',eta:<?= $deliveryData['eta'] ?>};
let msgs = [];

<?php if ($workerRole !== 'both'): ?>
// Simular delivery aceitar
setTimeout(() => {
    toast(deliveryData.photo, deliveryData.name + ' aceitou!', 'Chega em ' + deliveryData.eta + ' min');
    document.getElementById('searching').style.display = 'none';
    document.getElementById('delivery-card').classList.add('show');
    if(navigator.vibrate)navigator.vibrate([100,50,100]);
    
    // Countdown demo
    let e = deliveryData.eta;
    const i = setInterval(() => {
        e--;
        if(e > 0) {
            document.getElementById('eta').textContent = e;
        } else {
            clearInterval(i);
            document.getElementById('delivery-badge').textContent = 'Chegou no mercado!';
            document.getElementById('delivery-eta').innerHTML = '<svg fill="none" viewBox="0 0 24 24" stroke="var(--brand)"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span style="color:var(--brand);font-weight:600">Entregador chegou!</span>';
            toast(deliveryData.photo, deliveryData.name + ' chegou!', 'Entregue as sacolas');
            if(navigator.vibrate)navigator.vibrate([200,100,200]);
        }
    }, 3000); // demo: 3s = 1min
}, 3000);

// Check QR escaneado
setInterval(() => {
    fetch('api/check-handoff.php?code=' + qrCode).then(r => r.json()).then(d => {
        if(d.collected) {
            document.getElementById('qr-status').innerHTML = '<svg fill="none" viewBox="0 0 24 24" stroke="var(--brand)"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Escaneado!';
            document.getElementById('qr-status').style.background = 'var(--brand-lt)';
            document.getElementById('qr-status').style.color = 'var(--brand)';
            toast(deliveryData.photo, 'Pedido coletado!', 'Pagamento liberado');
            setTimeout(() => {
                alert('🎉 Pronto!\n\nPagamento de R$ <?= number_format($orderData['earnings_shopping'], 2, ',', '.') ?> liberado!');
                location.href = 'app.php';
            }, 2000);
        }
    }).catch(() => {});
}, 5000);
<?php endif; ?>

function toast(img, title, sub) {
    document.getElementById('toast-img').src = img;
    document.getElementById('toast-title').textContent = title;
    document.getElementById('toast-subtitle').textContent = sub;
    document.getElementById('toast').classList.add('show');
    setTimeout(() => document.getElementById('toast').classList.remove('show'), 4000);
}

function openChat() { document.getElementById('chat-modal').classList.add('show'); render(); }
function closeChat() { document.getElementById('chat-modal').classList.remove('show'); }

function sendMsg() {
    const input = document.getElementById('chat-input');
    const txt = input.value.trim();
    if(!txt) return;
    
    msgs.push({sent:true, text:txt, time:new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})});
    input.value = '';
    render();
    
    // Salvar no servidor
    fetch('api/save-chat.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({order_id:orderId, message:txt, chat_type:'shopper_delivery'})
    }).catch(() => {});
    
    // Resposta simulada
    setTimeout(() => {
        const resps = ['Ok!','Entendi! 👍','Combinado!','Perfeito!','Já estou chegando!'];
        msgs.push({sent:false, text:resps[Math.floor(Math.random()*resps.length)], time:new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})});
        render();
        if(navigator.vibrate)navigator.vibrate(50);
    }, 1500);
}

function render() {
    document.getElementById('chat-body').innerHTML = msgs.map(m => `<div class="msg ${m.sent?'sent':'recv'}"><div class="msg-bubble">${m.text}</div><div class="msg-time">${m.time}</div></div>`).join('');
    document.getElementById('chat-body').scrollTop = 99999;
}

function startDelivery() {
    document.getElementById('transition').classList.add('show');
    if(navigator.vibrate)navigator.vibrate([100,50,100]);
    setTimeout(() => location.href = 'delivery.php?id='+orderId+'&from=shopping', 2500);
}

function openMaps() {
    window.open('https://maps.google.com/?daddr=<?= urlencode($orderData['client_address']) ?>', '_blank');
}

function confirmHandoff() {
    if(confirm('Confirma que deixou as sacolas no ponto?')) {
        alert('✅ Confirmado!\n\nAguarde o entregador escanear o código.');
    }
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
