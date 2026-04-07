<?php
/**
 * Cron: Generate SEO meta tags for stores and products.
 *
 * Stored in om_seo_meta table; consumed by the public store page renderer.
 *
 * Schedule: 0 4 * * *  (4 AM daily, batch of 50)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS om_seo_meta (
        id BIGSERIAL PRIMARY KEY,
        entity_type VARCHAR(20) NOT NULL,
        entity_id INTEGER NOT NULL,
        title VARCHAR(160),
        description VARCHAR(300),
        keywords TEXT,
        og_title VARCHAR(160),
        og_description VARCHAR(300),
        generated_at TIMESTAMPTZ DEFAULT NOW(),
        UNIQUE(entity_type, entity_id)
    )");
} catch (Exception $e) {}

// Process stores without SEO meta yet
$stmt = $db->query(
    "SELECT p.partner_id, p.name, p.categoria, p.address_city, p.description
     FROM om_market_partners p
     LEFT JOIN om_seo_meta m ON m.entity_type = 'partner' AND m.entity_id = p.partner_id
     WHERE p.status::text = '1' AND m.id IS NULL
     LIMIT 50"
);
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

$processed = 0;
$ins = $db->prepare(
    "INSERT INTO om_seo_meta (entity_type, entity_id, title, description, keywords, og_title, og_description)
     VALUES ('partner', :id, :t, :d, :k, :ot, :od)
     ON CONFLICT (entity_type, entity_id) DO UPDATE SET
       title = EXCLUDED.title, description = EXCLUDED.description, keywords = EXCLUDED.keywords,
       og_title = EXCLUDED.og_title, og_description = EXCLUDED.og_description, generated_at = NOW()"
);

foreach ($partners as $p) {
    $prompt = "Gere meta tags SEO em pt-BR para esta loja:\n" .
              "Nome: {$p['name']}\nCategoria: {$p['categoria']}\nCidade: {$p['address_city']}\n" .
              "Descricao: " . ($p['description'] ?? '-') . "\n\n" .
              "Responda APENAS JSON: " .
              '{"title":"max 60 chars com cidade","description":"max 160 chars vendendo a loja",' .
              '"keywords":"5-10 palavras chave separadas por virgula",' .
              '"og_title":"para Facebook/Instagram max 70 chars",' .
              '"og_description":"chamativo max 200 chars"}';

    $reply = ClaudeClient::text($prompt, 'Voce eh especialista SEO local. JSON apenas.', 400);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) continue;

    $ins->execute([
        ':id' => $p['partner_id'],
        ':t' => mb_substr($parsed['title'] ?? $p['name'], 0, 160),
        ':d' => mb_substr($parsed['description'] ?? '', 0, 300),
        ':k' => mb_substr($parsed['keywords'] ?? '', 0, 1000),
        ':ot' => mb_substr($parsed['og_title'] ?? '', 0, 160),
        ':od' => mb_substr($parsed['og_description'] ?? '', 0, 300),
    ]);
    $processed++;
    usleep(150000);
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[meta-seo] generated {$processed}/" . count($partners) . "\n");
