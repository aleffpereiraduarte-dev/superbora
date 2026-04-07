<?php
/**
 * Cron: Retroactively tag reviews with sentiment + topics.
 *
 * Backfills the columns sentiment + topics on om_market_reviews for old entries.
 *
 * Schedule: 0 5 * * *  (5 AM daily, batch of 100)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

try {
    $db->exec("ALTER TABLE om_market_reviews ADD COLUMN IF NOT EXISTS sentiment_ai VARCHAR(20)");
    $db->exec("ALTER TABLE om_market_reviews ADD COLUMN IF NOT EXISTS topics_ai JSONB");
} catch (Exception $e) {}

$stmt = $db->query(
    "SELECT id, rating, COALESCE(comment,'') AS comment
     FROM om_market_reviews
     WHERE sentiment_ai IS NULL AND COALESCE(comment,'') <> ''
     LIMIT 100"
);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($reviews)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[retag-reviews] nothing to process\n");
    exit(0);
}

$upd = $db->prepare("UPDATE om_market_reviews SET sentiment_ai = :s, topics_ai = :t::jsonb WHERE id = :id");
$processed = 0;

foreach ($reviews as $r) {
    $prompt = "Avaliacao (nota {$r['rating']}/5): \"{$r['comment']}\"\n\n" .
              "Responda APENAS JSON: " .
              '{"sentiment":"positive|neutral|negative","topics":["entrega","sabor","preco","atendimento","embalagem","quantidade","temperatura","outros"]}';

    $reply = ClaudeClient::text($prompt, 'Classificador de reviews. JSON apenas.', 200);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) continue;

    $sentiment = in_array($parsed['sentiment'] ?? '', ['positive','neutral','negative'], true) ? $parsed['sentiment'] : 'neutral';
    $topics = is_array($parsed['topics'] ?? null) ? $parsed['topics'] : [];

    $upd->execute([':s' => $sentiment, ':t' => json_encode($topics), ':id' => $r['id']]);
    $processed++;
    usleep(80000);
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[retag-reviews] tagged {$processed}/" . count($reviews) . "\n");
