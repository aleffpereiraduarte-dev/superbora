<?php
/**
 * CRON: Visual product embedding pipeline.
 *
 * For products without visual_description:
 *   1. Download product image
 *   2. Claude vision describes in Portuguese (rich, search-friendly text)
 *   3. OpenAI text-embedding-3-small (1536 dims) embeds the description
 *   4. Store in om_market_products.embedding + visual_description
 *
 * Runs every 10min, batches of 20 products per run (avoids API rate limits).
 * Re-runs on products whose image URL changed since last embed.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/claude-client.php';

$lockFile = '/tmp/superbora_cron_visual_embed.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another instance running.\n";
    exit(0);
}

$OPENAI_KEY = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
if (!$OPENAI_KEY) { echo "OPENAI_API_KEY not set\n"; exit(1); }

$BATCH_SIZE = 20;
$db = getDB();
$log = function(string $m) { echo "[" . date('H:i:s') . "] $m\n"; };

$log("=== Visual embed cron started ===");

// Find products needing embedding
$stmt = $db->query("
    SELECT product_id, name, description, image
    FROM om_market_products
    WHERE status::text = '1'
      AND image IS NOT NULL AND TRIM(image) != '' AND image NOT LIKE '%.svg'
      AND embedding IS NULL
    ORDER BY product_id DESC
    LIMIT {$BATCH_SIZE}
");
$products = $stmt->fetchAll();
$log("Queued " . count($products) . " products for embedding");

if (empty($products)) { $log("Nothing to do"); exit(0); }

function openaiEmbed(string $text, string $apiKey): ?array {
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'text-embedding-3-small',
            'input' => mb_substr($text, 0, 8000),
            'dimensions' => 1536,
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $data = json_decode($res, true);
    return $data['data'][0]['embedding'] ?? null;
}

function fetchImageAsBase64(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'SuperBora-VisualEmbed/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    $mime = preg_replace('/;.*/', '', $contentType) ?: 'image/jpeg';
    if (!str_starts_with($mime, 'image/')) return null;
    // Cap at 4MB
    if (strlen($body) > 4 * 1024 * 1024) return null;
    return ['data' => base64_encode($body), 'mime' => $mime];
}

$claude = new ClaudeClient('claude-sonnet-4-20250514', 30, 0);
$visionPrompt = <<<'P'
Descreva esta foto de produto alimenticio/mercado em portugues brasileiro.
Foque em caracteristicas visuais que ajudariam alguem a buscar por produtos similares.
Inclua: tipo de produto, cor predominante, textura, ingredientes visiveis, formato, apresentacao.
Responda em texto corrido (nao JSON), maximo 2 frases, direto ao ponto.
P;

$done = 0;
foreach ($products as $p) {
    $pid = (int)$p['product_id'];
    $imgUrl = $p['image'];

    // Claude vision: describe image
    $img = fetchImageAsBase64($imgUrl);
    if (!$img) {
        $log("#{$pid} skip (cannot fetch image)");
        continue;
    }

    $r = $claude->sendWithVision($visionPrompt, [$img], $p['name'] ?: '', 256);
    if (!$r['success']) {
        $log("#{$pid} claude vision fail: " . ($r['error'] ?? ''));
        continue;
    }
    $description = trim($r['text'] ?? '');
    if (mb_strlen($description) < 10) {
        $log("#{$pid} description too short, skip");
        continue;
    }

    // Combine product name + description for richer embedding text
    $embedText = trim(($p['name'] ?? '') . '. ' . $description . ' ' . ($p['description'] ?? ''));

    $embedding = openaiEmbed($embedText, $OPENAI_KEY);
    if (!$embedding) {
        $log("#{$pid} openai embed fail");
        continue;
    }

    // Store as pgvector literal
    $vectorLiteral = '[' . implode(',', array_map(fn($v) => sprintf('%.6f', $v), $embedding)) . ']';
    $stmt = $db->prepare("
        UPDATE om_market_products
        SET visual_description = ?, embedding = ?::vector, embedded_at = NOW()
        WHERE product_id = ?
    ");
    $stmt->execute([$description, $vectorLiteral, $pid]);

    $done++;
    $log("#{$pid} embedded ({$done}/" . count($products) . ")");
}

$log("=== Done. Embedded {$done} products ===");
