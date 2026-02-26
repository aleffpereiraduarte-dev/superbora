<?php
/**
 * POST /api/mercado/boraum/avaliar.php
 * Avaliar pedido (1-5 estrelas + comentario)
 *
 * Body: { order_id, rating, comment? }
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

setCorsHeaders();

try {
    $db = getDB();
    $user = requirePassageiro($db);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $orderId = (int)($input['order_id'] ?? 0);
    $rating = (int)($input['rating'] ?? 0);
    $comment = trim(substr($input['comment'] ?? '', 0, 1000));

    if (!$orderId) response(false, null, "order_id obrigatorio", 400);
    if ($rating < 1 || $rating > 5) response(false, null, "Avaliacao deve ser de 1 a 5", 400);

    $customerId = $user['customer_id'];

    // Verify order belongs to customer and is delivered
    $stmtOrder = $db->prepare("
        SELECT order_id, partner_id, status, order_number
        FROM om_market_orders
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmtOrder->execute([$orderId, $customerId]);
    $order = $stmtOrder->fetch();

    if (!$order) response(false, null, "Pedido nao encontrado", 404);

    $deliveredStatuses = ['entregue'];
    if (!in_array($order['status'], $deliveredStatuses)) {
        response(false, null, "Somente pedidos entregues podem ser avaliados", 400);
    }

    // Check if already rated
    $stmtCheck = $db->prepare("SELECT id FROM om_market_ratings WHERE order_id = ? AND customer_id = ?");
    $stmtCheck->execute([$orderId, $customerId]);
    if ($stmtCheck->fetch()) {
        response(false, null, "Voce ja avaliou este pedido", 400);
    }

    $partnerId = (int)$order['partner_id'];

    // Insert rating
    $stmtInsert = $db->prepare("
        INSERT INTO om_market_ratings (order_id, partner_id, customer_id, rating, comment, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmtInsert->execute([$orderId, $partnerId, $customerId, $rating, $comment ?: null]);

    // Update partner average rating
    try {
        $stmtAvg = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM om_market_ratings WHERE partner_id = ?");
        $stmtAvg->execute([$partnerId]);
        $avgRow = $stmtAvg->fetch();

        if ($avgRow) {
            $db->prepare("UPDATE om_market_partners SET rating = ? WHERE partner_id = ?")
                ->execute([round((float)$avgRow['avg_rating'], 2), $partnerId]);
        }
    } catch (Exception $e) {
        error_log("[BoraUm Avaliar] Erro ao atualizar media: " . $e->getMessage());
    }

    // Record event
    try {
        $db->prepare("
            INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
            VALUES (?, 'rated', ?, ?, NOW())
        ")->execute([$orderId, "Avaliacao: {$rating}/5" . ($comment ? " - {$comment}" : ''), "customer:{$customerId}"]);
    } catch (Exception $e) {}

    response(true, [
        'avaliacao' => [
            'order_id' => $orderId,
            'rating' => $rating,
            'comment' => $comment ?: null,
        ]
    ], "Avaliacao enviada com sucesso!");

} catch (Exception $e) {
    error_log("[BoraUm Avaliar] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar avaliacao", 500);
}
