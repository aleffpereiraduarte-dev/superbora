<?php
require_once __DIR__ . '/config/database.php';
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO MERCADO - MEUS PEDIDOS PREMIUM
 * ══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/auth-guard.php';
session_start();

$customer_id = $_SESSION['customer_id'] ?? 0;
if (!$customer_id) {
    header('Location: /mercado/mercado-login.php');
    exit;
}

$pdo = getPDO();

// Filtro de status
$status_filter = $_GET['status'] ?? 'all';

// Buscar pedidos
$orders = [];
try {
    $sql = "
        SELECT o.*, os.name as status_name
        FROM oc_order o
        LEFT JOIN oc_order_status os ON o.order_status_id = os.order_status_id AND os.language_id = 2
        WHERE o.customer_id = ? AND o.order_status_id > 0
        ORDER BY o.date_added DESC
        LIMIT 50
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$customer_id]);
    $orders = $stmt->fetchAll();
} catch (Exception $e) {}

// Status mapping
$status_icons = [
    'Pendente' => ['icon' => 'clock', 'color' => '#f59e0b', 'bg' => '#fef3c7'],
    'Processando' => ['icon' => 'spinner fa-spin', 'color' => '#3b82f6', 'bg' => '#eff6ff'],
    'Em Separação' => ['icon' => 'box', 'color' => '#8b5cf6', 'bg' => '#f3e8ff'],
    'Saiu para Entrega' => ['icon' => 'truck', 'color' => '#10b981', 'bg' => '#ecfdf5'],
    'Entregue' => ['icon' => 'check-circle', 'color' => '#10b981', 'bg' => '#ecfdf5'],
    'Cancelado' => ['icon' => 'times-circle', 'color' => '#ef4444', 'bg' => '#fef2f2'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Pedidos - OneMundo Mercado</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #f1f5f9; min-height: 100vh; }
        
        .page-header {
            background: linear-gradient(135deg, #047857, #10b981);
            padding: 20px;
            color: white;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-inner {
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .header-back {
            width: 44px;
            height: 44px;
            background: rgba(255,255,255,0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            font-size: 20px;
        }
        
        .page-header h1 { font-size: 1.5rem; flex: 1; }
        
        /* Tabs */
        .tabs {
            background: white;
            padding: 16px 20px;
            overflow-x: auto;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .tabs-inner {
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            gap: 8px;
        }
        
        .tab {
            padding: 10px 20px;
            background: #f1f5f9;
            border: none;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }
        
        .tab.active {
            background: #10b981;
            color: white;
        }
        
        /* Container */
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Order Card */
        .order-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }
        
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .order-info h3 {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .order-date {
            font-size: 13px;
            color: #64748b;
        }
        
        .order-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .order-items {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            overflow-x: auto;
            padding-bottom: 8px;
        }
        
        .order-item-img {
            width: 60px;
            height: 60px;
            background: #f8fafc;
            border-radius: 12px;
            flex-shrink: 0;
            overflow: hidden;
        }
        
        .order-item-img img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 6px;
        }
        
        .order-more {
            width: 60px;
            height: 60px;
            background: #f1f5f9;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            color: #64748b;
            flex-shrink: 0;
        }
        
        .order-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
            border-top: 1px solid #f1f5f9;
        }
        
        .order-total-label {
            font-size: 13px;
            color: #64748b;
        }
        
        .order-total-value {
            font-size: 20px;
            font-weight: 800;
            color: #10b981;
        }
        
        .order-actions {
            display: flex;
            gap: 10px;
        }
        
        .order-btn {
            padding: 10px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .order-btn.primary {
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            color: white;
        }
        
        .order-btn.secondary {
            background: #f1f5f9;
            border: none;
            color: #64748b;
        }
        
        .order-btn:hover { transform: scale(1.02); }
        
        /* Tracking */
        .order-tracking {
            margin: 16px 0;
            padding: 16px;
            background: #f8fafc;
            border-radius: 16px;
        }
        
        .tracking-title {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tracking-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
        }
        
        .tracking-steps::before {
            content: "";
            position: absolute;
            top: 20px;
            left: 30px;
            right: 30px;
            height: 3px;
            background: #e2e8f0;
            z-index: 0;
        }
        
        .tracking-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 1;
        }
        
        .tracking-step-icon {
            width: 40px;
            height: 40px;
            background: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: #94a3b8;
        }
        
        .tracking-step.active .tracking-step-icon,
        .tracking-step.completed .tracking-step-icon {
            background: #10b981;
            color: white;
        }
        
        .tracking-step-label {
            font-size: 11px;
            color: #64748b;
            text-align: center;
            max-width: 70px;
        }
        
        /* Empty */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-icon { font-size: 80px; margin-bottom: 20px; }
        .empty-state h2 { font-size: 20px; color: #1e293b; margin-bottom: 8px; }
        .empty-state p { color: #64748b; margin-bottom: 24px; }
        
        .empty-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 28px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
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

<header class="page-header">
    <div class="header-inner">
        <a href="/mercado/conta.php" class="header-back"><i class="fas fa-arrow-left"></i></a>
        <h1>📦 Meus Pedidos</h1>
    </div>
</header>

<div class="tabs">
    <div class="tabs-inner">
        <button class="tab <?= $status_filter === 'all' ? 'active' : '' ?>" onclick="filterOrders('all')">Todos</button>
        <button class="tab <?= $status_filter === 'andamento' ? 'active' : '' ?>" onclick="filterOrders('andamento')">Em Andamento</button>
        <button class="tab <?= $status_filter === 'entregue' ? 'active' : '' ?>" onclick="filterOrders('entregue')">Entregues</button>
        <button class="tab <?= $status_filter === 'cancelado' ? 'active' : '' ?>" onclick="filterOrders('cancelado')">Cancelados</button>
    </div>
</div>

<div class="container">
    <?php if (empty($orders)): ?>
    <div class="empty-state">
        <div class="empty-icon">📦</div>
        <h2>Nenhum pedido encontrado</h2>
        <p>Faça seu primeiro pedido agora!</p>
        <a href="/mercado/" class="empty-btn"><i class="fas fa-shopping-basket"></i> Ir às Compras</a>
    </div>
    
    <?php else: ?>
    
    <?php foreach ($orders as $order): 
        $status = $order['status_name'] ?? 'Pendente';
        $status_config = $status_icons[$status] ?? $status_icons['Pendente'];
        
        // Buscar itens do pedido
        $stmt = $pdo->prepare("
            SELECT op.*, p.image 
            FROM oc_order_product op 
            LEFT JOIN oc_product p ON op.product_id = p.product_id
            WHERE op.order_id = ? LIMIT 4
        ");
        $stmt->execute([$order['order_id']]);
        $items = $stmt->fetchAll();
        
        // Contar total de itens
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM oc_order_product WHERE order_id = ?");
        $stmt->execute([$order['order_id']]);
        $total_items = $stmt->fetchColumn();
    ?>
    
    <div class="order-card">
        <div class="order-header">
            <div class="order-info">
                <h3>Pedido #<?= $order['order_id'] ?></h3>
                <span class="order-date"><?= date('d/m/Y H:i', strtotime($order['date_added'])) ?></span>
            </div>
            <div class="order-status" style="background:<?= $status_config['bg'] ?>;color:<?= $status_config['color'] ?>">
                <i class="fas fa-<?= $status_config['icon'] ?>"></i>
                <?= htmlspecialchars($status) ?>
            </div>
        </div>
        
        <?php if (in_array($status, ['Processando', 'Em Separação', 'Saiu para Entrega'])): ?>
        <div class="order-tracking">
            <div class="tracking-title"><i class="fas fa-route"></i> Acompanhe seu pedido</div>
            <div class="tracking-steps">
                <div class="tracking-step completed">
                    <div class="tracking-step-icon"><i class="fas fa-check"></i></div>
                    <span class="tracking-step-label">Confirmado</span>
                </div>
                <div class="tracking-step <?= in_array($status, ['Em Separação', 'Saiu para Entrega']) ? 'completed' : 'active' ?>">
                    <div class="tracking-step-icon"><i class="fas fa-box"></i></div>
                    <span class="tracking-step-label">Separando</span>
                </div>
                <div class="tracking-step <?= $status === 'Saiu para Entrega' ? 'active' : '' ?>">
                    <div class="tracking-step-icon"><i class="fas fa-truck"></i></div>
                    <span class="tracking-step-label">A caminho</span>
                </div>
                <div class="tracking-step">
                    <div class="tracking-step-icon"><i class="fas fa-home"></i></div>
                    <span class="tracking-step-label">Entregue</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="order-items">
            <?php foreach ($items as $item): ?>
            <div class="order-item-img">
                <img src="/image/<?= htmlspecialchars($item['image'] ?? 'placeholder.png') ?>" onerror="this.src='/image/placeholder.png'">
            </div>
            <?php endforeach; ?>
            
            <?php if ($total_items > 4): ?>
            <div class="order-more">+<?= $total_items - 4 ?></div>
            <?php endif; ?>
        </div>
        
        <div class="order-footer">
            <div>
                <div class="order-total-label"><?= $total_items ?> <?= $total_items == 1 ? 'item' : 'itens' ?></div>
                <div class="order-total-value">R$ <?= number_format($order['total'], 2, ',', '.') ?></div>
            </div>
            <div class="order-actions">
                <a href="/mercado/pedido.php?id=<?= $order['order_id'] ?>" class="order-btn secondary">
                    <i class="fas fa-eye"></i> Detalhes
                </a>
                <?php if ($status === 'Entregue'): ?>
                <button class="order-btn primary" onclick="repetirPedido(<?= $order['order_id'] ?>)">
                    <i class="fas fa-redo"></i> Repetir
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function filterOrders(status) {
    window.location.href = '?status=' + status;
}

function repetirPedido(orderId) {
    if (confirm('Adicionar os itens deste pedido ao carrinho?')) {
        fetch('/mercado/api/cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'repeat_order', order_id: orderId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = '/mercado/carrinho.php';
            }
        });
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
<script src="/mercado/assets/js/mercado-app.js"></script>
</body>
</html>
