<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * 💰 GANHOS WORKER - ONEMUNDO MERCADO
 * Upload em: /mercado/worker/ganhos.php
 */

session_start();
error_reporting(0);

if (!isset($_SESSION['worker_id'])) {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$workerId = $_SESSION['worker_id'];

// Buscar worker
$worker = $pdo->query("SELECT * FROM om_market_workers WHERE worker_id = $workerId")->fetch(PDO::FETCH_ASSOC);

// Stats
$stats = [
    'saldo' => $worker['balance'] ?? 0,
    'total' => $worker['total_earned'] ?? 0,
    'hoje' => $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM om_market_worker_earnings WHERE worker_id = $workerId AND DATE(created_at) = CURRENT_DATE")->fetchColumn(),
    'semana' => $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM om_market_worker_earnings WHERE worker_id = $workerId AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
    'mes' => $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM om_market_worker_earnings WHERE worker_id = $workerId AND MONTH(created_at) = MONTH(NOW())")->fetchColumn(),
];

// Histórico
$historico = $pdo->query("
    SELECT * FROM om_market_worker_earnings 
    WHERE worker_id = $workerId 
    ORDER BY created_at DESC 
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por dia
$porDia = [];
foreach ($historico as $h) {
    $dia = date('Y-m-d', strtotime($h['created_at']));
    if (!isset($porDia[$dia])) {
        $porDia[$dia] = ['items' => [], 'total' => 0];
    }
    $porDia[$dia]['items'][] = $h;
    $porDia[$dia]['total'] += $h['amount'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ganhos - Worker OneMundo</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #00B04F;
            --secondary: #FF6B35;
            --dark: #1A1A1A;
            --gray: #6B7280;
            --light: #F8F9FA;
            --white: #FFFFFF;
            --radius: 12px;
            --shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--light);
            color: var(--dark);
            min-height: 100vh;
            padding-bottom: 80px;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary), #009640);
            color: var(--white);
            padding: 20px;
            padding-top: 30px;
            padding-bottom: 80px;
        }
        .header h1 { font-size: 1rem; opacity: 0.9; margin-bottom: 10px; }
        .header .balance {
            font-size: 2.5rem;
            font-weight: 700;
        }
        .header .balance-label {
            font-size: 0.85rem;
            opacity: 0.8;
            margin-top: 5px;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 0 20px;
            margin-top: -60px;
        }
        
        .withdraw-btn {
            display: block;
            width: 100%;
            padding: 16px;
            background: var(--white);
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 1rem;
            color: var(--primary);
            cursor: pointer;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        .withdraw-btn:hover { background: #f0f0f0; }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 16px 12px;
            text-align: center;
            box-shadow: var(--shadow);
        }
        .stat-value { font-size: 1.2rem; font-weight: 700; color: var(--primary); }
        .stat-label { font-size: 0.7rem; color: var(--gray); margin-top: 4px; }
        
        .section-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--dark);
        }
        
        .day-group {
            margin-bottom: 20px;
        }
        .day-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding: 0 5px;
        }
        .day-date { font-size: 0.85rem; color: var(--gray); font-weight: 500; }
        .day-total { font-size: 0.9rem; font-weight: 700; color: var(--primary); }
        
        .earning-item {
            background: var(--white);
            border-radius: var(--radius);
            padding: 14px 16px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow);
        }
        .earning-info { display: flex; gap: 12px; align-items: center; }
        .earning-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .earning-icon.delivery { background: #dbeafe; }
        .earning-icon.shopping { background: #d1fae5; }
        .earning-icon.bonus { background: #fef3c7; }
        .earning-details h4 { font-size: 0.9rem; font-weight: 600; }
        .earning-details p { font-size: 0.75rem; color: var(--gray); }
        .earning-amount { font-weight: 700; color: var(--primary); }
        
        .empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }
        
        .nav-bottom {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            color: var(--gray);
            font-size: 0.75rem;
        }
        .nav-item.active { color: var(--primary); }
        .nav-item span { font-size: 1.3rem; }
    </style>
</head>
<body>
    <header class="header">
        <h1>💰 Seus Ganhos</h1>
        <div class="balance">R$ <?= number_format($stats['saldo'], 2, ',', '.') ?></div>
        <div class="balance-label">Saldo disponível para saque</div>
    </header>
    
    <div class="container">
        
        <button class="withdraw-btn">
            💳 Sacar para conta bancária
        </button>
        
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($stats['hoje'], 0, ',', '.') ?></div>
                <div class="stat-label">Hoje</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($stats['semana'], 0, ',', '.') ?></div>
                <div class="stat-label">Esta Semana</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($stats['mes'], 0, ',', '.') ?></div>
                <div class="stat-label">Este Mês</div>
            </div>
        </div>
        
        <div class="section-title">📋 Histórico de Ganhos</div>
        
        <?php if (empty($historico)): ?>
        <div class="empty">
            <p>Nenhum ganho registrado ainda</p>
            <p style="font-size: 0.85rem; margin-top: 10px;">Complete pedidos para começar a ganhar!</p>
        </div>
        <?php else: ?>
        
        <?php foreach ($porDia as $dia => $dados): ?>
        <div class="day-group">
            <div class="day-header">
                <span class="day-date">
                    <?php
                    if ($dia == date('Y-m-d')) echo 'Hoje';
                    elseif ($dia == date('Y-m-d', strtotime('-1 day'))) echo 'Ontem';
                    else echo date('d/m', strtotime($dia));
                    ?>
                </span>
                <span class="day-total">+ R$ <?= number_format($dados['total'], 2, ',', '.') ?></span>
            </div>
            
            <?php foreach ($dados['items'] as $item): ?>
            <div class="earning-item">
                <div class="earning-info">
                    <div class="earning-icon <?= $item['type'] ?>">
                        <?= $item['type'] == 'delivery' ? '🚗' : ($item['type'] == 'shopping' ? '🛒' : '⭐') ?>
                    </div>
                    <div class="earning-details">
                        <h4><?= $item['description'] ?: ucfirst($item['type']) ?></h4>
                        <p><?= date('H:i', strtotime($item['created_at'])) ?> • Pedido #<?= $item['order_id'] ?></p>
                    </div>
                </div>
                <span class="earning-amount">+ R$ <?= number_format($item['amount'], 2, ',', '.') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        
        <?php endif; ?>
        
    </div>
    
    <nav class="nav-bottom">
        <a href="index.php" class="nav-item"><span>🏠</span>Início</a>
        <a href="pedidos.php" class="nav-item"><span>📦</span>Pedidos</a>
        <a href="ganhos.php" class="nav-item active"><span>💰</span>Ganhos</a>
        <a href="perfil.php" class="nav-item"><span>👤</span>Perfil</a>
    </nav>
</body>
</html>
