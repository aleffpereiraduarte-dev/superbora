<?php
/**
 * 📍 TRACKING COM MAPA - VERSÃO CORRIGIDA
 */

$oc_root = dirname(__DIR__);
require_once($oc_root . '/config.php');

$pdo = new PDO(
    "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
    DB_USERNAME, DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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

// Itens
$stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ?");
$stmt->execute([$order_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mensagens
$stmt = $pdo->prepare("SELECT * FROM om_market_chat WHERE order_id = ? ORDER BY date_added DESC LIMIT 20");
$stmt->execute([$order_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Delivery
$delivery = null;
if ($order['delivery_id']) {
    $stmt = $pdo->prepare("SELECT * FROM om_market_deliveries WHERE delivery_id = ?");
    $stmt->execute([$order['delivery_id']]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Buscar substituições pendentes
$substitutions = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM om_market_substitutions WHERE order_id = ? AND status = 'pending' ORDER BY created_at DESC");
    $stmt->execute([$order_id]);
    $substitutions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Status
$status_config = [
    'pending' => ['icon' => '⏳', 'label' => 'Aguardando', 'color' => 'var(--warning)', 'step' => 1],
    'confirmed' => ['icon' => '✅', 'label' => 'Confirmado', 'color' => 'var(--primary)', 'step' => 2],
    'shopping' => ['icon' => '🛒', 'label' => 'Comprando', 'color' => 'var(--info)', 'step' => 3],
    'purchased' => ['icon' => '📦', 'label' => 'Pronto', 'color' => '#8b5cf6', 'step' => 4],
    'delivering' => ['icon' => '🚴', 'label' => 'A caminho', 'color' => '#f97316', 'step' => 5],
    'delivered' => ['icon' => '🎉', 'label' => 'Entregue', 'color' => 'var(--primary)', 'step' => 6]
];

$current_status = $status_config[$order['status']] ?? $status_config['pending'];
$is_delivering = ($order['status'] === 'delivering');
$is_delivered = ($order['status'] === 'delivered');

// Coordenadas
$dest_lat = !empty($order['shipping_lat']) ? (float)$order['shipping_lat'] : -23.5505;
$dest_lng = !empty($order['shipping_lng']) ? (float)$order['shipping_lng'] : -46.6333;
$delivery_lat = !empty($delivery['lat']) ? (float)$delivery['lat'] : ($dest_lat + 0.009);
$delivery_lng = !empty($delivery['lng']) ? (float)$delivery['lng'] : $dest_lng;

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>📍 Pedido #<?= $order['order_number'] ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; }
        .container { max-width: 500px; margin: 0 auto; padding-bottom: 100px; }
        
        .header { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 16px 20px; position: sticky; top: 0; z-index: 100; border-bottom: 1px solid #334155; }
        .header-top { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .back-btn { width: 40px; height: 40px; border-radius: 10px; background: #334155; border: none; color: #e2e8f0; font-size: 18px; cursor: pointer; }
        .order-info h1 { font-size: 1.1rem; font-weight: 700; }
        .order-number { font-size: 13px; color: #64748b; }
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 600; background: <?= $current_status['color'] ?>20; color: <?= $current_status['color'] ?>; border: 1px solid <?= $current_status['color'] ?>40; }
        
        .progress-container { padding: 20px; background: #1e293b; }
        .progress-steps { display: flex; justify-content: space-between; position: relative; }
        .progress-line { position: absolute; top: 16px; left: 24px; right: 24px; height: 3px; background: #334155; z-index: 1; }
        .progress-line-fill { height: 100%; background: linear-gradient(90deg, var(--primary), var(--info)); width: <?= min(100, ($current_status['step'] - 1) * 20) ?>%; }
        .step { display: flex; flex-direction: column; align-items: center; position: relative; z-index: 2; }
        .step-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; background: #334155; border: 3px solid #1e293b; }
        .step.active .step-icon { background: var(--primary); transform: scale(1.1); box-shadow: 0 0 20px var(--primary)50; }
        .step.done .step-icon { background: var(--primary); }
        .step-label { font-size: 10px; color: #64748b; margin-top: 6px; }
        .step.active .step-label, .step.done .step-label { color: #e2e8f0; }
        
        .map-section { position: relative; height: 300px; background: #1e293b; }
        #map { width: 100%; height: 100%; }
        .map-overlay { position: absolute; bottom: 16px; left: 16px; right: 16px; background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(10px); border-radius: 12px; padding: 12px 16px; display: flex; align-items: center; gap: 12px; z-index: 1000; }
        .delivery-avatar { width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, #f97316, #ea580c); display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .delivery-info h3 { font-size: 14px; font-weight: 600; }
        .delivery-status { font-size: 12px; color: var(--primary); }
        .eta-badge { margin-left: auto; background: var(--primary)20; color: var(--primary); padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; }
        
        .delivery-code-card { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); margin: 16px; border-radius: 16px; padding: 20px; text-align: center; }
        .delivery-code-label { font-size: 13px; opacity: 0.9; margin-bottom: 8px; }
        .delivery-code { font-size: 32px; font-weight: 800; letter-spacing: 2px; font-family: 'Courier New', monospace; }
        .delivery-code-hint { font-size: 12px; opacity: 0.8; margin-top: 8px; }
        
        .card { background: #1e293b; margin: 16px; border-radius: 16px; overflow: hidden; }
        .card-header { padding: 16px; border-bottom: 1px solid #334155; display: flex; align-items: center; gap: 10px; }
        .card-header h2 { font-size: 15px; font-weight: 600; }
        .card-body { padding: 16px; }
        
        .no-map { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 150px; color: #64748b; text-align: center; padding: 20px; background: #1e293b; margin: 16px; border-radius: 16px; }
        .no-map .icon { font-size: 48px; margin-bottom: 12px; }
        
        .chat-messages { max-height: 350px; overflow-y: auto; padding: 8px 0; scroll-behavior: smooth; }
        .chat-messages::-webkit-scrollbar { width: 4px; }
        .chat-messages::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        
        .chat-bubble-wrapper { display: flex; margin-bottom: 12px; }
        .chat-bubble-wrapper.customer { justify-content: flex-end; }
        .chat-bubble-wrapper.system { justify-content: center; }
        
        .chat-bubble { max-width: 80%; padding: 10px 14px; border-radius: 18px; position: relative; }
        .chat-bubble-wrapper.customer .chat-bubble { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border-bottom-right-radius: 4px; }
        .chat-bubble-wrapper.shopper .chat-bubble, .chat-bubble-wrapper.delivery .chat-bubble { background: #334155; color: #e2e8f0; border-bottom-left-radius: 4px; }
        .chat-bubble-wrapper.system .chat-bubble { background: #1e293b; border: 1px solid #334155; color: #94a3b8; font-size: 12px; padding: 8px 16px; border-radius: 20px; }
        
        .chat-bubble-sender { font-size: 11px; font-weight: 600; margin-bottom: 4px; opacity: 0.8; display: flex; align-items: center; gap: 6px; }
        .chat-bubble-wrapper.customer .chat-bubble-sender { display: none; }
        .sender-avatar { width: 18px; height: 18px; border-radius: 50%; background: #475569; display: flex; align-items: center; justify-content: center; font-size: 10px; }
        .chat-bubble-wrapper.shopper .sender-avatar { background: var(--info); }
        .chat-bubble-wrapper.delivery .sender-avatar { background: #f97316; }
        
        .chat-bubble-text { font-size: 14px; line-height: 1.45; word-wrap: break-word; white-space: pre-wrap; }
        
        .chat-bubble-meta { display: flex; align-items: center; justify-content: flex-end; gap: 4px; margin-top: 4px; }
        .chat-bubble-time { font-size: 10px; opacity: 0.6; }
        .chat-bubble-wrapper.customer .chat-bubble-time { color: rgba(255,255,255,0.7); }
        
        .chat-date-divider { text-align: center; margin: 16px 0; }
        .chat-date-divider span { background: #0f172a; color: #64748b; font-size: 11px; padding: 4px 12px; border-radius: 10px; }
        
        .chat-typing { display: flex; align-items: center; gap: 8px; padding: 8px 0; color: #64748b; font-size: 13px; }
        .typing-dots { display: flex; gap: 4px; }
        .typing-dots span { width: 6px; height: 6px; background: #64748b; border-radius: 50%; animation: typing 1.4s infinite; }
        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typing { 0%, 60%, 100% { transform: translateY(0); } 30% { transform: translateY(-4px); } }
        
        .chat-empty { text-align: center; padding: 40px 20px; color: #64748b; }
        .chat-empty-icon { font-size: 48px; margin-bottom: 12px; opacity: 0.5; }
        .chat-empty-text { font-size: 14px; }
        
        .chat-input-container { position: fixed; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, #1e293b 90%, transparent); padding: 16px; padding-top: 24px; display: flex; gap: 10px; max-width: 500px; margin: 0 auto; }
        .chat-input-wrapper { flex: 1; position: relative; }
        .chat-input { width: 100%; padding: 14px 48px 14px 18px; border: 1px solid #334155; border-radius: 24px; background: #0f172a; color: #e2e8f0; font-size: 15px; outline: none; transition: all 0.2s; }
        .chat-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }
        .chat-input::placeholder { color: #64748b; }
        .chat-emoji-btn { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; font-size: 20px; cursor: pointer; opacity: 0.6; transition: opacity 0.2s; }
        .chat-emoji-btn:hover { opacity: 1; }
        .chat-send { width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border: none; color: white; font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .chat-send:hover { transform: scale(1.05); box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4); }
        .chat-send:active { transform: scale(0.95); }
        .chat-send svg { width: 20px; height: 20px; fill: currentColor; }
        
        .item { display: flex; gap: 12px; padding: 10px 0; border-bottom: 1px solid #33415520; }
        .item:last-child { border: none; }
        .item-qty { width: 28px; height: 28px; border-radius: 6px; background: #334155; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; }
        .item-name { flex: 1; font-size: 14px; }
        .item-price { font-size: 14px; font-weight: 600; color: var(--primary); }
        
        /* Substituições */
        .substitution-item { background: #0f172a; border-radius: 12px; padding: 16px; margin-bottom: 12px; }
        .substitution-item:last-child { margin-bottom: 0; }
        .sub-header { margin-bottom: 12px; }
        .sub-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; background: #f9731630; color: #f97316; }
        .sub-products { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .sub-original, .sub-suggested { flex: 1; }
        .sub-arrow { color: #64748b; font-size: 20px; }
        .sub-label { display: block; font-size: 11px; color: #64748b; margin-bottom: 4px; }
        .sub-name { display: block; font-size: 14px; font-weight: 600; margin-bottom: 2px; }
        .sub-price { font-size: 13px; color: #94a3b8; }
        .sub-diff { display: inline-block; margin-left: 8px; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .sub-diff.price-up { background: var(--error)20; color: var(--error); }
        .sub-diff.price-down { background: var(--primary)20; color: var(--primary); }
        .sub-reason { font-size: 12px; color: #94a3b8; margin-bottom: 12px; padding: 8px; background: #1e293b; border-radius: 6px; }
        .sub-actions { display: flex; gap: 10px; }
        .btn-approve, .btn-reject { flex: 1; padding: 12px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-approve { background: var(--primary); color: white; }
        .btn-reject { background: #334155; color: #e2e8f0; }
        .btn-approve:hover { background: var(--primary-dark); }
        .btn-reject:hover { background: #475569; }
    </style>

<style>

/* 🎨 OneMundo Design System v2.0 - Injected Styles */
:root {
    --primary: var(--primary);
    --primary-dark: var(--primary-dark);
    --primary-light: var(--primary-light);
    --primary-50: #ecfdf5;
    --primary-100: #d1fae5;
    --primary-glow: rgba(16, 185, 129, 0.15);
    --accent: #8b5cf6;
    --success: #22c55e;
    --warning: var(--warning);
    --error: var(--error);
    --info: var(--info);
    --white: #ffffff;
    --gray-50: #f8fafc;
    --gray-100: #f1f5f9;
    --gray-200: #e2e8f0;
    --gray-300: #cbd5e1;
    --gray-400: #94a3b8;
    --gray-500: #64748b;
    --gray-600: #475569;
    --gray-700: #334155;
    --gray-800: #1e293b;
    --gray-900: #0f172a;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --radius-xl: 20px;
    --radius-full: 9999px;
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
    --transition-fast: 150ms ease;
    --transition-base: 200ms ease;
}

/* Melhorias globais */
body {
    font-family: "Inter", -apple-system, BlinkMacSystemFont, sans-serif !important;
    -webkit-font-smoothing: antialiased;
}

/* Headers melhorados */
.header, [class*="header"] {
    background: rgba(255,255,255,0.9) !important;
    backdrop-filter: blur(20px) saturate(180%);
    -webkit-backdrop-filter: blur(20px) saturate(180%);
    border-bottom: 1px solid rgba(0,0,0,0.05) !important;
    box-shadow: none !important;
}

/* Botões melhorados */
button, .btn, [class*="btn-"] {
    transition: all var(--transition-base) !important;
    border-radius: var(--radius-md) !important;
}

button:hover, .btn:hover, [class*="btn-"]:hover {
    transform: translateY(-2px);
}

/* Botões primários */
.btn-primary, .btn-checkout, [class*="btn-green"], [class*="btn-success"] {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark)) !important;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35) !important;
    border: none !important;
}

.btn-primary:hover, .btn-checkout:hover {
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
}

/* Cards melhorados */
.card, [class*="card"], .item, [class*="-item"] {
    border-radius: var(--radius-lg) !important;
    box-shadow: var(--shadow-md) !important;
    transition: all var(--transition-base) !important;
    border: none !important;
}

.card:hover, [class*="card"]:hover {
    box-shadow: var(--shadow-lg) !important;
    transform: translateY(-4px);
}

/* Inputs melhorados */
input, textarea, select {
    border-radius: var(--radius-md) !important;
    border: 2px solid var(--gray-200) !important;
    transition: all var(--transition-base) !important;
}

input:focus, textarea:focus, select:focus {
    border-color: var(--primary) !important;
    box-shadow: 0 0 0 4px var(--primary-glow) !important;
    outline: none !important;
}

/* Badges melhorados */
.badge, [class*="badge"] {
    border-radius: var(--radius-full) !important;
    font-weight: 700 !important;
    padding: 6px 12px !important;
}

/* Bottom bar melhorado */
.bottom-bar, [class*="bottom-bar"], [class*="bottombar"] {
    background: var(--white) !important;
    border-top: 1px solid var(--gray-200) !important;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.08) !important;
    border-radius: 24px 24px 0 0 !important;
}

/* Preços */
[class*="price"], [class*="preco"], [class*="valor"] {
    font-weight: 800 !important;
}

/* Links */
a {
    transition: color var(--transition-fast) !important;
}

/* Imagens de produto */
.item-img img, .product-img img, [class*="produto"] img {
    border-radius: var(--radius-md) !important;
    transition: transform var(--transition-base) !important;
}

.item-img:hover img, .product-img:hover img {
    transform: scale(1.05);
}

/* Animações suaves */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.animate-fade { animation: fadeIn 0.3s ease forwards; }
.animate-up { animation: slideUp 0.4s ease forwards; }

/* Scrollbar bonita */
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: var(--gray-100); }
::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: var(--gray-400); }

/* Selection */
::selection {
    background: var(--primary-100);
    color: var(--primary-dark);
}

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
<link rel="stylesheet" href="/mercado/assets/css/mercado-premium.css">
</head>
<body>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="header-top">
            <button class="back-btn" onclick="history.back()">←</button>
            <div class="order-info">
                <h1>Acompanhar Pedido</h1>
                <div class="order-number">#<?= htmlspecialchars($order['order_number']) ?></div>
            </div>
        </div>
        <div class="status-badge"><?= $current_status['icon'] ?> <?= $current_status['label'] ?></div>
    </div>
    
    <!-- Progress -->
    <div class="progress-container">
        <div class="progress-steps">
            <div class="progress-line"><div class="progress-line-fill"></div></div>
            <?php
            $steps = [
                ['icon' => '⏳', 'label' => 'Pedido'],
                ['icon' => '✅', 'label' => 'Aceito'],
                ['icon' => '🛒', 'label' => 'Comprando'],
                ['icon' => '📦', 'label' => 'Pronto'],
                ['icon' => '🚴', 'label' => 'Entrega'],
                ['icon' => '🎉', 'label' => 'Entregue']
            ];
            foreach ($steps as $i => $step):
                $stepNum = $i + 1;
                $class = '';
                if ($stepNum < $current_status['step']) $class = 'done';
                elseif ($stepNum == $current_status['step']) $class = 'active';
            ?>
            <div class="step <?= $class ?>">
                <div class="step-icon"><?= $step['icon'] ?></div>
                <div class="step-label"><?= $step['label'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <?php if ($is_delivering): ?>
    <!-- MAPA -->
    <div class="map-section">
        <div id="map"></div>
        <div class="map-overlay">
            <div class="delivery-avatar">🚴</div>
            <div class="delivery-info">
                <h3><?= htmlspecialchars($order['delivery_name'] ?: 'Entregador') ?></h3>
                <div class="delivery-status">● A caminho</div>
            </div>
            <div class="eta-badge" id="eta">~10 min</div>
        </div>
    </div>
    
    <?php if ($order['delivery_code']): ?>
    <div class="delivery-code-card">
        <div class="delivery-code-label">🔑 Código de Entrega</div>
        <div class="delivery-code"><?= htmlspecialchars($order['delivery_code']) ?></div>
        <div class="delivery-code-hint">Fale esse código para o entregador</div>
    </div>
    <?php endif; ?>
    
    <?php elseif (!$is_delivered): ?>
    <div class="no-map">
        <div class="icon"><?= $current_status['icon'] ?></div>
        <div>
            <?php if ($order['status'] === 'pending'): ?>
                Aguardando shopper aceitar...
            <?php elseif ($order['status'] === 'confirmed'): ?>
                <?= htmlspecialchars($order['shopper_name']) ?> vai começar!
            <?php elseif ($order['status'] === 'shopping'): ?>
                <?= htmlspecialchars($order['shopper_name']) ?> está comprando!
            <?php elseif ($order['status'] === 'purchased'): ?>
                Aguardando entregador...
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($substitutions)): ?>
    <!-- Substituições Pendentes -->
    <div class="card" style="border: 2px solid #f97316;">
        <div class="card-header" style="background: #f9731620;">
            <span>🔄</span>
            <h2>Substituições Pendentes (<?= count($substitutions) ?>)</h2>
        </div>
        <div class="card-body">
            <?php foreach ($substitutions as $sub): ?>
            <div class="substitution-item" data-id="<?= $sub['substitution_id'] ?>">
                <div class="sub-header">
                    <span class="sub-badge">Aguardando aprovação</span>
                </div>
                <div class="sub-products">
                    <div class="sub-original">
                        <span class="sub-label">❌ Indisponível</span>
                        <span class="sub-name"><?= htmlspecialchars($sub['original_name']) ?></span>
                        <span class="sub-price">R$ <?= number_format($sub['original_price'], 2, ',', '.') ?></span>
                    </div>
                    <div class="sub-arrow">→</div>
                    <div class="sub-suggested">
                        <span class="sub-label">✅ Sugestão</span>
                        <span class="sub-name"><?= htmlspecialchars($sub['suggested_name']) ?></span>
                        <span class="sub-price">R$ <?= number_format($sub['suggested_price'], 2, ',', '.') ?></span>
                        <?php 
                        $diff = $sub['suggested_price'] - $sub['original_price'];
                        if ($diff != 0):
                            $diff_class = $diff > 0 ? 'price-up' : 'price-down';
                            $diff_text = $diff > 0 ? '+' : '';
                        ?>
                        <span class="sub-diff <?= $diff_class ?>"><?= $diff_text ?>R$ <?= number_format($diff, 2, ',', '.') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($sub['reason']): ?>
                <div class="sub-reason">📝 <?= htmlspecialchars($sub['reason']) ?></div>
                <?php endif; ?>
                <div class="sub-actions">
                    <button class="btn-approve" onclick="responderSubstituicao(<?= $sub['substitution_id'] ?>, 'approve')">
                        ✅ Aprovar
                    </button>
                    <button class="btn-reject" onclick="responderSubstituicao(<?= $sub['substitution_id'] ?>, 'reject')">
                        ❌ Recusar
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Chat -->
    <div class="card">
        <div class="card-header">
            <span>💬</span>
            <h2>Chat</h2>
            <?php if ($order['chat_enabled'] && !$order['chat_expired']): ?>
            <span style="margin-left:auto;font-size:11px;color:var(--primary);">● Online</span>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding: 12px;">
            <div class="chat-messages" id="chat-messages">
                <?php if (empty($messages)): ?>
                <div class="chat-empty">
                    <div class="chat-empty-icon">💬</div>
                    <div class="chat-empty-text">Nenhuma mensagem ainda<br><small style="color:#475569;">Envie uma mensagem para iniciar</small></div>
                </div>
                <?php else: ?>
                <?php 
                $lastDate = '';
                foreach (array_reverse($messages) as $msg): 
                    $msgDate = date('d/m/Y', strtotime($msg['date_added']));
                    $isToday = ($msgDate === date('d/m/Y'));
                    $isYesterday = ($msgDate === date('d/m/Y', strtotime('-1 day')));
                    
                    if ($msgDate !== $lastDate):
                        $lastDate = $msgDate;
                        $dateLabel = $isToday ? 'Hoje' : ($isYesterday ? 'Ontem' : $msgDate);
                ?>
                <div class="chat-date-divider"><span><?= $dateLabel ?></span></div>
                <?php endif; ?>
                
                <div class="chat-bubble-wrapper <?= $msg['sender_type'] ?>">
                    <div class="chat-bubble">
                        <?php if ($msg['sender_type'] !== 'customer' && $msg['sender_type'] !== 'system'): ?>
                        <div class="chat-bubble-sender">
                            <span class="sender-avatar">
                                <?= $msg['sender_type'] === 'shopper' ? '🛒' : '🚴' ?>
                            </span>
                            <?= htmlspecialchars($msg['sender_name']) ?>
                        </div>
                        <?php endif; ?>
                        <div class="chat-bubble-text"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                        <div class="chat-bubble-meta">
                            <span class="chat-bubble-time"><?= date('H:i', strtotime($msg['date_added'])) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Itens -->
    <div class="card">
        <div class="card-header">
            <span>🛒</span>
            <h2><?= count($items) ?> itens</h2>
        </div>
        <div class="card-body">
            <?php foreach ($items as $item): ?>
            <div class="item">
                <div class="item-qty"><?= (int)$item['quantity'] ?>x</div>
                <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                <div class="item-price">R$ <?= number_format($item['total'], 2, ',', '.') ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($order['chat_enabled'] && !$order['chat_expired']): ?>
<div class="chat-input-container">
    <div class="chat-input-wrapper">
        <input type="text" class="chat-input" id="chat-input" placeholder="Digite uma mensagem...">
    </div>
    <button class="chat-send" onclick="enviarMensagem()">
        <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
    </button>
</div>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const ORDER_ID = <?= $order_id ?>;
const DEST_LAT = <?= $dest_lat ?>;
const DEST_LNG = <?= $dest_lng ?>;
const DELIVERY_LAT = <?= $delivery_lat ?>;
const DELIVERY_LNG = <?= $delivery_lng ?>;
const IS_DELIVERING = <?= $is_delivering ? 'true' : 'false' ?>;

// Chat
async function enviarMensagem() {
    const input = document.getElementById('chat-input');
    const msg = input.value.trim();
    if (!msg) return;
    
    // Limpar input
    input.value = '';
    
    // Adicionar bolha imediatamente (otimistic UI)
    const container = document.getElementById('chat-messages');
    const emptyMsg = container.querySelector('.chat-empty');
    if (emptyMsg) emptyMsg.remove();
    
    const time = new Date().toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble-wrapper customer';
    bubble.innerHTML = `
        <div class="chat-bubble">
            <div class="chat-bubble-text">${msg.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')}</div>
            <div class="chat-bubble-meta">
                <span class="chat-bubble-time">${time}</span>
            </div>
        </div>
    `;
    container.appendChild(bubble);
    container.scrollTop = container.scrollHeight;
    
    // Enviar pro servidor
    try {
        await fetch('/mercado/api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: ORDER_ID, sender_type: 'customer', sender_id: <?= $order['customer_id'] ?>, message: msg })
        });
    } catch (e) {
        console.error(e);
    }
}

// Enter para enviar
document.getElementById('chat-input')?.addEventListener('keypress', e => { 
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        enviarMensagem(); 
    }
});

// Scroll para baixo no load
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('chat-messages');
    if (container) container.scrollTop = container.scrollHeight;
});

// Responder substituição
async function responderSubstituicao(id, response) {
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = '...';
    
    try {
        const res = await fetch('/mercado/api/substituicao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'responder',
                substitution_id: id,
                response: response
            })
        });
        
        const data = await res.json();
        
        if (data.success) {
            // Remover card da substituição
            const item = document.querySelector(`[data-id="${id}"]`);
            if (item) {
                item.style.opacity = '0.5';
                item.innerHTML = `<div style="text-align:center;padding:20px;color:${response === 'approve' ? 'var(--primary)' : '#94a3b8'}">
                    ${response === 'approve' ? '✅ Aprovado!' : '❌ Recusado'}
                </div>`;
                setTimeout(() => item.remove(), 2000);
            }
        } else {
            alert(data.error || 'Erro ao responder');
            btn.disabled = false;
            btn.textContent = response === 'approve' ? '✅ Aprovar' : '❌ Recusar';
        }
    } catch (e) {
        alert('Erro de conexão');
        btn.disabled = false;
        btn.textContent = response === 'approve' ? '✅ Aprovar' : '❌ Recusar';
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// POLLING EM TEMPO REAL
// ═══════════════════════════════════════════════════════════════════════════════

let deliveryMarker = null;
let routeLine = null;
let mapInstance = null;

// Guardar referência do mapa
if (IS_DELIVERING && document.getElementById('map')) {
    // Recriar mapa com referência global
    document.getElementById('map').innerHTML = '';
    
    mapInstance = L.map('map', { zoomControl: false, attributionControl: false }).setView([DELIVERY_LAT, DELIVERY_LNG], 14);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 19 }).addTo(mapInstance);
    
    // Dest marker (fixo)
    const destIcon = L.divIcon({
        className: 'dest-marker',
        html: '<div style="width:40px;height:40px;border-radius:var(--radius-full);background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 4px 15px rgba(16,185,129,0.5);">🏠</div>',
        iconSize: [40, 40],
        iconAnchor: [20, 20]
    });
    L.marker([DEST_LAT, DEST_LNG], { icon: destIcon }).addTo(mapInstance);
    
    // Delivery marker (vai mover)
    const deliveryIcon = L.divIcon({
        className: 'delivery-marker',
        html: '<div style="width:40px;height:40px;border-radius:var(--radius-full);background:#f97316;display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 4px 15px rgba(249,115,22,0.5);">🚴</div>',
        iconSize: [40, 40],
        iconAnchor: [20, 20]
    });
    deliveryMarker = L.marker([DELIVERY_LAT, DELIVERY_LNG], { icon: deliveryIcon }).addTo(mapInstance);
    
    // Linha
    routeLine = L.polyline([[DELIVERY_LAT, DELIVERY_LNG], [DEST_LAT, DEST_LNG]], {
        color: '#f97316', weight: 3, opacity: 0.7, dashArray: '10, 10'
    }).addTo(mapInstance);
    
    // Fit
    mapInstance.fitBounds([[DELIVERY_LAT, DELIVERY_LNG], [DEST_LAT, DEST_LNG]], { padding: [50, 50] });
    
    // ETA inicial
    updateETA(DELIVERY_LAT, DELIVERY_LNG);
}

function updateETA(lat, lng) {
    if (!mapInstance) return;
    const dist = mapInstance.distance([lat, lng], [DEST_LAT, DEST_LNG]);
    const eta = Math.max(1, Math.ceil(dist / 300)); // ~18km/h
    document.getElementById('eta').textContent = '~' + eta + ' min';
    
    // Se muito perto, mostrar "Chegando!"
    if (dist < 100) {
        document.getElementById('eta').textContent = '🎉 Chegando!';
        document.getElementById('eta').style.background = 'var(--primary)';
        document.getElementById('eta').style.color = 'white';
    }
}

function updateDeliveryPosition(lat, lng) {
    if (!deliveryMarker || !mapInstance) return;
    
    // Animar movimento
    deliveryMarker.setLatLng([lat, lng]);
    
    // Atualizar linha
    if (routeLine) {
        routeLine.setLatLngs([[lat, lng], [DEST_LAT, DEST_LNG]]);
    }
    
    // Atualizar ETA
    updateETA(lat, lng);
    
    console.log('📍 Posição atualizada:', lat, lng);
}

// Polling GPS
async function pollGPS() {
    if (!IS_DELIVERING) return;
    
    try {
        const res = await fetch('/mercado/api/delivery_gps.php?order_id=' + ORDER_ID);
        const data = await res.json();
        
        if (data.success && data.delivery && data.delivery.lat && data.delivery.lng) {
            updateDeliveryPosition(data.delivery.lat, data.delivery.lng);
            
            // Se pedido não está mais em delivering, recarregar página
            if (data.status !== 'delivering') {
                location.reload();
            }
        }
    } catch (e) {
        console.error('Erro GPS:', e);
    }
}

// Polling Chat
let lastMessageId = 0;
async function pollChat() {
    try {
        const res = await fetch('/mercado/api/chat.php?order_id=' + ORDER_ID);
        const data = await res.json();
        
        if (data.success && data.messages && data.messages.length > 0) {
            const container = document.getElementById('chat-messages');
            const lastMsg = data.messages[data.messages.length - 1];
            
            // Se tem mensagem nova
            if (lastMsg.message_id > lastMessageId) {
                // Recarregar mensagens (simples)
                container.innerHTML = '';
                data.messages.forEach(msg => {
                    let senderClass = msg.sender_type;
                    let senderLabel = '🔔 Sistema';
                    if (msg.sender_type === 'shopper') senderLabel = '🛒 ' + msg.sender_name;
                    else if (msg.sender_type === 'customer') senderLabel = '👤 Você';
                    
                    container.innerHTML += `
                        <div class="chat-message">
                            <div class="chat-sender ${senderClass}">${senderLabel}</div>
                            <div class="chat-text">${msg.message.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>
                            <div class="chat-time">${new Date(msg.date_added).toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'})}</div>
                        </div>
                    `;
                });
                container.scrollTop = container.scrollHeight;
                lastMessageId = lastMsg.message_id;
            }
        }
    } catch (e) {
        console.error('Erro chat:', e);
    }
}

// Iniciar polling
setInterval(pollGPS, 5000);   // GPS a cada 5 segundos
setInterval(pollChat, 3000);  // Chat a cada 3 segundos

// Primeira execução
setTimeout(pollGPS, 1000);
setTimeout(pollChat, 1000);

console.log('🔄 Polling ativo: GPS 5s, Chat 3s');
</script>

<style>
@keyframes bounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}
</style>


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
<script src="/mercado/assets/js/mercado-app.js"></script>
</body>
</html>
