<?php
/**
 * ============================================================================
 * GET/POST/DELETE /api/mercado/admin/alert-config.php
 * ============================================================================
 *
 * Manage Slack/Discord alert webhooks for system notifications.
 *
 * Actions:
 *   GET                     — List all configured webhooks
 *   POST                    — Add new webhook
 *   DELETE ?id=X            — Remove webhook by ID
 *   POST ?action=test&id=X  — Send test message to a webhook
 *   POST ?action=toggle&id=X — Toggle webhook active/inactive
 *
 * POST body (add):
 *   {
 *     "type": "slack",                 // "slack" or "discord"
 *     "webhook_url": "https://...",
 *     "events": ["order_cancelled", "payment_failed"],
 *     "label": "Production Alerts"     // optional friendly name
 *   }
 *
 * Table: sb_alert_webhooks
 *   id SERIAL PRIMARY KEY,
 *   type VARCHAR(10) NOT NULL CHECK (type IN ('slack', 'discord')),
 *   webhook_url TEXT NOT NULL,
 *   label VARCHAR(100),
 *   events TEXT[] NOT NULL DEFAULT '{}',
 *   is_active BOOLEAN NOT NULL DEFAULT true,
 *   created_by INTEGER REFERENCES om_admins(admin_id),
 *   created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 *   updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
 *
 * ============================================================================
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once __DIR__ . "/../helpers/alert-bot.php";

setCorsHeaders();

// Valid event types
$validEvents = [
    'order_cancelled',
    'payment_failed',
    'store_offline',
    'high_fraud_score',
    'system_error',
    'review_negative',
    'stock_alert',
    'delivery_delayed',
    'refund_requested',
    'new_dispute',
    'cron_summary',
];

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Require admin authentication
    $payload = om_auth()->requireAdmin();
    $adminId = $payload['admin_id'] ?? $payload['user_id'] ?? 0;

    $method = $_SERVER['REQUEST_METHOD'];

    // Ensure table exists (auto-create on first use)
    ensureTable($db);

    // ========================================================================
    // GET — List all webhooks
    // ========================================================================
    if ($method === 'GET') {
        $stmt = $db->query("
            SELECT
                w.id,
                w.type,
                w.webhook_url,
                w.label,
                w.events,
                w.is_active,
                w.created_by,
                COALESCE(a.name, a.email, 'System') AS created_by_name,
                w.created_at,
                w.updated_at
            FROM sb_alert_webhooks w
            LEFT JOIN om_admins a ON a.admin_id = w.created_by
            ORDER BY w.created_at DESC
        ");
        $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse PostgreSQL text[] to PHP array
        foreach ($webhooks as &$wh) {
            $wh['events'] = pgArrayToPhp($wh['events']);
            $wh['is_active'] = (bool)$wh['is_active'];
            // Mask webhook URL for display (show first 40 chars + ...)
            $wh['webhook_url_masked'] = strlen($wh['webhook_url']) > 45
                ? substr($wh['webhook_url'], 0, 40) . '...'
                : $wh['webhook_url'];
        }
        unset($wh);

        response(true, [
            'webhooks' => $webhooks,
            'valid_events' => $validEvents,
        ]);
    }

    // ========================================================================
    // DELETE ?id=X — Remove webhook
    // ========================================================================
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            response(false, null, 'Parameter id is required', 400);
        }

        $stmt = $db->prepare("DELETE FROM sb_alert_webhooks WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            response(false, null, 'Webhook not found', 404);
        }

        response(true, null, 'Webhook removed');
    }

    // ========================================================================
    // POST — Add webhook OR perform action (test/toggle)
    // ========================================================================
    if ($method === 'POST') {
        $action = $_GET['action'] ?? '';

        // --- Test webhook ---
        if ($action === 'test') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                response(false, null, 'Parameter id is required', 400);
            }

            $stmt = $db->prepare("SELECT id, type, webhook_url, label FROM sb_alert_webhooks WHERE id = ?");
            $stmt->execute([$id]);
            $wh = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$wh) {
                response(false, null, 'Webhook not found', 404);
            }

            $testDetails = [
                'Webhook ID' => $wh['id'],
                'Label'      => $wh['label'] ?: '(unnamed)',
                'Type'       => $wh['type'],
            ];

            $ok = false;
            if ($wh['type'] === 'slack') {
                $ok = sendSlackAlert(
                    $wh['webhook_url'],
                    'Test alert from SuperBora Alert Bot',
                    'info',
                    $testDetails
                );
            } elseif ($wh['type'] === 'discord') {
                $ok = sendDiscordAlert(
                    $wh['webhook_url'],
                    'Test alert from SuperBora Alert Bot',
                    'info',
                    $testDetails
                );
            }

            if ($ok) {
                response(true, null, 'Test message sent successfully');
            } else {
                response(false, null, 'Failed to send test message. Check the webhook URL.', 502);
            }
        }

        // --- Toggle active/inactive ---
        if ($action === 'toggle') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                response(false, null, 'Parameter id is required', 400);
            }

            $stmt = $db->prepare("
                UPDATE sb_alert_webhooks
                SET is_active = NOT is_active, updated_at = NOW()
                WHERE id = ?
                RETURNING id, is_active
            ");
            $stmt->execute([$id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                response(false, null, 'Webhook not found', 404);
            }

            $status = $result['is_active'] ? 'activated' : 'deactivated';
            response(true, ['id' => $result['id'], 'is_active' => (bool)$result['is_active']], "Webhook {$status}");
        }

        // --- Add new webhook ---
        $input = getInput();
        $type = trim($input['type'] ?? '');
        $webhookUrl = trim($input['webhook_url'] ?? '');
        $events = $input['events'] ?? [];
        $label = trim($input['label'] ?? '');

        // Validate type
        if (!in_array($type, ['slack', 'discord'], true)) {
            response(false, null, 'Type must be "slack" or "discord"', 400);
        }

        // Validate URL
        if (empty($webhookUrl) || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            response(false, null, 'A valid webhook_url is required', 400);
        }

        // Validate URL scheme (only HTTPS)
        $parsed = parse_url($webhookUrl);
        if (($parsed['scheme'] ?? '') !== 'https') {
            response(false, null, 'Webhook URL must use HTTPS', 400);
        }

        // Slack URLs must be hooks.slack.com, Discord must be discord.com/api/webhooks
        if ($type === 'slack' && strpos($parsed['host'] ?? '', 'hooks.slack.com') === false) {
            response(false, null, 'Slack webhook URL must be from hooks.slack.com', 400);
        }
        if ($type === 'discord' && strpos($parsed['host'] ?? '', 'discord.com') === false) {
            response(false, null, 'Discord webhook URL must be from discord.com', 400);
        }

        // Validate events
        if (!is_array($events) || empty($events)) {
            response(false, null, 'At least one event is required. Valid events: ' . implode(', ', $validEvents), 400);
        }
        $invalidEvents = array_diff($events, $validEvents);
        if (!empty($invalidEvents)) {
            response(false, null, 'Invalid events: ' . implode(', ', $invalidEvents) . '. Valid events: ' . implode(', ', $validEvents), 400);
        }

        // Check for duplicate URL
        $stmt = $db->prepare("SELECT id FROM sb_alert_webhooks WHERE webhook_url = ?");
        $stmt->execute([$webhookUrl]);
        if ($stmt->fetch()) {
            response(false, null, 'A webhook with this URL already exists', 409);
        }

        // Convert PHP array to PostgreSQL text[]
        $pgEvents = phpArrayToPg($events);

        $stmt = $db->prepare("
            INSERT INTO sb_alert_webhooks (type, webhook_url, label, events, is_active, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?::text[], true, ?, NOW(), NOW())
            RETURNING id
        ");
        $stmt->execute([$type, $webhookUrl, $label ?: null, $pgEvents, $adminId]);
        $newId = $stmt->fetchColumn();

        response(true, ['id' => (int)$newId], 'Webhook added successfully', 201);
    }

    // Unsupported method
    response(false, null, 'Method not allowed', 405);

} catch (Exception $e) {
    error_log("[admin/alert-config] Error: " . $e->getMessage());
    response(false, null, "Internal error", 500);
}

// ============================================================================
// Helpers
// ============================================================================

/**
 * Convert PostgreSQL text[] string to PHP array.
 * E.g. "{order_cancelled,payment_failed}" -> ["order_cancelled", "payment_failed"]
 */
function pgArrayToPhp(?string $pgArray): array {
    if (empty($pgArray) || $pgArray === '{}') return [];
    $inner = trim($pgArray, '{}');
    if (empty($inner)) return [];
    return array_map(function ($v) {
        return trim($v, '"');
    }, str_getcsv($inner));
}

/**
 * Convert PHP array to PostgreSQL text[] literal.
 * E.g. ["order_cancelled", "payment_failed"] -> "{order_cancelled,payment_failed}"
 */
function phpArrayToPg(array $arr): string {
    $escaped = array_map(function ($v) {
        // Escape any special chars for pg array
        $v = str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
        return '"' . $v . '"';
    }, $arr);
    return '{' . implode(',', $escaped) . '}';
}

/**
 * Auto-create the sb_alert_webhooks table if it does not exist.
 */
function ensureTable(PDO $db): void {
    static $checked = false;
    if ($checked) return;

    $db->exec("
        CREATE TABLE IF NOT EXISTS sb_alert_webhooks (
            id SERIAL PRIMARY KEY,
            type VARCHAR(10) NOT NULL CHECK (type IN ('slack', 'discord')),
            webhook_url TEXT NOT NULL,
            label VARCHAR(100),
            events TEXT[] NOT NULL DEFAULT '{}',
            is_active BOOLEAN NOT NULL DEFAULT true,
            created_by INTEGER,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    // Index for fast event lookup
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_sb_alert_webhooks_active
        ON sb_alert_webhooks (is_active)
        WHERE is_active = true
    ");

    $checked = true;
}
