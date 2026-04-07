<?php
/**
 * Cron: Anomaly detector
 *
 * Compares the last hour of orders against the average of the same hour
 * over the past 7 days. Flags significant drops (>50%) or spikes (>200%).
 * Generates an explained alert via Llama and persists it.
 *
 * Schedule: every 30 min during business hours
 * Run manually: php cron/anomaly-detector.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();
$now = new DateTime();
$hour = (int)$now->format('H');

// Skip overnight when volume is naturally low
if ($hour < 9 || $hour > 23) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[anomaly] outside business hours\n");
    exit(0);
}

// Last full hour
$lastHourStmt = $db->prepare(
    "SELECT COUNT(*) AS qty, COALESCE(SUM(total),0) AS gmv,
            COUNT(*) FILTER (WHERE status IN ('cancelado','recusado')) AS cancelled
     FROM om_market_orders
     WHERE created_at >= date_trunc('hour', NOW()) - INTERVAL '1 hour'
       AND created_at < date_trunc('hour', NOW())"
);
$lastHourStmt->execute();
$last = $lastHourStmt->fetch(PDO::FETCH_ASSOC);

// Same hour for the last 7 days (baseline)
$baselineStmt = $db->prepare(
    "SELECT AVG(qty) AS avg_qty, AVG(gmv) AS avg_gmv
     FROM (
         SELECT DATE_TRUNC('hour', created_at) AS h,
                COUNT(*) AS qty,
                SUM(total) AS gmv
         FROM om_market_orders
         WHERE created_at >= NOW() - INTERVAL '8 days'
           AND created_at < NOW() - INTERVAL '1 day'
           AND EXTRACT(HOUR FROM created_at) = :h
         GROUP BY h
     ) sub"
);
$baselineStmt->execute([':h' => $hour - 1]);
$baseline = $baselineStmt->fetch(PDO::FETCH_ASSOC);

$avgQty = (float)($baseline['avg_qty'] ?? 0);
$lastQty = (int)($last['qty'] ?? 0);

if ($avgQty < 1) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[anomaly] insufficient baseline\n");
    exit(0);
}

$ratio = $lastQty / $avgQty;
$dropPct = round((1 - $ratio) * 100, 1);
$spikePct = round(($ratio - 1) * 100, 1);

$alertType = null;
if ($ratio < 0.5) $alertType = 'drop';
elseif ($ratio > 2.0) $alertType = 'spike';
else {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[anomaly] no anomaly (ratio={$ratio})\n");
    exit(0);
}

$prompt = "ALERTA " . strtoupper($alertType) . " no SuperBora delivery!\n\n" .
          "Hora: " . ($hour - 1) . "h (ultima hora cheia)\n" .
          "Pedidos esta hora: {$lastQty}\n" .
          "Media historica (mesma hora, ultimos 7 dias): " . round($avgQty, 1) . "\n" .
          "Cancelamentos: {$last['cancelled']}\n" .
          "GMV ultima hora: R$ " . number_format((float)$last['gmv'], 2, ',', '.') . "\n\n" .
          "Em pt-BR, escreva um alerta CURTO (max 400 chars) com: " .
          "1) frase principal com emoji, 2) possiveis causas (1-2), 3) acao recomendada. " .
          "Tom: " . ($alertType === 'drop' ? 'preocupado mas pratico' : 'oportunidade!') . ".";

$message = ClaudeClient::text($prompt, 'Voce eh o SOC do delivery. Detecta anomalias e age rapido.', 400);

// Persist
try {
    $db->prepare(
        "INSERT INTO om_anomaly_alerts (alert_type, hour_iso, last_qty, baseline_avg, ratio, message, created_at)
         VALUES (:t, :h, :lq, :ba, :r, :m, NOW())"
    )->execute([
        ':t' => $alertType,
        ':h' => date('Y-m-d H:00:00', strtotime('-1 hour')),
        ':lq' => $lastQty,
        ':ba' => $avgQty,
        ':r' => $ratio,
        ':m' => $message ?? '',
    ]);
} catch (Exception $e) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS om_anomaly_alerts (
            id BIGSERIAL PRIMARY KEY,
            alert_type VARCHAR(20) NOT NULL,
            hour_iso TIMESTAMP NOT NULL,
            last_qty INTEGER,
            baseline_avg DECIMAL(10,2),
            ratio DECIMAL(10,4),
            message TEXT,
            sent_to TEXT,
            created_at TIMESTAMPTZ DEFAULT NOW()
        )");
        $db->prepare("INSERT INTO om_anomaly_alerts (alert_type, hour_iso, last_qty, baseline_avg, ratio, message) VALUES (:t, :h, :lq, :ba, :r, :m)")
           ->execute([':t' => $alertType, ':h' => date('Y-m-d H:00:00', strtotime('-1 hour')), ':lq' => $lastQty, ':ba' => $avgQty, ':r' => $ratio, ':m' => $message ?? '']);
    } catch (Exception $e2) {}
}

// Send to admin WhatsApp
$numbers = $_ENV['ADMIN_WHATSAPP_NUMBERS'] ?? getenv('ADMIN_WHATSAPP_NUMBERS') ?: '';
if ($numbers && $message && file_exists(__DIR__ . '/../helpers/zapi-whatsapp.php')) {
    require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
    foreach (explode(',', $numbers) as $num) {
        $num = trim($num);
        if ($num && function_exists('sendWhatsappMessage')) {
            try { sendWhatsappMessage($num, $message); } catch (Exception $e) {}
        }
    }
}

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "[anomaly] {$alertType} detected: {$message}\n");
}
