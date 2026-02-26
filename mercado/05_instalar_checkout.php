<?php
/**
 * ONEMUNDO MERCADO - INSTALADOR 05 - CHECKOUT PREMIUM
 * Checkout completo com Pagar.me (PIX + Cartão)
 */

$BASE = __DIR__;
$FILE = $BASE . '/checkout.php';

if (file_exists($FILE) && !file_exists($FILE . '.premium_backup')) {
    copy($FILE, $FILE . '.premium_backup');
}

$checkout_php = <<<'CHECKOUT'
<?php
/**
 * ONEMUNDO MERCADO - CHECKOUT PREMIUM
 * PIX + Cartão de Crédito via Pagar.me
 */
session_name('OCSESSID');
session_start();

$customer_id = $_SESSION['customer_id'] ?? 0;
if (!$customer_id) { header('Location: /mercado/mercado-login.php?redirect=checkout.php'); exit; }

require_once dirname(__DIR__) . '/config.php';
$pdo = new PDO("mysql:host=".DB_HOSTNAME.";dbname=".DB_DATABASE.";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// Cliente
$customer = $pdo->query("SELECT * FROM oc_customer WHERE customer_id = $customer_id")->fetch();

// Endereços
$stmt = $pdo->prepare("SELECT a.*, z.name as zone_name, z.code as zone_code FROM oc_address a LEFT JOIN oc_zone z ON a.zone_id = z.zone_id WHERE a.customer_id = ? ORDER BY a.address_id = ? DESC");
$stmt->execute([$customer_id, $customer['address_id'] ?? 0]);
$addresses = $stmt->fetchAll();

// Carrinho
$cart = $_SESSION['market_cart'] ?? [];
if (empty($cart)) { header('Location: /mercado/carrinho.php'); exit; }

$items = array_values($cart);
$cart_count = 0;
$subtotal = 0;
foreach ($items as &$item) {
    $cart_count += $item['qty'];
    $price = ($item['price_promo'] ?? 0) > 0 ? $item['price_promo'] : $item['price'];
    $item['final_price'] = $price;
    $item['subtotal'] = $price * $item['qty'];
    $subtotal += $item['subtotal'];
}

$frete = $subtotal >= 99 ? 0 : 9.90;
$total = $subtotal + $frete;

// Pagar.me Public Key
$pagarme_pk = 'pk_6nEXG00upjhYONZv';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - OneMundo Mercado</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mercado/assets/css/om-premium.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f1f5f9; padding-bottom: 40px; }
        
        .checkout-header { background: linear-gradient(135deg, #047857, #059669, #10b981); padding: 20px; color: #fff; position: sticky; top: 0; z-index: 100; }
        .checkout-header-inner { max-width: 900px; margin: 0 auto; display: flex; align-items: center; gap: 16px; }
        .checkout-back { width: 44px; height: 44px; background: rgba(255,255,255,0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #fff; text-decoration: none; font-size: 20px; }
        .checkout-header h1 { font-size: 1.4rem; font-weight: 700; flex: 1; }
        .checkout-secure { display: flex; align-items: center; gap: 6px; font-size: 12px; opacity: 0.9; }
        
        .checkout-container { max-width: 900px; margin: 0 auto; padding: 24px 20px; display: grid; grid-template-columns: 1fr 360px; gap: 24px; }
        @media (max-width: 800px) { .checkout-container { grid-template-columns: 1fr; } }
        
        .checkout-card { background: #fff; border-radius: 16px; padding: 24px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .checkout-card-title { font-size: 16px; font-weight: 700; color: #1e293b; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
        .checkout-card-title i { color: #10b981; }
        
        .address-option { display: flex; gap: 12px; padding: 16px; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer; transition: all 0.2s; margin-bottom: 12px; }
        .address-option:hover, .address-option.selected { border-color: #10b981; background: #f0fdf4; }
        .address-radio { width: 20px; height: 20px; border: 2px solid #cbd5e1; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
        .address-option.selected .address-radio { border-color: #10b981; background: #10b981; }
        .address-option.selected .address-radio::after { content: ''; width: 8px; height: 8px; background: #fff; border-radius: 50%; }
        .address-details { flex: 1; }
        .address-line { font-weight: 600; color: #1e293b; margin-bottom: 4px; }
        .address-extra { font-size: 13px; color: #64748b; }
        
        .payment-methods { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 20px; }
        .payment-method { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 20px; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer; transition: all 0.2s; }
        .payment-method:hover, .payment-method.selected { border-color: #10b981; background: #f0fdf4; }
        .payment-method i { font-size: 28px; color: #64748b; }
        .payment-method.selected i { color: #10b981; }
        .payment-method span { font-size: 14px; font-weight: 600; color: #334155; }
        .payment-method input { display: none; }
        
        .card-form, .pix-info { display: none; }
        .card-form.show, .pix-info.show { display: block; }
        
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .form-input { width: 100%; padding: 14px 16px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 15px; transition: all 0.2s; }
        .form-input:focus { outline: none; border-color: #10b981; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        
        .pix-info { text-align: center; padding: 20px; }
        .pix-icon { font-size: 48px; color: #10b981; margin-bottom: 12px; }
        .pix-text { color: #64748b; font-size: 14px; }
        
        .order-summary { position: sticky; top: 100px; }
        .summary-items { max-height: 200px; overflow-y: auto; margin-bottom: 16px; }
        .summary-item { display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .summary-item:last-child { border: none; }
        .summary-item-img { width: 50px; height: 50px; background: #f8fafc; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; overflow: hidden; }
        .summary-item-img img { width: 100%; height: 100%; object-fit: cover; }
        .summary-item-info { flex: 1; min-width: 0; }
        .summary-item-name { font-size: 13px; font-weight: 500; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .summary-item-qty { font-size: 12px; color: #64748b; }
        .summary-item-price { font-weight: 700; color: #10b981; font-size: 14px; }
        
        .summary-row { display: flex; justify-content: space-between; padding: 10px 0; font-size: 14px; color: #64748b; }
        .summary-row.total { font-size: 20px; font-weight: 700; color: #1e293b; border-top: 2px solid #e2e8f0; margin-top: 8px; padding-top: 16px; }
        .frete-gratis { color: #10b981; font-weight: 600; }
        
        .checkout-btn { width: 100%; padding: 18px; background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: none; border-radius: 14px; font-size: 16px; font-weight: 700; cursor: pointer; margin-top: 16px; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.3s; }
        .checkout-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(16,185,129,0.3); }
        .checkout-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        
        .security-badges { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #f1f5f9; }
        .security-badge { display: flex; align-items: center; gap: 6px; font-size: 11px; color: #64748b; }
        .security-badge i { color: #10b981; }
        
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px; }
        .modal-overlay.show { display: flex; }
        .modal { background: #fff; border-radius: 20px; padding: 32px; max-width: 400px; width: 100%; text-align: center; }
        .modal-title { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
        .modal-subtitle { color: #64748b; margin-bottom: 24px; }
        .qr-code { width: 200px; height: 200px; background: #f8fafc; border-radius: 16px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; }
        .qr-code img { width: 180px; height: 180px; }
        .pix-code { background: #f8fafc; padding: 12px; border-radius: 10px; font-family: monospace; font-size: 11px; word-break: break-all; margin-bottom: 16px; cursor: pointer; }
        .copy-btn { padding: 12px 24px; background: #10b981; color: #fff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; }
        .modal-timer { margin-top: 16px; color: #64748b; font-size: 14px; }
        .modal-timer strong { color: #ef4444; }
        
        .toast { position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%) translateY(100px); background: #1e293b; color: #fff; padding: 16px 32px; border-radius: 50px; font-weight: 600; z-index: 2000; opacity: 0; transition: all 0.3s; }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: #10b981; }
        .toast.error { background: #ef4444; }
    </style>
</head>
<body>

<header class="checkout-header">
    <div class="checkout-header-inner">
        <a href="/mercado/carrinho.php" class="checkout-back"><i class="fas fa-arrow-left"></i></a>
        <h1>Finalizar Compra</h1>
        <span class="checkout-secure"><i class="fas fa-lock"></i> Ambiente Seguro</span>
    </div>
</header>

<div class="checkout-container">
    <div class="checkout-main">
        
        <!-- Endereço -->
        <div class="checkout-card">
            <h2 class="checkout-card-title"><i class="fas fa-map-marker-alt"></i> Endereço de Entrega</h2>
            <?php if (empty($addresses)): ?>
            <p style="color:#64748b;margin-bottom:16px">Você ainda não tem endereços cadastrados.</p>
            <a href="/index.php?route=account/address/add" style="color:#10b981;font-weight:600"><i class="fas fa-plus"></i> Adicionar Endereço</a>
            <?php else: ?>
            <?php foreach ($addresses as $i => $addr): ?>
            <label class="address-option <?= $i === 0 ? 'selected' : '' ?>">
                <input type="radio" name="address_id" value="<?= $addr['address_id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                <div class="address-radio"></div>
                <div class="address-details">
                    <div class="address-line"><?= htmlspecialchars($addr['address_1']) ?><?= $addr['address_2'] ? ', ' . htmlspecialchars($addr['address_2']) : '' ?></div>
                    <div class="address-extra"><?= htmlspecialchars($addr['city']) ?> - <?= $addr['zone_code'] ?? $addr['zone_name'] ?> • CEP <?= htmlspecialchars($addr['postcode']) ?></div>
                </div>
            </label>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Pagamento -->
        <div class="checkout-card">
            <h2 class="checkout-card-title"><i class="fas fa-credit-card"></i> Forma de Pagamento</h2>
            
            <div class="payment-methods">
                <label class="payment-method selected">
                    <input type="radio" name="payment_method" value="credit_card" checked>
                    <i class="fas fa-credit-card"></i>
                    <span>Cartão</span>
                </label>
                <label class="payment-method">
                    <input type="radio" name="payment_method" value="pix">
                    <i class="fas fa-qrcode"></i>
                    <span>PIX</span>
                </label>
            </div>
            
            <div class="card-form show" id="cardForm">
                <div class="form-group">
                    <label class="form-label">Número do Cartão</label>
                    <input type="text" class="form-input" id="cardNumber" placeholder="0000 0000 0000 0000" maxlength="19">
                </div>
                <div class="form-group">
                    <label class="form-label">Nome no Cartão</label>
                    <input type="text" class="form-input" id="cardName" placeholder="Como está no cartão" style="text-transform:uppercase">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Validade</label>
                        <input type="text" class="form-input" id="cardExpiry" placeholder="MM/AA" maxlength="5">
                    </div>
                    <div class="form-group">
                        <label class="form-label">CVV</label>
                        <input type="text" class="form-input" id="cardCvv" placeholder="123" maxlength="4">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Parcelas</label>
                    <select class="form-input" id="installments">
                        <option value="1">1x de R$ <?= number_format($total, 2, ',', '.') ?> (à vista)</option>
                        <?php for ($i = 2; $i <= 12; $i++): $p = $total / $i; if ($p >= 10): ?>
                        <option value="<?= $i ?>"><?= $i ?>x de R$ <?= number_format($p, 2, ',', '.') ?></option>
                        <?php endif; endfor; ?>
                    </select>
                </div>
            </div>
            
            <div class="pix-info" id="pixInfo">
                <div class="pix-icon"><i class="fas fa-qrcode"></i></div>
                <p class="pix-text">Ao confirmar, você receberá um QR Code para pagamento.<br><strong>Aprovação instantânea!</strong></p>
            </div>
        </div>
        
        <!-- CPF -->
        <div class="checkout-card">
            <h2 class="checkout-card-title"><i class="fas fa-id-card"></i> CPF do Titular</h2>
            <input type="text" class="form-input" id="cpf" placeholder="000.000.000-00" maxlength="14">
        </div>
        
    </div>
    
    <!-- Resumo -->
    <div class="checkout-sidebar">
        <div class="checkout-card order-summary">
            <h2 class="checkout-card-title"><i class="fas fa-shopping-bag"></i> Resumo</h2>
            
            <div class="summary-items">
                <?php foreach ($items as $item): ?>
                <div class="summary-item">
                    <div class="summary-item-img"><?php if (!empty($item['image'])): ?><img src="<?= htmlspecialchars($item['image']) ?>"><?php else: ?>🛒<?php endif; ?></div>
                    <div class="summary-item-info">
                        <div class="summary-item-name"><?= htmlspecialchars($item['name']) ?></div>
                        <div class="summary-item-qty"><?= $item['qty'] ?>x R$ <?= number_format($item['final_price'], 2, ',', '.') ?></div>
                    </div>
                    <div class="summary-item-price">R$ <?= number_format($item['subtotal'], 2, ',', '.') ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="summary-row"><span>Subtotal (<?= $cart_count ?> <?= $cart_count === 1 ? 'item' : 'itens' ?>)</span><span>R$ <?= number_format($subtotal, 2, ',', '.') ?></span></div>
            <div class="summary-row"><span>Entrega</span><span class="<?= $frete === 0 ? 'frete-gratis' : '' ?>"><?= $frete === 0 ? 'Grátis!' : 'R$ ' . number_format($frete, 2, ',', '.') ?></span></div>
            <div class="summary-row total"><span>Total</span><span>R$ <?= number_format($total, 2, ',', '.') ?></span></div>
            
            <button type="button" class="checkout-btn" id="checkoutBtn" onclick="processPayment()">
                <i class="fas fa-lock"></i> Pagar R$ <?= number_format($total, 2, ',', '.') ?>
            </button>
            
            <div class="security-badges">
                <span class="security-badge"><i class="fas fa-shield-alt"></i> Compra Segura</span>
                <span class="security-badge"><i class="fas fa-lock"></i> SSL</span>
                <span class="security-badge"><i class="fas fa-check-circle"></i> Pagar.me</span>
            </div>
        </div>
    </div>
</div>

<!-- Modal PIX -->
<div class="modal-overlay" id="pixModal">
    <div class="modal">
        <h3 class="modal-title">📱 Pague com PIX</h3>
        <p class="modal-subtitle">Escaneie o QR Code ou copie o código</p>
        <div class="qr-code" id="qrCode"><i class="fas fa-spinner fa-spin" style="font-size:32px;color:#64748b"></i></div>
        <div class="pix-code" id="pixCode" onclick="copyPixCode()">Gerando código...</div>
        <button class="copy-btn" onclick="copyPixCode()"><i class="fas fa-copy"></i> Copiar Código PIX</button>
        <div class="modal-timer">Expira em <strong id="pixTimer">15:00</strong></div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const orderData = {
    customer_id: <?= $customer_id ?>,
    customer_name: '<?= addslashes($customer['firstname'] ?? '') ?>',
    customer_email: '<?= addslashes($customer['email'] ?? '') ?>',
    items: <?= json_encode($items) ?>,
    subtotal: <?= $subtotal ?>,
    frete: <?= $frete ?>,
    total: <?= $total ?>
};

// Toggle pagamento
document.querySelectorAll('input[name="payment_method"]').forEach(input => {
    input.addEventListener('change', function() {
        document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('selected'));
        this.closest('.payment-method').classList.add('selected');
        document.getElementById('cardForm').classList.toggle('show', this.value === 'credit_card');
        document.getElementById('pixInfo').classList.toggle('show', this.value === 'pix');
    });
});

// Toggle endereço
document.querySelectorAll('input[name="address_id"]').forEach(input => {
    input.addEventListener('change', function() {
        document.querySelectorAll('.address-option').forEach(a => a.classList.remove('selected'));
        this.closest('.address-option').classList.add('selected');
    });
});

// Máscaras
document.getElementById('cardNumber').addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '');
    v = v.replace(/(\d{4})(?=\d)/g, '$1 ');
    e.target.value = v.substring(0, 19);
});

document.getElementById('cardExpiry').addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '');
    if (v.length >= 2) v = v.substring(0, 2) + '/' + v.substring(2, 4);
    e.target.value = v;
});

document.getElementById('cpf').addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '');
    v = v.replace(/(\d{3})(\d)/, '$1.$2');
    v = v.replace(/(\d{3})(\d)/, '$1.$2');
    v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    e.target.value = v;
});

async function processPayment() {
    const btn = document.getElementById('checkoutBtn');
    const method = document.querySelector('input[name="payment_method"]:checked').value;
    const addressId = document.querySelector('input[name="address_id"]:checked')?.value;
    const cpf = document.getElementById('cpf').value.replace(/\D/g, '');
    
    if (!addressId) { showToast('Selecione um endereço', 'error'); return; }
    if (!cpf || cpf.length !== 11) { showToast('CPF inválido', 'error'); return; }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
    
    try {
        if (method === 'credit_card') {
            await processCard(addressId, cpf);
        } else {
            await processPix(addressId, cpf);
        }
    } catch (error) {
        showToast(error.message || 'Erro ao processar', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-lock"></i> Pagar R$ <?= number_format($total, 2, ',', '.') ?>';
    }
}

async function processCard(addressId, cpf) {
    const cardNumber = document.getElementById('cardNumber').value.replace(/\s/g, '');
    const cardName = document.getElementById('cardName').value.toUpperCase();
    const cardExpiry = document.getElementById('cardExpiry').value.split('/');
    const cardCvv = document.getElementById('cardCvv').value;
    const installments = document.getElementById('installments').value;
    
    if (!cardNumber || cardNumber.length < 16) throw new Error('Número do cartão inválido');
    if (!cardName) throw new Error('Informe o nome no cartão');
    if (cardExpiry.length !== 2) throw new Error('Validade inválida');
    if (!cardCvv || cardCvv.length < 3) throw new Error('CVV inválido');
    
    const response = await fetch('/mercado/api/pagarme.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'card',
            address_id: addressId,
            cpf: cpf,
            card: { number: cardNumber, holder_name: cardName, exp_month: parseInt(cardExpiry[0]), exp_year: 2000 + parseInt(cardExpiry[1]), cvv: cardCvv },
            installments: parseInt(installments),
            ...orderData
        })
    });
    
    const result = await response.json();
    if (result.success) {
        showToast('Pagamento aprovado!', 'success');
        setTimeout(() => { window.location.href = '/mercado/pedido-confirmado.php?id=' + result.order_id; }, 1500);
    } else {
        throw new Error(result.error || 'Pagamento recusado');
    }
}

async function processPix(addressId, cpf) {
    const response = await fetch('/mercado/api/pagarme.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'pix', address_id: addressId, cpf: cpf, ...orderData })
    });
    
    const result = await response.json();
    if (result.success) {
        document.getElementById('pixModal').classList.add('show');
        document.getElementById('qrCode').innerHTML = `<img src="${result.qr_code_url}" alt="QR Code">`;
        document.getElementById('pixCode').textContent = result.pix_code;
        
        let seconds = 900;
        const timer = setInterval(() => {
            seconds--;
            const min = Math.floor(seconds / 60);
            const sec = seconds % 60;
            document.getElementById('pixTimer').textContent = min.toString().padStart(2, '0') + ':' + sec.toString().padStart(2, '0');
            if (seconds <= 0) { clearInterval(timer); document.getElementById('pixModal').classList.remove('show'); showToast('PIX expirado', 'error'); }
        }, 1000);
        
        const checkPayment = setInterval(async () => {
            const check = await fetch('/mercado/api/pagarme.php?action=check&order_id=' + result.order_id);
            const status = await check.json();
            if (status.paid) {
                clearInterval(checkPayment);
                clearInterval(timer);
                showToast('Pagamento confirmado!', 'success');
                setTimeout(() => { window.location.href = '/mercado/pedido-confirmado.php?id=' + result.order_id; }, 1500);
            }
        }, 5000);
    } else {
        throw new Error(result.error || 'Erro ao gerar PIX');
    }
}

function copyPixCode() {
    const code = document.getElementById('pixCode').textContent;
    navigator.clipboard.writeText(code).then(() => showToast('Código copiado!', 'success'));
}

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    setTimeout(() => t.classList.remove('show'), 3000);
}
</script>

</body>
</html>
CHECKOUT;

$created = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['executar'])) {
    if (file_put_contents($FILE, $checkout_php)) {
        $created = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador 05 - Checkout</title>
    <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:system-ui;background:linear-gradient(135deg,#1e293b,#0f172a);min-height:100vh;padding:20px;color:#e2e8f0}.container{max-width:800px;margin:0 auto}.header{text-align:center;padding:40px;background:rgba(255,255,255,0.05);border-radius:20px;margin-bottom:30px}.header h1{font-size:32px;background:linear-gradient(135deg,#10b981,#34d399);-webkit-background-clip:text;-webkit-text-fill-color:transparent}.card{background:rgba(255,255,255,0.05);border-radius:16px;padding:24px;margin-bottom:20px}.success{background:rgba(16,185,129,0.2);text-align:center;padding:30px;border-radius:16px}.success h2{color:#10b981}.btn{display:inline-block;padding:16px 32px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:12px;font-weight:600;cursor:pointer;text-decoration:none}</style>
</head>
<body>
<div class="container">
    <div class="header"><h1>💳 Checkout Premium</h1><p>Instalador 05 de 05 - FINAL</p></div>
    <?php if ($created): ?>
        <div class="success">
            <h2>✅ Checkout Criado!</h2>
            <p>Backup em checkout.php.premium_backup</p>
            <p style="margin-top:20px;font-size:18px">🎉 Instalação Completa!</p>
        </div>
        <div class="card">
            <h3>📋 Resumo da Instalação:</h3>
            <ul style="margin:16px 0 0 20px;line-height:2">
                <li>✅ CSS Premium Unificado</li>
                <li>✅ Header Universal</li>
                <li>✅ Página de Produto</li>
                <li>✅ Carrinho</li>
                <li>✅ Checkout com Pagar.me</li>
            </ul>
        </div>
        <div style="text-align:center;margin-top:30px">
            <a href="/mercado/" class="btn">🏠 Ir para o Mercado</a>
        </div>
    <?php else: ?>
        <div class="card"><h3>📦 Será criado:</h3><ul style="margin:16px 0 0 20px;line-height:2"><li>Checkout completo</li><li>Seleção de endereço</li><li>Cartão de Crédito com parcelamento</li><li>PIX com QR Code e timer</li><li>Modal de pagamento</li><li>Verificação automática</li></ul></div>
        <div style="text-align:center"><form method="POST"><button type="submit" name="executar" class="btn">🚀 Criar Checkout</button></form></div>
    <?php endif; ?>
</div>
</body>
</html>
