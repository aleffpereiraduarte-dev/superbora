<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO MERCADO - SACOLA / CARRINHO
 * ══════════════════════════════════════════════════════════════════════════════
 * Layout: DoorDash Style
 * Sessão: Lê de $_SESSION['market_cart'] (mesmo do index.php)
 */

// Sessão com mesmo nome do OpenCart/index.php
session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Conexão
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    $pdo = null;
}

$customer_id = $_SESSION['customer_id'] ?? 0;
$cart = $_SESSION['market_cart'] ?? [];

// Debug: descomentar para ver o carrinho
// echo '<pre>'; print_r($cart); echo '</pre>'; exit;

// Calcular totais
$items = [];
$subtotal = 0;
$total_qty = 0;

foreach ($cart as $key => $item) {
    // Normalizar dados (aceitar diferentes formatos)
    $id = (int)($item['id'] ?? $item['product_id'] ?? 0);
    $qty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
    $price = (float)($item['price'] ?? 0);
    $price_promo = (float)($item['price_promo'] ?? 0);
    
    // Preço final
    $final_price = ($price_promo > 0 && $price_promo < $price) ? $price_promo : $price;
    $line_total = $final_price * $qty;
    
    $items[] = [
        'id' => $id,
        'product_id' => $id,
        'name' => $item['name'] ?? 'Produto',
        'price' => $price,
        'price_promo' => $price_promo,
        'final_price' => $final_price,
        'image' => $item['image'] ?? '',
        'qty' => $qty,
        'line_total' => $line_total
    ];
    
    $subtotal += $line_total;
    $total_qty += $qty;
}

// Frete
$free_delivery_min = 99;
$delivery_fee = $subtotal >= $free_delivery_min ? 0 : 7.99;
$service_fee = 1.99;
$to_free_delivery = max(0, $free_delivery_min - $subtotal);
$progress = $free_delivery_min > 0 ? min(100, ($subtotal / $free_delivery_min) * 100) : 100;
$total = $subtotal + $delivery_fee + $service_fee;

// Endereço do cliente
$address = null;
if ($pdo && $customer_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM oc_address WHERE customer_id = ? ORDER BY address_id DESC LIMIT 1");
        $stmt->execute([$customer_id]);
        $address = $stmt->fetch();
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sacola - OneMundo Mercado</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --black: #191919;
            --gray-900: #2d2d2d;
            --gray-700: #4d4d4d;
            --gray-600: #6b6b6b;
            --gray-500: #8a8a8a;
            --gray-400: #a3a3a3;
            --gray-300: #c2c2c2;
            --gray-200: #e0e0e0;
            --gray-100: #f0f0f0;
            --gray-50: #f7f7f7;
            --white: #ffffff;
            --green: #00aa5b;
            --green-light: #e6f7ef;
            --red: #d4111e;
            --orange: #ff6b35;
        }
        
        body {
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--black);
            -webkit-font-smoothing: antialiased;
        }
        
        /* Header */
        .header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
        }
        
        .header-main {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            gap: 16px;
        }
        
        .btn-close {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            color: var(--black);
        }
        
        .btn-close:hover { background: var(--gray-100); }
        .btn-close svg { width: 20px; height: 20px; }
        
        .header-title {
            flex: 1;
            font-size: 18px;
            font-weight: 700;
        }
        
        .header-clear {
            font-size: 14px;
            font-weight: 600;
            color: var(--red);
            background: none;
            border: none;
            cursor: pointer;
        }
        
        /* Content */
        .content {
            max-width: 680px;
            margin: 0 auto;
            padding-bottom: 200px;
        }
        
        /* Store Card */
        .store-card {
            background: var(--white);
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .store-logo {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .store-info h2 { font-size: 16px; font-weight: 700; margin-bottom: 2px; }
        .store-info p { font-size: 13px; color: var(--gray-600); }
        .store-arrow { margin-left: auto; color: var(--gray-400); }
        
        /* Delivery Promo */
        .delivery-promo {
            background: var(--white);
            padding: 16px;
            border-bottom: 8px solid var(--gray-100);
        }
        
        .promo-content {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .promo-icon {
            width: 40px;
            height: 40px;
            background: <?= $delivery_fee === 0 ? 'var(--green-light)' : '#fff3e6' ?>;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .promo-text { flex: 1; }
        .promo-text strong { display: block; font-size: 14px; font-weight: 700; margin-bottom: 2px; }
        .promo-text span { font-size: 13px; color: var(--gray-600); }
        
        .progress-wrap { margin-top: 12px; }
        .progress-bar { height: 4px; background: var(--gray-200); border-radius: 2px; overflow: hidden; }
        .progress-fill { height: 100%; background: <?= $delivery_fee === 0 ? 'var(--green)' : 'var(--orange)' ?>; border-radius: 2px; }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 24px;
            background: var(--white);
        }
        
        .empty-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 20px;
            background: var(--gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .empty-icon svg { width: 48px; height: 48px; color: var(--gray-400); }
        .empty-state h2 { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
        .empty-state p { font-size: 14px; color: var(--gray-600); margin-bottom: 24px; }
        
        .btn-shop {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 32px;
            background: var(--green);
            color: var(--white);
            text-decoration: none;
            border-radius: 24px;
            font-size: 15px;
            font-weight: 600;
        }
        
        .btn-shop:hover { background: #009950; }
        
        /* Items */
        .items-section { background: var(--white); }
        
        .section-title {
            padding: 16px 16px 8px;
            font-size: 13px;
            font-weight: 700;
            color: var(--gray-600);
            text-transform: uppercase;
        }
        
        .cart-item {
            display: flex;
            padding: 12px 16px;
            gap: 12px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .cart-item:last-child { border-bottom: none; }
        
        .item-img {
            width: 80px;
            height: 80px;
            background: var(--gray-100);
            border-radius: 12px;
            overflow: hidden;
            position: relative;
        }
        
        .item-img img { width: 100%; height: 100%; object-fit: cover; }
        
        .item-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            font-size: 36px;
        }
        
        .item-qty-badge {
            position: absolute;
            top: -6px;
            left: -6px;
            width: 24px;
            height: 24px;
            background: var(--green);
            color: var(--white);
            border-radius: 50%;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--white);
        }
        
        .item-details {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 4px 0;
        }
        
        .item-name {
            font-size: 15px;
            font-weight: 600;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 4px;
        }
        
        .item-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .item-price { font-size: 15px; font-weight: 700; }
        
        .item-controls {
            display: flex;
            align-items: center;
            background: var(--gray-100);
            border-radius: 20px;
            padding: 4px;
        }
        
        .ctrl-btn {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        
        .ctrl-btn:hover { background: var(--gray-50); }
        .ctrl-btn.delete { color: var(--red); }
        .ctrl-btn svg { width: 14px; height: 14px; }
        .ctrl-qty { min-width: 32px; text-align: center; font-size: 14px; font-weight: 700; }
        
        /* Add More */
        .add-more {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px;
            background: var(--white);
            color: var(--green);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            border-bottom: 8px solid var(--gray-100);
        }
        
        .add-more:hover { background: var(--gray-50); }
        .add-more svg { width: 18px; height: 18px; }
        
        /* Summary */
        .summary {
            background: var(--white);
            padding: 16px;
        }
        
        .summary-title { font-size: 16px; font-weight: 700; margin-bottom: 16px; }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }
        
        .summary-row .label { color: var(--gray-700); }
        .summary-row .value { font-weight: 500; }
        .summary-row .value.free { color: var(--green); font-weight: 600; }
        
        .summary-row.total {
            border-top: 1px solid var(--gray-200);
            margin-top: 12px;
            padding-top: 16px;
            font-size: 16px;
        }
        
        .summary-row.total .label,
        .summary-row.total .value { font-weight: 700; }
        
        /* Bottom Bar */
        .bottom-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            border-top: 1px solid var(--gray-200);
            padding: 12px 16px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
            z-index: 100;
        }
        
        .bottom-inner { max-width: 680px; margin: 0 auto; }
        
        .address-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--gray-50);
            border-radius: 12px;
            margin-bottom: 12px;
            cursor: pointer;
        }
        
        .address-bar:hover { background: var(--gray-100); }
        
        .address-icon {
            width: 36px;
            height: 36px;
            background: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .address-info { flex: 1; min-width: 0; }
        .address-label { font-size: 11px; color: var(--gray-600); text-transform: uppercase; margin-bottom: 2px; }
        .address-text { font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .address-arrow { color: var(--gray-500); }
        
        .btn-checkout {
            width: 100%;
            padding: 16px 24px;
            background: var(--green);
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .btn-checkout:hover:not(:disabled) { background: #009950; }
        .btn-checkout:disabled { background: var(--gray-300); cursor: not-allowed; }
        .btn-total { font-size: 14px; opacity: 0.9; }
        
        /* Toast */
        .toast {
            position: fixed;
            bottom: 220px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: var(--black);
            color: var(--white);
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: all 0.25s;
        }
        
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        
        @media (max-width: 480px) {
            .item-img { width: 70px; height: 70px; }
            .item-name { font-size: 14px; }
        }
    </style>
</head>
<body>

<header class="header">
    <div class="header-main">
        <button class="btn-close" onclick="history.back()">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <h1 class="header-title">Sua sacola</h1>
        <?php if (!empty($items)): ?>
        <button class="header-clear" onclick="clearCart()">Limpar</button>
        <?php endif; ?>
    </div>
</header>

<main class="content">
    
    <?php if (empty($items)): ?>
    <!-- Empty State -->
    <div class="empty-state">
        <div class="empty-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
            </svg>
        </div>
        <h2>Sua sacola está vazia</h2>
        <p>Adicione itens para fazer seu pedido</p>
        <a href="/mercado/" class="btn-shop">Explorar produtos</a>
    </div>
    
    <?php else: ?>
    
    <!-- Store Card -->
    <div class="store-card">
        <div class="store-logo">🛒</div>
        <div class="store-info">
            <h2>OneMundo Mercado</h2>
            <p>25-40 min • Entrega</p>
        </div>
        <span class="store-arrow">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </span>
    </div>
    
    <!-- Delivery Promo -->
    <div class="delivery-promo">
        <div class="promo-content">
            <div class="promo-icon"><?= $delivery_fee === 0 ? '🎉' : '🚚' ?></div>
            <div class="promo-text">
                <?php if ($delivery_fee === 0): ?>
                <strong>Parabéns! Entrega grátis</strong>
                <span>Você desbloqueou a entrega gratuita</span>
                <?php else: ?>
                <strong>Faltam R$ <?= number_format($to_free_delivery, 2, ',', '.') ?> para entrega grátis</strong>
                <span>Em pedidos acima de R$ <?= number_format($free_delivery_min, 0) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="progress-wrap">
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= $progress ?>%"></div>
            </div>
        </div>
    </div>
    
    <!-- Items -->
    <div class="items-section">
        <div class="section-title"><?= $total_qty ?> <?= $total_qty === 1 ? 'item' : 'itens' ?></div>
        
        <?php foreach ($items as $item): ?>
        <div class="cart-item" data-id="<?= $item['id'] ?>">
            <div class="item-img">
                <?php if ($item['qty'] > 1): ?>
                <div class="item-qty-badge"><?= $item['qty'] ?></div>
                <?php endif; ?>
                <?php if (!empty($item['image'])): ?>
                <img src="<?= htmlspecialchars($item['image']) ?>" alt="" onerror="this.outerHTML='<div class=item-placeholder>🛒</div>'">
                <?php else: ?>
                <div class="item-placeholder">🛒</div>
                <?php endif; ?>
            </div>
            
            <div class="item-details">
                <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                
                <div class="item-bottom">
                    <div class="item-price">R$ <?= number_format($item['line_total'], 2, ',', '.') ?></div>
                    
                    <div class="item-controls">
                        <?php if ($item['qty'] === 1): ?>
                        <button class="ctrl-btn delete" onclick="removeItem(<?= $item['id'] ?>)">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                        <?php else: ?>
                        <button class="ctrl-btn" onclick="updateQty(<?= $item['id'] ?>, <?= $item['qty'] - 1 ?>)">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>
                        </button>
                        <?php endif; ?>
                        
                        <span class="ctrl-qty"><?= $item['qty'] ?></span>
                        
                        <button class="ctrl-btn" onclick="updateQty(<?= $item['id'] ?>, <?= $item['qty'] + 1 ?>)">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Add More -->
    <a href="/mercado/" class="add-more">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Adicionar mais itens
    </a>
    
    <!-- Summary -->
    <div class="summary">
        <div class="summary-title">Resumo de valores</div>
        
        <div class="summary-row">
            <span class="label">Subtotal</span>
            <span class="value">R$ <?= number_format($subtotal, 2, ',', '.') ?></span>
        </div>
        
        <div class="summary-row">
            <span class="label">Taxa de entrega</span>
            <span class="value <?= $delivery_fee === 0 ? 'free' : '' ?>"><?= $delivery_fee === 0 ? 'Grátis' : 'R$ ' . number_format($delivery_fee, 2, ',', '.') ?></span>
        </div>
        
        <div class="summary-row">
            <span class="label">Taxa de serviço</span>
            <span class="value">R$ <?= number_format($service_fee, 2, ',', '.') ?></span>
        </div>
        
        <div class="summary-row total">
            <span class="label">Total</span>
            <span class="value">R$ <?= number_format($total, 2, ',', '.') ?></span>
        </div>
    </div>
    
    <!-- Bottom Bar -->
    <div class="bottom-bar">
        <div class="bottom-inner">
            <div class="address-bar" onclick="location.href='/mercado/checkout.php'">
                <div class="address-icon">📍</div>
                <div class="address-info">
                    <div class="address-label">Entregar em</div>
                    <div class="address-text"><?= $address ? htmlspecialchars($address['address_1'] . ', ' . $address['city']) : 'Selecione um endereço' ?></div>
                </div>
                <span class="address-arrow">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </span>
            </div>
            
            <button class="btn-checkout" onclick="goCheckout()" <?= !$customer_id ? 'disabled' : '' ?>>
                <span><?= $customer_id ? 'Ir para pagamento' : 'Faça login para continuar' ?></span>
                <span class="btn-total">R$ <?= number_format($total, 2, ',', '.') ?></span>
            </button>
        </div>
    </div>
    
    <?php endif; ?>
    
</main>

<div class="toast" id="toast"></div>

<script>
function updateQty(id, qty) {
    if (qty < 1) return removeItem(id);
    
    fetch('/mercado/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'update', product_id: id, qty: qty})
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else toast('Erro ao atualizar');
    }).catch(() => toast('Erro de conexão'));
}

function removeItem(id) {
    const el = document.querySelector(`[data-id="${id}"]`);
    if (el) {
        el.style.transition = 'all 0.3s';
        el.style.opacity = '0';
        el.style.transform = 'translateX(-100%)';
    }
    
    fetch('/mercado/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'remove', product_id: id})
    }).then(() => setTimeout(() => location.reload(), 300));
}

function clearCart() {
    if (!confirm('Limpar toda a sacola?')) return;
    
    fetch('/mercado/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'clear'})
    }).then(() => location.reload());
}

function goCheckout() {
    location.href = '/mercado/checkout.php';
}

function toast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}
</script>

</body>
</html>
