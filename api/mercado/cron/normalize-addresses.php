<?php
/**
 * Cron: Normalize customer addresses (typos, abbreviations, formatting).
 *
 * Schedule: 0 6 * * *  (6 AM daily, batch of 100)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

try {
    $db->exec("ALTER TABLE om_customer_addresses ADD COLUMN IF NOT EXISTS normalized_at TIMESTAMPTZ");
} catch (Exception $e) {}

try {
    $stmt = $db->query(
        "SELECT id, street, number, complement, neighborhood, city, state
         FROM om_customer_addresses
         WHERE normalized_at IS NULL
         LIMIT 100"
    );
    $addrs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    fwrite(STDERR, "[normalize-addr] schema error: " . $e->getMessage() . "\n");
    exit(0);
}

if (empty($addrs)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[normalize-addr] nothing to process\n");
    exit(0);
}

$upd = $db->prepare(
    "UPDATE om_customer_addresses
     SET street = :s, neighborhood = :n, city = :c, state = :st, normalized_at = NOW()
     WHERE id = :id"
);
$processed = 0;

foreach ($addrs as $a) {
    $original = json_encode([
        'street' => $a['street'], 'neighborhood' => $a['neighborhood'],
        'city' => $a['city'], 'state' => $a['state'],
    ], JSON_UNESCAPED_UNICODE);

    $prompt = "Normalize este endereco brasileiro: {$original}\n\n" .
              "Corrija typos, expanda abreviacoes (R. -> Rua, Av. -> Avenida), padronize capitalizacao. " .
              "Use estado UF (2 letras). Cidade com acentos corretos. " .
              "Responda APENAS JSON com os mesmos campos: " .
              '{"street":"","neighborhood":"","city":"","state":""}';

    $reply = ClaudeClient::text($prompt, 'Especialista em enderecos brasileiros. JSON apenas.', 250);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) continue;

    $upd->execute([
        ':s' => $parsed['street'] ?? $a['street'],
        ':n' => $parsed['neighborhood'] ?? $a['neighborhood'],
        ':c' => $parsed['city'] ?? $a['city'],
        ':st' => mb_strtoupper(mb_substr($parsed['state'] ?? $a['state'], 0, 2)),
        ':id' => $a['id'],
    ]);
    $processed++;
    usleep(80000);
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[normalize-addr] cleaned {$processed}/" . count($addrs) . "\n");
