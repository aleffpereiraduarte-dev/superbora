<?php
/**
 * Cron: Detect potential duplicate stores or products.
 *
 * Uses simple normalization + Llama similarity check on candidates.
 * Stores findings in om_duplicate_candidates for human review.
 *
 * Schedule: 0 8 * * 1  (Mondays 8 AM)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS om_duplicate_candidates (
        id BIGSERIAL PRIMARY KEY,
        entity_type VARCHAR(20) NOT NULL,
        entity_id_a INTEGER NOT NULL,
        entity_id_b INTEGER NOT NULL,
        confidence DECIMAL(4,2),
        reason TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMPTZ DEFAULT NOW(),
        UNIQUE(entity_type, entity_id_a, entity_id_b)
    )");
} catch (Exception $e) {}

// Stores: find pairs with similar normalized names in same city
$dupCandidates = $db->query(
    "SELECT a.partner_id AS id_a, b.partner_id AS id_b, a.name AS name_a, b.name AS name_b, a.address_city AS city
     FROM om_market_partners a
     JOIN om_market_partners b ON
         a.address_city = b.address_city
         AND a.partner_id < b.partner_id
         AND LOWER(REGEXP_REPLACE(a.name, '[^a-zA-Z0-9]', '', 'g'))
             = LOWER(REGEXP_REPLACE(b.name, '[^a-zA-Z0-9]', '', 'g'))
     LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

$ins = $db->prepare(
    "INSERT INTO om_duplicate_candidates (entity_type, entity_id_a, entity_id_b, confidence, reason)
     VALUES ('partner', :a, :b, :c, :r)
     ON CONFLICT DO NOTHING"
);
$found = 0;

foreach ($dupCandidates as $pair) {
    // Confirm via Llama
    $prompt = "Estas duas lojas sao a mesma?\nA: {$pair['name_a']} ({$pair['city']})\nB: {$pair['name_b']} ({$pair['city']})\n\n" .
              "Responda APENAS JSON: " . '{"same":true_ou_false,"confidence":0-100,"reason":"breve"}';
    $reply = ClaudeClient::text($prompt, 'Detector de duplicatas. JSON apenas.', 200);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['same'])) continue;
    if (($parsed['confidence'] ?? 0) < 70) continue;

    $ins->execute([
        ':a' => $pair['id_a'],
        ':b' => $pair['id_b'],
        ':c' => (float)$parsed['confidence'] / 100,
        ':r' => $parsed['reason'] ?? '',
    ]);
    $found++;
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[detect-dupes] found {$found} duplicate candidates\n");
