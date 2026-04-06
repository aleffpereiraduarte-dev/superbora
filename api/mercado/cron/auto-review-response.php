<?php
/**
 * Auto Review Response -- Cron (every 15 min recommended)
 *
 * Finds reviews with no partner_reply that are older than 2 hours,
 * generates an AI response via Claude, and updates the review.
 *
 * Tone varies by rating:
 *   4-5 stars: warm thank you, mention something from the comment
 *   1-2 stars: empathetic, apologize, offer to make it right
 *   3 stars:   thank and ask for specific feedback
 *
 * Limit: 20 reviews per run to control API costs.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

// Cron auth guard — skip for CLI (crontab runs as root)
if (php_sapi_name() !== 'cli') {
    $cronKey = $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    $expectedKey = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
    if (empty($expectedKey) || !hash_equals($expectedKey, $cronKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$db = getDB();
$processed = 0;
$errors = 0;
$startTime = microtime(true);

// Clear stale processing markers from previous failed runs (older than 10 min)
try {
    $db->exec("
        UPDATE om_market_reviews
        SET partner_reply = NULL
        WHERE partner_reply = '__processing__'
        AND partner_reply_at < NOW() - INTERVAL '10 minutes'
    ");
} catch (Exception $e) {
    error_log("[auto-review-response] Failed to clear stale markers: " . $e->getMessage());
}

// Select unresponded reviews older than 2 hours, with FOR UPDATE SKIP LOCKED
// to prevent duplicate processing under concurrent cron runs
$db->beginTransaction();
try {
    $stmt = $db->prepare("
        SELECT r.id AS review_id,
               r.partner_id,
               r.customer_id,
               r.rating,
               r.comment,
               r.customer_name,
               COALESCE(p.trade_name, p.name, 'Nosso Estabelecimento') AS business_name
        FROM om_market_reviews r
        JOIN om_market_partners p ON p.partner_id = r.partner_id
        WHERE r.partner_reply IS NULL
          AND r.created_at < NOW() - INTERVAL '2 hours'
          AND r.created_at > NOW() - INTERVAL '30 days'
          AND r.comment IS NOT NULL
          AND TRIM(r.comment) != ''
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
        $db->prepare("UPDATE om_market_reviews SET partner_reply = '__processing__', partner_reply_at = NOW() WHERE id IN ($placeholders)")
           ->execute($reviewIds);
    }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log("[auto-review-response] Failed to select reviews: " . $e->getMessage());
    echo date('Y-m-d H:i:s') . " -- Auto review response: ERROR selecting reviews\n";
    exit;
}

if (empty($reviews)) {
    echo date('Y-m-d H:i:s') . " -- Auto review response: 0 reviews to process\n";
    exit;
}

$claude = new ClaudeClient('claude-sonnet-4-20250514', 30, 1);

foreach ($reviews as $review) {
    try {
        $rating = (int)$review['rating'];
        $comment = trim($review['comment']);
        $customerName = trim($review['customer_name'] ?? '');
        $businessName = $review['business_name'];

        // Build tone instruction based on rating
        if ($rating >= 4) {
            $toneInstruction = "Esta e uma avaliacao POSITIVA ({$rating}/5 estrelas). "
                . "Escreva uma resposta calorosa e agradecida. "
                . "Mencione algo especifico do comentario do cliente para mostrar que voce leu. "
                . "Convide o cliente a voltar.";
        } elseif ($rating <= 2) {
            $toneInstruction = "Esta e uma avaliacao NEGATIVA ({$rating}/5 estrelas). "
                . "Escreva uma resposta empatica e profissional. "
                . "Peca desculpas sinceras pelo ocorrido. "
                . "Reconheca o problema mencionado no comentario. "
                . "Ofereca-se para resolver e melhorar a experiencia na proxima vez.";
        } else {
            // rating == 3
            $toneInstruction = "Esta e uma avaliacao NEUTRA (3/5 estrelas). "
                . "Agradeca o feedback do cliente. "
                . "Pergunte especificamente o que poderia ser melhorado para atingir 5 estrelas. "
                . "Mostre que voce valoriza a opiniao dele(a).";
        }

        $systemPrompt = "Voce e o dono do estabelecimento '{$businessName}', um negocio de delivery no Brasil. "
            . "Escreva uma resposta para a avaliacao de um cliente.\n\n"
            . $toneInstruction . "\n\n"
            . "REGRAS OBRIGATORIAS:\n"
            . "- Responda em portugues brasileiro natural e acolhedor\n"
            . "- Use o primeiro nome do cliente se disponivel\n"
            . "- Maximo 2-3 frases curtas\n"
            . "- NAO use emojis excessivos (maximo 1 se apropriado)\n"
            . "- NAO mencione que voce e uma IA\n"
            . "- NAO use linguagem excessivamente formal ou robotica\n"
            . "- Retorne APENAS o texto da resposta, sem aspas, sem JSON, sem explicacoes";

        $userMsg = "";
        if ($customerName) {
            $userMsg .= "Nome do cliente: {$customerName}\n";
        }
        $userMsg .= "Nota: {$rating}/5 estrelas\n";
        $userMsg .= "Comentario: {$comment}";

        $result = $claude->send($systemPrompt, [['role' => 'user', 'content' => $userMsg]], 200);

        if (!$result['success']) {
            error_log("[auto-review-response] Claude API failed for review {$review['review_id']}: {$result['error']}");
            // Clear processing marker so it can be retried next run
            $db->prepare("UPDATE om_market_reviews SET partner_reply = NULL, partner_reply_at = NULL WHERE id = ? AND partner_reply = '__processing__'")
               ->execute([$review['review_id']]);
            $errors++;
            continue;
        }

        $responseText = trim($result['text']);

        // Strip surrounding quotes if Claude wrapped the response
        if (str_starts_with($responseText, '"') && str_ends_with($responseText, '"')) {
            $responseText = trim($responseText, '"');
        }

        // Safety: skip if response is empty or suspiciously short
        if (strlen($responseText) < 10) {
            error_log("[auto-review-response] Response too short for review {$review['review_id']}: '{$responseText}'");
            $db->prepare("UPDATE om_market_reviews SET partner_reply = NULL, partner_reply_at = NULL WHERE id = ? AND partner_reply = '__processing__'")
               ->execute([$review['review_id']]);
            $errors++;
            continue;
        }

        // Update partner_reply on the reviews table
        $db->prepare("UPDATE om_market_reviews SET partner_reply = ?, partner_reply_at = NOW() WHERE id = ?")
           ->execute([$responseText, $review['review_id']]);

        // Also insert/update om_review_responses for partner panel consistency
        $existsStmt = $db->prepare("SELECT id FROM om_review_responses WHERE review_id = ?");
        $existsStmt->execute([$review['review_id']]);
        if ($existsStmt->fetch()) {
            $db->prepare("UPDATE om_review_responses SET response = ?, ai_generated = 1 WHERE review_id = ?")
               ->execute([$responseText, $review['review_id']]);
        } else {
            $db->prepare("INSERT INTO om_review_responses (review_id, partner_id, response, ai_generated, created_at) VALUES (?, ?, ?, 1, NOW())")
               ->execute([$review['review_id'], $review['partner_id'], $responseText]);
        }

        $processed++;
        error_log("[auto-review-response] Responded to review {$review['review_id']} (rating: {$rating}, partner: {$review['partner_id']})");

    } catch (Exception $e) {
        error_log("[auto-review-response] Error processing review {$review['review_id']}: " . $e->getMessage());
        // Clear the processing marker so it can be retried
        try {
            $db->prepare("UPDATE om_market_reviews SET partner_reply = NULL, partner_reply_at = NULL WHERE id = ? AND partner_reply = '__processing__'")
               ->execute([$review['review_id']]);
        } catch (Exception $clearErr) {
            error_log("[auto-review-response] Failed to clear processing marker for review {$review['review_id']}");
        }
        $errors++;
    }
}

$elapsed = round((microtime(true) - $startTime) * 1000);
$total = count($reviews);
echo date('Y-m-d H:i:s') . " -- Auto review response: {$processed}/{$total} reviews responded, {$errors} errors, {$elapsed}ms\n";

// Send summary alert via configured webhooks (non-blocking, failures logged but don't break cron)
try {
    require_once __DIR__ . '/../helpers/alert-bot.php';

    $level = $errors > 0 ? 'warning' : 'success';
    $summary = "Auto-review: {$processed}/{$total} responded, {$errors} errors ({$elapsed}ms)";
    $details = [
        'Processed' => "{$processed}/{$total}",
        'Errors'    => (string)$errors,
        'Duration'  => "{$elapsed}ms",
    ];

    // Only send alerts if there were reviews to process
    if ($total > 0) {
        dispatchAlert('cron_summary', $summary, $level, $details);
    }
} catch (Exception $alertErr) {
    // Alert failure must never break the cron job
    error_log("[auto-review-response] Alert dispatch failed: " . $alertErr->getMessage());
}
