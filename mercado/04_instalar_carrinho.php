<?php
/**
 * ONEMUNDO MERCADO - INSTALADOR 04 - CARRINHO PREMIUM
 */

$BASE = __DIR__;
$FILE = $BASE . '/carrinho.php';

if (file_exists($FILE) && !file_exists($FILE . '.premium_backup')) {
    copy($FILE, $FILE . '.premium_backup');
}

$carrinho_php = <<<'CARRINHO'
<?php
/**
 * ONEMUNDO MERCADO - CARRINHO PREMIUM
 */
session_name('OCSESSID');
session_start();

require_once dirname(__DIR__) . '/config.php';
$pdo = new PDO("mysql:host=".DB_HOSTNAME.";dbname=".DB_DATABASE.";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$customer_id = $_SESSION['customer_id'] ?? 0;
if (!$customer_id) { header('Location: /mercado/mercado-login.php?redirect=carrinho.php'); exit; }

$cart = $_SESSION['market_cart'] ?? [];
$items = array_values($cart);
$subtotal = 0;
foreach ($items as &$item) {
    $price = ($item['price_promo'] ?? 0) > 0 ? $item['price_promo'] : $item['price'];
    $item['final_price'] = $price;
    $item['subtotal'] = $price * $item['qty'];
    $subtotal += $item['subtotal'];
}

$frete_minimo = 99;
$frete_valor = 9.90;
$frete = $subtotal >= $frete_minimo ? 0 : $frete_valor;
$total = $subtotal + $frete;
$falta_frete = max(0, $frete_minimo - $subtotal);
$progresso = min(100, ($subtotal / $frete_minimo) * 100);

$stmt = $pdo->prepare("SELECT * FROM oc_address WHERE customer_id = ? ORDER BY address_id DESC");
$stmt->execute([$customer_id]);
$addresses = $stmt->fetchAll();
$default_addr = $addresses[0] ?? null;

$page_title = 'Carrinho - OneMundo Mercado';
require_once 'includes/header.php';
?>
<style>
.carrinho-container { max-width: 800px; margin: 0 auto; padding: 20px 16px 200px; }
.carrinho-header { display: flex; align-items: center; gap: 16px; margin-bottom: 20px; }
.carrinho-back { width: 44px; height: 44px; background: #f1f5f9; border: none; border-radius: 12px; cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center; font-size: 20px; }
.carrinho-title { font-size: 24px; font-weight: 800; }
.frete-bar { background: linear-gradient(135deg, #10b981, #059669); color: #fff; padding: 14px 16px; border-radius: 12px; margin-bottom: 20px; }
.frete-bar.conseguiu { background: linear-gradient(135deg, #059669, #047857); }
.frete-bar-text { font-size: 14px; font-weight: 600; margin-bottom: 8px; }
.frete-bar-progress { height: 6px; background: rgba(255,255,255,0.3); border-radius: 3px; overflow: hidden; }
.frete-bar-fill { height: 100%; background: #fff; border-radius: 3px; transition: width 0.3s; }
.carrinho-empty { text-align: center; padding: 60px 20px; }
.carrinho-empty-icon { font-size: 80px; margin-bottom: 20px; }
.carrinho-empty h2 { font-size: 22px; color: #334155; margin-bottom: 8px; }
.carrinho-empty p { color: #64748b; margin-bottom: 24px; }
.carrinho-empty-btn { display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #10b981, #059669); color: #fff; text-decoration: none; border-radius: 12px; font-weight: 600; }
.carrinho-item { background: #fff; border-radius: 16px; padding: 16px; margin-bottom: 12px; display: flex; gap: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: transform 0.2s; }
.carrinho-item:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.carrinho-item-img { width: 80px; height: 80px; background: #f1f5f9; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 36px; flex-shrink: 0; overflow: hidden; }
.carrinho-item-img img { width: 100%; height: 100%; object-fit: cover; }
.carrinho-item-info { flex: 1; min-width: 0; }
.carrinho-item-nome { font-size: 15px; font-weight: 600; color: #1e293b; margin-bottom: 4px; }
.carrinho-item-preco { font-size: 18px; font-weight: 800; color: #10b981; margin-bottom: 8px; }
.carrinho-item-qty { display: flex; align-items: center; gap: 8px; }
.carrinho-qty-btn { width: 36px; height: 36px; border: 2px solid #e2e8f0; background: #fff; border-radius: 10px; font-size: 18px; cursor: pointer; }
.carrinho-qty-btn:hover { border-color: #10b981; color: #10b981; }
.carrinho-qty-val { font-size: 16px; font-weight: 700; min-width: 32px; text-align: center; }
.carrinho-item-remove { width: 44px; height: 44px; background: none; border: none; color: #ef4444; font-size: 22px; cursor: pointer; flex-shrink: 0; }
.carrinho-resumo { background: #fff; border-radius: 16px; padding: 20px; margin-top: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.carrinho-resumo h3 { font-size: 16px; color: #64748b; margin-bottom: 16px; }
.carrinho-resumo-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 15px; }
.carrinho-resumo-row .label { color: #64748b; }
.carrinho-resumo-row .value { font-weight: 600; }
.carrinho-resumo-row.total { font-size: 22px; font-weight: 800; border-top: 2px solid #f1f5f9; padding-top: 16px; margin-top: 16px; }
.carrinho-resumo-row.total .label { color: #1e293b; }
.carrinho-resumo-row.total .value { color: #10b981; }
.frete-gratis { color: #10b981; font-weight: 700; }
.carrinho-bottom { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid #e2e8f0; padding: 16px 20px; z-index: 100; }
.carrinho-bottom-inner { max-width: 800px; margin: 0 auto; }
.carrinho-endereco { background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; display: flex; align-items: center; gap: 12px; cursor: pointer; }
.carrinho-endereco:hover { border-color: #10b981; }
.carrinho-endereco-icon { font-size: 24px; }
.carrinho-endereco-info { flex: 1; }
.carrinho-endereco-label { font-size: 12px; color: #64748b; }
.carrinho-endereco-text { font-size: 14px; font-weight: 600; color: #1e293b; }
.carrinho-btn-checkout { width: 100%; padding: 18px; background: linear-gradient(135deg, #10b981, #059669); border: none; border-radius: 14px; color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
.carrinho-btn-checkout:disabled { background: #94a3b8; cursor: not-allowed; }
</style>
<div class="carrinho-container">
    <div class="carrinho-header">
        <a href="/mercado/" class="carrinho-back">←</a>
        <h1 class="carrinho-title">🛒 Carrinho</h1>
    </div>
    <?php if ($subtotal > 0): ?>
    <div class="frete-bar <?= $frete === 0 ? 'conseguiu' : '' ?>">
        <div class="frete-bar-text"><?php if ($frete === 0): ?>🎉 Parabéns! Você ganhou frete grátis!<?php else: ?>Faltam R$ <?= number_format($falta_frete, 2, ',', '.') ?> para frete grátis!<?php endif; ?></div>
        <div class="frete-bar-progress"><div class="frete-bar-fill" style="width:<?= $progresso ?>%"></div></div>
    </div>
    <?php endif; ?>
    <?php if (empty($items)): ?>
    <div class="carrinho-empty">
        <div class="carrinho-empty-icon">🛒</div>
        <h2>Seu carrinho está vazio</h2>
        <p>Adicione produtos para continuar</p>
        <a href="/mercado/" class="carrinho-empty-btn">Ir para o Mercado</a>
    </div>
    <?php else: ?>
        <?php foreach ($items as $i => $item): ?>
        <div class="carrinho-item" data-id="<?= $item['id'] ?>">
            <div class="carrinho-item-img"><?php if (!empty($item['image'])): ?><img src="<?= htmlspecialchars($item['image']) ?>"><?php else: ?>🛒<?php endif; ?></div>
            <div class="carrinho-item-info">
                <div class="carrinho-item-nome"><?= htmlspecialchars($item['name']) ?></div>
                <div class="carrinho-item-preco">R$ <?= number_format($item['final_price'], 2, ',', '.') ?></div>
                <div class="carrinho-item-qty">
                    <button class="carrinho-qty-btn" onclick="updateQty(<?= $item['id'] ?>, <?= $item['qty'] - 1 ?>)">−</button>
                    <span class="carrinho-qty-val"><?= $item['qty'] ?></span>
                    <button class="carrinho-qty-btn" onclick="updateQty(<?= $item['id'] ?>, <?= $item['qty'] + 1 ?>)">+</button>
                </div>
            </div>
            <button class="carrinho-item-remove" onclick="removeItem(<?= $item['id'] ?>)">🗑️</button>
        </div>
        <?php endforeach; ?>
        <div class="carrinho-resumo">
            <h3>Resumo do Pedido</h3>
            <div class="carrinho-resumo-row"><span class="label">Subtotal</span><span class="value">R$ <?= number_format($subtotal, 2, ',', '.') ?></span></div>
            <div class="carrinho-resumo-row"><span class="label">Entrega</span><span class="value <?= $frete === 0 ? 'frete-gratis' : '' ?>"><?= $frete === 0 ? 'Grátis!' : 'R$ ' . number_format($frete, 2, ',', '.') ?></span></div>
            <div class="carrinho-resumo-row total"><span class="label">Total</span><span class="value">R$ <?= number_format($total, 2, ',', '.') ?></span></div>
        </div>
    <?php endif; ?>
</div>
<?php if (!empty($items)): ?>
<div class="carrinho-bottom">
    <div class="carrinho-bottom-inner">
        <div class="carrinho-endereco" onclick="location.href='/mercado/checkout.php'">
            <span class="carrinho-endereco-icon">📍</span>
            <div class="carrinho-endereco-info">
                <div class="carrinho-endereco-label">Entregar em</div>
                <div class="carrinho-endereco-text"><?= $default_addr ? htmlspecialchars($default_addr['address_1'] . ', ' . $default_addr['city']) : 'Selecione um endereço' ?></div>
            </div>
            <span>›</span>
        </div>
        <button class="carrinho-btn-checkout" onclick="location.href='/mercado/checkout.php'" <?= !$default_addr ? 'disabled' : '' ?>>
            <span>Finalizar Pedido</span>
            <span>R$ <?= number_format($total, 2, ',', '.') ?></span>
        </button>
    </div>
</div>
<?php endif; ?>
<script>
function updateQty(id, qty) {
    fetch('/mercado/api/cart.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'update', product_id: id, qty: qty }) }).then(() => location.reload());
}
function removeItem(id) {
    if (!confirm('Remover item?')) return;
    fetch('/mercado/api/cart.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'remove', product_id: id }) }).then(() => location.reload());
}
</script>
<?php require_once 'includes/footer.php'; ?>
CARRINHO;

$created = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['executar'])) {
    if (file_put_contents($FILE, $carrinho_php)) { $created = true; }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador 04 - Carrinho</title>
    <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:system-ui;background:linear-gradient(135deg,#1e293b,#0f172a);min-height:100vh;padding:20px;color:#e2e8f0}.container{max-width:800px;margin:0 auto}.header{text-align:center;padding:40px;background:rgba(255,255,255,0.05);border-radius:20px;margin-bottom:30px}.header h1{font-size:32px;background:linear-gradient(135deg,#10b981,#34d399);-webkit-background-clip:text;-webkit-text-fill-color:transparent}.card{background:rgba(255,255,255,0.05);border-radius:16px;padding:24px;margin-bottom:20px}.success{background:rgba(16,185,129,0.2);text-align:center;padding:30px;border-radius:16px}.success h2{color:#10b981}.btn{display:inline-block;padding:16px 32px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:12px;font-weight:600;cursor:pointer;text-decoration:none}</style>
</head>
<body>
<div class="container">
    <div class="header"><h1>🛒 Carrinho Premium</h1><p>Instalador 04 de 05</p></div>
    <?php if ($created): ?>
        <div class="success"><h2>✅ Carrinho Criado!</h2></div>
        <div style="text-align:center;margin-top:30px"><a href="05_instalar_checkout.php" class="btn">Próximo: 05 - Checkout →</a></div>
    <?php else: ?>
        <div class="card"><h3>📦 Será criado:</h3><ul style="margin:16px 0 0 20px;line-height:2"><li>Barra de progresso frete grátis</li><li>Cards de item com hover</li><li>Controle de quantidade</li><li>Resumo + Bottom bar</li></ul></div>
        <div style="text-align:center"><form method="POST"><button type="submit" name="executar" class="btn">🚀 Criar Carrinho</button></form></div>
    <?php endif; ?>
</div>
</body>
</html>
