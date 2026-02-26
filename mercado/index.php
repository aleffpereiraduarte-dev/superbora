<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SUPERBORA - LAYOUT REFORMULADO 2024
 * Design moderno, limpo e performatico com recomendacoes personalizadas
 * ══════════════════════════════════════════════════════════════════════════════
 */

// ═══════════════════════════════════════════════════════════════════════════════
// INCLUIR TODA A LÓGICA PHP DO ARQUIVO ORIGINAL
// ═══════════════════════════════════════════════════════════════════════════════

require_once 'auth-guard.php';
require_once __DIR__ . '/includes/env_loader.php';

// Conectar ao banco usando a função do mercado
$pdo = null;
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    // Fallback: tentar config do OpenCart
    $oc_root = dirname(__DIR__);
    if (file_exists($oc_root . '/config.php') && !defined('DB_DATABASE')) {
        require_once($oc_root . '/config.php');
    }

    if (defined('DB_HOSTNAME') && defined('DB_DATABASE')) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
                DB_USERNAME,
                DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e2) {
            die("Erro de conexão com o banco de dados.");
        }
    }
}

// Session já iniciada em auth-guard.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('OCSESSID');
    session_start();
}

// Redirect regular page views to the redesigned vitrine (iFood-style)
// Preserves AJAX cart endpoints (POST actions + GET ?action=get_cart)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['action'])) {
    header('Location: /mercado/vitrine/');
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// DADOS DO CLIENTE LOGADO
// ═══════════════════════════════════════════════════════════════════════════════

$customer_id = $_SESSION['customer_id'] ?? 0;
$customer = null;
$customer_address = null;
$is_logged = false;
$customer_name = 'Visitante';

if ($customer_id && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();

        if ($customer) {
            $is_logged = true;
            $customer_name = $customer['firstname'];

            // Endereço
            $stmt = $pdo->prepare("SELECT * FROM oc_address WHERE customer_id = ? ORDER BY address_id DESC LIMIT 1");
            $stmt->execute([$customer_id]);
            $customer_address = $stmt->fetch();
        }
    } catch (Exception $e) {}
}

// Saudação
$hora = (int)date('H');
$saudacao = ($hora >= 5 && $hora < 12) ? 'Bom dia' : (($hora >= 12 && $hora < 18) ? 'Boa tarde' : 'Boa noite');

// ═══════════════════════════════════════════════════════════════════════════════
// MEMBERSHIP - Buscar dados do usuário
// ═══════════════════════════════════════════════════════════════════════════════

$membership = null;
$is_member = false;

if ($customer_id && $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT m.member_id, m.level_id, m.status, m.free_shipping_used, m.annual_points,
                   l.name as level_name, l.slug as level_code, l.icon as level_icon,
                   l.free_shipping_qty, l.shipping_discount, l.color,
                   l.color_primary, l.color_secondary
            FROM om_membership_members m
            JOIN om_membership_levels l ON m.level_id = l.level_id
            WHERE m.customer_id = ? AND m.status = 'active'
        ");
        $stmt->execute([$customer_id]);
        $membership = $stmt->fetch();

        if ($membership) {
            $is_member = true;
            $free_qty = (int)$membership['free_shipping_qty'];
            $used = (int)$membership['free_shipping_used'];
            $membership['free_available'] = $free_qty === 999999 ? 'ilimitado' : max(0, $free_qty - $used);
            $membership['has_free_shipping'] = ($membership['free_available'] === 'ilimitado' || $membership['free_available'] > 0) && $membership['shipping_discount'] >= 100;
        }
    } catch (Exception $e) {}
}

// ═══════════════════════════════════════════════════════════════════════════════
// PARTNER / MERCADO - Detectado por localização
// ═══════════════════════════════════════════════════════════════════════════════

$partner_id = $_SESSION['market_partner_id'] ?? null;
$geo_cidade = $_SESSION['cep_cidade'] ?? '';
$geo_estado = $_SESSION['cep_estado'] ?? '';
$tem_mercado_selecionado = ($partner_id > 0);

// Se cliente logado tem CEP, não mostrar modal (vai buscar automaticamente via JS)
$cliente_tem_cep = $is_logged && is_array($customer_address) && !empty($customer_address['postcode']);
// Desabilitar location-detector.php - index.php já tem seu próprio sistema de detecção
$mostrar_detector_localizacao = false;

// Se já tem mercado na sessão, buscar dados dele
$mercado_atual = null;
if ($partner_id && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT partner_id, name, city, state, logo, min_order_value, delivery_fee FROM om_market_partners WHERE partner_id = ? AND status = '1'");
        $stmt->execute([$partner_id]);
        $mercado_atual = $stmt->fetch();
        if ($mercado_atual) {
            $geo_cidade = $mercado_atual['city'] ?: $geo_cidade;
            $geo_estado = $mercado_atual['state'] ?: $geo_estado;
        }
    } catch (Exception $e) {}
}

// ═══════════════════════════════════════════════════════════════════════════════
// TEMPO DE ENTREGA REAL - Baseado em fatores dinâmicos
// ═══════════════════════════════════════════════════════════════════════════════

$tempo_entrega_min = 25;
$tempo_entrega_max = 40;

if ($partner_id && $pdo) {
    try {
        // Shoppers disponíveis
        $stmt = $pdo->query("SELECT COUNT(*) FROM om_market_shoppers WHERE status = 'available'");
        $shoppers_disponiveis = max(1, $stmt->fetchColumn());

        // Pedidos na fila
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE partner_id = ? AND status IN ('pending', 'confirmed', 'shopping')");
        $stmt->execute([$partner_id]);
        $pedidos_fila = $stmt->fetchColumn();

        // Tempo base de separação
        $tempo_separacao = 15;

        // Tempo de espera na fila
        $tempo_fila = ceil($pedidos_fila / $shoppers_disponiveis) * 8;

        // Tempo de deslocamento (estimado)
        $tempo_deslocamento = 12;

        // Horário de pico (almoço e jantar)
        $hora = (int)date('H');
        $fator_pico = (($hora >= 11 && $hora <= 13) || ($hora >= 18 && $hora <= 20)) ? 1.2 : 1.0;

        // Cálculo final
        $tempo_entrega_min = max(20, round(($tempo_separacao + $tempo_fila + $tempo_deslocamento) * $fator_pico));
        $tempo_entrega_max = round($tempo_entrega_min * 1.4);

        // Limites
        $tempo_entrega_min = min(45, $tempo_entrega_min);
        $tempo_entrega_max = min(60, $tempo_entrega_max);
    } catch (Exception $e) {}
}

$tempo_entrega = "{$tempo_entrega_min}-{$tempo_entrega_max}";

// ═══════════════════════════════════════════════════════════════════════════════
// ENDEREÇO DE ENTREGA - Rua para logados, cidade para visitantes
// ═══════════════════════════════════════════════════════════════════════════════

$endereco_entrega = '';
$endereco_completo = false;

if ($is_logged && $customer_address) {
    // Usuário logado - mostrar rua e número
    $rua = $customer_address['address_1'] ?? '';
    if ($rua) {
        $endereco_entrega = $rua;
        $endereco_completo = true;
    }
}

if (!$endereco_entrega) {
    // Visitante ou sem endereço - mostrar cidade
    $endereco_entrega = $geo_cidade ?: 'Selecione sua localização';
    if ($geo_estado && $geo_cidade) {
        $endereco_entrega .= ', ' . $geo_estado;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// CARRINHO - Sincronizar sessões
// ═══════════════════════════════════════════════════════════════════════════════

// Sincronizar cart e market_cart (usar market_cart como principal)
if (!isset($_SESSION['market_cart'])) {
    $_SESSION['market_cart'] = [];
}
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Se market_cart tem dados e cart está vazio, sincronizar
if (!empty($_SESSION['market_cart']) && empty($_SESSION['cart'])) {
    foreach ($_SESSION['market_cart'] as $key => $item) {
        $pid = $item['product_id'] ?? $item['id'] ?? 0;
        if ($pid > 0) {
            $_SESSION['cart'][$pid] = $item;
        }
    }
}
// Se cart tem dados e market_cart está vazio, sincronizar inverso
if (!empty($_SESSION['cart']) && empty($_SESSION['market_cart'])) {
    foreach ($_SESSION['cart'] as $pid => $item) {
        $_SESSION['market_cart']['p'.$pid] = $item;
    }
}

$cart = $_SESSION['cart'] ?? [];
$cartCount = 0;
$cartTotal = 0;
foreach ($cart as $item) {
    $qty = $item['qty'] ?? 1;
    $price = ($item['price_promo'] ?? 0) > 0 && $item['price_promo'] < $item['price']
        ? $item['price_promo']
        : ($item['price'] ?? 0);
    $cartCount += $qty;
    $cartTotal += $price * $qty;
}

// ═══════════════════════════════════════════════════════════════════════════════
// APIS (ações AJAX)
// ═══════════════════════════════════════════════════════════════════════════════

// API: Adicionar ao carrinho
if (isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    header('Content-Type: application/json');
    $product_id = (int)($_POST['product_id'] ?? 0);
    $name = $_POST['name'] ?? '';
    $price = (float)($_POST['price'] ?? 0);
    $price_promo = (float)($_POST['price_promo'] ?? 0);
    $image = $_POST['image'] ?? '';
    $qty = (int)($_POST['qty'] ?? 1);

    if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
    if (!isset($_SESSION['market_cart'])) $_SESSION['market_cart'] = [];

    $item_data = [
        'product_id' => $product_id,
        'id' => $product_id,
        'name' => $name,
        'price' => $price,
        'price_promo' => $price_promo,
        'image' => $image,
        'qty' => $qty
    ];

    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id]['qty'] += $qty;
        $_SESSION['market_cart']['p'.$product_id]['qty'] = $_SESSION['cart'][$product_id]['qty'];
    } else {
        $_SESSION['cart'][$product_id] = $item_data;
        $_SESSION['market_cart']['p'.$product_id] = $item_data;
    }

    $total = 0;
    $count = 0;
    foreach ($_SESSION['cart'] as $item) {
        $item_qty = $item['qty'] ?? 1;
        $item_price = ($item['price_promo'] ?? 0) > 0 && $item['price_promo'] < $item['price']
            ? $item['price_promo']
            : ($item['price'] ?? 0);
        $count += $item_qty;
        $total += $item_price * $item_qty;
    }

    echo json_encode(['success' => true, 'count' => $count, 'total' => $total, 'qty' => $_SESSION['cart'][$product_id]['qty']]);
    exit;
}

// API: Atualizar quantidade
if (isset($_POST['action']) && $_POST['action'] === 'update_qty') {
    header('Content-Type: application/json');
    $product_id = (int)($_POST['product_id'] ?? 0);
    $change = (int)($_POST['change'] ?? 0);

    if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
    if (!isset($_SESSION['market_cart'])) $_SESSION['market_cart'] = [];

    $current_qty = 0;
    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id]['qty'] += $change;
        if ($_SESSION['cart'][$product_id]['qty'] <= 0) {
            unset($_SESSION['cart'][$product_id]);
            unset($_SESSION['market_cart']['p'.$product_id]);
            $current_qty = 0;
        } else {
            $current_qty = $_SESSION['cart'][$product_id]['qty'];
            $_SESSION['market_cart']['p'.$product_id]['qty'] = $current_qty;
        }
    }

    $total = 0;
    $count = 0;
    foreach ($_SESSION['cart'] as $item) {
        $item_qty = $item['qty'] ?? 1;
        $item_price = ($item['price_promo'] ?? 0) > 0 && $item['price_promo'] < $item['price']
            ? $item['price_promo']
            : ($item['price'] ?? 0);
        $count += $item_qty;
        $total += $item_price * $item_qty;
    }

    echo json_encode(['success' => true, 'count' => $count, 'total' => $total, 'qty' => $current_qty]);
    exit;
}

// API: Remover do carrinho
if (isset($_POST['action']) && $_POST['action'] === 'remove_from_cart') {
    header('Content-Type: application/json');
    $product_id = (int)($_POST['product_id'] ?? 0);

    unset($_SESSION['cart'][$product_id]);
    unset($_SESSION['market_cart']['p'.$product_id]);

    $total = 0;
    $count = 0;
    foreach ($_SESSION['cart'] ?? [] as $item) {
        $item_qty = $item['qty'] ?? 1;
        $item_price = ($item['price_promo'] ?? 0) > 0 && $item['price_promo'] < $item['price']
            ? $item['price_promo']
            : ($item['price'] ?? 0);
        $count += $item_qty;
        $total += $item_price * $item_qty;
    }

    echo json_encode(['success' => true, 'count' => $count, 'total' => $total]);
    exit;
}

// API: Limpar carrinho
if (isset($_POST['action']) && $_POST['action'] === 'clear_cart') {
    header('Content-Type: application/json');
    $_SESSION['cart'] = [];
    $_SESSION['market_cart'] = [];
    echo json_encode(['success' => true, 'count' => 0, 'total' => 0]);
    exit;
}

// API: Obter carrinho completo
if (isset($_GET['action']) && $_GET['action'] === 'get_cart') {
    header('Content-Type: application/json');
    $items = [];
    $total = 0;
    $count = 0;

    foreach ($_SESSION['cart'] ?? [] as $item) {
        $item_qty = $item['qty'] ?? 1;
        $item_price = ($item['price_promo'] ?? 0) > 0 && $item['price_promo'] < $item['price']
            ? $item['price_promo']
            : ($item['price'] ?? 0);
        $count += $item_qty;
        $total += $item_price * $item_qty;

        $items[] = [
            'product_id' => $item['product_id'],
            'name' => $item['name'],
            'price' => $item['price'],
            'price_promo' => $item['price_promo'] ?? 0,
            'image' => $item['image'],
            'qty' => $item_qty,
            'line_total' => $item_price * $item_qty
        ];
    }

    echo json_encode([
        'success' => true,
        'count' => $count,
        'total' => $total,
        'items' => $items
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// CATEGORIAS
// ═══════════════════════════════════════════════════════════════════════════════

$categorias = [];
if ($pdo) {
    try {
        $categorias = $pdo->query("
            SELECT c.category_id, c.name, c.icon, COUNT(p.product_id) as total
            FROM om_market_categories c
            LEFT JOIN om_market_products_base p ON p.category_id = c.category_id
            WHERE c.status = 1
            GROUP BY c.category_id, c.name
            HAVING total > 0
            ORDER BY c.sort_order ASC, total DESC
            LIMIT 20
        ")->fetchAll();
    } catch (Exception $e) {}
}

$cat_icons = [
    'Hortifruti' => '🥬', 'Açougue' => '🥩', 'Padaria' => '🥖', 'Laticínios' => '🥛',
    'Bebidas' => '🍺', 'Limpeza' => '🧹', 'Higiene' => '🧴', 'Congelados' => '🧊',
    'Mercearia' => '🏪', 'Pet Shop' => '🐕', 'Doces' => '🍫', 'Frios' => '🧀'
];

// ═══════════════════════════════════════════════════════════════════════════════
// FILTROS E BUSCA
// ═══════════════════════════════════════════════════════════════════════════════

$cat_id = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$busca = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 24;
$offset = ($page - 1) * $limit;

// Query
$where = "WHERE pp.partner_id = ? AND pp.status = '1' AND pp.price > 0";
$params = [$partner_id];

if ($cat_id > 0) {
    $where .= " AND pb.category_id = ?";
    $params[] = $cat_id;
}

if ($busca) {
    $where .= " AND (pb.name LIKE ? OR pb.brand LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

// Total
$total = 0;
$totalPages = 1;
$produtos = [];
$ofertas = [];
$cat_atual = null;

if ($pdo) {
    try {
        $countSql = "SELECT COUNT(DISTINCT pb.product_id) FROM om_market_products_base pb
                     JOIN om_market_products_price pp ON pb.product_id = pp.product_id $where";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        $totalPages = ceil($total / $limit);

        // Produtos - USA PRECO AI (ai_price) quando disponivel, senao preco normal
        $sql = "
            SELECT pb.product_id, pb.name, pb.brand, pb.image, pb.unit,
                   COALESCE(NULLIF(pp.ai_price, 0), pp.price) as price,
                   pp.price_promo, pp.stock,
                   pp.price as preco_original
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            $where
            ORDER BY pb.name
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $produtos = $stmt->fetchAll();

        // Ofertas - USA PRECO AI para calcular desconto real
        if ($partner_id) {
            $stmtOfertas = $pdo->prepare("
                SELECT pb.product_id, pb.name, pb.brand, pb.image,
                       COALESCE(NULLIF(pp.ai_price, 0), pp.price) as price,
                       pp.price_promo,
                       ROUND((1 - pp.price_promo / COALESCE(NULLIF(pp.ai_price, 0), pp.price)) * 100) as desconto
                FROM om_market_products_base pb
                JOIN om_market_products_price pp ON pb.product_id = pp.product_id
                WHERE pp.partner_id = ? AND pp.price_promo > 0 AND pp.price_promo < COALESCE(NULLIF(pp.ai_price, 0), pp.price) AND pp.status = 1
                ORDER BY desconto DESC
                LIMIT 10
            ");
            $stmtOfertas->execute([$partner_id]);
            $ofertas = $stmtOfertas->fetchAll();
        }

        // Categoria atual
        if ($cat_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM om_market_categories WHERE category_id = ?");
            $stmt->execute([$cat_id]);
            $cat_atual = $stmt->fetch();
        }

    } catch (Exception $e) {}
}

// Timer ofertas
$fim_ofertas = strtotime('23:59:59') - time();
if ($fim_ofertas < 0) $fim_ofertas = 86400;

// ═══════════════════════════════════════════════════════════════════════════════
// RECOMENDACOES AI - Personalizadas para o cliente
// ═══════════════════════════════════════════════════════════════════════════════
$ai_recommendations = [];
$buy_again = [];
if ($pdo && $partner_id) {
    try {
        require_once __DIR__ . '/api/recommendations.php';

        // Produtos do carrinho atual
        $cart_ids = array_keys($_SESSION['cart'] ?? []);

        $ai = new AIRecommendations($partner_id, $customer_id, $cart_ids, 10);

        if ($customer_id) {
            // Cliente logado: buscar "compre novamente" e recomendacoes personalizadas
            $homepage_recs = $ai->getHomepageRecommendations();

            foreach ($homepage_recs as $rec) {
                if ($rec['section'] === 'buy_again') {
                    $buy_again[] = $rec;
                } elseif ($rec['section'] === 'trending') {
                    $ai_recommendations[] = $rec;
                }
            }
        } else {
            // Visitante: mostrar trending
            $ai_recommendations = $ai->getRecommendations();
        }
    } catch (Exception $e) {
        // Silently fail
    }
}

// Atualizar contagem do carrinho
$cart = $_SESSION['cart'] ?? [];
$cartCount = 0;
$cartTotal = 0;
foreach ($cart as $item) {
    $qty = $item['qty'] ?? 1;
    $price = ($item['price_promo'] ?? 0) > 0 && $item['price_promo'] < $item['price']
        ? $item['price_promo']
        : ($item['price'] ?? 0);
    $cartCount += $qty;
    $cartTotal += $price * $qty;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#10b981">
    <meta name="description" content="SuperBora - Seu supermercado online com entrega rápida">
    <title><?= $busca ? htmlspecialchars($busca) . ' - ' : '' ?><?= $cat_atual ? htmlspecialchars($cat_atual['name']) . ' - ' : '' ?>SuperBora</title>

    <!-- Preconnect -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- CSS -->
    <link rel="stylesheet" href="/mercado/assets/css/mercado-new.css">
    <link rel="stylesheet" href="/mercado/assets/css/superbora-design-system.css">
    <link rel="stylesheet" href="/mercado/assets/css/header-v2.css">
    <link rel="stylesheet" href="/mercado/assets/css/fixes-2024.css">
    <!-- Mobile Responsive Fixes - Correcoes para 320px, 375px, 414px, 768px, 1024px -->
    <link rel="stylesheet" href="/mercado/assets/css/mobile-responsive-fixes.css">
    <!-- Fix Blur Global - Remove backdrop-filter de overlays -->
    <link rel="stylesheet" href="/mercado/assets/css/no-blur-fix.css">

    <!-- Inline Styles - Apenas animacoes criticas -->
    <style>
        /* Animacoes que precisam estar inline para performance */
        @keyframes sb-spin { to { transform: rotate(360deg); } }
        @keyframes sb-pop-in { 0% { transform: scale(0); } 50% { transform: scale(1.2); } 100% { transform: scale(1); } }
        @keyframes sb-slide-in-right { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes sb-cart-bounce { 0%, 100% { transform: scale(1); } 25% { transform: scale(1.15); } 50% { transform: scale(0.95); } }

        /* Loading state */
        .product-card__add-btn.loading { pointer-events: none; }
        .product-card__add-btn.loading svg { animation: sb-spin 0.8s linear infinite; }

        /* Quantity show state */
        .product-card__qty.show {
            animation: sb-pop-in 0.3s ease;
        }
    </style>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛒</text></svg>">

    <!-- PWA -->
    <link rel="manifest" href="/mercado/manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="SuperBora">
    <link rel="apple-touch-icon" href="/mercado/assets/img/icon-192.png">
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     HEADER MODERNO - Estilo iFood/Rappi
═══════════════════════════════════════════════════════════════════════════════ -->
<header class="header-v2" id="mainHeader">
    <!-- Main Header -->
    <div class="header-v2__main">
        <div class="container header-v2__inner">
            <!-- Logo -->
            <a href="/mercado/" class="header-v2__logo">
                <div class="header-v2__logo-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                </div>
                <span class="header-v2__logo-text">SuperBora</span>
            </a>

            <!-- Location Button -->
            <button class="header-v2__location" onclick="abrirModalEndereco()" type="button">
                <div class="header-v2__location-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                    </svg>
                </div>
                <div class="header-v2__location-text">
                    <span class="header-v2__location-label"><?= $endereco_completo ? 'Entregar em' : 'Entrega para' ?></span>
                    <span class="header-v2__location-address"><?= htmlspecialchars($endereco_entrega) ?></span>
                </div>
                <svg class="header-v2__location-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
            </button>

            <!-- Search -->
            <form action="/mercado/" method="GET" class="header-v2__search">
                <svg class="header-v2__search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                <input type="text" name="q" id="omSearchInput" class="header-v2__search-input" placeholder="Buscar no SuperBora" value="<?= htmlspecialchars($busca) ?>" autocomplete="off">
            </form>

            <!-- Actions -->
            <div class="header-v2__actions">
                <!-- Delivery Time Badge -->
                <div class="header-v2__delivery">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span><?= $tempo_entrega ?> min</span>
                </div>

                <?php if ($is_logged): ?>
                <!-- User Menu -->
                <a href="/mercado/conta.php" class="header-v2__user" title="Minha conta">
                    <div class="header-v2__user-avatar"><?= strtoupper(mb_substr($customer_name, 0, 1)) ?></div>
                    <div class="header-v2__user-info">
                        <span class="header-v2__user-name"><?= htmlspecialchars($customer_name) ?></span>
                        <?php if ($is_member): ?>
                        <span class="header-v2__user-badge" style="background: <?= htmlspecialchars($membership['color']) ?>"><?= $membership['level_icon'] ?> <?= $membership['level_name'] ?></span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php else: ?>
                <!-- Login -->
                <a href="/mercado/login.php" class="header-v2__login">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    <span>Entrar</span>
                </a>
                <?php endif; ?>

                <!-- Cart -->
                <button class="header-v2__cart <?= $cartCount > 0 ? 'has-items' : '' ?>" onclick="toggleCart()" id="cartBtn" type="button">
                    <div class="header-v2__cart-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <path d="M16 10a4 4 0 0 1-8 0"/>
                        </svg>
                        <?php if ($cartCount > 0): ?>
                        <span class="header-v2__cart-badge" id="cartBadge"><?= $cartCount ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="header-v2__cart-info">
                        <span class="header-v2__cart-total" id="cartTotalHeader"><?= $cartCount > 0 ? 'R$ ' . number_format($cartTotal, 2, ',', '.') : 'Vazio' ?></span>
                    </div>
                </button>
            </div>
        </div>
    </div>

    <!-- Categories Nav -->
    <nav class="header-v2__nav">
        <div class="container">
            <div class="header-v2__nav-scroll">
                <a href="/mercado/" class="header-v2__nav-item <?= !$cat_id && !$busca ? 'active' : '' ?>">
                    <span class="header-v2__nav-icon">🏠</span>
                    <span>Início</span>
                </a>
                <a href="/mercado/?ofertas=1" class="header-v2__nav-item header-v2__nav-item--promo">
                    <span class="header-v2__nav-icon">🔥</span>
                    <span>Ofertas</span>
                </a>
                <?php foreach (array_slice($categorias, 0, 8) as $cat):
                    $icon = '📦';
                    foreach ($cat_icons as $key => $val) {
                        if (stripos($cat['name'], $key) !== false) { $icon = $val; break; }
                    }
                ?>
                <a href="?cat=<?= $cat['category_id'] ?>" class="header-v2__nav-item <?= $cat_id == $cat['category_id'] ? 'active' : '' ?>">
                    <span class="header-v2__nav-icon"><?= $icon ?></span>
                    <span><?= htmlspecialchars($cat['name']) ?></span>
                </a>
                <?php endforeach; ?>
                <a href="/mercado/categorias.php" class="header-v2__nav-item header-v2__nav-item--more">
                    <span>Ver todas</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            </div>
        </div>
    </nav>

    <!-- Promo Strip (opcional - só mostra se não for membro) -->
    <?php if (!$is_member): ?>
    <div class="header-v2__promo">
        <div class="container">
            <div class="header-v2__promo-content">
                <span>🚚 <strong>Frete Grátis</strong> acima de R$100</span>
                <span class="header-v2__promo-sep">•</span>
                <span>⚡ Entrega em <strong><?= $tempo_entrega ?> min</strong></span>
                <span class="header-v2__promo-sep">•</span>
                <a href="/mercado/membership.php" class="header-v2__promo-link">💎 Seja Membro</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</header>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     MAIN CONTENT
═══════════════════════════════════════════════════════════════════════════════ -->
<main class="main">
    <div class="container">

        <?php if (!$busca && !$cat_id): ?>
        <!-- Hero Section Premium -->
        <section class="hero sb-hero-premium">
            <!-- Floating Orbs Background -->
            <div class="sb-hero-premium__orbs" aria-hidden="true">
                <div class="sb-hero-premium__orb"></div>
                <div class="sb-hero-premium__orb"></div>
                <div class="sb-hero-premium__orb"></div>
            </div>

            <div class="hero__main sb-hero-premium__content">
                <!-- Floating Particles -->
                <div class="hero__particles" aria-hidden="true">
                    <span></span><span></span><span></span><span></span><span></span>
                </div>
                <span class="hero__badge sb-hero-premium__badge">
                    <span>🚀</span>
                    <span>Entrega em <?= $tempo_entrega ?> minutos</span>
                </span>
                <h1 class="hero__title sb-hero-premium__title">
                    Seu mercado<br>
                    <span class="hero__title-highlight sb-hero-premium__title-highlight sb-gradient-text-animated">em casa</span>
                </h1>
                <p class="hero__subtitle sb-hero-premium__subtitle">
                    Mais de <?= number_format($total) ?> produtos frescos e de qualidade com entrega rápida para você
                </p>
                <a href="#produtos" class="hero__cta sb-hero-premium__cta sb-btn-premium">
                    Começar compras
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>

            <div class="hero__cards sb-stagger">
                <a href="#ofertas" class="hero__card">
                    <div class="hero__card-icon hero__card-icon--offers">🔥</div>
                    <div class="hero__card-content">
                        <div class="hero__card-title">Ofertas do Dia</div>
                        <div class="hero__card-desc">Até 50% de desconto</div>
                    </div>
                    <div class="hero__card-arrow">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                    </div>
                </a>
                <a href="?cat=hortifruti" class="hero__card">
                    <div class="hero__card-icon hero__card-icon--free">🥬</div>
                    <div class="hero__card-content">
                        <div class="hero__card-title">Hortifruti</div>
                        <div class="hero__card-desc">Fresquinho do dia</div>
                    </div>
                    <div class="hero__card-arrow">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                    </div>
                </a>
                <a href="?frete_gratis=1" class="hero__card">
                    <div class="hero__card-icon hero__card-icon--delivery">🚚</div>
                    <div class="hero__card-content">
                        <div class="hero__card-title">Frete Grátis</div>
                        <div class="hero__card-desc">Acima de R$ 100</div>
                    </div>
                    <div class="hero__card-arrow">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                    </div>
                </a>
            </div>
        </section>

        <!-- Categories -->
        <section class="section">
            <div class="section__header">
                <h2 class="section__title">
                    <span class="section__title-icon">🛒</span>
                    Categorias
                </h2>
                <a href="/mercado/categorias.php" class="section__link">
                    Ver todas
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            </div>

            <div class="categories-grid sb-stagger">
                <?php
                $cat_colors = [
                    'Hortifruti' => 'category-card--hortifruti',
                    'Açougue' => 'category-card--acougue',
                    'Padaria' => 'category-card--padaria',
                    'Laticínios' => 'category-card--laticinios',
                    'Bebidas' => 'category-card--bebidas',
                    'Limpeza' => 'category-card--limpeza',
                    'Higiene' => 'category-card--higiene',
                    'Congelados' => 'category-card--congelados',
                    'Mercearia' => 'category-card--mercearia',
                    'Pet Shop' => 'category-card--petshop',
                    'Doces' => 'category-card--doces',
                    'Frios' => 'category-card--frios',
                ];
                foreach (array_slice($categorias, 0, 6) as $cat):
                    $icon = '📦';
                    $color_class = '';
                    foreach ($cat_icons as $key => $val) {
                        if (stripos($cat['name'], $key) !== false) {
                            $icon = $val;
                            $color_class = $cat_colors[$key] ?? '';
                            break;
                        }
                    }
                ?>
                <a href="?cat=<?= $cat['category_id'] ?>" class="category-card sb-category-card <?= $color_class ?>">
                    <div class="category-card__content">
                        <div class="category-card__icon"><?= $icon ?></div>
                        <div class="category-card__name"><?= htmlspecialchars($cat['name']) ?></div>
                        <div class="category-card__count"><?= $cat['total'] ?> produtos</div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($ofertas) && !$busca && !$cat_id): ?>
        <!-- Flash Deals Premium -->
        <section class="flash-section sb-flash-premium" id="ofertas">
            <div class="flash-header">
                <div class="flash-title">
                    <span class="flash-icon">⚡</span>
                    <div class="flash-text">
                        <h2>Ofertas Relâmpago</h2>
                        <p>Corra antes que acabe!</p>
                    </div>
                </div>

                <div class="timer sb-timer-premium">
                    <span class="timer__label">Termina em:</span>
                    <div class="timer__block">
                        <span class="timer__value sb-timer-value" id="timerH"><?= str_pad(floor($fim_ofertas/3600), 2, '0', STR_PAD_LEFT) ?></span>
                        <span class="timer__unit">horas</span>
                    </div>
                    <span class="timer__sep">:</span>
                    <div class="timer__block">
                        <span class="timer__value sb-timer-value" id="timerM"><?= str_pad(floor(($fim_ofertas%3600)/60), 2, '0', STR_PAD_LEFT) ?></span>
                        <span class="timer__unit">min</span>
                    </div>
                    <span class="timer__sep">:</span>
                    <div class="timer__block">
                        <span class="timer__value sb-timer-value" id="timerS"><?= str_pad($fim_ofertas%60, 2, '0', STR_PAD_LEFT) ?></span>
                        <span class="timer__unit">seg</span>
                    </div>
                </div>
            </div>

            <div class="flash-scroll sb-scrollbar-hidden sb-stagger">
                <?php foreach ($ofertas as $of): ?>
                <div class="flash-card sb-flash-card" onclick="openProductModal(<?= $of['product_id'] ?>)">
                    <span class="flash-card__badge sb-badge-promo">-<?= $of['desconto'] ?>%</span>
                    <div class="flash-card__image">
                        <img src="<?= $of['image'] ?: 'https://via.placeholder.com/180' ?>" alt="<?= htmlspecialchars($of['name']) ?>" loading="lazy">
                    </div>
                    <div class="flash-card__info">
                        <?php if ($of['brand']): ?><div class="flash-card__brand"><?= htmlspecialchars($of['brand']) ?></div><?php endif; ?>
                        <div class="flash-card__name"><?= htmlspecialchars($of['name']) ?></div>
                        <div class="flash-card__prices">
                            <span class="flash-card__price">R$ <?= number_format($of['price_promo'], 2, ',', '.') ?></span>
                            <span class="flash-card__price-old">R$ <?= number_format($of['price'], 2, ',', '.') ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($buy_again) && !$busca && !$cat_id): ?>
        <!-- Compre Novamente (Cliente Logado) - Premium -->
        <section class="ai-section sb-ai-section" id="compre-novamente">
            <div class="ai-section__header">
                <div class="ai-section__title">
                    <span class="ai-section__icon">🔄</span>
                    <div>
                        <h2 class="sb-gradient-text">Compre novamente</h2>
                        <p>Produtos que você já conhece e adora</p>
                    </div>
                </div>
            </div>
            <div class="ai-scroll sb-scrollbar-hidden sb-stagger">
                <?php foreach ($buy_again as $rec):
                    $preco = $rec['price_promo'] > 0 && $rec['price_promo'] < $rec['price'] ? $rec['price_promo'] : $rec['price'];
                    $tem_promo = $rec['price_promo'] > 0 && $rec['price_promo'] < $rec['price'];
                ?>
                <div class="ai-card" onclick="openProductModal(<?= $rec['product_id'] ?>)">
                    <div class="ai-card__image">
                        <img src="<?= $rec['image'] ?: '/mercado/assets/img/no-image.png' ?>" alt="" loading="lazy" onerror="this.src='/mercado/assets/img/no-image.png'">
                    </div>
                    <div class="ai-card__info">
                        <div class="ai-card__name"><?= htmlspecialchars($rec['name']) ?></div>
                        <div class="ai-card__prices">
                            <?php if ($tem_promo): ?>
                            <span class="ai-card__price-old">R$ <?= number_format($rec['price'], 2, ',', '.') ?></span>
                            <?php endif; ?>
                            <span class="ai-card__price">R$ <?= number_format($preco, 2, ',', '.') ?></span>
                        </div>
                        <button class="ai-card__add" onclick="event.stopPropagation(); addToCart(<?= $rec['product_id'] ?>, <?= htmlspecialchars(json_encode($rec['name']), ENT_QUOTES) ?>, <?= $preco ?>, <?= htmlspecialchars(json_encode($rec['image'] ?? ''), ENT_QUOTES) ?>)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                            Adicionar
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($ai_recommendations) && !$busca && !$cat_id): ?>
        <!-- Recomendados para Você - Premium -->
        <section class="ai-section sb-ai-section" id="recomendados">
            <div class="ai-section__header">
                <div class="ai-section__title">
                    <span class="ai-section__icon">⭐</span>
                    <div>
                        <h2 class="sb-gradient-text-blue"><?= $customer_id ? 'Recomendados para você' : 'Em alta agora' ?></h2>
                        <p><?= $customer_id ? 'Selecionados especialmente para você' : 'Os mais vendidos da semana' ?></p>
                    </div>
                </div>
            </div>
            <div class="ai-scroll sb-scrollbar-hidden sb-stagger">
                <?php foreach ($ai_recommendations as $rec):
                    $preco = $rec['price_promo'] > 0 && $rec['price_promo'] < $rec['price'] ? $rec['price_promo'] : $rec['price'];
                    $tem_promo = $rec['price_promo'] > 0 && $rec['price_promo'] < $rec['price'];
                    $reason = $rec['recommendation_reason'] ?? 'trending';
                    $badges = ['bought_together' => '🛒', 'purchase_history' => '🔄', 'trending' => '🔥', 'same_category' => '📦', 'on_sale' => '💰'];
                    $badge = $badges[$reason] ?? '⭐';
                ?>
                <div class="ai-card" onclick="openProductModal(<?= $rec['product_id'] ?>)">
                    <div class="ai-card__image">
                        <span class="ai-card__reason"><?= $badge ?></span>
                        <img src="<?= $rec['image'] ?: '/mercado/assets/img/no-image.png' ?>" alt="" loading="lazy" onerror="this.src='/mercado/assets/img/no-image.png'">
                    </div>
                    <div class="ai-card__info">
                        <div class="ai-card__name"><?= htmlspecialchars($rec['name']) ?></div>
                        <div class="ai-card__prices">
                            <?php if ($tem_promo): ?>
                            <span class="ai-card__price-old">R$ <?= number_format($rec['price'], 2, ',', '.') ?></span>
                            <?php endif; ?>
                            <span class="ai-card__price">R$ <?= number_format($preco, 2, ',', '.') ?></span>
                        </div>
                        <button class="ai-card__add" onclick="event.stopPropagation(); addToCart(<?= $rec['product_id'] ?>, <?= htmlspecialchars(json_encode($rec['name']), ENT_QUOTES) ?>, <?= $preco ?>, <?= htmlspecialchars(json_encode($rec['image'] ?? ''), ENT_QUOTES) ?>)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                            Adicionar
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- CTA Estabelecimentos -->
        <section class="section" style="padding:0 0 8px;">
            <a href="/mercado/estabelecimentos.php" style="display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#059669 0%,#047857 100%);border-radius:16px;padding:20px 24px;color:white;text-decoration:none;transition:transform 0.2s,box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 20px rgba(5,150,105,0.3)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                <div>
                    <div style="font-size:18px;font-weight:700;margin-bottom:4px;">&#127978; Veja todos os estabelecimentos</div>
                    <div style="font-size:13px;opacity:0.9;">Mercados, restaurantes, farm&aacute;cias e lojas perto de voc&ecirc;</div>
                </div>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            </a>
        </section>

        <!-- Products Section -->
        <section class="section" id="produtos">
            <div class="section__header">
                <h2 class="section__title">
                    <span class="section__title-icon"><?= $busca ? '🔍' : ($cat_atual ? ($cat_icons[$cat_atual['name']] ?? '📦') : '🛍️') ?></span>
                    <?php if ($busca): ?>
                        Resultados para "<?= htmlspecialchars($busca) ?>"
                    <?php elseif ($cat_atual): ?>
                        <?= htmlspecialchars($cat_atual['name']) ?>
                    <?php else: ?>
                        Produtos em destaque
                    <?php endif; ?>
                </h2>
                <span class="products-count">
                    <?= number_format($total) ?> produto<?= $total != 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if (empty($produtos)): ?>
            <div class="empty-state">
                <div class="empty-state__icon">🔍</div>
                <h3 class="empty-state__title">Nenhum produto encontrado</h3>
                <p class="empty-state__text">Tente buscar por outro termo</p>
                <a href="/mercado/" class="empty-state__link">Voltar ao inicio</a>
            </div>
            <?php else: ?>

            <div class="products-grid sb-stagger">
                <?php foreach ($produtos as $p):
                    $preco_final = $p['price_promo'] > 0 ? $p['price_promo'] : $p['price'];
                    $tem_promo = $p['price_promo'] > 0 && $p['price_promo'] < $p['price'];
                    $desconto = $tem_promo ? round((1 - $p['price_promo'] / $p['price']) * 100) : 0;
                    $in_cart = isset($cart[$p['product_id']]);
                    $cart_qty = $in_cart ? $cart[$p['product_id']]['qty'] : 0;
                ?>
                <div class="product-card sb-product-card-premium" data-id="<?= $p['product_id'] ?>" data-product-id="<?= $p['product_id'] ?>">
                    <div class="product-card__image sb-product-card-premium__image" onclick="openProductModal(<?= $p['product_id'] ?>)">
                        <img src="<?= $p['image'] ?: 'https://via.placeholder.com/200' ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">

                        <!-- Quick Actions on Hover -->
                        <div class="sb-product-card-premium__actions">
                            <button class="sb-product-card-premium__action-btn" title="Favoritar" onclick="event.stopPropagation();">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                            </button>
                            <button class="sb-product-card-premium__action-btn" title="Compartilhar" onclick="event.stopPropagation();">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                            </button>
                        </div>

                        <?php if ($tem_promo || $p['stock'] <= 5): ?>
                        <div class="product-card__badges sb-product-card-premium__badges">
                            <?php if ($tem_promo): ?>
                            <span class="product-card__badge product-card__badge--promo sb-badge-promo">-<?= $desconto ?>%</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($p['stock'] > 0 && !$in_cart): ?>
                        <div class="product-card__quick-add">
                            <button class="product-card__quick-btn sb-btn-premium sb-ripple-container" onclick="event.stopPropagation(); addToCartPremium(<?= $p['product_id'] ?>, <?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>, <?= $preco_final ?>, <?= htmlspecialchars(json_encode($p['image']), ENT_QUOTES) ?>, this)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="product-card__info sb-product-card-premium__content">
                        <?php if ($p['brand']): ?><div class="product-card__brand sb-product-card-premium__brand"><?= htmlspecialchars($p['brand']) ?></div><?php endif; ?>
                        <div class="product-card__name sb-product-card-premium__name" onclick="openProductModal(<?= $p['product_id'] ?>)"><?= htmlspecialchars($p['name']) ?></div>
                        <?php if ($p['unit']): ?><div class="product-card__unit sb-product-card-premium__weight"><?= htmlspecialchars($p['unit']) ?></div><?php endif; ?>

                        <div class="product-card__footer sb-product-card-premium__price-row">
                            <div class="product-card__prices sb-product-card-premium__price-info">
                                <?php if ($tem_promo): ?><span class="product-card__price-old sb-product-card-premium__price-old">R$ <?= number_format($p['price'], 2, ',', '.') ?></span><?php endif; ?>
                                <span class="product-card__price sb-product-card-premium__price <?= $tem_promo ? 'product-card__price--promo has-promo' : '' ?>">R$ <?= number_format($preco_final, 2, ',', '.') ?></span>
                            </div>

                            <?php if ($p['stock'] > 0): ?>
                                <?php if (!$in_cart): ?>
                                <button class="product-card__add-btn sb-product-card-premium__add-btn sb-btn-glow sb-ripple-container" onclick="addToCartPremium(<?= $p['product_id'] ?>, <?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>, <?= $preco_final ?>, <?= htmlspecialchars(json_encode($p['image']), ENT_QUOTES) ?>, this)" id="addBtn-<?= $p['product_id'] ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                </button>
                                <?php endif; ?>

                                <div class="product-card__qty sb-qty-stepper <?= $in_cart ? 'show' : '' ?>" id="qty-<?= $p['product_id'] ?>">
                                    <button class="product-card__qty-btn sb-qty-stepper__btn" onclick="changeQty(<?= $p['product_id'] ?>, -1)">−</button>
                                    <span class="product-card__qty-value sb-qty-stepper__value" id="qtyVal-<?= $p['product_id'] ?>"><?= $cart_qty ?></span>
                                    <button class="product-card__qty-btn sb-qty-stepper__btn" onclick="changeQty(<?= $p['product_id'] ?>, 1)">+</button>
                                </div>
                            <?php else: ?>
                                <span class="out-of-stock">Esgotado</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
            <nav class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="pagination__btn">Anterior</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="pagination__number <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="pagination__btn">Proxima</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

            <?php endif; ?>
        </section>

    </div>
</main>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     CART SIDEBAR - Glassmorphism Premium
═══════════════════════════════════════════════════════════════════════════════ -->
<div class="cart-overlay sb-modal-glass__backdrop" id="cartOverlay" onclick="toggleCart()"></div>

<aside class="cart-sidebar sb-scrollbar" id="cartSidebar">
    <div class="cart-sidebar__header">
        <h2 class="cart-sidebar__title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            Seu Carrinho
        </h2>
        <button class="cart-sidebar__close" onclick="toggleCart()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>

    <div class="cart-sidebar__body" id="cartBody">
        <?php if (empty($cart)): ?>
        <div class="cart-sidebar__empty">
            <span class="cart-sidebar__empty-icon">🛒</span>
            <h3>Seu carrinho está vazio</h3>
            <p>Adicione produtos para continuar</p>
        </div>
        <?php else: ?>
        <div class="cart-sidebar__items" id="cartItems">
            <?php foreach ($cart as $item): ?>
            <div class="cart-item" id="cartItem-<?= $item['product_id'] ?>">
                <div class="cart-item__image">
                    <img src="<?= $item['image'] ?: 'https://via.placeholder.com/70' ?>" alt="">
                </div>
                <div class="cart-item__info">
                    <div class="cart-item__name"><?= htmlspecialchars($item['name']) ?></div>
                    <div class="cart-item__price">R$ <?= number_format($item['price'] * $item['qty'], 2, ',', '.') ?></div>
                    <div class="cart-item__qty">
                        <button class="cart-item__qty-btn" onclick="changeQty(<?= $item['product_id'] ?>, -1)">−</button>
                        <span class="cart-item__qty-value"><?= $item['qty'] ?></span>
                        <button class="cart-item__qty-btn" onclick="changeQty(<?= $item['product_id'] ?>, 1)">+</button>
                    </div>
                </div>
                <button class="cart-item__remove" onclick="removeFromCart(<?= $item['product_id'] ?>)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    </svg>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($cart)): ?>
    <div class="cart-sidebar__footer" id="cartFooter">
        <div class="cart-sidebar__summary">
            <div class="cart-sidebar__row">
                <span>Subtotal</span>
                <span id="cartSubtotal">R$ <?= number_format($cartTotal, 2, ',', '.') ?></span>
            </div>
            <div class="cart-sidebar__row">
                <span>Entrega</span>
                <span class="free-shipping">Gratis</span>
            </div>
            <div class="cart-sidebar__row cart-sidebar__row--total">
                <span>Total</span>
                <span id="cartTotalFooter">R$ <?= number_format($cartTotal, 2, ',', '.') ?></span>
            </div>
        </div>

        <a href="/mercado/checkout.php" class="cart-sidebar__checkout">
            Finalizar Compra
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
        </a>
    </div>
    <?php endif; ?>
</aside>

<!-- Toast -->
<div class="toast" id="toast">
    <svg class="toast__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20 6L9 17l-5-5"/>
    </svg>
    <span id="toastMessage">Produto adicionado!</span>
</div>

<!-- Mobile Navigation -->
<nav class="mobile-nav">
    <div class="mobile-nav__inner">
        <a href="/mercado/" class="mobile-nav__item active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            <span class="mobile-nav__label">Início</span>
        </a>
        <a href="/mercado/categorias.php" class="mobile-nav__item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"/>
                <rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/>
                <rect x="3" y="14" width="7" height="7"/>
            </svg>
            <span class="mobile-nav__label">Categorias</span>
        </a>
        <a href="?ofertas=1" class="mobile-nav__item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
            </svg>
            <span class="mobile-nav__label">Ofertas</span>
        </a>
        <a href="/mercado/pedidos/" class="mobile-nav__item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span class="mobile-nav__label">Pedidos</span>
        </a>
        <button class="mobile-nav__item mobile-nav__cart" onclick="toggleCart()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            <?php if ($cartCount > 0): ?>
            <span class="mobile-nav__badge"><?= $cartCount ?></span>
            <?php endif; ?>
            <span class="mobile-nav__label">Carrinho</span>
        </button>
    </div>
</nav>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════════════════════════ -->
<script>
// Header Scroll Effect
const header = document.querySelector('.header-v2') || document.querySelector('.header');
let lastScroll = 0;

window.addEventListener('scroll', () => {
    if (!header) return;
    const currentScroll = window.pageYOffset;
    if (currentScroll > 50) {
        header.classList.add('scrolled');
    } else {
        header.classList.remove('scrolled');
    }
    lastScroll = currentScroll;
}, { passive: true });

// Timer das ofertas
let timerSeconds = <?= $fim_ofertas ?>;
function updateTimer() {
    if (timerSeconds <= 0) {
        timerSeconds = 86400;
    }
    const h = Math.floor(timerSeconds / 3600);
    const m = Math.floor((timerSeconds % 3600) / 60);
    const s = timerSeconds % 60;

    const timerH = document.getElementById('timerH');
    const timerM = document.getElementById('timerM');
    const timerS = document.getElementById('timerS');

    if (timerH) timerH.textContent = String(h).padStart(2, '0');
    if (timerM) timerM.textContent = String(m).padStart(2, '0');
    if (timerS) timerS.textContent = String(s).padStart(2, '0');

    timerSeconds--;
}
setInterval(updateTimer, 1000);
updateTimer();

// Toggle Cart
function toggleCart() {
    const overlay = document.getElementById('cartOverlay');
    const sidebar = document.getElementById('cartSidebar');
    overlay.classList.toggle('open');
    sidebar.classList.toggle('open');
    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
}

// Toast
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');

    toast.className = 'toast show toast--' + type;
    toastMessage.textContent = message;

    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// Add to Cart
function addToCart(productId, name, price, image, pricePromo = 0) {
    // Efeito visual no botão
    const addBtn = document.getElementById('addBtn-' + productId);
    if (addBtn) {
        addBtn.classList.add('loading');
    }

    fetch('/mercado/', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=add_to_cart&product_id=${productId}&name=${encodeURIComponent(name)}&price=${price}&price_promo=${pricePromo}&image=${encodeURIComponent(image)}&qty=1`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Atualizar badge
            updateCartBadge(data.count);

            // Mostrar controle de quantidade
            const qtyControl = document.getElementById('qty-' + productId);
            const qtyVal = document.getElementById('qtyVal-' + productId);

            if (addBtn) {
                addBtn.classList.remove('loading');
                addBtn.style.display = 'none';
            }
            if (qtyControl) {
                qtyControl.classList.add('show');
                if (qtyVal) qtyVal.textContent = data.qty || '1';
            }

            // Atualizar totais
            updateCartTotals(data.total);

            // Atualizar sidebar se aberta
            refreshCartSidebar();

            showToast('Produto adicionado ao carrinho!');
        }
    })
    .catch(err => {
        console.error(err);
        if (addBtn) addBtn.classList.remove('loading');
        showToast('Erro ao adicionar produto', 'error');
    });
}

// Add to Cart with Premium Animation
function addToCartPremium(productId, name, price, image, buttonElement, pricePromo = 0) {
    const addBtn = buttonElement || document.getElementById('addBtn-' + productId);
    const productCard = addBtn ? addBtn.closest('.product-card, .sb-product-card-premium') : null;
    const productImage = productCard ? productCard.querySelector('img') : null;
    const cartBtn = document.getElementById('cartBtn') || document.querySelector('.header__cart');

    // Ripple effect
    if (addBtn && window.OMApp && window.OMApp.SBAnimations) {
        const rect = addBtn.getBoundingClientRect();
        const fakeEvent = { clientX: rect.left + rect.width/2, clientY: rect.top + rect.height/2 };
        window.OMApp.SBAnimations.createRipple(fakeEvent, addBtn);
    }

    // Fly to cart animation
    if (productImage && cartBtn && window.OMApp && window.OMApp.SBAnimations) {
        window.OMApp.SBAnimations.flyToCart(productImage, cartBtn);
    }

    // Button rotation animation
    if (addBtn) {
        addBtn.style.transform = 'scale(0.9) rotate(180deg)';
        setTimeout(() => {
            addBtn.style.transform = '';
        }, 300);
    }

    // Call original function
    addToCart(productId, name, price, image, pricePromo);
}

// Change Quantity
function changeQty(productId, change) {
    fetch('/mercado/', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=update_qty&product_id=${productId}&change=${change}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const qtyVal = document.getElementById('qtyVal-' + productId);
            const addBtn = document.getElementById('addBtn-' + productId);
            const qtyControl = document.getElementById('qty-' + productId);

            if (data.qty <= 0) {
                if (qtyControl) qtyControl.classList.remove('show');
                if (addBtn) addBtn.style.display = '';

                // Remover item da sidebar
                const cartItem = document.getElementById('cartItem-' + productId);
                if (cartItem) {
                    cartItem.style.opacity = '0';
                    cartItem.style.transform = 'translateX(100%)';
                    setTimeout(() => cartItem.remove(), 300);
                }
            } else {
                if (qtyVal) qtyVal.textContent = data.qty;

                // Atualizar quantidade na sidebar
                const cartItemQty = document.querySelector('#cartItem-' + productId + ' .cart-item__qty-value');
                if (cartItemQty) cartItemQty.textContent = data.qty;
            }

            // Atualizar badge e totais
            updateCartBadge(data.count);
            updateCartTotals(data.total);

            // Verificar se carrinho está vazio
            if (data.count === 0) {
                showEmptyCart();
            }
        }
    })
    .catch(err => {
        console.error('Erro ao atualizar quantidade:', err);
        showToast('Erro ao atualizar quantidade', 'error');
    });
}

// Remove from Cart
function removeFromCart(productId) {
    fetch('/mercado/', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=remove_from_cart&product_id=${productId}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Remover item da sidebar com animação
            const cartItem = document.getElementById('cartItem-' + productId);
            if (cartItem) {
                cartItem.style.opacity = '0';
                cartItem.style.transform = 'translateX(100%)';
                setTimeout(() => cartItem.remove(), 300);
            }

            // Resetar controles no grid
            const qtyControl = document.getElementById('qty-' + productId);
            const addBtn = document.getElementById('addBtn-' + productId);
            if (qtyControl) qtyControl.classList.remove('show');
            if (addBtn) addBtn.style.display = '';

            // Atualizar badge e totais
            updateCartBadge(data.count);
            updateCartTotals(data.total);

            // Verificar se carrinho está vazio
            if (data.count === 0) {
                showEmptyCart();
            }

            showToast('Produto removido do carrinho');
        }
    })
    .catch(err => {
        console.error('Erro ao remover produto:', err);
        showToast('Erro ao remover produto', 'error');
    });
}

// Limpar carrinho
function clearCart() {
    if (!confirm('Deseja limpar todo o carrinho?')) return;

    fetch('/mercado/', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=clear_cart'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            updateCartBadge(0);
            updateCartTotals(0);
            showEmptyCart();

            // Resetar todos os controles de quantidade
            document.querySelectorAll('.product-card__qty.show').forEach(el => {
                el.classList.remove('show');
            });
            document.querySelectorAll('.product-card__add-btn').forEach(el => {
                el.style.display = '';
            });

            showToast('Carrinho limpo!');
        }
    })
    .catch(err => {
        console.error('Erro ao limpar carrinho:', err);
        showToast('Erro ao limpar carrinho', 'error');
    });
}

// Funções auxiliares
function updateCartBadge(count) {
    let badge = document.getElementById('cartBadge');
    const cartIconWrap = document.querySelector('.header-v2__cart-icon') || document.querySelector('.header__cart-icon-wrap');
    const cartBtn = document.getElementById('cartBtn');

    if (count > 0) {
        // Criar badge se não existir
        if (!badge && cartIconWrap) {
            badge = document.createElement('span');
            badge.className = 'header-v2__cart-badge';
            badge.id = 'cartBadge';
            cartIconWrap.appendChild(badge);
        }
        if (badge) {
            badge.textContent = count;
            badge.classList.add('pulse');
            setTimeout(() => badge.classList.remove('pulse'), 300);
        }

        // Cart bounce animation e estado has-items
        if (cartBtn) {
            cartBtn.classList.add('bounce', 'has-items');
            setTimeout(() => cartBtn.classList.remove('bounce'), 400);
        }

        // Mobile badge
        const mobileBadge = document.querySelector('.mobile-nav__badge');
        if (mobileBadge) {
            mobileBadge.textContent = count;
            mobileBadge.style.display = '';
        }
    } else {
        if (badge) badge.remove();
        if (cartBtn) cartBtn.classList.remove('has-items');
        const mobileBadge = document.querySelector('.mobile-nav__badge');
        if (mobileBadge) mobileBadge.style.display = 'none';
    }
}

function updateCartTotals(total) {
    const totalFormatted = 'R$ ' + total.toFixed(2).replace('.', ',');

    const subtotal = document.getElementById('cartSubtotal');
    const totalFooter = document.getElementById('cartTotalFooter');
    const totalHeader = document.getElementById('cartTotalHeader');

    if (subtotal) subtotal.textContent = totalFormatted;
    if (totalFooter) totalFooter.textContent = totalFormatted;
    if (totalHeader) totalHeader.textContent = total > 0 ? totalFormatted : 'Vazio';
}

function showEmptyCart() {
    const cartBody = document.getElementById('cartBody');
    const cartFooter = document.getElementById('cartFooter');

    if (cartBody) {
        cartBody.innerHTML = `
            <div class="cart-sidebar__empty">
                <span class="cart-sidebar__empty-icon">🛒</span>
                <h3>Seu carrinho está vazio</h3>
                <p>Adicione produtos para continuar</p>
            </div>
        `;
    }
    if (cartFooter) {
        cartFooter.style.display = 'none';
    }
}

function refreshCartSidebar() {
    fetch('/mercado/?action=get_cart')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.items.length > 0) {
                const cartBody = document.getElementById('cartBody');
                const cartFooter = document.getElementById('cartFooter');

                let html = '<div class="cart-sidebar__items" id="cartItems">';
                data.items.forEach(item => {
                    html += `
                        <div class="cart-item" id="cartItem-${item.product_id}">
                            <div class="cart-item__image">
                                <img src="${item.image || 'https://via.placeholder.com/70'}" alt="">
                            </div>
                            <div class="cart-item__info">
                                <div class="cart-item__name">${item.name}</div>
                                <div class="cart-item__price">R$ ${item.line_total.toFixed(2).replace('.', ',')}</div>
                                <div class="cart-item__qty">
                                    <button class="cart-item__qty-btn" onclick="changeQty(${item.product_id}, -1)">−</button>
                                    <span class="cart-item__qty-value">${item.qty}</span>
                                    <button class="cart-item__qty-btn" onclick="changeQty(${item.product_id}, 1)">+</button>
                                </div>
                            </div>
                            <button class="cart-item__remove" onclick="removeFromCart(${item.product_id})">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                </svg>
                            </button>
                        </div>
                    `;
                });
                html += '</div>';

                if (cartBody) cartBody.innerHTML = html;
                if (cartFooter) cartFooter.style.display = '';
            }
        })
        .catch(err => {
            console.error('Erro ao atualizar sidebar:', err);
        });
}

// Abrir pagina do produto inteligente
function openProductModal(productId) {
    window.location.href = '/mercado/produto-view.php?id=' + productId;
}

// ═══════════════════════════════════════════════════════════════════════════════
// SISTEMA DE LOCALIZAÇÃO E COBERTURA
// ═══════════════════════════════════════════════════════════════════════════════

let locationChecked = false;

// Verificar localização ao carregar a página
document.addEventListener('DOMContentLoaded', function() {
    // Se não tem mercado selecionado na sessão, detectar localização
    const hasMarket = <?= $partner_id ? 'true' : 'false' ?>;
    const isLogged = <?= $is_logged ? 'true' : 'false' ?>;
    const customerCep = <?= json_encode($customer_address['postcode'] ?? '') ?>;

    if (!hasMarket && !locationChecked) {
        locationChecked = true;

        if (isLogged && customerCep) {
            // Usuário logado com CEP - buscar mercado automaticamente
            buscarMercadoPorCepSalvo(customerCep);
        } else if (isLogged) {
            // Usuário logado sem CEP - pedir CEP
            abrirModalEndereco('Complete seu cadastro informando seu CEP.');
        } else {
            // Visitante - detectar por IP
            detectarLocalizacao();
        }
    }
});

// Buscar mercado pelo CEP salvo do cliente
function buscarMercadoPorCepSalvo(cep) {
    // Limpar CEP (remover hífen e espaços)
    const cepLimpo = cep.replace(/\D/g, '');

    if (cepLimpo.length !== 8) {
        abrirModalEndereco('Informe seu CEP para encontrarmos o mercado mais próximo.');
        return;
    }

    showLocationLoading();

    fetch(`/mercado/api/geo-location.php?action=cep&cep=${cepLimpo}`, {
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            hideLocationLoading();

            if (data.success && data.tem_cobertura) {
                selecionarMercado(data.mercado.id, data.endereco.cidade, data.endereco.estado);
                showToast(`📍 ${data.mercado.nome} selecionado!`, 'success');
            } else if (data.success) {
                abrirModalEndereco('Ainda não entregamos no seu endereço cadastrado. Veja os mercados disponíveis ou informe outro CEP.');
            } else {
                abrirModalEndereco('Informe seu CEP para encontrarmos o mercado mais próximo.');
            }
        })
        .catch(() => {
            hideLocationLoading();
            abrirModalEndereco('Informe seu CEP para encontrarmos o mercado mais próximo.');
        });
}

// Detectar localização por IP
function detectarLocalizacao() {
    showLocationLoading();

    fetch('/mercado/api/geo-location.php?action=detect', {
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.detected && data.tem_cobertura) {
                // Tem mercado próximo - selecionar automaticamente
                hideLocationLoading();
                selecionarMercado(data.mercado.id, data.location.cidade, data.location.estado);
                showToast(`📍 Mercado encontrado em ${data.location.cidade}!`, 'success');
            } else {
                // Não encontrou por IP - buscar mercado padrão
                buscarMercadoPadrao();
            }
        })
        .catch(() => {
            // Erro na detecção - buscar mercado padrão
            buscarMercadoPadrao();
        });
}

// Buscar mercado padrão (primeiro disponível com produtos)
function buscarMercadoPadrao() {
    fetch('/mercado/api/geo-location.php?action=mercados', {
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            hideLocationLoading();
            if (data.success && data.mercados && data.mercados.length > 0) {
                // Selecionar o primeiro mercado disponível
                const m = data.mercados[0];
                selecionarMercado(m.partner_id, m.city, m.state);
            } else {
                // Nenhum mercado disponível - pedir CEP
                abrirModalEndereco('Informe seu CEP para encontrarmos o mercado mais próximo.');
            }
        })
        .catch(() => {
            hideLocationLoading();
            abrirModalEndereco('Informe seu CEP para encontrarmos o mercado mais próximo.');
        });
}

// Selecionar mercado
function selecionarMercado(partnerId, cidade, estado) {
    fetch('/mercado/api/geo-location.php?action=selecionar', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `partner_id=${partnerId}`,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        }
    })
    .catch(err => {
        console.error('Erro ao selecionar mercado:', err);
        showToast('Erro ao selecionar mercado', 'error');
    });
}

// Abrir modal de endereço (fallback - sera sobrescrita pelo location-detector.php)
function abrirModalEnderecoFallback(mensagem = '') {
    const modal = document.getElementById('modalEndereco');
    if (modal) {
        if (mensagem) {
            const msgEl = document.getElementById('modalEnderecoMsg');
            if (msgEl) msgEl.textContent = mensagem;
        }
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';

        // Focar no input
        setTimeout(() => {
            const input = document.getElementById('inputCep');
            if (input) input.focus();
        }, 300);
    }
}

// Wrapper que tenta usar location-detector primeiro
function abrirModalEndereco(mensagem = '') {
    // Se location-detector carregou, usa o modal dele
    const locOverlay = document.getElementById('locOverlay');
    if (locOverlay && typeof mostrarEstado === 'function') {
        mostrarEstado('cep');
        if (mensagem) {
            const msgEl = document.querySelector('#locStateCep .loc-message');
            if (msgEl) msgEl.textContent = mensagem;
        }
        locOverlay.style.display = 'block';
        document.body.style.overflow = 'hidden';
    } else {
        // Fallback para modal antigo
        abrirModalEnderecoFallback(mensagem);
    }
}

// Fechar modal
function fecharModalEndereco() {
    const modal = document.getElementById('modalEndereco');
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

// Buscar por CEP
function buscarPorCep() {
    const cep = document.getElementById('inputCep').value.replace(/\D/g, '');

    if (cep.length !== 8) {
        showToast('Digite um CEP válido com 8 dígitos', 'error');
        return;
    }

    const btn = document.getElementById('btnBuscarCep');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Buscando...';

    fetch(`/mercado/api/geo-location.php?action=cep&cep=${cep}`)
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = 'Buscar';

            if (data.success) {
                if (data.tem_cobertura) {
                    // Tem cobertura!
                    selecionarMercado(data.mercado.id, data.endereco.cidade, data.endereco.estado);
                    showToast(`🎉 Ótimo! Entregamos em ${data.endereco.cidade}!`, 'success');
                } else {
                    // Sem cobertura
                    mostrarSemCobertura(data.endereco.cidade, data.endereco.estado);
                }
            } else {
                showToast(data.error || 'CEP não encontrado', 'error');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = 'Buscar';
            showToast('Erro ao buscar CEP. Tente novamente.', 'error');
        });
}

// Mostrar mensagem de sem cobertura
function mostrarSemCobertura(cidade, estado) {
    const content = document.getElementById('modalEnderecoContent');
    content.innerHTML = `
        <div class="modal-no-coverage">
            <div class="modal-no-coverage__icon">😔</div>
            <h3>Ainda não chegamos em ${cidade}</h3>
            <p>Infelizmente ainda não temos cobertura na sua região, mas estamos expandindo!</p>
            <p class="modal-no-coverage__cta">Deixe seu e-mail para ser avisado quando chegarmos:</p>
            <form class="modal-notify-form" onsubmit="salvarNotificacao(event, '${cidade}', '${estado}')">
                <input type="email" id="inputEmailNotify" placeholder="seu@email.com" required>
                <button type="submit" class="btn-primary">Me avise!</button>
            </form>
            <button class="btn-secondary" onclick="verMercadosDisponiveis()">Ver mercados disponíveis</button>
        </div>
    `;
}

// Salvar e-mail para notificação
function salvarNotificacao(e, cidade, estado) {
    e.preventDefault();
    const email = document.getElementById('inputEmailNotify').value;

    // Aqui você pode salvar no banco
    console.log('Notificar:', email, cidade, estado);

    showToast('Pronto! Você será avisado quando chegarmos aí! 🚀', 'success');
    fecharModalEndereco();
}

// Ver mercados disponíveis
function verMercadosDisponiveis() {
    const content = document.getElementById('modalEnderecoContent');
    content.innerHTML = '<div class="modal-loading"><span class="spinner"></span> Carregando mercados...</div>';

    fetch('/mercado/api/geo-location.php?action=mercados')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.mercados.length > 0) {
                let html = '<div class="modal-mercados"><h3>Escolha um mercado:</h3><div class="mercados-list">';

                data.mercados.forEach(m => {
                    html += `
                        <button class="mercado-item" onclick="selecionarMercado(${m.partner_id}, '${m.city}', '${m.state}')">
                            <div class="mercado-item__info">
                                <strong>${m.name}</strong>
                                <span>${m.city}, ${m.state}</span>
                            </div>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                        </button>
                    `;
                });

                html += '</div></div>';
                content.innerHTML = html;
            } else {
                content.innerHTML = '<p>Nenhum mercado disponível no momento.</p>';
            }
        })
        .catch(err => {
            console.error('Erro ao carregar mercados:', err);
            content.innerHTML = '<p>Erro ao carregar mercados. Tente novamente.</p>';
        });
}

// Máscara de CEP
document.addEventListener('DOMContentLoaded', function() {
    const inputCep = document.getElementById('inputCep');
    if (inputCep) {
        inputCep.addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            if (v.length > 5) {
                v = v.substring(0, 5) + '-' + v.substring(5, 8);
            }
            e.target.value = v;
        });

        inputCep.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                buscarPorCep();
            }
        });
    }
});

// Loading de localização
function showLocationLoading() {
    const locationBtn = document.querySelector('.header-v2__location') || document.querySelector('.header__location');
    if (locationBtn) {
        locationBtn.classList.add('loading');
    }
}

function hideLocationLoading() {
    const locationBtn = document.querySelector('.header-v2__location') || document.querySelector('.header__location');
    if (locationBtn) {
        locationBtn.classList.remove('loading');
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// PREMIUM ANIMATIONS INITIALIZATION
// ═══════════════════════════════════════════════════════════════════════════════

// Initialize premium features when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Add ripple effect to all premium buttons
    document.querySelectorAll('.sb-btn-premium, .sb-ripple-container').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (window.OMApp && window.OMApp.SBAnimations) {
                window.OMApp.SBAnimations.createRipple(e, this);
            }
        });
    });

    // Initialize Intersection Observer for scroll animations
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '50px'
    };

    const animationObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                animationObserver.unobserve(entry.target);
            }
        });
    }, observerOptions);

    // Observe all stagger items
    document.querySelectorAll('.sb-stagger > *').forEach((el, index) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = `all 0.5s ease ${index * 0.05}s`;
        animationObserver.observe(el);
    });

    // Add hover sound effect simulation (visual feedback)
    document.querySelectorAll('.sb-product-card-premium__add-btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.15) rotate(90deg)';
        });
        btn.addEventListener('mouseleave', function() {
            this.style.transform = '';
        });
    });

    console.log('Premium animations initialized');
});

// Keyboard shortcut for search (Cmd+K or Ctrl+K)
document.addEventListener('keydown', function(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        const searchInput = document.querySelector('.header-v2__search-input');
        if (searchInput) {
            searchInput.focus();
        }
    }
});
</script>

<!-- Modal de Endereco - Glassmorphism -->
<div class="modal-overlay sb-modal-glass__backdrop" id="modalEndereco">
    <div class="modal-container modal-endereco">
        <button class="modal-close" onclick="fecharModalEndereco()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 6L6 18M6 6l12 12"/>
            </svg>
        </button>

        <div class="modal-content" id="modalEnderecoContent">
            <div class="modal-header">
                <div class="modal-icon">📍</div>
                <h2>Onde você está?</h2>
                <p id="modalEnderecoMsg">Informe seu CEP para encontrarmos o mercado mais próximo</p>
            </div>

            <div class="modal-body">
                <div class="cep-input-group">
                    <input type="text" id="inputCep" placeholder="00000-000" maxlength="9" inputmode="numeric">
                    <button type="button" id="btnBuscarCep" class="btn-primary" onclick="buscarPorCep()">Buscar</button>
                </div>

                <div class="modal-divider">
                    <span>ou</span>
                </div>

                <button class="btn-secondary btn-full" onclick="verMercadosDisponiveis()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    Ver todos os mercados
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Modal de Endereço */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.75);
    /* backdrop-filter: blur(4px); REMOVIDO */
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.modal-overlay.show {
    opacity: 1;
    visibility: visible;
}

.modal-container {
    background: white;
    border-radius: 24px;
    max-width: 420px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
    transform: translateY(20px) scale(0.95);
    transition: transform 0.3s ease;
}

.modal-overlay.show .modal-container {
    transform: translateY(0) scale(1);
}

.modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    z-index: 10;
}

.modal-close:hover {
    background: #e5e7eb;
}

.modal-close svg {
    width: 20px;
    height: 20px;
    color: #6b7280;
}

.modal-content {
    padding: 32px;
}

.modal-header {
    text-align: center;
    margin-bottom: 24px;
}

.modal-icon {
    font-size: 48px;
    margin-bottom: 16px;
}

.modal-header h2 {
    font-size: 24px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 8px;
}

.modal-header p {
    color: #6b7280;
    font-size: 15px;
}

.cep-input-group {
    display: flex;
    gap: 12px;
}

.cep-input-group input {
    flex: 1;
    padding: 16px;
    font-size: 18px;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    text-align: center;
    letter-spacing: 2px;
    transition: border-color 0.2s;
}

.cep-input-group input:focus {
    outline: none;
    border-color: #10b981;
}

.btn-primary {
    padding: 16px 24px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    font-weight: 600;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-primary:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
}

.btn-secondary {
    padding: 14px 20px;
    background: #f3f4f6;
    color: #374151;
    font-weight: 600;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-secondary:hover {
    background: #e5e7eb;
}

.btn-full {
    width: 100%;
}

.modal-divider {
    display: flex;
    align-items: center;
    margin: 20px 0;
    color: #9ca3af;
    font-size: 14px;
}

.modal-divider::before,
.modal-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e5e7eb;
}

.modal-divider span {
    padding: 0 16px;
}

/* Spinner */
.spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    display: inline-block;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Sem cobertura */
.modal-no-coverage {
    text-align: center;
    padding: 20px 0;
}

.modal-no-coverage__icon {
    font-size: 64px;
    margin-bottom: 16px;
}

.modal-no-coverage h3 {
    font-size: 20px;
    margin-bottom: 12px;
    color: #111827;
}

.modal-no-coverage p {
    color: #6b7280;
    margin-bottom: 8px;
}

.modal-no-coverage__cta {
    margin-top: 20px !important;
    font-weight: 500;
}

.modal-notify-form {
    display: flex;
    gap: 12px;
    margin: 16px 0 20px;
}

.modal-notify-form input {
    flex: 1;
    padding: 14px;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    font-size: 15px;
}

.modal-notify-form input:focus {
    outline: none;
    border-color: #10b981;
}

/* Lista de mercados */
.modal-mercados h3 {
    font-size: 18px;
    margin-bottom: 16px;
    color: #111827;
}

.mercados-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-height: 300px;
    overflow-y: auto;
}

.mercado-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s;
    text-align: left;
    width: 100%;
}

.mercado-item:hover {
    background: #ecfdf5;
    border-color: #10b981;
}

.mercado-item__info strong {
    display: block;
    color: #111827;
    margin-bottom: 4px;
}

.mercado-item__info span {
    color: #6b7280;
    font-size: 14px;
}

.mercado-item svg {
    color: #9ca3af;
}

/* Loading no botão de localização */
.header__location.loading,
.header-v2__location.loading {
    pointer-events: none;
    opacity: 0.7;
    position: relative;
}

.header__location.loading::after,
.header-v2__location.loading::after {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    border: 2px solid #10b981;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    right: 12px;
    top: 50%;
    margin-top: -8px;
}

.modal-loading {
    text-align: center;
    padding: 40px;
    color: #6b7280;
}

.modal-loading .spinner {
    border-color: rgba(16, 185, 129, 0.3);
    border-top-color: #10b981;
    width: 32px;
    height: 32px;
    margin-bottom: 12px;
}

/* Animações do carrinho */
.header__cart-count.pulse {
    animation: badgePulse 0.3s ease-out;
}

@keyframes badgePulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.3); }
    100% { transform: scale(1); }
}

/* Botão de adicionar com loading */
.product-card__add-btn.loading {
    pointer-events: none;
    opacity: 0.7;
}

.product-card__add-btn.loading svg {
    animation: spin 0.8s linear infinite;
}

.product-card__quick-btn.loading svg {
    animation: spin 0.8s linear infinite;
}

/* Animação de item do carrinho */
.cart-item {
    transition: all 0.3s ease;
}

.cart-item:hover {
    background: #f9fafb;
}

/* Toast melhorado */
.toast {
    position: fixed;
    bottom: 100px;
    left: 50%;
    transform: translateX(-50%) translateY(100px);
    background: #1f2937;
    color: white;
    padding: 14px 24px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    font-weight: 500;
}

.toast.show {
    opacity: 1;
    visibility: visible;
    transform: translateX(-50%) translateY(0);
}

.toast--success {
    background: linear-gradient(135deg, #10b981, #059669);
}

.toast--error {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}

.toast__icon {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}

/* Botão de limpar carrinho */
.cart-sidebar__clear {
    background: none;
    border: none;
    color: #ef4444;
    font-size: 13px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: all 0.2s;
}

.cart-sidebar__clear:hover {
    background: #fef2f2;
}

/* Favoritos */
.product-card__favorite {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    z-index: 5;
    transition: all 0.2s;
}

.product-card__favorite:hover {
    transform: scale(1.1);
}

.product-card__favorite svg {
    width: 18px;
    height: 18px;
    color: #d1d5db;
    transition: all 0.2s;
}

.product-card__favorite.active svg {
    color: #ef4444;
    fill: #ef4444;
}

/* Cupom banner */
.coupon-banner {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border: 1px dashed #f59e0b;
    border-radius: 12px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.coupon-banner__icon {
    font-size: 24px;
}

.coupon-banner__info {
    flex: 1;
}

.coupon-banner__code {
    font-weight: 700;
    color: #92400e;
}

.coupon-banner__desc {
    font-size: 13px;
    color: #78350f;
}

.coupon-banner__btn {
    background: #f59e0b;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.coupon-banner__btn:hover {
    background: #d97706;
}

/* Frete grátis progress */
.shipping-progress {
    background: #f3f4f6;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 16px;
}

.shipping-progress__text {
    font-size: 13px;
    color: #4b5563;
    margin-bottom: 8px;
}

.shipping-progress__text strong {
    color: #10b981;
}

.shipping-progress__bar {
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
}

.shipping-progress__fill {
    height: 100%;
    background: linear-gradient(90deg, #10b981, #059669);
    border-radius: 3px;
    transition: width 0.3s ease;
}

/* Mobile badge no nav */
.mobile-nav__badge {
    position: absolute;
    top: 4px;
    right: 50%;
    transform: translateX(50%);
    background: #ef4444;
    color: white;
    font-size: 10px;
    font-weight: 700;
    min-width: 18px;
    height: 18px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
}

/* Categoria selecionada */
.header__nav-item.active {
    background: var(--color-primary-50);
    color: var(--color-primary);
}

/* ═══════════════════════════════════════════════════════════════════════════
   MEMBERSHIP STYLES
   ═══════════════════════════════════════════════════════════════════════════ */

/* Promo bar para membros */
.header__promo--member {
    background: linear-gradient(90deg, #1a1a2e 0%, #16213e 50%, #1a1a2e 100%);
}

.header__promo-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: white;
    text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}

.header__promo-link {
    color: #fbbf24;
    text-decoration: none;
    transition: all 0.2s;
}

.header__promo-link:hover {
    color: #fcd34d;
    text-decoration: underline;
}

/* Banner de membership na página */
.membership-banner {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border-radius: 16px;
    padding: 20px 24px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    color: white;
    position: relative;
    overflow: hidden;
}

.membership-banner::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 200px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(251, 191, 36, 0.1));
}

.membership-banner__icon {
    font-size: 48px;
    flex-shrink: 0;
}

.membership-banner__content {
    flex: 1;
}

.membership-banner__title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 4px;
}

.membership-banner__desc {
    font-size: 14px;
    opacity: 0.9;
}

.membership-banner__benefits {
    display: flex;
    gap: 16px;
    margin-top: 8px;
    font-size: 13px;
}

.membership-banner__benefit {
    display: flex;
    align-items: center;
    gap: 4px;
}

.membership-banner__btn {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: #1a1a2e;
    border: none;
    padding: 12px 24px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
    z-index: 1;
}

.membership-banner__btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(251, 191, 36, 0.4);
}

/* Badge de membership no avatar */
.header__user-member {
    position: absolute;
    bottom: -2px;
    right: -2px;
    font-size: 10px;
    background: white;
    border-radius: 50%;
    padding: 2px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}

/* Desconto de frete no produto */
.product-card__shipping {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: #10b981;
    margin-top: 4px;
}

.product-card__shipping--free {
    background: linear-gradient(90deg, #10b981, #059669);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: 600;
}

/* Sidebar do carrinho com membership */
.cart-sidebar__membership {
    background: linear-gradient(135deg, #1a1a2e, #16213e);
    border-radius: 12px;
    padding: 16px;
    margin: 12px 0;
    color: white;
}

.cart-sidebar__membership-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.cart-sidebar__membership-badge {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}

.cart-sidebar__membership-discount {
    font-size: 14px;
    font-weight: 600;
    color: #22c55e;
}

.cart-sidebar__membership-info {
    font-size: 12px;
    opacity: 0.8;
}

/* Níveis de membership - cores */
.level-bronze { background: linear-gradient(135deg, #CD7F32, #8B4513); }
.level-silver { background: linear-gradient(135deg, #C0C0C0, #A8A8A8); }
.level-gold { background: linear-gradient(135deg, #FFD700, #FFA500); }
.level-platinum { background: linear-gradient(135deg, #E5E4E2, #9CA3AF); }
.level-diamond { background: linear-gradient(135deg, #00F5FF, #0099FF); }

/* ═══════════════════════════════════════════════════════════════════════════════
   CORREÇÕES E MELHORIAS CSS
   ═══════════════════════════════════════════════════════════════════════════════ */

/* Ocultar badges de AI */
.ai-badge,
.sb-ai-badge-blue {
    display: none !important;
}

/* Melhorar espaçamento das seções */
.ai-section,
.section {
    margin-bottom: 32px;
}

/* Corrigir espaçamento do header em mobile */
@media (max-width: 768px) {
    .header__top-inner {
        flex-wrap: wrap;
        gap: 8px;
        padding: 8px 0;
    }

    .header__status {
        display: none;
    }

    .header__location {
        max-width: 100%;
        flex: 1;
    }

    .header__location-address {
        font-size: 13px;
        max-width: 200px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .header__main-inner {
        flex-wrap: wrap;
        gap: 12px;
    }

    .header__search {
        order: 3;
        width: 100%;
        max-width: none;
    }

    .header__actions {
        margin-left: auto;
    }
}

/* Melhorar hero em mobile */
@media (max-width: 768px) {
    .hero {
        flex-direction: column;
        padding: 24px 16px;
    }

    .hero__title {
        font-size: 1.75rem !important;
    }

    .hero__cards {
        flex-direction: row;
        overflow-x: auto;
        gap: 12px;
        padding-bottom: 8px;
    }

    .hero__card {
        flex: 0 0 auto;
        min-width: 200px;
    }
}

/* Corrigir grid de categorias em mobile */
@media (max-width: 640px) {
    .categories-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
    }

    .category-card {
        padding: 12px 8px;
    }

    .category-card__icon {
        font-size: 24px;
    }

    .category-card__name {
        font-size: 11px;
    }

    .category-card__count {
        font-size: 9px;
    }
}

/* Melhorar cards de flash deals */
@media (max-width: 768px) {
    .flash-scroll {
        padding: 0 16px;
    }

    .flash-card {
        flex: 0 0 140px;
    }
}

/* Corrigir carrinho sidebar em mobile */
@media (max-width: 480px) {
    .cart-sidebar {
        max-width: 100%;
    }

    .cart-sidebar__footer {
        padding: 16px;
    }
}

/* Garantir que main tenha padding adequado com mobile nav */
@media (max-width: 768px) {
    .main {
        padding-bottom: 80px;
    }
}

/* Fix para promo banner não truncar texto */
.header__promo-inner {
    white-space: nowrap;
}

/* Melhorar acessibilidade de botões */
button:focus-visible,
a:focus-visible {
    outline: 2px solid #10b981;
    outline-offset: 2px;
}

/* Corrigir z-index do toast para ficar acima do mobile nav */
.toast {
    z-index: 10001;
    bottom: 90px;
}

/* Corrigir espaçamento do footer do carrinho */
.cart-sidebar__checkout {
    width: 100%;
    justify-content: center;
    padding: 16px 24px;
    font-size: 16px;
    border-radius: 14px;
}
</style>

<?php
// ═══════════════════════════════════════════════════════════════════════════════
// DETECTOR DE LOCALIZAÇÃO - Sempre inclui, mas só abre automaticamente se necessário
// ═══════════════════════════════════════════════════════════════════════════════
include __DIR__ . '/components/location-detector.php';
?>

<!-- PWA Service Worker -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/mercado/sw.js')
            .then((registration) => {
                console.log('[PWA] Service Worker registrado:', registration.scope);
            })
            .catch((error) => {
                console.error('[PWA] Erro ao registrar Service Worker:', error);
            });
    });
}

// Prompt de instalacao do PWA
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
});

// Funcao para instalar PWA (pode ser chamada por um botao)
function installPWA() {
    if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then((choiceResult) => {
            console.log('[PWA] User choice:', choiceResult.outcome);
            deferredPrompt = null;
        });
    }
}
</script>

<!-- Smart Search Autocomplete -->
<script src="/mercado/assets/js/smart-search.js"></script>

</body>
</html>
