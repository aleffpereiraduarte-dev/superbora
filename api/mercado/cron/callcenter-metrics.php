<?php
/**
 * Cron: Aggregate daily callcenter metrics per agent.
 * Run every hour via crontab: 0 * * * * php /var/www/html/api/mercado/cron/callcenter-metrics.php
 *
 * Populates om_callcenter_metrics with today's stats (upsert).
 */
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();
    $today = date('Y-m-d');

    // Get all agents
    $agents = $db->query("SELECT id FROM om_callcenter_agents")->fetchAll();

    foreach ($agents as $agent) {
        $agentId = (int)$agent['id'];

        // Call stats for this agent today
        $callStmt = $db->prepare("
            SELECT
                COUNT(*) AS total_calls,
                COUNT(*) FILTER (WHERE status IN ('completed', 'in_progress')) AS answered_calls,
                COUNT(*) FILTER (WHERE status = 'missed') AS missed_calls,
                COUNT(*) FILTER (WHERE agent_id IS NULL AND status = 'completed') AS ai_handled_calls,
                COUNT(*) FILTER (WHERE callback_requested = TRUE) AS callbacks_requested,
                COUNT(*) FILTER (WHERE callback_completed_at IS NOT NULL) AS callbacks_completed,
                COALESCE(AVG(duration_seconds) FILTER (WHERE duration_seconds > 0), 0)::int AS avg_handle_time,
                COALESCE(AVG(wait_time_seconds) FILTER (WHERE wait_time_seconds IS NOT NULL), 0)::int AS avg_wait_time
            FROM om_callcenter_calls
            WHERE created_at::date = ?
              AND (agent_id = ? OR (agent_id IS NULL AND ? = 0))
        ");
        // For agent_id=0, we'll skip — only real agents
        $callStmt->execute([$today, $agentId, $agentId]);
        $calls = $callStmt->fetch();

        // Draft/order stats
        $draftStmt = $db->prepare("
            SELECT
                COUNT(*) FILTER (WHERE status = 'submitted' AND source = 'phone') AS ai_orders,
                COUNT(*) FILTER (WHERE status = 'submitted' AND source != 'phone') AS agent_orders,
                COALESCE(SUM(total) FILTER (WHERE status = 'submitted'), 0) AS orders_value
            FROM om_callcenter_order_drafts
            WHERE created_at::date = ? AND agent_id = ?
        ");
        $draftStmt->execute([$today, $agentId]);
        $drafts = $draftStmt->fetch();

        // WhatsApp conversations
        $waStmt = $db->prepare("
            SELECT COUNT(*) AS wa_convos
            FROM om_callcenter_whatsapp
            WHERE created_at::date = ? AND agent_id = ?
        ");
        $waStmt->execute([$today, $agentId]);
        $wa = $waStmt->fetch();

        // Upsert metrics
        $db->prepare("
            INSERT INTO om_callcenter_metrics (
                date, agent_id, total_calls, answered_calls, missed_calls,
                ai_handled_calls, ai_orders_placed, agent_orders_placed,
                avg_handle_time_seconds, avg_wait_time_seconds,
                orders_total_value, whatsapp_conversations,
                callbacks_requested, callbacks_completed
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (date, agent_id) DO UPDATE SET
                total_calls = EXCLUDED.total_calls,
                answered_calls = EXCLUDED.answered_calls,
                missed_calls = EXCLUDED.missed_calls,
                ai_handled_calls = EXCLUDED.ai_handled_calls,
                ai_orders_placed = EXCLUDED.ai_orders_placed,
                agent_orders_placed = EXCLUDED.agent_orders_placed,
                avg_handle_time_seconds = EXCLUDED.avg_handle_time_seconds,
                avg_wait_time_seconds = EXCLUDED.avg_wait_time_seconds,
                orders_total_value = EXCLUDED.orders_total_value,
                whatsapp_conversations = EXCLUDED.whatsapp_conversations,
                callbacks_requested = EXCLUDED.callbacks_requested,
                callbacks_completed = EXCLUDED.callbacks_completed
        ")->execute([
            $today, $agentId,
            (int)$calls['total_calls'], (int)$calls['answered_calls'], (int)$calls['missed_calls'],
            (int)$calls['ai_handled_calls'], (int)($drafts['ai_orders'] ?? 0), (int)($drafts['agent_orders'] ?? 0),
            (int)$calls['avg_handle_time'], (int)$calls['avg_wait_time'],
            round((float)($drafts['orders_value'] ?? 0), 2), (int)($wa['wa_convos'] ?? 0),
            (int)$calls['callbacks_requested'], (int)$calls['callbacks_completed'],
        ]);
    }

    echo date('Y-m-d H:i:s') . " — Callcenter metrics updated for {$today}\n";

} catch (Exception $e) {
    error_log("[callcenter-metrics cron] " . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
