<?php
/**
 * CRON: Notification Retry System
 * Run every 10 minutes via crontab
 *
 * Retries failed notification deliveries with exponential backoff:
 * 1. Find undelivered notifications from last 24h
 * 2. Retry with exponential backoff (10min, 30min, 60min) - max 3 attempts
 * 3. Mark permanently failed after max retries
 * 4. Alert admin about failure patterns
 * 5. Generate delivery status summary
 */

$isCli = php_sapi_name() === 'cli';

function cron_log($msg) {
    global $isCli;
    $line = "[" . date('Y-m-d H:i:s') . "] [notification-retry] {$msg}";
    if ($isCli) echo $line . "\n";
    error_log($line);
}

try {
    $db = new PDO(
        "pgsql:host=147.93.12.236;port=5432;dbname=love1",
        'love1', 'Aleff2009@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // ============================================================
    // ENSURE RETRY TRACKING COLUMNS EXIST
    // ============================================================
    $db->exec("
        ALTER TABLE om_notifications ADD COLUMN IF NOT EXISTS retry_count INT DEFAULT 0;
        ALTER TABLE om_notifications ADD COLUMN IF NOT EXISTS next_retry_at TIMESTAMP;
        ALTER TABLE om_notifications ADD COLUMN IF NOT EXISTS last_error TEXT;
    ");

    $stats = [
        'checked' => 0,
        'pending_retry' => 0,
        'marked_failed' => 0,
        'admin_alerted' => 0,
        'errors' => 0,
    ];

    // ============================================================
    // 1. FIND UNDELIVERED NOTIFICATIONS (LAST 24H)
    // ============================================================
    cron_log("--- Finding undelivered notifications from last 24h ---");

    $stmtUndelivered = $db->query("
        SELECT notification_id, user_id, user_type, title, body, type,
               channels, retry_count,
               push_sent, push_error,
               whatsapp_sent, whatsapp_message_id,
               email_sent, sms_sent,
               next_retry_at, last_error, created_at
        FROM om_notifications
        WHERE created_at > NOW() - INTERVAL '24 hours'
          AND (status IS NULL OR status NOT IN ('sent', 'failed'))
          AND (
              push_sent = 0
              OR whatsapp_sent = 0
              OR email_sent = 0
              OR sms_sent = 0
          )
          AND retry_count < 3
          AND (next_retry_at IS NULL OR next_retry_at <= NOW())
        ORDER BY created_at ASC
        LIMIT 100
    ");
    $undeliveredNotifications = $stmtUndelivered->fetchAll();
    $stats['checked'] = count($undeliveredNotifications);

    cron_log("Found {$stats['checked']} undelivered notifications to process");

    // ============================================================
    // 2. PROCESS RETRIES WITH EXPONENTIAL BACKOFF
    // ============================================================
    cron_log("--- Processing retries ---");

    foreach ($undeliveredNotifications as $notif) {
        $notifId = (int)$notif['notification_id'];
        $retryCount = (int)$notif['retry_count'];
        $failedChannels = [];

        // Identify which channels failed
        if (!$notif['push_sent']) {
            $failedChannels[] = 'push';
        }
        if (!$notif['whatsapp_sent']) {
            $failedChannels[] = 'whatsapp';
        }
        if (!$notif['email_sent']) {
            $failedChannels[] = 'email';
        }
        if (!$notif['sms_sent']) {
            $failedChannels[] = 'sms';
        }

        if (empty($failedChannels)) continue;

        try {
            $newRetryCount = $retryCount + 1;

            // Exponential backoff: attempt 1 → 10min, attempt 2 → 30min, attempt 3 → 60min
            $backoffMinutes = [1 => 10, 2 => 30, 3 => 60];
            $nextRetryMinutes = $backoffMinutes[$newRetryCount] ?? 60;
            $nextRetryAt = date('Y-m-d H:i:s', time() + ($nextRetryMinutes * 60));

            $failedChannelStr = implode(', ', $failedChannels);

            // Flag notification for retry and set next_retry_at
            // The actual sending will be handled by the notification dispatch system
            // We increment retry_count and set the schedule
            $lastErrorMsg = "Retry #{$newRetryCount} agendado para canais: {$failedChannelStr}. Proximo em {$nextRetryMinutes}min.";
            $db->prepare("
                UPDATE om_notifications
                SET retry_count = ?,
                    next_retry_at = ?::timestamp,
                    last_error = ?,
                    status = 'pending_retry'
                WHERE notification_id = ?
            ")->execute([
                $newRetryCount,
                $nextRetryAt,
                $lastErrorMsg,
                $notifId
            ]);

            $stats['pending_retry']++;
            cron_log("RETRY #{$newRetryCount} notificacao #{$notifId} (canais: {$failedChannelStr}). Proximo: {$nextRetryAt}");

        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO retry notificacao #{$notifId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 3. MARK PERMANENTLY FAILED (retry_count >= 3)
    // ============================================================
    cron_log("--- Marking permanently failed notifications ---");

    $stmtMaxRetry = $db->query("
        SELECT notification_id, user_id, user_type, title, type,
               push_sent, whatsapp_sent, email_sent, sms_sent,
               retry_count, last_error, created_at
        FROM om_notifications
        WHERE created_at > NOW() - INTERVAL '24 hours'
          AND retry_count >= 3
          AND (status IS NULL OR status NOT IN ('sent', 'failed'))
        ORDER BY created_at ASC
        LIMIT 100
    ");
    $maxRetryNotifications = $stmtMaxRetry->fetchAll();

    foreach ($maxRetryNotifications as $notif) {
        $notifId = (int)$notif['notification_id'];

        try {
            $db->prepare("
                UPDATE om_notifications
                SET status = 'failed',
                    last_error = COALESCE(last_error, '') || ' | Marcado como falha permanente apos 3 tentativas.'
                WHERE notification_id = ?
            ")->execute([$notifId]);

            $stats['marked_failed']++;
            cron_log("FAILED notificacao #{$notifId} (tipo: {$notif['type']}, user: {$notif['user_type']}#{$notif['user_id']}) - max retries atingido");

        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO marking failed #{$notifId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 4. ALERT ADMIN IF THERE ARE PERMANENTLY FAILED NOTIFICATIONS
    // ============================================================
    if ($stats['marked_failed'] > 0) {
        cron_log("--- Alerting admin about permanently failed notifications ---");

        try {
            // Check if we already alerted in the last hour (avoid spam)
            $stmtAlertCheck = $db->query("
                SELECT COUNT(*) as cnt FROM om_notifications
                WHERE type = 'notification_failure_alert'
                  AND user_type = 'admin'
                  AND created_at > NOW() - INTERVAL '1 hour'
            ");
            $alreadyAlerted = (int)$stmtAlertCheck->fetch()['cnt'] > 0;

            if (!$alreadyAlerted) {
                // Get breakdown by channel
                $stmtBreakdown = $db->query("
                    SELECT
                        COUNT(*) FILTER (WHERE push_sent = 0) as push_failed,
                        COUNT(*) FILTER (WHERE whatsapp_sent = 0) as whatsapp_failed,
                        COUNT(*) FILTER (WHERE email_sent = 0) as email_failed,
                        COUNT(*) FILTER (WHERE sms_sent = 0) as sms_failed,
                        COUNT(*) as total_failed
                    FROM om_notifications
                    WHERE created_at > NOW() - INTERVAL '24 hours'
                      AND status = 'failed'
                ");
                $breakdown = $stmtBreakdown->fetch();

                $alertBody = "{$breakdown['total_failed']} notificacao(es) falharam permanentemente nas ultimas 24h. Push: {$breakdown['push_failed']}, WhatsApp: {$breakdown['whatsapp_failed']}, Email: {$breakdown['email_failed']}, SMS: {$breakdown['sms_failed']}. Verificar integracao dos canais.";
                $db->prepare("
                    INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                    VALUES (1, 'admin', 'ALERTA: Notificacoes falharam permanentemente', ?, 'notification_failure_alert', ?::jsonb, NOW())
                ")->execute([
                    $alertBody,
                    json_encode([
                        'total_failed' => (int)$breakdown['total_failed'],
                        'push_failed' => (int)$breakdown['push_failed'],
                        'whatsapp_failed' => (int)$breakdown['whatsapp_failed'],
                        'email_failed' => (int)$breakdown['email_failed'],
                        'sms_failed' => (int)$breakdown['sms_failed'],
                        'period' => '24h'
                    ])
                ]);

                $stats['admin_alerted']++;
                cron_log("ADMIN ALERT: {$breakdown['total_failed']} notificacoes falharam (push: {$breakdown['push_failed']}, whatsapp: {$breakdown['whatsapp_failed']}, email: {$breakdown['email_failed']}, sms: {$breakdown['sms_failed']})");
            } else {
                cron_log("Admin ja alertado na ultima hora, pulando alerta");
            }

        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO alertando admin: " . $e->getMessage());
        }
    }

    // ============================================================
    // 5. DELIVERY STATUS SUMMARY (LAST 24H)
    // ============================================================
    cron_log("--- Generating delivery status summary ---");

    try {
        $stmtSummary = $db->query("
            SELECT
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE status = 'sent') as delivered,
                COUNT(*) FILTER (WHERE status = 'pending_retry') as pending_retry,
                COUNT(*) FILTER (WHERE status = 'failed') as failed,
                COUNT(*) FILTER (WHERE status IS NULL OR status NOT IN ('sent', 'pending_retry', 'failed')) as unknown
            FROM om_notifications
            WHERE created_at > NOW() - INTERVAL '24 hours'
        ");
        $summary = $stmtSummary->fetch();

        cron_log("SUMMARY (24h): Total={$summary['total']}, Entregues={$summary['delivered']}, Pendentes retry={$summary['pending_retry']}, Falhadas={$summary['failed']}, Outros={$summary['unknown']}");

    } catch (Exception $e) {
        cron_log("ERRO gerando summary: " . $e->getMessage());
    }

    // ============================================================
    // SUMMARY
    // ============================================================
    cron_log("=== RESUMO ===");
    cron_log("Verificadas: {$stats['checked']}");
    cron_log("Agendadas para retry: {$stats['pending_retry']}");
    cron_log("Marcadas como falha permanente: {$stats['marked_failed']}");
    cron_log("Admin alertado: {$stats['admin_alerted']}");
    cron_log("Erros: {$stats['errors']}");

    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'stats' => $stats, 'timestamp' => date('c')]);
    }

} catch (Exception $e) {
    cron_log("ERRO FATAL: " . $e->getMessage());
    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit(1);
}
