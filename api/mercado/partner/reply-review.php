<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/partner/reply-review.php
 * Partner replies to a customer review
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Header: Authorization: Bearer {partner_token}
 * Body JSON: { "review_id": 123, "reply_text": "Obrigado pela avaliação!" }
 *
 * - Max 500 chars for reply_text
 * - Only the partner that owns the review can reply
 * - Updates partner_reply and partner_reply_at on om_market_reviews
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Método não permitido', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    $input = getInput();
    $reviewId = (int)($input['review_id'] ?? 0);
    $replyText = trim($input['reply_text'] ?? '');

    // Validation
    if (!$reviewId) {
        response(false, null, 'review_id obrigatório', 400);
    }

    if (empty($replyText)) {
        response(false, null, 'reply_text obrigatório', 400);
    }

    if (mb_strlen($replyText) > 500) {
        response(false, null, 'Resposta deve ter no máximo 500 caracteres', 400);
    }

    // Verify the review belongs to this partner
    $stmt = $db->prepare("
        SELECT id, partner_id, partner_reply
        FROM om_market_reviews
        WHERE id = ?
    ");
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch();

    if (!$review) {
        response(false, null, 'Avaliação não encontrada', 404);
    }

    if ((int)$review['partner_id'] !== $partnerId) {
        response(false, null, 'Você não tem permissão para responder esta avaliação', 403);
    }

    // Sanitize reply text (prevent XSS)
    $replyText = sanitizeOutput($replyText);

    // Update the review with partner reply
    $stmt = $db->prepare("
        UPDATE om_market_reviews
        SET partner_reply = ?,
            partner_reply_at = NOW()
        WHERE id = ? AND partner_id = ?
    ");
    $stmt->execute([$replyText, $reviewId, $partnerId]);

    if ($stmt->rowCount() === 0) {
        response(false, null, 'Erro ao salvar resposta', 500);
    }

    // Invalidate cache for store reviews
    try {
        require_once dirname(__DIR__, 3) . "/cache/CacheHelper.php";
        CacheHelper::deletePattern("avaliacoes_lista_*");
    } catch (Exception $e) {
        // Cache invalidation failure is non-critical
        error_log("[ReplyReview] Cache invalidation failed: " . $e->getMessage());
    }

    response(true, [
        'review_id' => $reviewId,
        'partner_reply' => $replyText,
        'partner_reply_at' => date('c'),
    ], 'Resposta enviada com sucesso');

} catch (Exception $e) {
    error_log("[API partner/reply-review] Erro: " . $e->getMessage());
    response(false, null, "Erro ao responder avaliação", 500);
}
