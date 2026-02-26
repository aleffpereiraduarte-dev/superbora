<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🚚 DELIVERY SCANNER V4
 * Scanner QR para entregadores confirmarem recebimento do pedido
 */

session_start();

$db_host = '147.93.12.236';
$db_name = 'love1';
$db_user = 'root';
// $db_pass loaded from central config

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão");
}

// Criar tabela de entregadores se não existir
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `om_market_delivery` (
        `delivery_id` INT(11) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `phone` VARCHAR(20) DEFAULT NULL,
        `vehicle` VARCHAR(50) DEFAULT 'moto',
        `status` ENUM('online', 'entregando', 'offline') DEFAULT 'offline',
        `current_order_id` INT(11) DEFAULT NULL,
        `total_deliveries_today` INT(11) DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`delivery_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Simular delivery logado (em produção seria login real)
$delivery_id = $_SESSION['delivery_id'] ?? 1;
$delivery_name = $_SESSION['delivery_name'] ?? 'Entregador';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>🚚 Delivery Scanner</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        :root {
            --bg: #000;
            --card: #0a0a0a;
            --border: rgba(255,255,255,0.08);
            --text: #fff;
            --text-muted: rgba(255,255,255,0.4);
            --brand: #f59e0b;
            --brand-dark: #d97706;
            --success: #22c55e;
            --error: #ef4444;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: env(safe-area-inset-bottom);
        }

        .header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.95);
            backdrop-filter: blur(20px);
            padding: 16px;
            padding-top: max(16px, env(safe-area-inset-top));
            border-bottom: 1px solid var(--border);
            z-index: 100;
        }

        .header-content {
            max-width: 500px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .header-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .header-info h1 { font-size: 18px; font-weight: 800; }
        .header-info p { font-size: 12px; color: var(--text-muted); }

        .main {
            padding-top: 100px;
            min-height: 100vh;
        }

        .container {
            max-width: 500px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Scanner */
        .scanner-container {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .scanner-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid var(--border);
        }

        .scanner-header h2 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .scanner-header p {
            font-size: 13px;
            color: var(--text-muted);
        }

        #scanner-viewport {
            width: 100%;
            min-height: 320px;
            background: #111;
            position: relative;
        }

        #scanner-viewport video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .scanner-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }

        .scanner-frame {
            width: 260px;
            height: 260px;
            border: 3px solid var(--brand);
            border-radius: 20px;
            box-shadow: 0 0 0 9999px rgba(0,0,0,0.6);
            position: relative;
        }

        .scanner-frame::before,
        .scanner-frame::after {
            content: '';
            position: absolute;
            width: 40px;
            height: 40px;
            border-color: var(--brand);
            border-style: solid;
        }

        .scanner-frame::before {
            top: -3px;
            left: -3px;
            border-width: 4px 0 0 4px;
            border-radius: 20px 0 0 0;
        }

        .scanner-frame::after {
            bottom: -3px;
            right: -3px;
            border-width: 0 4px 4px 0;
            border-radius: 0 0 20px 0;
        }

        .scanner-line {
            position: absolute;
            left: 10px;
            right: 10px;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--brand), transparent);
            animation: scan 2s linear infinite;
        }

        @keyframes scan {
            0% { top: 10px; }
            50% { top: calc(100% - 10px); }
            100% { top: 10px; }
        }

        .scanner-status {
            padding: 16px;
            text-align: center;
            font-size: 14px;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
        }

        .scanner-status.scanning { color: var(--brand); }
        .scanner-status.success { color: var(--success); }
        .scanner-status.error { color: var(--error); }

        /* Order Card (shown after scan) */
        .order-card {
            display: none;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            overflow: hidden;
            margin-bottom: 20px;
            animation: slideUp 0.4s ease;
        }

        .order-card.show { display: block; }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .order-header {
            padding: 20px;
            background: linear-gradient(135deg, rgba(245,158,11,0.15), rgba(217,119,6,0.1));
            border-bottom: 1px solid var(--border);
        }

        .order-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .order-number {
            font-size: 28px;
            font-weight: 900;
            letter-spacing: -1px;
        }

        .order-badge {
            padding: 8px 14px;
            background: rgba(34,197,94,0.2);
            color: var(--success);
            border-radius: 50px;
            font-size: 12px;
            font-weight: 700;
        }

        .customer-info {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .customer-avatar {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .customer-details h3 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .customer-details p {
            font-size: 12px;
            color: var(--text-muted);
        }

        .order-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            border-bottom: 1px solid var(--border);
        }

        .order-stat {
            padding: 16px;
            text-align: center;
            border-right: 1px solid var(--border);
        }

        .order-stat:last-child { border-right: none; }

        .order-stat-value {
            font-size: 20px;
            font-weight: 800;
            color: var(--brand);
        }

        .order-stat-label {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .order-address {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }

        .address-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .address-text {
            font-size: 14px;
            font-weight: 500;
            line-height: 1.4;
        }

        .order-actions {
            padding: 20px;
        }

        .btn {
            width: 100%;
            padding: 18px;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s;
        }

        .btn:active { transform: scale(0.98); }

        .btn-confirm {
            background: linear-gradient(135deg, var(--success), #16a34a);
            color: #fff;
            box-shadow: 0 4px 20px rgba(34,197,94,0.4);
        }

        .btn-cancel {
            background: rgba(255,255,255,0.05);
            color: var(--text-muted);
            margin-top: 12px;
        }

        /* Success Screen */
        .success-screen {
            display: none;
            position: fixed;
            inset: 0;
            background: var(--bg);
            z-index: 200;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            text-align: center;
            padding: 40px;
        }

        .success-screen.show { display: flex; }

        .success-icon {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--success), #16a34a);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            margin-bottom: 24px;
            animation: pop 0.5s ease;
        }

        @keyframes pop {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        .success-title {
            font-size: 28px;
            font-weight: 900;
            margin-bottom: 12px;
        }

        .success-text {
            font-size: 16px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .success-order {
            font-size: 22px;
            font-weight: 700;
            color: var(--brand);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-icon {
            font-size: 64px;
            margin-bottom: 16px;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .empty-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .empty-text {
            font-size: 14px;
            color: var(--text-muted);
        }

        /* Loading */
        .loading {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.9);
            z-index: 300;
            align-items: center;
            justify-content: center;
        }

        .loading.show { display: flex; }

        .spinner {
            width: 50px;
            height: 50px;
            border: 3px solid var(--border);
            border-top-color: var(--brand);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Instructions */
        .instructions {
            background: rgba(245,158,11,0.1);
            border: 1px solid rgba(245,158,11,0.2);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .instructions h3 {
            font-size: 14px;
            font-weight: 700;
            color: var(--brand);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .instructions ol {
            margin: 0;
            padding-left: 20px;
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .instructions li { margin-bottom: 4px; }
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
    <header class="header">
        <div class="header-content">
            <div class="header-icon">🚚</div>
            <div class="header-info">
                <h1>Delivery Scanner</h1>
                <p>Escaneie o QR do Shopper</p>
            </div>
        </div>
    </header>

    <main class="main">
        <div class="container">
            <!-- Instructions -->
            <div class="instructions">
                <h3>📋 Como funciona</h3>
                <ol>
                    <li>O Shopper mostra o QR Code do pedido</li>
                    <li>Escaneie o código com a câmera</li>
                    <li>Verifique os dados do pedido</li>
                    <li>Confirme o recebimento</li>
                </ol>
            </div>

            <!-- Scanner -->
            <div class="scanner-container" id="scannerContainer">
                <div class="scanner-header">
                    <h2>📷 Escaneando...</h2>
                    <p>Aponte a câmera para o QR Code</p>
                </div>
                <div id="scanner-viewport">
                    <div class="scanner-overlay">
                        <div class="scanner-frame">
                            <div class="scanner-line"></div>
                        </div>
                    </div>
                </div>
                <div class="scanner-status scanning" id="scannerStatus">
                    Aguardando QR Code...
                </div>
            </div>

            <!-- Order Card (hidden until scan) -->
            <div class="order-card" id="orderCard">
                <div class="order-header">
                    <div class="order-top">
                        <span class="order-number" id="orderNumber">#0</span>
                        <span class="order-badge">📦 Pronto</span>
                    </div>
                    <div class="customer-info">
                        <div class="customer-avatar">👤</div>
                        <div class="customer-details">
                            <h3 id="customerName">-</h3>
                            <p id="customerPhone">-</p>
                        </div>
                    </div>
                </div>

                <div class="order-stats">
                    <div class="order-stat">
                        <div class="order-stat-value" id="orderItems">0</div>
                        <div class="order-stat-label">Itens</div>
                    </div>
                    <div class="order-stat">
                        <div class="order-stat-value" id="orderTotal">R$ 0</div>
                        <div class="order-stat-label">Total</div>
                    </div>
                    <div class="order-stat">
                        <div class="order-stat-value" id="orderWeight">-</div>
                        <div class="order-stat-label">Peso Est.</div>
                    </div>
                </div>

                <div class="order-address">
                    <div class="address-label">📍 Endereço de Entrega</div>
                    <div class="address-text" id="orderAddress">-</div>
                </div>

                <div class="order-actions">
                    <button class="btn btn-confirm" onclick="confirmHandoff()">
                        ✅ Confirmar Recebimento
                    </button>
                    <button class="btn btn-cancel" onclick="cancelScan()">
                        ← Cancelar
                    </button>
                </div>
            </div>
        </div>
    </main>

    <!-- Success Screen -->
    <div class="success-screen" id="successScreen">
        <div class="success-icon">✓</div>
        <h2 class="success-title">Recebimento Confirmado!</h2>
        <p class="success-text">Pedido pronto para entrega</p>
        <p class="success-order" id="successOrder">#0</p>
    </div>

    <!-- Loading -->
    <div class="loading" id="loading">
        <div class="spinner"></div>
    </div>

    <script>
    const DELIVERY_ID = <?= $delivery_id ?>;
    let html5QrCode = null;
    let currentOrderId = null;
    let currentBoxCode = null;

    // Iniciar scanner
    function startScanner() {
        html5QrCode = new Html5Qrcode("scanner-viewport");
        
        Html5Qrcode.getCameras().then(devices => {
            if (devices && devices.length) {
                // Preferir câmera traseira
                let cameraId = devices[0].id;
                for (let device of devices) {
                    if (device.label.toLowerCase().includes('back') || 
                        device.label.toLowerCase().includes('rear') ||
                        device.label.toLowerCase().includes('traseira')) {
                        cameraId = device.id;
                        break;
                    }
                }
                
                html5QrCode.start(
                    cameraId,
                    {
                        fps: 10,
                        qrbox: { width: 250, height: 250 },
                        aspectRatio: 1
                    },
                    onScanSuccess,
                    onScanError
                ).catch(err => {
                    console.error("Erro ao iniciar câmera:", err);
                    document.getElementById('scannerStatus').textContent = '❌ Erro ao acessar câmera';
                    document.getElementById('scannerStatus').className = 'scanner-status error';
                });
            }
        }).catch(err => {
            console.error("Erro ao buscar câmeras:", err);
        });
    }

    function stopScanner() {
        if (html5QrCode) {
            html5QrCode.stop().catch(err => {});
        }
    }

    // Scan success
    function onScanSuccess(decodedText) {
        try {
            const data = JSON.parse(decodedText);
            
            if (data.type !== 'delivery_handoff') {
                showStatus('❌ QR Code inválido', 'error');
                return;
            }
            
            // Verificar expiração
            if (data.expires && Date.now() > data.expires) {
                showStatus('⏱️ QR Code expirado', 'error');
                return;
            }
            
            // Parar scanner
            stopScanner();
            
            // Vibrar
            if (navigator.vibrate) navigator.vibrate(100);
            
            // Buscar dados do pedido
            fetchOrderData(data.order_id, data.box_code);
            
        } catch (e) {
            showStatus('❌ QR Code inválido', 'error');
        }
    }

    function onScanError(error) {
        // Ignore errors
    }

    function showStatus(text, type = 'scanning') {
        const el = document.getElementById('scannerStatus');
        el.textContent = text;
        el.className = 'scanner-status ' + type;
    }

    // Buscar dados do pedido
    async function fetchOrderData(orderId, boxCode) {
        showLoading(true);
        
        try {
            const res = await fetch(`api/shopper.php?action=get_order_by_qr&box_code=${encodeURIComponent(boxCode)}`);
            const data = await res.json();
            
            if (data.success && data.order) {
                currentOrderId = data.order.order_id;
                currentBoxCode = boxCode;
                
                // Preencher dados
                document.getElementById('orderNumber').textContent = '#' + data.order.order_id;
                document.getElementById('customerName').textContent = data.order.customer_name || 'Cliente';
                document.getElementById('customerPhone').textContent = '📞 Cliente';
                document.getElementById('orderItems').textContent = data.order.total_items || 0;
                document.getElementById('orderTotal').textContent = 'R$ ' + parseFloat(data.order.total || 0).toFixed(2).replace('.', ',');
                document.getElementById('orderWeight').textContent = (parseFloat(data.order.total_items || 0) * 0.5).toFixed(1) + 'kg';
                document.getElementById('orderAddress').textContent = data.order.shipping_address || 'Endereço não disponível';
                
                // Mostrar card
                document.getElementById('scannerContainer').style.display = 'none';
                document.getElementById('orderCard').classList.add('show');
                
            } else {
                showStatus('❌ ' + (data.error || 'Pedido não encontrado'), 'error');
                startScanner();
            }
        } catch (e) {
            showStatus('❌ Erro de conexão', 'error');
            startScanner();
        }
        
        showLoading(false);
    }

    // Confirmar handoff
    async function confirmHandoff() {
        if (!currentOrderId || !currentBoxCode) return;
        
        showLoading(true);
        
        try {
            const res = await fetch('api/shopper.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'confirm_handoff',
                    order_id: currentOrderId,
                    box_code: currentBoxCode,
                    delivery_id: DELIVERY_ID
                })
            });
            
            const data = await res.json();
            
            if (data.success) {
                // Mostrar sucesso
                document.getElementById('successOrder').textContent = '#' + currentOrderId;
                document.getElementById('successScreen').classList.add('show');
                
                // Vibrar
                if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
                
                // Recarregar após 4s
                setTimeout(() => {
                    location.reload();
                }, 4000);
            } else {
                alert('Erro: ' + (data.error || 'Falha ao confirmar'));
                cancelScan();
            }
        } catch (e) {
            alert('Erro de conexão');
            cancelScan();
        }
        
        showLoading(false);
    }

    // Cancelar
    function cancelScan() {
        currentOrderId = null;
        currentBoxCode = null;
        document.getElementById('orderCard').classList.remove('show');
        document.getElementById('scannerContainer').style.display = 'block';
        showStatus('Aguardando QR Code...', 'scanning');
        startScanner();
    }

    function showLoading(show) {
        document.getElementById('loading').classList.toggle('show', show);
    }

    // Iniciar ao carregar
    document.addEventListener('DOMContentLoaded', startScanner);
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
