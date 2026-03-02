<?php
/**
 * POST /api/mercado/vitrine/ticket-rating.php
 * Customer rates the resolution of a support ticket
 * Body: { ticket_id, rating (1-5), comment }
 *
 * Requires customer auth.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/ws-customer-broadcast.php";

setCorsHeaders();

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $ticketId = (int)($input['ticket_id'] ?? 0);
    $rating = (int)($input['rating'] ?? 0);
    $comment = strip_tags(trim(substr($input['comment'] ?? '', 0, 1000)));

    if (!$ticketId) response(false, null, "ticket_id obrigatorio", 400);
    if ($rating < 1 || $rating > 5) response(false, null, "Nota deve ser de 1 a 5", 400);

    // Verify ticket belongs to customer and is resolved/closed
    $stmt = $db->prepare("
        SELECT id, assunto, status, avaliacao, entidade_id
        FROM om_support_tickets
        WHERE id = ? AND entidade_tipo = 'customer' AND entidade_id = ?
    ");
    $stmt->execute([$ticketId, $customerId]);
    $ticket = $stmt->fetch();

    if (!$ticket) response(false, null, "Ticket nao encontrado", 404);

    if (!in_array($ticket['status'], ['resolvido', 'resolved', 'fechado', 'closed'])) {
        response(false, null, "Apenas tickets resolvidos podem ser avaliados", 400);
    }

    if (!empty($ticket['avaliacao'])) {
        response(false, null, "Este ticket ja foi avaliado", 400);
    }

    $db->beginTransaction();

    try {
        // Update ticket with rating
        $db->prepare("
            UPDATE om_support_tickets
            SET avaliacao = ?, avaliacao_comentario = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$rating, $comment ?: null, $ticketId]);

        // Add system message with rating info
        $ratingLabels = [1 => 'Pessimo', 2 => 'Ruim', 3 => 'Regular', 4 => 'Bom', 5 => 'Otimo'];
        $ratingLabel = $ratingLabels[$rating] ?? $rating;
        $systemMessage = "Cliente avaliou o atendimento: {$ratingLabel} ({$rating}/5)";
        if ($comment) {
            $systemMessage .= " - " . substr($comment, 0, 200);
        }

        $db->prepare("
            INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_id, remetente_nome, mensagem, created_at)
            VALUES (?, 'sistema', ?, 'Sistema', ?, NOW())
        ")->execute([$ticketId, $customerId, $systemMessage]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    // Broadcast via WebSocket
    wsBroadcastToCustomer($customerId, 'ticket_update', [
        'ticket_id' => $ticketId,
        'action' => 'rated',
        'rating' => $rating,
    ]);

    response(true, [
        'rating' => $rating,
        'comment' => $comment,
    ], "Avaliacao enviada com sucesso!");

} catch (Exception $e) {
    error_log("[ticket-rating] Erro: " . $e->getMessage());
    response(false, null, "Erro ao salvar avaliacao", 500);
}
