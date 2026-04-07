<?php
/**
 * Cron: Address fixer
 *
 * Lighter version of normalize-addresses that focuses on flagging suspicious
 * or unusable addresses for manual review (no AI calls — pure heuristics).
 *
 * Schedule: 0 8 * * *  (8 AM daily, processes all flagged-null addresses)
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();

try {
    $db->exec("ALTER TABLE om_customer_addresses ADD COLUMN IF NOT EXISTS quality_flag VARCHAR(20)");
    $db->exec("ALTER TABLE om_customer_addresses ADD COLUMN IF NOT EXISTS quality_reason TEXT");
} catch (Exception $e) {}

try {
    $stmt = $db->query(
        "SELECT id, street, number, neighborhood, city, state, cep
         FROM om_customer_addresses
         WHERE quality_flag IS NULL
         LIMIT 500"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    fwrite(STDERR, "[address-fixer] " . $e->getMessage() . "\n");
    exit(0);
}

$upd = $db->prepare("UPDATE om_customer_addresses SET quality_flag = :f, quality_reason = :r WHERE id = :id");
$flagged = ['ok' => 0, 'incomplete' => 0, 'suspicious' => 0, 'invalid' => 0];

foreach ($rows as $a) {
    $reasons = [];
    $flag = 'ok';

    // Required fields
    foreach (['street', 'city', 'state'] as $req) {
        if (empty(trim($a[$req] ?? ''))) { $reasons[] = "{$req} vazio"; $flag = 'incomplete'; }
    }

    // Street too short
    if (strlen(trim($a['street'] ?? '')) < 5) { $reasons[] = 'street muito curto'; $flag = 'incomplete'; }

    // Number invalid
    if (!empty($a['number']) && !preg_match('/^\d{1,6}([a-zA-Z\s\-]+)?$/', trim($a['number']))) {
        $reasons[] = 'numero formato invalido';
        if ($flag === 'ok') $flag = 'suspicious';
    }

    // CEP format
    if (!empty($a['cep'])) {
        $cep = preg_replace('/\D/', '', $a['cep']);
        if (strlen($cep) !== 8) {
            $reasons[] = 'CEP formato invalido';
            $flag = 'invalid';
        }
    }

    // State must be 2 chars
    $state = trim($a['state'] ?? '');
    if ($state && strlen($state) !== 2) {
        $reasons[] = 'state nao eh UF (2 letras)';
        if ($flag === 'ok') $flag = 'suspicious';
    }

    // Test/fake patterns
    $combined = strtolower(($a['street'] ?? '') . ' ' . ($a['city'] ?? ''));
    $fakeWords = ['teste', 'test ', 'asdf', 'aaaa', '1234', 'xyz', 'lorem', 'ipsum'];
    foreach ($fakeWords as $f) {
        if (strpos($combined, $f) !== false) { $reasons[] = "contem '{$f}'"; $flag = 'suspicious'; break; }
    }

    $upd->execute([
        ':f' => $flag,
        ':r' => empty($reasons) ? null : implode('; ', $reasons),
        ':id' => $a['id'],
    ]);
    $flagged[$flag]++;
}

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "[address-fixer] processed " . count($rows) . ": " . json_encode($flagged) . "\n");
}
