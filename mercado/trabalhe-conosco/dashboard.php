<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$worker = getWorker();
$firstName = explode(' ', $worker['name'])[0];
$balance = getBalance($worker['worker_id']);
$pending = getPendingBalance($worker['worker_id']);
$todayEarnings = getEarningsToday($worker['worker_id']);
$todayOrders = getOrdersToday($worker['worker_id']);
$weekEarnings = getEarningsWeek($worker['worker_id']);
$weekOrders = getOrdersWeek($worker['worker_id']);
$offers = getAvailableOffers($worker['worker_id']);
$activeOrder = getActiveOrder($worker['worker_id']);
$isOnline = $worker['is_online'] ?? 0;
$rating = $worker['rating'] ?? 5.0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#108910">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>OneMundo Shopper</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Instacart Colors */
            --ic-green: #108910;
            --ic-green-dark: #0D6B0D;
            --ic-green-light: #E8F5E8;
            --ic-orange: #FF5500;
            --ic-orange-light: #FFF3ED;
            --ic-black: #1C1C1C;
            --ic-gray-900: #2D2D2D;
            --ic-gray-700: #5C5C5C;
            --ic-gray-500: #8B8B8B;
            --ic-gray-300: #C7C7C7;
            --ic-gray-100: #F6F6F6;
            --ic-white: #FFFFFF;
            --ic-shadow: 0 2px 8px rgba(0,0,0,0.08);
            --ic-shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        
        html { height: 100%; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--ic-gray-100);
            color: var(--ic-black);
            min-height: 100%;
            padding-top: var(--safe-top);
            padding-bottom: calc(72px + var(--safe-bottom));
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* TOP BAR - Estilo Instacart */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .topbar {
            background: var(--ic-white);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--ic-gray-300);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--ic-green) 0%, var(--ic-green-dark) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            color: var(--ic-white);
        }
        
        .greeting {
            font-size: 15px;
            font-weight: 600;
            color: var(--ic-black);
        }
        
        .rating-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--ic-gray-100);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            color: var(--ic-gray-700);
        }
        
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .icon-btn {
            width: 40px;
            height: 40px;
            background: transparent;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .icon-btn:hover { background: var(--ic-gray-100); }
        
        .icon-btn svg {
            width: 24px;
            height: 24px;
            color: var(--ic-gray-700);
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* GO ONLINE - Grande e Central (Estilo Instacart) */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .online-section {
            padding: 20px 16px;
        }
        
        .online-card {
            background: var(--ic-white);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--ic-shadow);
        }
        
        .online-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .online-status {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--ic-gray-300);
        }
        
        .status-dot.active {
            background: var(--ic-green);
            box-shadow: 0 0 0 4px rgba(16, 137, 16, 0.2);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 4px rgba(16, 137, 16, 0.2); }
            50% { box-shadow: 0 0 0 8px rgba(16, 137, 16, 0.1); }
        }
        
        .status-text h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--ic-black);
        }
        
        .status-text p {
            font-size: 14px;
            color: var(--ic-gray-500);
            margin-top: 2px;
        }
        
        /* Toggle Instacart Style */
        .toggle-switch {
            position: relative;
            width: 56px;
            height: 32px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-track {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: var(--ic-gray-300);
            border-radius: 32px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .toggle-track::before {
            position: absolute;
            content: '';
            height: 28px;
            width: 28px;
            left: 2px;
            top: 2px;
            background: var(--ic-white);
            border-radius: 50%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .toggle-switch input:checked + .toggle-track {
            background: var(--ic-green);
        }
        
        .toggle-switch input:checked + .toggle-track::before {
            transform: translateX(24px);
        }
        
        /* Earnings Summary in Online Card */
        .earnings-preview {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding-top: 16px;
            border-top: 1px solid var(--ic-gray-100);
        }
        
        .earning-item {
            text-align: center;
        }
        
        .earning-item .value {
            font-size: 24px;
            font-weight: 800;
            color: var(--ic-black);
        }
        
        .earning-item .label {
            font-size: 12px;
            color: var(--ic-gray-500);
            margin-top: 4px;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* ACTIVE ORDER BANNER */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .active-banner {
            margin: 0 16px 16px;
            background: linear-gradient(135deg, var(--ic-orange) 0%, #E64A00 100%);
            border-radius: 16px;
            padding: 20px;
            color: var(--ic-white);
            position: relative;
            overflow: hidden;
        }
        
        .active-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        
        .active-banner-content {
            position: relative;
            z-index: 1;
        }
        
        .active-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.2);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .active-badge .dot {
            width: 8px;
            height: 8px;
            background: var(--ic-white);
            border-radius: 50%;
            animation: blink 1s infinite;
        }
        
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .active-banner h3 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .active-banner p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .active-banner-btn {
            display: block;
            background: var(--ic-white);
            color: var(--ic-orange);
            text-decoration: none;
            text-align: center;
            padding: 14px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 15px;
            margin-top: 16px;
            transition: transform 0.2s;
        }
        
        .active-banner-btn:active { transform: scale(0.98); }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* BALANCE CARD - Estilo Clean */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .balance-section {
            padding: 0 16px 16px;
        }
        
        .balance-card {
            background: var(--ic-white);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--ic-shadow);
        }
        
        .balance-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .balance-info .label {
            font-size: 13px;
            color: var(--ic-gray-500);
            margin-bottom: 4px;
        }
        
        .balance-info .amount {
            font-size: 32px;
            font-weight: 800;
            color: var(--ic-black);
            letter-spacing: -1px;
        }
        
        .balance-info .pending {
            font-size: 13px;
            color: var(--ic-orange);
            margin-top: 4px;
        }
        
        .balance-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        
        .btn:active { transform: scale(0.98); }
        
        .btn-primary {
            background: var(--ic-green);
            color: var(--ic-white);
        }
        
        .btn-secondary {
            background: var(--ic-gray-100);
            color: var(--ic-black);
        }
        
        .btn svg {
            width: 18px;
            height: 18px;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* STATS GRID */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .stats-section {
            padding: 0 16px 16px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }
        
        .stat-item {
            background: var(--ic-white);
            border-radius: 12px;
            padding: 14px 8px;
            text-align: center;
            box-shadow: var(--ic-shadow);
        }
        
        .stat-icon {
            font-size: 20px;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--ic-black);
        }
        
        .stat-label {
            font-size: 10px;
            color: var(--ic-gray-500);
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* SECTION HEADER */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            margin-bottom: 12px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--ic-black);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title .count {
            background: var(--ic-green);
            color: var(--ic-white);
            font-size: 12px;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 20px;
        }
        
        .section-link {
            font-size: 14px;
            color: var(--ic-green);
            text-decoration: none;
            font-weight: 600;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* BATCH CARDS - Estilo Instacart Premium */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .batches-section {
            padding: 0 16px 20px;
        }
        
        .batch-card {
            background: var(--ic-white);
            border-radius: 16px;
            margin-bottom: 12px;
            box-shadow: var(--ic-shadow);
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .batch-card:active {
            transform: scale(0.99);
            box-shadow: var(--ic-shadow-lg);
        }
        
        .batch-header {
            padding: 16px;
            border-bottom: 1px solid var(--ic-gray-100);
        }
        
        .batch-store {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .store-logo {
            width: 48px;
            height: 48px;
            background: var(--ic-gray-100);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            overflow: hidden;
        }
        
        .store-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .store-info {
            flex: 1;
        }
        
        .store-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--ic-black);
        }
        
        .store-address {
            font-size: 13px;
            color: var(--ic-gray-500);
            margin-top: 2px;
        }
        
        .batch-earning {
            text-align: right;
        }
        
        .batch-earning .amount {
            font-size: 22px;
            font-weight: 800;
            color: var(--ic-green);
        }
        
        .batch-earning .label {
            font-size: 11px;
            color: var(--ic-gray-500);
        }
        
        .batch-details {
            display: flex;
            padding: 12px 16px;
            gap: 16px;
            background: var(--ic-gray-100);
        }
        
        .batch-detail {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--ic-gray-700);
        }
        
        .batch-detail svg {
            width: 16px;
            height: 16px;
            color: var(--ic-gray-500);
        }
        
        .batch-tags {
            display: flex;
            gap: 8px;
            padding: 12px 16px 0;
        }
        
        .batch-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .batch-tag.tip {
            background: var(--ic-green-light);
            color: var(--ic-green);
        }
        
        .batch-tag.boost {
            background: var(--ic-orange-light);
            color: var(--ic-orange);
        }
        
        .batch-actions {
            display: flex;
            padding: 16px;
            gap: 10px;
        }
        
        .batch-btn {
            flex: 1;
            padding: 14px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        
        .batch-btn:active { transform: scale(0.98); }
        
        .batch-btn.accept {
            background: var(--ic-green);
            color: var(--ic-white);
        }
        
        .batch-btn.skip {
            background: var(--ic-gray-100);
            color: var(--ic-gray-700);
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* EMPTY STATE */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .empty-state {
            background: var(--ic-white);
            border-radius: 16px;
            padding: 48px 24px;
            text-align: center;
            box-shadow: var(--ic-shadow);
        }
        
        .empty-icon {
            width: 80px;
            height: 80px;
            background: var(--ic-gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin: 0 auto 20px;
        }
        
        .empty-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--ic-black);
            margin-bottom: 8px;
        }
        
        .empty-text {
            font-size: 14px;
            color: var(--ic-gray-500);
            line-height: 1.5;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* BOTTOM NAV - Estilo Instacart */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--ic-white);
            border-top: 1px solid var(--ic-gray-300);
            padding-bottom: var(--safe-bottom);
            z-index: 100;
        }
        
        .nav-items {
            display: flex;
            height: 72px;
        }
        
        .nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            text-decoration: none;
            color: var(--ic-gray-500);
            font-size: 11px;
            font-weight: 500;
            transition: color 0.2s;
            position: relative;
        }
        
        .nav-item.active {
            color: var(--ic-green);
        }
        
        .nav-item.active::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 48px;
            height: 3px;
            background: var(--ic-green);
            border-radius: 0 0 3px 3px;
        }
        
        .nav-item svg {
            width: 26px;
            height: 26px;
        }
        
        .nav-item span {
            font-weight: 600;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* TOAST */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--ic-black);
            color: var(--ic-white);
            padding: 14px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            box-shadow: var(--ic-shadow-lg);
        }
        
        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        
        .toast.success { background: var(--ic-green); }
        .toast.error { background: #dc2626; }
    </style>
</head>
<body>

<!-- Top Bar -->
<header class="topbar">
    <div class="topbar-left">
        <div class="avatar"><?= strtoupper(substr($firstName, 0, 1)) ?></div>
        <div>
            <div class="greeting">Olá, <?= sanitize($firstName) ?></div>
            <span class="rating-badge">⭐ <?= number_format($rating, 1) ?></span>
        </div>
    </div>
    <div class="topbar-right">
        <a href="notificacoes.php" class="icon-btn">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        </a>
        <a href="ajuda.php" class="icon-btn">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </a>
    </div>
</header>

<!-- Go Online Section -->
<section class="online-section">
    <div class="online-card">
        <div class="online-header">
            <div class="online-status">
                <div class="status-dot <?= $isOnline ? 'active' : '' ?>" id="statusDot"></div>
                <div class="status-text">
                    <h3 id="statusTitle"><?= $isOnline ? 'Você está online' : 'Você está offline' ?></h3>
                    <p id="statusDesc"><?= $isOnline ? 'Recebendo ofertas de pedidos' : 'Fique online para receber ofertas' ?></p>
                </div>
            </div>
            <label class="toggle-switch">
                <input type="checkbox" id="onlineToggle" <?= $isOnline ? 'checked' : '' ?> onchange="toggleOnline(this.checked)">
                <span class="toggle-track"></span>
            </label>
        </div>
        <div class="earnings-preview">
            <div class="earning-item">
                <div class="value"><?= formatMoney($todayEarnings) ?></div>
                <div class="label">Ganhos Hoje</div>
            </div>
            <div class="earning-item">
                <div class="value"><?= $todayOrders ?></div>
                <div class="label">Pedidos Hoje</div>
            </div>
        </div>
    </div>
</section>

<?php if ($activeOrder): ?>
<!-- Active Order Banner -->
<div class="active-banner">
    <div class="active-banner-content">
        <span class="active-badge"><span class="dot"></span> EM ANDAMENTO</span>
        <h3><?= sanitize($activeOrder['partner_name']) ?></h3>
        <p><?= $activeOrder['item_count'] ?> itens • #<?= $activeOrder['order_number'] ?></p>
        <a href="shopping.php?id=<?= $activeOrder['order_id'] ?>" class="active-banner-btn">
            Continuar Pedido →
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Balance Section -->
<section class="balance-section">
    <div class="balance-card">
        <div class="balance-top">
            <div class="balance-info">
                <div class="label">Saldo disponível</div>
                <div class="amount"><?= formatMoney($balance) ?></div>
                <?php if ($pending > 0): ?>
                <div class="pending">+ <?= formatMoney($pending) ?> pendente</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="balance-actions">
            <a href="saque.php" class="btn btn-primary">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                Sacar
            </a>
            <a href="carteira.php" class="btn btn-secondary">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                Histórico
            </a>
        </div>
    </div>
</section>

<!-- Stats Grid -->
<section class="stats-section">
    <div class="stats-grid">
        <div class="stat-item">
            <div class="stat-icon">💰</div>
            <div class="stat-value"><?= formatMoney($weekEarnings) ?></div>
            <div class="stat-label">Semana</div>
        </div>
        <div class="stat-item">
            <div class="stat-icon">📦</div>
            <div class="stat-value"><?= $weekOrders ?></div>
            <div class="stat-label">Pedidos</div>
        </div>
        <div class="stat-item">
            <div class="stat-icon">⭐</div>
            <div class="stat-value"><?= number_format($rating, 1) ?></div>
            <div class="stat-label">Rating</div>
        </div>
        <div class="stat-item">
            <div class="stat-icon">🏆</div>
            <div class="stat-value"><?= $worker['level'] ?? 1 ?></div>
            <div class="stat-label">Nível</div>
        </div>
    </div>
</section>

<!-- Available Batches -->
<div class="section-header">
    <h2 class="section-title">
        Pedidos Disponíveis
        <?php if (count($offers) > 0): ?>
        <span class="count"><?= count($offers) ?></span>
        <?php endif; ?>
    </h2>
    <a href="ofertas.php" class="section-link">Ver todos</a>
</div>

<section class="batches-section">
    <?php if (empty($offers)): ?>
    <div class="empty-state">
        <div class="empty-icon">🛒</div>
        <div class="empty-title">Nenhum pedido disponível</div>
        <div class="empty-text">Fique online e novos pedidos aparecerão aqui quando disponíveis</div>
    </div>
    <?php else: ?>
    <?php foreach (array_slice($offers, 0, 5) as $offer): 
        $earning = $offer['shopper_earning'] ?? $offer['shopper_earnings'] ?? 15;
        $tip = rand(5, 20); // Simulado - você pode pegar do banco
    ?>
    <div class="batch-card" data-order="<?= $offer['order_id'] ?>">
        <div class="batch-header">
            <div class="batch-store">
                <div class="store-logo">
                    <?php if ($offer['partner_logo']): ?>
                    <img src="<?= $offer['partner_logo'] ?>" alt="">
                    <?php else: ?>
                    🏪
                    <?php endif; ?>
                </div>
                <div class="store-info">
                    <div class="store-name"><?= sanitize($offer['partner_name'] ?? 'Mercado') ?></div>
                    <div class="store-address"><?= sanitize(substr($offer['partner_address'] ?? 'Endereço não informado', 0, 35)) ?>...</div>
                </div>
                <div class="batch-earning">
                    <div class="amount"><?= formatMoney($earning) ?></div>
                    <div class="label">Ganho</div>
                </div>
            </div>
        </div>
        <div class="batch-details">
            <div class="batch-detail">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                <?= $offer['item_count'] ?> itens
            </div>
            <div class="batch-detail">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                2.3 km
            </div>
            <div class="batch-detail">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                ~35 min
            </div>
        </div>
        <div class="batch-tags">
            <span class="batch-tag tip">💵 + <?= formatMoney($tip) ?> gorjeta</span>
            <?php if (rand(0, 1)): ?>
            <span class="batch-tag boost">🔥 Boost ativo</span>
            <?php endif; ?>
        </div>
        <div class="batch-actions">
            <button class="batch-btn skip" onclick="skipBatch(<?= $offer['order_id'] ?>)">Pular</button>
            <button class="batch-btn accept" onclick="acceptBatch(<?= $offer['order_id'] ?>)">Aceitar</button>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</section>

<!-- Bottom Navigation -->
<nav class="bottom-nav">
    <div class="nav-items">
        <a href="dashboard.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span>Home</span>
        </a>
        <a href="ofertas.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
            <span>Pedidos</span>
        </a>
        <a href="ganhos.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>Ganhos</span>
        </a>
        <a href="carteira.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            <span>Carteira</span>
        </a>
        <a href="perfil.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <span>Conta</span>
        </a>
    </div>
</nav>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 3000);
}

function toggleOnline(on) {
    fetch('api/toggle-online.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({is_online: on})
    }).then(r => r.json()).then(d => {
        if (d.success) {
            document.getElementById('statusDot').className = on ? 'status-dot active' : 'status-dot';
            document.getElementById('statusTitle').textContent = on ? 'Você está online' : 'Você está offline';
            document.getElementById('statusDesc').textContent = on ? 'Recebendo ofertas de pedidos' : 'Fique online para receber ofertas';
            showToast(on ? 'Você está online!' : 'Você está offline');
        }
    });
}

function acceptBatch(id) {
    const card = document.querySelector(`[data-order="${id}"]`);
    card.style.opacity = '0.5';
    card.style.pointerEvents = 'none';
    
    fetch('api/accept-offer.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({order_id: id})
    }).then(r => r.json()).then(d => {
        if (d.success) {
            showToast('Pedido aceito! Boas compras!');
            setTimeout(() => location.href = 'shopping.php?id=' + id, 500);
        } else {
            card.style.opacity = '1';
            card.style.pointerEvents = 'auto';
            showToast(d.error || 'Erro ao aceitar', 'error');
        }
    });
}

function skipBatch(id) {
    const card = document.querySelector(`[data-order="${id}"]`);
    card.style.transition = 'all 0.3s';
    card.style.transform = 'translateX(-100%)';
    card.style.opacity = '0';
    
    fetch('api/reject-offer.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({order_id: id})
    }).then(() => {
        setTimeout(() => card.remove(), 300);
    });
}

// GPS quando online
if (navigator.geolocation && document.getElementById('onlineToggle').checked) {
    navigator.geolocation.watchPosition(p => {
        fetch('api/update-location.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({lat: p.coords.latitude, lng: p.coords.longitude})
        });
    }, null, {enableHighAccuracy: true, maximumAge: 30000});
}
</script>

</body>
</html>