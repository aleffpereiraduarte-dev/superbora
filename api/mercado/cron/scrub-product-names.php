<?php
/**
 * Cron: Scrub product names — fix typos, padronize capitalization,
 * remove sale tags from name (e.g. "PROMOCAO!" prefix), trim repetitions.
 *
 * Schedule: 0 7 * * *  (7 AM daily, batch of 200)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

try {
    $db->exec("ALTER TABLE om_market_products ADD COLUMN IF NOT EXISTS name_scrubbed_at TIMESTAMPTZ");
} catch (Exception $e) {}

$stmt = $db->query(
    "SELECT product_id, name FROM om_market_products
     WHERE status::text = '1' AND name <> '' AND name_scrubbed_at IS NULL
     LIMIT 200"
);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($products)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[scrub-names] nothing to process\n");
    exit(0);
}

// Process in groups of 10 to save AI calls
$upd = $db->prepare("UPDATE om_market_products SET name = :n, name_scrubbed_at = NOW() WHERE product_id = :id");
$processed = 0;
$skipped = 0;

foreach (array_chunk($products, 10) as $chunk) {
    $list = array_map(fn($p) => "{$p['product_id']}|{$p['name']}", $chunk);
    $prompt = "Limpe e padronize estes nomes de produtos brasileiros. " .
              "Regras: capitalize cada palavra principal, remova prefixos promocionais ('PROMO!', 'NOVO!'), " .
              "corrija typos obvios, mantenha unidades (500ml, 1kg). " .
              "Mantenha o ID. Responda APENAS JSON: " .
              '{"products":[{"id":123,"name":"Nome Limpo"}]}' . "\n\n" .
              "Produtos:\n" . implode("\n", $list);

    $reply = ClaudeClient::text($prompt, 'Padronizador de catalogo. JSON apenas, mesma quantidade de items.', 1000);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['products'])) {
        // Mark all in this chunk as processed (skip) to not loop forever
        foreach ($chunk as $c) {
            $db->prepare("UPDATE om_market_products SET name_scrubbed_at = NOW() WHERE product_id = ?")->execute([$c['product_id']]);
            $skipped++;
        }
        continue;
    }

    foreach ($parsed['products'] as $clean) {
        $id = (int)($clean['id'] ?? 0);
        $newName = trim($clean['name'] ?? '');
        if (!$id || $newName === '' || mb_strlen($newName) > 200) continue;
        $upd->execute([':n' => $newName, ':id' => $id]);
        $processed++;
    }
    usleep(150000);
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[scrub-names] cleaned {$processed}, skipped {$skipped}\n");
