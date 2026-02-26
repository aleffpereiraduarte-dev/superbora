<?php
/**
 * ONEMUNDO MERCADO - PRODUTO VIEW
 * Design Instacart Style - Completo
 */

require_once 'auth-guard.php';
require_once __DIR__ . '/includes/env_loader.php';

$pdo = null;
$oc_root = dirname(__DIR__);
if (file_exists($oc_root . '/config.php') && !defined('DB_DATABASE')) {
    require_once($oc_root . '/config.php');
}

if (defined('DB_HOSTNAME') && defined('DB_DATABASE')) {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
            DB_USERNAME, DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        die("Erro de conexao.");
    }
}

session_start();

$customer_id = $_SESSION['customer_id'] ?? 0;
$is_logged = false;
$customer_name = 'Visitante';

if ($customer_id && $pdo) {
    $stmt = $pdo->prepare("SELECT firstname FROM oc_customer WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    if ($customer) {
        $is_logged = true;
        $customer_name = $customer['firstname'];
    }
}

$cart = $_SESSION['cart'] ?? [];
$cartCount = 0;
$cartTotal = 0;
foreach ($cart as $item) {
    $cartCount += $item['qty'];
    $cartTotal += $item['price'] * $item['qty'];
}

$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$product_id) { header('Location: /mercado/'); exit; }

$product = null;
if ($pdo) {
    $stmt = $pdo->prepare("
        SELECT pb.*, pp.price, pp.price_promo, pp.stock, c.name as category_name,
               pb.ai_benefits, pb.ai_tips, pb.ai_combines, pb.ai_recipe, pb.ai_generated_at
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
        WHERE pb.product_id = ? AND pp.status = '1' LIMIT 1
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
}

// Verificar se tem conteúdo AI em cache
$ai_cached = null;
if ($product && !empty($product['ai_generated_at'])) {
    $ai_cached = [
        'beneficios' => $product['ai_benefits'] ? json_decode($product['ai_benefits'], true) : null,
        'dicas_uso' => $product['ai_tips'] ? json_decode($product['ai_tips'], true) : null,
        'harmonizacao' => $product['ai_combines'] ? json_decode($product['ai_combines'], true) : null,
        'receita_rapida' => $product['ai_recipe'] ? json_decode($product['ai_recipe'], true) : null
    ];
}

if (!$product) { header('Location: /mercado/'); exit; }

$preco_final = $product['price_promo'] > 0 ? $product['price_promo'] : $product['price'];
$tem_promo = $product['price_promo'] > 0 && $product['price_promo'] < $product['price'];
$desconto = $tem_promo ? round((1 - $product['price_promo'] / $product['price']) * 100) : 0;
$in_cart = isset($cart[$product_id]);
$cart_qty = $in_cart ? $cart[$product_id]['qty'] : 0;

// Produtos relacionados
$related = [];
if ($pdo && $product['category_id']) {
    $stmt = $pdo->prepare("
        SELECT pb.product_id, pb.name, pb.image, pb.brand, pp.price, pp.price_promo
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        WHERE pb.category_id = ? AND pb.product_id != ? AND pp.status = 1
        ORDER BY RANDOM() LIMIT 12
    ");
    $stmt->execute([$product['category_id'], $product_id]);
    $related = $stmt->fetchAll();
}

// API Carrinho
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'add_to_cart') {
        $qty = (int)($_POST['qty'] ?? 1);
        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
        if (isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]['qty'] += $qty;
        } else {
            $_SESSION['cart'][$product_id] = [
                'product_id' => $product_id, 'name' => $product['name'],
                'price' => $preco_final, 'image' => $product['image'], 'qty' => $qty
            ];
        }
        $total = 0; $count = 0;
        foreach ($_SESSION['cart'] as $item) { $count += $item['qty']; $total += $item['price'] * $item['qty']; }
        echo json_encode(['success' => true, 'count' => $count, 'total' => $total, 'qty' => $_SESSION['cart'][$product_id]['qty']]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#108910">
    <title><?= htmlspecialchars($product['name']) ?> - OneMundo Mercado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* Instacart Colors */
            --green: #108910;
            --green-hover: #007200;
            --green-active: #006000;
            --green-light: #e8f5e8;
            --green-forest: #003121;

            --white: #FFFFFF;
            --bg-light: #F6F7F8;
            --bg-cream: #F7F5F0;
            --border: #E8E9EB;
            --border-dark: #C7C8CD;
            --text-light: #72767E;
            --text: #343538;
            --text-dark: #242529;

            --red: #D4342A;
            --red-light: #FEF0EF;
            --yellow: #F7A000;

            --shadow-sm: 0 1px 2px rgba(0,0,0,0.08);
            --shadow-md: 0 2px 8px rgba(0,0,0,0.1);
            --shadow-lg: 0 4px 16px rgba(0,0,0,0.12);
            --shadow-xl: 0 8px 32px rgba(0,0,0,0.16);

            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
            --radius-full: 9999px;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--white);
            color: var(--text);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button {
            font-family: inherit;
            cursor: pointer;
            border: none;
            background: none;
        }

        /* ==================== HEADER ==================== */
        .header {
            position: sticky !important;
            top: 0 !important;
            z-index: 9999 !important;
            background: #108910 !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15) !important;
        }

        .header-top {
            background: rgba(0,0,0,0.15) !important;
            color: #FFFFFF !important;
            text-align: center !important;
            padding: 8px 16px !important;
            font-size: 13px !important;
            font-weight: 500 !important;
        }

        .header-main {
            max-width: 1280px !important;
            margin: 0 auto !important;
            padding: 12px 24px !important;
            display: flex !important;
            align-items: center !important;
            gap: 24px !important;
            background: transparent !important;
        }

        .btn-back {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 40px !important;
            height: 40px !important;
            border-radius: 9999px !important;
            color: #FFFFFF !important;
            background: transparent !important;
            transition: background 0.2s !important;
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.2) !important;
        }

        .logo {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
        }

        .logo-icon {
            width: 40px !important;
            height: 40px !important;
            background: #FFFFFF !important;
            border-radius: 12px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 20px !important;
        }

        .logo-text {
            font-size: 20px !important;
            font-weight: 800 !important;
            color: #FFFFFF !important;
            letter-spacing: -0.5px !important;
        }

        /* Search */
        .search-box {
            flex: 1 !important;
            max-width: 600px !important;
            position: relative !important;
        }

        .search-input {
            width: 100% !important;
            height: 48px !important;
            padding: 0 48px !important;
            background: #FFFFFF !important;
            border: none !important;
            border-radius: 9999px !important;
            font-size: 15px !important;
            color: #343538 !important;
            transition: all 0.2s !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
        }

        .search-input::placeholder {
            color: #72767E !important;
        }

        .search-input:focus {
            outline: none !important;
            box-shadow: 0 2px 12px rgba(0,0,0,0.15) !important;
        }

        .search-icon {
            position: absolute !important;
            left: 16px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            color: #108910 !important;
            pointer-events: none !important;
        }

        .search-clear {
            position: absolute !important;
            right: 12px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            width: 28px !important;
            height: 28px !important;
            border-radius: 9999px !important;
            background: #E8E9EB !important;
            color: #343538 !important;
            display: none !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 14px !important;
        }

        .search-input:not(:placeholder-shown) + .search-icon + .search-clear {
            display: flex !important;
        }

        /* Header Actions */
        .header-actions {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
        }

        .header-btn {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 44px !important;
            height: 44px !important;
            border-radius: 9999px !important;
            color: #FFFFFF !important;
            background: transparent !important;
            position: relative !important;
            transition: background 0.2s !important;
        }

        .header-btn:hover {
            background: rgba(255,255,255,0.2) !important;
        }

        .header-btn.active {
            color: #FFFFFF !important;
            background: rgba(255,255,255,0.3) !important;
        }

        .header-btn .badge {
            position: absolute !important;
            top: 4px !important;
            right: 4px !important;
            min-width: 18px !important;
            height: 18px !important;
            background: #FFFFFF !important;
            color: #108910 !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            border-radius: 9999px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 0 5px !important;
        }

        .btn-cart-main {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            height: 44px !important;
            padding: 0 20px !important;
            background: #FFFFFF !important;
            color: #108910 !important;
            border-radius: 9999px !important;
            font-size: 15px !important;
            font-weight: 700 !important;
            transition: all 0.2s !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
            text-decoration: none !important;
        }

        .btn-cart-main:hover {
            background: #FFFFFF !important;
            transform: scale(1.02) !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
        }

        .btn-cart-main svg {
            width: 20px;
            height: 20px;
        }

        .cart-count {
            background: var(--orange);
            color: var(--white);
            padding: 2px 8px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 700;
        }

        /* ==================== MAIN CONTENT ==================== */
        .main {
            max-width: 1280px;
            margin: 0 auto;
            padding: 24px;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 24px;
        }

        .breadcrumb a {
            color: var(--green);
            transition: color 0.2s;
        }

        .breadcrumb a:hover {
            color: var(--green-hover);
            text-decoration: underline;
        }

        .breadcrumb-sep {
            color: var(--border-dark);
        }

        /* Product Grid */
        .product-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
            margin-bottom: 48px;
        }

        @media (max-width: 900px) {
            .product-grid {
                grid-template-columns: 1fr;
                gap: 32px;
            }
        }

        /* Gallery */
        .gallery {
            position: sticky;
            top: 140px;
        }

        @media (max-width: 900px) {
            .gallery {
                position: static;
            }
        }

        .gallery-main {
            position: relative;
            background: var(--bg-light);
            border-radius: var(--radius-xl);
            padding: 32px;
            margin-bottom: 12px;
            overflow: hidden;
        }

        .gallery-image {
            width: 100%;
            height: 400px;
            object-fit: contain;
            display: block;
            cursor: zoom-in;
            transition: transform 0.3s;
        }

        .gallery-main:hover .gallery-image {
            transform: scale(1.05);
        }

        .gallery-badge {
            position: absolute;
            top: 16px;
            left: 16px;
            background: var(--red);
            color: var(--white);
            padding: 6px 12px;
            border-radius: var(--radius-full);
            font-size: 13px;
            font-weight: 700;
        }

        .gallery-actions {
            position: absolute;
            top: 16px;
            right: 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .gallery-action {
            width: 44px;
            height: 44px;
            background: var(--white);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text);
            box-shadow: var(--shadow-md);
            transition: all 0.2s;
        }

        .gallery-action:hover {
            background: var(--bg-light);
            transform: scale(1.05);
        }

        .gallery-action.active {
            background: var(--red-light);
            color: var(--red);
        }

        .gallery-thumbs {
            display: flex;
            gap: 8px;
        }

        .thumb {
            width: 72px;
            height: 72px;
            border: 2px solid var(--border);
            border-radius: var(--radius-md);
            overflow: hidden;
            cursor: pointer;
            transition: border-color 0.2s;
            background: var(--bg-light);
            padding: 8px;
        }

        .thumb:hover {
            border-color: var(--border-dark);
        }

        .thumb.active {
            border-color: var(--green);
        }

        .thumb img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        /* Product Info */
        .product-info {
            padding-top: 8px;
        }

        .product-brand {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: var(--bg-light);
            border-radius: var(--radius-full);
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 12px;
        }

        .product-brand svg {
            color: var(--green);
        }

        .product-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.3;
            margin-bottom: 12px;
        }

        .product-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .product-rating {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }

        .product-rating svg {
            color: var(--yellow);
        }

        .product-reviews {
            color: var(--text-light);
            font-size: 14px;
        }

        .product-stock {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            font-weight: 500;
            color: var(--green);
        }

        .stock-dot {
            width: 8px;
            height: 8px;
            background: var(--green);
            border-radius: 50%;
        }

        /* Price Box */
        .price-box {
            background: var(--bg-light);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 20px;
        }

        .price-row {
            display: flex;
            align-items: baseline;
            gap: 12px;
            flex-wrap: wrap;
        }

        .price-current {
            font-size: 36px;
            font-weight: 800;
            color: var(--text-dark);
        }

        .price-old {
            font-size: 18px;
            color: var(--text-light);
            text-decoration: line-through;
        }

        .price-discount {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: var(--red-light);
            color: var(--red);
            border-radius: var(--radius-full);
            font-size: 13px;
            font-weight: 700;
        }

        .price-unit {
            font-size: 14px;
            color: var(--text-light);
            margin-top: 4px;
        }

        /* Quantity */
        .quantity-section {
            margin-bottom: 16px;
        }

        .quantity-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
        }

        .quantity-selector {
            display: inline-flex;
            align-items: center;
            background: var(--bg-light);
            border-radius: var(--radius-full);
            padding: 4px;
        }

        .qty-btn {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-full);
            background: var(--white);
            color: var(--text);
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            border: 1px solid var(--border);
        }

        .qty-btn:hover {
            background: var(--green);
            color: var(--white);
            border-color: var(--green);
        }

        .qty-value {
            min-width: 48px;
            text-align: center;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-dark);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        .btn-add {
            flex: 1;
            height: 56px;
            background: var(--green);
            color: var(--white);
            border-radius: var(--radius-full);
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s;
        }

        .btn-add:hover {
            background: var(--green-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-add:active {
            transform: translateY(0);
        }

        .btn-add.in-cart {
            background: var(--green-forest);
        }

        .btn-buy {
            height: 56px;
            padding: 0 32px;
            background: var(--white);
            color: var(--text-dark);
            border: 2px solid var(--border-dark);
            border-radius: var(--radius-full);
            font-size: 16px;
            font-weight: 700;
            transition: all 0.2s;
        }

        .btn-buy:hover {
            border-color: var(--green);
            color: var(--green);
        }

        /* Features */
        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            padding: 20px 0;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            margin-bottom: 24px;
        }

        @media (max-width: 600px) {
            .features {
                grid-template-columns: 1fr;
            }
        }

        .feature {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--bg-light);
            border-radius: var(--radius-md);
        }

        .feature-icon {
            width: 40px;
            height: 40px;
            background: var(--white);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .feature-text {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
        }

        /* Description */
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title svg {
            color: var(--green);
        }

        .description-text {
            font-size: 15px;
            color: var(--text);
            line-height: 1.7;
            margin-bottom: 24px;
        }

        /* Details Cards */
        .details-section {
            margin-top: 48px;
            padding-top: 48px;
            border-top: 1px solid var(--border);
        }

        .details-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }

        .details-icon {
            width: 48px;
            height: 48px;
            background: var(--green);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
        }

        .details-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
        }

        .detail-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px;
            transition: all 0.2s;
        }

        .detail-card:hover {
            border-color: var(--green);
            box-shadow: var(--shadow-md);
        }

        .detail-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .detail-card-icon {
            width: 40px;
            height: 40px;
            background: var(--green-light);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .detail-card-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .detail-list {
            list-style: none;
        }

        .detail-list li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 0;
            font-size: 14px;
            color: var(--text);
            border-bottom: 1px solid var(--border);
        }

        .detail-list li:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .detail-list .bullet {
            color: var(--green);
            font-weight: 700;
            flex-shrink: 0;
        }

        /* Recipe Card Special */
        .detail-card.recipe {
            background: linear-gradient(135deg, #FEF7E0 0%, #FEF3C7 100%);
            border: none;
        }

        .detail-card.recipe .detail-card-icon {
            background: rgba(247, 160, 0, 0.2);
        }

        .detail-card.recipe .detail-card-title {
            color: #92400E;
        }

        .recipe-content {
            font-size: 14px;
            color: #78350F;
            line-height: 1.7;
        }

        .recipe-ingredients {
            background: rgba(255,255,255,0.6);
            border-radius: var(--radius-md);
            padding: 12px;
            margin-bottom: 12px;
        }

        .recipe-ingredients h5 {
            font-size: 13px;
            font-weight: 700;
            color: #92400E;
            margin-bottom: 8px;
        }

        .recipe-ingredients ul {
            margin: 0;
            padding-left: 20px;
        }

        .recipe-ingredients li {
            padding: 2px 0;
            border: none;
        }

        /* Tags */
        .tags-section {
            margin-top: 24px;
        }

        .tags-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .tag {
            padding: 8px 16px;
            background: var(--green-light);
            color: var(--green);
            border-radius: var(--radius-full);
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .tag:hover {
            background: var(--green);
            color: var(--white);
        }

        /* Related Section */
        .related-section {
            margin-top: 48px;
            padding-top: 48px;
            border-top: 1px solid var(--border);
        }

        .related-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .related-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .related-nav {
            display: flex;
            gap: 8px;
        }

        .nav-btn {
            width: 44px;
            height: 44px;
            border: 1px solid var(--border-dark);
            border-radius: var(--radius-full);
            background: var(--white);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .nav-btn:hover {
            border-color: var(--green);
            color: var(--green);
        }

        .related-track {
            display: flex;
            gap: 16px;
            overflow-x: auto;
            scroll-behavior: smooth;
            padding-bottom: 16px;
            scrollbar-width: none;
        }

        .related-track::-webkit-scrollbar {
            display: none;
        }

        .product-card {
            flex: 0 0 200px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            transition: all 0.2s;
        }

        .product-card:hover {
            border-color: var(--green);
            box-shadow: var(--shadow-lg);
            transform: translateY(-4px);
        }

        .card-image {
            position: relative;
            background: var(--bg-light);
            padding: 16px;
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-image img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .card-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            background: var(--red);
            color: var(--white);
            padding: 4px 8px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 700;
        }

        .card-body {
            padding: 12px;
        }

        .card-brand {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .card-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 40px;
        }

        .card-price {
            display: flex;
            align-items: baseline;
            gap: 8px;
        }

        .card-price-current {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .card-price-old {
            font-size: 13px;
            color: var(--text-light);
            text-decoration: line-through;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: var(--text-dark);
            color: var(--white);
            padding: 16px 24px;
            border-radius: var(--radius-full);
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow-xl);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
            z-index: 9999;
        }

        .toast.show {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0);
        }

        .toast.success {
            background: var(--green);
        }

        /* Zoom Modal */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.9);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal img {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
            border-radius: var(--radius-lg);
        }

        .modal-close {
            position: absolute;
            top: 24px;
            right: 24px;
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.1);
            border-radius: var(--radius-full);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: rgba(255,255,255,0.2);
        }

        /* Mobile Nav */
        .mobile-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            border-top: 1px solid var(--border);
            padding: 8px 16px;
            padding-bottom: calc(8px + env(safe-area-inset-bottom));
            z-index: 1000;
        }

        .mobile-nav-inner {
            display: flex;
            justify-content: space-around;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 8px 16px;
            color: var(--text-light);
            font-size: 11px;
            font-weight: 500;
            position: relative;
        }

        .nav-item:hover,
        .nav-item.active {
            color: var(--green);
        }

        .nav-item .badge {
            position: absolute;
            top: 2px;
            right: 8px;
            min-width: 16px;
            height: 16px;
            background: var(--red);
            color: var(--white);
            font-size: 10px;
            font-weight: 700;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header-top {
                display: none;
            }

            .header-main {
                padding: 12px 16px;
                gap: 12px;
            }

            .search-box {
                display: none;
            }

            .btn-cart-main span {
                display: none;
            }

            .btn-cart-main {
                width: 44px;
                padding: 0;
            }

            .main {
                padding: 16px;
                padding-bottom: 100px;
            }

            .product-title {
                font-size: 22px;
            }

            .price-current {
                font-size: 28px;
            }

            .features {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn-buy {
                width: 100%;
            }

            .mobile-nav {
                display: block;
            }

            .gallery-image {
                height: 280px;
            }

            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Skeleton */
        .skeleton {
            background: linear-gradient(90deg, var(--bg-light) 25%, var(--border) 50%, var(--bg-light) 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: var(--radius-md);
        }

        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Stock Status */
        .stock-low {
            color: var(--yellow);
        }
        .stock-low .stock-dot {
            background: var(--yellow);
        }
        .stock-out {
            color: var(--red);
        }
        .stock-out .stock-dot {
            background: var(--red);
        }

        /* Button States */
        .btn-add:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }
        .btn-add.in-cart {
            background: var(--green-forest);
        }

        /* Animations */
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        .btn-add:not(:disabled):active {
            animation: pulse 0.2s ease;
        }

        /* Error fallback image */
        .gallery-image.error,
        .card-image img.error {
            opacity: 0.5;
            filter: grayscale(1);
        }

        /* Fix for mobile cart count */
        .btn-cart-main .cart-count {
            position: relative;
            margin-left: 4px;
        }

        /* Improved mobile experience */
        @media (max-width: 768px) {
            .price-box {
                position: sticky;
                bottom: 70px;
                z-index: 50;
                margin: 0 -16px;
                border-radius: 0;
                box-shadow: 0 -4px 12px rgba(0,0,0,0.1);
            }
            .action-buttons {
                position: sticky;
                bottom: 70px;
                z-index: 50;
                background: var(--white);
                padding: 12px;
                margin: 0 -16px;
                box-shadow: 0 -4px 12px rgba(0,0,0,0.1);
            }
            .quantity-section {
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
        }

        /* Print styles */
        @media print {
            .header, .mobile-nav, .action-buttons, .related-section {
                display: none !important;
            }
            .gallery {
                position: static;
            }
        }
    </style>
    <!-- Mobile Responsive Fixes -->
    <link rel="stylesheet" href="/mercado/assets/css/mobile-responsive-fixes.css">
</head>
<body>

<!-- Header -->
<header class="header" style="background:#108910 !important;position:sticky !important;top:0 !important;z-index:9999 !important;">
    <div class="header-top" style="background:rgba(0,0,0,0.15) !important;color:#fff !important;padding:8px 16px !important;text-align:center !important;">
        🚚 Entrega grátis acima de R$ 150 | Use o código PRIMEIRA10 e ganhe 10% OFF
    </div>
    <div class="header-main" style="background:transparent !important;">
        <button class="btn-back" onclick="history.back()" style="color:#fff !important;background:transparent !important;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
        </button>

        <a href="/mercado/" class="logo">
            <span class="logo-icon" style="background:#fff !important;">🛒</span>
            <span class="logo-text" style="color:#fff !important;font-weight:800 !important;">OneMundo</span>
        </a>

        <div class="search-box">
            <input type="text" class="search-input" placeholder="Buscar produtos, marcas e categorias..." id="searchInput" style="background:#fff !important;border:none !important;">
            <svg class="search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#108910" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
            <button class="search-clear" onclick="clearSearch()">×</button>
        </div>

        <div class="header-actions">
            <button class="header-btn" id="favBtn" onclick="toggleFavorite()" style="color:#fff !important;background:transparent !important;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                </svg>
            </button>

            <a href="/mercado/checkout.php" class="btn-cart-main" style="background:#fff !important;color:#108910 !important;border-radius:9999px !important;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <path d="M16 10a4 4 0 0 1-8 0"/>
                </svg>
                <span>Carrinho</span>
                <?php if ($cartCount > 0): ?>
                <span class="cart-count" id="cartCount" style="background:#108910 !important;color:#fff !important;"><?= $cartCount ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>
</header>

<!-- Main Content -->
<main class="main">
    <!-- Breadcrumb -->
    <nav class="breadcrumb">
        <a href="/mercado/">Início</a>
        <span class="breadcrumb-sep">›</span>
        <?php if ($product['category_name']): ?>
        <a href="/mercado/?categoria=<?= $product['category_id'] ?>"><?= htmlspecialchars($product['category_name']) ?></a>
        <span class="breadcrumb-sep">›</span>
        <?php endif; ?>
        <span><?= htmlspecialchars($product['name']) ?></span>
    </nav>

    <!-- Product Grid -->
    <div class="product-grid">
        <!-- Gallery -->
        <div class="gallery">
            <div class="gallery-main" onclick="openModal()">
                <?php if ($tem_promo): ?>
                <span class="gallery-badge">-<?= $desconto ?>% OFF</span>
                <?php endif; ?>

                <div class="gallery-actions" onclick="event.stopPropagation()">
                    <button class="gallery-action" id="galleryFavBtn" onclick="toggleFavorite()">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                        </svg>
                    </button>
                    <button class="gallery-action" onclick="shareProduct()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
                            <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                        </svg>
                    </button>
                </div>

                <img src="<?= $product['image'] ?: '/mercado/assets/img/no-image.svg' ?>"
                     alt="<?= htmlspecialchars($product['name']) ?>"
                     class="gallery-image"
                     id="mainImage"
                     onerror="this.onerror=null;this.src='/mercado/assets/img/no-image.svg';">
            </div>

            <div class="gallery-thumbs">
                <button class="thumb active" data-src="<?= $product['image'] ?>">
                    <img src="<?= $product['image'] ?: 'https://via.placeholder.com/72' ?>" alt="">
                </button>
            </div>
        </div>

        <!-- Product Info -->
        <div class="product-info">
            <?php if ($product['brand']): ?>
            <span class="product-brand">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <?= htmlspecialchars($product['brand']) ?>
            </span>
            <?php endif; ?>

            <h1 class="product-title"><?= htmlspecialchars($product['name']) ?></h1>

            <div class="product-meta">
                <div class="product-rating">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                    </svg>
                    4.8
                </div>
                <span class="product-reviews">(127 avaliações)</span>
                <span class="product-stock">
                    <span class="stock-dot"></span>
                    Em estoque
                </span>
            </div>

            <!-- Price -->
            <div class="price-box">
                <div class="price-row">
                    <span class="price-current">R$ <?= number_format($preco_final, 2, ',', '.') ?></span>
                    <?php if ($tem_promo): ?>
                    <span class="price-old">R$ <?= number_format($product['price'], 2, ',', '.') ?></span>
                    <span class="price-discount">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
                        </svg>
                        Economia R$ <?= number_format($product['price'] - $preco_final, 2, ',', '.') ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php if ($product['unit']): ?>
                <p class="price-unit"><?= htmlspecialchars($product['unit']) ?></p>
                <?php endif; ?>
            </div>

            <!-- Quantity -->
            <div class="quantity-section">
                <p class="quantity-label">Quantidade</p>
                <div class="quantity-selector">
                    <button class="qty-btn" onclick="changeQty(-1)">−</button>
                    <span class="qty-value" id="qtyValue"><?= $cart_qty ?: 1 ?></span>
                    <button class="qty-btn" onclick="changeQty(1)">+</button>
                </div>
            </div>

            <!-- Actions -->
            <div class="action-buttons">
                <button class="btn-add <?= $in_cart ? 'in-cart' : '' ?>" id="btnAdd" onclick="addToCart()">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <path d="M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                    <span id="btnAddText"><?= $in_cart ? 'Atualizar carrinho' : 'Adicionar ao carrinho' ?></span>
                </button>
                <button class="btn-buy" onclick="buyNow()">Comprar agora</button>
            </div>

            <!-- Features -->
            <div class="features">
                <div class="feature">
                    <span class="feature-icon">🚚</span>
                    <span class="feature-text">Entrega rápida</span>
                </div>
                <div class="feature">
                    <span class="feature-icon">✅</span>
                    <span class="feature-text">Qualidade garantida</span>
                </div>
                <div class="feature">
                    <span class="feature-icon">↩️</span>
                    <span class="feature-text">Troca fácil</span>
                </div>
            </div>

            <!-- Description -->
            <?php if ($product['description']): ?>
            <div class="description-section">
                <h3 class="section-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                    Sobre o produto
                </h3>
                <p class="description-text"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
            </div>
            <?php endif; ?>

            <!-- Ingredientes -->
            <?php if (!empty($product['ingredients'])): ?>
            <div class="ingredients-section" style="margin-top:20px;">
                <h3 class="section-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                        <rect x="9" y="3" width="6" height="4" rx="1"/>
                    </svg>
                    Ingredientes
                </h3>
                <p class="description-text" style="font-size:13px;"><?= nl2br(htmlspecialchars($product['ingredients'])) ?></p>
            </div>
            <?php endif; ?>

            <!-- Informacoes Nutricionais -->
            <?php
            $nutrition = null;
            if (!empty($product['nutrition_json'])) {
                $nutrition = is_string($product['nutrition_json'])
                    ? json_decode($product['nutrition_json'], true)
                    : $product['nutrition_json'];
            }
            if ($nutrition && is_array($nutrition)):
            ?>
            <div class="nutrition-section" style="margin-top:20px;">
                <h3 class="section-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8h1a4 4 0 010 8h-1"/>
                        <path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/>
                        <line x1="6" y1="1" x2="6" y2="4"/>
                        <line x1="10" y1="1" x2="10" y2="4"/>
                        <line x1="14" y1="1" x2="14" y2="4"/>
                    </svg>
                    Informacoes Nutricionais
                </h3>
                <div style="background:var(--bg-light);border-radius:var(--radius-md);padding:16px;margin-top:12px;">
                    <table style="width:100%;font-size:14px;border-collapse:collapse;">
                        <tbody>
                            <?php foreach ($nutrition as $key => $value): ?>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td style="padding:8px 0;color:var(--text);"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $key))) ?></td>
                                <td style="padding:8px 0;text-align:right;font-weight:600;color:var(--text-dark);"><?= htmlspecialchars($value) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Details Section -->
    <section class="details-section">
        <div class="details-header">
            <div class="details-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
            </div>
            <h2 class="details-title">Informações do produto</h2>
        </div>

        <div class="details-grid" id="detailsGrid">
            <div class="detail-card">
                <div class="skeleton" style="height:24px;width:50%;margin-bottom:16px"></div>
                <div class="skeleton" style="height:16px;width:100%;margin-bottom:8px"></div>
                <div class="skeleton" style="height:16px;width:90%;margin-bottom:8px"></div>
                <div class="skeleton" style="height:16px;width:80%"></div>
            </div>
            <div class="detail-card">
                <div class="skeleton" style="height:24px;width:45%;margin-bottom:16px"></div>
                <div class="skeleton" style="height:16px;width:100%;margin-bottom:8px"></div>
                <div class="skeleton" style="height:16px;width:85%"></div>
            </div>
        </div>
    </section>

    <!-- Related Products -->
    <?php if (!empty($related)): ?>
    <section class="related-section">
        <div class="related-header">
            <h2 class="related-title">Você também pode gostar</h2>
            <div class="related-nav">
                <button class="nav-btn" onclick="scrollRelated(-1)">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M15 18l-6-6 6-6"/>
                    </svg>
                </button>
                <button class="nav-btn" onclick="scrollRelated(1)">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 18l6-6-6-6"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="related-track" id="relatedTrack">
            <?php foreach ($related as $r):
                $rp = $r['price_promo'] > 0 ? $r['price_promo'] : $r['price'];
                $rPromo = $r['price_promo'] > 0 && $r['price_promo'] < $r['price'];
                $rDesc = $rPromo ? round((1 - $r['price_promo'] / $r['price']) * 100) : 0;
            ?>
            <a href="/mercado/produto-view.php?id=<?= $r['product_id'] ?>" class="product-card">
                <div class="card-image">
                    <?php if ($rPromo): ?>
                    <span class="card-badge">-<?= $rDesc ?>%</span>
                    <?php endif; ?>
                    <img src="<?= $r['image'] ?: 'https://via.placeholder.com/150' ?>" alt="<?= htmlspecialchars($r['name']) ?>" loading="lazy">
                </div>
                <div class="card-body">
                    <?php if ($r['brand']): ?>
                    <span class="card-brand"><?= htmlspecialchars($r['brand']) ?></span>
                    <?php endif; ?>
                    <p class="card-name"><?= htmlspecialchars($r['name']) ?></p>
                    <div class="card-price">
                        <span class="card-price-current">R$ <?= number_format($rp, 2, ',', '.') ?></span>
                        <?php if ($rPromo): ?>
                        <span class="card-price-old">R$ <?= number_format($r['price'], 2, ',', '.') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</main>

<!-- Toast -->
<div class="toast" id="toast">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
        <path d="M20 6L9 17l-5-5"/>
    </svg>
    <span id="toastMsg">Adicionado ao carrinho</span>
</div>

<!-- Modal -->
<div class="modal" id="modal" onclick="closeModal()">
    <button class="modal-close">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
    </button>
    <img src="<?= $product['image'] ?: 'https://via.placeholder.com/600' ?>" alt="">
</div>

<!-- Mobile Nav -->
<nav class="mobile-nav">
    <div class="mobile-nav-inner">
        <a href="/mercado/" class="nav-item">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            </svg>
            Início
        </a>
        <a href="/mercado/categorias.php" class="nav-item">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
            </svg>
            Categorias
        </a>
        <a href="/mercado/checkout.php" class="nav-item">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            <?php if ($cartCount > 0): ?>
            <span class="badge" id="mobileCartBadge"><?= $cartCount ?></span>
            <?php endif; ?>
            Carrinho
        </a>
    </div>
</nav>

<script>
const productId = <?= $product_id ?>;
const aiCached = <?= $ai_cached ? json_encode($ai_cached, JSON_UNESCAPED_UNICODE) : 'null' ?>;
let qty = <?= $cart_qty ?: 1 ?>;
let inCart = <?= $in_cart ? 'true' : 'false' ?>;
let isFavorite = false;

// Quantity
function changeQty(delta) {
    qty = Math.max(1, qty + delta);
    document.getElementById('qtyValue').textContent = qty;
}

// Add to Cart
function addToCart() {
    const btn = document.getElementById('btnAdd');
    const btnText = document.getElementById('btnAddText');
    const originalText = btnText.textContent;

    btn.disabled = true;
    btnText.textContent = 'Adicionando...';

    fetch(location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=add_to_cart&qty=${qty}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            inCart = true;
            btnText.textContent = 'No carrinho';
            btn.classList.add('in-cart');

            // Atualizar contadores do carrinho
            const cc = document.getElementById('cartCount');
            if (cc) {
                cc.textContent = data.count;
                cc.style.display = 'inline-flex';
            } else {
                // Criar badge se nao existir
                const cartBtn = document.querySelector('.btn-cart-main');
                if (cartBtn && data.count > 0) {
                    const badge = document.createElement('span');
                    badge.className = 'cart-count';
                    badge.id = 'cartCount';
                    badge.style.cssText = 'background:#108910 !important;color:#fff !important;';
                    badge.textContent = data.count;
                    cartBtn.appendChild(badge);
                }
            }

            const mc = document.getElementById('mobileCartBadge');
            if (mc) {
                mc.textContent = data.count;
                mc.style.display = 'flex';
            }

            showToast('Produto adicionado ao carrinho!');

            // Animacao de sucesso
            btn.style.transform = 'scale(1.05)';
            setTimeout(() => btn.style.transform = '', 200);
        } else {
            btnText.textContent = originalText;
            showToast(data.error || 'Erro ao adicionar');
        }
        btn.disabled = false;
    })
    .catch((e) => {
        console.error('Erro:', e);
        btnText.textContent = originalText;
        btn.disabled = false;
        showToast('Erro de conexao');
    });
}

function buyNow() {
    addToCart();
    setTimeout(() => location.href = '/mercado/checkout.php', 500);
}

// Toast
function showToast(msg) {
    const t = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    t.classList.add('show', 'success');
    setTimeout(() => t.classList.remove('show', 'success'), 3000);
}

// Favorite
function toggleFavorite() {
    isFavorite = !isFavorite;
    ['favBtn', 'galleryFavBtn'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) {
            btn.classList.toggle('active', isFavorite);
            btn.querySelector('svg').setAttribute('fill', isFavorite ? 'currentColor' : 'none');
        }
    });
    showToast(isFavorite ? 'Salvo nos favoritos!' : 'Removido dos favoritos');
}

// Share
function shareProduct() {
    if (navigator.share) {
        navigator.share({title: document.title, url: location.href});
    } else {
        navigator.clipboard.writeText(location.href);
        showToast('Link copiado!');
    }
}

// Modal
function openModal() {
    document.getElementById('modal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('modal').classList.remove('show');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});

// Related scroll
function scrollRelated(dir) {
    const track = document.getElementById('relatedTrack');
    track.scrollBy({left: dir * 240, behavior: 'smooth'});
}

// Search
function clearSearch() {
    document.getElementById('searchInput').value = '';
}

// Thumbnails
document.querySelectorAll('.thumb').forEach(thumb => {
    thumb.addEventListener('click', () => {
        document.querySelectorAll('.thumb').forEach(t => t.classList.remove('active'));
        thumb.classList.add('active');
        document.getElementById('mainImage').src = thumb.dataset.src;
    });
});

// Handle broken images
document.querySelectorAll('img').forEach(img => {
    img.addEventListener('error', function() {
        this.classList.add('error');
        if (!this.dataset.fallback) {
            this.dataset.fallback = '1';
            this.src = 'https://via.placeholder.com/300x300?text=Imagem+indisponivel';
        }
    });
});

// Lazy load related products images
if ('IntersectionObserver' in window) {
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                }
                observer.unobserve(img);
            }
        });
    });

    document.querySelectorAll('.product-card img[loading="lazy"]').forEach(img => {
        imageObserver.observe(img);
    });
}

// Handle search input
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && this.value.trim()) {
            window.location.href = '/mercado/?q=' + encodeURIComponent(this.value.trim());
        }
    });
}

// Load details - usa cache se disponível, senão chama API
if (aiCached && (aiCached.beneficios || aiCached.dicas_uso || aiCached.harmonizacao || aiCached.receita_rapida)) {
    // Dados já em cache no banco - usa direto sem chamar API
    renderDetails(aiCached);
} else {
    // Sem cache - chama API e salva no banco
    fetch(`/mercado/api/produto-inteligente.php?id=${productId}&save_cache=1`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.ai_content) {
                renderDetails(data.ai_content);
            }
        })
        .catch(() => {
            document.getElementById('detailsGrid').innerHTML = '';
        });
}

function renderDetails(ai) {
    let html = '';

    if (ai.beneficios?.length) {
        html += `
        <div class="detail-card">
            <div class="detail-card-header">
                <span class="detail-card-icon">✅</span>
                <h3 class="detail-card-title">Benefícios</h3>
            </div>
            <ul class="detail-list">
                ${ai.beneficios.map(b => `<li><span class="bullet">✓</span>${b}</li>`).join('')}
            </ul>
        </div>`;
    }

    if (ai.dicas_uso?.length) {
        html += `
        <div class="detail-card">
            <div class="detail-card-header">
                <span class="detail-card-icon">💡</span>
                <h3 class="detail-card-title">Dicas de uso</h3>
            </div>
            <ul class="detail-list">
                ${ai.dicas_uso.map(d => `<li><span class="bullet">•</span>${d}</li>`).join('')}
            </ul>
        </div>`;
    }

    if (ai.harmonizacao?.length) {
        html += `
        <div class="detail-card">
            <div class="detail-card-header">
                <span class="detail-card-icon">🍽️</span>
                <h3 class="detail-card-title">Combina com</h3>
            </div>
            <ul class="detail-list">
                ${ai.harmonizacao.map(h => `<li><span class="bullet">•</span>${h}</li>`).join('')}
            </ul>
        </div>`;
    }

    if (ai.receita_rapida?.nome) {
        html += `
        <div class="detail-card recipe">
            <div class="detail-card-header">
                <span class="detail-card-icon">👨‍🍳</span>
                <h3 class="detail-card-title">Receita: ${ai.receita_rapida.nome}</h3>
            </div>
            <div class="recipe-content">
                ${ai.receita_rapida.ingredientes ? `
                <div class="recipe-ingredients">
                    <h5>Ingredientes:</h5>
                    <ul>${ai.receita_rapida.ingredientes.map(i => `<li>${i}</li>`).join('')}</ul>
                </div>` : ''}
                ${ai.receita_rapida.preparo ? `<p>${ai.receita_rapida.preparo}</p>` : ''}
            </div>
        </div>`;
    }

    if (ai.conservacao) {
        html += `
        <div class="detail-card">
            <div class="detail-card-header">
                <span class="detail-card-icon">❄️</span>
                <h3 class="detail-card-title">Conservação</h3>
            </div>
            <p style="font-size:14px;color:var(--text);line-height:1.7">${ai.conservacao}</p>
        </div>`;
    }

    if (ai.tags?.length) {
        html += `
        <div class="tags-section">
            <h3 class="section-title">Tags</h3>
            <div class="tags-grid">
                ${ai.tags.map(t => `<span class="tag">${t}</span>`).join('')}
            </div>
        </div>`;
    }

    document.getElementById('detailsGrid').innerHTML = html;
}
</script>

</body>
</html>
