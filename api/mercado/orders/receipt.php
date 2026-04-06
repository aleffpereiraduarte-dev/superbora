<?php
/**
 * GET /api/mercado/orders/receipt.php?order_id=X
 * Generates an HTML receipt for a delivered order.
 * Returns JSON with `receipt_html` field.
 * Requires customer auth — order must belong to the authenticated customer.
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    $orderId = (int)($_GET['order_id'] ?? 0);
    if (!$orderId) {
        response(false, null, "ID do pedido obrigatorio", 400);
    }

    // ── 1. Fetch order + partner data ──
    $stmt = dbQuery($db, "
        SELECT o.*,
               p.trade_name AS partner_trade_name,
               p.name AS partner_legal_name,
               p.logo AS partner_logo,
               p.address AS partner_address,
               p.phone AS partner_phone,
               p.city AS partner_city,
               p.state AS partner_state,
               p.document AS partner_document,
               c.name AS customer_full_name,
               c.email AS customer_email_addr,
               c.phone AS customer_phone_num
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN om_market_customers c ON o.customer_id = c.customer_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ", [$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // ── 2. Fetch order items ──
    $stmt = dbQuery($db, "
        SELECT product_name, quantity, price, unit_price, total_price,
               category_name, brand, unit, weight
        FROM om_market_order_items
        WHERE order_id = ?
        ORDER BY item_id ASC
    ", [$orderId]);
    $items = $stmt->fetchAll();

    // ── 3. Build values ──
    $storeName = $order['partner_trade_name'] ?: $order['partner_legal_name'] ?: $order['partner_name'] ?: 'Loja';
    $storeLogo = $order['partner_logo'] ?: '';
    $storeAddress = $order['partner_address'] ?: '';
    $storeCity = trim(($order['partner_city'] ?: '') . ' ' . ($order['partner_state'] ?: ''));
    $storeDoc = $order['partner_document'] ?: '';

    $orderNumber = $order['order_number'] ?: ('#' . $order['order_id']);
    $orderDate = '';
    $rawDate = $order['date_added'] ?: $order['created_at'] ?? '';
    if ($rawDate) {
        try {
            $dt = new DateTime($rawDate, new DateTimeZone('America/Sao_Paulo'));
            $orderDate = $dt->format('d/m/Y H:i');
        } catch (Exception $e) {
            $orderDate = $rawDate;
        }
    }

    $customerName = $order['customer_full_name'] ?: $order['customer_name'] ?: '';
    $customerPhone = $order['customer_phone_num'] ?: $order['customer_phone'] ?: '';

    $deliveryAddress = $order['delivery_address'] ?: $order['shipping_address'] ?: '';
    if (!$deliveryAddress && $order['shipping_address']) {
        $parts = array_filter([
            $order['shipping_address'],
            $order['shipping_number'],
            $order['shipping_complement'],
            $order['shipping_neighborhood'],
            $order['shipping_city'],
            $order['shipping_state'],
            $order['shipping_cep'],
        ]);
        $deliveryAddress = implode(', ', $parts);
    }

    $subtotal = (float)($order['subtotal'] ?? 0);
    $deliveryFee = (float)($order['delivery_fee'] ?? 0);
    $serviceFee = (float)($order['service_fee'] ?? 0);
    $discount = (float)($order['discount'] ?? 0);
    $couponDiscount = (float)($order['coupon_discount'] ?? 0);
    $cashbackUsed = (float)($order['cashback_discount'] ?? 0);
    $tipAmount = (float)($order['tip_amount'] ?? 0);
    $total = (float)($order['total'] ?? 0);

    $paymentMethod = $order['forma_pagamento'] ?: $order['payment_method'] ?: '';
    $paymentLabel = formatPaymentMethod($paymentMethod);

    $isPickup = (bool)($order['is_pickup'] ?? false);

    // ── 4. Build items HTML ──
    $itemsHtml = '';
    foreach ($items as $item) {
        $name = htmlspecialchars($item['product_name'] ?: '', ENT_QUOTES, 'UTF-8');
        $qty = (int)($item['quantity'] ?? 1);
        $unitPrice = (float)($item['unit_price'] ?: $item['price'] ?: 0);
        $lineTotal = (float)($item['total_price'] ?: ($unitPrice * $qty));
        $brand = $item['brand'] ? htmlspecialchars($item['brand'], ENT_QUOTES, 'UTF-8') : '';
        $extra = $brand ? "<span class=\"item-brand\">{$brand}</span>" : '';

        $itemsHtml .= "
        <tr>
            <td class=\"item-name\">{$name}{$extra}</td>
            <td class=\"item-qty\">{$qty}</td>
            <td class=\"item-price\">" . formatBRL($unitPrice) . "</td>
            <td class=\"item-total\">" . formatBRL($lineTotal) . "</td>
        </tr>";
    }

    // ── 5. Build totals rows ──
    $totalsHtml = '<tr><td>Subtotal</td><td>' . formatBRL($subtotal) . '</td></tr>';

    if ($deliveryFee > 0) {
        $totalsHtml .= '<tr><td>Taxa de entrega</td><td>' . formatBRL($deliveryFee) . '</td></tr>';
    } elseif (!$isPickup) {
        $totalsHtml .= '<tr><td>Taxa de entrega</td><td class="free">Gratis</td></tr>';
    }

    if ($serviceFee > 0) {
        $totalsHtml .= '<tr><td>Taxa de servico</td><td>' . formatBRL($serviceFee) . '</td></tr>';
    }

    if ($tipAmount > 0) {
        $totalsHtml .= '<tr><td>Gorjeta</td><td>' . formatBRL($tipAmount) . '</td></tr>';
    }

    if ($discount > 0) {
        $totalsHtml .= '<tr class="discount"><td>Desconto</td><td>-' . formatBRL($discount) . '</td></tr>';
    }

    if ($couponDiscount > 0) {
        $totalsHtml .= '<tr class="discount"><td>Cupom de desconto</td><td>-' . formatBRL($couponDiscount) . '</td></tr>';
    }

    if ($cashbackUsed > 0) {
        $totalsHtml .= '<tr class="discount"><td>Cashback utilizado</td><td>-' . formatBRL($cashbackUsed) . '</td></tr>';
    }

    // Logo HTML
    $logoHtml = '';
    if ($storeLogo) {
        $logoUrl = htmlspecialchars($storeLogo, ENT_QUOTES, 'UTF-8');
        // Prefix relative URLs with base
        if (strpos($logoUrl, 'http') !== 0) {
            $logoUrl = 'https://superbora.com.br/' . ltrim($logoUrl, '/');
        }
        $logoHtml = "<img src=\"{$logoUrl}\" alt=\"Logo\" class=\"store-logo\" />";
    }

    $safeStoreName = htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8');
    $safeOrderNumber = htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8');
    $safeCustomerName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
    $safeDeliveryAddress = htmlspecialchars($deliveryAddress, ENT_QUOTES, 'UTF-8');
    $safePaymentLabel = htmlspecialchars($paymentLabel, ENT_QUOTES, 'UTF-8');

    // ── 6. Build full HTML ──
    $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Comprovante - {$safeOrderNumber}</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: #f8fafc;
    color: #1e293b;
    padding: 16px;
    font-size: 14px;
    line-height: 1.5;
}
.receipt {
    max-width: 420px;
    margin: 0 auto;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    overflow: hidden;
}
.header {
    background: linear-gradient(135deg, #00A868, #047857);
    color: #fff;
    padding: 24px 20px;
    text-align: center;
}
.store-logo {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    object-fit: cover;
    margin-bottom: 12px;
    border: 2px solid rgba(255,255,255,0.2);
}
.header h1 {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 4px;
}
.header .order-num {
    font-size: 13px;
    opacity: 0.8;
}
.header .order-date {
    font-size: 12px;
    opacity: 0.65;
    margin-top: 2px;
}
.section {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
}
.section:last-child {
    border-bottom: none;
}
.section-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
    margin-bottom: 10px;
}
.info-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 6px;
    font-size: 13px;
}
.info-row .label { color: #64748b; }
.info-row .value { color: #1e293b; font-weight: 500; text-align: right; max-width: 60%; }
.items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.items-table th {
    text-align: left;
    font-size: 11px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f1f5f9;
}
.items-table th:nth-child(2),
.items-table th:nth-child(3),
.items-table th:nth-child(4) { text-align: right; }
.items-table td {
    padding: 8px 0;
    border-bottom: 1px solid #f8fafc;
    vertical-align: top;
}
.item-name { font-weight: 500; }
.item-brand { display: block; font-size: 11px; color: #94a3b8; font-weight: 400; }
.item-qty, .item-price, .item-total { text-align: right; white-space: nowrap; }
.item-total { font-weight: 600; }
.totals-table {
    width: 100%;
    font-size: 13px;
}
.totals-table td {
    padding: 5px 0;
}
.totals-table td:last-child {
    text-align: right;
    font-weight: 500;
}
.totals-table .discount td { color: #00A868; }
.totals-table .discount td:last-child { font-weight: 600; }
.total-row {
    border-top: 2px solid #e2e8f0;
    margin-top: 8px;
    padding-top: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.total-label {
    font-size: 15px;
    font-weight: 700;
    color: #1e293b;
}
.total-value {
    font-size: 20px;
    font-weight: 800;
    color: #00A868;
}
.free { color: #00A868; font-weight: 600; }
.footer {
    text-align: center;
    padding: 20px;
    background: #f8fafc;
    font-size: 11px;
    color: #94a3b8;
    line-height: 1.6;
}
.footer .brand {
    font-weight: 700;
    color: #00A868;
}
</style>
</head>
<body>
<div class="receipt">
    <div class="header">
        {$logoHtml}
        <h1>{$safeStoreName}</h1>
        <div class="order-num">Pedido {$safeOrderNumber}</div>
        <div class="order-date">{$orderDate}</div>
    </div>

    <div class="section">
        <div class="section-title">Cliente</div>
        <div class="info-row">
            <span class="label">Nome</span>
            <span class="value">{$safeCustomerName}</span>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Itens do pedido</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qtd</th>
                    <th>Preco</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                {$itemsHtml}
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Resumo</div>
        <table class="totals-table">
            <tbody>
                {$totalsHtml}
            </tbody>
        </table>
        <div class="total-row">
            <span class="total-label">Total</span>
            <span class="total-value">{TOTAL_PLACEHOLDER}</span>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Pagamento</div>
        <div class="info-row">
            <span class="label">Forma</span>
            <span class="value">{$safePaymentLabel}</span>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Entrega</div>
        <div class="info-row">
            <span class="label">Endereco</span>
            <span class="value">{$safeDeliveryAddress}</span>
        </div>
    </div>

    <div class="footer">
        Comprovante gerado por <span class="brand">SuperBora</span><br>
        superbora.com.br
    </div>
</div>
</body>
</html>
HTML;

    // Replace total placeholder (avoids heredoc interpolation issues)
    $html = str_replace('{TOTAL_PLACEHOLDER}', formatBRL($total), $html);

    response(true, [
        'receipt_html' => $html,
        'order_id' => (int)$order['order_id'],
        'order_number' => $orderNumber,
    ]);

} catch (Exception $e) {
    error_log("[orders/receipt] Erro: " . $e->getMessage());
    response(false, null, "Erro ao gerar comprovante", 500);
}

// ── Helper functions ──

function formatBRL(float $value): string {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function formatPaymentMethod(string $method): string {
    $map = [
        'pix'         => 'PIX',
        'credit_card' => 'Cartao de Credito',
        'debit_card'  => 'Cartao de Debito',
        'cash'        => 'Dinheiro',
        'dinheiro'    => 'Dinheiro',
        'cartao'      => 'Cartao',
        'vale'        => 'Vale Refeicao',
    ];
    $key = strtolower(trim($method));
    return $map[$key] ?? ucfirst($method);
}
