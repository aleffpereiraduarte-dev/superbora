<?php
require_once __DIR__ . '/config/database.php';
/**
 * 👩‍🍳 MODO COZINHA - ONE
 */
session_start();
$recipe_id = isset($_GET["recipe_id"]) ? intval($_GET["recipe_id"]) : 0;
$customer_id = isset($_SESSION["customer_id"]) ? $_SESSION["customer_id"] : 0;

if (!$recipe_id) {
    header("Location: /mercado/one.php");
    exit;
}

// Buscar receita
$db = new PDO("mysql:host=localhost;dbname=love1;charset=utf8mb4", "root", DB_PASSWORD);
$stmt = $db->prepare("SELECT * FROM om_one_recipes WHERE recipe_id = ?");
$stmt->execute(array($recipe_id));
$recipe = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recipe) {
    header("Location: /mercado/one.php");
    exit;
}

// Buscar passos
$stmt = $db->prepare("SELECT * FROM om_one_recipe_steps WHERE recipe_id = ? ORDER BY step_number");
$stmt->execute(array($recipe_id));
$steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar ingredientes
$stmt = $db->prepare("SELECT * FROM om_one_recipe_ingredients WHERE recipe_id = ? ORDER BY display_order");
$stmt->execute(array($recipe_id));
$ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>👩‍🍳 <?php echo htmlspecialchars($recipe["name"]); ?> - ONE</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #22c55e;
            --primary-dark: #16a34a;
            --bg: #0a0a0a;
            --card: #141414;
            --border: #262626;
            --text: #ffffff;
            --muted: #888888;
        }
        
        body {
            font-family: "Inter", sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 16px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-top {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .back-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: #fff;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
        }
        
        .recipe-title {
            flex: 1;
            font-size: 16px;
            font-weight: 700;
        }
        
        .progress-bar {
            margin-top: 12px;
            height: 4px;
            background: rgba(255,255,255,0.2);
            border-radius: 2px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: #fff;
            transition: width 0.3s ease;
        }
        
        .step-indicator {
            text-align: center;
            font-size: 12px;
            margin-top: 8px;
            opacity: 0.9;
        }
        
        /* Step Content */
        .step-container {
            padding: 24px 20px;
            min-height: calc(100vh - 200px);
        }
        
        .step-number {
            display: inline-block;
            background: var(--primary);
            color: #000;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 16px;
        }
        
        .step-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 16px;
        }
        
        .step-instruction {
            font-size: 17px;
            line-height: 1.7;
            color: rgba(255,255,255,0.9);
            margin-bottom: 24px;
        }
        
        .step-tip {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
        }
        
        .step-tip-label {
            color: #f59e0b;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .step-tip-text {
            font-size: 14px;
            color: rgba(255,255,255,0.8);
        }
        
        /* Timer */
        .timer-card {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            margin-bottom: 24px;
        }
        
        .timer-display {
            font-size: 56px;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }
        
        .timer-label {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 4px;
        }
        
        .timer-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: #fff;
            padding: 12px 32px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 16px;
        }
        
        .timer-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* Navigation */
        .nav-buttons {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--card);
            border-top: 1px solid var(--border);
            padding: 16px 20px;
            display: flex;
            gap: 12px;
        }
        
        .nav-btn {
            flex: 1;
            padding: 16px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .nav-btn.prev {
            background: var(--border);
            color: var(--text);
        }
        
        .nav-btn.next {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #000;
        }
        
        .nav-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Ingredients Panel */
        .ingredients-toggle {
            position: fixed;
            bottom: 90px;
            right: 20px;
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 12px 20px;
            border-radius: 25px;
            font-size: 14px;
            cursor: pointer;
            z-index: 50;
        }
        
        .ingredients-panel {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--card);
            border-top-left-radius: 24px;
            border-top-right-radius: 24px;
            padding: 24px;
            transform: translateY(100%);
            transition: transform 0.3s ease;
            z-index: 200;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .ingredients-panel.show {
            transform: translateY(0);
        }
        
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .panel-title {
            font-size: 18px;
            font-weight: 700;
        }
        
        .close-panel {
            background: var(--border);
            border: none;
            color: var(--text);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            font-size: 20px;
            cursor: pointer;
        }
        
        .ingredient-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(255,255,255,0.03);
            border-radius: 10px;
            margin-bottom: 8px;
        }
        
        .ingredient-check {
            width: 24px;
            height: 24px;
            border: 2px solid var(--border);
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .ingredient-check.checked {
            background: var(--primary);
            border-color: var(--primary);
        }
        
        .ingredient-name {
            flex: 1;
        }
        
        .ingredient-qty {
            color: var(--muted);
            font-size: 13px;
        }
        
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
            z-index: 150;
        }
        
        .overlay.show {
            opacity: 1;
            visibility: visible;
        }
        
        /* Completion */
        .completion-screen {
            position: fixed;
            inset: 0;
            background: var(--bg);
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            text-align: center;
            z-index: 300;
        }
        
        .completion-screen.show {
            display: flex;
        }
        
        .completion-emoji {
            font-size: 80px;
            margin-bottom: 24px;
            animation: bounce 0.6s ease;
        }
        
        @keyframes bounce {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }
        
        .completion-title {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 12px;
        }
        
        .completion-text {
            color: var(--muted);
            font-size: 16px;
            margin-bottom: 32px;
        }
        
        .rating-stars {
            display: flex;
            gap: 8px;
            margin-bottom: 32px;
        }
        
        .star {
            font-size: 36px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .star:hover {
            transform: scale(1.2);
        }
        
        .completion-btn {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #000;
            border: none;
            padding: 16px 48px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
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
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-top">
            <button class="back-btn" onclick="history.back()">←</button>
            <div class="recipe-title"><?php echo htmlspecialchars($recipe["name"]); ?></div>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" id="progressFill" style="width: 0%"></div>
        </div>
        <div class="step-indicator">
            Passo <span id="currentStepNum">1</span> de <?php echo count($steps); ?>
        </div>
    </div>
    
    <!-- Step Content -->
    <div class="step-container" id="stepContainer">
        <!-- Preenchido via JS -->
    </div>
    
    <!-- Ingredients Toggle -->
    <button class="ingredients-toggle" onclick="showIngredients()">📝 Ingredientes</button>
    
    <!-- Navigation -->
    <div class="nav-buttons">
        <button class="nav-btn prev" id="prevBtn" onclick="prevStep()">◀ Voltar</button>
        <button class="nav-btn next" id="nextBtn" onclick="nextStep()">Próximo ▶</button>
    </div>
    
    <!-- Overlay -->
    <div class="overlay" id="overlay" onclick="hideIngredients()"></div>
    
    <!-- Ingredients Panel -->
    <div class="ingredients-panel" id="ingredientsPanel">
        <div class="panel-header">
            <div class="panel-title">📝 Ingredientes</div>
            <button class="close-panel" onclick="hideIngredients()">×</button>
        </div>
        <?php foreach ($ingredients as $ing): ?>
        <div class="ingredient-item">
            <div class="ingredient-check" onclick="toggleIngredient(this)"></div>
            <div class="ingredient-name"><?php echo htmlspecialchars($ing["name"]); ?></div>
            <div class="ingredient-qty"><?php echo $ing["quantity"] . " " . $ing["unit"]; ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Completion Screen -->
    <div class="completion-screen" id="completionScreen">
        <div class="completion-emoji">🎉</div>
        <div class="completion-title">Parabéns!</div>
        <div class="completion-text">Você terminou a receita!<br>Como ficou?</div>
        <div class="rating-stars" id="ratingStars">
            <span class="star" onclick="setRating(1)">⭐</span>
            <span class="star" onclick="setRating(2)">⭐</span>
            <span class="star" onclick="setRating(3)">⭐</span>
            <span class="star" onclick="setRating(4)">⭐</span>
            <span class="star" onclick="setRating(5)">⭐</span>
        </div>
        <button class="completion-btn" onclick="finishRecipe()">Concluir</button>
    </div>
    
    <script>
        const steps = <?php echo json_encode($steps); ?>;
        const recipeId = <?php echo $recipe_id; ?>;
        const totalSteps = steps.length;
        let currentStep = 0;
        let activeTimer = null;
        let timerInterval = null;
        let rating = 0;
        
        function renderStep(index) {
            const step = steps[index];
            const container = document.getElementById("stepContainer");
            
            let html = `<div class="step-number">Passo ${step.step_number}</div>`;
            
            if (step.title) {
                html += `<div class="step-title">${step.title}</div>`;
            }
            
            html += `<div class="step-instruction">${step.instruction}</div>`;
            
            if (step.tip) {
                html += `
                    <div class="step-tip">
                        <div class="step-tip-label">💡 Dica da ONE</div>
                        <div class="step-tip-text">${step.tip}</div>
                    </div>
                `;
            }
            
            if (step.timer_minutes) {
                const seconds = step.timer_minutes * 60;
                html += `
                    <div class="timer-card" id="timerCard">
                        <div class="timer-display" id="timerDisplay">${formatTime(seconds)}</div>
                        <div class="timer-label">${step.timer_label || "Timer"}</div>
                        <button class="timer-btn" id="timerBtn" onclick="toggleTimer(${seconds})">▶ Iniciar Timer</button>
                    </div>
                `;
            }
            
            container.innerHTML = html;
            
            // Atualizar UI
            document.getElementById("currentStepNum").textContent = index + 1;
            document.getElementById("progressFill").style.width = ((index + 1) / totalSteps * 100) + "%";
            
            // Botões de navegação
            document.getElementById("prevBtn").disabled = index === 0;
            
            if (index === totalSteps - 1) {
                document.getElementById("nextBtn").innerHTML = "✅ Terminar";
            } else {
                document.getElementById("nextBtn").innerHTML = "Próximo ▶";
            }
        }
        
        function formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return mins.toString().padStart(2, "0") + ":" + secs.toString().padStart(2, "0");
        }
        
        function toggleTimer(totalSeconds) {
            const btn = document.getElementById("timerBtn");
            const display = document.getElementById("timerDisplay");
            
            if (timerInterval) {
                // Pausar
                clearInterval(timerInterval);
                timerInterval = null;
                btn.innerHTML = "▶ Continuar";
            } else {
                // Iniciar/Continuar
                if (activeTimer === null) {
                    activeTimer = totalSeconds;
                }
                
                btn.innerHTML = "⏸ Pausar";
                
                timerInterval = setInterval(() => {
                    activeTimer--;
                    display.textContent = formatTime(activeTimer);
                    
                    if (activeTimer <= 0) {
                        clearInterval(timerInterval);
                        timerInterval = null;
                        activeTimer = null;
                        btn.innerHTML = "✅ Pronto!";
                        btn.disabled = true;
                        
                        // Notificação
                        if ("Notification" in window && Notification.permission === "granted") {
                            new Notification("⏱️ Timer da ONE!", {
                                body: "O timer acabou! Hora do próximo passo!",
                                icon: "🍳"
                            });
                        }
                        
                        // Som
                        try {
                            const audio = new Audio("data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2teleE5EcqzU4oo7");
                            audio.play();
                        } catch(e) {}
                    }
                }, 1000);
            }
        }
        
        function prevStep() {
            if (currentStep > 0) {
                resetTimer();
                currentStep--;
                renderStep(currentStep);
            }
        }
        
        function nextStep() {
            resetTimer();
            
            if (currentStep < totalSteps - 1) {
                currentStep++;
                renderStep(currentStep);
            } else {
                // Terminou!
                document.getElementById("completionScreen").classList.add("show");
            }
        }
        
        function resetTimer() {
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
            activeTimer = null;
        }
        
        function showIngredients() {
            document.getElementById("ingredientsPanel").classList.add("show");
            document.getElementById("overlay").classList.add("show");
        }
        
        function hideIngredients() {
            document.getElementById("ingredientsPanel").classList.remove("show");
            document.getElementById("overlay").classList.remove("show");
        }
        
        function toggleIngredient(el) {
            el.classList.toggle("checked");
            el.innerHTML = el.classList.contains("checked") ? "✓" : "";
        }
        
        function setRating(stars) {
            rating = stars;
            const starsEl = document.querySelectorAll(".star");
            starsEl.forEach((star, i) => {
                star.style.opacity = i < stars ? "1" : "0.3";
            });
        }
        
        function finishRecipe() {
            // Salvar que fez a receita
            fetch("/mercado/api/recipes.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    action: "mark_made",
                    recipe_id: recipeId,
                    rating: rating
                })
            });
            
            window.location.href = "/mercado/one.php";
        }
        
        // Pedir permissão de notificação
        if ("Notification" in window && Notification.permission === "default") {
            Notification.requestPermission();
        }
        
        // Iniciar
        renderStep(0);
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
