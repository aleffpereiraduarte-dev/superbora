<?php
/**
 * Cron: Daily KPI digest
 *
 * Reads yesterday's KPIs from the DB, asks Llama to write a friendly summary
 * in pt-BR, and sends it via WhatsApp to the configured admin numbers.
 *
 * Schedule: 0 9 * * *  (every day at 9 AM)
 * Run manually: php cron/daily-kpi-digest.php
 *
 * Required env (in .env):
 *   ADMIN_WHATSAPP_NUMBERS=5511999999999,5511888888888
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();
$yesterday = date('Y-m-d', strtotime('-1 day'));

// Pull KPIs
$kpis = [];

$stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE DATE(created_at) = :d");
$stmt->execute([':d' => $yesterday]);
$kpis['total_orders'] = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COALESCE(SUM(total),0) FROM om_market_orders WHERE DATE(created_at) = :d AND status NOT IN ('cancelado','recusado')");
$stmt->execute([':d' => $yesterday]);
$kpis['gmv'] = (float)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE DATE(created_at) = :d AND status IN ('cancelado','recusado')");
$stmt->execute([':d' => $yesterday]);
$kpis['cancelled'] = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(DISTINCT customer_id) FROM om_market_orders WHERE DATE(created_at) = :d");
$stmt->execute([':d' => $yesterday]);
$kpis['active_customers'] = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM om_customers WHERE DATE(created_at) = :d");
try {
    $stmt->execute([':d' => $yesterday]);
    $kpis['new_customers'] = (int)$stmt->fetchColumn();
} catch (Exception $e) { $kpis['new_customers'] = 0; }

// Top 3 partners by orders
$stmt = $db->prepare(
    "SELECT p.name, COUNT(o.order_id) AS qty, SUM(o.total) AS gmv
     FROM om_market_orders o
     JOIN om_market_partners p ON o.partner_id = p.partner_id
     WHERE DATE(o.created_at) = :d AND o.status NOT IN ('cancelado','recusado')
     GROUP BY p.partner_id, p.name
     ORDER BY qty DESC LIMIT 3"
);
$stmt->execute([':d' => $yesterday]);
$kpis['top_partners'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compare to day-before-yesterday
$dby = date('Y-m-d', strtotime('-2 days'));
$stmt = $db->prepare("SELECT COUNT(*), COALESCE(SUM(total),0) FROM om_market_orders WHERE DATE(created_at) = :d AND status NOT IN ('cancelado','recusado')");
$stmt->execute([':d' => $dby]);
$prev = $stmt->fetch(PDO::FETCH_NUM);
$kpis['prev_orders'] = (int)$prev[0];
$kpis['prev_gmv'] = (float)$prev[1];

$kpis['orders_delta_pct'] = $kpis['prev_orders'] > 0
    ? round((($kpis['total_orders'] - $kpis['prev_orders']) / $kpis['prev_orders']) * 100, 1)
    : 0;
$kpis['gmv_delta_pct'] = $kpis['prev_gmv'] > 0
    ? round((($kpis['gmv'] - $kpis['prev_gmv']) / $kpis['prev_gmv']) * 100, 1)
    : 0;

// Llama generates the prose
$kpiJson = json_encode($kpis, JSON_UNESCAPED_UNICODE);
$prompt = "KPIs do dia {$yesterday} do SuperBora delivery:\n\n" . $kpiJson . "\n\n" .
          "Escreva um resumo CURTO (max 600 chars) em portugues do Brasil, formato WhatsApp. " .
          "Use emojis. Destaque o que esta bom e o que precisa atencao. Termine com 1 acao recomendada.";

$message = ClaudeClient::text(
    $prompt,
    'Voce eh o COO virtual do SuperBora. Direto, util, sem firula.',
    600
);

if (!$message) {
    fwrite(STDERR, "[daily-kpi-digest] AI failed\n");
    exit(1);
}

// Persist the digest
try {
    $db->prepare("INSERT INTO om_daily_digests (date, kpis_json, message, created_at) VALUES (:d, :k, :m, NOW())")
       ->execute([':d' => $yesterday, ':k' => $kpiJson, ':m' => $message]);
} catch (Exception $e) {
    // Table may not exist; create it
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS om_daily_digests (
            id BIGSERIAL PRIMARY KEY,
            date DATE NOT NULL,
            kpis_json JSONB,
            message TEXT,
            sent_to TEXT,
            created_at TIMESTAMPTZ DEFAULT NOW()
        )");
        $db->prepare("INSERT INTO om_daily_digests (date, kpis_json, message) VALUES (:d, :k, :m)")
           ->execute([':d' => $yesterday, ':k' => $kpiJson, ':m' => $message]);
    } catch (Exception $e2) { /* ignore */ }
}

// Send via WhatsApp helper if available
$numbers = $_ENV['ADMIN_WHATSAPP_NUMBERS'] ?? getenv('ADMIN_WHATSAPP_NUMBERS') ?: '';
if ($numbers && file_exists(__DIR__ . '/../helpers/zapi-whatsapp.php')) {
    require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
    foreach (explode(',', $numbers) as $num) {
        $num = trim($num);
        if (!$num) continue;
        if (function_exists('sendWhatsappMessage')) {
            try { sendWhatsappMessage($num, $message); } catch (Exception $e) { error_log('[kpi-digest] wa: ' . $e->getMessage()); }
        }
    }
}

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "=== Daily KPI digest for {$yesterday} ===\n{$message}\n");
}
