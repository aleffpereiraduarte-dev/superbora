<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$worker = getWorker();
$balance = getBalance($worker['worker_id']);
$pendingBalance = getPendingBalance($worker['worker_id']);
$transactions = getTransactions($worker['worker_id'], 30);
$withdrawals = getWithdrawals($worker['worker_id']);

$weekEarnings = getEarningsWeek($worker['worker_id']);
$monthEarnings = getEarningsMonth($worker['worker_id']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0AAD0A">
    <title>Carteira - OneMundo Shopper</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #0AAD0A;
            --green-dark: #089808;
            --green-light: #E8F5E9;
            --orange: #FF6B00;
            --blue: #2196F3;
            --red: #F44336;
            --gray-900: #212121;
            --gray-700: #616161;
            --gray-500: #9E9E9E;
            --gray-300: #E0E0E0;
            --gray-100: #F5F5F5;
            --white: #FFFFFF;
            --safe-top: env(safe-area-inset-top);
            --safe-bottom: env(safe-area-inset-bottom);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--gray-100);
            color: var(--gray-900);
            min-height: 100vh;
            padding-bottom: calc(80px + var(--safe-bottom));
        }
        
        .header {
            background: linear-gradient(135deg, var(--green) 0%, var(--green-dark) 100%);
            padding: calc(16px + var(--safe-top)) 20px 100px;
            position: relative;
        }
        
        .header-top {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .back-btn {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.15);
            border: none;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--white);
        }
        
        .header-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--white);
        }
        
        .wallet-card {
            background: var(--white);
            border-radius: 24px;
            margin: -70px 16px 20px;
            padding: 28px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            position: relative;
            z-index: 10;
        }
        
        .wallet-balance {
            text-align: center;
            margin-bottom: 24px;
        }
        
        .wallet-label {
            font-size: 14px;
            color: var(--gray-500);
            margin-bottom: 8px;
        }
        
        .wallet-value {
            font-size: 42px;
            font-weight: 800;
            color: var(--gray-900);
            letter-spacing: -2px;
        }
        
        .wallet-value small {
            font-size: 24px;
            font-weight: 600;
            color: var(--gray-500);
        }
        
        .wallet-pending {
            font-size: 14px;
            color: var(--orange);
            margin-top: 8px;
        }
        
        .wallet-actions {
            display: flex;
            gap: 12px;
        }
        
        .wallet-btn {
            flex: 1;
            padding: 16px;
            border: none;
            border-radius: 16px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .wallet-btn.primary {
            background: var(--green);
            color: var(--white);
        }
        
        .wallet-btn.secondary {
            background: var(--gray-100);
            color: var(--gray-900);
        }
        
        .stats-row {
            display: flex;
            gap: 12px;
            padding: 0 16px;
            margin-bottom: 24px;
        }
        
        .stat-box {
            flex: 1;
            background: var(--white);
            border-radius: 16px;
            padding: 16px;
            text-align: center;
        }
        
        .stat-box-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }
        
        .stat-box-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .stat-box-label {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 4px;
        }
        
        .section {
            padding: 0 16px;
            margin-bottom: 24px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 16px;
        }
        
        .transaction-list {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
        }
        
        .transaction-item {
            display: flex;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .transaction-item:last-child {
            border-bottom: none;
        }
        
        .transaction-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-right: 14px;
        }
        
        .transaction-icon.earning {
            background: var(--green-light);
        }
        
        .transaction-icon.withdrawal {
            background: #FFF3E0;
        }
        
        .transaction-info {
            flex: 1;
        }
        
        .transaction-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 2px;
        }
        
        .transaction-date {
            font-size: 12px;
            color: var(--gray-500);
        }
        
        .transaction-amount {
            font-size: 16px;
            font-weight: 700;
        }
        
        .transaction-amount.positive {
            color: var(--green);
        }
        
        .transaction-amount.negative {
            color: var(--red);
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            background: var(--white);
            border-radius: 20px;
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .empty-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .empty-text {
            font-size: 14px;
            color: var(--gray-500);
        }
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            border-top: 1px solid var(--gray-300);
            padding: 8px 0;
            padding-bottom: calc(8px + var(--safe-bottom));
            z-index: 100;
        }
        
        .nav-items {
            display: flex;
            justify-content: space-around;
            max-width: 500px;
            margin: 0 auto;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 8px 16px;
            text-decoration: none;
            color: var(--gray-500);
        }
        
        .nav-item.active {
            color: var(--green);
        }
        
        .nav-item svg {
            width: 24px;
            height: 24px;
        }
        
        .nav-item span {
            font-size: 11px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<header class="header">
    <div class="header-top">
        <button class="back-btn" onclick="history.back()">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <h1 class="header-title">Carteira</h1>
    </div>
</header>

<div class="wallet-card">
    <div class="wallet-balance">
        <div class="wallet-label">Saldo disponível</div>
        <div class="wallet-value">
            <small>R$</small> <?= number_format($balance, 2, ',', '.') ?>
        </div>
        <?php if ($pendingBalance > 0): ?>
        <div class="wallet-pending">+ <?= formatMoney($pendingBalance) ?> pendente</div>
        <?php endif; ?>
    </div>
    <div class="wallet-actions">
        <a href="saque.php" class="wallet-btn primary">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Sacar via PIX
        </a>
        <a href="dados-bancarios.php" class="wallet-btn secondary">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Dados PIX
        </a>
    </div>
</div>

<div class="stats-row">
    <div class="stat-box">
        <div class="stat-box-icon">📅</div>
        <div class="stat-box-value"><?= formatMoney($weekEarnings) ?></div>
        <div class="stat-box-label">Esta semana</div>
    </div>
    <div class="stat-box">
        <div class="stat-box-icon">📆</div>
        <div class="stat-box-value"><?= formatMoney($monthEarnings) ?></div>
        <div class="stat-box-label">Este mês</div>
    </div>
</div>

<div class="section">
    <h2 class="section-title">Histórico</h2>
    
    <?php if (empty($transactions)): ?>
    <div class="empty-state">
        <div class="empty-icon">💳</div>
        <div class="empty-title">Nenhuma transação ainda</div>
        <div class="empty-text">Suas transações aparecerão aqui</div>
    </div>
    <?php else: ?>
    <div class="transaction-list">
        <?php foreach ($transactions as $tx): ?>
        <div class="transaction-item">
            <div class="transaction-icon <?= $tx['type'] ?>">
                <?= $tx['type'] === 'earning' ? '💰' : ($tx['type'] === 'withdrawal' ? '📤' : '💳') ?>
            </div>
            <div class="transaction-info">
                <div class="transaction-title"><?= sanitize($tx['description'] ?: 'Transação') ?></div>
                <div class="transaction-date"><?= date('d/m/Y H:i', strtotime($tx['created_at'])) ?></div>
            </div>
            <div class="transaction-amount <?= $tx['amount'] > 0 ? 'positive' : 'negative' ?>">
                <?= $tx['amount'] > 0 ? '+' : '' ?><?= formatMoney($tx['amount']) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<nav class="bottom-nav">
    <div class="nav-items">
        <a href="dashboard.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span>Home</span>
        </a>
        <a href="ofertas.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
            <span>Ofertas</span>
        </a>
        <a href="carteira.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            <span>Carteira</span>
        </a>
        <a href="pedidos.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>Histórico</span>
        </a>
        <a href="perfil.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <span>Perfil</span>
        </a>
    </div>
</nav>

</body>
</html>