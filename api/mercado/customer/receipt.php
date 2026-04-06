<?php
/**
 * GET /api/mercado/customer/receipt.php?order_id=X
 * Generate print-optimized HTML receipt for a delivered order.
 * Auth: requireCustomerAuth — order must belong to the authenticated customer.
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { response(false, null, "Method not allowed", 405); }

try {
    $db = getDB();

    // Support token via query param for browser-opened URLs (no Authorization header)
    if (!empty($_GET['token']) && empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $_GET['token'];
    }

    $customer_id = requireCustomerAuth();

    $order_id = (int)($_GET['order_id'] ?? 0);
    if (!$order_id) {
        response(false, null, "order_id obrigatorio", 400);
    }

    // Fetch order — must belong to this customer
    $stmt = $db->prepare("
        SELECT o.*, p.trade_name AS partner_trade_name, p.name AS partner_name_full,
               p.cnpj AS partner_cnpj, p.address AS partner_address, p.phone AS partner_phone
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$order_id, $customer_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Fetch order items
    $stmtItems = $db->prepare("
        SELECT oi.*, pr.name AS product_name_full, pr.image AS product_image
        FROM om_market_order_items oi
        LEFT JOIN om_market_products pr ON pr.product_id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $stmtItems->execute([$order_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Build values
    $order_number = htmlspecialchars($order['order_number'] ?? "#{$order_id}");
    $date_added = $order['date_added'] ?? $order['created_at'] ?? '';
    $date_formatted = $date_added ? date('d/m/Y H:i', strtotime($date_added)) : '-';
    $store_name = htmlspecialchars($order['partner_trade_name'] ?? $order['partner_name_full'] ?? $order['partner_name'] ?? 'Loja');
    $cnpj = $order['partner_cnpj'] ?? '';
    if ($cnpj) {
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
        if (strlen($cnpj) === 14) {
            $cnpj = substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/' . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12, 2);
        }
    }

    $subtotal = (float)($order['subtotal'] ?? 0);
    $delivery_fee = (float)($order['delivery_fee'] ?? 0);
    $service_fee = (float)($order['service_fee'] ?? 0);
    $tip = (float)($order['tip_amount'] ?? 0);
    $coupon_discount = (float)($order['coupon_discount'] ?? 0);
    $cashback_discount = (float)($order['cashback_discount'] ?? 0);
    $loyalty_discount = (float)($order['loyalty_discount'] ?? 0);
    $total = (float)($order['total'] ?? 0);
    $installments = (int)($order['installments'] ?? 1);
    $installment_value = (float)($order['installment_value'] ?? $total);

    $payment_method = $order['forma_pagamento'] ?? 'pix';
    $payment_labels = [
        'pix' => 'PIX',
        'credito' => 'Cartao de Credito',
        'debito' => 'Cartao de Debito',
        'stripe_card' => 'Cartao de Credito',
        'stripe_wallet' => 'Carteira Digital',
        'efi_card' => 'Cartao de Credito',
        'dinheiro' => 'Dinheiro',
        'cartao_entrega' => 'Maquininha na entrega',
        'vale_refeicao' => 'Vale Refeicao',
    ];
    $payment_label = $payment_labels[$payment_method] ?? ucfirst($payment_method);
    if ($installments > 1) {
        $payment_label .= " ({$installments}x R$ " . number_format($installment_value, 2, ',', '.') . ")";
    }

    $address = htmlspecialchars($order['delivery_address'] ?? $order['shipping_address'] ?? '-');
    $customer_name = htmlspecialchars($order['customer_name'] ?? 'Cliente');
    $cpf_nota = $order['cpf_nota'] ?? '';

    $status_labels = [
        'entregue' => 'Entregue', 'retirado' => 'Retirado', 'concluido' => 'Concluido',
        'cancelado' => 'Cancelado', 'recusado' => 'Recusado',
        'pendente' => 'Pendente', 'confirmado' => 'Confirmado',
        'preparando' => 'Em preparo', 'em_preparo' => 'Em preparo',
        'pronto' => 'Pronto', 'saiu_entrega' => 'Saiu para entrega',
        'em_entrega' => 'Em entrega',
    ];
    $status_label = $status_labels[$order['status'] ?? ''] ?? ucfirst($order['status'] ?? 'desconhecido');

    // Format currency
    function fmt($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }

    // Build items HTML
    $items_html = '';
    foreach ($items as $i => $item) {
        $name = htmlspecialchars($item['product_name_full'] ?? $item['name'] ?? $item['product_name'] ?? 'Item');
        $qty = (int)($item['quantity'] ?? $item['qty'] ?? 1);
        $price = (float)($item['price'] ?? $item['preco'] ?? 0);
        $item_total = (float)($item['total'] ?? ($qty * $price));
        $items_html .= '<tr>
            <td style="padding:8px 4px;border-bottom:1px solid #f0f0f0;">' . $qty . 'x</td>
            <td style="padding:8px 4px;border-bottom:1px solid #f0f0f0;">' . $name . '</td>
            <td style="padding:8px 4px;border-bottom:1px solid #f0f0f0;text-align:right;white-space:nowrap;">' . fmt($price) . '</td>
            <td style="padding:8px 4px;border-bottom:1px solid #f0f0f0;text-align:right;white-space:nowrap;font-weight:600;">' . fmt($item_total) . '</td>
        </tr>';
    }

    // Discounts total
    $total_discounts = $coupon_discount + $cashback_discount + $loyalty_discount;

    // Output HTML receipt
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comprovante - Pedido {$order_number}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    color: #1e293b;
    background: #f8fafc;
    padding: 0;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .receipt {
    max-width: 480px;
    margin: 20px auto;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
    overflow: hidden;
  }
  .header {
    background: linear-gradient(135deg, #036B52, #04876a);
    color: #fff;
    padding: 28px 24px;
    text-align: center;
  }
  .header h1 { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
  .header .subtitle { font-size: 13px; opacity: 0.85; }
  .header .logo { font-size: 28px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 8px; }
  .section { padding: 20px 24px; }
  .section + .section { border-top: 1px solid #f0f0f0; }
  .section-title { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; font-weight: 700; margin-bottom: 12px; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .info-item label { display: block; font-size: 11px; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
  .info-item span { display: block; font-size: 14px; font-weight: 600; color: #1e293b; margin-top: 2px; }
  .info-item.full { grid-column: 1 / -1; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 700; padding: 8px 4px; border-bottom: 2px solid #e2e8f0; }
  table th:nth-child(3), table th:nth-child(4) { text-align: right; }
  .totals { margin-top: 16px; }
  .totals .row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 13px; color: #64748b; }
  .totals .row.discount { color: #059669; }
  .totals .row.total { font-size: 18px; font-weight: 800; color: #036B52; padding-top: 12px; margin-top: 8px; border-top: 2px solid #e2e8f0; }
  .status-badge {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    background: #ecfdf5;
    color: #059669;
  }
  .footer {
    text-align: center;
    padding: 20px 24px;
    background: #f8fafc;
    border-top: 1px solid #f0f0f0;
    font-size: 11px;
    color: #94a3b8;
  }
  .print-btn {
    display: block;
    width: calc(100% - 48px);
    margin: 16px 24px;
    padding: 14px;
    background: #036B52;
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    text-align: center;
  }
  .print-btn:hover { background: #025a44; }
  @media print {
    body { background: #fff; padding: 0; }
    .receipt { box-shadow: none; margin: 0; max-width: 100%; border-radius: 0; }
    .print-btn { display: none !important; }
    .no-print { display: none !important; }
  }
</style>
</head>
<body>
<div class="receipt">
  <div class="header">
    <div class="logo">SuperBora</div>
    <h1>Comprovante de Pedido</h1>
    <div class="subtitle">{$order_number} - {$date_formatted}</div>
  </div>

  <div class="section">
    <div class="section-title">Informacoes do pedido</div>
    <div class="info-grid">
      <div class="info-item">
        <label>Pedido</label>
        <span>{$order_number}</span>
      </div>
      <div class="info-item">
        <label>Status</label>
        <span><span class="status-badge">{$status_label}</span></span>
      </div>
      <div class="info-item">
        <label>Data</label>
        <span>{$date_formatted}</span>
      </div>
      <div class="info-item">
        <label>Pagamento</label>
        <span>{$payment_label}</span>
      </div>
      <div class="info-item full">
        <label>Cliente</label>
        <span>{$customer_name}</span>
      </div>
HTML;

    if ($cpf_nota) {
        $cpf_fmt = strlen($cpf_nota) === 11
            ? substr($cpf_nota, 0, 3) . '.' . substr($cpf_nota, 3, 3) . '.' . substr($cpf_nota, 6, 3) . '-' . substr($cpf_nota, 9, 2)
            : htmlspecialchars($cpf_nota);
        echo '<div class="info-item full"><label>CPF na nota</label><span>' . $cpf_fmt . '</span></div>';
    }

    echo <<<HTML
    </div>
  </div>

  <div class="section">
    <div class="section-title">Estabelecimento</div>
    <div class="info-grid">
      <div class="info-item full">
        <label>Nome</label>
        <span>{$store_name}</span>
      </div>
HTML;

    if ($cnpj) {
        echo '<div class="info-item full"><label>CNPJ</label><span>' . htmlspecialchars($cnpj) . '</span></div>';
    }

    echo <<<HTML
    </div>
  </div>

  <div class="section">
    <div class="section-title">Endereco de entrega</div>
    <div class="info-grid">
      <div class="info-item full">
        <label>Endereco</label>
        <span>{$address}</span>
      </div>
    </div>
  </div>

  <div class="section">
    <div class="section-title">Itens do pedido</div>
    <table>
      <thead>
        <tr>
          <th>Qtd</th>
          <th>Item</th>
          <th>Preco</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        {$items_html}
      </tbody>
    </table>

    <div class="totals">
      <div class="row"><span>Subtotal</span><span>{fmt($subtotal)}</span></div>
      <div class="row"><span>Taxa de entrega</span><span>{fmt($delivery_fee)}</span></div>
HTML;

    if ($service_fee > 0) {
        echo '<div class="row"><span>Taxa de servico</span><span>' . fmt($service_fee) . '</span></div>';
    }
    if ($tip > 0) {
        echo '<div class="row"><span>Gorjeta</span><span>' . fmt($tip) . '</span></div>';
    }
    if ($coupon_discount > 0) {
        echo '<div class="row discount"><span>Desconto cupom</span><span>-' . fmt($coupon_discount) . '</span></div>';
    }
    if ($cashback_discount > 0) {
        echo '<div class="row discount"><span>Cashback utilizado</span><span>-' . fmt($cashback_discount) . '</span></div>';
    }
    if ($loyalty_discount > 0) {
        echo '<div class="row discount"><span>Desconto fidelidade</span><span>-' . fmt($loyalty_discount) . '</span></div>';
    }

    echo <<<HTML
      <div class="row total"><span>Total</span><span>{fmt($total)}</span></div>
    </div>
  </div>

  <button class="print-btn no-print" onclick="window.print()">Imprimir / Salvar PDF</button>

  <div class="footer">
    SuperBora - Delivery de comida, mercado e farmacia<br>
    Este documento nao possui valor fiscal.<br>
    Gerado em {$date_formatted}
  </div>
</div>
</body>
</html>
HTML;

} catch (Exception $e) {
    error_log("[Receipt] Error: " . $e->getMessage());
    response(false, null, "Erro ao gerar comprovante", 500);
}
