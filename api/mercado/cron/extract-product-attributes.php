<?php
/**
 * Cron: Extract structured attributes from product names+descriptions in bulk.
 * Llama parses each product and stores brand/size/flavor/weight/etc as JSONB.
 *
 * Schedule: hourly  (processes 100 products per run)
 * Run: php cron/extract-product-attributes.php [batch_size]
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();
$batch = (int)($argv[1] ?? 100);

try {
    $db->exec("ALTER TABLE om_market_products ADD COLUMN IF NOT EXISTS attributes_json JSONB");
} catch (Exception $e) {}

$stmt = $db->prepare(
    "SELECT product_id, name, COALESCE(description,'') AS description, COALESCE(brand,'') AS brand
     FROM om_market_products
     WHERE attributes_json IS NULL AND status::text = '1' AND name <> ''
     LIMIT :lim"
);
$stmt->bindValue(':lim', $batch, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($products)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[extract-attrs] no products to process\n");
    exit(0);
}

$processed = 0;
$updateStmt = $db->prepare("UPDATE om_market_products SET attributes_json = :a::jsonb WHERE product_id = :id");

foreach ($products as $p) {
    $prompt = "Produto: {$p['name']}\nMarca: {$p['brand']}\nDescricao: {$p['description']}\n\n" .
              "Extraia atributos estruturados. Responda APENAS JSON: " .
              '{"brand":"","flavor":"","size":"","weight":"","unit":"un|kg|g|ml|l","color":"","' .
              'category_inferred":"","keywords":["","",""],"dietary":["sem-lactose","vegano",""]}';

    $reply = ClaudeClient::text($prompt, 'Extrator de atributos. JSON apenas, valores em pt-BR.', 300);
    if (!$reply) continue;

    $parsed = ClaudeClient::parseJson($reply);
    if (!is_array($parsed)) {
        $updateStmt->execute([':a' => '{}', ':id' => $p['product_id']]);
        continue;
    }

    $updateStmt->execute([':a' => json_encode($parsed, JSON_UNESCAPED_UNICODE), ':id' => $p['product_id']]);
    $processed++;
    usleep(100000); // 0.1s throttle
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[extract-attrs] processed {$processed}/" . count($products) . "\n");
