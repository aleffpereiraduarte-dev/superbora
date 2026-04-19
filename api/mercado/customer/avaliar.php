<?php
/**
 * POST /api/mercado/customer/avaliar.php
 * Submit a review/rating for a store
 * Body: { store_id, rating, comment, order_id, ... }
 * Proxies to avaliacoes/salvar-completa.php pattern
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth();
    $input = getInput();

    $storeId = (int)($input['store_id'] ?? $input['partner_id'] ?? 0);
    $orderId = (int)($input['order_id'] ?? 0);
    $rating = (int)($input['rating'] ?? $input['nota'] ?? 0);
    $comment = trim(substr($input['comment'] ?? $input['comentario'] ?? '', 0, 1000));

    if (!$storeId) {
        response(false, null, "store_id obrigatorio", 400);
    }
    if ($rating < 1 || $rating > 5) {
        response(false, null, "Nota deve ser entre 1 e 5", 400);
    }

    // Verify store exists
    $stmt = $db->prepare("SELECT partner_id FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$storeId]);
    if (!$stmt->fetch()) {
        response(false, null, "Loja nao encontrada", 404);
    }

    // If order_id provided, verify it belongs to this customer
    if ($orderId) {
        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmt->execute([$orderId, $customerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Pedido nao encontrado", 404);
        }
    }

    // Check for duplicate rating
    $stmt = $db->prepare("
        SELECT rating_id FROM om_market_ratings
        WHERE rater_id = ? AND rater_type = 'customer' AND rated_id = ? AND rated_type = 'partner'
        AND (order_id = ? OR (order_id IS NULL AND ? = 0))
    ");
    $stmt->execute([$customerId, $storeId, $orderId, $orderId]);
    if ($stmt->fetch()) {
        response(false, null, "Voce ja avaliou este pedido", 409);
    }

    // Insert rating
    $stmt = $db->prepare("
        INSERT INTO om_market_ratings (rater_id, rater_type, rated_id, rated_type, order_id, rating, comment, is_public, created_at)
        VALUES (?, 'customer', ?, 'partner', NULLIF(?, 0), ?, ?, 1, NOW())
        RETURNING rating_id
    ");
    $stmt->execute([$customerId, $storeId, $orderId, $rating, $comment ?: null]);
    $ratingId = (int)$stmt->fetchColumn();

    // Update store average rating
    $stmt = $db->prepare("
        UPDATE om_market_partners
        SET rating = (SELECT AVG(rating) FROM om_market_ratings WHERE rated_id = ? AND rated_type = 'partner' AND is_public = 1),
            total_ratings = (SELECT COUNT(*) FROM om_market_ratings WHERE rated_id = ? AND rated_type = 'partner' AND is_public = 1)
        WHERE partner_id = ?
    ");
    $stmt->execute([$storeId, $storeId, $storeId]);

    response(true, ['rating_id' => $ratingId], "Avaliacao enviada com sucesso!");

} catch (Exception $e) {
    error_log("[customer/avaliar] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar avaliacao", 500);
}
