<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * 📦 PEDIDOS WORKER - ONEMUNDO MERCADO
 * Upload em: /mercado/worker/pedidos.php
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

// Aceitar pedido
if (isset($_POST['aceitar'])) {
    $orderId = intval($_POST['order_id']);
    $pdo->prepare("UPDATE om_market_orders SET shopper_id = ?, status = 'shopping' WHERE order_id = ? AND shopper_id IS NULL")
        ->execute([$workerId, $orderId]);
    header("Location: ?");
    exit;
}

// Pedidos disponíveis
$disponiveis = $pdo->query("
    SELECT o.*, p.name as partner_name 
    FROM om_market_orders o 
    LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
    WHERE o.status = 'paid' AND o.shopper_id IS NULL 
    ORDER BY o.created_at DESC 
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Meus pedidos ativos
$meusAtivos = $pdo->query("
    SELECT o.*, p.name as partner_name 
    FROM om_market_orders o 
    LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
    WHERE (o.shopper_id = $workerId OR o.delivery_driver_id = $workerId)
    AND o.status NOT IN ('delivered', 'cancelled')
    ORDER BY o.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos - Worker OneMundo</title>
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
            background: var(--primary);
            color: var(--white);
            padding: 16px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header h1 { font-size: 1.2rem; }
        
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: var(--white);
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            color: var(--gray);
        }
        .tab.active { background: var(--primary); color: var(--white); }
        
        .section-title {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .badge {
            background: var(--secondary);
            color: var(--white);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
        }
        
        .order-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow);
        }
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .order-id { font-weight: 700; font-size: 1rem; }
        .order-time { font-size: 0.75rem; color: var(--gray); }
        .order-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .order-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 12px;
            font-size: 0.85rem;
        }
        .order-info div { display: flex; gap: 8px; align-items: center; }
        .order-info span { color: var(--gray); }
        
        .order-items {
            background: var(--light);
            padding: 10px;
            border-radius: 8px;
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 12px;
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            font-size: 0.95rem;
        }
        .btn-accept { background: var(--primary); color: var(--white); }
        .btn-accept:hover { background: #009640; }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .status-shopping { background: #dbeafe; color: #1d4ed8; }
        .status-delivering { background: #fef3c7; color: #d97706; }
        
        .empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }
        .empty-icon { font-size: 3rem; margin-bottom: 15px; }
        
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
        <h1>📦 Pedidos</h1>
    </header>
    
    <div class="container">
        
        <!-- Meus Pedidos Ativos -->
        <?php if (!empty($meusAtivos)): ?>
        <div class="section-title">
            🔥 Meus Pedidos Ativos
            <span class="badge"><?= count($meusAtivos) ?></span>
        </div>
        
        <?php foreach ($meusAtivos as $o): ?>
        <div class="order-card">
            <div class="order-header">
                <div>
                    <div class="order-id">Pedido #<?= $o['order_id'] ?></div>
                    <div class="order-time"><?= date('H:i', strtotime($o['created_at'])) ?></div>
                </div>
                <span class="status-badge status-<?= $o['status'] ?>"><?= strtoupper($o['status']) ?></span>
            </div>
            <div class="order-info">
                <div>🏪 <span><?= $o['partner_name'] ?: 'Mercado' ?></span></div>
                <div>📍 <span><?= $o['delivery_address'] ?: $o['delivery_city'] ?></span></div>
                <div>👤 <span><?= $o['customer_name'] ?: 'Cliente' ?></span></div>
            </div>
            <div class="order-value">R$ <?= number_format($o['total'], 2, ',', '.') ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Pedidos Disponíveis -->
        <div class="section-title" style="margin-top: 25px;">
            📦 Pedidos Disponíveis
            <span class="badge"><?= count($disponiveis) ?></span>
        </div>
        
        <?php if (empty($disponiveis)): ?>
        <div class="empty">
            <div class="empty-icon">😴</div>
            <p>Nenhum pedido disponível no momento</p>
            <p style="font-size: 0.85rem; margin-top: 10px;">Fique online para receber ofertas!</p>
        </div>
        <?php else: ?>
        
        <?php foreach ($disponiveis as $o): ?>
        <div class="order-card">
            <div class="order-header">
                <div>
                    <div class="order-id">Pedido #<?= $o['order_id'] ?></div>
                    <div class="order-time"><?= date('H:i', strtotime($o['created_at'])) ?> • há <?= floor((time() - strtotime($o['created_at'])) / 60) ?> min</div>
                </div>
                <div class="order-value">R$ <?= number_format($o['total'], 2, ',', '.') ?></div>
            </div>
            <div class="order-info">
                <div>🏪 <span><?= $o['partner_name'] ?: 'Mercado' ?></span></div>
                <div>📍 <span><?= $o['delivery_address'] ?: $o['delivery_city'] ?></span></div>
            </div>
            <div class="order-items">
                Ganho estimado: <b style="color: var(--primary);">R$ <?= number_format($o['total'] * 0.08 + rand(5, 12), 2, ',', '.') ?></b>
            </div>
            <form method="POST">
                <input type="hidden" name="order_id" value="<?= $o['order_id'] ?>">
                <button type="submit" name="aceitar" class="btn btn-accept">✅ Aceitar Pedido</button>
            </form>
        </div>
        <?php endforeach; ?>
        
        <?php endif; ?>
    </div>
    
    <nav class="nav-bottom">
        <a href="index.php" class="nav-item"><span>🏠</span>Início</a>
        <a href="pedidos.php" class="nav-item active"><span>📦</span>Pedidos</a>
        <a href="ganhos.php" class="nav-item"><span>💰</span>Ganhos</a>
        <a href="perfil.php" class="nav-item"><span>👤</span>Perfil</a>
    </nav>
</body>
</html>
