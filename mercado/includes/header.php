<?php
// Header Premium SuperBora - Include em todas as paginas
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
    <title><?= $page_title ?? 'SuperBora' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mercado/assets/css/om-premium.css">
    <link rel="stylesheet" href="/mercado/assets/css/mobile-responsive-fixes.css">
    <link rel="stylesheet" href="/mercado/assets/css/no-blur-fix.css">
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
            <span class="om-logo-text">SuperBora</span>
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

<!-- COMPONENTE DE ENDEREÇO - OneMundo Localização -->
<?php include __DIR__ . '/../components/endereco-entrega.php'; ?>
<!-- /COMPONENTE DE ENDEREÇO -->
</header>

<main>