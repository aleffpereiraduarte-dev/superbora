<?php
/**
 * GET /painel/mercado/pedido-detalhe.php?id=123
 * Returns order details as JSON for the partner panel modal.
 * Auth: session-based (mercado_id).
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['mercado_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nao autorizado']);
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';

$mercado_id = $_SESSION['mercado_id'];
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID invalido']);
    exit;
}

try {
    $db = getDB();

    // Fetch order — must belong to this partner
    $stmt = $db->prepare("
        SELECT o.*
        FROM om_market_orders o
        WHERE o.order_id = ? AND o.partner_id = ?
    ");
    $stmt->execute([$id, $mercado_id]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido nao encontrado']);
        exit;
    }

    // Fetch order items
    $stmt = $db->prepare("SELECT * FROM om_market_order_items WHERE order_id = ? ORDER BY id");
    $stmt->execute([$id]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pedido['itens'] = $itens;

    echo json_encode(['success' => true, 'pedido' => $pedido]);

} catch (Exception $e) {
    error_log("[pedido-detalhe] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
