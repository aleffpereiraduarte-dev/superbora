<?php
/**
 * ONEMUNDO MERCADO - INSTALADOR 02 - HEADER PREMIUM
 * Cria um include de header universal para todas as páginas
 */

$BASE = __DIR__;
$INC = $BASE . '/includes';
if (!is_dir($INC)) mkdir($INC, 0755, true);

$header_html = <<<'HTML'
<?php
// Header Premium OneMundo - Include em todas as páginas
$customer_id = $_SESSION['customer_id'] ?? 0;
$customer_name = '';
$cart_count = 0;

if ($customer_id && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT firstname FROM oc_customer WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $c = $stmt->fetch();
    if ($c) $customer_name = $c['firstname'];
}

// Carrinho
$cart = $_SESSION['market_cart'] ?? [];
foreach ($cart as $item) $cart_count += (int)($item['qty'] ?? 0);

// Endereço
$endereco = 'Selecionar endereço';
if ($customer_id && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT address_1, city FROM oc_address WHERE customer_id = ? LIMIT 1");
    $stmt->execute([$customer_id]);
    $addr = $stmt->fetch();
    if ($addr) $endereco = $addr['address_1'] . ', ' . $addr['city'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'OneMundo Mercado' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mercado/assets/css/om-premium.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<header class="om-header">
    <!-- Topbar Verde -->
    <div class="om-topbar" onclick="location.href='/mercado/conta.php'">
        <div class="om-topbar-main">
            <div class="om-topbar-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <div class="om-topbar-content">
                <div class="om-topbar-label">Entregar em</div>
                <div class="om-topbar-address"><?= htmlspecialchars($endereco) ?></div>
            </div>
        </div>
        <div class="om-topbar-time">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            25-35 min
        </div>
    </div>
    
    <!-- Header Row -->
    <div class="om-header-row">
        <a href="/mercado/" class="om-logo">
            <div class="om-logo-icon">🛒</div>
            <span class="om-logo-text">Mercado</span>
        </a>
        
        <form action="/mercado/busca.php" method="GET" class="om-search">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" name="q" placeholder="Buscar produtos...">
        </form>
        
        <?php if ($customer_id): ?>
        <a href="/mercado/conta.php" class="om-user">
            <div class="om-user-avatar"><?= strtoupper(substr($customer_name, 0, 1)) ?></div>
        </a>
        <?php else: ?>
        <a href="/mercado/mercado-login.php" class="om-user">
            <div class="om-user-guest">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            </div>
        </a>
        <?php endif; ?>
        
        <a href="/mercado/carrinho.php" class="om-cart">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            <?php if ($cart_count > 0): ?>
            <span class="om-cart-badge"><?= $cart_count ?></span>
            <?php endif; ?>
        </a>
    </div>
</header>

<main>
HTML;

$footer_html = <<<'HTML'
</main>

<script src="/mercado/assets/js/om-app.js"></script>
</body>
</html>
HTML;

$created = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['executar'])) {
    file_put_contents($INC . '/header.php', $header_html);
    file_put_contents($INC . '/footer.php', $footer_html);
    $created = true;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador 02 - Header</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}body{font-family:system-ui;background:linear-gradient(135deg,#1e293b,#0f172a);min-height:100vh;padding:20px;color:#e2e8f0}.container{max-width:800px;margin:0 auto}.header{text-align:center;padding:40px;background:rgba(255,255,255,0.05);border-radius:20px;margin-bottom:30px}.header h1{font-size:32px;background:linear-gradient(135deg,#10b981,#34d399);-webkit-background-clip:text;-webkit-text-fill-color:transparent}.card{background:rgba(255,255,255,0.05);border-radius:16px;padding:24px;margin-bottom:20px}.success{background:rgba(16,185,129,0.2);text-align:center;padding:30px;border-radius:16px}.success h2{color:#10b981}.btn{display:inline-block;padding:16px 32px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:12px;font-weight:600;cursor:pointer;text-decoration:none}
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🏠 Header Premium</h1>
        <p>Instalador 02 de 05</p>
    </div>
    <?php if ($created): ?>
        <div class="success"><h2>✅ Header Criado!</h2><p>Arquivos: /includes/header.php e /includes/footer.php</p></div>
        <div class="card">
            <h3>📋 Como usar:</h3>
            <pre style="background:#0f172a;padding:16px;border-radius:8px;margin-top:12px">&lt;?php
$page_title = 'Minha Página';
require_once 'includes/header.php';
?&gt;

&lt;!-- Seu conteúdo --&gt;

&lt;?php require_once 'includes/footer.php'; ?&gt;</pre>
        </div>
        <div style="text-align:center;margin-top:30px"><a href="03_instalar_produto.php" class="btn">Próximo: 03 - Produto →</a></div>
    <?php else: ?>
        <div class="card">
            <h3>📦 Será criado:</h3>
            <ul style="margin:16px 0 0 20px;line-height:2">
                <li>Header com topbar verde (endereço + tempo)</li>
                <li>Logo + Busca + Usuário + Carrinho</li>
                <li>100% responsivo mobile</li>
                <li>Include reutilizável</li>
            </ul>
        </div>
        <div style="text-align:center"><form method="POST"><button type="submit" name="executar" class="btn">🚀 Criar Header</button></form></div>
    <?php endif; ?>
</div>
</body>
</html>
