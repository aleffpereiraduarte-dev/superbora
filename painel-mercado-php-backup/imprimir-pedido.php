<?php
/**
 * GET /painel/mercado/imprimir-pedido.php?id=123
 * Print-friendly receipt for thermal/regular printers.
 * Auth: session-based (mercado_id).
 */

session_start();

if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';

$mercado_id = $_SESSION['mercado_id'];
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    echo 'Pedido invalido';
    exit;
}

try {
    $db = getDB();

    // Fetch order
    $stmt = $db->prepare("
        SELECT o.*, p.name as partner_name, p.phone as partner_phone, p.address as partner_address
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ? AND o.partner_id = ?
    ");
    $stmt->execute([$id, $mercado_id]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        echo 'Pedido nao encontrado';
        exit;
    }

    // Fetch items
    $stmt = $db->prepare("SELECT * FROM om_market_order_items WHERE order_id = ? ORDER BY id");
    $stmt->execute([$id]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    echo 'Erro ao carregar pedido';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Pedido #<?= $pedido['order_id'] ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            width: 80mm;
            margin: 0 auto;
            padding: 5mm;
            color: #000;
        }
        .header { text-align: center; margin-bottom: 8px; border-bottom: 1px dashed #000; padding-bottom: 8px; }
        .header h1 { font-size: 16px; margin-bottom: 2px; }
        .header p { font-size: 10px; }
        .order-info { margin-bottom: 8px; border-bottom: 1px dashed #000; padding-bottom: 8px; }
        .order-info p { margin-bottom: 2px; }
        .order-number { font-size: 18px; font-weight: bold; text-align: center; margin: 4px 0; }
        .items-table { width: 100%; margin-bottom: 8px; border-collapse: collapse; }
        .items-table th { text-align: left; border-bottom: 1px solid #000; padding: 2px 0; font-size: 11px; }
        .items-table td { padding: 3px 0; vertical-align: top; font-size: 11px; }
        .items-table .qty { text-align: center; width: 30px; }
        .items-table .price { text-align: right; width: 60px; }
        .totals { border-top: 1px dashed #000; padding-top: 6px; margin-bottom: 8px; }
        .totals .row { display: flex; justify-content: space-between; margin-bottom: 2px; }
        .totals .total-row { font-weight: bold; font-size: 14px; border-top: 1px solid #000; padding-top: 4px; margin-top: 4px; }
        .customer { border-top: 1px dashed #000; padding-top: 6px; margin-bottom: 8px; }
        .customer p { margin-bottom: 2px; }
        .footer { text-align: center; font-size: 10px; margin-top: 10px; border-top: 1px dashed #000; padding-top: 6px; }
        .obs { margin-top: 6px; padding: 4px; border: 1px solid #000; font-size: 11px; }
        @media print {
            body { width: 80mm; }
            @page { margin: 0; size: 80mm auto; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= htmlspecialchars($pedido['partner_name'] ?? 'SuperBora') ?></h1>
        <?php if (!empty($pedido['partner_phone'])): ?>
        <p>Tel: <?= htmlspecialchars($pedido['partner_phone']) ?></p>
        <?php endif; ?>
    </div>

    <div class="order-number">
        PEDIDO #<?= $pedido['order_id'] ?>
    </div>

    <div class="order-info">
        <?php if (!empty($pedido['order_number'])): ?>
        <p><strong>Cod:</strong> <?= htmlspecialchars($pedido['order_number']) ?></p>
        <?php endif; ?>
        <p><strong>Data:</strong> <?= date('d/m/Y H:i', strtotime($pedido['date_added'])) ?></p>
        <p><strong>Pagto:</strong> <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $pedido['forma_pagamento'] ?? '-'))) ?></p>
        <?php if (!empty($pedido['is_pickup']) && $pedido['is_pickup']): ?>
        <p><strong>** RETIRADA NO LOCAL **</strong></p>
        <?php endif; ?>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>Item</th>
                <th class="qty">Qtd</th>
                <th class="price">Valor</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($itens as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['name'] ?? $item['product_name'] ?? 'Produto') ?></td>
                <td class="qty"><?= $item['quantity'] ?></td>
                <td class="price">R$ <?= number_format(($item['price'] ?? 0) * ($item['quantity'] ?? 1), 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="row">
            <span>Subtotal</span>
            <span>R$ <?= number_format($pedido['subtotal'] ?? 0, 2, ',', '.') ?></span>
        </div>
        <?php if (($pedido['delivery_fee'] ?? 0) > 0): ?>
        <div class="row">
            <span>Entrega</span>
            <span>R$ <?= number_format($pedido['delivery_fee'], 2, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <?php if (($pedido['service_fee'] ?? 0) > 0): ?>
        <div class="row">
            <span>Taxa Servico</span>
            <span>R$ <?= number_format($pedido['service_fee'], 2, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <?php if (($pedido['coupon_discount'] ?? 0) > 0): ?>
        <div class="row">
            <span>Desconto</span>
            <span>- R$ <?= number_format($pedido['coupon_discount'], 2, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <?php if (($pedido['tip_amount'] ?? 0) > 0): ?>
        <div class="row">
            <span>Gorjeta</span>
            <span>R$ <?= number_format($pedido['tip_amount'], 2, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <div class="row total-row">
            <span>TOTAL</span>
            <span>R$ <?= number_format($pedido['total'] ?? 0, 2, ',', '.') ?></span>
        </div>
    </div>

    <div class="customer">
        <p><strong>Cliente:</strong> <?= htmlspecialchars($pedido['customer_name'] ?? 'N/A') ?></p>
        <?php if (!empty($pedido['customer_phone'])): ?>
        <p><strong>Tel:</strong> <?= htmlspecialchars($pedido['customer_phone']) ?></p>
        <?php endif; ?>
        <?php if (!empty($pedido['delivery_address'])): ?>
        <p><strong>Endereco:</strong> <?= htmlspecialchars($pedido['delivery_address']) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($pedido['notes'])): ?>
    <div class="obs">
        <strong>Obs:</strong> <?= htmlspecialchars($pedido['notes']) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($pedido['codigo_entrega'])): ?>
    <div style="text-align:center;margin-top:8px;font-size:16px;font-weight:bold;border:1px solid #000;padding:4px;">
        Codigo: <?= htmlspecialchars($pedido['codigo_entrega']) ?>
    </div>
    <?php endif; ?>

    <div class="footer">
        <p>SuperBora - <?= date('d/m/Y H:i') ?></p>
    </div>
</body>
</html>
