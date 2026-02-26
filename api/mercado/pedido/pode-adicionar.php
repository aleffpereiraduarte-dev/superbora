<?php
/**
 * GET /api/mercado/pedido/pode-adicionar.php?order_id=1
 * Verifica se o cliente pode adicionar mais itens ao pedido
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

try {
    $customer_id = requireCustomerAuth();
    $order_id = (int)($_GET["order_id"] ?? 0);
    if (!$order_id) response(false, null, "order_id obrigatorio", 400);

    $db = getDB();
    $stmt = $db->prepare("
        SELECT status, customer_id FROM om_market_orders WHERE order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || (int)$order['customer_id'] !== $customer_id) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Can only add items if order is in early stages
    $addableStatuses = ['pendente', 'confirmado', 'aceito'];
    $canAdd = in_array($order['status'], $addableStatuses);

    response(true, ['pode_adicionar' => $canAdd]);

} catch (Exception $e) {
    error_log("[pedido/pode-adicionar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
