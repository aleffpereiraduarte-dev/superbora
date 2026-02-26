<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/customer/cashback-preview.php
 * Preview de cashback que sera ganho em um pedido
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Query params:
 * - partner_id: ID da loja
 * - order_total: Valor total do pedido
 *
 * Retorna quanto de cashback o cliente ganharia neste pedido
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/cashback.php";

setCorsHeaders();

try {
    $db = getDB();
    $customerId = requireCustomerAuth();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    $orderTotal = (float)($_GET['order_total'] ?? 0);

    if (!$partnerId || $orderTotal <= 0) {
        response(false, null, "partner_id e order_total sao obrigatorios", 400);
    }

    // Calcular preview de cashback
    $preview = previewCashback($db, $partnerId, $orderTotal);

    response(true, [
        'cashback_preview' => $preview
    ]);

} catch (Exception $e) {
    error_log("[customer/cashback-preview] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
