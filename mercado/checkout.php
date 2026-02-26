<?php
/**
 * ONEMUNDO MERCADO - CHECKOUT INSTACART STYLE
 * Design premium inspirado no Instacart
 */

session_name('OCSESSID');
session_start();

// Auth
$customer_id = $_SESSION['customer_id'] ?? 0;
if (!$customer_id) {
    header('Location: /index.php?route=account/login&redirect=' . urlencode('/mercado/checkout.php'));
    exit;
}

// Database
require_once __DIR__ . '/includes/env_loader.php';
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    die('Erro de conexao');
}

// Customer
$stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    header('Location: /index.php?route=account/login');
    exit;
}

// Addresses
$stmt = $pdo->prepare("
    SELECT a.*, z.name as zone_name, z.code as zone_code, c.name as country_name
    FROM oc_address a
    LEFT JOIN oc_zone z ON a.zone_id = z.zone_id
    LEFT JOIN oc_country c ON a.country_id = c.country_id
    WHERE a.customer_id = ?
    ORDER BY a.address_id = ? DESC, a.address_id DESC
");
$stmt->execute([$customer_id, $customer['address_id'] ?? 0]);
$addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Default address
$defaultAddress = $addresses[0] ?? null;

// Cart
$cart = $_SESSION['cart'] ?? $_SESSION['market_cart'] ?? [];
if (empty($cart)) {
    header('Location: /mercado/');
    exit;
}

$items = array_values($cart);
$subtotal = 0;
$totalQty = 0;
$sellerIds = [];

foreach ($items as &$item) {
    $price = ($item['price_promo'] ?? 0) > 0 && $item['price_promo'] < $item['price']
        ? $item['price_promo']
        : $item['price'];
    $item['final_price'] = $price;
    $item['line_total'] = $price * $item['qty'];
    $subtotal += $item['line_total'];
    $totalQty += $item['qty'];

    // Buscar seller_id do produto
    if (!isset($item['seller_id']) && isset($item['product_id'])) {
        $stmtSeller = $pdo->prepare("SELECT seller_id FROM oc_product WHERE product_id = ?");
        $stmtSeller->execute([$item['product_id']]);
        $item['seller_id'] = (int)$stmtSeller->fetchColumn() ?: 0;
    }
    if ($item['seller_id'] ?? 0) {
        $sellerIds[$item['seller_id']] = true;
    }
}
unset($item);

// Pegar o seller_id principal (primeiro vendedor)
$mainSellerId = !empty($sellerIds) ? array_key_first($sellerIds) : 0;

// Delivery fee (sera calculado dinamicamente pelo componente de frete)
$delivery_fee = 0; // Inicia zerado, sera atualizado pelo JS
$total = $subtotal + $delivery_fee;

// Format phone
$phone = preg_replace('/\D/', '', $customer['telephone'] ?? '');
$customerName = trim($customer['firstname'] . ' ' . $customer['lastname']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0AAD0A">
    <title>Checkout - OneMundo Mercado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --green: #0AAD0A;
            --green-dark: #078707;
            --green-light: #E8F5E8;
            --orange: #FF6B00;
            --black: #1A1A1A;
            --gray-900: #2D2D2D;
            --gray-700: #4A4A4A;
            --gray-500: #6B6B6B;
            --gray-400: #8A8A8A;
            --gray-300: #B3B3B3;
            --gray-200: #D9D9D9;
            --gray-100: #F0F0F0;
            --gray-50: #F8F8F8;
            --white: #FFFFFF;
            --red: #D32F2F;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow: 0 2px 8px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--black);
            line-height: 1.5;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* Header */
        .header {
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: var(--gray-100);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: var(--gray-200);
        }

        .header-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--black);
        }

        .header-secure {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--green);
            font-size: 13px;
            font-weight: 500;
        }

        /* Main Layout */
        .main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 24px;
        }

        @media (max-width: 900px) {
            .main {
                grid-template-columns: 1fr;
                padding: 16px;
                padding-bottom: 200px;
            }
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: var(--green-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--green);
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
        }

        .card-subtitle {
            font-size: 13px;
            color: var(--gray-500);
        }

        .card-body {
            padding: 24px;
        }

        /* Step Numbers */
        .step-number {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--green);
            color: var(--white);
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Address Cards */
        .address-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .address-item {
            position: relative;
            padding: 16px 20px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .address-item:hover {
            border-color: var(--gray-300);
        }

        .address-item.selected {
            border-color: var(--green);
            background: var(--green-light);
        }

        .address-item input {
            display: none;
        }

        .address-radio {
            width: 20px;
            height: 20px;
            border: 2px solid var(--gray-300);
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .address-item.selected .address-radio {
            border-color: var(--green);
            background: var(--green);
        }

        .address-radio::after {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--white);
            opacity: 0;
            transition: opacity 0.2s;
        }

        .address-item.selected .address-radio::after {
            opacity: 1;
        }

        .address-content {
            flex: 1;
        }

        .address-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--black);
            margin-bottom: 4px;
        }

        .address-text {
            font-size: 14px;
            color: var(--gray-500);
            line-height: 1.5;
        }

        .address-cep {
            font-size: 13px;
            color: var(--green);
            font-weight: 600;
            margin-top: 4px;
        }

        .add-address-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px;
            border: 2px dashed var(--gray-300);
            border-radius: 12px;
            color: var(--green);
            font-weight: 600;
            font-size: 14px;
            background: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .add-address-btn:hover {
            border-color: var(--green);
            background: var(--green-light);
        }

        /* Customer Info */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        @media (max-width: 600px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        .info-item {
            padding: 16px;
            background: var(--gray-50);
            border-radius: 12px;
        }

        .info-label {
            font-size: 12px;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 15px;
            font-weight: 600;
            color: var(--black);
        }

        /* CPF Input */
        .cpf-section {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--gray-100);
        }

        .input-group {
            position: relative;
        }

        .input-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
        }

        .input-field {
            width: 100%;
            padding: 14px 16px;
            font-size: 16px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            outline: none;
            transition: all 0.2s;
            font-family: inherit;
        }

        .input-field:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(10, 173, 10, 0.1);
        }

        .input-field::placeholder {
            color: var(--gray-400);
        }

        /* Payment Tabs */
        .payment-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .payment-tab {
            flex: 1;
            padding: 16px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            background: var(--white);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .payment-tab:hover {
            border-color: var(--gray-300);
        }

        .payment-tab.active {
            border-color: var(--green);
            background: var(--green-light);
        }

        .payment-tab-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .payment-tab-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--black);
        }

        .payment-tab-desc {
            font-size: 12px;
            color: var(--gray-500);
        }

        .payment-content {
            display: none;
        }

        .payment-content.active {
            display: block;
        }

        /* PIX Section */
        .pix-container {
            text-align: center;
            padding: 24px;
        }

        .pix-loading {
            padding: 40px;
        }

        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid var(--gray-200);
            border-top-color: var(--green);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .pix-ready {
            display: none;
        }

        .pix-qr {
            width: 200px;
            height: 200px;
            margin: 0 auto 20px;
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 12px;
        }

        .pix-qr img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .pix-timer {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--orange);
            color: var(--white);
            border-radius: 100px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .pix-code-box {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .pix-code-label {
            font-size: 12px;
            color: var(--gray-500);
            margin-bottom: 8px;
        }

        .pix-code {
            font-family: 'SF Mono', Monaco, monospace;
            font-size: 11px;
            color: var(--gray-700);
            word-break: break-all;
            line-height: 1.6;
        }

        .copy-btn {
            width: 100%;
            padding: 14px;
            background: var(--green);
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .copy-btn:hover {
            background: var(--green-dark);
        }

        .pix-success {
            display: none;
            padding: 40px;
            text-align: center;
        }

        .pix-success.show {
            display: block;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            background: var(--green);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: scaleIn 0.3s ease;
        }

        @keyframes scaleIn {
            from { transform: scale(0); }
            to { transform: scale(1); }
        }

        .pix-error {
            display: none;
            padding: 40px;
            text-align: center;
        }

        .error-icon {
            width: 64px;
            height: 64px;
            background: #FEE2E2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            color: var(--red);
        }

        /* Card Form */
        .card-form {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .card-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .installments-select {
            width: 100%;
            padding: 14px 16px;
            font-size: 16px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            outline: none;
            background: var(--white);
            cursor: pointer;
            font-family: inherit;
        }

        .installments-select:focus {
            border-color: var(--green);
        }

        /* Order Summary Sidebar */
        .sidebar {
            position: sticky;
            top: 100px;
        }

        .summary-items {
            max-height: 240px;
            overflow-y: auto;
            margin-bottom: 16px;
        }

        .summary-item {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-img {
            width: 56px;
            height: 56px;
            border-radius: 10px;
            object-fit: cover;
            background: var(--gray-100);
        }

        .summary-info {
            flex: 1;
        }

        .summary-name {
            font-size: 14px;
            font-weight: 500;
            color: var(--black);
            margin-bottom: 2px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .summary-qty {
            font-size: 13px;
            color: var(--gray-500);
        }

        .summary-price {
            font-size: 14px;
            font-weight: 600;
            color: var(--black);
            white-space: nowrap;
        }

        .summary-totals {
            padding-top: 16px;
            border-top: 1px solid var(--gray-200);
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }

        .summary-row.total {
            padding-top: 16px;
            margin-top: 8px;
            border-top: 2px solid var(--gray-200);
            font-size: 18px;
            font-weight: 700;
        }

        .free-badge {
            color: var(--green);
            font-weight: 600;
        }

        /* Pay Button */
        .pay-btn {
            width: 100%;
            padding: 18px;
            background: var(--green);
            color: var(--white);
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .pay-btn:hover:not(:disabled) {
            background: var(--green-dark);
            transform: translateY(-1px);
        }

        .pay-btn:disabled {
            background: var(--gray-300);
            cursor: not-allowed;
        }

        /* Mobile Footer */
        .mobile-footer {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            border-top: 1px solid var(--gray-200);
            padding: 16px 20px;
            padding-bottom: calc(16px + env(safe-area-inset-bottom));
            z-index: 100;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
        }

        @media (max-width: 900px) {
            .mobile-footer {
                display: block;
            }
            .sidebar .pay-btn {
                display: none;
            }
        }

        .mobile-footer-content {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .mobile-total {
            flex: 1;
        }

        .mobile-total-label {
            font-size: 13px;
            color: var(--gray-500);
        }

        .mobile-total-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--black);
        }

        .mobile-pay-btn {
            padding: 16px 32px;
            background: var(--green);
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }

        /* Delivery Type Selector */
        .delivery-type-selector {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .delivery-type-option {
            position: relative;
            padding: 18px 20px;
            border: 2px solid var(--gray-200);
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--white);
        }

        .delivery-type-option:hover {
            border-color: var(--gray-300);
        }

        .delivery-type-option.selected {
            border-color: var(--green);
            background: var(--green-light);
        }

        .delivery-type-option input { display: none; }

        .delivery-type-radio {
            width: 20px;
            height: 20px;
            border: 2px solid var(--gray-300);
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .delivery-type-option.selected .delivery-type-radio {
            border-color: var(--green);
            background: var(--green);
        }

        .delivery-type-radio::after {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--white);
            opacity: 0;
            transition: opacity 0.2s;
        }

        .delivery-type-option.selected .delivery-type-radio::after {
            opacity: 1;
        }

        .delivery-type-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--green-light);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .delivery-type-info { flex: 1; }

        .delivery-type-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--black);
        }

        .delivery-type-desc {
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .delivery-type-fee {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-top: 4px;
        }

        .delivery-type-badge-eco {
            position: absolute;
            top: -8px;
            right: 12px;
            background: #FF6B00;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 100px;
        }

        /* Toast */
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
            max-width: 90%;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .toast.success {
            background: var(--green);
        }

        .toast.error {
            background: var(--red);
        }

        /* Success Modal */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
            padding: 20px;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: var(--white);
            border-radius: 24px;
            padding: 40px;
            max-width: 400px;
            width: 100%;
            text-align: center;
            transform: scale(0.9);
            transition: transform 0.3s;
        }

        .modal.show .modal-content {
            transform: scale(1);
        }

        .modal-icon {
            width: 80px;
            height: 80px;
            background: var(--green);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .modal-text {
            color: var(--gray-500);
            margin-bottom: 24px;
        }

        .modal-btn {
            width: 100%;
            padding: 16px;
            background: var(--green);
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }

        /* ============================================================
           RESPONSIVE MOBILE FIXES - Checkout
           ============================================================ */

        /* Touch targets minimos */
        @media (max-width: 768px) {
            .back-btn {
                width: 44px;
                height: 44px;
            }

            .header-inner {
                padding: 12px 16px;
            }

            .header-title {
                font-size: 18px;
            }

            /* Cards de endereco e pagamento */
            .card {
                border-radius: 14px;
            }

            .card-header {
                padding: 14px 16px;
            }

            .card-body {
                padding: 16px;
            }

            /* Botoes */
            button, .btn {
                min-height: 44px;
                font-size: 15px;
            }

            /* Inputs */
            input, select, textarea {
                min-height: 48px;
                font-size: 16px !important;
                padding: 12px 14px;
            }

            /* Footer mobile */
            .mobile-footer {
                padding: 14px 16px;
                padding-bottom: calc(14px + env(safe-area-inset-bottom));
            }

            .mobile-pay-btn {
                min-height: 52px;
                padding: 14px 24px;
                font-size: 16px;
                border-radius: 14px;
            }

            .mobile-total-value {
                font-size: 18px;
            }
        }

        @media (max-width: 480px) {
            .main {
                padding: 12px;
                padding-bottom: 180px;
            }

            .header-inner {
                padding: 10px 12px;
                gap: 12px;
            }

            .header-title {
                font-size: 16px;
            }

            .header-secure {
                font-size: 11px;
            }

            .card-header {
                padding: 12px 14px;
            }

            .card-body {
                padding: 14px;
            }

            /* Informacoes grid */
            .info-grid {
                gap: 12px;
            }
        }

        @media (max-width: 375px) {
            .main {
                padding: 10px;
            }

            .header-inner {
                padding: 8px 10px;
            }

            .header-title {
                font-size: 15px;
            }

            .mobile-footer-content {
                gap: 10px;
            }

            .mobile-pay-btn {
                padding: 12px 20px;
                font-size: 15px;
            }
        }
    </style>
    <!-- Mobile Responsive Fixes -->
    <link rel="stylesheet" href="/mercado/assets/css/mobile-responsive-fixes.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-inner">
            <button class="back-btn" onclick="history.back()">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>
            <h1 class="header-title">Finalizar pedido</h1>
            <div class="header-secure">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/>
                </svg>
                Pagamento seguro
            </div>
        </div>
    </header>

    <!-- Main -->
    <main class="main">
        <div class="checkout-left">
            <!-- Delivery Address -->
            <div class="card" style="margin-bottom: 20px;">
                <div class="card-header">
                    <span class="step-number">1</span>
                    <div>
                        <div class="card-title">Endereco de entrega</div>
                        <div class="card-subtitle">Selecione onde deseja receber</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="address-list">
                        <?php foreach ($addresses as $i => $addr): ?>
                        <label class="address-item <?= $i === 0 ? 'selected' : '' ?>" onclick="selectAddress(this, <?= $addr['address_id'] ?>)">
                            <input type="radio" name="address_id" value="<?= $addr['address_id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                            <div class="address-radio"></div>
                            <div class="address-content">
                                <div class="address-label"><?= htmlspecialchars($addr['firstname'] . ' ' . $addr['lastname']) ?></div>
                                <div class="address-text">
                                    <?= htmlspecialchars($addr['address_1']) ?><?= $addr['address_2'] ? ', ' . htmlspecialchars($addr['address_2']) : '' ?><br>
                                    <?= htmlspecialchars($addr['city']) ?> - <?= htmlspecialchars($addr['zone_name'] ?? $addr['zone_code'] ?? '') ?>
                                </div>
                                <div class="address-cep">CEP: <?= htmlspecialchars($addr['postcode']) ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>

                        <?php if (empty($addresses)): ?>
                        <a href="/index.php?route=account/address/add" class="add-address-btn">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            Adicionar endereco
                        </a>
                        <?php else: ?>
                        <a href="/index.php?route=account/address/add" class="add-address-btn">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            Adicionar outro endereco
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Customer Data -->
            <div class="card" style="margin-bottom: 20px;">
                <div class="card-header">
                    <span class="step-number">2</span>
                    <div>
                        <div class="card-title">Seus dados</div>
                        <div class="card-subtitle">Confirme suas informacoes</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Nome</div>
                            <div class="info-value"><?= htmlspecialchars($customerName) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">E-mail</div>
                            <div class="info-value"><?= htmlspecialchars($customer['email']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Telefone</div>
                            <div class="info-value"><?= htmlspecialchars($customer['telephone'] ?? 'Nao informado') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">CEP de entrega</div>
                            <div class="info-value" id="selectedCep"><?= htmlspecialchars($defaultAddress['postcode'] ?? '-') ?></div>
                        </div>
                    </div>

                    <div class="cpf-section">
                        <div class="input-group">
                            <label class="input-label">CPF (obrigatorio para pagamento)</label>
                            <input type="text" id="cpf" class="input-field" placeholder="000.000.000-00" maxlength="14">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delivery Type -->
            <div class="card" style="margin-bottom: 20px;">
                <div class="card-header">
                    <span class="step-number">3</span>
                    <div>
                        <div class="card-title">Como deseja receber?</div>
                        <div class="card-subtitle">Escolha entrega ou retirada na loja</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="delivery-type-selector">
                        <label class="delivery-type-option selected" onclick="selectDeliveryType('entrega', this)">
                            <input type="radio" name="delivery_type" value="entrega" checked>
                            <div class="delivery-type-radio"></div>
                            <div class="delivery-type-icon">
                                <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                            </div>
                            <div class="delivery-type-info">
                                <div class="delivery-type-name">Entrega</div>
                                <div class="delivery-type-desc">Receba no seu endereco</div>
                                <div class="delivery-type-fee">R$ 7,99</div>
                            </div>
                        </label>

                        <label class="delivery-type-option" onclick="selectDeliveryType('retirada', this)">
                            <input type="radio" name="delivery_type" value="retirada">
                            <div class="delivery-type-radio"></div>
                            <div class="delivery-type-icon" style="background: #FFF3E8; color: #FF6B00;">
                                <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                            </div>
                            <div class="delivery-type-info">
                                <div class="delivery-type-name">Retirar na Loja</div>
                                <div class="delivery-type-desc">Retire no mercado sem frete</div>
                                <div class="delivery-type-fee" style="color: var(--green); font-weight: 700;">GRATIS</div>
                            </div>
                            <span class="delivery-type-badge-eco">Economize R$ 7,99</span>
                        </label>
                    </div>

                    <!-- Info box que aparece ao selecionar retirada -->
                    <div id="pickupInfo" style="display: none; margin-top: 16px; padding: 16px; background: #FFF8F0; border: 1px solid #FFE0C0; border-radius: 12px;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                            <span style="font-size: 1.3rem;">🏪</span>
                            <strong style="font-size: 15px;">Como funciona a retirada?</strong>
                        </div>
                        <ol style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--gray-700); line-height: 1.8;">
                            <li>Um shopper faz as compras pra voce no mercado</li>
                            <li>Voce recebe um <strong>codigo de retirada</strong> por WhatsApp</li>
                            <li>Va ate o mercado e informe o codigo ao shopper</li>
                            <li>Pronto! Leve suas compras sem esperar entrega</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Payment -->
            <div class="card">
                <div class="card-header">
                    <span class="step-number">4</span>
                    <div>
                        <div class="card-title">Forma de pagamento</div>
                        <div class="card-subtitle">Escolha como pagar</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="payment-tabs">
                        <button class="payment-tab active" onclick="switchPayment('pix')">
                            <div class="payment-tab-icon">
                                <svg width="32" height="32" viewBox="0 0 512 512" fill="#0AAD0A">
                                    <path d="M242.4 292.5C247.8 287.1 257.1 287.1 262.5 292.5L339.5 369.5C353.7 383.7 googletag372.3 404.7 372.3 430.3V430.8C372.3 456.4 353.7 477.4 339.5 491.6L262.5 568.5C257.1 573.9 247.8 573.9 242.4 568.5L165.4 491.6C151.2 477.4 132.6 456.4 132.6 430.8V430.3C132.6 404.7 151.2 383.7 165.4 369.5L242.4 292.5zM248.5 91.4L180.7 159.2C174.9 165 174.9 174.6 180.7 180.4L248.5 248.2C254.3 254 263.9 254 269.7 248.2L337.5 180.4C343.3 174.6 343.3 165 337.5 159.2L269.7 91.4C263.9 85.6 254.3 85.6 248.5 91.4z"/>
                                </svg>
                            </div>
                            <div class="payment-tab-name">PIX</div>
                            <div class="payment-tab-desc">Aprovacao instantanea</div>
                        </button>
                        <button class="payment-tab" onclick="switchPayment('card')">
                            <div class="payment-tab-icon">
                                <svg width="32" height="32" fill="none" stroke="#0AAD0A" viewBox="0 0 24 24">
                                    <rect x="1" y="4" width="22" height="16" rx="2" stroke-width="2"/>
                                    <line x1="1" y1="10" x2="23" y2="10" stroke-width="2"/>
                                </svg>
                            </div>
                            <div class="payment-tab-name">Cartao</div>
                            <div class="payment-tab-desc">Credito em ate 12x</div>
                        </button>
                    </div>

                    <!-- PIX Content -->
                    <div id="pixContent" class="payment-content active">
                        <div id="pixLoading" class="pix-loading">
                            <div class="spinner"></div>
                            <div style="color: var(--gray-500);">Gerando codigo PIX...</div>
                        </div>

                        <div id="pixReady" class="pix-ready">
                            <div class="pix-timer">
                                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                                </svg>
                                <span id="pixTimerText">Expira em 60:00</span>
                            </div>
                            <div class="pix-qr">
                                <img id="pixQrImg" src="" alt="QR Code PIX">
                            </div>
                            <div class="pix-code-box">
                                <div class="pix-code-label">Codigo PIX copia e cola:</div>
                                <div class="pix-code" id="pixCode"></div>
                            </div>
                            <button class="copy-btn" onclick="copyPixCode()">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <rect x="9" y="9" width="13" height="13" rx="2" stroke-width="2"/>
                                    <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" stroke-width="2"/>
                                </svg>
                                Copiar codigo
                            </button>
                        </div>

                        <div id="pixSuccess" class="pix-success">
                            <div class="success-icon">
                                <svg width="40" height="40" fill="white" viewBox="0 0 24 24">
                                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                                </svg>
                            </div>
                            <h3 style="font-size: 20px; margin-bottom: 8px;">Pagamento confirmado!</h3>
                            <p style="color: var(--gray-500);">Seu pedido esta sendo preparado</p>
                        </div>

                        <div id="pixError" class="pix-error">
                            <div class="error-icon">
                                <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                            </div>
                            <h3 style="margin-bottom: 8px;">Erro ao gerar PIX</h3>
                            <p id="pixErrorMsg" style="color: var(--gray-500); margin-bottom: 16px;"></p>
                            <button onclick="generatePix()" style="padding: 12px 24px; background: var(--green); color: white; border: none; border-radius: 10px; font-weight: 600; cursor: pointer;">
                                Tentar novamente
                            </button>
                        </div>
                    </div>

                    <!-- Card Content -->
                    <div id="cardContent" class="payment-content">
                        <div class="card-form">
                            <div class="input-group">
                                <label class="input-label">Numero do cartao</label>
                                <input type="text" id="cardNumber" class="input-field" placeholder="0000 0000 0000 0000" maxlength="19">
                            </div>
                            <div class="input-group">
                                <label class="input-label">Nome impresso no cartao</label>
                                <input type="text" id="cardName" class="input-field" placeholder="NOME COMPLETO" style="text-transform: uppercase;">
                            </div>
                            <div class="card-row">
                                <div class="input-group">
                                    <label class="input-label">Validade</label>
                                    <input type="text" id="cardExpiry" class="input-field" placeholder="MM/AA" maxlength="5">
                                </div>
                                <div class="input-group">
                                    <label class="input-label">CVV</label>
                                    <input type="text" id="cardCvv" class="input-field" placeholder="000" maxlength="4">
                                </div>
                            </div>
                            <div class="input-group">
                                <label class="input-label">Parcelas</label>
                                <select id="installments" class="installments-select">
                                    <option value="1">1x de R$ <?= number_format($total, 2, ',', '.') ?> sem juros</option>
                                    <?php for ($i = 2; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>"><?= $i ?>x de R$ <?= number_format($total / $i, 2, ',', '.') ?> sem juros</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="sidebar">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="card-title">Resumo do pedido</div>
                        <div class="card-subtitle"><?= $totalQty ?> <?= $totalQty == 1 ? 'item' : 'itens' ?></div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="summary-items">
                        <?php foreach ($items as $item): ?>
                        <div class="summary-item">
                            <img src="<?= htmlspecialchars($item['image'] ?? '/mercado/assets/no-image.png') ?>" alt="" class="summary-img">
                            <div class="summary-info">
                                <div class="summary-name"><?= htmlspecialchars($item['name']) ?></div>
                                <div class="summary-qty"><?= $item['qty'] ?>x R$ <?= number_format($item['final_price'], 2, ',', '.') ?></div>
                            </div>
                            <div class="summary-price">R$ <?= number_format($item['line_total'], 2, ',', '.') ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="summary-totals">
                        <div class="summary-row">
                            <span>Subtotal</span>
                            <span>R$ <?= number_format($subtotal, 2, ',', '.') ?></span>
                        </div>
                        <div class="summary-row">
                            <span>Entrega</span>
                            <span>R$ 7,99</span>
                        </div>
                        <div class="summary-row total">
                            <span>Total</span>
                            <span>R$ <?= number_format($subtotal + 7.99, 2, ',', '.') ?></span>
                        </div>
                    </div>

                    <button id="payBtn" class="pay-btn" onclick="processPayment()">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Finalizar pedido
                    </button>
                </div>
            </div>
        </div>
    </main>

    <!-- Mobile Footer -->
    <div class="mobile-footer">
        <div class="mobile-footer-content">
            <div class="mobile-total">
                <div class="mobile-total-label">Total</div>
                <div class="mobile-total-value">R$ <?= number_format($subtotal + 7.99, 2, ',', '.') ?></div>
            </div>
            <button id="mobilePayBtn" class="mobile-pay-btn" onclick="processPayment()">
                Finalizar pedido
            </button>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast"></div>

    <!-- Success Modal -->
    <div id="successModal" class="modal">
        <div class="modal-content">
            <div class="modal-icon">
                <svg width="40" height="40" fill="white" viewBox="0 0 24 24">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                </svg>
            </div>
            <h2 class="modal-title" id="successTitle">Pedido confirmado!</h2>
            <p class="modal-text" id="successText">Seu pedido foi realizado com sucesso. Voce recebera atualizacoes por e-mail.</p>
            <button class="modal-btn" onclick="window.location.href='/mercado/meus-pedidos.php'">Ver meus pedidos</button>
        </div>
    </div>

    <script>
        // Todos os endereços com CEP
        const allAddresses = <?= json_encode(array_map(function($addr) {
            return [
                'id' => $addr['address_id'],
                'street' => $addr['address_1'],
                'number' => $addr['address_2'] ?? 'S/N',
                'neighborhood' => $addr['address_2'] ?? $addr['city'],
                'city' => $addr['city'],
                'state' => $addr['zone_code'] ?? 'SP',
                'zipcode' => preg_replace('/\D/', '', $addr['postcode'] ?? ''),
                'postcode' => $addr['postcode'] ?? ''
            ];
        }, $addresses)) ?>;

        // Order Data
        const orderData = {
            items: <?= json_encode($items) ?>,
            subtotal: <?= $subtotal ?>,
            delivery_fee: 7.99,
            total: <?= $subtotal ?> + 7.99,
            seller_id: <?= $mainSellerId ?>,
            customer: {
                id: <?= $customer_id ?>,
                name: '<?= addslashes($customerName) ?>',
                email: '<?= addslashes($customer['email']) ?>',
                phone: '<?= addslashes($phone) ?>'
            },
            address: allAddresses[0] || null,
            delivery_type: null,
            delivery_option: null
        };

        // Seller ID global para o componente de frete
        window.currentSellerId = <?= $mainSellerId ?>;

        let paymentMethod = 'pix';
        let pixChargeId = null;
        let pixPaid = false;
        let pixTimer = null;
        let pixChecker = null;
        let deliveryMode = 'entrega'; // 'entrega' ou 'retirada'
        const DELIVERY_FEE_ENTREGA = 7.99;

        // CPF Mask
        document.getElementById('cpf').addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            if (v.length > 11) v = v.substring(0, 11);
            if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d+)/, '$1.$2.$3');
            else if (v.length > 3) v = v.replace(/(\d{3})(\d+)/, '$1.$2');
            e.target.value = v;
        });

        // Card masks
        document.getElementById('cardNumber').addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            if (v.length > 16) v = v.substring(0, 16);
            v = v.replace(/(\d{4})(?=\d)/g, '$1 ');
            e.target.value = v;
        });

        document.getElementById('cardExpiry').addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            if (v.length >= 2) v = v.substring(0, 2) + '/' + v.substring(2, 4);
            e.target.value = v;
        });

        document.getElementById('cardCvv').addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/\D/g, '').substring(0, 4);
        });

        // Select Address
        function selectAddress(el, addressId) {
            document.querySelectorAll('.address-item').forEach(a => a.classList.remove('selected'));
            el.classList.add('selected');
            el.querySelector('input').checked = true;

            // Atualizar orderData.address
            const addr = allAddresses.find(a => a.id == addressId);
            if (addr) {
                orderData.address = addr;
                document.getElementById('selectedCep').textContent = addr.postcode || addr.zipcode;
                console.log('Endereco atualizado:', addr);
            }
        }

        // Select Delivery Type (entrega / retirada)
        function selectDeliveryType(type, el) {
            deliveryMode = type;

            // Visual
            document.querySelectorAll('.delivery-type-option').forEach(o => o.classList.remove('selected'));
            el.classList.add('selected');
            el.querySelector('input').checked = true;

            // Pickup info box
            document.getElementById('pickupInfo').style.display = type === 'retirada' ? 'block' : 'none';

            // Address card - show/hide hint
            const addrCard = document.querySelector('.card:first-child');
            const addrSubtitle = addrCard?.querySelector('.card-subtitle');
            if (addrSubtitle) {
                addrSubtitle.textContent = type === 'retirada'
                    ? 'Endereco para contato (nao sera usado para entrega)'
                    : 'Selecione onde deseja receber';
            }

            // Update delivery fee
            if (type === 'retirada') {
                orderData.delivery_fee = 0;
                orderData.delivery_type = 'retirada';
                orderData.delivery_option = { tipo: 'retirada', preco: 0 };
            } else {
                orderData.delivery_fee = DELIVERY_FEE_ENTREGA;
                orderData.delivery_type = 'standard';
                orderData.delivery_option = { tipo: 'standard', preco: DELIVERY_FEE_ENTREGA };
            }

            // Update total
            orderData.total = orderData.subtotal + orderData.delivery_fee;

            // Update display
            const deliveryEl = document.querySelector('.summary-row:nth-child(2) span:last-child');
            const totalEl = document.querySelector('.summary-row.total span:last-child');
            const mobileTotal = document.querySelector('.mobile-total-value');

            if (deliveryEl) {
                if (orderData.delivery_fee === 0) {
                    deliveryEl.className = 'free-badge';
                    deliveryEl.textContent = 'GRATIS';
                } else {
                    deliveryEl.className = '';
                    deliveryEl.textContent = 'R$ ' + orderData.delivery_fee.toFixed(2).replace('.', ',');
                }
            }
            if (totalEl) totalEl.textContent = 'R$ ' + orderData.total.toFixed(2).replace('.', ',');
            if (mobileTotal) mobileTotal.textContent = 'R$ ' + orderData.total.toFixed(2).replace('.', ',');

            // Update sidebar delivery label
            const deliveryLabel = document.querySelector('.summary-row:nth-child(2) span:first-child');
            if (deliveryLabel) {
                deliveryLabel.textContent = type === 'retirada' ? 'Retirada na loja' : 'Entrega';
            }
        }

        // Switch Payment
        function switchPayment(method) {
            paymentMethod = method;
            document.querySelectorAll('.payment-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.payment-content').forEach(c => c.classList.remove('active'));

            event.target.closest('.payment-tab').classList.add('active');
            document.getElementById(method + 'Content').classList.add('active');

            if (method === 'pix' && !pixChargeId) {
                generatePix();
            }
        }

        // Generate PIX
        async function generatePix() {
            const cpf = document.getElementById('cpf').value.replace(/\D/g, '');

            if (cpf.length !== 11) {
                showToast('Informe seu CPF para gerar o PIX', 'error');
                document.getElementById('cpf').focus();
                return;
            }

            document.getElementById('pixLoading').style.display = 'block';
            document.getElementById('pixReady').style.display = 'none';
            document.getElementById('pixError').style.display = 'none';
            document.getElementById('pixSuccess').style.display = 'none';

            try {
                const response = await fetch('/mercado/api/checkout.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'pix',
                        nome: orderData.customer.name,
                        email: orderData.customer.email,
                        cpf: cpf,
                        telefone: orderData.customer.phone,
                        valor: orderData.total,
                        cep: orderData.address?.zipcode || orderData.address?.postcode || '',
                        rua: orderData.address?.street || '',
                        numero: orderData.address?.number || 'S/N',
                        bairro: orderData.address?.neighborhood || '',
                        cidade: orderData.address?.city || '',
                        estado: orderData.address?.state || '',
                        items: JSON.stringify(orderData.items)
                    })
                });

                const data = await response.json();
                console.log('PIX Response:', data);

                if (data.success) {
                    pixChargeId = data.charge_id;
                    document.getElementById('pixQrImg').src = data.qr_code_url;
                    document.getElementById('pixCode').textContent = data.qr_code;

                    document.getElementById('pixLoading').style.display = 'none';
                    document.getElementById('pixReady').style.display = 'block';

                    startPixTimer(60);
                    startPixChecker();
                } else {
                    throw new Error(data.error || 'Erro ao gerar PIX');
                }
            } catch (err) {
                console.error('PIX Error:', err);
                document.getElementById('pixLoading').style.display = 'none';
                document.getElementById('pixError').style.display = 'block';
                document.getElementById('pixErrorMsg').textContent = err.message;
            }
        }

        function startPixTimer(minutes) {
            let seconds = minutes * 60;

            if (pixTimer) clearInterval(pixTimer);

            pixTimer = setInterval(() => {
                const m = Math.floor(seconds / 60);
                const s = seconds % 60;
                document.getElementById('pixTimerText').textContent =
                    `Expira em ${m}:${s.toString().padStart(2, '0')}`;

                if (--seconds < 0) {
                    clearInterval(pixTimer);
                    document.getElementById('pixTimerText').textContent = 'PIX expirado';
                    generatePix();
                }
            }, 1000);
        }

        function startPixChecker() {
            if (pixChecker) clearInterval(pixChecker);

            pixChecker = setInterval(async () => {
                if (!pixChargeId) return;

                try {
                    const res = await fetch(`/mercado/api/checkout.php?action=check&charge_id=${pixChargeId}`);
                    const data = await res.json();

                    if (data.paid || data.status === 'paid') {
                        clearInterval(pixChecker);
                        clearInterval(pixTimer);
                        pixPaid = true;

                        document.getElementById('pixReady').style.display = 'none';
                        document.getElementById('pixSuccess').style.display = 'block';
                        showToast('Pagamento PIX confirmado!', 'success');
                    }
                } catch (e) {}
            }, 3000);
        }

        function copyPixCode() {
            const code = document.getElementById('pixCode').textContent;
            navigator.clipboard.writeText(code);
            showToast('Codigo PIX copiado!', 'success');
        }

        // Process Payment
        async function processPayment() {
            const btn = document.getElementById('payBtn');
            const mobileBtn = document.getElementById('mobilePayBtn');
            const addressId = document.querySelector('input[name="address_id"]:checked')?.value;
            const cpf = document.getElementById('cpf').value.replace(/\D/g, '');

            if (!addressId) {
                showToast('Selecione um endereco de entrega', 'error');
                return;
            }

            if (cpf.length !== 11) {
                showToast('Informe um CPF valido', 'error');
                document.getElementById('cpf').focus();
                return;
            }

            // Validar selecao de frete
            if (!orderData.delivery_option && typeof freteSelecionado !== 'undefined' && !freteSelecionado) {
                showToast('Selecione uma opcao de entrega', 'error');
                return;
            }

            btn.disabled = true;
            mobileBtn.disabled = true;
            btn.innerHTML = '<div class="spinner" style="width:20px;height:20px;border-width:2px"></div>';

            try {
                if (paymentMethod === 'pix') {
                    if (!pixPaid) {
                        showToast('Aguarde a confirmacao do PIX', 'error');
                        resetButtons();
                        return;
                    }
                    await createOrder('pix');
                } else {
                    const cardNumber = document.getElementById('cardNumber').value.replace(/\s/g, '');
                    const cardName = document.getElementById('cardName').value;
                    const expiry = document.getElementById('cardExpiry').value.split('/');
                    const cvv = document.getElementById('cardCvv').value;
                    const installments = document.getElementById('installments').value;

                    if (!cardNumber || cardNumber.length < 13) {
                        showToast('Numero do cartao invalido', 'error');
                        resetButtons();
                        return;
                    }

                    const res = await fetch('/mercado/api/checkout.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'cartao',
                            nome: orderData.customer.name,
                            email: orderData.customer.email,
                            cpf: cpf,
                            telefone: orderData.customer.phone,
                            valor: orderData.total,
                            card_number: cardNumber,
                            card_name: cardName,
                            card_exp_month: expiry[0],
                            card_exp_year: '20' + expiry[1],
                            card_cvv: cvv,
                            parcelas: installments,
                            cep: orderData.address?.zipcode || '',
                            rua: orderData.address?.street || '',
                            numero: orderData.address?.number || 'S/N',
                            bairro: orderData.address?.neighborhood || '',
                            cidade: orderData.address?.city || '',
                            estado: orderData.address?.state || '',
                            items: JSON.stringify(orderData.items)
                        })
                    });

                    const data = await res.json();
                    if (data.success) {
                        await createOrder('credit_card');
                    } else {
                        throw new Error(data.error || 'Pagamento recusado');
                    }
                }
            } catch (err) {
                showToast(err.message, 'error');
                resetButtons();
            }
        }

        async function createOrder(method) {
            const addressId = document.querySelector('input[name="address_id"]:checked').value;
            const cpf = document.getElementById('cpf').value.replace(/\D/g, '');

            // Dados de entrega do componente de frete
            const deliveryData = orderData.delivery_option || {};

            try {
                const isPickup = deliveryMode === 'retirada';
                const res = await fetch('/mercado/api/pedido.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create',
                        customer_id: orderData.customer.id,
                        address_id: addressId,
                        cpf: cpf,
                        items: orderData.items,
                        subtotal: orderData.subtotal,
                        delivery_fee: orderData.delivery_fee,
                        total: orderData.total,
                        payment_method: method,
                        charge_id: pixChargeId,
                        delivery_type: isPickup ? 'retirada' : 'standard',
                        tipo_entrega: isPickup ? 'retirada' : 'entrega',
                        is_pickup: isPickup ? 1 : 0
                    })
                });

                const data = await res.json();
                if (data.success) {
                    // Limpar carrinho
                    await fetch('/mercado/api/cart.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'clear' })
                    });
                    // Atualizar modal de sucesso para retirada
                    if (deliveryMode === 'retirada') {
                        document.getElementById('successTitle').textContent = 'Pedido para retirada!';
                        document.getElementById('successText').textContent = 'Um shopper vai fazer suas compras. Voce recebera o codigo de retirada por WhatsApp quando estiver pronto.';
                    }
                    document.getElementById('successModal').classList.add('show');
                } else {
                    throw new Error(data.error || 'Erro ao criar pedido');
                }
            } catch (err) {
                // Se der erro no pedido mas o pagamento foi feito, limpar carrinho e mostrar sucesso
                await fetch('/mercado/api/cart.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clear' })
                });
                document.getElementById('successModal').classList.add('show');
            }
        }

        function resetButtons() {
            const btn = document.getElementById('payBtn');
            const mobileBtn = document.getElementById('mobilePayBtn');
            btn.disabled = false;
            mobileBtn.disabled = false;
            btn.innerHTML = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Finalizar pedido';
        }

        function showToast(msg, type = '') {
            const toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.className = 'toast show ' + type;
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        // Init - gera PIX automaticamente quando CPF for preenchido
        document.getElementById('cpf').addEventListener('blur', function() {
            if (this.value.replace(/\D/g, '').length === 11 && paymentMethod === 'pix' && !pixChargeId) {
                generatePix();
            }
        });
    </script>
</body>
</html>
