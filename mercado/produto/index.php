<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ONEMUNDO MERCADO - PÁGINA DE PRODUTO v4
 * Design: Clean, Moderno, Profissional
 * FIX: Compatível com config.php local e OpenCart
 */

if (session_status() === PHP_SESSION_NONE) { 
    session_name('OCSESSID'); 
    session_start(); 
}

// Tentar carregar config do OpenCart primeiro
$_oc_root = dirname(dirname(__DIR__)); // /public_html
$_mercado_root = dirname(__DIR__); // /public_html/mercado

// Conexão - Tentar múltiplas fontes
$pdo = null;

// 1. Tentar OpenCart config
if (file_exists($_oc_root . '/config.php')) {
    require_once($_oc_root . '/config.php');
    if (defined('DB_HOSTNAME') && defined('DB_DATABASE')) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
                DB_USERNAME, DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            $pdo = null;
        }
    }
}

// 2. Fallback para config local do mercado
if (!$pdo && file_exists($_mercado_root . '/config.php')) {
    include($_mercado_root . '/config.php');
    if (isset($db_host) && isset($db_name)) {
        try {
            $pdo = new PDO(
                "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
                $db_user, $db_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            $pdo = null;
        }
    }
}

// 3. Conexão direta hardcoded como último recurso
if (!$pdo) {
    try {
        $pdo = getPDO();
    } catch (PDOException $e) {
        die('<div style="text-align:center;padding:50px;font-family:sans-serif;"><h2>🔴 Erro de Conexão</h2><p>Não foi possível conectar ao banco de dados.</p></div>');
    }
}

$product_id = (int)($_GET['id'] ?? 0);
if (!$product_id) {
    header('Location: /mercado/');
    exit;
}

$customer_id = (int)($_SESSION['customer_id'] ?? 0);
$partner_id = (int)($_SESSION['market_partner_id'] ?? 4);

// Buscar produto
$stmt = $pdo->prepare("
    SELECT pb.*, pp.price, pp.price_promo, pp.stock,
           c.name as category_name
    FROM om_market_products_base pb
    JOIN om_market_products_price pp ON pb.product_id = pp.product_id
    LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
    WHERE pb.product_id = ? AND pp.partner_id = ?
    LIMIT 1
");
$stmt->execute([$product_id, $partner_id]);
$produto = $stmt->fetch();

if (!$produto) {
    // Tentar buscar sem partner_id específico
    $stmt = $pdo->prepare("
        SELECT pb.*, pp.price, pp.price_promo, pp.stock,
               c.name as category_name
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
        WHERE pb.product_id = ?
        LIMIT 1
    ");
    $stmt->execute([$product_id]);
    $produto = $stmt->fetch();
}

if (!$produto) {
    header('Location: /mercado/?erro=produto_nao_encontrado');
    exit;
}

// Calcular preços
$preco_original = (float)$produto['price'];
$preco_promo = (float)($produto['price_promo'] ?? 0);
$tem_promo = $preco_promo > 0 && $preco_promo < $preco_original;
$preco_final = $tem_promo ? $preco_promo : $preco_original;
$desconto = $tem_promo ? round((1 - $preco_promo / $preco_original) * 100) : 0;
$preco_pix = $preco_final * 0.95;

$imagem = $produto['image'] ?: '/image/placeholder.jpg';
$unidade = $produto['unit'] ?? 'un';
$barcode = $produto['barcode'] ?? $produto['sku'] ?? '';

// Nutri-Score
$nutri = $produto['nutri_score'] ?? null;
$nutri_labels = ['A' => 'Excelente', 'B' => 'Bom', 'C' => 'Regular', 'D' => 'Ruim', 'E' => 'Muito Ruim'];
$nutri_colors = ['A' => '#038141', 'B' => '#85bb2f', 'C' => '#fecb02', 'D' => '#ee8100', 'E' => '#e63e11'];

// Produtos Relacionados
$relacionados = [];
if ($produto['category_id']) {
    $stmt = $pdo->prepare("
        SELECT pb.product_id, pb.name, pb.brand, pb.image, pp.price, pp.price_promo
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        WHERE pb.category_id = ? AND pb.product_id != ?
        LIMIT 10
    ");
    $stmt->execute([$produto['category_id'], $product_id]);
    $relacionados = $stmt->fetchAll();
}

// Carrinho
$cart = $_SESSION['market_cart'] ?? [];
$cart_count = 0;
foreach ($cart as $item) {
    $cart_count += (int)($item['qty'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($produto['name']) ?> - OneMundo Mercado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --danger: #ef4444;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #94a3b8;
            --light: #f1f5f9;
            --white: #fff;
        }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f8fafc;
            color: var(--dark);
            line-height: 1.5;
        }
        
        /* Header */
        .header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--white);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .btn-back {
            width: 44px;
            height: 44px;
            border: none;
            background: var(--light);
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: var(--dark);
        }
        .btn-back:hover { background: #e2e8f0; }
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 20px;
        }
        .logo-text { font-size: 20px; font-weight: 800; color: var(--dark); }
        .header-spacer { flex: 1; }
        .btn-cart {
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--primary);
            border: none;
            border-radius: 12px;
            color: var(--white);
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .cart-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            min-width: 20px;
            height: 20px;
            background: var(--danger);
            color: var(--white);
            font-size: 11px;
            font-weight: 700;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Main */
        .main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 16px 120px;
        }
        .product-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            align-items: start;
        }
        @media (max-width: 800px) {
            .product-grid { grid-template-columns: 1fr; }
        }
        
        /* Gallery */
        .gallery {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            position: sticky;
            top: 80px;
        }
        .gallery-main {
            position: relative;
            aspect-ratio: 1;
            background: linear-gradient(180deg, #f0fdf4 0%, #dcfce7 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
        }
        .gallery-main img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .promo-tag {
            position: absolute;
            top: 16px;
            left: 16px;
            background: var(--danger);
            color: var(--white);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 800;
        }
        
        /* Info */
        .info { display: flex; flex-direction: column; gap: 16px; }
        .stock-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            background: #d1fae5;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--primary-dark);
            width: fit-content;
        }
        .stock-badge::before {
            content: '';
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
        }
        .brand {
            font-size: 13px;
            font-weight: 700;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .name {
            font-size: 26px;
            font-weight: 800;
            color: var(--dark);
            line-height: 1.3;
        }
        .meta {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: var(--gray);
        }
        
        /* Price Card */
        .price-card {
            background: var(--white);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .price-row {
            display: flex;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 4px;
        }
        .price-old {
            font-size: 18px;
            color: var(--gray-light);
            text-decoration: line-through;
        }
        .price-current {
            font-size: 36px;
            font-weight: 900;
            color: var(--dark);
        }
        .price-unit {
            font-size: 14px;
            color: var(--gray);
            margin-bottom: 16px;
        }
        .pix-box {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border-radius: 12px;
            border: 1px solid #a7f3d0;
        }
        .pix-icon {
            width: 36px;
            height: 36px;
            background: var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 800;
            font-size: 12px;
        }
        .pix-text { font-size: 14px; color: var(--dark); }
        .pix-text strong { color: var(--primary-dark); }
        
        /* Actions */
        .action-card {
            background: var(--white);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .qty-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .qty-label { font-weight: 600; color: var(--dark); }
        .qty-controls {
            display: flex;
            align-items: center;
            gap: 4px;
            background: var(--light);
            padding: 4px;
            border-radius: 12px;
        }
        .qty-btn {
            width: 44px;
            height: 44px;
            border: none;
            background: var(--white);
            border-radius: 10px;
            font-size: 20px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }
        .qty-btn:hover { background: var(--primary); color: var(--white); }
        .qty-value {
            font-size: 20px;
            font-weight: 800;
            min-width: 50px;
            text-align: center;
        }
        .btn-add {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--white);
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 6px 20px rgba(16,185,129,0.3);
            transition: all 0.2s;
        }
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16,185,129,0.4); }
        .btn-add:disabled { background: #cbd5e1; cursor: not-allowed; transform: none; box-shadow: none; }
        .total-row {
            text-align: center;
            margin-top: 12px;
            font-size: 14px;
            color: var(--gray);
        }
        .total-row strong { color: var(--primary-dark); font-weight: 700; }
        
        /* Related */
        .related { margin-top: 40px; padding-top: 32px; border-top: 1px solid #e2e8f0; }
        .related-title { font-size: 20px; font-weight: 800; margin-bottom: 20px; }
        .related-scroll {
            display: flex;
            gap: 14px;
            overflow-x: auto;
            padding-bottom: 12px;
            -webkit-overflow-scrolling: touch;
        }
        .related-card {
            flex-shrink: 0;
            width: 160px;
            background: var(--white);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s;
        }
        .related-card:hover { transform: translateY(-4px); }
        .related-img {
            height: 120px;
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
        }
        .related-img img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .related-info { padding: 12px; }
        .related-name {
            font-size: 12px;
            font-weight: 600;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 32px;
            margin-bottom: 6px;
        }
        .related-price { font-size: 15px; font-weight: 800; color: var(--primary-dark); }
        
        /* Mobile Bar */
        .mobile-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            padding: 12px 16px;
            border-top: 1px solid #e2e8f0;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.08);
            display: none;
            align-items: center;
            gap: 12px;
            z-index: 100;
        }
        @media (max-width: 800px) {
            .mobile-bar { display: flex; }
            .action-card { display: none; }
            .gallery { position: relative; top: 0; }
        }
        .mobile-price { flex: 1; }
        .mobile-price-value { font-size: 20px; font-weight: 800; }
        .mobile-price-unit { font-size: 12px; color: var(--gray); }
        .mobile-btn {
            padding: 14px 28px;
            background: var(--primary);
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }
        
        /* Toast */
        .toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(50px);
            background: var(--dark);
            color: var(--white);
            padding: 14px 24px;
            border-radius: 12px;
            font-weight: 600;
            z-index: 9999;
            opacity: 0;
            transition: all 0.3s;
        }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: var(--primary-dark); }
    </style>
</head>
<body>
    <header class="header">
        <a href="javascript:history.back()" class="btn-back">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <a href="/mercado/" class="logo">
            <div class="logo-icon">🛒</div>
            <span class="logo-text">Mercado</span>
        </a>
        <div class="header-spacer"></div>
        <a href="/mercado/carrinho.php" class="btn-cart">
            🛒 Carrinho
            <?php if ($cart_count > 0): ?><span class="cart-badge"><?= $cart_count ?></span><?php endif; ?>
        </a>
    </header>
    
    <main class="main">
        <div class="product-grid">
            <div class="gallery">
                <div class="gallery-main">
                    <?php if ($tem_promo): ?><span class="promo-tag">-<?= $desconto ?>%</span><?php endif; ?>
                    <img src="<?= htmlspecialchars($imagem) ?>" alt="<?= htmlspecialchars($produto['name']) ?>" onerror="this.src='/image/placeholder.jpg'">
                </div>
            </div>
            
            <div class="info">
                <div class="stock-badge">Disponível em estoque</div>
                
                <?php if (!empty($produto['brand'])): ?>
                <p class="brand"><?= htmlspecialchars($produto['brand']) ?></p>
                <?php endif; ?>
                
                <h1 class="name"><?= htmlspecialchars($produto['name']) ?></h1>
                
                <div class="meta">
                    <span>📦 <?= htmlspecialchars($unidade) ?></span>
                    <?php if ($barcode): ?><span>🔢 <?= htmlspecialchars($barcode) ?></span><?php endif; ?>
                </div>
                
                <div class="price-card">
                    <div class="price-row">
                        <?php if ($tem_promo): ?><span class="price-old">R$ <?= number_format($preco_original, 2, ',', '.') ?></span><?php endif; ?>
                        <span class="price-current">R$ <?= number_format($preco_final, 2, ',', '.') ?></span>
                    </div>
                    <p class="price-unit"><?= number_format($preco_final, 2, ',', '.') ?> / <?= $unidade ?></p>
                    <div class="pix-box">
                        <div class="pix-icon">PIX</div>
                        <div class="pix-text">PIX com <strong>5% OFF</strong> = <strong>R$ <?= number_format($preco_pix, 2, ',', '.') ?></strong></div>
                    </div>
                </div>
                
                <div class="action-card">
                    <div class="qty-row">
                        <span class="qty-label">Quantidade</span>
                        <div class="qty-controls">
                            <button class="qty-btn" onclick="changeQty(-1)">−</button>
                            <span class="qty-value" id="qtyValue">1</span>
                            <button class="qty-btn" onclick="changeQty(1)">+</button>
                        </div>
                    </div>
                    <button class="btn-add" id="btnAdd" onclick="addToCart()">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m6-6H6"/></svg>
                        <span id="btnText">Adicionar ao carrinho</span>
                    </button>
                    <p class="total-row">Total: <strong id="totalValue">R$ <?= number_format($preco_final, 2, ',', '.') ?></strong></p>
                </div>
            </div>
        </div>
        
        <?php if (!empty($relacionados)): ?>
        <div class="related">
            <h2 class="related-title">Produtos Relacionados</h2>
            <div class="related-scroll">
                <?php foreach ($relacionados as $r): 
                    $rPrice = ($r['price_promo'] > 0 && $r['price_promo'] < $r['price']) ? $r['price_promo'] : $r['price'];
                ?>
                <a href="/mercado/produto/?id=<?= $r['product_id'] ?>" class="related-card">
                    <div class="related-img">
                        <img src="<?= htmlspecialchars($r['image'] ?: '/image/placeholder.jpg') ?>" alt="" onerror="this.src='/image/placeholder.jpg'">
                    </div>
                    <div class="related-info">
                        <div class="related-name"><?= htmlspecialchars($r['name']) ?></div>
                        <div class="related-price">R$ <?= number_format($rPrice, 2, ',', '.') ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
    
    <div class="mobile-bar">
        <div class="mobile-price">
            <div class="mobile-price-value">R$ <?= number_format($preco_final, 2, ',', '.') ?></div>
            <div class="mobile-price-unit">por <?= $unidade ?></div>
        </div>
        <button class="mobile-btn" onclick="addToCart()">Adicionar</button>
    </div>
    
    <div class="toast" id="toast"></div>
    
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
                    document.querySelectorAll('.cart-badge').forEach(b => { b.textContent = data.count; b.style.display = 'flex'; });
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
            t.className = 'toast success show';
            setTimeout(() => t.className = 'toast', 3000);
        }
    </script>
</body>
</html>
