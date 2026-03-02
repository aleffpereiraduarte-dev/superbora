<?php
/**
 * POST /api/mercado/orders/dispute-rating.php
 * Customer rates the resolution of a dispute
 * Body: { dispute_id, rating (1-5), comment }
 *
 * Requires customer auth.
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $disputeId = (int)($input['dispute_id'] ?? 0);
    $rating = (int)($input['rating'] ?? 0);
    $comment = strip_tags(trim(substr($input['comment'] ?? '', 0, 1000)));

    if (!$disputeId) response(false, null, "dispute_id obrigatorio", 400);
    if ($rating < 1 || $rating > 5) response(false, null, "Nota deve ser de 1 a 5", 400);

    // Verify dispute belongs to customer and is resolved
    $stmt = $db->prepare("
        SELECT dispute_id, order_id, customer_id, status, rating_after_dispute
        FROM om_order_disputes
        WHERE dispute_id = ? AND customer_id = ?
    ");
    $stmt->execute([$disputeId, $customerId]);
    $dispute = $stmt->fetch();

    if (!$dispute) response(false, null, "Disputa nao encontrada", 404);

    if (!in_array($dispute['status'], ['resolved', 'auto_resolved', 'closed'])) {
        response(false, null, "Apenas disputas resolvidas podem ser avaliadas", 400);
    }

    if (!empty($dispute['rating_after_dispute'])) {
        response(false, null, "Esta disputa ja foi avaliada", 400);
    }

    // Update dispute with rating
    $db->prepare("
        UPDATE om_order_disputes
        SET rating_after_dispute = ?, rating_comment = ?, updated_at = NOW()
        WHERE dispute_id = ?
    ")->execute([$rating, $comment ?: null, $disputeId]);

    // Add timeline entry
    $ratingLabels = [1 => 'Pessimo', 2 => 'Ruim', 3 => 'Regular', 4 => 'Bom', 5 => 'Otimo'];
    $ratingLabel = $ratingLabels[$rating] ?? $rating;
    $timelineDesc = "Cliente avaliou a resolucao: {$ratingLabel} ({$rating}/5)";
    if ($comment) {
        $timelineDesc .= " - " . substr($comment, 0, 200);
    }

    $db->prepare("
        INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
        VALUES (?, 'rated', 'customer', ?, ?, NOW())
    ")->execute([$disputeId, $customerId, $timelineDesc]);

    response(true, [
        'rating' => $rating,
        'comment' => $comment,
    ], "Avaliacao enviada com sucesso!");

} catch (Exception $e) {
    error_log("[dispute-rating] Erro: " . $e->getMessage());
    response(false, null, "Erro ao salvar avaliacao", 500);
}
