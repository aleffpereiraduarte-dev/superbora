<?php
/**
 * SUPERBORA CHECKOUT - Sistema Proprio
 * SEM OpenCart - usa classes proprias
 */

require_once __DIR__ . '/includes/om_bootstrap.php';

$cart = om_cart();
$config = om_config();
$customer = om_customer();

// Redirect se carrinho vazio
if (!$cart->hasProducts()) {
    header('Location: /carrinho_novo.php');
    exit;
}

// Dados do carrinho
$products = $cart->getProducts();
$subtotal = $cart->getSubTotal();
$total_items = $cart->countProducts();

// Dados do cliente
$customer_data = [];
if ($customer->isLogged()) {
    $customer_data = [
        'customer_id' => $customer->getId(),
        'firstname' => $customer->getFirstName(),
        'lastname' => $customer->getLastName(),
        'email' => $customer->getEmail(),
        'telephone' => $customer->getTelephone()
    ];

    $address = $customer->getAddress();
    if ($address) {
        $customer_data['address'] = $address;
    }
}

$store_name = $config->getStoreName();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finalizar Compra - <?= om_escape($store_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #FF6B00;
            --primary-dark: #E55A00;
            --secondary: #1a1a2e;
            --success: #10B981;
            --warning: #F59E0B;
            --error: #EF4444;
            --text: #1F2937;
            --text-light: #6B7280;
            --text-muted: #9CA3AF;
            --border: #E5E7EB;
            --bg: #F3F4F6;
            --white: #FFFFFF;
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --radius: 12px;
            --radius-sm: 8px;
        }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

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
        }

        .logo-text {
            font-size: 28px;
            font-weight: 800;
            color: #FFF;
        }

        .logo-text span { color: var(--primary); }

        .checkout-title {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #FFF;
            font-size: 18px;
            font-weight: 600;
            margin-left: auto;
        }

        .checkout-title i { color: #00D4AA; }

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

        .step-icon.done {
            background: var(--success);
            color: #FFF;
        }

        .step-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .step-label.active { color: var(--primary); }
        .step-label.done { color: var(--success); }

        .main {
            flex: 1;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 24px;
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 32px;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .main { grid-template-columns: 1fr; }
            .sidebar { order: -1; }
        }

        .card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            background: linear-gradient(135deg, #FAFAFA 0%, #F5F5F5 100%);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .card-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #FFF;
        }

        .card-icon.address { background: linear-gradient(135deg, #3B82F6, #1D4ED8); }
        .card-icon.shipping { background: linear-gradient(135deg, #10B981, #059669); }
        .card-icon.payment { background: linear-gradient(135deg, #F59E0B, #D97706); }

        .card-title-group { flex: 1; }
        .card-title { font-size: 18px; font-weight: 700; }
        .card-subtitle { font-size: 13px; color: var(--text-light); }

        .card-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .card-badge.success { background: #D1FAE5; color: #047857; }

        .card-body { padding: 24px; }

        .address-box {
            background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 100%);
            border: 1px solid #BAE6FD;
            border-radius: var(--radius-sm);
            padding: 20px;
        }

        .address-box p { line-height: 1.8; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-row-3 { grid-template-columns: 1fr 1fr 1fr; }

        @media (max-width: 600px) {
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
        }

        .form-group { margin-bottom: 20px; }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-group label span { color: var(--error); }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 14px 16px;
            font-size: 15px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(255,107,0,0.1);
        }

        .payment-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        @media (max-width: 600px) { .payment-options { grid-template-columns: 1fr; } }

        .payment-option {
            position: relative;
            background: var(--white);
            border: 2px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 16px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }

        .payment-option:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .payment-option.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, #FFF7ED 0%, #FFEDD5 100%);
        }

        .payment-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .payment-icon.pix { background: linear-gradient(135deg, #0D9488, #0F766E); color: #FFF; }
        .payment-icon.card { background: linear-gradient(135deg, #3B82F6, #1D4ED8); color: #FFF; }
        .payment-icon.boleto { background: linear-gradient(135deg, #F59E0B, #D97706); color: #FFF; }

        .payment-name { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
        .payment-desc { font-size: 13px; color: var(--text-light); margin-bottom: 12px; }

        .payment-tag {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .payment-tag.instant { background: #D1FAE5; color: #047857; }
        .payment-tag.parcelas { background: #DBEAFE; color: #1D4ED8; }
        .payment-tag.prazo { background: #FEF3C7; color: #92400E; }

        .payment-form {
            display: none;
            margin-top: 24px;
            padding: 24px;
            background: var(--bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .payment-form.active { display: block; }

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

        .summary-items {
            padding: 20px 24px;
            max-height: 300px;
            overflow-y: auto;
        }

        .summary-item {
            display: flex;
            gap: 16px;
            padding: 16px 0;
            border-bottom: 1px solid var(--border);
        }

        .summary-item:last-child { border-bottom: none; }

        .item-img {
            width: 70px;
            height: 70px;
            background: var(--bg);
            border-radius: var(--radius-sm);
            overflow: hidden;
            border: 1px solid var(--border);
        }

        .item-img img { width: 100%; height: 100%; object-fit: contain; }

        .item-info { flex: 1; min-width: 0; }

        .item-name {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 4px;
        }

        .item-qty { font-size: 13px; color: var(--text-muted); }

        .item-price {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary);
        }

        .summary-totals {
            padding: 20px 24px;
            background: var(--bg);
            border-top: 1px solid var(--border);
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
            color: var(--text-light);
        }

        .summary-row.total {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 2px solid var(--border);
            font-size: 20px;
            font-weight: 800;
            color: var(--text);
        }

        .summary-row.total span:last-child { color: var(--primary); }

        .summary-cta { padding: 24px; }

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

        .footer {
            background: var(--secondary);
            color: rgba(255,255,255,0.8);
            margin-top: auto;
            padding: 30px 24px;
            text-align: center;
        }

        .loading {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.95);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 20px;
        }

        .loading.active { display: flex; }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success { background: #D1FAE5; color: #047857; }
        .alert-error { background: #FEE2E2; color: #DC2626; }
    </style>
</head>
<body>
    <div class="loading" id="loading">
        <div class="spinner"></div>
        <span>Processando seu pedido...</span>
    </div>

    <header class="header">
        <div class="header-main">
            <div class="header-inner">
                <a href="/" class="logo">
                    <span class="logo-text">Super<span>Bora</span></span>
                </a>
                <div class="checkout-title">
                    <i class="fas fa-lock"></i>
                    Checkout Seguro
                </div>
            </div>
        </div>
    </header>

    <div class="progress-bar">
        <div class="progress-inner">
            <div class="progress-steps">
                <div class="progress-step">
                    <div class="step-icon done"><i class="fas fa-check"></i></div>
                    <span class="step-label done">Carrinho</span>
                </div>
                <div class="progress-step">
                    <div class="step-icon active">2</div>
                    <span class="step-label active">Checkout</span>
                </div>
                <div class="progress-step">
                    <div class="step-icon">3</div>
                    <span class="step-label">Confirmacao</span>
                </div>
            </div>
        </div>
    </div>

    <main class="main">
        <div class="content">
            <div id="messages"></div>

            <!-- ENDERECO -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon address"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="card-title-group">
                        <div class="card-title">Endereco de Entrega</div>
                        <div class="card-subtitle">Para onde devemos enviar seu pedido?</div>
                    </div>
                    <?php if (!empty($customer_data['address'])): ?>
                    <div class="card-badge success"><i class="fas fa-check"></i> Confirmado</div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($customer_data['address'])): ?>
                    <div class="address-box">
                        <p>
                            <strong><?= om_escape($customer_data['firstname'] . ' ' . $customer_data['lastname']) ?></strong><br>
                            <?= om_escape($customer_data['address']['address_1'] ?? '') ?>
                            <?php if(!empty($customer_data['address']['address_2'])): ?>, <?= om_escape($customer_data['address']['address_2']) ?><?php endif; ?><br>
                            <?= om_escape($customer_data['address']['city'] ?? '') ?> - <?= om_escape($customer_data['address']['zone_name'] ?? '') ?><br>
                            <strong>CEP:</strong> <?= om_escape($customer_data['address']['postcode'] ?? '') ?>
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>CEP <span>*</span></label>
                            <input type="text" id="cep" placeholder="00000-000" maxlength="9">
                        </div>
                        <div class="form-group">
                            <label>Numero <span>*</span></label>
                            <input type="text" id="numero" placeholder="123">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Endereco <span>*</span></label>
                        <input type="text" id="endereco" placeholder="Rua, Avenida...">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Bairro <span>*</span></label>
                            <input type="text" id="bairro" placeholder="Seu bairro">
                        </div>
                        <div class="form-group">
                            <label>Cidade <span>*</span></label>
                            <input type="text" id="cidade" placeholder="Sua cidade">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- FRETE -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon shipping"><i class="fas fa-truck"></i></div>
                    <div class="card-title-group">
                        <div class="card-title">Opcoes de Entrega</div>
                        <div class="card-subtitle">Escolha como deseja receber</div>
                    </div>
                </div>
                <div class="card-body">
                    <div id="shipping-options">
                        <div style="text-align: center; padding: 30px; color: var(--text-light);">
                            <i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                            Calculando opcoes de entrega...
                        </div>
                    </div>
                </div>
            </div>

            <!-- PAGAMENTO -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon payment"><i class="fas fa-credit-card"></i></div>
                    <div class="card-title-group">
                        <div class="card-title">Forma de Pagamento</div>
                        <div class="card-subtitle">Escolha como deseja pagar</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="payment-options">
                        <div class="payment-option" data-method="pix" onclick="selectPayment('pix')">
                            <div class="payment-icon pix"><i class="fas fa-qrcode"></i></div>
                            <div class="payment-name">PIX</div>
                            <div class="payment-desc">Aprovacao instantanea</div>
                            <span class="payment-tag instant">Instantaneo</span>
                        </div>
                        <div class="payment-option" data-method="card" onclick="selectPayment('card')">
                            <div class="payment-icon card"><i class="fas fa-credit-card"></i></div>
                            <div class="payment-name">Cartao de Credito</div>
                            <div class="payment-desc">Parcele em ate 12x</div>
                            <span class="payment-tag parcelas">Ate 12x</span>
                        </div>
                    </div>

                    <!-- Card Form - Stripe Elements abre em modal -->
                    <div class="payment-form" id="card-form">
                        <div style="text-align: center; padding: 30px;">
                            <i class="fas fa-credit-card" style="font-size: 64px; color: #635BFF; margin-bottom: 20px;"></i>
                            <h3>Cartao de Credito</h3>
                            <p style="color: var(--text-light); margin-bottom: 20px;">Pagamento seguro via Stripe</p>
                            <button type="button" class="btn btn-primary" style="max-width: 300px; margin: 0 auto;" onclick="OM29.abrirCartao(<?= $subtotal ?> + shippingCost)">
                                <i class="fas fa-lock"></i> Inserir Dados do Cartao
                            </button>
                            <div style="margin-top: 16px; display: flex; align-items: center; justify-content: center; gap: 8px; color: #64748b; font-size: 12px;">
                                <span>Powered by</span>
                                <svg viewBox="0 0 60 25" width="50" height="20"><path fill="#635BFF" d="M5.17 10.11c0-.59.48-.82 1.28-.82.73 0 1.65.22 2.38.61V7.68c-.8-.32-1.59-.44-2.38-.44-1.95 0-3.25 1.02-3.25 2.72 0 2.65 3.65 2.23 3.65 3.37 0 .7-.61.93-1.46.93-.84 0-1.93-.35-2.79-.82v2.26c.95.41 1.91.59 2.79.59 2 0 3.37-.99 3.37-2.71-.01-2.86-3.67-2.35-3.67-3.47z"/></svg>
                            </div>
                        </div>
                    </div>

                    <!-- PIX Form -->
                    <div class="payment-form" id="pix-form">
                        <div style="text-align: center; padding: 30px;">
                            <i class="fas fa-qrcode" style="font-size: 64px; color: #0D9488; margin-bottom: 20px;"></i>
                            <h3>Pague com PIX</h3>
                            <p style="color: var(--text-light);">O QR Code sera gerado apos confirmar o pedido</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="summary-card">
                <div class="summary-header">
                    <h3><i class="fas fa-shopping-bag"></i> Resumo do Pedido</h3>
                </div>
                <div class="summary-items">
                    <?php foreach ($products as $product): ?>
                    <div class="summary-item">
                        <div class="item-img">
                            <?php if (!empty($product['image'])): ?>
                            <img src="image/<?= om_escape($product['image']) ?>" alt="">
                            <?php endif; ?>
                        </div>
                        <div class="item-info">
                            <div class="item-name"><?= om_escape($product['name']) ?></div>
                            <div class="item-qty">Qtd: <?= $product['quantity'] ?></div>
                        </div>
                        <div class="item-price"><?= om_money($product['total']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="summary-totals">
                    <div class="summary-row">
                        <span>Subtotal (<?= $total_items ?> <?= $total_items > 1 ? 'itens' : 'item' ?>)</span>
                        <span><?= om_money($subtotal) ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Frete</span>
                        <span id="shipping-value">Calculando...</span>
                    </div>
                    <div class="summary-row total">
                        <span>Total</span>
                        <span id="total-value"><?= om_money($subtotal) ?></span>
                    </div>
                </div>
                <div class="summary-cta">
                    <button class="btn btn-primary" id="btn-finalizar" onclick="finalizarCompra()" disabled>
                        <i class="fas fa-lock"></i> Finalizar Compra
                    </button>
                    <a href="/carrinho_novo.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Voltar ao Carrinho
                    </a>
                </div>
            </div>
        </aside>
    </main>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> <?= om_escape($store_name) ?>. Todos os direitos reservados.</p>
    </footer>

    <!-- Stripe.js -->
    <script src="https://js.stripe.com/v3/"></script>
    <!-- Sistema de Pagamentos v29 (Stripe + Pagar.me PIX) -->
    <script src="/om_pagamentos_v29.js"></script>

    <script>
        let selectedPayment = null;
        let subtotal = <?= $subtotal ?>;
        let shippingCost = 0;

        // Simular calculo de frete
        setTimeout(() => {
            document.getElementById('shipping-options').innerHTML = `
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <label style="display: flex; align-items: center; gap: 16px; padding: 16px; background: var(--white); border: 2px solid var(--border); border-radius: 8px; cursor: pointer;" onclick="selectShipping(this, 15.90)">
                        <input type="radio" name="shipping" value="sedex">
                        <div style="flex: 1;">
                            <strong>SEDEX</strong>
                            <p style="font-size: 13px; color: var(--text-light); margin: 0;">Entrega em ate 3 dias uteis</p>
                        </div>
                        <span style="font-weight: 700; color: var(--primary);">R$ 15,90</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 16px; padding: 16px; background: var(--white); border: 2px solid var(--border); border-radius: 8px; cursor: pointer;" onclick="selectShipping(this, 9.90)">
                        <input type="radio" name="shipping" value="pac">
                        <div style="flex: 1;">
                            <strong>PAC</strong>
                            <p style="font-size: 13px; color: var(--text-light); margin: 0;">Entrega em ate 8 dias uteis</p>
                        </div>
                        <span style="font-weight: 700; color: var(--primary);">R$ 9,90</span>
                    </label>
                </div>
            `;
        }, 1500);

        function selectShipping(el, cost) {
            document.querySelectorAll('#shipping-options label').forEach(l => {
                l.style.borderColor = 'var(--border)';
                l.style.background = 'var(--white)';
            });
            el.style.borderColor = 'var(--primary)';
            el.style.background = '#FFF7ED';
            shippingCost = cost;
            document.getElementById('shipping-value').textContent = formatMoney(cost);
            document.getElementById('total-value').textContent = formatMoney(subtotal + cost);
            updateButton();
        }

        function selectPayment(method) {
            selectedPayment = method;
            document.querySelectorAll('.payment-option').forEach(el => el.classList.remove('selected'));
            document.querySelector(`.payment-option[data-method="${method}"]`).classList.add('selected');
            document.querySelectorAll('.payment-form').forEach(el => el.classList.remove('active'));

            // Abrir modal de pagamento correspondente
            if (method === 'pix' && window.OM29) {
                OM29.abrirPix(subtotal + shippingCost);
            } else if (method === 'card' && window.OM29) {
                OM29.abrirCartao(subtotal + shippingCost);
            } else {
                document.getElementById(`${method}-form`).classList.add('active');
            }
            updateButton();
        }

        // Verificar se pagamento foi confirmado
        function checkPaymentStatus() {
            try {
                var saved = sessionStorage.getItem('om_payment_state_v29');
                if (saved) {
                    var data = JSON.parse(saved);
                    return data.paid === true;
                }
            } catch(e) {}
            return false;
        }

        function getPaymentData() {
            try {
                var saved = sessionStorage.getItem('om_payment_state_v29');
                if (saved) return JSON.parse(saved);
            } catch(e) {}
            return null;
        }

        async function finalizarCompra() {
            if (shippingCost === 0) {
                showMessage('Selecione uma opcao de frete', 'error');
                return;
            }

            // Verificar se pagamento foi feito
            if (!checkPaymentStatus()) {
                showMessage('Realize o pagamento antes de finalizar', 'error');
                return;
            }

            var paymentData = getPaymentData();
            if (!paymentData) {
                showMessage('Erro ao recuperar dados do pagamento', 'error');
                return;
            }

            showLoading(true);

            try {
                // Criar pedido no backend
                var response = await fetch('/api/pagamento/finalizar_pedido.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        payment_method: paymentData.method,
                        payment_intent_id: paymentData.paymentIntentId,
                        charge_id: paymentData.chargeId,
                        total: subtotal + shippingCost,
                        shipping_cost: shippingCost,
                        shipping_method: document.querySelector('input[name="shipping"]:checked')?.value || 'pac'
                    })
                });

                var result = await response.json();

                if (result.success) {
                    // Limpar estado do pagamento
                    sessionStorage.removeItem('om_payment_state_v29');

                    showLoading(false);
                    showMessage('Pedido #' + result.order_id + ' realizado com sucesso!', 'success');

                    setTimeout(() => {
                        window.location.href = result.redirect || '/pedido-confirmado.php?id=' + result.order_id;
                    }, 2000);
                } else {
                    showLoading(false);
                    showMessage(result.error || 'Erro ao finalizar pedido', 'error');
                }
            } catch(e) {
                showLoading(false);
                showMessage('Erro de conexao: ' + e.message, 'error');
            }
        }

        function updateButton() {
            document.getElementById('btn-finalizar').disabled = !selectedPayment || shippingCost === 0;
        }

        function showMessage(msg, type) {
            const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
            document.getElementById('messages').innerHTML = `<div class="alert alert-${type}"><i class="fas fa-${icon}"></i>${msg}</div>`;
            setTimeout(() => document.getElementById('messages').innerHTML = '', 5000);
        }

        function showLoading(show) {
            document.getElementById('loading').classList.toggle('active', show);
        }

        function formatMoney(value) {
            return 'R$ ' + value.toFixed(2).replace('.', ',');
        }

        // Mascaras
        document.getElementById('card-number')?.addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '').replace(/(\d{4})(?=\d)/g, '$1 ');
            e.target.value = v.substring(0, 19);
        });

        document.getElementById('card-expiry')?.addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            if (v.length >= 2) v = v.substring(0,2) + '/' + v.substring(2);
            e.target.value = v.substring(0, 5);
        });
    </script>
</body>
</html>
