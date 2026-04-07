<?php
/**
 * GET /api/mercado/intelligence/app-review-timing.php?customer_id=123
 * Decides whether NOW is a good moment to ask the customer for an app store review.
 *
 * Considers: total orders, recent ratings, last order outcome, last review prompt.
 * Returns: { "should_ask": bool, "confidence": 0-100, "reason": "...", "message": "..." }
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) response(false, null, 'customer_id obrigatorio', 400);

    $db = getDB();

    // Customer profile
    $stmt = $db->prepare(
        "SELECT
            (SELECT COUNT(*) FROM om_market_orders WHERE customer_id = :cid AND status NOT IN ('cancelado','recusado')) AS total_orders,
            (SELECT AVG(rating) FROM om_market_reviews WHERE customer_id = :cid) AS avg_rating,
            (SELECT MAX(created_at) FROM om_market_orders WHERE customer_id = :cid AND status NOT IN ('cancelado','recusado')) AS last_order"
    );
    $stmt->execute([':cid' => $customerId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalOrders = (int)($profile['total_orders'] ?? 0);
    $avgRating = (float)($profile['avg_rating'] ?? 0);

    // Heuristics first — cheap rejection
    if ($totalOrders < 3) {
        response(true, ['should_ask' => false, 'confidence' => 95, 'reason' => 'cliente novo, esperar mais pedidos']);
    }
    if ($avgRating > 0 && $avgRating < 4) {
        response(true, ['should_ask' => false, 'confidence' => 90, 'reason' => 'cliente parece insatisfeito']);
    }

    // Already prompted recently?
    try {
        $stmt = $db->prepare("SELECT MAX(sent_at) FROM om_smart_push_log WHERE customer_id = :cid AND push_type = 'review_prompt'");
        $stmt->execute([':cid' => $customerId]);
        $lastPrompt = $stmt->fetchColumn();
        if ($lastPrompt && (time() - strtotime($lastPrompt)) < 90 * 86400) {
            response(true, ['should_ask' => false, 'confidence' => 99, 'reason' => 'ja perguntado recentemente']);
        }
    } catch (Exception $e) {}

    $prompt = "Cliente do SuperBora: {$totalOrders} pedidos, rating medio {$avgRating}. " .
              "Decida se eh BOM momento pra pedir review na App Store. " .
              "Responda APENAS JSON: " .
              '{"should_ask":true_ou_false,"confidence":0-100,"reason":"breve",' .
              '"message":"se sim, mensagem max 150 chars empurrando review"}';

    $reply = ClaudeClient::text($prompt, 'Voce decide o melhor momento pra pedir review. Conservador.', 250);
    $parsed = ClaudeClient::parseJson($reply ?: '') ?: ['should_ask' => false, 'confidence' => 50, 'reason' => 'sem decisao'];

    response(true, $parsed);
} catch (Exception $e) {
    error_log('[app-review-timing] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
