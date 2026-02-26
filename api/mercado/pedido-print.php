<?php
/**
 * Página de impressão de pedido
 */

require_once __DIR__ . '/config/database.php';

$id = intval($_GET['id'] ?? 0);

if (!$id) {
    die('ID inválido');
}

try {
    $db = getDB();

    // Auth: verificar que o pedido pertence ao usuario autenticado
    $customer_id = getCustomerIdFromToken();
    if (!$customer_id) {
        http_response_code(401);
        die('Autenticacao necessaria');
    }

    $stmt = $db->prepare("
        SELECT o.*, p.name as mercado_name, p.phone as mercado_phone
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$id, $customer_id]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        die('Pedido não encontrado');
    }

    $stmt = $db->prepare("SELECT * FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$id]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("[pedido-print] Erro: " . $e->getMessage());
    http_response_code(500);
    die('Erro ao carregar pedido');
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Pedido #<?= $pedido['order_id'] ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; font-size: 12px; max-width: 300px; margin: 0 auto; padding: 10px; }
        .header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px; }
        .header h1 { font-size: 16px; }
        .info { margin-bottom: 10px; }
        .info p { margin: 3px 0; }
        .items { border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 10px 0; }
        .item { display: flex; justify-content: space-between; margin: 5px 0; }
        .item-name { flex: 1; }
        .totals { margin-top: 10px; }
        .totals .row { display: flex; justify-content: space-between; margin: 3px 0; }
        .totals .total { font-weight: bold; font-size: 14px; border-top: 1px solid #000; padding-top: 5px; margin-top: 5px; }
        .footer { text-align: center; margin-top: 15px; font-size: 10px; }
        @media print {
            body { max-width: 100%; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= htmlspecialchars($pedido['mercado_name']) ?></h1>
        <p>PEDIDO #<?= htmlspecialchars($pedido['order_id']) ?></p>
        <p><?= date('d/m/Y H:i', strtotime($pedido['date_added'] ?? $pedido['created_at'] ?? 'now')) ?></p>
    </div>

    <div class="info">
        <p><strong>Cliente:</strong> <?= htmlspecialchars($pedido['customer_name'] ?? 'N/A') ?></p>
        <p><strong>Telefone:</strong> <?= htmlspecialchars($pedido['customer_phone'] ?? 'N/A') ?></p>
        <p><strong>Endereço:</strong></p>
        <p><?= htmlspecialchars($pedido['delivery_address'] ?? 'N/A') ?></p>
    </div>

    <div class="items">
        <p><strong>ITENS DO PEDIDO</strong></p>
        <?php foreach ($itens as $item): ?>
        <div class="item">
            <span class="item-name"><?= $item['quantity'] ?>x <?= htmlspecialchars($item['name']) ?></span>
            <span>R$ <?= number_format($item['quantity'] * $item['price'], 2, ',', '.') ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="totals">
        <div class="row">
            <span>Subtotal:</span>
            <span>R$ <?= number_format($pedido['subtotal'], 2, ',', '.') ?></span>
        </div>
        <div class="row">
            <span>Taxa de entrega:</span>
            <span>R$ <?= number_format($pedido['delivery_fee'], 2, ',', '.') ?></span>
        </div>
        <?php if ($pedido['discount'] > 0): ?>
        <div class="row">
            <span>Desconto:</span>
            <span>- R$ <?= number_format($pedido['discount'], 2, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <div class="row total">
            <span>TOTAL:</span>
            <span>R$ <?= number_format($pedido['total'], 2, ',', '.') ?></span>
        </div>
    </div>

    <div class="footer">
        <p>Obrigado pela preferência!</p>
        <p>OneMundo - Delivery</p>
    </div>

    <script>window.onload = function() { window.print(); }</script>
</body>
</html>
