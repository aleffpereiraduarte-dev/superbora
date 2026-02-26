<?php
/**
 * SUPERBORA CARRINHO - Sistema Proprio
 * SEM OpenCart - usa classes proprias
 */

require_once __DIR__ . '/includes/om_bootstrap.php';

$cart = om_cart();
$config = om_config();
$customer = om_customer();

// API Actions
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'update' && isset($_POST['cart_id']) && isset($_POST['quantity'])) {
        $cart_id = (int)$_POST['cart_id'];
        $quantity = (int)$_POST['quantity'];
        $cart->update($cart_id, $quantity);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'remove' && isset($_POST['cart_id'])) {
        $cart_id = (int)$_POST['cart_id'];
        $cart->remove($cart_id);
        echo json_encode(['success' => true]);
        exit;
    }

    exit;
}

// Dados do carrinho
$products = $cart->getProducts();
$subtotal = $cart->getSubTotal();
$total_items = $cart->countProducts();

$store_name = $config->getStoreName();
$logo = $config->getLogo();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carrinho de Compras - <?= om_escape($store_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #FF6B00;
            --primary-dark: #E55A00;
            --primary-light: #FF8533;
            --secondary: #1a1a2e;
            --accent: #00D4AA;
            --success: #10B981;
            --warning: #F59E0B;
            --error: #EF4444;
            --text: #1F2937;
            --text-light: #6B7280;
            --text-muted: #9CA3AF;
            --border: #E5E7EB;
            --bg: #F3F4F6;
            --white: #FFFFFF;
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --radius: 12px;
            --radius-sm: 8px;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* HEADER */
        .header {
            background: linear-gradient(135deg, var(--secondary) 0%, #16213e 100%);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow-lg);
        }

        .header-main { padding: 16px 0; }

        .header-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            align-items: center;
            gap: 40px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            flex-shrink: 0;
        }

        .logo-text {
            font-size: 28px;
            font-weight: 800;
            color: #FFF;
            letter-spacing: -1px;
        }

        .logo-text span { color: var(--primary); }

        .search-box {
            flex: 1;
            max-width: 600px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 14px 50px 14px 20px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            background: var(--white);
        }

        .search-box button {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 36px;
            height: 36px;
            background: var(--primary);
            border: none;
            border-radius: 6px;
            color: #FFF;
            cursor: pointer;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .header-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            color: #FFF;
            text-decoration: none;
            font-size: 12px;
            transition: color 0.2s;
        }

        .header-action:hover { color: var(--primary); }
        .header-action i { font-size: 22px; }

        @media (max-width: 900px) {
            .search-box { display: none; }
            .header-inner { gap: 20px; }
        }

        @media (max-width: 600px) {
            .header-action span { display: none; }
        }

        /* PROGRESS BAR */
        .progress-bar {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            padding: 20px 0;
        }

        .progress-inner {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .progress-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
        }

        .progress-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 60px;
            right: 60px;
            height: 3px;
            background: var(--border);
            z-index: 1;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 2;
        }

        .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--border);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
        }

        .step-icon.active {
            background: var(--primary);
            color: #FFF;
            box-shadow: 0 0 0 4px rgba(255,107,0,0.2);
        }

        .step-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .step-label.active { color: var(--primary); }

        /* MAIN */
        .main {
            flex: 1;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 24px;
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 32px;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .main { grid-template-columns: 1fr; }
        }

        /* PAGE TITLE */
        .page-title {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            color: var(--text);
        }

        .page-title .count {
            background: var(--primary);
            color: #FFF;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        /* CART CARD */
        .cart-card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .cart-header {
            padding: 20px 24px;
            background: linear-gradient(135deg, #FAFAFA 0%, #F5F5F5 100%);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .cart-header h2 {
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .cart-header h2 i { color: var(--primary); }

        /* CART ITEM */
        .cart-item {
            padding: 24px;
            border-bottom: 1px solid var(--border);
            display: grid;
            grid-template-columns: 100px 1fr auto auto;
            gap: 20px;
            align-items: center;
            transition: background 0.2s;
        }

        .cart-item:hover { background: #FAFAFA; }
        .cart-item:last-child { border-bottom: none; }

        @media (max-width: 768px) {
            .cart-item {
                grid-template-columns: 80px 1fr;
                gap: 16px;
            }
            .item-actions { grid-column: 1 / -1; display: flex; justify-content: space-between; }
        }

        .item-image {
            width: 100px;
            height: 100px;
            background: var(--bg);
            border-radius: var(--radius-sm);
            overflow: hidden;
            border: 1px solid var(--border);
        }

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .item-details { min-width: 0; }

        .item-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
        }

        .item-name a {
            color: inherit;
            text-decoration: none;
        }

        .item-name a:hover { color: var(--primary); }

        .item-meta {
            font-size: 13px;
            color: var(--text-light);
        }

        .item-stock {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            font-size: 13px;
            color: var(--success);
        }

        .item-quantity {
            display: flex;
            align-items: center;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            overflow: hidden;
        }

        .qty-btn {
            width: 40px;
            height: 40px;
            background: var(--bg);
            border: none;
            cursor: pointer;
            font-size: 18px;
            color: var(--text);
            transition: all 0.2s;
        }

        .qty-btn:hover { background: var(--primary); color: #FFF; }

        .qty-input {
            width: 50px;
            height: 40px;
            border: none;
            text-align: center;
            font-size: 16px;
            font-weight: 600;
            background: var(--white);
        }

        .item-price { text-align: right; min-width: 120px; }

        .price-current {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary);
        }

        .item-actions { display: flex; flex-direction: column; gap: 8px; }

        .btn-remove {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px;
            border-radius: var(--radius-sm);
            transition: all 0.2s;
        }

        .btn-remove:hover { color: var(--error); background: #FEE2E2; }

        /* EMPTY CART */
        .empty-cart {
            text-align: center;
            padding: 60px 24px;
        }

        .empty-cart i {
            font-size: 80px;
            color: var(--border);
            margin-bottom: 24px;
        }

        .empty-cart h2 {
            font-size: 24px;
            color: var(--text);
            margin-bottom: 12px;
        }

        .empty-cart p {
            color: var(--text-light);
            margin-bottom: 24px;
        }

        /* SIDEBAR */
        .sidebar { position: sticky; top: 100px; }

        .summary-card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .summary-header {
            background: linear-gradient(135deg, var(--secondary) 0%, #16213e 100%);
            padding: 20px 24px;
            color: #FFF;
        }

        .summary-header h3 {
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary-header h3 i { color: var(--primary); }

        .summary-body { padding: 24px; }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 16px;
            font-size: 15px;
        }

        .summary-row.total {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid var(--border);
            font-size: 22px;
            font-weight: 800;
        }

        .summary-row.total span:last-child { color: var(--primary); }

        .summary-cta { padding: 0 24px 24px; }

        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 16px 24px;
            font-size: 16px;
            font-weight: 700;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #FFF;
            box-shadow: 0 4px 14px rgba(255,107,0,0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255,107,0,0.5);
        }

        .btn-primary:disabled {
            background: var(--border);
            color: var(--text-muted);
            box-shadow: none;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: var(--white);
            color: var(--text);
            border: 2px solid var(--border);
            margin-top: 12px;
        }

        .btn-secondary:hover { border-color: var(--primary); color: var(--primary); }

        /* BENEFITS */
        .benefits {
            margin-top: 20px;
            padding: 16px;
            background: #F0FDF4;
            border-radius: var(--radius-sm);
            border: 1px solid #BBF7D0;
        }

        .benefit {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #166534;
            margin-bottom: 8px;
        }

        .benefit:last-child { margin-bottom: 0; }
        .benefit i { color: var(--success); }

        /* FOOTER */
        .footer {
            background: var(--secondary);
            color: rgba(255,255,255,0.8);
            margin-top: auto;
            padding: 30px 24px;
            text-align: center;
        }

        /* LOADING */
        .loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.9);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .loading-overlay.active { display: flex; }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loading">
        <div class="spinner"></div>
    </div>

    <!-- HEADER SUPERBORA -->
    <header class="header">
        <div class="header-main">
            <div class="header-inner">
                <a href="/" class="logo">
                    <span class="logo-text">Super<span>Bora</span></span>
                </a>

                <div class="search-box">
                    <input type="text" placeholder="Buscar produtos...">
                    <button><i class="fas fa-search"></i></button>
                </div>

                <div class="header-actions">
                    <a href="/conta.php" class="header-action">
                        <i class="fas fa-user"></i>
                        <span><?= $customer->isLogged() ? om_escape($customer->getFirstName()) : 'Entrar' ?></span>
                    </a>
                    <a href="/carrinho_novo.php" class="header-action">
                        <i class="fas fa-shopping-cart"></i>
                        <span>Carrinho (<?= $total_items ?>)</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Progress Bar -->
    <div class="progress-bar">
        <div class="progress-inner">
            <div class="progress-steps">
                <div class="progress-step">
                    <div class="step-icon active">1</div>
                    <span class="step-label active">Carrinho</span>
                </div>
                <div class="progress-step">
                    <div class="step-icon">2</div>
                    <span class="step-label">Checkout</span>
                </div>
                <div class="progress-step">
                    <div class="step-icon">3</div>
                    <span class="step-label">Confirmacao</span>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN -->
    <main class="main">
        <div class="content">
            <div class="page-title">
                <h1><i class="fas fa-shopping-cart"></i> Carrinho de Compras</h1>
                <span class="count"><?= $total_items ?> <?= $total_items == 1 ? 'item' : 'itens' ?></span>
            </div>

            <?php if (empty($products)): ?>
            <!-- Empty Cart -->
            <div class="cart-card">
                <div class="empty-cart">
                    <i class="fas fa-shopping-cart"></i>
                    <h2>Seu carrinho esta vazio</h2>
                    <p>Adicione produtos para continuar comprando</p>
                    <a href="/" class="btn btn-primary" style="max-width: 300px; margin: 0 auto;">
                        <i class="fas fa-arrow-left"></i> Continuar Comprando
                    </a>
                </div>
            </div>
            <?php else: ?>
            <!-- Cart Items -->
            <div class="cart-card">
                <div class="cart-header">
                    <h2><i class="fas fa-box"></i> Produtos</h2>
                </div>

                <?php foreach ($products as $product): ?>
                <div class="cart-item" data-cart-id="<?= $product['cart_id'] ?>">
                    <div class="item-image">
                        <?php if (!empty($product['image'])): ?>
                        <img src="image/<?= om_escape($product['image']) ?>" alt="">
                        <?php else: ?>
                        <i class="fas fa-image" style="font-size: 32px; color: var(--text-muted);"></i>
                        <?php endif; ?>
                    </div>
                    <div class="item-details">
                        <h3 class="item-name">
                            <?= om_escape($product['name']) ?>
                        </h3>
                        <?php if (!empty($product['option'])): ?>
                        <div class="item-meta">
                            <?php foreach ($product['option'] as $option): ?>
                            <?= om_escape($option['name']) ?>: <?= om_escape($option['value']) ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="item-stock">
                            <i class="fas fa-check-circle"></i> Em estoque
                        </div>
                    </div>
                    <div class="item-quantity">
                        <button class="qty-btn" onclick="updateQty(<?= $product['cart_id'] ?>, -1)">-</button>
                        <input type="text" class="qty-input" value="<?= $product['quantity'] ?>" readonly>
                        <button class="qty-btn" onclick="updateQty(<?= $product['cart_id'] ?>, 1)">+</button>
                    </div>
                    <div class="item-price">
                        <div class="price-current"><?= om_money($product['total']) ?></div>
                    </div>
                    <div class="item-actions">
                        <button class="btn-remove" onclick="removeItem(<?= $product['cart_id'] ?>)">
                            <i class="fas fa-trash"></i> Remover
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="summary-card">
                <div class="summary-header">
                    <h3><i class="fas fa-receipt"></i> Resumo</h3>
                </div>
                <div class="summary-body">
                    <div class="summary-row">
                        <span>Subtotal (<?= $total_items ?> <?= $total_items == 1 ? 'item' : 'itens' ?>)</span>
                        <span><?= om_money($subtotal) ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Frete</span>
                        <span style="color: var(--success);">Calcular no checkout</span>
                    </div>
                    <div class="summary-row total">
                        <span>Total</span>
                        <span><?= om_money($subtotal) ?></span>
                    </div>
                </div>
                <div class="summary-cta">
                    <?php if (!empty($products)): ?>
                    <a href="/checkout_novo.php" class="btn btn-primary">
                        <i class="fas fa-lock"></i> Finalizar Compra
                    </a>
                    <?php else: ?>
                    <button class="btn btn-primary" disabled>
                        <i class="fas fa-lock"></i> Finalizar Compra
                    </button>
                    <?php endif; ?>
                    <a href="/" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Continuar Comprando
                    </a>
                </div>
            </div>

            <div class="benefits">
                <div class="benefit">
                    <i class="fas fa-truck"></i>
                    <span>Frete gratis acima de R$ 199</span>
                </div>
                <div class="benefit">
                    <i class="fas fa-shield-alt"></i>
                    <span>Compra 100% segura</span>
                </div>
                <div class="benefit">
                    <i class="fas fa-undo"></i>
                    <span>Troca e devolucao em ate 30 dias</span>
                </div>
            </div>
        </aside>
    </main>

    <!-- FOOTER -->
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> <?= om_escape($store_name) ?>. Todos os direitos reservados.</p>
    </footer>

    <script>
        function showLoading() { document.getElementById('loading').classList.add('active'); }
        function hideLoading() { document.getElementById('loading').classList.remove('active'); }

        function updateQty(cartId, delta) {
            const item = document.querySelector(`[data-cart-id="${cartId}"]`);
            const input = item.querySelector('.qty-input');
            let qty = parseInt(input.value) + delta;

            if (qty < 1) qty = 1;
            input.value = qty;

            showLoading();

            fetch(`/carrinho_novo.php?action=update`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `cart_id=${cartId}&quantity=${qty}`
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) location.reload();
            })
            .finally(() => hideLoading());
        }

        function removeItem(cartId) {
            if (!confirm('Remover este item do carrinho?')) return;

            showLoading();

            fetch(`/carrinho_novo.php?action=remove`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `cart_id=${cartId}`
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) location.reload();
            })
            .finally(() => hideLoading());
        }
    </script>
</body>
</html>
