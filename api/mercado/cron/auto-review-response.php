<?php
/**
 * Auto Review Response — Cron every 15 min
 * Generates AI responses for positive reviews (partners with auto-respond enabled)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

// Cron auth guard
$cronKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$expectedKey = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($expectedKey) || !hash_equals($expectedKey, $cronKey)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$processed = 0;

// Select reviews with FOR UPDATE SKIP LOCKED and immediately mark as processing
// to prevent duplicate AI responses under concurrent execution
$db->beginTransaction();
$stmt = $db->prepare("
    SELECT r.id as review_id, r.partner_id, r.customer_id, r.rating, r.comment,
           r.order_id, COALESCE(p.trade_name, p.name) as business_name,
           COALESCE(c.name, r.customer_name) as customer_name,
           prc.response_style, prc.min_rating_auto
    FROM om_market_reviews r
    JOIN om_market_partners p ON p.partner_id = r.partner_id
    LEFT JOIN om_market_customers c ON c.customer_id = r.customer_id
    JOIN om_partner_review_config prc ON prc.partner_id = r.partner_id
    WHERE r.partner_reply IS NULL
    AND NOT EXISTS (SELECT 1 FROM om_review_responses rr WHERE rr.review_id = r.id)
    AND r.created_at > NOW() - INTERVAL '7 days'
    AND (
        (r.rating >= prc.min_rating_auto AND prc.auto_respond_positive = 1)
        OR (r.rating = 3 AND prc.auto_respond_neutral = 1)
    )
    ORDER BY r.created_at ASC
    LIMIT 20
    FOR UPDATE OF r SKIP LOCKED
");
$stmt->execute();
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mark all selected reviews as processing to claim them
if (!empty($reviews)) {
    $reviewIds = array_column($reviews, 'review_id');
    $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
    $db->prepare("UPDATE om_market_reviews SET partner_reply = '__processing__' WHERE id IN ($placeholders)")
       ->execute($reviewIds);
}
$db->commit();

$claude = new ClaudeClient();

foreach ($reviews as $review) {
    try {
        $style = $review['response_style'] ?? 'professional';
        $systemPrompt = "Voce e o dono do restaurante '{$review['business_name']}'. Escreva uma resposta para esta avaliacao.
Estilo: {$style}
REGRAS:
- Responda em portugues brasileiro
- Seja genuino e agradecido
- Mencione o nome do cliente se disponivel
- Se houver critica, reconheca e prometa melhorar
- Maximo 2-3 frases
- NAO use emojis excessivos
- Retorne apenas o texto da resposta, sem JSON";

        $userMsg = "Cliente: {$review['customer_name']}\nNota: {$review['rating']}/5\nComentario: " . ($review['comment'] ?: '(sem comentario)');

        $result = $claude->send($systemPrompt, [['role' => 'user', 'content' => $userMsg]], 256);
        if (!$result['success']) {
            error_log("Auto review response failed for review {$review['review_id']}: {$result['error']}");
            $db->prepare("UPDATE om_market_reviews SET partner_reply = NULL WHERE id = ? AND partner_reply = '__processing__'")
               ->execute([$review['review_id']]);
            continue;
        }

        $responseText = trim($result['text']);
        if (str_starts_with($responseText, '"') && str_ends_with($responseText, '"')) {
            $responseText = trim($responseText, '"');
        }

        // Update partner_reply on the reviews table
        $db->prepare("UPDATE om_market_reviews SET partner_reply = ?, partner_reply_at = NOW() WHERE id = ?")
           ->execute([$responseText, $review['review_id']]);

        // Also insert into om_review_responses for partner panel consistency
        $existsStmt = $db->prepare("SELECT id FROM om_review_responses WHERE review_id = ?");
        $existsStmt->execute([$review['review_id']]);
        if ($existsStmt->fetch()) {
            $db->prepare("UPDATE om_review_responses SET response = ?, ai_generated = 1 WHERE review_id = ?")
               ->execute([$responseText, $review['review_id']]);
        } else {
            $db->prepare("INSERT INTO om_review_responses (review_id, partner_id, response, ai_generated) VALUES (?, ?, ?, 1)")
               ->execute([$review['review_id'], $review['partner_id'], $responseText]);
        }

        $processed++;
    } catch (Exception $e) {
        error_log("[auto-review-response] Error processing review {$review['review_id']}: " . $e->getMessage());
        // Clear the processing marker so it can be retried next run
        try {
            $db->prepare("UPDATE om_market_reviews SET partner_reply = NULL WHERE id = ? AND partner_reply = '__processing__'")
               ->execute([$review['review_id']]);
        } catch (Exception $clearErr) {
            error_log("[auto-review-response] Failed to clear processing marker for review {$review['review_id']}");
        }
    }
}

echo date('Y-m-d H:i:s') . " — Auto review response: {$processed} reviews responded\n";
