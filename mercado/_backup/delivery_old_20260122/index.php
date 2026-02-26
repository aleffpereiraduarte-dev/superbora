<?php
/**
 * DELIVERY APP v2.0 - OneMundo
 * Interface premium estilo Uber para entregadores
 * SEGURO: Config central + prepared statements
 */
require_once __DIR__ . '/config.php';
requireLogin();

$pdo = getDB();
$delivery_id = $_SESSION["delivery_id"];
$delivery = getDelivery();

if (!$delivery) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Estatisticas do dia
$today_orders = 0;
$today_earnings = 0;
$active_delivery = null;

try {
    // Entregas de hoje
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(delivery_earning), 0) as earn
        FROM om_market_orders
        WHERE delivery_id = ? AND DATE(delivered_at) = CURRENT_DATE AND status = 'delivered'");
    $stmt->execute([$delivery_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $today_orders = $stats['cnt'] ?? 0;
    $today_earnings = $stats['earn'] ?? 0;

    // Entrega ativa
    $stmt = $pdo->prepare("SELECT o.*, p.name as partner_name, p.logo as partner_logo,
        (SELECT COUNT(*) FROM om_market_order_products WHERE order_id = o.order_id) as total_items
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.delivery_id = ? AND o.status IN ('delivering', 'out_for_delivery')
        ORDER BY o.order_id DESC LIMIT 1");
    $stmt->execute([$delivery_id]);
    $active_delivery = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Ofertas disponiveis
$available_offers = [];
try {
    $stmt = $pdo->prepare("SELECT o.*, p.name as partner_name, p.logo as partner_logo, p.address as partner_address,
        (SELECT COUNT(*) FROM om_market_order_products WHERE order_id = o.order_id) as total_items
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.status IN ('ready', 'purchased', 'awaiting_delivery')
        AND (o.delivery_id IS NULL OR o.delivery_id = 0)
        ORDER BY o.created_at ASC
        LIMIT 10");
    $stmt->execute();
    $available_offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

function formatMoney($v) {
    return "R$ " . number_format((float)$v, 2, ",", ".");
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0a0a0a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>OneMundo Delivery</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #050505;
            --card: #111111;
            --card2: #161616;
            --border: #222222;
            --text: #ffffff;
            --text2: #9ca3af;
            --text3: #6b7280;
            --orange: #f97316;
            --orange2: #ea580c;
            --orange-glow: rgba(249, 115, 22, 0.25);
            --green: #10b981;
            --green-glow: rgba(16, 185, 129, 0.25);
            --yellow: #f59e0b;
            --red: #ef4444;
            --blue: #3b82f6;
            --safe-top: env(safe-area-inset-top);
            --safe-bottom: env(safe-area-inset-bottom);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            min-height: 100dvh;
            padding-bottom: 100px;
            overscroll-behavior: none;
        }

        /* HEADER */
        .header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: linear-gradient(180deg, var(--card) 0%, var(--bg) 100%);
            padding: calc(var(--safe-top) + 12px) 16px 16px;
            border-bottom: 1px solid var(--border);
        }

        .header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-icon {
            width: 46px;
            height: 46px;
            background: linear-gradient(135deg, var(--orange), var(--orange2));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 4px 15px var(--orange-glow);
        }

        .logo-text { font-size: 18px; font-weight: 800; }
        .logo-sub { font-size: 11px; color: var(--text3); font-weight: 500; }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .status-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: var(--card2);
            border: 1px solid var(--border);
            border-radius: 24px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 8px var(--green);
            animation: pulse 2s infinite;
        }

        .status-dot.offline { background: var(--text3); box-shadow: none; animation: none; }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .status-text { font-size: 13px; font-weight: 600; }

        .avatar {
            width: 44px;
            height: 44px;
            background: var(--card2);
            border: 2px solid var(--border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
        }

        /* STATS */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 16px;
        }

        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px;
            text-align: center;
        }

        .stat-icon { font-size: 26px; margin-bottom: 8px; }
        .stat-value { font-size: 22px; font-weight: 800; }
        .stat-value.orange { color: var(--orange); }
        .stat-value.green { color: var(--green); }
        .stat-value.yellow { color: var(--yellow); }
        .stat-label { font-size: 11px; color: var(--text3); margin-top: 4px; }

        /* VEHICLE BADGE */
        .vehicle-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 16px 16px;
        }

        .vehicle-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            flex: 1;
        }

        .vehicle-icon {
            width: 40px;
            height: 40px;
            background: var(--orange-glow);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .vehicle-info { flex: 1; }
        .vehicle-name { font-size: 14px; font-weight: 600; }
        .vehicle-plate { font-size: 12px; color: var(--text3); }

        /* ACTIVE DELIVERY */
        .active-delivery {
            margin: 0 16px 16px;
            background: linear-gradient(135deg, var(--orange-glow), rgba(249, 115, 22, 0.05));
            border: 2px solid var(--orange);
            border-radius: 20px;
            padding: 18px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }

        .active-delivery::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(249, 115, 22, 0.1), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .active-delivery:active { transform: scale(0.98); }

        .active-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: var(--orange);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            color: #000;
            margin-bottom: 14px;
        }

        .active-badge .dot {
            width: 6px;
            height: 6px;
            background: #000;
            border-radius: 50%;
            animation: blink 1s infinite;
        }

        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .active-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }

        .active-store-logo {
            width: 52px;
            height: 52px;
            background: var(--card);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }

        .active-store-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; }

        .active-info { flex: 1; }
        .active-store { font-size: 17px; font-weight: 700; }
        .active-order-num { font-size: 13px; color: var(--text2); }

        .active-address {
            background: rgba(0,0,0,0.3);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 14px;
            position: relative;
            z-index: 1;
        }

        .address-label { font-size: 11px; color: var(--text3); margin-bottom: 4px; }
        .address-text { font-size: 14px; font-weight: 500; }

        .active-stats {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            position: relative;
            z-index: 1;
        }

        .active-stats span { color: var(--text2); }
        .active-stats strong { color: var(--text); }

        /* WAITING SECTION */
        .waiting-section {
            margin: 0 16px;
            text-align: center;
            padding: 50px 20px;
        }

        .waiting-icon {
            font-size: 72px;
            margin-bottom: 20px;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .waiting-title { font-size: 22px; font-weight: 700; margin-bottom: 10px; }
        .waiting-text { font-size: 15px; color: var(--text2); margin-bottom: 24px; }

        .waiting-pulse {
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .waiting-pulse span {
            width: 14px;
            height: 14px;
            background: var(--orange);
            border-radius: 50%;
            animation: pulseDot 1.4s infinite;
        }

        .waiting-pulse span:nth-child(2) { animation-delay: 0.2s; }
        .waiting-pulse span:nth-child(3) { animation-delay: 0.4s; }

        @keyframes pulseDot {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
            40% { transform: scale(1); opacity: 1; }
        }

        /* OFFERS LIST */
        .section-title {
            padding: 20px 16px 12px;
            font-size: 17px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .offers-list { padding: 0 16px; }

        .offer-item {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px;
            margin-bottom: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .offer-item:hover { border-color: var(--orange); }
        .offer-item:active { transform: scale(0.98); }

        .offer-top {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
        }

        .offer-icon {
            width: 50px;
            height: 50px;
            background: var(--card2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .offer-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; }

        .offer-info { flex: 1; }
        .offer-store { font-size: 15px; font-weight: 600; }
        .offer-addr { font-size: 12px; color: var(--text3); margin-top: 2px; }

        .offer-earning {
            font-size: 18px;
            font-weight: 800;
            color: var(--green);
        }

        .offer-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .offer-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 10px;
            background: var(--card2);
            border-radius: 8px;
            font-size: 12px;
            color: var(--text2);
        }

        .offer-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--orange), var(--orange2));
            border: none;
            border-radius: 12px;
            color: #000;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .offer-btn:active { transform: scale(0.98); }

        /* QUICK ACTIONS */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 16px;
        }

        .quick-btn {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: var(--text);
        }

        .quick-btn:hover { border-color: var(--orange); }
        .quick-btn:active { transform: scale(0.98); }

        .quick-icon {
            width: 48px;
            height: 48px;
            background: var(--card2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin: 0 auto 10px;
        }

        .quick-label { font-size: 13px; font-weight: 600; }

        /* BOTTOM NAV */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--card);
            border-top: 1px solid var(--border);
            padding: 12px 16px calc(12px + var(--safe-bottom));
            display: flex;
            justify-content: space-around;
            z-index: 50;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            color: var(--text3);
            text-decoration: none;
            font-size: 10px;
            padding: 10px 18px;
            border-radius: 14px;
            transition: all 0.2s;
        }

        .nav-item.active {
            color: var(--orange);
            background: var(--orange-glow);
        }

        .nav-item .icon { font-size: 24px; }

        /* OFFER OVERLAY */
        .offer-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.95);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .offer-overlay.show {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .offer-card {
            background: var(--card);
            border-radius: 28px;
            width: 100%;
            max-width: 380px;
            overflow: hidden;
            animation: slideUp 0.4s ease;
            border: 1px solid var(--border);
        }

        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .offer-card-header {
            padding: 24px;
            text-align: center;
            background: linear-gradient(180deg, var(--card2) 0%, var(--card) 100%);
        }

        .timer-ring-wrap {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 16px;
        }

        .timer-ring {
            transform: rotate(-90deg);
            width: 100%;
            height: 100%;
        }

        .timer-ring-bg { fill: none; stroke: var(--border); stroke-width: 8; }
        .timer-ring-fill {
            fill: none;
            stroke: var(--orange);
            stroke-width: 8;
            stroke-linecap: round;
            stroke-dasharray: 339.292;
            stroke-dashoffset: 0;
            transition: stroke-dashoffset 1s linear, stroke 0.3s;
        }

        .timer-ring-fill.warning { stroke: var(--yellow); }
        .timer-ring-fill.danger { stroke: var(--red); }

        .timer-center {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .timer-seconds {
            font-size: 38px;
            font-weight: 900;
            color: var(--orange);
            line-height: 1;
        }

        .timer-seconds.warning { color: var(--yellow); }
        .timer-seconds.danger { color: var(--red); animation: blink 0.5s infinite; }

        .timer-label { font-size: 11px; color: var(--text3); text-transform: uppercase; letter-spacing: 1px; }

        .offer-card-title { font-size: 19px; font-weight: 700; color: var(--orange); }

        .offer-card-details { padding: 20px 24px; }

        .offer-card-store {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
        }

        .offer-card-logo {
            width: 58px;
            height: 58px;
            background: var(--card2);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            border: 1px solid var(--border);
        }

        .offer-card-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 16px; }

        .offer-card-store-info { flex: 1; }
        .offer-card-store-name { font-size: 17px; font-weight: 700; }
        .offer-card-store-addr { font-size: 12px; color: var(--text2); margin-top: 2px; }

        .offer-card-meta {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }

        .offer-meta-item {
            background: var(--card2);
            border-radius: 12px;
            padding: 14px;
            text-align: center;
        }

        .offer-meta-icon { font-size: 22px; margin-bottom: 4px; }
        .offer-meta-value { font-size: 16px; font-weight: 700; }
        .offer-meta-label { font-size: 10px; color: var(--text3); }

        .offer-card-delivery {
            background: var(--card2);
            border-radius: 14px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .offer-delivery-icon {
            width: 44px;
            height: 44px;
            background: rgba(59, 130, 246, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .offer-delivery-info { flex: 1; }
        .offer-delivery-name { font-size: 14px; font-weight: 600; }
        .offer-delivery-addr { font-size: 12px; color: var(--text2); }

        .offer-card-earnings {
            background: linear-gradient(135deg, var(--green-glow), rgba(16, 185, 129, 0.05));
            margin: 0 24px 20px;
            padding: 18px;
            border-radius: 16px;
            border: 1px solid rgba(16, 185, 129, 0.3);
            text-align: center;
        }

        .offer-earnings-label { font-size: 13px; color: var(--green); margin-bottom: 4px; }
        .offer-earnings-value { font-size: 36px; font-weight: 900; color: var(--green); }

        .offer-card-actions {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 12px;
            padding: 0 24px 24px;
        }

        .btn-offer {
            padding: 18px;
            border-radius: 16px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-offer:active { transform: scale(0.96); }

        .btn-reject {
            background: var(--card2);
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn-accept {
            background: linear-gradient(135deg, var(--orange), var(--orange2));
            color: #000;
            box-shadow: 0 4px 20px var(--orange-glow);
        }

        .btn-accept:disabled { opacity: 0.5; cursor: not-allowed; }
    </style>
</head>
<body>
    <!-- HEADER -->
    <header class="header">
        <div class="header-row">
            <div class="logo">
                <div class="logo-icon">🚴</div>
                <div>
                    <div class="logo-text">OneMundo</div>
                    <div class="logo-sub">Delivery</div>
                </div>
            </div>
            <div class="header-right">
                <div class="status-toggle" onclick="toggleStatus()">
                    <div class="status-dot<?= ($delivery['is_online'] ?? 0) ? '' : ' offline' ?>" id="status-dot"></div>
                    <span class="status-text" id="status-text"><?= ($delivery['is_online'] ?? 0) ? 'Online' : 'Offline' ?></span>
                </div>
                <div class="avatar" onclick="location.href='perfil.php'">👤</div>
            </div>
        </div>
    </header>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon">🛵</div>
            <div class="stat-value"><?= $today_orders ?></div>
            <div class="stat-label">Entregas Hoje</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-value green"><?= formatMoney($today_earnings) ?></div>
            <div class="stat-label">Ganhos Hoje</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⭐</div>
            <div class="stat-value yellow"><?= number_format($delivery['rating'] ?? 5, 1) ?></div>
            <div class="stat-label">Avaliacao</div>
        </div>
    </div>

    <!-- VEHICLE BADGE -->
    <div class="vehicle-bar">
        <div class="vehicle-badge">
            <div class="vehicle-icon"><?= ($delivery['vehicle_type'] ?? 'moto') === 'bike' ? '🚲' : '🏍️' ?></div>
            <div class="vehicle-info">
                <div class="vehicle-name"><?= ucfirst($delivery['vehicle_type'] ?? 'Moto') ?></div>
                <div class="vehicle-plate"><?= htmlspecialchars($delivery['vehicle_plate'] ?? 'Placa nao informada') ?></div>
            </div>
        </div>
    </div>

    <?php if ($active_delivery): ?>
    <!-- ACTIVE DELIVERY -->
    <div class="active-delivery" onclick="location.href='entrega.php?order_id=<?= $active_delivery['order_id'] ?>'">
        <div class="active-badge"><span class="dot"></span> ENTREGA EM ANDAMENTO</div>
        <div class="active-header">
            <div class="active-store-logo">
                <?php if (!empty($active_delivery['partner_logo'])): ?>
                    <img src="/image/<?= $active_delivery['partner_logo'] ?>" alt="">
                <?php else: ?>
                    🏪
                <?php endif; ?>
            </div>
            <div class="active-info">
                <div class="active-store"><?= htmlspecialchars($active_delivery['partner_name'] ?? 'Mercado') ?></div>
                <div class="active-order-num">#<?= $active_delivery['order_number'] ?></div>
            </div>
        </div>
        <div class="active-address">
            <div class="address-label">📍 Entregar em</div>
            <div class="address-text"><?= htmlspecialchars($active_delivery['shipping_address'] ?? $active_delivery['delivery_address'] ?? 'Endereco') ?></div>
        </div>
        <div class="active-stats">
            <span>📦 <strong><?= $active_delivery['total_items'] ?? 0 ?></strong> itens</span>
            <span>💰 <strong><?= formatMoney($active_delivery['delivery_earning'] ?? 8) ?></strong></span>
        </div>
    </div>
    <?php else: ?>
    <!-- WAITING -->
    <div class="waiting-section" id="waiting-section">
        <div class="waiting-icon">📦</div>
        <div class="waiting-title">Buscando entregas...</div>
        <div class="waiting-text">Fique online para receber ofertas de entrega</div>
        <div class="waiting-pulse">
            <span></span><span></span><span></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if (count($available_offers) > 0): ?>
    <!-- OFFERS LIST -->
    <div class="section-title">📋 Ofertas Disponiveis (<?= count($available_offers) ?>)</div>
    <div class="offers-list">
        <?php foreach ($available_offers as $offer): ?>
        <div class="offer-item" onclick="showOffer(<?= htmlspecialchars(json_encode($offer)) ?>)">
            <div class="offer-top">
                <div class="offer-icon">
                    <?php if (!empty($offer['partner_logo'])): ?>
                        <img src="/image/<?= $offer['partner_logo'] ?>" alt="">
                    <?php else: ?>
                        🏪
                    <?php endif; ?>
                </div>
                <div class="offer-info">
                    <div class="offer-store"><?= htmlspecialchars($offer['partner_name'] ?? 'Mercado') ?></div>
                    <div class="offer-addr"><?= htmlspecialchars(substr($offer['partner_address'] ?? '', 0, 40)) ?>...</div>
                </div>
                <div class="offer-earning"><?= formatMoney($offer['delivery_earning'] ?? 8) ?></div>
            </div>
            <div class="offer-meta">
                <span class="offer-tag">📦 <?= $offer['total_items'] ?? 0 ?> itens</span>
                <span class="offer-tag">📍 ~3 km</span>
                <span class="offer-tag">⏱️ ~20 min</span>
            </div>
            <button class="offer-btn">🚴 Aceitar Entrega</button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- QUICK ACTIONS -->
    <div class="section-title">⚡ Acoes Rapidas</div>
    <div class="quick-actions">
        <a href="scanner.php" class="quick-btn">
            <div class="quick-icon">📷</div>
            <div class="quick-label">Escanear QR</div>
        </a>
        <a href="historico.php" class="quick-btn">
            <div class="quick-icon">📋</div>
            <div class="quick-label">Historico</div>
        </a>
        <a href="ganhos.php" class="quick-btn">
            <div class="quick-icon">💰</div>
            <div class="quick-label">Ganhos</div>
        </a>
        <a href="suporte.php" class="quick-btn">
            <div class="quick-icon">💬</div>
            <div class="quick-label">Suporte</div>
        </a>
    </div>

    <!-- BOTTOM NAV -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item active">
            <span class="icon">🏠</span>
            <span>Inicio</span>
        </a>
        <a href="ofertas.php" class="nav-item">
            <span class="icon">📦</span>
            <span>Ofertas</span>
        </a>
        <a href="ganhos.php" class="nav-item">
            <span class="icon">💰</span>
            <span>Ganhos</span>
        </a>
        <a href="perfil.php" class="nav-item">
            <span class="icon">👤</span>
            <span>Perfil</span>
        </a>
    </nav>

    <!-- OFFER OVERLAY -->
    <div class="offer-overlay" id="offer-overlay">
        <div class="offer-card" id="offer-card">
            <div class="offer-card-header">
                <div class="timer-ring-wrap">
                    <svg class="timer-ring" viewBox="0 0 120 120">
                        <circle class="timer-ring-bg" cx="60" cy="60" r="54"/>
                        <circle class="timer-ring-fill" id="timer-ring" cx="60" cy="60" r="54"/>
                    </svg>
                    <div class="timer-center">
                        <div class="timer-seconds" id="timer-seconds">60</div>
                        <div class="timer-label">segundos</div>
                    </div>
                </div>
                <div class="offer-card-title">🚴 Nova Entrega!</div>
            </div>

            <div class="offer-card-details">
                <div class="offer-card-store">
                    <div class="offer-card-logo" id="offer-logo">🏪</div>
                    <div class="offer-card-store-info">
                        <div class="offer-card-store-name" id="offer-store">Mercado</div>
                        <div class="offer-card-store-addr" id="offer-addr">Endereco</div>
                    </div>
                </div>

                <div class="offer-card-meta">
                    <div class="offer-meta-item">
                        <div class="offer-meta-icon">📦</div>
                        <div class="offer-meta-value" id="offer-items">0</div>
                        <div class="offer-meta-label">Itens</div>
                    </div>
                    <div class="offer-meta-item">
                        <div class="offer-meta-icon">📍</div>
                        <div class="offer-meta-value" id="offer-distance">~3 km</div>
                        <div class="offer-meta-label">Distancia</div>
                    </div>
                    <div class="offer-meta-item">
                        <div class="offer-meta-icon">⏱️</div>
                        <div class="offer-meta-value" id="offer-time">~20min</div>
                        <div class="offer-meta-label">Estimado</div>
                    </div>
                </div>

                <div class="offer-card-delivery">
                    <div class="offer-delivery-icon">👤</div>
                    <div class="offer-delivery-info">
                        <div class="offer-delivery-name" id="offer-customer">Cliente</div>
                        <div class="offer-delivery-addr" id="offer-delivery-addr">Endereco de entrega</div>
                    </div>
                </div>
            </div>

            <div class="offer-card-earnings">
                <div class="offer-earnings-label">Voce vai ganhar</div>
                <div class="offer-earnings-value" id="offer-earning">R$ 0,00</div>
            </div>

            <div class="offer-card-actions">
                <button class="btn-offer btn-reject" onclick="hideOffer()">✕ Recusar</button>
                <button class="btn-offer btn-accept" id="btn-accept" onclick="acceptOffer()">✓ Aceitar</button>
            </div>
        </div>
    </div>

    <script>
        const DELIVERY_ID = <?= $delivery_id ?>;
        let isOnline = <?= ($delivery['is_online'] ?? 0) ? 'true' : 'false' ?>;
        let currentOffer = null;
        let offerTimer = null;
        let offerSeconds = 60;

        function toggleStatus() {
            isOnline = !isOnline;
            document.getElementById('status-dot').classList.toggle('offline', !isOnline);
            document.getElementById('status-text').textContent = isOnline ? 'Online' : 'Offline';

            fetch('/mercado/api/delivery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update_status', delivery_id: DELIVERY_ID, is_online: isOnline ? 1 : 0 })
            }).catch(() => {});
        }

        function showOffer(offer) {
            currentOffer = offer;
            offerSeconds = 60;

            document.getElementById('offer-store').textContent = offer.partner_name || 'Mercado';
            document.getElementById('offer-addr').textContent = offer.partner_address || '';
            document.getElementById('offer-items').textContent = offer.total_items || '?';
            document.getElementById('offer-customer').textContent = offer.customer_name || 'Cliente';
            document.getElementById('offer-delivery-addr').textContent = offer.shipping_address || offer.delivery_address || '';
            document.getElementById('offer-earning').textContent = 'R$ ' + (parseFloat(offer.delivery_earning || 8)).toFixed(2).replace('.', ',');

            if (offer.partner_logo) {
                document.getElementById('offer-logo').innerHTML = `<img src="/image/${offer.partner_logo}">`;
            } else {
                document.getElementById('offer-logo').textContent = '🏪';
            }

            document.getElementById('offer-overlay').classList.add('show');
            startOfferTimer();

            if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
        }

        function hideOffer() {
            document.getElementById('offer-overlay').classList.remove('show');
            stopOfferTimer();
            currentOffer = null;
        }

        function startOfferTimer() {
            stopOfferTimer();

            const ring = document.getElementById('timer-ring');
            const secondsEl = document.getElementById('timer-seconds');
            const circumference = 2 * Math.PI * 54;

            offerTimer = setInterval(() => {
                offerSeconds--;
                secondsEl.textContent = offerSeconds;

                const offset = circumference - (offerSeconds / 60) * circumference;
                ring.style.strokeDashoffset = offset;

                if (offerSeconds <= 10) {
                    ring.classList.remove('warning');
                    ring.classList.add('danger');
                    secondsEl.classList.remove('warning');
                    secondsEl.classList.add('danger');
                } else if (offerSeconds <= 20) {
                    ring.classList.add('warning');
                    secondsEl.classList.add('warning');
                }

                if (offerSeconds <= 0) hideOffer();
            }, 1000);
        }

        function stopOfferTimer() {
            if (offerTimer) {
                clearInterval(offerTimer);
                offerTimer = null;
            }
            const ring = document.getElementById('timer-ring');
            const secondsEl = document.getElementById('timer-seconds');
            ring.style.strokeDashoffset = 0;
            ring.classList.remove('warning', 'danger');
            secondsEl.classList.remove('warning', 'danger');
            secondsEl.textContent = '60';
        }

        async function acceptOffer() {
            if (!currentOffer) return;

            const btn = document.getElementById('btn-accept');
            btn.disabled = true;
            btn.textContent = 'Aceitando...';

            try {
                const response = await fetch('/mercado/api/delivery.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'accept_delivery',
                        order_id: currentOffer.order_id,
                        delivery_id: DELIVERY_ID
                    })
                });

                const data = await response.json();

                if (data.success) {
                    window.location.href = 'entrega.php?order_id=' + currentOffer.order_id;
                } else {
                    alert(data.error || 'Erro ao aceitar entrega');
                    hideOffer();
                }
            } catch (e) {
                alert('Erro de conexao');
                hideOffer();
            }

            btn.disabled = false;
            btn.innerHTML = '✓ Aceitar';
        }

        // Poll for new offers every 10 seconds
        if (isOnline) {
            setInterval(async () => {
                try {
                    const r = await fetch('/mercado/api/delivery.php?action=get_offers&delivery_id=' + DELIVERY_ID);
                    const d = await r.json();
                    if (d.success && d.offers && d.offers.length > 0 && !currentOffer) {
                        showOffer(d.offers[0]);
                    }
                } catch (e) {}
            }, 10000);
        }
    </script>
</body>
</html>
