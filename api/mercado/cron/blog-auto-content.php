<?php
/**
 * Cron: Blog auto content generator
 *
 * Generates 1 SEO blog post per run, based on a topic queue or trending products.
 * Saved to om_blog_posts (auto-published or draft).
 *
 * Schedule: 0 10 * * *  (10 AM daily)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS om_blog_posts (
        id BIGSERIAL PRIMARY KEY,
        slug VARCHAR(200) UNIQUE NOT NULL,
        title VARCHAR(200) NOT NULL,
        meta_description VARCHAR(300),
        body_html TEXT,
        category VARCHAR(50),
        keywords TEXT,
        status VARCHAR(20) DEFAULT 'draft',
        author VARCHAR(50) DEFAULT 'ai',
        created_at TIMESTAMPTZ DEFAULT NOW()
    )");
} catch (Exception $e) {}

// Pick a trending product to write about
try {
    $stmt = $db->query(
        "SELECT pr.product_id, pr.name, pr.description, pa.categoria, COUNT(*) AS qty
         FROM om_market_order_items oi
         JOIN om_market_orders o ON o.order_id = oi.order_id
         JOIN om_market_products pr ON pr.product_id = oi.product_id
         JOIN om_market_partners pa ON pa.partner_id = pr.partner_id
         WHERE o.created_at > NOW() - INTERVAL '14 days'
           AND o.status NOT IN ('cancelado','recusado')
         GROUP BY pr.product_id, pr.name, pr.description, pa.categoria
         ORDER BY qty DESC LIMIT 5"
    );
    $trending = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    fwrite(STDERR, "[blog] " . $e->getMessage() . "\n");
    exit(1);
}

if (empty($trending)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[blog] no trending products\n");
    exit(0);
}

// Pick the one we haven't written about yet
$picked = null;
foreach ($trending as $p) {
    $check = $db->prepare("SELECT 1 FROM om_blog_posts WHERE slug LIKE :s LIMIT 1");
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $p['name']));
    $check->execute([':s' => $slug . '%']);
    if (!$check->fetch()) { $picked = $p; break; }
}
if (!$picked) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[blog] all trending already covered\n");
    exit(0);
}

$prompt = "Escreva um post de blog SEO em pt-BR sobre o produto {$picked['name']} ({$picked['categoria']}). " .
          "Tom: amigavel, util, informativo. Inclua: introducao, beneficios, dicas de consumo, " .
          "como pedir pelo SuperBora, fechamento. " .
          "Use HTML simples (h2, p, ul). Max 1500 palavras. " .
          "Responda APENAS JSON: " .
          '{"title":"chamativo max 70 chars","slug":"slug-com-hifens","meta_description":"max 160 chars",' .
          '"keywords":"5-10 separadas por virgula","body_html":"HTML completo do post","category":"alimentacao|saude|dicas|receitas"}';

$reply = ClaudeClient::text($prompt, 'Voce eh redator SEO especializado em food/delivery. Escrita natural, nao robotica.', 4000);
$parsed = ClaudeClient::parseJson($reply ?: '');
if (!$parsed || empty($parsed['title']) || empty($parsed['body_html'])) {
    fwrite(STDERR, "[blog] AI did not return valid post\n");
    exit(1);
}

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower($parsed['slug'] ?? ''));
if ($slug === '') $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $parsed['title']));
$slug = trim($slug, '-') . '-' . substr(md5($slug), 0, 6);

try {
    $stmt = $db->prepare(
        "INSERT INTO om_blog_posts (slug, title, meta_description, body_html, category, keywords, status)
         VALUES (:s, :t, :m, :b, :c, :k, 'draft')"
    );
    $stmt->execute([
        ':s' => $slug,
        ':t' => mb_substr($parsed['title'], 0, 200),
        ':m' => mb_substr($parsed['meta_description'] ?? '', 0, 300),
        ':b' => $parsed['body_html'],
        ':c' => $parsed['category'] ?? 'dicas',
        ':k' => $parsed['keywords'] ?? '',
    ]);
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[blog] published draft: {$slug}\n");
} catch (Exception $e) {
    fwrite(STDERR, "[blog] " . $e->getMessage() . "\n");
}
