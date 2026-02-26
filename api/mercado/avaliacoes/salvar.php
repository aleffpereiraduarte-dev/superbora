<?php
/**
 * POST /api/mercado/avaliacoes/salvar.php
 * Body: { "order_id": 1, "partner_id": 2, "rating": 5, "comment": "..." }
 * Saves a customer rating for a partner (store)
 *
 * SECURITY: customer_id is taken from authenticated token, not from request body
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $input = getInput();
    $db = getDB();

    // SECURITY: Authenticate user and get customer_id from token (not from request body)
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();

    if (!$token) {
        response(false, null, "Autenticacao necessaria", 401);
    }

    $payload = om_auth()->validateToken($token);
    if (!$payload || ($payload['type'] ?? '') !== 'customer') {
        response(false, null, "Token invalido ou expirado", 401);
    }

    // Get customer_id from authenticated token (ignore any customer_id in request body)
    $customer_id = (int)$payload['uid'];

    if (!$customer_id) {
        response(false, null, "Cliente nao autenticado", 401);
    }

    $order_id = (int)($input["order_id"] ?? 0);
    $partner_id = (int)($input["partner_id"] ?? 0);
    $rating = (int)($input["rating"] ?? 0);
    $comment = strip_tags(substr(trim($input["comment"] ?? ""), 0, 1000));

    if (!$partner_id) {
        response(false, null, "partner_id obrigatorio", 400);
    }

    // If order_id is provided, verify the order belongs to the authenticated customer
    if ($order_id) {
        $stmtOrder = $db->prepare("SELECT customer_id FROM om_market_orders WHERE order_id = ?");
        $stmtOrder->execute([$order_id]);
        $order = $stmtOrder->fetch();

        if (!$order || (int)$order['customer_id'] !== $customer_id) {
            response(false, null, "Pedido nao encontrado ou nao pertence ao cliente", 403);
        }
    }
    if ($rating < 1 || $rating > 5) {
        response(false, null, "Rating deve ser entre 1 e 5", 400);
    }

    // Check if already rated this order
    if ($order_id) {
        $stmt = $db->prepare("
            SELECT rating_id FROM om_market_ratings
            WHERE order_id = ? AND rater_id = ? AND rated_type = 'partner'
        ");
        $stmt->execute([$order_id, $customer_id]);
        if ($stmt->fetch()) {
            // Return 409 Conflict for duplicate rating attempt
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "Voce ja avaliou este pedido",
                "error_code" => "DUPLICATE_RATING"
            ]);
            exit;
        }
    }

    // Insert rating
    $stmt = $db->prepare("
        INSERT INTO om_market_ratings (order_id, rated_type, rated_id, rater_type, rater_id, rating, comment, is_public, created_at)
        VALUES (?, 'partner', ?, 'customer', ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$order_id, $partner_id, $customer_id, $rating, $comment]);

    // Update partner average rating
    $stmt = $db->prepare("
        SELECT AVG(rating) as avg_rating, COUNT(*) as total
        FROM om_market_ratings
        WHERE rated_type = 'partner' AND rated_id = ?
    ");
    $stmt->execute([$partner_id]);
    $avg = $stmt->fetch();

    if ($avg) {
        $stmt = $db->prepare("UPDATE om_market_partners SET rating = ? WHERE partner_id = ?");
        $stmt->execute([round($avg['avg_rating'], 2), $partner_id]);
    }

    // Mark order as rated
    if ($order_id) {
        $stmt = $db->prepare("UPDATE om_market_orders SET is_rated = 1, rated_at = NOW() WHERE order_id = ?");
        $stmt->execute([$order_id]);
    }

    response(true, [
        "rating" => $rating,
        "media" => round((float)($avg['avg_rating'] ?? $rating), 1),
        "total_avaliacoes" => (int)($avg['total'] ?? 1)
    ], "Avaliacao salva com sucesso");

} catch (Exception $e) {
    error_log("[API Avaliacoes] Erro: " . $e->getMessage());
    response(false, null, "Erro ao salvar avaliacao", 500);
}
