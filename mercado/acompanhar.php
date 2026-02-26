<?php
/**
 * 📍 ACOMPANHAR PEDIDO - CLIENTE
 * Upload em: /mercado/acompanhar.php
 */

session_start();
error_reporting(0);

// Config centralizado
require_once __DIR__ . '/config/database.php';
$pdo = getPDO();

$orderId = intval($_GET['id'] ?? 0);
$pedido = null;

if ($orderId) {
    $stmt = $pdo->prepare("
        SELECT o.*, 
               p.name as partner_name, p.lat as partner_lat, p.lng as partner_lng,
               w1.name as shopper_name, w1.phone as shopper_phone,
               w2.name as driver_name, w2.phone as driver_phone,
               w2.current_lat as driver_lat, w2.current_lng as driver_lng
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN om_market_workers w1 ON o.shopper_id = w1.worker_id
        LEFT JOIN om_market_workers w2 ON o.delivery_driver_id = w2.worker_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Buscar recálculo de valor (se houver)
$recalculo = null;
if ($pedido) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM om_order_recalculations WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$orderId]);
        $recalculo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($recalculo) {
            $recalculo['changes'] = json_decode($recalculo['explanation'], true) ?: [];
        }
    } catch (Exception $e) {
        // Tabela pode não existir ainda
    }
}

// Calcular valores finais
$valorOriginal = (float)($pedido['original_total'] ?? $pedido['total'] ?? 0);
$valorFinal = (float)($pedido['final_total'] ?? $pedido['total'] ?? 0);
$diferenca = $valorFinal - $valorOriginal;
$temAlteracao = $recalculo || abs($diferenca) > 0.01;

// Chat do pedido
$mensagens = [];
if ($pedido) {
    $mensagens = $pdo->query("
        SELECT c.*, 
               CASE 
                   WHEN c.sender_type = 'shopper' THEN w1.name
                   WHEN c.sender_type = 'driver' THEN w2.name
                   ELSE 'Você'
               END as sender_name
        FROM om_order_chat c
        LEFT JOIN om_market_workers w1 ON c.sender_id = w1.worker_id AND c.sender_type = 'shopper'
        LEFT JOIN om_market_workers w2 ON c.sender_id = w2.worker_id AND c.sender_type = 'driver'
        WHERE c.order_id = $orderId
        ORDER BY c.created_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$etapas = [
    'pending' => ['Pedido recebido', 'Aguardando confirmação', false],
    'paid' => ['Pagamento confirmado', 'Buscando shopper', false],
    'shopping' => ['Comprando', 'Shopper está no mercado', true],
    'ready_for_delivery' => ['Compras prontas', 'Aguardando entregador', false],
    'delivering' => ['A caminho', 'Entregador está indo até você', true],
    'delivered' => ['Entregue', 'Pedido finalizado', false],
];

$statusAtual = $pedido['status'] ?? 'pending';
$etapaIndex = array_search($statusAtual, array_keys($etapas));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acompanhar Pedido - OneMundo</title>
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
            background: var(--primary);
            color: var(--white);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .header a { color: var(--white); text-decoration: none; font-size: 1.2rem; }
        .header h1 { font-size: 1.1rem; }
        
        .map-container {
            height: 200px;
            background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .map-placeholder {
            text-align: center;
            color: #6366f1;
        }
        .map-placeholder .icon { font-size: 3rem; margin-bottom: 10px; }
        
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        
        .status-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }
        .status-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        .status-icon {
            width: 50px;
            height: 50px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--white);
        }
        .status-text h2 { font-size: 1.1rem; margin-bottom: 4px; }
        .status-text p { font-size: 0.85rem; color: var(--gray); }
        
        .timeline {
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        .timeline-item {
            display: flex;
            gap: 15px;
            padding: 15px 0;
            position: relative;
        }
        .timeline-item:not(:last-child)::before {
            content: '';
            position: absolute;
            left: 14px;
            top: 40px;
            bottom: 0;
            width: 2px;
            background: #e5e7eb;
        }
        .timeline-item.completed:not(:last-child)::before { background: var(--primary); }
        .timeline-dot {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            flex-shrink: 0;
            z-index: 1;
        }
        .timeline-item.completed .timeline-dot { background: var(--primary); color: var(--white); }
        .timeline-item.current .timeline-dot { 
            background: var(--secondary); 
            color: var(--white);
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(255, 107, 53, 0.4); }
            50% { box-shadow: 0 0 0 10px rgba(255, 107, 53, 0); }
        }
        .timeline-content h4 { font-size: 0.9rem; margin-bottom: 4px; }
        .timeline-content p { font-size: 0.8rem; color: var(--gray); }
        
        .worker-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 15px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .worker-avatar {
            width: 50px;
            height: 50px;
            background: var(--light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .worker-info { flex: 1; }
        .worker-info h4 { font-size: 0.95rem; margin-bottom: 4px; }
        .worker-info p { font-size: 0.8rem; color: var(--gray); }
        .worker-actions { display: flex; gap: 10px; }
        .worker-btn {
            width: 44px;
            height: 44px;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            cursor: pointer;
        }
        .btn-call { background: var(--primary); color: var(--white); }
        .btn-chat { background: var(--light); color: var(--dark); }
        
        .chat-section {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }
        .chat-section h3 { font-size: 0.95rem; margin-bottom: 15px; }
        .chat-messages {
            max-height: 200px;
            overflow-y: auto;
            margin-bottom: 15px;
        }
        .chat-message {
            padding: 10px 14px;
            border-radius: 12px;
            margin-bottom: 8px;
            max-width: 80%;
        }
        .chat-message.customer {
            background: var(--primary);
            color: var(--white);
            margin-left: auto;
        }
        .chat-message.worker {
            background: var(--light);
        }
        .chat-message .sender { font-size: 0.7rem; opacity: 0.8; margin-bottom: 4px; }
        .chat-message .text { font-size: 0.85rem; }
        
        .delivery-code {
            background: linear-gradient(135deg, var(--secondary), #e85d2a);
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            color: var(--white);
            margin-bottom: 20px;
        }
        .delivery-code h3 { font-size: 0.9rem; opacity: 0.9; margin-bottom: 10px; }
        .delivery-code .code {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 4px;
        }
        .delivery-code p { font-size: 0.8rem; opacity: 0.8; margin-top: 10px; }
        
        .not-found {
            text-align: center;
            padding: 60px 20px;
        }
        .not-found .icon { font-size: 4rem; margin-bottom: 20px; }
    </style>
</head>
<body>
    <header class="header">
        <a href="pedidos.php">←</a>
        <h1>📍 Acompanhar Pedido #<?= $orderId ?></h1>
    </header>
    
    <?php if (!$pedido): ?>
    <div class="not-found">
        <div class="icon">🔍</div>
        <h2>Pedido não encontrado</h2>
        <p style="color: var(--gray);">Verifique o número do pedido e tente novamente.</p>
    </div>
    
    <?php else: ?>
    
    <!-- Mapa -->
    <div class="map-container">
        <div class="map-placeholder">
            <div class="icon">🗺️</div>
            <p>Mapa em tempo real</p>
        </div>
    </div>
    
    <div class="container">
        
        <!-- Status Principal -->
        <div class="status-card">
            <div class="status-header">
                <div class="status-icon">
                    <?= $etapas[$statusAtual][0] == 'Entregue' ? '✅' : ($statusAtual == 'delivering' ? '🚗' : ($statusAtual == 'shopping' ? '🛒' : '📦')) ?>
                </div>
                <div class="status-text">
                    <h2><?= $etapas[$statusAtual][0] ?? 'Processando' ?></h2>
                    <p><?= $etapas[$statusAtual][1] ?? '' ?></p>
                </div>
            </div>
            
            <!-- Timeline -->
            <div class="timeline">
                <?php 
                $i = 0;
                foreach ($etapas as $key => $etapa): 
                    $completed = $i < $etapaIndex;
                    $current = $i == $etapaIndex;
                    $i++;
                ?>
                <div class="timeline-item <?= $completed ? 'completed' : '' ?> <?= $current ? 'current' : '' ?>">
                    <div class="timeline-dot"><?= $completed ? '✓' : $i ?></div>
                    <div class="timeline-content">
                        <h4><?= $etapa[0] ?></h4>
                        <p><?= $etapa[1] ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Código de Entrega -->
        <?php if ($pedido['delivery_code'] && in_array($statusAtual, ['delivering', 'ready_for_delivery'])): ?>
        <div class="delivery-code">
            <h3>🔑 Código de Entrega</h3>
            <div class="code"><?= $pedido['delivery_code'] ?></div>
            <p>Informe este código ao entregador para confirmar a entrega</p>
        </div>
        <?php endif; ?>
        
        <!-- Worker Info -->
        <?php if ($pedido['shopper_name'] && in_array($statusAtual, ['shopping', 'ready_for_delivery'])): ?>
        <div class="worker-card">
            <div class="worker-avatar">🛒</div>
            <div class="worker-info">
                <h4><?= $pedido['shopper_name'] ?></h4>
                <p>Shopper • Fazendo suas compras</p>
            </div>
            <div class="worker-actions">
                <button class="worker-btn btn-chat">💬</button>
                <a href="tel:<?= $pedido['shopper_phone'] ?>" class="worker-btn btn-call">📞</a>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($pedido['driver_name'] && $statusAtual == 'delivering'): ?>
        <div class="worker-card">
            <div class="worker-avatar">🚗</div>
            <div class="worker-info">
                <h4><?= $pedido['driver_name'] ?></h4>
                <p>Entregador • A caminho</p>
            </div>
            <div class="worker-actions">
                <button class="worker-btn btn-chat">💬</button>
                <a href="tel:<?= $pedido['driver_phone'] ?>" class="worker-btn btn-call">📞</a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Recálculo de Valor -->
        <?php if ($temAlteracao && $recalculo): ?>
        <div class="status-card recalc-card">
            <h3 style="margin-bottom: 15px; font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 1.2rem;">💰</span> Valor Atualizado
            </h3>

            <?php if (!empty($recalculo['ai_summary'])): ?>
            <div style="background: #f0fdf4; border-radius: 8px; padding: 12px; margin-bottom: 15px; font-size: 0.85rem; color: #166534;">
                <?= htmlspecialchars($recalculo['ai_summary']) ?>
            </div>
            <?php endif; ?>

            <!-- Alterações -->
            <?php if (!empty($recalculo['changes'])): ?>
            <div style="margin-bottom: 15px;">
                <?php foreach ($recalculo['changes'] as $change): ?>
                <div style="display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f3f4f6; font-size: 0.85rem;">
                    <?php if ($change['type'] === 'removed'): ?>
                        <span style="width: 24px; height: 24px; background: #fef2f2; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem;">❌</span>
                        <div style="flex: 1;">
                            <div style="color: #991b1b;"><?= htmlspecialchars($change['product']) ?></div>
                            <div style="font-size: 0.75rem; color: var(--gray);">Produto indisponível</div>
                        </div>
                        <span style="color: #16a34a; font-weight: 500;">-R$ <?= number_format(abs($change['amount']), 2, ',', '.') ?></span>
                    <?php elseif ($change['type'] === 'substituted'): ?>
                        <span style="width: 24px; height: 24px; background: #fef9c3; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem;">🔄</span>
                        <div style="flex: 1;">
                            <div><?= htmlspecialchars($change['product']) ?> → <?= htmlspecialchars($change['substitute']) ?></div>
                            <div style="font-size: 0.75rem; color: var(--gray);">Produto substituído</div>
                        </div>
                        <span style="color: <?= $change['amount'] > 0 ? '#dc2626' : '#16a34a' ?>; font-weight: 500;">
                            <?= $change['amount'] >= 0 ? '+' : '-' ?>R$ <?= number_format(abs($change['amount']), 2, ',', '.') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Resumo de Valores -->
            <div style="background: #f8fafc; border-radius: 8px; padding: 12px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.85rem;">
                    <span style="color: var(--gray);">Valor Original</span>
                    <span style="text-decoration: line-through; color: var(--gray);">R$ <?= number_format($recalculo['original_total'], 2, ',', '.') ?></span>
                </div>
                <?php if ($diferenca < 0): ?>
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.85rem;">
                    <span style="color: #16a34a;">Economia</span>
                    <span style="color: #16a34a;">-R$ <?= number_format(abs($diferenca), 2, ',', '.') ?></span>
                </div>
                <?php elseif ($diferenca > 0): ?>
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.85rem;">
                    <span style="color: #dc2626;">Ajuste</span>
                    <span style="color: #dc2626;">+R$ <?= number_format($diferenca, 2, ',', '.') ?></span>
                </div>
                <?php endif; ?>
                <div style="display: flex; justify-content: space-between; font-size: 1rem; font-weight: 600; padding-top: 8px; border-top: 1px solid #e2e8f0;">
                    <span>Valor Final</span>
                    <span style="color: var(--primary);">R$ <?= number_format($recalculo['final_total'], 2, ',', '.') ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Chat -->
        <?php if (!empty($mensagens)): ?>
        <div class="chat-section">
            <h3>💬 Mensagens</h3>
            <div class="chat-messages">
                <?php foreach ($mensagens as $m): ?>
                <div class="chat-message <?= $m['sender_type'] == 'customer' ? 'customer' : 'worker' ?>">
                    <div class="sender"><?= $m['sender_name'] ?></div>
                    <div class="text"><?= htmlspecialchars($m['message']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Info do Pedido -->
        <div class="status-card">
            <h3 style="margin-bottom: 15px; font-size: 0.95rem;">📋 Detalhes do Pedido</h3>
            <div style="display: flex; flex-direction: column; gap: 10px; font-size: 0.9rem;">
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--gray);">🏪 Mercado</span>
                    <span><?= $pedido['partner_name'] ?: 'Mercado' ?></span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--gray);">📍 Entrega</span>
                    <span><?= $pedido['delivery_address'] ?: $pedido['delivery_city'] ?></span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--gray);">💰 Total</span>
                    <span style="font-weight: 700; color: var(--primary);">
                        R$ <?= number_format($valorFinal, 2, ',', '.') ?>
                        <?php if ($temAlteracao && $diferenca < 0): ?>
                        <span style="font-size: 0.75rem; color: #16a34a; font-weight: 500;">(economia!)</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
        
    </div>
    
    <?php endif; ?>
    
    <script>
        // Auto refresh a cada 30s para status em tempo real
        <?php if ($pedido && !in_array($statusAtual, ['delivered', 'cancelled'])): ?>
        setTimeout(() => location.reload(), 30000);
        <?php endif; ?>
    </script>
</body>
</html>
