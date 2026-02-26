<?php
/**
 * Partner Daily Digest — Cron at 9:00 AM
 * Sends yesterday's metrics via WhatsApp and push notification
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
require_once __DIR__ . '/../helpers/NotificationSender.php';

// Cron auth guard
$cronKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$expectedKey = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($expectedKey) || !hash_equals($expectedKey, $cronKey)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$yesterday = date('Y-m-d', strtotime('-1 day'));
$lastWeekDay = date('Y-m-d', strtotime('-8 days'));
$processed = 0;

$stmt = $db->query("
    SELECT p.partner_id, COALESCE(p.trade_name, p.name) as business_name, p.phone
    FROM om_market_partners p
    WHERE p.status::text = '1'
    ORDER BY p.partner_id
");

while ($partner = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $pid = $partner['partner_id'];

    // Check if already sent today
    $check = $db->prepare("SELECT 1 FROM om_partner_digest_log WHERE partner_id = ? AND digest_date = ?");
    $check->execute([$pid, $yesterday]);
    if ($check->fetch()) continue;

    // Yesterday's metrics
    $metrics = $db->prepare("
        SELECT COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue,
               COALESCE(AVG(total), 0) as avg_ticket,
               COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelled
        FROM om_market_orders
        WHERE partner_id = ? AND DATE(created_at) = ?
    ");
    $metrics->execute([$pid, $yesterday]);
    $m = $metrics->fetch(PDO::FETCH_ASSOC);

    // Last week same day for comparison
    $lastWeek = $db->prepare("SELECT COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue FROM om_market_orders WHERE partner_id = ? AND DATE(created_at) = ?");
    $lastWeek->execute([$pid, $lastWeekDay]);
    $lw = $lastWeek->fetch(PDO::FETCH_ASSOC);

    // Rating
    $rating = $db->prepare("SELECT COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as review_count FROM om_market_reviews WHERE partner_id = ? AND DATE(created_at) = ?");
    $rating->execute([$pid, $yesterday]);
    $r = $rating->fetch(PDO::FETCH_ASSOC);

    $revDelta = $lw['revenue'] > 0 ? round(($m['revenue'] - $lw['revenue']) / $lw['revenue'] * 100) : 0;
    $deltaSign = $revDelta >= 0 ? '+' : '';

    // WhatsApp message
    $msg = "Resumo — {$partner['business_name']}\n";
    $msg .= "Ontem: {$m['orders']} pedidos | R$ " . number_format($m['revenue'], 2, ',', '.') . "\n";
    $msg .= "vs semana passada: {$deltaSign}{$revDelta}%\n";
    $msg .= "Ticket medio: R$ " . number_format($m['avg_ticket'], 2, ',', '.') . "\n";
    if ($r['review_count'] > 0) {
        $msg .= "Avaliacao: " . number_format($r['avg_rating'], 1) . " (" . $r['review_count'] . " avaliacoes)\n";
    }
    if ($m['cancelled'] > 0) {
        $msg .= "Cancelamentos: {$m['cancelled']}\n";
    }

    $alerts = [];
    if ($revDelta < -20) $alerts[] = "Queda de {$revDelta}% na receita";
    if ($m['orders'] > 0 && $m['cancelled'] > 0 && ($m['cancelled'] / $m['orders']) > 0.15) {
        $alerts[] = "Taxa de cancelamento alta: " . round($m['cancelled'] / $m['orders'] * 100) . "%";
    }

    // Send WhatsApp
    $sentWa = false;
    if (!empty($partner['phone'])) {
        try { sendWhatsApp($partner['phone'], $msg); $sentWa = true; }
        catch (Exception $e) { error_log("Digest WA error partner {$pid}: " . $e->getMessage()); }
    }

    // Send push
    $sentPush = false;
    try {
        $sender = NotificationSender::getInstance($db);
        $sender->notifyPartner($pid, 'Resumo de ontem', "{$m['orders']} pedidos | R$ " . number_format($m['revenue'], 2, ',', '.'), ['type' => 'daily_digest']);
        $sentPush = true;
    } catch (Exception $e) { /* skip */ }

    // Log
    $db->prepare("INSERT INTO om_partner_digest_log (partner_id, digest_date, digest_type, metrics, alerts, sent_whatsapp, sent_push) VALUES (?, ?, 'daily', ?::jsonb, ?::jsonb, ?, ?)")
       ->execute([$pid, $yesterday, json_encode($m), json_encode($alerts), $sentWa ? 't' : 'f', $sentPush ? 't' : 'f']);

    // Update metrics cache
    $db->prepare("INSERT INTO om_partner_metrics (partner_id, order_count_30d, avg_ticket, calculated_at) VALUES (?, ?, ?, NOW()) ON CONFLICT (partner_id) DO UPDATE SET order_count_30d = EXCLUDED.order_count_30d, avg_ticket = EXCLUDED.avg_ticket, calculated_at = NOW()")
       ->execute([$pid, $m['orders'], $m['avg_ticket']]);

    $processed++;
}

echo date('Y-m-d H:i:s') . " — Partner digest: {$processed} partners processed\n";
