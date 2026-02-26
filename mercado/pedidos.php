<?php
/**
 * 📦 MEUS PEDIDOS - CLIENTE
 * Upload em: /mercado/pedidos.php
 */

session_start();
error_reporting(0);

require_once __DIR__ . '/config/database.php';
$pdo = getPDO();

$logado = isset($_SESSION['customer_id']);
$pedidos = [];

if ($logado) {
    $customerId = $_SESSION['customer_id'];
    $pedidos = $pdo->query("
        SELECT o.*, p.name as partner_name,
               w1.name as shopper_name,
               w2.name as driver_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN om_market_workers w1 ON o.shopper_id = w1.worker_id
        LEFT JOIN om_market_workers w2 ON o.delivery_driver_id = w2.worker_id
        WHERE o.customer_id = $customerId
        ORDER BY o.created_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$statusLabels = [
    'pending' => ['⏳', 'Aguardando', '#f59e0b'],
    'confirmed' => ['✅', 'Confirmado', '#3b82f6'],
    'paid' => ['💳', 'Pago', '#3b82f6'],
    'shopping' => ['🛒', 'Comprando', '#8b5cf6'],
    'purchased' => ['📦', 'Compras Prontas', '#06b6d4'],
    'ready_for_delivery' => ['📦', 'Pronto', '#06b6d4'],
    'aguardando_retirada' => ['🏪', 'Aguardando Retirada', '#f97316'],
    'delivering' => ['🚗', 'A caminho', '#f97316'],
    'delivered' => ['✅', 'Entregue', '#10b981'],
    'retirado' => ['🏪', 'Retirado', '#10b981'],
    'cancelled' => ['❌', 'Cancelado', '#ef4444'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Pedidos - OneMundo</title>
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
        }
        
        .header {
            background: var(--white);
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .header a { color: var(--dark); text-decoration: none; font-size: 1.2rem; }
        .header h1 { font-size: 1.1rem; }
        
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        
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
            padding-bottom: 12px;
            border-bottom: 1px solid #f3f4f6;
        }
        .order-id { font-weight: 700; font-size: 1rem; }
        .order-date { font-size: 0.75rem; color: var(--gray); margin-top: 4px; }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .order-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 12px;
        }
        .order-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }
        .order-info-item span:first-child { font-size: 1.1rem; }
        .order-info-item .label { color: var(--gray); }
        
        .order-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid #f3f4f6;
        }
        .order-total { font-size: 1.2rem; font-weight: 700; color: var(--primary); }
        .order-action {
            padding: 8px 16px;
            background: var(--primary);
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        
        .empty {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-icon { font-size: 4rem; margin-bottom: 20px; }
        .empty h2 { font-size: 1.2rem; margin-bottom: 10px; }
        .empty p { color: var(--gray); margin-bottom: 25px; }
        .empty a {
            display: inline-block;
            padding: 14px 30px;
            background: var(--primary);
            color: var(--white);
            text-decoration: none;
            border-radius: var(--radius);
            font-weight: 600;
        }
        
        .login-prompt {
            text-align: center;
            padding: 60px 20px;
        }
        .login-prompt a {
            display: inline-block;
            padding: 14px 30px;
            background: var(--primary);
            color: var(--white);
            text-decoration: none;
            border-radius: var(--radius);
            font-weight: 600;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <header class="header">
        <a href="index.php">←</a>
        <h1>📦 Meus Pedidos</h1>
    </header>
    
    <div class="container">
        
        <?php if (!$logado): ?>
        <div class="login-prompt">
            <div style="font-size: 4rem;">🔐</div>
            <h2>Faça login para ver seus pedidos</h2>
            <p>Você precisa estar logado para acessar seu histórico de pedidos.</p>
            <a href="login.php">Entrar</a>
        </div>
        
        <?php elseif (empty($pedidos)): ?>
        <div class="empty">
            <div class="empty-icon">📦</div>
            <h2>Nenhum pedido ainda</h2>
            <p>Você ainda não fez nenhum pedido. Que tal começar agora?</p>
            <a href="index.php">Explorar produtos</a>
        </div>
        
        <?php else: ?>
        
        <?php foreach ($pedidos as $p): 
            $status = $statusLabels[$p['status']] ?? ['❓', $p['status'], '#6b7280'];
        ?>
        <div class="order-card">
            <div class="order-header">
                <div>
                    <div class="order-id">Pedido #<?= $p['order_id'] ?></div>
                    <div class="order-date"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></div>
                </div>
                <span class="status-badge" style="background: <?= $status[2] ?>20; color: <?= $status[2] ?>;">
                    <?= $status[0] ?> <?= $status[1] ?>
                </span>
            </div>
            
            <?php if (($p['tipo_entrega'] ?? 'entrega') === 'retirada'): ?>
            <div style="margin: 8px 0; display: flex; align-items: center; gap: 6px; font-size: 13px; color: #FF6B00; font-weight: 600;">
                🏪 Retirada na Loja
            </div>
            <?php endif; ?>

            <div class="order-info">
                <div class="order-info-item">
                    <span>🏪</span>
                    <span><?= $p['partner_name'] ?: 'Mercado' ?></span>
                </div>
                <div class="order-info-item">
                    <span>📍</span>
                    <span class="label"><?= $p['shipping_address'] ?? $p['delivery_address'] ?? $p['shipping_city'] ?? '' ?></span>
                </div>
                <?php if ($p['shopper_name']): ?>
                <div class="order-info-item">
                    <span>🛒</span>
                    <span>Shopper: <?= $p['shopper_name'] ?></span>
                </div>
                <?php endif; ?>
                <?php if ($p['driver_name'] && $p['status'] == 'delivering'): ?>
                <div class="order-info-item">
                    <span>🚗</span>
                    <span>Entregador: <?= $p['driver_name'] ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($p['status'] === 'aguardando_retirada' && !empty($p['retirada_code'])): ?>
            <div style="margin: 8px 0; padding: 10px; background: #FFF3E8; border-radius: 8px; text-align: center;">
                <div style="font-size: 12px; color: #666;">Codigo de Retirada</div>
                <div style="font-size: 24px; font-weight: 800; color: #FF6B00; letter-spacing: 4px;"><?= htmlspecialchars($p['retirada_code']) ?></div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">Apresente este codigo no mercado</div>
            </div>
            <?php endif; ?>

            <div class="order-footer">
                <span class="order-total">R$ <?= number_format($p['total'], 2, ',', '.') ?></span>
                <?php if (in_array($p['status'], ['shopping', 'delivering'])): ?>
                <a href="acompanhar.php?id=<?= $p['order_id'] ?>" class="order-action">Acompanhar</a>
                <?php elseif (in_array($p['status'], ['delivered', 'retirado'])): ?>
                <a href="?refazer=<?= $p['order_id'] ?>" class="order-action" style="background: var(--secondary);">Pedir novamente</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php endif; ?>
        
    </div>
</body>
</html>
