<?php
/**
 * ONEMUNDO MERCADO - INSTALADOR 03 - PRODUTO PREMIUM
 * Substitui produto.php por versão premium
 */

$BASE = __DIR__;
$FILE = $BASE . '/produto.php';

// Backup
if (file_exists($FILE) && !file_exists($FILE . '.premium_backup')) {
    copy($FILE, $FILE . '.premium_backup');
}

$produto_php = <<<'PRODUTO'
<?php
/**
 * ONEMUNDO MERCADO - PRODUTO PREMIUM
 */
session_name('OCSESSID');
session_start();

require_once dirname(__DIR__) . '/config.php';

$pdo = new PDO("mysql:host=".DB_HOSTNAME.";dbname=".DB_DATABASE.";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$product_id = (int)($_GET['id'] ?? 0);
if (!$product_id) { header('Location: /mercado/'); exit; }

$partner_id = (int)($_SESSION['market_partner_id'] ?? 4);

$stmt = $pdo->prepare("SELECT pb.*, pp.price, pp.price_promo, pp.stock, c.name as category_name FROM om_market_products_base pb JOIN om_market_products_price pp ON pb.product_id = pp.product_id LEFT JOIN om_market_categories c ON pb.category_id = c.category_id WHERE pb.product_id = ? AND pp.partner_id = ? LIMIT 1");
$stmt->execute([$product_id, $partner_id]);
$produto = $stmt->fetch();

if (!$produto) { header('Location: /mercado/'); exit; }

$preco = (float)$produto['price'];
$preco_promo = (float)($produto['price_promo'] ?? 0);
$tem_promo = $preco_promo > 0 && $preco_promo < $preco;
$preco_final = $tem_promo ? $preco_promo : $preco;
$desconto = $tem_promo ? round((1 - $preco_promo / $preco) * 100) : 0;

// Relacionados
$stmt = $pdo->prepare("SELECT pb.product_id, pb.name, pb.image, pp.price, pp.price_promo FROM om_market_products_base pb JOIN om_market_products_price pp ON pb.product_id = pp.product_id WHERE pb.category_id = ? AND pb.product_id != ? AND pp.partner_id = ? LIMIT 8");
$stmt->execute([$produto['category_id'], $product_id, $partner_id]);
$relacionados = $stmt->fetchAll();

$page_title = $produto['name'] . ' - OneMundo Mercado';
require_once 'includes/header.php';
?>

<style>
.produto-container { max-width: 1100px; margin: 0 auto; padding: 24px 16px 100px; }
.produto-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; }
@media (max-width: 800px) { .produto-grid { grid-template-columns: 1fr; } }

.produto-gallery { background: #fff; border-radius: 20px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); position: sticky; top: 100px; }
.produto-gallery-main { aspect-ratio: 1; background: linear-gradient(180deg, #f0fdf4 0%, #dcfce7 100%); display: flex; align-items: center; justify-content: center; padding: 32px; position: relative; }
.produto-gallery-main img { max-width: 100%; max-height: 100%; object-fit: contain; }
.produto-desconto { position: absolute; top: 16px; left: 16px; background: #ef4444; color: #fff; padding: 8px 16px; border-radius: 8px; font-weight: 800; }

.produto-info { display: flex; flex-direction: column; gap: 16px; }
.produto-brand { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
.produto-nome { font-size: 28px; font-weight: 800; color: #1e293b; line-height: 1.3; }
.produto-meta { display: flex; gap: 16px; font-size: 13px; color: #64748b; }

.produto-preco-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
.produto-preco-row { display: flex; align-items: baseline; gap: 12px; margin-bottom: 4px; }
.produto-preco-old { font-size: 18px; color: #94a3b8; text-decoration: line-through; }
.produto-preco-atual { font-size: 36px; font-weight: 900; color: #1e293b; }
.produto-preco-unit { font-size: 14px; color: #64748b; margin-bottom: 16px; }

.produto-pix { display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: linear-gradient(135deg, #ecfdf5, #d1fae5); border-radius: 12px; border: 1px solid #a7f3d0; }
.produto-pix-icon { width: 36px; height: 36px; background: #10b981; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 12px; }
.produto-pix-text { font-size: 14px; }
.produto-pix-text strong { color: #059669; }

.produto-acoes { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
.produto-qty-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.produto-qty-label { font-weight: 600; }
.produto-qty { display: flex; align-items: center; gap: 4px; background: #f1f5f9; padding: 4px; border-radius: 12px; }
.produto-qty-btn { width: 44px; height: 44px; border: none; background: #fff; border-radius: 10px; font-size: 20px; font-weight: 600; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.06); }
.produto-qty-btn:hover { background: #10b981; color: #fff; }
.produto-qty-value { font-size: 20px; font-weight: 800; min-width: 50px; text-align: center; }

.produto-btn-add { width: 100%; padding: 18px; background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: none; border-radius: 14px; font-size: 16px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 6px 20px rgba(16,185,129,0.3); transition: all 0.2s; }
.produto-btn-add:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16,185,129,0.4); }
.produto-btn-add:disabled { background: #cbd5e1; cursor: not-allowed; transform: none; }
.produto-total { text-align: center; margin-top: 12px; font-size: 14px; color: #64748b; }
.produto-total strong { color: #059669; }

.relacionados { margin-top: 40px; padding-top: 32px; border-top: 1px solid #e2e8f0; }
.relacionados-title { font-size: 20px; font-weight: 800; margin-bottom: 20px; }
.relacionados-scroll { display: flex; gap: 14px; overflow-x: auto; padding-bottom: 12px; }
.relacionados-card { flex-shrink: 0; width: 160px; background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); text-decoration: none; color: inherit; transition: transform 0.2s; }
.relacionados-card:hover { transform: translateY(-4px); }
.relacionados-img { height: 120px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; padding: 12px; }
.relacionados-img img { max-width: 100%; max-height: 100%; object-fit: contain; }
.relacionados-info { padding: 12px; }
.relacionados-nome { font-size: 12px; font-weight: 600; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 32px; margin-bottom: 6px; }
.relacionados-preco { font-size: 15px; font-weight: 800; color: #059669; }

.produto-mobile-bar { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; padding: 12px 16px; border-top: 1px solid #e2e8f0; box-shadow: 0 -4px 20px rgba(0,0,0,0.08); display: none; align-items: center; gap: 12px; z-index: 100; }
@media (max-width: 800px) { .produto-mobile-bar { display: flex; } .produto-acoes { display: none; } .produto-gallery { position: relative; top: 0; } }
.produto-mobile-preco { flex: 1; }
.produto-mobile-preco-valor { font-size: 20px; font-weight: 800; }
.produto-mobile-btn { padding: 14px 28px; background: #10b981; color: #fff; border: none; border-radius: 12px; font-size: 15px; font-weight: 700; cursor: pointer; }

.om-toast { position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%) translateY(50px); background: #1e293b; color: #fff; padding: 14px 24px; border-radius: 12px; font-weight: 600; z-index: 9999; opacity: 0; transition: all 0.3s; }
.om-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
.om-toast.success { background: #059669; }
</style>

<div class="produto-container">
    <div class="produto-grid">
        <div class="produto-gallery">
            <div class="produto-gallery-main">
                <?php if ($tem_promo): ?><span class="produto-desconto">-<?= $desconto ?>%</span><?php endif; ?>
                <img src="<?= htmlspecialchars($produto['image'] ?: '/image/placeholder.jpg') ?>" alt="<?= htmlspecialchars($produto['name']) ?>">
            </div>
        </div>
        
        <div class="produto-info">
            <?php if ($produto['brand']): ?><p class="produto-brand"><?= htmlspecialchars($produto['brand']) ?></p><?php endif; ?>
            <h1 class="produto-nome"><?= htmlspecialchars($produto['name']) ?></h1>
            <div class="produto-meta">
                <span>📦 <?= htmlspecialchars($produto['unit'] ?? 'un') ?></span>
                <?php if ($produto['barcode']): ?><span>🔢 <?= htmlspecialchars($produto['barcode']) ?></span><?php endif; ?>
            </div>
            
            <div class="produto-preco-card">
                <div class="produto-preco-row">
                    <?php if ($tem_promo): ?><span class="produto-preco-old">R$ <?= number_format($preco, 2, ',', '.') ?></span><?php endif; ?>
                    <span class="produto-preco-atual">R$ <?= number_format($preco_final, 2, ',', '.') ?></span>
                </div>
                <p class="produto-preco-unit"><?= number_format($preco_final, 2, ',', '.') ?> / <?= $produto['unit'] ?? 'un' ?></p>
                <div class="produto-pix">
                    <div class="produto-pix-icon">PIX</div>
                    <div class="produto-pix-text">PIX com <strong>5% OFF</strong> = <strong>R$ <?= number_format($preco_final * 0.95, 2, ',', '.') ?></strong></div>
                </div>
            </div>
            
            <div class="produto-acoes">
                <div class="produto-qty-row">
                    <span class="produto-qty-label">Quantidade</span>
                    <div class="produto-qty">
                        <button class="produto-qty-btn" onclick="changeQty(-1)">−</button>
                        <span class="produto-qty-value" id="qtyValue">1</span>
                        <button class="produto-qty-btn" onclick="changeQty(1)">+</button>
                    </div>
                </div>
                <button class="produto-btn-add" id="btnAdd" onclick="addToCart()">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m6-6H6"/></svg>
                    <span id="btnText">Adicionar ao carrinho</span>
                </button>
                <p class="produto-total">Total: <strong id="totalValue">R$ <?= number_format($preco_final, 2, ',', '.') ?></strong></p>
            </div>
        </div>
    </div>
    
    <?php if (!empty($relacionados)): ?>
    <div class="relacionados">
        <h2 class="relacionados-title">Produtos Relacionados</h2>
        <div class="relacionados-scroll">
            <?php foreach ($relacionados as $r): $rPrice = ($r['price_promo'] > 0 && $r['price_promo'] < $r['price']) ? $r['price_promo'] : $r['price']; ?>
            <a href="/mercado/produto.php?id=<?= $r['product_id'] ?>" class="relacionados-card">
                <div class="relacionados-img"><img src="<?= htmlspecialchars($r['image'] ?: '/image/placeholder.jpg') ?>"></div>
                <div class="relacionados-info">
                    <div class="relacionados-nome"><?= htmlspecialchars($r['name']) ?></div>
                    <div class="relacionados-preco">R$ <?= number_format($rPrice, 2, ',', '.') ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="produto-mobile-bar">
    <div class="produto-mobile-preco">
        <div class="produto-mobile-preco-valor">R$ <?= number_format($preco_final, 2, ',', '.') ?></div>
    </div>
    <button class="produto-mobile-btn" onclick="addToCart()">Adicionar</button>
</div>

<div class="om-toast" id="toast"></div>

<script>
const productId = <?= $product_id ?>;
const unitPrice = <?= $preco_final ?>;
let qty = 1;

function changeQty(d) {
    qty = Math.max(1, Math.min(99, qty + d));
    document.getElementById('qtyValue').textContent = qty;
    document.getElementById('totalValue').textContent = 'R$ ' + (qty * unitPrice).toFixed(2).replace('.', ',');
}

function addToCart() {
    const btn = document.getElementById('btnAdd');
    const btnText = document.getElementById('btnText');
    if (btn) { btn.disabled = true; if (btnText) btnText.textContent = 'Adicionando...'; }
    
    fetch('/mercado/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'add', product_id: productId, qty: qty})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('✓ Adicionado ao carrinho!');
            document.querySelectorAll('.om-cart-badge').forEach(b => { b.textContent = data.count; b.style.display = 'flex'; });
            if (btnText) btnText.textContent = 'Adicionado!';
            setTimeout(() => { if (btn) btn.disabled = false; if (btnText) btnText.textContent = 'Adicionar ao carrinho'; }, 2000);
        } else {
            showToast('Erro ao adicionar');
            if (btn) btn.disabled = false;
            if (btnText) btnText.textContent = 'Adicionar ao carrinho';
        }
    }).catch(() => {
        showToast('Erro de conexão');
        if (btn) btn.disabled = false;
        if (btnText) btnText.textContent = 'Adicionar ao carrinho';
    });
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'om-toast success show';
    setTimeout(() => t.className = 'om-toast', 3000);
}
</script>

<?php require_once 'includes/footer.php'; ?>
PRODUTO;

$created = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['executar'])) {
    if (file_put_contents($FILE, $produto_php)) {
        $created = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador 03 - Produto</title>
    <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:system-ui;background:linear-gradient(135deg,#1e293b,#0f172a);min-height:100vh;padding:20px;color:#e2e8f0}.container{max-width:800px;margin:0 auto}.header{text-align:center;padding:40px;background:rgba(255,255,255,0.05);border-radius:20px;margin-bottom:30px}.header h1{font-size:32px;background:linear-gradient(135deg,#10b981,#34d399);-webkit-background-clip:text;-webkit-text-fill-color:transparent}.card{background:rgba(255,255,255,0.05);border-radius:16px;padding:24px;margin-bottom:20px}.success{background:rgba(16,185,129,0.2);text-align:center;padding:30px;border-radius:16px}.success h2{color:#10b981}.btn{display:inline-block;padding:16px 32px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:12px;font-weight:600;cursor:pointer;text-decoration:none}</style>
</head>
<body>
<div class="container">
    <div class="header"><h1>📦 Produto Premium</h1><p>Instalador 03 de 05</p></div>
    <?php if ($created): ?>
        <div class="success"><h2>✅ Produto Criado!</h2><p>Backup salvo em produto.php.premium_backup</p></div>
        <div style="text-align:center;margin-top:30px"><a href="04_instalar_carrinho.php" class="btn">Próximo: 04 - Carrinho →</a></div>
    <?php else: ?>
        <div class="card"><h3>📦 Será criado:</h3><ul style="margin:16px 0 0 20px;line-height:2"><li>Página de produto moderna</li><li>Galeria com desconto</li><li>Preço + PIX</li><li>Quantidade + Adicionar</li><li>Produtos relacionados</li><li>Mobile bar</li></ul></div>
        <div style="text-align:center"><form method="POST"><button type="submit" name="executar" class="btn">🚀 Criar Produto</button></form></div>
    <?php endif; ?>
</div>
</body>
</html>
