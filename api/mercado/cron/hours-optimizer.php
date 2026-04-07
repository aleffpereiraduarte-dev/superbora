<?php
/**
 * Cron: Hours optimizer
 *
 * For each partner, looks at the demand pattern by hour-of-day and
 * suggests optimal opening/closing hours. Stores in om_hours_suggestions.
 *
 * Schedule: 0 7 * * 1  (Mondays 7 AM)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS om_hours_suggestions (
        id BIGSERIAL PRIMARY KEY,
        partner_id INTEGER NOT NULL,
        current_open TIME,
        current_close TIME,
        suggested_open TIME,
        suggested_close TIME,
        reason TEXT,
        confidence INTEGER,
        created_at TIMESTAMPTZ DEFAULT NOW()
    )");
} catch (Exception $e) {}

$partners = $db->query(
    "SELECT partner_id, name, opens_at, closes_at FROM om_market_partners
     WHERE status::text = '1' AND opens_at IS NOT NULL AND closes_at IS NOT NULL
     LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);

$ins = $db->prepare(
    "INSERT INTO om_hours_suggestions (partner_id, current_open, current_close, suggested_open, suggested_close, reason, confidence)
     VALUES (:pid, :co, :cc, :so, :sc, :r, :conf)"
);
$processed = 0;

foreach ($partners as $p) {
    // Hourly demand histogram
    $stmt = $db->prepare(
        "SELECT EXTRACT(HOUR FROM created_at)::int AS hr, COUNT(*) AS qty
         FROM om_market_orders
         WHERE partner_id = :pid AND created_at > NOW() - INTERVAL '60 days'
         GROUP BY hr ORDER BY hr"
    );
    $stmt->execute([':pid' => $p['partner_id']]);
    $histogram = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if (count($histogram) < 5) continue;

    $hist = [];
    for ($h = 0; $h < 24; $h++) $hist[$h] = (int)($histogram[$h] ?? 0);

    $prompt = "Loja {$p['name']} (atual: {$p['opens_at']} - {$p['closes_at']}). " .
              "Distribuicao de pedidos por hora (ultimos 60 dias): " . json_encode($hist) . ". " .
              "Sugira horario otimo. Responda APENAS JSON: " .
              '{"suggested_open":"HH:MM","suggested_close":"HH:MM","reason":"max 200 chars","confidence":0-100}';

    $reply = ClaudeClient::text($prompt, 'Voce eh consultor de operacoes. Pratico e baseado em dados.', 250);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) continue;

    $so = $parsed['suggested_open'] ?? null;
    $sc = $parsed['suggested_close'] ?? null;
    if (!preg_match('/^\d{2}:\d{2}$/', $so ?? '') || !preg_match('/^\d{2}:\d{2}$/', $sc ?? '')) continue;

    try {
        $ins->execute([
            ':pid' => $p['partner_id'],
            ':co' => $p['opens_at'],
            ':cc' => $p['closes_at'],
            ':so' => $so,
            ':sc' => $sc,
            ':r' => $parsed['reason'] ?? '',
            ':conf' => (int)($parsed['confidence'] ?? 70),
        ]);
        $processed++;
    } catch (Exception $e) {}
    usleep(200000);
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[hours-optimizer] processed {$processed}/" . count($partners) . "\n");
