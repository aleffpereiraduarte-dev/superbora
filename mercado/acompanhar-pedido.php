<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ACOMPANHAR PEDIDO - Tracking em Tempo Real com Adição de Itens
 * Cliente pode ver status e adicionar produtos até 30% do progresso
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/includes/env_loader.php';

$pdo = null;
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    die('Erro de conexão');
}

$customer_id = $_SESSION['customer_id'] ?? 0;
$order_id = (int)($_GET['id'] ?? $_GET['order_id'] ?? $_SESSION['last_order_id'] ?? 0);

if (!$customer_id) {
    header('Location: /mercado/mercado-login.php?redirect=acompanhar-pedido&id=' . $order_id);
    exit;
}

// Buscar pedido
$order = null;
$items = [];
$shopper = null;
$timeline = [];

if ($order_id && $pdo) {
    // Pedido
    $stmt = $pdo->prepare("
        SELECT o.*,
               s.name as shopper_name, s.phone as shopper_phone, s.rating as shopper_rating
        FROM om_market_orders o
        LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$order_id, $customer_id]);
    $order = $stmt->fetch();

    if ($order) {
        // Itens do pedido
        $stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll();

        // Timeline
        $stmt = $pdo->prepare("SELECT * FROM om_order_timeline WHERE order_id = ? ORDER BY created_at ASC");
        $stmt->execute([$order_id]);
        $timeline = $stmt->fetchAll();

        // Shopper
        if ($order['shopper_id']) {
            $shopper = [
                'name' => $order['shopper_name'],
                'phone' => $order['shopper_phone'],
                'rating' => $order['shopper_rating']
            ];
        }
    }
}

if (!$order) {
    // Se não tem pedido, tentar pegar o último
    $stmt = $pdo->prepare("
        SELECT order_id FROM om_market_orders
        WHERE customer_id = ?
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$customer_id]);
    $lastOrder = $stmt->fetchColumn();

    if ($lastOrder) {
        header("Location: /mercado/acompanhar-pedido.php?id=$lastOrder");
        exit;
    }

    header('Location: /mercado/meus-pedidos.php');
    exit;
}

// Calcular progresso e se pode adicionar itens
$scan_progress = (float)($order['scan_progress'] ?? $order['progress_pct'] ?? 0);
$can_add_items = in_array($order['status'], ['confirmed', 'shopping', 'pending', 'accepted']) && $scan_progress < 30;

// Status para exibição
$statusMap = [
    'pending' => ['label' => 'Aguardando', 'icon' => 'clock', 'color' => '#f59e0b'],
    'confirmed' => ['label' => 'Confirmado', 'icon' => 'check', 'color' => '#10b981'],
    'accepted' => ['label' => 'Shopper Aceitou', 'icon' => 'user-check', 'color' => '#10b981'],
    'shopping' => ['label' => 'Comprando', 'icon' => 'shopping-cart', 'color' => '#3b82f6'],
    'packing' => ['label' => 'Embalando', 'icon' => 'box', 'color' => '#8b5cf6'],
    'ready' => ['label' => 'Pronto', 'icon' => 'check-circle', 'color' => '#22c55e'],
    'delivering' => ['label' => 'Saiu para Entrega', 'icon' => 'truck', 'color' => '#f97316'],
    'delivered' => ['label' => 'Entregue', 'icon' => 'check-double', 'color' => '#22c55e'],
    'cancelled' => ['label' => 'Cancelado', 'icon' => 'times-circle', 'color' => '#ef4444']
];

$currentStatus = $statusMap[$order['status']] ?? $statusMap['pending'];

// Emojis para produtos
$emojis = ['🍚', '🫘', '🥛', '🥖', '🍎', '🥩', '🧀', '🥚', '🍌', '🥤', '☕', '🧈'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Acompanhar Pedido #<?= htmlspecialchars($order['order_number']) ?> - SuperBora</title>
    <meta name="theme-color" content="#059669">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/mercado/assets/css/order-tracking.css">
</head>
<body>

<!-- Header -->
<header class="tracking-header">
    <div class="header-content">
        <a href="/mercado/meus-pedidos.php" class="btn-back">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div class="header-title">
            <h1>Pedido #<?= htmlspecialchars($order['order_number']) ?></h1>
            <p><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></p>
        </div>
        <button class="btn-help" onclick="showHelp()">
            <i class="fas fa-headset"></i>
        </button>
    </div>
</header>

<main class="tracking-main">
    <!-- Status Card -->
    <section class="status-card">
        <div class="status-icon" style="background: <?= $currentStatus['color'] ?>;">
            <i class="fas fa-<?= $currentStatus['icon'] ?>"></i>
        </div>
        <div class="status-info">
            <span class="status-label"><?= $currentStatus['label'] ?></span>
            <p class="status-description">
                <?php
                switch ($order['status']) {
                    case 'pending':
                        echo 'Procurando um shopper para seu pedido...';
                        break;
                    case 'confirmed':
                    case 'accepted':
                        echo $shopper ? 'Shopper a caminho do mercado!' : 'Shopper aceitou seu pedido!';
                        break;
                    case 'shopping':
                        echo $scan_progress > 0
                            ? sprintf('Separando seus produtos... %.0f%% concluído', $scan_progress)
                            : 'Shopper está separando seus produtos';
                        break;
                    case 'packing':
                        echo 'Finalizando e embalando seu pedido';
                        break;
                    case 'ready':
                        echo 'Aguardando entregador...';
                        break;
                    case 'delivering':
                        echo 'Pedido a caminho! Fique atento.';
                        break;
                    case 'delivered':
                        echo 'Pedido entregue com sucesso!';
                        break;
                    case 'cancelled':
                        echo 'Este pedido foi cancelado.';
                        break;
                    default:
                        echo 'Processando seu pedido...';
                }
                ?>
            </p>
        </div>

        <?php if ($order['status'] === 'shopping' && $scan_progress > 0): ?>
        <div class="progress-bar-wrap">
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= min($scan_progress, 100) ?>%"></div>
            </div>
            <span class="progress-text"><?= round($scan_progress) ?>% dos itens</span>
        </div>
        <?php endif; ?>
    </section>

    <!-- Shopper Info -->
    <?php if ($shopper && !in_array($order['status'], ['pending', 'delivered', 'cancelled'])): ?>
    <section class="shopper-card">
        <div class="shopper-avatar">
            <i class="fas fa-user"></i>
        </div>
        <div class="shopper-info">
            <h3><?= htmlspecialchars($shopper['name'] ?? 'Shopper') ?></h3>
            <div class="shopper-rating">
                <i class="fas fa-star"></i>
                <span><?= number_format($shopper['rating'] ?? 5, 1, ',', '.') ?></span>
            </div>
        </div>
        <div class="shopper-actions">
            <a href="tel:<?= htmlspecialchars($shopper['phone'] ?? '') ?>" class="btn-contact">
                <i class="fas fa-phone"></i>
            </a>
            <button class="btn-contact" onclick="openChat()">
                <i class="fas fa-comment"></i>
            </button>
        </div>
    </section>
    <?php endif; ?>

    <!-- Add Items Banner -->
    <?php if ($can_add_items): ?>
    <section class="add-items-banner">
        <div class="banner-icon">
            <i class="fas fa-plus-circle"></i>
        </div>
        <div class="banner-content">
            <h3>Esqueceu algo?</h3>
            <p>Adicione mais produtos antes do shopper terminar!</p>
        </div>
        <button class="btn-add-items" onclick="showAddItemsModal()">
            Adicionar
        </button>
    </section>
    <?php elseif ($order['status'] === 'shopping' && $scan_progress >= 30): ?>
    <section class="add-items-banner locked">
        <div class="banner-icon">
            <i class="fas fa-lock"></i>
        </div>
        <div class="banner-content">
            <h3>Compras em andamento</h3>
            <p>O shopper já está escaneando os produtos</p>
        </div>
    </section>
    <?php endif; ?>

    <!-- AI Suggestion -->
    <section class="ai-suggestion" id="ai-suggestion">
        <div class="ai-avatar">
            <span>AI</span>
        </div>
        <div class="ai-content">
            <span class="ai-badge"><i class="fas fa-magic"></i> Sugestão</span>
            <p id="ai-message">Carregando sugestões...</p>
        </div>
        <button class="ai-dismiss" onclick="this.parentElement.style.display='none'">
            <i class="fas fa-times"></i>
        </button>
    </section>

    <!-- Timeline -->
    <section class="timeline-section">
        <h2><i class="fas fa-history"></i> Histórico</h2>
        <div class="timeline">
            <?php
            // Timeline padrão baseada no status atual
            $steps = [
                ['status' => 'confirmed', 'label' => 'Pedido Confirmado', 'icon' => 'check'],
                ['status' => 'shopping', 'label' => 'Shopper Comprando', 'icon' => 'shopping-cart'],
                ['status' => 'ready', 'label' => 'Pedido Pronto', 'icon' => 'box'],
                ['status' => 'delivering', 'label' => 'Saiu para Entrega', 'icon' => 'truck'],
                ['status' => 'delivered', 'label' => 'Entregue', 'icon' => 'check-circle']
            ];

            $statusOrder = ['pending', 'confirmed', 'accepted', 'shopping', 'packing', 'ready', 'delivering', 'delivered'];
            $currentIndex = array_search($order['status'], $statusOrder);

            foreach ($steps as $i => $step):
                $stepIndex = array_search($step['status'], $statusOrder);
                $isCompleted = $stepIndex !== false && $currentIndex !== false && $stepIndex <= $currentIndex;
                $isCurrent = $step['status'] === $order['status'] ||
                            ($step['status'] === 'confirmed' && in_array($order['status'], ['pending', 'confirmed', 'accepted'])) ||
                            ($step['status'] === 'shopping' && in_array($order['status'], ['shopping', 'packing']));
            ?>
            <div class="timeline-item <?= $isCompleted ? 'completed' : '' ?> <?= $isCurrent ? 'current' : '' ?>">
                <div class="timeline-dot">
                    <i class="fas fa-<?= $step['icon'] ?>"></i>
                </div>
                <div class="timeline-content">
                    <h4><?= $step['label'] ?></h4>
                    <?php
                    // Buscar timestamp da timeline real
                    $timelineEntry = array_filter($timeline, fn($t) => $t['status'] === $step['status']);
                    if ($timelineEntry):
                        $entry = array_values($timelineEntry)[0];
                    ?>
                    <span class="timeline-time"><?= date('H:i', strtotime($entry['created_at'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Order Items -->
    <section class="items-section">
        <div class="items-header">
            <h2><i class="fas fa-shopping-bag"></i> Itens do Pedido</h2>
            <span class="items-count"><?= count($items) ?> itens</span>
        </div>
        <div class="items-list">
            <?php foreach ($items as $i => $item): ?>
            <div class="order-item">
                <div class="item-emoji"><?= $emojis[$i % count($emojis)] ?></div>
                <div class="item-info">
                    <span class="item-name"><?= htmlspecialchars($item['product_name']) ?></span>
                    <span class="item-qty"><?= $item['quantity'] ?>x R$ <?= number_format($item['price'], 2, ',', '.') ?></span>
                </div>
                <span class="item-total">R$ <?= number_format($item['total_price'], 2, ',', '.') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Order Summary -->
    <section class="summary-section">
        <h2><i class="fas fa-receipt"></i> Resumo</h2>
        <div class="summary-card">
            <div class="summary-row">
                <span>Subtotal</span>
                <span>R$ <?= number_format($order['subtotal'], 2, ',', '.') ?></span>
            </div>
            <div class="summary-row">
                <span>Entrega</span>
                <span>R$ <?= number_format($order['delivery_fee'], 2, ',', '.') ?></span>
            </div>
            <?php if ($order['discount'] > 0): ?>
            <div class="summary-row discount">
                <span>Desconto</span>
                <span>-R$ <?= number_format($order['discount'], 2, ',', '.') ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-row total">
                <span>Total</span>
                <span>R$ <?= number_format($order['total'], 2, ',', '.') ?></span>
            </div>
            <div class="payment-method">
                <i class="fas fa-<?= $order['payment_method'] === 'pix' ? 'qrcode' : ($order['payment_method'] === 'credit_card' ? 'credit-card' : 'money-bill') ?>"></i>
                <span>
                    <?php
                    $paymentNames = [
                        'pix' => 'PIX',
                        'credit_card' => 'Cartão de Crédito',
                        'debit_card' => 'Cartão de Débito',
                        'cash' => 'Dinheiro'
                    ];
                    echo $paymentNames[$order['payment_method']] ?? 'Outro';
                    ?>
                </span>
                <span class="payment-status <?= $order['payment_status'] === 'paid' ? 'paid' : 'pending' ?>">
                    <?= $order['payment_status'] === 'paid' ? 'Pago' : 'Pendente' ?>
                </span>
            </div>
        </div>
    </section>

    <!-- Delivery Address -->
    <section class="address-section">
        <h2><i class="fas fa-map-marker-alt"></i> Entrega</h2>
        <div class="address-card">
            <p class="address-line"><?= htmlspecialchars($order['shipping_address']) ?>, <?= htmlspecialchars($order['shipping_number']) ?></p>
            <?php if ($order['shipping_complement']): ?>
            <p class="address-complement"><?= htmlspecialchars($order['shipping_complement']) ?></p>
            <?php endif; ?>
            <p class="address-city">
                <?= htmlspecialchars($order['shipping_neighborhood']) ?> -
                <?= htmlspecialchars($order['shipping_city']) ?>/<?= htmlspecialchars($order['shipping_state']) ?>
            </p>
            <p class="address-cep">CEP: <?= htmlspecialchars($order['shipping_cep']) ?></p>
        </div>
    </section>

    <!-- Actions -->
    <?php if (!in_array($order['status'], ['delivered', 'cancelled'])): ?>
    <section class="actions-section">
        <?php if (in_array($order['status'], ['pending', 'confirmed'])): ?>
        <button class="btn-action danger" onclick="cancelOrder()">
            <i class="fas fa-times"></i>
            Cancelar Pedido
        </button>
        <?php endif; ?>
        <button class="btn-action secondary" onclick="shareOrder()">
            <i class="fas fa-share-alt"></i>
            Compartilhar
        </button>
        <a href="tel:<?= $shopper['phone'] ?? '' ?>" class="btn-action primary" <?= !$shopper ? 'style="display:none"' : '' ?>>
            <i class="fas fa-phone"></i>
            Ligar para Shopper
        </a>
    </section>
    <?php endif; ?>

    <!-- Reorder Button -->
    <?php if ($order['status'] === 'delivered'): ?>
    <section class="reorder-section">
        <button class="btn-reorder" onclick="reorder()">
            <i class="fas fa-redo"></i>
            Repetir Pedido
        </button>
    </section>
    <?php endif; ?>
</main>

<!-- Modal: Add Items -->
<div class="modal-overlay" id="add-items-modal">
    <div class="modal-content modal-fullscreen">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Adicionar Produtos</h3>
            <button class="modal-close" onclick="hideAddItemsModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <!-- Search -->
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="product-search" placeholder="Buscar produtos..." oninput="searchProducts(this.value)">
            </div>

            <!-- Quick Categories -->
            <div class="quick-categories" id="quick-categories">
                <button class="cat-btn active" onclick="filterCategory(0, this)">Todos</button>
                <button class="cat-btn" onclick="filterCategory(59, this)">Hortifruti</button>
                <button class="cat-btn" onclick="filterCategory(60, this)">Padaria</button>
                <button class="cat-btn" onclick="filterCategory(61, this)">Laticínios</button>
                <button class="cat-btn" onclick="filterCategory(62, this)">Carnes</button>
                <button class="cat-btn" onclick="filterCategory(63, this)">Bebidas</button>
            </div>

            <!-- Products Grid -->
            <div class="products-grid" id="products-grid">
                <div class="loading-products">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Carregando produtos...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <div class="added-items-count" id="added-count">
                <span>0 itens adicionados</span>
            </div>
            <button class="btn-done" onclick="hideAddItemsModal()">
                Pronto
            </button>
        </div>
    </div>
</div>

<!-- Modal: Chat -->
<div class="modal-overlay" id="chat-modal">
    <div class="modal-content modal-fullscreen">
        <div class="modal-header">
            <h3><i class="fas fa-comment"></i> Chat com Shopper</h3>
            <button class="modal-close" onclick="closeChatModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="chat-messages" id="chat-messages">
            <!-- Messages will be loaded here -->
        </div>
        <div class="chat-input-wrap">
            <input type="text" id="chat-input" placeholder="Digite sua mensagem...">
            <button onclick="sendMessage()">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- JS Data -->
<script>
const orderData = {
    orderId: <?= $order_id ?>,
    orderNumber: '<?= htmlspecialchars($order['order_number']) ?>',
    status: '<?= $order['status'] ?>',
    canAddItems: <?= $can_add_items ? 'true' : 'false' ?>,
    scanProgress: <?= $scan_progress ?>,
    customerId: <?= $customer_id ?>,
    partnerId: <?= $order['partner_id'] ?? 100 ?>,
    total: <?= $order['total'] ?>
};
</script>
<script src="/mercado/assets/js/order-tracking.js"></script>

</body>
</html>
