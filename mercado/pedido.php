<?php
require_once __DIR__ . '/config/database.php';
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO MERCADO - ACOMPANHAMENTO DE PEDIDO
 * ══════════════════════════════════════════════════════════════════════════════
 * Design Premium estilo Instacart
 * - Mapa sempre visível
 * - Status em tempo real
 * - Shopper/Driver tracking
 * - Chat integrado
 * - Progresso de escaneamento
 */

session_name('OCSESSID');
session_start();

$customer_id = $_SESSION['customer_id'] ?? 0;
if (!$customer_id) {
    header('Location: /index.php?route=account/login');
    exit;
}

try {
    $pdo = getPDO();
} catch (Exception $e) {
    die('Erro de conexão');
}

// Buscar pedido
$order_id = $_GET['id'] ?? $_SESSION['last_order_id'] ?? 0;

if (!$order_id) {
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE customer_id = ? ORDER BY order_id DESC LIMIT 1");
    $stmt->execute([$customer_id]);
    $order = $stmt->fetch();
} else {
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
    $stmt->execute([$order_id, $customer_id]);
    $order = $stmt->fetch();
}

if (!$order) {
    header('Location: /mercado/');
    exit;
}

$order_id = $order['order_id'];
$order_number = $order['order_number'] ?? '#' . str_pad($order_id, 6, '0', STR_PAD_LEFT);

// Buscar itens do pedido
$stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ?");
$stmt->execute([$order_id]);
$items = $stmt->fetchAll();
$total_items = count($items);

// Buscar parceiro/mercado
$partner = null;
if ($order['partner_id']) {
    $stmt = $pdo->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$order['partner_id']]);
    $partner = $stmt->fetch();
}

// Buscar shopper (se atribuído)
$shopper = null;
if ($order['shopper_id']) {
    $stmt = $pdo->prepare("SELECT * FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$order['shopper_id']]);
    $shopper = $stmt->fetch();
}

// Buscar driver (se atribuído)
$driver = null;
if ($order['driver_id'] ?? $order['delivery_driver_id'] ?? null) {
    $driver_id = $order['driver_id'] ?? $order['delivery_driver_id'];
    $stmt = $pdo->prepare("SELECT * FROM om_market_drivers WHERE driver_id = ?");
    $stmt->execute([$driver_id]);
    $driver = $stmt->fetch();
}

// Calcular valores
$subtotal = (float)$order['subtotal'];
$delivery_fee = (float)$order['delivery_fee'];
$service_fee = (float)$order['service_fee'];
$discount = (float)$order['discount'];
$total = (float)$order['total'];

// Progresso do escaneamento
$scan_progress = (int)($order['scan_progress'] ?? $order['progress_pct'] ?? $order['collection_percent'] ?? 0);
$items_found = (int)($order['items_found'] ?? $order['items_collected'] ?? 0);
$items_total = (int)($order['items_total'] ?? $order['total_items'] ?? $total_items);

// Pode adicionar mais itens? (< 30% escaneado)
$can_add_items = in_array($order['status'], ['confirmed', 'shopping']) && $scan_progress < 30;

// Status do pedido
$status_config = [
    'pending' => ['label' => 'Aguardando', 'icon' => '⏳', 'color' => '#F5A623', 'bg' => '#FFF8E6', 'step' => 0],
    'confirmed' => ['label' => 'Confirmado', 'icon' => '✅', 'color' => '#0AAD0A', 'bg' => '#E5F8E5', 'step' => 1],
    'shopping' => ['label' => 'Comprando', 'icon' => '🛒', 'color' => '#3B82F6', 'bg' => '#EFF6FF', 'step' => 2],
    'purchased' => ['label' => 'Compra Finalizada', 'icon' => '✨', 'color' => '#8B5CF6', 'bg' => '#F3E8FF', 'step' => 3],
    'delivering' => ['label' => 'A Caminho', 'icon' => '🚗', 'color' => '#FF5A00', 'bg' => '#FFF4ED', 'step' => 4],
    'delivered' => ['label' => 'Entregue', 'icon' => '🎉', 'color' => '#22C55E', 'bg' => '#DCFCE7', 'step' => 5],
    'cancelled' => ['label' => 'Cancelado', 'icon' => '❌', 'color' => '#EF4444', 'bg' => '#FEE2E2', 'step' => -1]
];

$current_status = $status_config[$order['status']] ?? $status_config['pending'];
$current_step = $current_status['step'];

// Código de entrega
$delivery_code = $order['delivery_code'] ?? $order['verification_code'] ?? $order['codigo_entrega'] ?? strtoupper(substr(md5($order_id), 0, 6));

// Coordenadas do endereço
$address_lat = $order['shipping_lat'] ?? $order['customer_lat'] ?? $order['latitude_entrega'] ?? -18.8516;
$address_lng = $order['shipping_lng'] ?? $order['customer_lng'] ?? $order['longitude_entrega'] ?? -41.9499;

// Coordenadas do driver/shopper
$worker_lat = $order['delivery_lat'] ?? $order['driver_lat'] ?? null;
$worker_lng = $order['delivery_lng'] ?? $order['driver_lng'] ?? null;

// Tempo estimado
$eta_minutes = $order['eta_minutes'] ?? $order['tempo_estimado_min'] ?? null;

// Endereço formatado
$address_line1 = $order['shipping_address'] ?? $order['customer_address'] ?? '';
$address_line2 = trim(($order['shipping_neighborhood'] ?? '') . ', ' . ($order['shipping_city'] ?? ''));
$address_number = $order['shipping_number'] ?? '';
$address_complement = $order['shipping_complement'] ?? '';

// Mostrar mapa em quais status
$show_live_tracking = in_array($order['status'], ['delivering', 'shopping']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pedido <?= htmlspecialchars($order_number) ?> - OneMundo Mercado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #003D29;
            --primary-light: #108910;
            --orange: #FF5A00;
            --black: #1A1A1A;
            --gray-900: #343538;
            --gray-700: #52555A;
            --gray-600: #6D7175;
            --gray-500: #8B8D8F;
            --gray-400: #A6A7A8;
            --gray-300: #C7C8C9;
            --gray-200: #E8E9EB;
            --gray-100: #F6F7F8;
            --gray-50: #FAFBFC;
            --white: #FFFFFF;
            --success: #22C55E;
            --error: #EF4444;
            --warning: #F5A623;
            --blue: #3B82F6;
            --purple: #8B5CF6;
        }
        
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--gray-100);
            color: var(--gray-900);
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }
        
        /* ═══════════════════════════════════════════════════════════════════════
           HEADER
        ═══════════════════════════════════════════════════════════════════════ */
        .header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
        }
        
        .header-inner {
            max-width: 600px;
            margin: 0 auto;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .btn-back {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gray-100);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            text-decoration: none;
            color: var(--gray-700);
            transition: all 0.2s;
        }
        .btn-back:hover { background: var(--gray-200); transform: scale(1.05); }
        
        .header-info { flex: 1; }
        .header-title { font-size: 16px; font-weight: 700; color: var(--black); }
        .header-subtitle { font-size: 12px; color: var(--gray-500); }
        
        .header-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: <?= $current_status['bg'] ?>;
            color: <?= $current_status['color'] ?>;
        }
        
        /* ═══════════════════════════════════════════════════════════════════════
           MAP SECTION
        ═══════════════════════════════════════════════════════════════════════ */
        .map-section {
            position: relative;
            height: 280px;
            background: linear-gradient(135deg, var(--primary) 0%, #005a3c 100%);
        }
        
        #map {
            height: 100%;
            width: 100%;
        }
        
        .map-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.7) 0%, transparent 100%);
            padding: 60px 16px 16px;
            color: var(--white);
        }
        
        .map-address {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .map-address-icon {
            width: 40px;
            height: 40px;
            background: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .map-address-text h3 { font-size: 15px; font-weight: 600; margin-bottom: 2px; }
        .map-address-text p { font-size: 13px; opacity: 0.8; }
        
        .eta-badge {
            position: absolute;
            top: 16px;
            right: 16px;
            background: var(--white);
            padding: 10px 16px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            text-align: center;
        }
        
        .eta-badge-label { font-size: 11px; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.5px; }
        .eta-badge-time { font-size: 22px; font-weight: 800; color: var(--gray-900); }
        .eta-badge-unit { font-size: 12px; color: var(--gray-600); }
        
        /* ═══════════════════════════════════════════════════════════════════════
           CONTAINER
        ═══════════════════════════════════════════════════════════════════════ */
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 16px;
            padding-bottom: 100px;
        }
        
        /* ═══════════════════════════════════════════════════════════════════════
           STATUS TRACKER
        ═══════════════════════════════════════════════════════════════════════ */
        .status-tracker {
            background: var(--white);
            border-radius: 20px;
            padding: 24px;
            margin-top: -40px;
            position: relative;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 16px;
        }
        
        .status-current {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .status-icon {
            width: 64px;
            height: 64px;
            background: <?= $current_status['bg'] ?>;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            position: relative;
        }
        
        .status-icon::after {
            content: '';
            position: absolute;
            inset: -4px;
            border: 3px solid <?= $current_status['color'] ?>;
            border-radius: 50%;
            opacity: 0.3;
            animation: pulse-ring 2s infinite;
        }
        
        @keyframes pulse-ring {
            0% { transform: scale(1); opacity: 0.3; }
            50% { transform: scale(1.1); opacity: 0.1; }
            100% { transform: scale(1); opacity: 0.3; }
        }
        
        .status-info h2 { font-size: 20px; font-weight: 800; color: var(--gray-900); margin-bottom: 4px; }
        .status-info p { font-size: 14px; color: var(--gray-600); }
        
        /* Progress Steps */
        .progress-steps {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            padding: 0 8px;
        }
        
        .progress-line {
            position: absolute;
            top: 16px;
            left: 24px;
            right: 24px;
            height: 3px;
            background: var(--gray-200);
            border-radius: 2px;
        }
        
        .progress-line-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success) 0%, <?= $current_status['color'] ?> 100%);
            border-radius: 2px;
            transition: width 0.5s ease;
            width: <?= min(100, ($current_step / 5) * 100) ?>%;
        }
        
        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 1;
        }
        
        .step-dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .step-dot.completed {
            background: var(--success);
            color: var(--white);
        }
        
        .step-dot.current {
            background: <?= $current_status['color'] ?>;
            color: var(--white);
            animation: pulse-dot 2s infinite;
        }
        
        @keyframes pulse-dot {
            0%, 100% { box-shadow: 0 0 0 0 <?= $current_status['color'] ?>60; }
            50% { box-shadow: 0 0 0 8px <?= $current_status['color'] ?>00; }
        }
        
        .step-label {
            font-size: 10px;
            color: var(--gray-500);
            text-align: center;
            max-width: 60px;
        }
        
        .step-label.active { color: var(--gray-900); font-weight: 600; }
        
        /* ═══════════════════════════════════════════════════════════════════════
           WORKER CARD (Shopper/Driver)
        ═══════════════════════════════════════════════════════════════════════ */
        .worker-card {
            background: var(--white);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .worker-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
        }
        
        .worker-avatar {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: var(--white);
            position: relative;
        }
        
        .worker-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .worker-online {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 14px;
            height: 14px;
            background: var(--success);
            border: 2px solid var(--white);
            border-radius: 50%;
        }
        
        .worker-info { flex: 1; }
        .worker-name { font-size: 16px; font-weight: 700; color: var(--gray-900); }
        .worker-role { font-size: 13px; color: var(--gray-500); }
        
        .worker-rating {
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 4px;
        }
        .worker-rating span { font-size: 13px; color: var(--warning); }
        .worker-rating small { font-size: 12px; color: var(--gray-500); }
        
        .worker-actions {
            display: flex;
            gap: 10px;
        }
        
        .worker-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .worker-btn.call {
            background: var(--success);
            color: var(--white);
        }
        
        .worker-btn.chat {
            background: var(--blue);
            color: var(--white);
        }
        
        .worker-btn:hover { transform: scale(1.1); }
        
        /* Scan Progress */
        .scan-progress {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 16px;
        }
        
        .scan-progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .scan-progress-label { font-size: 13px; color: var(--gray-600); }
        .scan-progress-value { font-size: 15px; font-weight: 700; color: var(--gray-900); }
        
        .scan-progress-bar {
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .scan-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success) 0%, var(--primary-light) 100%);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .scan-items {
            display: flex;
            justify-content: space-around;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--gray-200);
        }
        
        .scan-item { text-align: center; }
        .scan-item-value { font-size: 18px; font-weight: 700; color: var(--gray-900); }
        .scan-item-label { font-size: 11px; color: var(--gray-500); }
        
        /* ═══════════════════════════════════════════════════════════════════════
           DELIVERY CODE
        ═══════════════════════════════════════════════════════════════════════ */
        .delivery-code-card {
            background: linear-gradient(135deg, var(--gray-900) 0%, var(--black) 100%);
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            color: var(--white);
            margin-bottom: 16px;
        }
        
        .delivery-code-label { font-size: 12px; opacity: 0.7; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; }
        .delivery-code-value { font-size: 40px; font-weight: 900; letter-spacing: 10px; font-family: 'SF Mono', monospace; }
        .delivery-code-hint { font-size: 12px; opacity: 0.6; margin-top: 12px; }
        
        /* ═══════════════════════════════════════════════════════════════════════
           ORDER ITEMS
        ═══════════════════════════════════════════════════════════════════════ */
        .card {
            background: var(--white);
            border-radius: 20px;
            margin-bottom: 16px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .card-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .card-title-icon {
            width: 36px;
            height: 36px;
            background: var(--gray-100);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .card-badge {
            font-size: 12px;
            color: var(--gray-500);
            background: var(--gray-100);
            padding: 4px 10px;
            border-radius: 12px;
        }
        
        .card-body { padding: 16px 20px; }
        
        .order-items {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .order-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: var(--gray-50);
            border-radius: 12px;
        }
        
        .order-item-img {
            width: 50px;
            height: 50px;
            background: var(--white);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        .order-item-img img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        .order-item-qty {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 20px;
            height: 20px;
            background: var(--primary);
            color: var(--white);
            font-size: 11px;
            font-weight: 700;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .order-item-info { flex: 1; min-width: 0; }
        .order-item-name { font-size: 14px; font-weight: 600; color: var(--gray-900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .order-item-unit { font-size: 12px; color: var(--gray-500); }
        
        .order-item-price { font-size: 14px; font-weight: 700; color: var(--gray-900); white-space: nowrap; }
        
        /* Order Summary */
        .order-summary {
            padding: 16px 20px;
            border-top: 1px solid var(--gray-100);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .summary-row .label { color: var(--gray-600); }
        .summary-row .value { font-weight: 500; color: var(--gray-900); }
        .summary-row .value.free { color: var(--success); }
        .summary-row .value.discount { color: var(--error); }
        
        .summary-row.total {
            padding-top: 12px;
            margin-top: 12px;
            border-top: 1px solid var(--gray-200);
            font-size: 16px;
        }
        
        .summary-row.total .label { font-weight: 700; color: var(--gray-900); }
        .summary-row.total .value { font-weight: 800; color: var(--primary); }
        
        /* ═══════════════════════════════════════════════════════════════════════
           ADD MORE BANNER
        ═══════════════════════════════════════════════════════════════════════ */
        .add-more-banner {
            background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
            border: 2px solid var(--warning);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .add-more-icon {
            width: 44px;
            height: 44px;
            background: var(--warning);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .add-more-text h4 { font-size: 14px; font-weight: 700; color: var(--gray-900); }
        .add-more-text p { font-size: 12px; color: var(--gray-700); }
        
        .add-more-btn {
            margin-left: auto;
            padding: 10px 16px;
            background: var(--warning);
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        
        /* ═══════════════════════════════════════════════════════════════════════
           ACTION BUTTONS
        ═══════════════════════════════════════════════════════════════════════ */
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 20px;
        }
        
        .btn-primary {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 16px 24px;
            background: var(--primary);
            color: var(--white);
            border: none;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-primary:hover { background: #002a1c; transform: translateY(-2px); }
        
        .btn-secondary {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 16px 24px;
            background: var(--white);
            color: var(--gray-700);
            border: 2px solid var(--gray-200);
            border-radius: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover { border-color: var(--gray-400); background: var(--gray-50); }
        
        /* ═══════════════════════════════════════════════════════════════════════
           CHAT FAB
        ═══════════════════════════════════════════════════════════════════════ */
        .chat-fab {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 60px;
            height: 60px;
            background: var(--blue);
            color: var(--white);
            border: none;
            border-radius: 50%;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
            transition: all 0.3s;
        }
        
        .chat-fab:hover { transform: scale(1.1); }
        
        .chat-fab-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 22px;
            height: 22px;
            background: var(--error);
            color: var(--white);
            font-size: 11px;
            font-weight: 700;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* ═══════════════════════════════════════════════════════════════════════
           TOAST
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
            font-weight: 500;
            opacity: 0;
            transition: all 0.3s;
            z-index: 1000;
        }
        
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: var(--success); }
        .toast.error { background: var(--error); }
        
        /* ═══════════════════════════════════════════════════════════════════════
           RESPONSIVE
        ═══════════════════════════════════════════════════════════════════════ */
        @media (max-width: 480px) {
            .step-label { font-size: 9px; max-width: 50px; }
            .status-info h2 { font-size: 18px; }
            .delivery-code-value { font-size: 32px; letter-spacing: 6px; }
        }
        
        /* Leaflet custom */
        .leaflet-control-attribution { display: none; }
    </style>
</head>
<body>

<!-- Header -->
<header class="header">
    <div class="header-inner">
        <a href="/mercado/" class="btn-back">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>
        <div class="header-info">
            <div class="header-title">Pedido <?= htmlspecialchars($order_number) ?></div>
            <div class="header-subtitle"><?= $partner['partner_name'] ?? $partner['name'] ?? 'OneMundo Mercado' ?></div>
        </div>
        <span class="header-badge"><?= $current_status['icon'] ?> <?= $current_status['label'] ?></span>
    </div>
</header>

<!-- Map Section -->
<section class="map-section">
    <div id="map"></div>
    
    <?php if ($eta_minutes): ?>
    <div class="eta-badge">
        <div class="eta-badge-label">Chegada em</div>
        <div class="eta-badge-time"><?= $eta_minutes ?></div>
        <div class="eta-badge-unit">minutos</div>
    </div>
    <?php endif; ?>
    
    <div class="map-overlay">
        <div class="map-address">
            <div class="map-address-icon">📍</div>
            <div class="map-address-text">
                <h3><?= htmlspecialchars($address_line1) ?><?= $address_number ? ", $address_number" : '' ?></h3>
                <p><?= htmlspecialchars($address_complement) ?><?= $address_complement && $address_line2 ? ' • ' : '' ?><?= htmlspecialchars($address_line2) ?></p>
            </div>
        </div>
    </div>
</section>

<div class="container">
    <!-- Status Tracker -->
    <div class="status-tracker">
        <div class="status-current">
            <div class="status-icon"><?= $current_status['icon'] ?></div>
            <div class="status-info">
                <h2><?= $current_status['label'] ?></h2>
                <p>
                    <?php
                    $messages = [
                        'pending' => 'Aguardando confirmação do pagamento',
                        'confirmed' => 'Seu pedido foi confirmado! Buscando shopper...',
                        'shopping' => $shopper ? ($shopper['name'] ?? 'Shopper') . ' está fazendo suas compras' : 'Um shopper está fazendo suas compras',
                        'purchased' => 'Compras finalizadas! Aguardando entregador...',
                        'delivering' => $driver ? ($driver['name'] ?? 'Entregador') . ' está a caminho' : 'Seu pedido está a caminho',
                        'delivered' => 'Pedido entregue com sucesso!',
                        'cancelled' => 'Este pedido foi cancelado'
                    ];
                    echo $messages[$order['status']] ?? 'Processando seu pedido...';
                    ?>
                </p>
            </div>
        </div>
        
        <!-- Progress Steps -->
        <div class="progress-steps">
            <div class="progress-line"><div class="progress-line-fill"></div></div>
            
            <div class="progress-step">
                <div class="step-dot <?= $current_step >= 1 ? 'completed' : '' ?> <?= $current_step == 1 ? 'current' : '' ?>">
                    <?= $current_step >= 1 ? '✓' : '1' ?>
                </div>
                <span class="step-label <?= $current_step >= 1 ? 'active' : '' ?>">Confirmado</span>
            </div>
            
            <div class="progress-step">
                <div class="step-dot <?= $current_step >= 2 ? 'completed' : '' ?> <?= $current_step == 2 ? 'current' : '' ?>">
                    <?= $current_step >= 2 ? '✓' : '2' ?>
                </div>
                <span class="step-label <?= $current_step >= 2 ? 'active' : '' ?>">Comprando</span>
            </div>
            
            <div class="progress-step">
                <div class="step-dot <?= $current_step >= 3 ? 'completed' : '' ?> <?= $current_step == 3 ? 'current' : '' ?>">
                    <?= $current_step >= 3 ? '✓' : '3' ?>
                </div>
                <span class="step-label <?= $current_step >= 3 ? 'active' : '' ?>">Pronto</span>
            </div>
            
            <div class="progress-step">
                <div class="step-dot <?= $current_step >= 4 ? 'completed' : '' ?> <?= $current_step == 4 ? 'current' : '' ?>">
                    <?= $current_step >= 4 ? '✓' : '4' ?>
                </div>
                <span class="step-label <?= $current_step >= 4 ? 'active' : '' ?>">A Caminho</span>
            </div>
            
            <div class="progress-step">
                <div class="step-dot <?= $current_step >= 5 ? 'completed' : '' ?> <?= $current_step == 5 ? 'current' : '' ?>">
                    <?= $current_step >= 5 ? '✓' : '5' ?>
                </div>
                <span class="step-label <?= $current_step >= 5 ? 'active' : '' ?>">Entregue</span>
            </div>
        </div>
    </div>
    
    <?php if ($shopper && in_array($order['status'], ['shopping', 'purchased'])): ?>
    <!-- Shopper Card -->
    <div class="worker-card">
        <div class="worker-header">
            <div class="worker-avatar">
                <?php if (!empty($shopper['photo']) || !empty($shopper['foto'])): ?>
                <img src="<?= htmlspecialchars($shopper['photo'] ?? $shopper['foto']) ?>" alt="">
                <?php else: ?>
                👨‍🛒
                <?php endif; ?>
                <div class="worker-online"></div>
            </div>
            <div class="worker-info">
                <div class="worker-name"><?= htmlspecialchars($shopper['name'] ?? $shopper['nome'] ?? 'Shopper') ?></div>
                <div class="worker-role">Shopper</div>
                <div class="worker-rating">
                    <span>⭐ <?= number_format((float)($shopper['rating'] ?? $shopper['nota_media'] ?? 5), 1, ',', '') ?></span>
                    <small>• <?= (int)($shopper['total_orders'] ?? $shopper['total_pedidos'] ?? 0) ?> pedidos</small>
                </div>
            </div>
            <div class="worker-actions">
                <button class="worker-btn chat" onclick="openChat()">💬</button>
                <?php if ($shopper['phone'] ?? $shopper['telefone'] ?? null): ?>
                <a href="tel:<?= $shopper['phone'] ?? $shopper['telefone'] ?>" class="worker-btn call">📞</a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Scan Progress -->
        <div class="scan-progress">
            <div class="scan-progress-header">
                <span class="scan-progress-label">Progresso das compras</span>
                <span class="scan-progress-value"><?= $scan_progress ?>%</span>
            </div>
            <div class="scan-progress-bar">
                <div class="scan-progress-fill" style="width: <?= $scan_progress ?>%"></div>
            </div>
            <div class="scan-items">
                <div class="scan-item">
                    <div class="scan-item-value"><?= $items_found ?></div>
                    <div class="scan-item-label">Encontrados</div>
                </div>
                <div class="scan-item">
                    <div class="scan-item-value"><?= $items_total ?></div>
                    <div class="scan-item-label">Total</div>
                </div>
                <div class="scan-item">
                    <div class="scan-item-value"><?= $items_total - $items_found ?></div>
                    <div class="scan-item-label">Restantes</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($driver && in_array($order['status'], ['delivering'])): ?>
    <!-- Driver Card -->
    <div class="worker-card">
        <div class="worker-header">
            <div class="worker-avatar">
                <?php if (!empty($driver['photo']) || !empty($driver['foto'])): ?>
                <img src="<?= htmlspecialchars($driver['photo'] ?? $driver['foto']) ?>" alt="">
                <?php else: ?>
                🚗
                <?php endif; ?>
                <div class="worker-online"></div>
            </div>
            <div class="worker-info">
                <div class="worker-name"><?= htmlspecialchars($driver['name'] ?? $driver['nome'] ?? 'Entregador') ?></div>
                <div class="worker-role"><?= htmlspecialchars($driver['vehicle_type'] ?? $driver['vehicle'] ?? 'Moto') ?> • <?= htmlspecialchars($driver['vehicle_plate'] ?? $driver['plate'] ?? '') ?></div>
                <div class="worker-rating">
                    <span>⭐ <?= number_format((float)($driver['rating'] ?? 5), 1, ',', '') ?></span>
                </div>
            </div>
            <div class="worker-actions">
                <button class="worker-btn chat" onclick="openChat()">💬</button>
                <?php if ($driver['phone'] ?? $driver['telefone'] ?? null): ?>
                <a href="tel:<?= $driver['phone'] ?? $driver['telefone'] ?>" class="worker-btn call">📞</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (in_array($order['status'], ['delivering', 'purchased'])): ?>
    <!-- Delivery Code -->
    <div class="delivery-code-card">
        <div class="delivery-code-label">Código de Entrega</div>
        <div class="delivery-code-value"><?= $delivery_code ?></div>
        <div class="delivery-code-hint">Informe este código ao entregador para confirmar a entrega</div>
    </div>
    <?php endif; ?>
    
    <?php if ($can_add_items): ?>
    <!-- Add More Banner -->
    <div class="add-more-banner">
        <div class="add-more-icon">➕</div>
        <div class="add-more-text">
            <h4>Esqueceu algo?</h4>
            <p>Adicione mais itens antes do shopper começar</p>
        </div>
        <button class="add-more-btn" onclick="window.location.href='/mercado/?add_to_order=<?= $order_id ?>'">Adicionar</button>
    </div>
    <?php endif; ?>
    
    <!-- Order Items -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <div class="card-title-icon">📦</div>
                Itens do Pedido
            </div>
            <span class="card-badge"><?= $total_items ?> <?= $total_items == 1 ? 'item' : 'itens' ?></span>
        </div>
        <div class="card-body">
            <div class="order-items">
                <?php foreach ($items as $item): 
                    $item_name = $item['product_name'] ?? $item['name'] ?? 'Produto';
                    $item_image = $item['product_image'] ?? $item['image'] ?? '';
                    $item_qty = (int)($item['quantity'] ?? 1);
                    $item_unit = $item['unit'] ?? 'un';
                    $item_total = (float)($item['total_price'] ?? $item['total'] ?? 0);
                ?>
                <div class="order-item">
                    <div class="order-item-img">
                        <img src="<?= htmlspecialchars($item_image ?: '/mercado/assets/img/no-image.png') ?>" alt="" onerror="this.src='/mercado/assets/img/no-image.png'">
                        <?php if ($item_qty > 1): ?>
                        <span class="order-item-qty"><?= $item_qty ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="order-item-info">
                        <div class="order-item-name"><?= htmlspecialchars($item_name) ?></div>
                        <div class="order-item-unit"><?= $item_unit ?></div>
                    </div>
                    <div class="order-item-price">R$ <?= number_format($item_total, 2, ',', '.') ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="order-summary">
            <div class="summary-row">
                <span class="label">Subtotal</span>
                <span class="value">R$ <?= number_format($subtotal, 2, ',', '.') ?></span>
            </div>
            <div class="summary-row">
                <span class="label">Taxa de entrega</span>
                <span class="value <?= $delivery_fee == 0 ? 'free' : '' ?>"><?= $delivery_fee == 0 ? 'Grátis' : 'R$ ' . number_format($delivery_fee, 2, ',', '.') ?></span>
            </div>
            <div class="summary-row">
                <span class="label">Taxa de serviço</span>
                <span class="value">R$ <?= number_format($service_fee, 2, ',', '.') ?></span>
            </div>
            <?php if ($discount > 0): ?>
            <div class="summary-row">
                <span class="label">Desconto</span>
                <span class="value discount">-R$ <?= number_format($discount, 2, ',', '.') ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-row total">
                <span class="label">Total</span>
                <span class="value">R$ <?= number_format($total, 2, ',', '.') ?></span>
            </div>
        </div>
    </div>
    
    <!-- Action Buttons -->
    <div class="action-buttons">
        <a href="/mercado/" class="btn-primary">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            Voltar à Loja
        </a>
        
        <a href="/mercado/meus-pedidos.php" class="btn-secondary">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            Meus Pedidos
        </a>
    </div>
</div>

<!-- Chat FAB -->
<?php if ($shopper || $driver): ?>
<button class="chat-fab" onclick="openChat()">
    💬
</button>
<?php endif; ?>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
// ═══════════════════════════════════════════════════════════════════════════════
// MAP
// ═══════════════════════════════════════════════════════════════════════════════
const addressLat = <?= $address_lat ?>;
const addressLng = <?= $address_lng ?>;
const workerLat = <?= $worker_lat ?? 'null' ?>;
const workerLng = <?= $worker_lng ?? 'null' ?>;

const map = L.map('map', {
    zoomControl: false,
    attributionControl: false
}).setView([addressLat, addressLng], 15);

L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
    maxZoom: 19
}).addTo(map);

// Destination marker
const destIcon = L.divIcon({
    html: `<div style="
        background: #003D29;
        color: #fff;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        box-shadow: 0 4px 12px rgba(0,61,41,0.4);
        border: 3px solid #fff;
    ">🏠</div>`,
    iconSize: [36, 36],
    iconAnchor: [18, 36]
});

L.marker([addressLat, addressLng], { icon: destIcon }).addTo(map);

// Worker marker (if available)
let workerMarker = null;
if (workerLat && workerLng) {
    const workerIcon = L.divIcon({
        html: `<div style="
            background: #FF5A00;
            color: #fff;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: 0 4px 16px rgba(255,90,0,0.5);
            border: 3px solid #fff;
            animation: pulse 2s infinite;
        "><?= $order['status'] == 'delivering' ? '🚗' : '🛒' ?></div>`,
        iconSize: [44, 44],
        iconAnchor: [22, 44]
    });
    
    workerMarker = L.marker([workerLat, workerLng], { icon: workerIcon }).addTo(map);
    
    // Fit bounds to show both markers
    const bounds = L.latLngBounds([
        [addressLat, addressLng],
        [workerLat, workerLng]
    ]);
    map.fitBounds(bounds, { padding: [50, 50] });
}

// ═══════════════════════════════════════════════════════════════════════════════
// REAL-TIME UPDATES
// ═══════════════════════════════════════════════════════════════════════════════
const orderId = <?= $order_id ?>;
const currentStatus = '<?= $order['status'] ?>';

async function checkStatus() {
    try {
        const res = await fetch('/mercado/api/pedido.php?action=status&order_id=' + orderId);
        const data = await res.json();
        
        if (data.success && data.status !== currentStatus) {
            location.reload();
        }
    } catch(e) {
        console.error('Status check failed:', e);
    }
}

async function updateWorkerLocation() {
    try {
        const res = await fetch('/mercado/api/pedido.php?action=driver_location&order_id=' + orderId);
        const data = await res.json();
        
        if (data.success && data.lat && data.lng && workerMarker) {
            workerMarker.setLatLng([data.lat, data.lng]);
        }
    } catch(e) {
        console.error('Location update failed:', e);
    }
}

// Check status every 15 seconds
setInterval(checkStatus, 15000);

// Update location every 5 seconds if in delivering status
<?php if (in_array($order['status'], ['delivering', 'shopping'])): ?>
setInterval(updateWorkerLocation, 5000);
<?php endif; ?>

// ═══════════════════════════════════════════════════════════════════════════════
// CHAT
// ═══════════════════════════════════════════════════════════════════════════════
function openChat() {
    // TODO: Implementar modal de chat
    toast('Chat em breve!', 'info');
}

// ═══════════════════════════════════════════════════════════════════════════════
// TOAST
// ═══════════════════════════════════════════════════════════════════════════════
function toast(msg, type = '') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.classList.remove('show'), 3000);
}
</script>

</body>
</html>
