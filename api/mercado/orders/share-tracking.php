<?php
/**
 * POST /api/mercado/orders/share-tracking.php?order_id=X
 * Generates a shareable tracking link for an order.
 * Requires customer auth. Returns a URL that anyone can use to
 * view the order's tracking status (without personal/payment info).
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $customerId = requireCustomerAuth();
    $db = getDB();

    $orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
    if (!$orderId) {
        $input = getInput();
        $orderId = (int)($input['order_id'] ?? 0);
    }
    if (!$orderId) response(false, null, "order_id obrigatorio", 400);

    // Verify the order belongs to this customer
    $stmt = $db->prepare("
        SELECT order_id, customer_id, status, share_token, share_token_expires_at
        FROM om_market_orders
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Check if order is in a shareable status (not cancelled/recusado)
    $nonShareableStatuses = ['cancelado', 'recusado'];
    if (in_array($order['status'], $nonShareableStatuses)) {
        response(false, null, "Este pedido nao pode ser compartilhado", 400);
    }

    // Reuse existing token if still valid (not expired)
    $existingToken = $order['share_token'];
    $existingExpiry = $order['share_token_expires_at'];

    if ($existingToken && $existingExpiry) {
        $expiresAt = new DateTime($existingExpiry);
        $now = new DateTime();
        if ($expiresAt > $now) {
            // Token still valid — return it
            $shareUrl = "https://superbora.com.br/acompanhar/{$existingToken}";
            response(true, [
                'share_token' => $existingToken,
                'share_url' => $shareUrl,
                'expires_at' => $existingExpiry,
            ], "Link de compartilhamento gerado");
        }
    }

    // Generate a new share token (cryptographically secure, URL-safe)
    $token = bin2hex(random_bytes(24)); // 48 hex chars
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $stmtUpdate = $db->prepare("
        UPDATE om_market_orders
        SET share_token = ?, share_token_expires_at = ?
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmtUpdate->execute([$token, $expiresAt, $orderId, $customerId]);

    $shareUrl = "https://superbora.com.br/acompanhar/{$token}";

    response(true, [
        'share_token' => $token,
        'share_url' => $shareUrl,
        'expires_at' => $expiresAt,
    ], "Link de compartilhamento gerado");

} catch (Exception $e) {
    error_log("[API share-tracking] Erro: " . $e->getMessage());
    response(false, null, "Erro ao gerar link de compartilhamento", 500);
}
