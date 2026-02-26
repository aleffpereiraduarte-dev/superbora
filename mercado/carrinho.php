<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SUPERBORA - CARRINHO ULTRA PREMIUM v2.0
 * ══════════════════════════════════════════════════════════════════════════════
 * Design: DoorDash + Instacart + Rappi + iFood + Uber Eats
 * Features: Slots de entrega, gorjeta, cupons, recomendacoes AI, cashback
 */

session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) session_start();

// Conexão com banco
require_once __DIR__ . '/includes/env_loader.php';
$pdo = null;
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    $config_file = dirname(__DIR__) . '/config.php';
    if (file_exists($config_file)) {
        require_once $config_file;
        $pdo = new PDO(
            "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
            DB_USERNAME, DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
}

$customer_id = $_SESSION['customer_id'] ?? 0;
$customer_name = $_SESSION['customer_firstname'] ?? 'Visitante';
$cart = $_SESSION['market_cart'] ?? [];
$partner_id = $_SESSION['market_partner_id'] ?? 100;

// Buscar info do mercado
$market = ['name' => 'SuperBora Mercado', 'logo' => '🛒', 'rating' => 4.8];
if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partner_id]);
        $m = $stmt->fetch();
        if ($m) $market = array_merge($market, $m);
    } catch (Exception $e) {}
}

// Calcular totais
$items = [];
$subtotal = 0;
$total_qty = 0;
$total_savings = 0;

foreach ($cart as $key => $item) {
    $id = (int)($item['id'] ?? $item['product_id'] ?? 0);
    $qty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
    $price = (float)($item['price'] ?? 0);
    $price_promo = (float)($item['price_promo'] ?? 0);

    $final_price = ($price_promo > 0 && $price_promo < $price) ? $price_promo : $price;
    $line_total = $final_price * $qty;
    $line_savings = ($price_promo > 0 && $price_promo < $price) ? ($price - $price_promo) * $qty : 0;

    $items[] = [
        'key' => $key,
        'id' => $id,
        'name' => $item['name'] ?? 'Produto',
        'brand' => $item['brand'] ?? '',
        'price' => $price,
        'price_promo' => $price_promo,
        'final_price' => $final_price,
        'image' => $item['image'] ?? '',
        'qty' => $qty,
        'line_total' => $line_total,
        'savings' => $line_savings,
        'unit' => $item['unit'] ?? 'un'
    ];

    $subtotal += $line_total;
    $total_qty += $qty;
    $total_savings += $line_savings;
}

// Configurações de entrega
$free_delivery_min = 99;
$delivery_fee = $subtotal >= $free_delivery_min ? 0 : 9.99;
$service_fee = 2.49;
$tip = $_SESSION['delivery_tip'] ?? 0;
$to_free_delivery = max(0, $free_delivery_min - $subtotal);
$progress = $free_delivery_min > 0 ? min(100, ($subtotal / $free_delivery_min) * 100) : 100;

// Cupom
$coupon = $_SESSION['coupon'] ?? null;
$coupon_discount = 0;
if ($coupon) {
    $coupon_discount = $coupon['type'] === 'percent'
        ? ($subtotal * $coupon['value'] / 100)
        : $coupon['value'];
}

// Cashback (5% para membros)
$cashback_percent = $customer_id ? 5 : 0;
$cashback_amount = $subtotal * $cashback_percent / 100;

// Total
$total = $subtotal + $delivery_fee + $service_fee + $tip - $coupon_discount;

// Endereço do cliente
$address = null;
$addresses = [];
if ($pdo && $customer_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM oc_address WHERE customer_id = ? ORDER BY address_id DESC");
        $stmt->execute([$customer_id]);
        $addresses = $stmt->fetchAll();
        $address = $addresses[0] ?? null;
    } catch (Exception $e) {}
}

// Slots de entrega
$delivery_slots = [];
$now = new DateTime();
$now->modify('+30 minutes');
$slot_times = [
    ['label' => 'Agora', 'sub' => '30-45 min', 'price' => 6.99, 'express' => true, 'icon' => '⚡'],
    ['label' => '1 hora', 'sub' => '45-60 min', 'price' => 3.99, 'express' => false, 'icon' => '🚀'],
    ['label' => '2 horas', 'sub' => '90-120 min', 'price' => 0, 'express' => false, 'icon' => '📦'],
    ['label' => 'Agendar', 'sub' => 'Escolher horário', 'price' => 0, 'express' => false, 'icon' => '📅'],
];
foreach ($slot_times as $i => $s) {
    $delivery_slots[] = array_merge($s, ['id' => $i]);
}
$selected_slot = $_SESSION['delivery_slot'] ?? 2;

// Recomendações
$recommendations = [];
if ($pdo && count($items) > 0) {
    try {
        $product_ids = array_column($items, 'id');
        if (!empty($product_ids)) {
            $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
            $stmt = $pdo->prepare("
                SELECT pb.product_id, pb.name, pb.brand, pb.image, pb.unit,
                       COALESCE(ps.sale_price, pp.price) as price,
                       pp.price_promo
                FROM om_market_products_base pb
                JOIN om_market_products_price pp ON pb.product_id = pp.product_id
                LEFT JOIN om_market_products_sale ps ON pb.product_id = ps.product_id AND pp.partner_id = ps.partner_id
                WHERE pp.partner_id = ? AND pp.status = '1' AND pp.stock > 0
                AND pb.product_id NOT IN ({$placeholders})
                ORDER BY RANDOM()
                LIMIT 6
            ");
            $params = array_merge([$partner_id], $product_ids);
            $stmt->execute($params);
            $recommendations = $stmt->fetchAll();
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#059669">
    <title>Carrinho - SuperBora</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #059669;
            --primary-dark: #047857;
            --primary-light: #10b981;
            --accent: #f59e0b;
            --accent-light: #fbbf24;
            --black: #0f172a;
            --gray-900: #1e293b;
            --gray-800: #334155;
            --gray-700: #475569;
            --gray-600: #64748b;
            --gray-500: #94a3b8;
            --gray-400: #cbd5e1;
            --gray-300: #e2e8f0;
            --gray-200: #f1f5f9;
            --gray-100: #f8fafc;
            --white: #ffffff;
            --success: #22c55e;
            --success-bg: #dcfce7;
            --error: #ef4444;
            --error-bg: #fee2e2;
            --warning: #f59e0b;
            --warning-bg: #fef3c7;
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-100);
            color: var(--gray-900);
            -webkit-font-smoothing: antialiased;
            line-height: 1.5;
            min-height: 100vh;
        }

        /* ═══════════════════════════════════════════════════════════════════════
           HEADER PREMIUM
        ═══════════════════════════════════════════════════════════════════════ */
        .header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
        }

        .header-inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .btn-back {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gray-100);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-back:hover { background: var(--gray-200); transform: scale(1.05); }
        .btn-back svg { width: 22px; height: 22px; color: var(--gray-700); }

        .header-title {
            flex: 1;
            font-size: 22px;
            font-weight: 800;
            color: var(--black);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-title .cart-icon {
            width: 40px;
            height: 40px;
            background: var(--gradient);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .header-count {
            background: var(--primary);
            color: var(--white);
            font-size: 13px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 20px;
        }

        /* ═══════════════════════════════════════════════════════════════════════
           LAYOUT
        ═══════════════════════════════════════════════════════════════════════ */
        .main-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 24px;
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 24px;
            padding-bottom: 140px;
        }

        @media (max-width: 1024px) {
            .main-container {
                grid-template-columns: 1fr;
                padding: 16px;
                padding-bottom: 200px;
            }
            .sidebar { order: -1; }
        }

        .card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--black);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title .icon {
            width: 36px;
            height: 36px;
            background: var(--gray-100);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        /* ═══════════════════════════════════════════════════════════════════════
           EMPTY STATE
        ═══════════════════════════════════════════════════════════════════════ */
        .empty-state {
            text-align: center;
            padding: 80px 40px;
            grid-column: 1 / -1;
        }

        .empty-icon {
            width: 140px;
            height: 140px;
            margin: 0 auto 32px;
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-200) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 64px;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .empty-state h2 {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 12px;
            color: var(--black);
        }

        .empty-state p {
            color: var(--gray-600);
            font-size: 16px;
            margin-bottom: 32px;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 16px 32px;
            background: var(--gradient);
            color: var(--white);
            font-size: 16px;
            font-weight: 700;
            border: none;
            border-radius: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 4px 14px rgba(5, 150, 105, 0.4);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(5, 150, 105, 0.5);
        }

        /* ═══════════════════════════════════════════════════════════════════════
           STORE BANNER
        ═══════════════════════════════════════════════════════════════════════ */
        .store-banner {
            background: var(--gradient);
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            color: var(--white);
        }

        .store-logo {
            width: 60px;
            height: 60px;
            background: var(--white);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            box-shadow: var(--shadow-lg);
        }

        .store-info h2 {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .store-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 14px;
            opacity: 0.95;
        }

        .store-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ═══════════════════════════════════════════════════════════════════════
           FREE DELIVERY PROGRESS
        ═══════════════════════════════════════════════════════════════════════ */
        .delivery-progress {
            padding: 20px 24px;
            background: <?= $delivery_fee === 0 ? 'var(--success-bg)' : 'var(--warning-bg)' ?>;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .progress-icon {
            width: 52px;
            height: 52px;
            background: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            box-shadow: var(--shadow);
            flex-shrink: 0;
        }

        .progress-content { flex: 1; }

        .progress-text {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 10px;
        }

        .progress-text strong {
            color: <?= $delivery_fee === 0 ? 'var(--success)' : 'var(--accent)' ?>;
        }

        .progress-bar {
            height: 10px;
            background: var(--white);
            border-radius: 5px;
            overflow: hidden;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
        }

        .progress-fill {
            height: 100%;
            background: <?= $delivery_fee === 0 ? 'var(--success)' : 'linear-gradient(90deg, var(--accent), var(--accent-light))' ?>;
            border-radius: 5px;
            width: <?= $progress ?>%;
            transition: width 0.5s ease;
            position: relative;
        }

        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* ═══════════════════════════════════════════════════════════════════════
           CART ITEMS
        ═══════════════════════════════════════════════════════════════════════ */
        .items-list {
            padding: 0;
        }

        .cart-item {
            display: flex;
            gap: 16px;
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-100);
            transition: all 0.2s;
            position: relative;
        }

        .cart-item:hover { background: var(--gray-50); }
        .cart-item:last-child { border-bottom: none; }

        .cart-item.removing {
            animation: slideOut 0.3s ease forwards;
        }

        @keyframes slideOut {
            to {
                transform: translateX(-100%);
                opacity: 0;
                height: 0;
                padding: 0;
            }
        }

        .item-image {
            position: relative;
            width: 90px;
            height: 90px;
            flex-shrink: 0;
        }

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 14px;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
        }

        .item-badge {
            position: absolute;
            top: -8px;
            left: -8px;
            background: var(--error);
            color: var(--white);
            font-size: 11px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        .item-details {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .item-brand {
            font-size: 12px;
            color: var(--primary);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .item-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-900);
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .item-unit {
            font-size: 13px;
            color: var(--gray-500);
        }

        .item-prices {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: auto;
        }

        .item-price-current {
            font-size: 17px;
            font-weight: 800;
            color: var(--black);
        }

        .item-price-old {
            font-size: 13px;
            color: var(--gray-400);
            text-decoration: line-through;
        }

        .item-savings {
            font-size: 11px;
            font-weight: 700;
            color: var(--success);
            background: var(--success-bg);
            padding: 3px 8px;
            border-radius: 6px;
        }

        .item-controls {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 12px;
        }

        .item-total {
            font-size: 17px;
            font-weight: 800;
            color: var(--black);
        }

        .qty-controls {
            display: flex;
            align-items: center;
            gap: 0;
            background: var(--gray-100);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .qty-btn {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--gray-700);
            transition: all 0.2s;
        }

        .qty-btn:hover { background: var(--gray-200); color: var(--black); }
        .qty-btn.delete:hover { background: var(--error-bg); color: var(--error); }
        .qty-btn svg { width: 20px; height: 20px; }

        .qty-value {
            width: 44px;
            text-align: center;
            font-size: 16px;
            font-weight: 700;
            color: var(--gray-900);
        }

        /* ═══════════════════════════════════════════════════════════════════════
           ADD MORE BUTTON
        ═══════════════════════════════════════════════════════════════════════ */
        .add-more {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 18px 24px;
            color: var(--primary);
            font-weight: 700;
            font-size: 15px;
            text-decoration: none;
            border-top: 2px dashed var(--gray-200);
            transition: all 0.2s;
        }
        .add-more:hover {
            background: var(--gray-50);
            color: var(--primary-dark);
        }
        .add-more svg { width: 22px; height: 22px; }

        /* ═══════════════════════════════════════════════════════════════════════
           DELIVERY SLOTS
        ═══════════════════════════════════════════════════════════════════════ */
        .slots-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            padding: 20px 24px;
        }

        @media (max-width: 600px) {
            .slots-grid { grid-template-columns: repeat(2, 1fr); }
        }

        .slot-card {
            padding: 16px;
            background: var(--gray-50);
            border: 2px solid var(--gray-200);
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            position: relative;
        }

        .slot-card:hover {
            border-color: var(--gray-300);
            transform: translateY(-2px);
        }

        .slot-card.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(5, 150, 105, 0.05), rgba(16, 185, 129, 0.1));
        }

        .slot-card.express {
            border-color: var(--accent);
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05), rgba(251, 191, 36, 0.1));
        }

        .slot-card.express.selected {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2);
        }

        .slot-icon {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .slot-label {
            font-size: 15px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .slot-sub {
            font-size: 12px;
            color: var(--gray-500);
            margin-bottom: 8px;
        }

        .slot-price {
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-700);
        }

        .slot-price.free {
            color: var(--success);
        }

        .slot-check {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 24px;
            height: 24px;
            background: var(--primary);
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            color: var(--white);
            box-shadow: var(--shadow);
        }

        .slot-card.selected .slot-check { display: flex; }

        /* ═══════════════════════════════════════════════════════════════════════
           TIP SECTION
        ═══════════════════════════════════════════════════════════════════════ */
        .tip-section {
            padding: 24px;
            border-bottom: 1px solid var(--gray-200);
        }

        .tip-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .tip-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }

        .tip-text h4 {
            font-size: 16px;
            font-weight: 700;
            color: var(--black);
            margin-bottom: 4px;
        }

        .tip-text p {
            font-size: 13px;
            color: var(--gray-600);
        }

        .tip-options {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }

        .tip-btn {
            padding: 14px 8px;
            background: var(--gray-100);
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-700);
            cursor: pointer;
            transition: all 0.2s;
        }

        .tip-btn:hover {
            border-color: var(--gray-300);
            background: var(--gray-50);
        }

        .tip-btn.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(5, 150, 105, 0.05), rgba(16, 185, 129, 0.1));
            color: var(--primary);
        }

        /* ═══════════════════════════════════════════════════════════════════════
           COUPON SECTION
        ═══════════════════════════════════════════════════════════════════════ */
        .coupon-section {
            padding: 20px 24px;
        }

        .coupon-input-wrap {
            display: flex;
            gap: 10px;
        }

        .coupon-input {
            flex: 1;
            padding: 14px 18px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 15px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .coupon-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }

        .coupon-btn {
            padding: 14px 24px;
            background: var(--gray-900);
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }

        .coupon-btn:hover { background: var(--black); }

        .coupon-applied {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: var(--success-bg);
            border: 2px solid var(--success);
            border-radius: 12px;
        }

        .coupon-applied-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            color: var(--success);
        }

        .coupon-remove {
            width: 32px;
            height: 32px;
            background: var(--white);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            transition: all 0.2s;
        }

        .coupon-remove:hover {
            background: var(--error-bg);
            color: var(--error);
        }

        /* ═══════════════════════════════════════════════════════════════════════
           SIDEBAR
        ═══════════════════════════════════════════════════════════════════════ */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* Address Card */
        .address-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px 24px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .address-card:hover { background: var(--gray-50); }

        .address-icon {
            width: 52px;
            height: 52px;
            background: var(--gradient);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            flex-shrink: 0;
        }

        .address-icon svg { width: 26px; height: 26px; }

        .address-info { flex: 1; }

        .address-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 4px;
        }

        .address-text {
            font-size: 15px;
            font-weight: 700;
            color: var(--black);
            display: block;
        }

        .address-detail {
            font-size: 13px;
            color: var(--gray-600);
            display: block;
        }

        .address-arrow {
            color: var(--gray-400);
        }

        /* Summary Card */
        .summary-card {
            padding: 24px;
        }

        .summary-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--black);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
        }

        .summary-row .label {
            font-size: 15px;
            color: var(--gray-600);
        }

        .summary-row .value {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-900);
        }

        .summary-row .value.free {
            color: var(--success);
            font-weight: 700;
        }

        .summary-row .value.discount {
            color: var(--success);
        }

        .summary-row.savings {
            background: var(--success-bg);
            margin: 12px -24px;
            padding: 14px 24px;
        }

        .summary-row.savings .label,
        .summary-row.savings .value {
            color: var(--success);
            font-weight: 700;
        }

        .summary-row.cashback {
            background: linear-gradient(135deg, rgba(5, 150, 105, 0.05), rgba(16, 185, 129, 0.1));
            margin: 12px -24px;
            padding: 14px 24px;
            border: 1px dashed var(--primary);
            border-left: none;
            border-right: none;
        }

        .summary-row.cashback .label {
            color: var(--primary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .summary-row.cashback .value {
            color: var(--primary);
            font-weight: 700;
        }

        .summary-divider {
            height: 1px;
            background: var(--gray-200);
            margin: 16px 0;
        }

        .summary-row.total .label {
            font-size: 18px;
            font-weight: 700;
            color: var(--black);
        }

        .summary-row.total .value {
            font-size: 24px;
            font-weight: 800;
            color: var(--black);
        }

        /* Checkout Button */
        .btn-checkout {
            width: 100%;
            padding: 18px 24px;
            background: var(--gradient);
            color: var(--white);
            font-size: 17px;
            font-weight: 700;
            border: none;
            border-radius: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
            transition: all 0.3s;
            box-shadow: 0 4px 14px rgba(5, 150, 105, 0.4);
        }

        .btn-checkout:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(5, 150, 105, 0.5);
        }

        .btn-checkout:disabled {
            background: var(--gray-300);
            box-shadow: none;
            cursor: not-allowed;
        }

        .btn-checkout svg { width: 22px; height: 22px; }

        .security-badge {
            text-align: center;
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        /* ═══════════════════════════════════════════════════════════════════════
           RECOMMENDATIONS
        ═══════════════════════════════════════════════════════════════════════ */
        .recs-section {
            grid-column: 1 / -1;
        }

        .recs-scroll {
            display: flex;
            gap: 16px;
            padding: 20px 24px;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            -webkit-overflow-scrolling: touch;
        }

        .recs-scroll::-webkit-scrollbar { display: none; }

        .rec-card {
            min-width: 160px;
            flex-shrink: 0;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 16px;
            padding: 16px;
            scroll-snap-align: start;
            transition: all 0.2s;
        }

        .rec-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .rec-image {
            width: 100%;
            height: 100px;
            object-fit: contain;
            margin-bottom: 12px;
        }

        .rec-brand {
            font-size: 11px;
            color: var(--primary);
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .rec-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.3;
            height: 34px;
        }

        .rec-price {
            font-size: 16px;
            font-weight: 800;
            color: var(--black);
            margin-bottom: 12px;
        }

        .rec-btn {
            width: 100%;
            padding: 10px;
            background: var(--gradient);
            color: var(--white);
            font-size: 13px;
            font-weight: 700;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .rec-btn:hover {
            transform: scale(1.02);
        }

        .ai-badge {
            background: linear-gradient(135deg, #8b5cf6, #a78bfa);
            color: var(--white);
            font-size: 10px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ═══════════════════════════════════════════════════════════════════════
           MOBILE BOTTOM BAR
        ═══════════════════════════════════════════════════════════════════════ */
        @media (max-width: 1024px) {
            .mobile-bottom {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: var(--white);
                padding: 16px 20px;
                padding-bottom: calc(16px + env(safe-area-inset-bottom));
                box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
                z-index: 100;
                display: flex;
                align-items: center;
                gap: 16px;
            }

            .mobile-total {
                flex: 1;
            }

            .mobile-total-label {
                font-size: 13px;
                color: var(--gray-600);
            }

            .mobile-total-value {
                font-size: 22px;
                font-weight: 800;
                color: var(--black);
            }

            .mobile-btn {
                padding: 16px 32px;
                background: var(--gradient);
                color: var(--white);
                font-size: 16px;
                font-weight: 700;
                border: none;
                border-radius: 12px;
                cursor: pointer;
                box-shadow: 0 4px 14px rgba(5, 150, 105, 0.4);
            }
        }

        @media (min-width: 1025px) {
            .mobile-bottom { display: none; }
        }

        /* ═══════════════════════════════════════════════════════════════════════
           TOAST NOTIFICATIONS
        ═══════════════════════════════════════════════════════════════════════ */
        .toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--gray-900);
            color: var(--white);
            padding: 14px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 1000;
            box-shadow: var(--shadow-xl);
        }

        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }

        .toast.success { background: var(--success); }
        .toast.error { background: var(--error); }
    </style>
</head>
<body>

<!-- Header -->
<header class="header">
    <div class="header-inner">
        <button class="btn-back" onclick="history.back()">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </button>
        <h1 class="header-title">
            <span class="cart-icon">🛒</span>
            Meu Carrinho
        </h1>
        <?php if ($total_qty > 0): ?>
        <span class="header-count"><?= $total_qty ?> <?= $total_qty === 1 ? 'item' : 'itens' ?></span>
        <?php endif; ?>
    </div>
</header>

<?php if (empty($items)): ?>
<!-- Empty State -->
<div class="main-container">
    <div class="empty-state">
        <div class="empty-icon">🛒</div>
        <h2>Seu carrinho está vazio</h2>
        <p>Adicione produtos para começar suas compras</p>
        <a href="/mercado/" class="btn-primary">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            Começar a comprar
        </a>
    </div>
</div>

<?php else: ?>

<div class="main-container">
    <!-- Main Content -->
    <div class="main-content">

        <!-- Cart Items -->
        <div class="card">
            <!-- Store Banner -->
            <div class="store-banner">
                <div class="store-logo"><?= $market['logo'] ?? '🛒' ?></div>
                <div class="store-info">
                    <h2><?= htmlspecialchars($market['name'] ?? 'SuperBora') ?></h2>
                    <div class="store-meta">
                        <span>⭐ <?= number_format($market['rating'] ?? 4.8, 1) ?></span>
                        <span>📍 2.3 km</span>
                        <span>🕐 25-35 min</span>
                    </div>
                </div>
            </div>

            <!-- Free Delivery Progress -->
            <div class="delivery-progress">
                <div class="progress-icon"><?= $delivery_fee === 0 ? '🎉' : '🚚' ?></div>
                <div class="progress-content">
                    <?php if ($delivery_fee === 0): ?>
                    <div class="progress-text">
                        <strong>Parabéns!</strong> Você ganhou frete grátis!
                    </div>
                    <?php else: ?>
                    <div class="progress-text">
                        Faltam <strong>R$ <?= number_format($to_free_delivery, 2, ',', '.') ?></strong> para frete grátis
                    </div>
                    <?php endif; ?>
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                </div>
            </div>

            <!-- Items -->
            <div class="items-list">
                <?php foreach ($items as $item): ?>
                <div class="cart-item" data-key="<?= $item['key'] ?>" data-id="<?= $item['id'] ?>">
                    <div class="item-image">
                        <?php if ($item['savings'] > 0): ?>
                        <span class="item-badge">-<?= round(($item['price'] - $item['final_price']) / $item['price'] * 100) ?>%</span>
                        <?php endif; ?>
                        <img src="<?= htmlspecialchars($item['image'] ?: '/mercado/assets/img/placeholder.png') ?>"
                             alt="<?= htmlspecialchars($item['name']) ?>"
                             onerror="this.src='/mercado/assets/img/placeholder.png'">
                    </div>
                    <div class="item-details">
                        <?php if ($item['brand']): ?>
                        <span class="item-brand"><?= htmlspecialchars($item['brand']) ?></span>
                        <?php endif; ?>
                        <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                        <span class="item-unit"><?= htmlspecialchars($item['unit']) ?></span>
                        <div class="item-prices">
                            <span class="item-price-current">R$ <?= number_format($item['final_price'], 2, ',', '.') ?></span>
                            <?php if ($item['savings'] > 0): ?>
                            <span class="item-price-old">R$ <?= number_format($item['price'], 2, ',', '.') ?></span>
                            <span class="item-savings">-R$ <?= number_format($item['savings'], 2, ',', '.') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="item-controls">
                        <span class="item-total">R$ <?= number_format($item['line_total'], 2, ',', '.') ?></span>
                        <div class="qty-controls">
                            <button class="qty-btn <?= $item['qty'] === 1 ? 'delete' : '' ?>"
                                    onclick="updateQty('<?= $item['key'] ?>', <?= $item['id'] ?>, <?= $item['qty'] - 1 ?>)">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <?php if ($item['qty'] === 1): ?>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    <?php else: ?>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4"/>
                                    <?php endif; ?>
                                </svg>
                            </button>
                            <span class="qty-value"><?= $item['qty'] ?></span>
                            <button class="qty-btn" onclick="updateQty('<?= $item['key'] ?>', <?= $item['id'] ?>, <?= $item['qty'] + 1 ?>)">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Add More -->
            <a href="/mercado/" class="add-more">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Adicionar mais itens
            </a>
        </div>

        <!-- Delivery Slots -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <span class="icon">🕐</span>
                    Quando entregar?
                </h3>
            </div>
            <div class="slots-grid">
                <?php foreach ($delivery_slots as $slot): ?>
                <div class="slot-card <?= $slot['express'] ? 'express' : '' ?> <?= $slot['id'] === $selected_slot ? 'selected' : '' ?>"
                     onclick="selectSlot(<?= $slot['id'] ?>, <?= $slot['price'] ?>)">
                    <div class="slot-check">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="slot-icon"><?= $slot['icon'] ?></div>
                    <div class="slot-label"><?= $slot['label'] ?></div>
                    <div class="slot-sub"><?= $slot['sub'] ?></div>
                    <div class="slot-price <?= $slot['price'] === 0 ? 'free' : '' ?>">
                        <?= $slot['price'] === 0 ? 'Grátis' : '+R$ ' . number_format($slot['price'], 2, ',', '.') ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Tip & Coupon -->
        <div class="card">
            <div class="tip-section">
                <div class="tip-header">
                    <div class="tip-icon">💝</div>
                    <div class="tip-text">
                        <h4>Gorjeta para o entregador</h4>
                        <p>100% vai direto para quem entrega seu pedido</p>
                    </div>
                </div>
                <div class="tip-options">
                    <button class="tip-btn <?= $tip == 0 ? 'selected' : '' ?>" onclick="setTip(0)">Sem gorjeta</button>
                    <button class="tip-btn <?= $tip == 3 ? 'selected' : '' ?>" onclick="setTip(3)">R$ 3</button>
                    <button class="tip-btn <?= $tip == 5 ? 'selected' : '' ?>" onclick="setTip(5)">R$ 5</button>
                    <button class="tip-btn <?= $tip == 10 ? 'selected' : '' ?>" onclick="setTip(10)">R$ 10</button>
                </div>
            </div>

            <div class="coupon-section">
                <?php if ($coupon): ?>
                <div class="coupon-applied">
                    <div class="coupon-applied-info">
                        <span>🎟️</span>
                        <span><?= htmlspecialchars($coupon['code']) ?> aplicado (-R$ <?= number_format($coupon_discount, 2, ',', '.') ?>)</span>
                    </div>
                    <button class="coupon-remove" onclick="removeCoupon()">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <?php else: ?>
                <div class="coupon-input-wrap">
                    <input type="text" class="coupon-input" id="couponInput" placeholder="Tem um cupom? Digite aqui">
                    <button class="coupon-btn" onclick="applyCoupon()">Aplicar</button>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Sidebar -->
    <div class="sidebar">

        <!-- Address Card -->
        <div class="card">
            <div class="address-card" onclick="openAddressModal()">
                <div class="address-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                <div class="address-info">
                    <span class="address-label">Entregar em</span>
                    <?php if ($address): ?>
                    <span class="address-text"><?= htmlspecialchars($address['address_1']) ?></span>
                    <span class="address-detail"><?= htmlspecialchars(($address['address_2'] ? $address['address_2'] . ', ' : '') . $address['city']) ?></span>
                    <?php else: ?>
                    <span class="address-text">Selecione um endereço</span>
                    <span class="address-detail">Clique para adicionar</span>
                    <?php endif; ?>
                </div>
                <span class="address-arrow">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </span>
            </div>
        </div>

        <!-- Summary Card -->
        <div class="card summary-card">
            <h3 class="summary-title">📋 Resumo do pedido</h3>

            <div class="summary-row">
                <span class="label">Subtotal (<?= $total_qty ?> <?= $total_qty === 1 ? 'item' : 'itens' ?>)</span>
                <span class="value">R$ <?= number_format($subtotal, 2, ',', '.') ?></span>
            </div>

            <div class="summary-row">
                <span class="label">Taxa de entrega</span>
                <span class="value <?= $delivery_fee === 0 ? 'free' : '' ?>">
                    <?= $delivery_fee === 0 ? 'Grátis' : 'R$ ' . number_format($delivery_fee, 2, ',', '.') ?>
                </span>
            </div>

            <div class="summary-row">
                <span class="label">Taxa de serviço</span>
                <span class="value">R$ <?= number_format($service_fee, 2, ',', '.') ?></span>
            </div>

            <?php if ($tip > 0): ?>
            <div class="summary-row">
                <span class="label">Gorjeta</span>
                <span class="value">R$ <?= number_format($tip, 2, ',', '.') ?></span>
            </div>
            <?php endif; ?>

            <?php if ($coupon_discount > 0): ?>
            <div class="summary-row">
                <span class="label">Desconto do cupom</span>
                <span class="value discount">-R$ <?= number_format($coupon_discount, 2, ',', '.') ?></span>
            </div>
            <?php endif; ?>

            <?php if ($total_savings > 0): ?>
            <div class="summary-row savings">
                <span class="label">🎉 Você está economizando</span>
                <span class="value">R$ <?= number_format($total_savings, 2, ',', '.') ?></span>
            </div>
            <?php endif; ?>

            <?php if ($cashback_amount > 0): ?>
            <div class="summary-row cashback">
                <span class="label">💰 Cashback (<?= $cashback_percent ?>%)</span>
                <span class="value">+R$ <?= number_format($cashback_amount, 2, ',', '.') ?></span>
            </div>
            <?php endif; ?>

            <div class="summary-divider"></div>

            <div class="summary-row total">
                <span class="label">Total</span>
                <span class="value">R$ <?= number_format($total, 2, ',', '.') ?></span>
            </div>

            <button class="btn-checkout" onclick="goCheckout()" <?= !$customer_id ? 'disabled' : '' ?>>
                <?php if ($customer_id): ?>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                Ir para pagamento
                <?php else: ?>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                </svg>
                Faça login para continuar
                <?php endif; ?>
            </button>

            <div class="security-badge">
                🔒 Pagamento 100% seguro
            </div>
        </div>

    </div>

</div>

<?php if (!empty($recommendations)): ?>
<!-- Recommendations -->
<div class="main-container" style="padding-top: 0;">
    <div class="card recs-section">
        <div class="card-header">
            <h3 class="card-title">
                <span class="icon">✨</span>
                Que tal adicionar?
                <span class="ai-badge">AI</span>
            </h3>
        </div>
        <div class="recs-scroll">
            <?php foreach ($recommendations as $rec): ?>
            <div class="rec-card">
                <img class="rec-image"
                     src="<?= htmlspecialchars($rec['image'] ?: '/mercado/assets/img/placeholder.png') ?>"
                     alt="<?= htmlspecialchars($rec['name']) ?>"
                     onerror="this.src='/mercado/assets/img/placeholder.png'">
                <?php if (!empty($rec['brand'])): ?>
                <div class="rec-brand"><?= htmlspecialchars($rec['brand']) ?></div>
                <?php endif; ?>
                <div class="rec-name"><?= htmlspecialchars($rec['name']) ?></div>
                <div class="rec-price">R$ <?= number_format($rec['price_promo'] ?: $rec['price'], 2, ',', '.') ?></div>
                <button class="rec-btn" onclick="addToCart(<?= $rec['product_id'] ?>, '<?= htmlspecialchars(addslashes($rec['name'])) ?>', <?= $rec['price_promo'] ?: $rec['price'] ?>, '<?= htmlspecialchars($rec['image']) ?>')">
                    Adicionar
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Mobile Bottom Bar -->
<div class="mobile-bottom">
    <div class="mobile-total">
        <div class="mobile-total-label">Total</div>
        <div class="mobile-total-value">R$ <?= number_format($total, 2, ',', '.') ?></div>
    </div>
    <button class="mobile-btn" onclick="goCheckout()" <?= !$customer_id ? 'disabled' : '' ?>>
        <?= $customer_id ? 'Finalizar' : 'Fazer login' ?>
    </button>
</div>

<?php endif; ?>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
function showToast(msg, type = '') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.classList.remove('show'), 3000);
}

function updateQty(key, id, qty) {
    if (qty < 1) {
        // Remover item
        const item = document.querySelector(`.cart-item[data-key="${key}"]`);
        if (item) item.classList.add('removing');
    }

    fetch('/mercado/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: qty < 1 ? 'remove' : 'update', key: key, product_id: id, quantity: qty })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            setTimeout(() => location.reload(), qty < 1 ? 300 : 0);
        }
    });
}

function selectSlot(id, price) {
    document.querySelectorAll('.slot-card').forEach(c => c.classList.remove('selected'));
    document.querySelector(`.slot-card:nth-child(${id + 1})`).classList.add('selected');

    fetch('/mercado/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'set_slot', slot_id: id, slot_price: price })
    });
    showToast('Horário selecionado!', 'success');
}

function setTip(amount) {
    document.querySelectorAll('.tip-btn').forEach(b => b.classList.remove('selected'));
    event.target.classList.add('selected');

    fetch('/mercado/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'set_tip', tip: amount })
    })
    .then(() => location.reload());
}

function applyCoupon() {
    const code = document.getElementById('couponInput').value.trim();
    if (!code) return showToast('Digite um cupom', 'error');

    fetch('/mercado/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'apply_coupon', code: code })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast('Cupom aplicado!', 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(d.error || 'Cupom inválido', 'error');
        }
    });
}

function removeCoupon() {
    fetch('/mercado/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove_coupon' })
    })
    .then(() => location.reload());
}

function addToCart(id, name, price, image) {
    fetch('/mercado/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add', product_id: id, name: name, price: price, image: image, quantity: 1 })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast('Adicionado ao carrinho!', 'success');
            setTimeout(() => location.reload(), 500);
        }
    });
}

function openAddressModal() {
    <?php if (!$customer_id): ?>
    window.location.href = '/mercado/mercado-login.php?redirect=carrinho';
    <?php else: ?>
    // TODO: Implementar modal de endereços
    showToast('Seleção de endereço em breve!');
    <?php endif; ?>
}

function goCheckout() {
    <?php if (!$customer_id): ?>
    window.location.href = '/mercado/mercado-login.php?redirect=carrinho';
    <?php else: ?>
    window.location.href = '/mercado/checkout.php';
    <?php endif; ?>
}
</script>

</body>
</html>
