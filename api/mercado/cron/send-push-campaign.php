<?php
/**
 * Cron: Process push campaign send queue
 * Run every minute: * * * * * php /var/www/html/api/mercado/cron/send-push-campaign.php
 *
 * Processes pending sends in batches, dispatches via Expo Push API
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/NotificationSender.php';

$db = getDB();
$sender = NotificationSender::getInstance($db);

// 1. Check for scheduled campaigns that should start now
$scheduled = $db->query("
    SELECT id FROM om_push_campaigns
    WHERE status = 'scheduled' AND scheduled_at <= NOW()
")->fetchAll();

foreach ($scheduled as $s) {
    error_log("[CampaignCron] Starting scheduled campaign #{$s['id']}");
    $db->prepare("UPDATE om_push_campaigns SET status = 'sending', updated_at = NOW() WHERE id = ?")->execute([$s['id']]);
}

// 2. Process pending sends (batch of 100)
$pending = $db->prepare("
    SELECT s.id, s.campaign_id, s.customer_id, s.push_token,
           c.title, c.body, c.image_url, c.data
    FROM om_push_campaign_sends s
    JOIN om_push_campaigns c ON c.id = s.campaign_id
    WHERE s.status = 'pending' AND c.status = 'sending'
    ORDER BY s.id ASC
    LIMIT 100
");
$pending->execute();
$sends = $pending->fetchAll();

if (empty($sends)) {
    // Check if any 'sending' campaigns have all sends processed
    $db->exec("
        UPDATE om_push_campaigns SET status = 'sent', sent_at = NOW(), updated_at = NOW()
        WHERE status = 'sending'
        AND NOT EXISTS (
            SELECT 1 FROM om_push_campaign_sends
            WHERE campaign_id = om_push_campaigns.id AND status = 'pending'
        )
    ");
    exit;
}

$sent = 0;
$failed = 0;

foreach ($sends as $s) {
    try {
        $data = json_decode($s['data'] ?: '{}', true) ?: [];
        $data['campaign_id'] = $s['campaign_id'];
        if ($s['image_url']) {
            $data['image'] = $s['image_url'];
        }

        $result = $sender->notifyCustomer(
            $s['customer_id'],
            $s['title'],
            $s['body'],
            $data
        );

        $success = ($result['sent'] ?? 0) > 0;

        $db->prepare("
            UPDATE om_push_campaign_sends
            SET status = ?, sent_at = NOW()
            WHERE id = ?
        ")->execute([$success ? 'sent' : 'failed', $s['id']]);

        if ($success) $sent++;
        else $failed++;

    } catch (Exception $e) {
        error_log("[CampaignCron] Send error for #{$s['id']}: " . $e->getMessage());
        $db->prepare("
            UPDATE om_push_campaign_sends
            SET status = 'failed', error_message = ?, sent_at = NOW()
            WHERE id = ?
        ")->execute([substr($e->getMessage(), 0, 500), $s['id']]);
        $failed++;
    }
}

// Update campaign totals
if ($sent + $failed > 0) {
    $campaignIds = array_unique(array_column($sends, 'campaign_id'));
    foreach ($campaignIds as $cid) {
        $db->prepare("
            UPDATE om_push_campaigns SET
                total_sent = (SELECT COUNT(*) FROM om_push_campaign_sends WHERE campaign_id = ? AND status = 'sent'),
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$cid, $cid]);
    }
}

error_log("[CampaignCron] Processed: {$sent} sent, {$failed} failed");
