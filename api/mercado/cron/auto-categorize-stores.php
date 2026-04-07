<?php
/**
 * Cron: Auto-categorize stores based on their actual menu.
 *
 * For each store, samples up to 30 products and asks Llama to pick the most
 * appropriate `categoria` from a fixed taxonomy. Updates the row.
 *
 * Schedule: 0 3 * * 0  (Sundays 3 AM)
 * Run: php cron/auto-categorize-stores.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

try {
    $db->exec("ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS categoria_ai_at TIMESTAMPTZ");
} catch (Exception $e) {}

$validCats = ['restaurante', 'lanchonete', 'pizzaria', 'hamburgueria', 'sushi', 'acai',
              'mercado', 'supermercado', 'mercearia', 'hortifruti',
              'farmacia', 'pet', 'bebidas', 'doces', 'cafeteria',
              'padaria', 'churrascaria', 'comida_caseira', 'natural', 'outros'];

// Process partners not yet categorized via AI in last 30 days
$stmt = $db->prepare(
    "SELECT partner_id, name, COALESCE(categoria,'') AS categoria
     FROM om_market_partners
     WHERE status::text = '1'
       AND (categoria_ai_at IS NULL OR categoria_ai_at < NOW() - INTERVAL '30 days')
     LIMIT 50"
);
$stmt->execute();
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($partners)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[auto-cat] no partners to process\n");
    exit(0);
}

$processed = 0;
$prodStmt = $db->prepare("SELECT name FROM om_market_products WHERE partner_id = :pid AND status::text = '1' LIMIT 30");
$updStmt = $db->prepare("UPDATE om_market_partners SET categoria = :c, categoria_ai_at = NOW() WHERE partner_id = :pid");

foreach ($partners as $p) {
    $prodStmt->execute([':pid' => $p['partner_id']]);
    $items = array_column($prodStmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (count($items) < 3) continue;

    $prompt = "Loja: {$p['name']}\nCategoria atual: {$p['categoria']}\n" .
              "Amostra do cardapio (" . count($items) . " itens):\n- " . implode("\n- ", array_slice($items, 0, 30)) . "\n\n" .
              "Escolha a categoria que melhor descreve a loja. Opcoes validas: " . implode(', ', $validCats) . "\n" .
              "Responda APENAS JSON: " . '{"categoria":"valor_da_lista_acima","confianca":0-100}';

    $reply = ClaudeClient::text($prompt, 'Classificador de lojas. JSON apenas, use SOMENTE valores da lista.', 200);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || !in_array($parsed['categoria'] ?? '', $validCats, true)) continue;
    if (($parsed['confianca'] ?? 0) < 60) continue;

    $updStmt->execute([':c' => $parsed['categoria'], ':pid' => $p['partner_id']]);
    $processed++;
    usleep(150000);
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[auto-cat] reclassified {$processed}/" . count($partners) . "\n");
