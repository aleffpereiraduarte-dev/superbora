<?php
/**
 * /api/mercado/admin/webhooks.php
 *
 * Webhook management — external systems subscribing to SuperBora events.
 *
 * GET:                         List all webhooks
 * GET ?action=logs&id=X:       View delivery log (last 50)
 * POST:                        Register new webhook {url, events, secret?}
 * PUT ?id=X:                   Update webhook {url?, events?, is_active?}
 * DELETE ?id=X:                Remove webhook
 * POST ?action=test&id=X:     Send test event
 * POST ?action=retry&delivery_id=X: Retry failed delivery
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/webhook-dispatcher.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    // ── Ensure tables exist ──
    ensureWebhookTables($db);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = trim($_GET['action'] ?? '');

    // Allowed event types
    $allowedEvents = [
        'order.created', 'order.accepted', 'order.preparing', 'order.ready',
        'order.delivered', 'order.cancelled', 'order.refused',
        'payment.confirmed', 'payment.failed', 'payment.refunded',
        'customer.registered', 'customer.updated',
        'product.created', 'product.updated', 'product.out_of_stock',
    ];

    // =================== GET ===================
    if ($method === 'GET') {

        // Delivery logs for a webhook
        if ($action === 'logs') {
            $webhookId = (int)($_GET['id'] ?? 0);
            if (!$webhookId) response(false, null, "id obrigatorio", 400);

            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            $stmt = $db->prepare("
                SELECT id, event_type, response_code, duration_ms, status, attempts, created_at
                FROM sb_webhook_deliveries
                WHERE webhook_id = ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$webhookId, $limit, $offset]);
            $deliveries = $stmt->fetchAll();

            $stmt = $db->prepare("SELECT COUNT(*) as total FROM sb_webhook_deliveries WHERE webhook_id = ?");
            $stmt->execute([$webhookId]);
            $total = (int)$stmt->fetch()['total'];

            // Stats summary
            $stmt = $db->prepare("
                SELECT
                    COUNT(*) as total_deliveries,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    ROUND(AVG(duration_ms)) as avg_duration_ms,
                    MAX(created_at) as last_delivery
                FROM sb_webhook_deliveries
                WHERE webhook_id = ?
            ");
            $stmt->execute([$webhookId]);
            $stats = $stmt->fetch();

            response(true, [
                'deliveries' => $deliveries,
                'stats' => $stats,
                'total' => $total,
                'page' => $page,
                'pages' => ceil($total / $limit),
            ], "Logs de entrega");
        }

        // List all webhooks
        $stmt = $db->query("
            SELECT w.*,
                   (SELECT COUNT(*) FROM sb_webhook_deliveries d WHERE d.webhook_id = w.id) as total_deliveries,
                   (SELECT COUNT(*) FROM sb_webhook_deliveries d WHERE d.webhook_id = w.id AND d.status = 'success') as success_deliveries,
                   (SELECT COUNT(*) FROM sb_webhook_deliveries d WHERE d.webhook_id = w.id AND d.status = 'failed') as failed_deliveries,
                   (SELECT MAX(d.created_at) FROM sb_webhook_deliveries d WHERE d.webhook_id = w.id) as last_delivery
            FROM sb_webhooks w
            ORDER BY w.created_at DESC
        ");
        $webhooks = $stmt->fetchAll();

        // Mask secrets in response
        foreach ($webhooks as &$wh) {
            if (!empty($wh['secret'])) {
                $wh['secret'] = substr($wh['secret'], 0, 8) . '********';
            }
        }
        unset($wh);

        response(true, ['webhooks' => $webhooks, 'allowed_events' => $allowedEvents], "Webhooks listados");
    }

    // =================== POST ===================
    if ($method === 'POST') {

        // ── Test webhook ──
        if ($action === 'test') {
            $webhookId = (int)($_GET['id'] ?? 0);
            if (!$webhookId) response(false, null, "id obrigatorio", 400);

            $result = sendTestWebhook($db, $webhookId);
            om_audit()->log('webhook_test', 'sb_webhooks', $webhookId, null, null, $admin_id);

            response($result['success'], $result, $result['success'] ? "Teste enviado com sucesso" : "Falha no teste");
        }

        // ── Retry failed delivery ──
        if ($action === 'retry') {
            $deliveryId = (int)($_GET['delivery_id'] ?? 0);
            if (!$deliveryId) response(false, null, "delivery_id obrigatorio", 400);

            $result = retryWebhookDelivery($db, $deliveryId);
            om_audit()->log('webhook_retry', 'sb_webhook_deliveries', $deliveryId, null, null, $admin_id);

            response($result['success'], $result, $result['success'] ? "Reenvio bem-sucedido" : "Falha no reenvio");
        }

        // ── Register new webhook ──
        $input = getInput();
        $url = trim($input['url'] ?? '');
        $events = $input['events'] ?? [];
        $secret = trim($input['secret'] ?? '');

        if (!$url) {
            response(false, null, "URL obrigatoria", 400);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https:\/\//', $url)) {
            response(false, null, "URL deve ser HTTPS valida", 400);
        }
        if (!is_array($events) || empty($events)) {
            response(false, null, "Pelo menos um evento e obrigatorio", 400);
        }

        // Validate events
        foreach ($events as $evt) {
            if (!in_array($evt, $allowedEvents, true)) {
                response(false, null, "Evento invalido: {$evt}. Eventos disponiveis: " . implode(', ', $allowedEvents), 400);
            }
        }

        // Auto-generate secret if not provided
        if (!$secret) {
            $secret = bin2hex(random_bytes(32));
        }
        if (strlen($secret) > 64) {
            response(false, null, "Secret muito longo (max 64 caracteres)", 400);
        }

        // Check for duplicate URL
        $stmt = $db->prepare("SELECT id FROM sb_webhooks WHERE url = ?");
        $stmt->execute([$url]);
        if ($stmt->fetch()) {
            response(false, null, "Ja existe um webhook para esta URL", 409);
        }

        // PostgreSQL text[] literal
        $eventsArray = '{' . implode(',', array_map(function($e) { return '"' . $e . '"'; }, $events)) . '}';

        $stmt = $db->prepare("
            INSERT INTO sb_webhooks (url, events, secret, is_active, created_at)
            VALUES (?, ?::text[], ?, true, NOW())
            RETURNING id
        ");
        $stmt->execute([$url, $eventsArray, $secret]);
        $webhookId = (int)$stmt->fetch()['id'];

        om_audit()->log('webhook_created', 'sb_webhooks', $webhookId, null, null, $admin_id);

        response(true, [
            'id' => $webhookId,
            'url' => $url,
            'events' => $events,
            'secret' => $secret, // Show full secret only on creation
        ], "Webhook registrado", 201);
    }

    // =================== PUT ===================
    if ($method === 'PUT') {
        $webhookId = (int)($_GET['id'] ?? 0);
        if (!$webhookId) response(false, null, "id obrigatorio", 400);

        // Verify webhook exists
        $stmt = $db->prepare("SELECT * FROM sb_webhooks WHERE id = ?");
        $stmt->execute([$webhookId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            response(false, null, "Webhook nao encontrado", 404);
        }

        $input = getInput();
        $updates = [];
        $params = [];

        if (isset($input['url'])) {
            $url = trim($input['url']);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https:\/\//', $url)) {
                response(false, null, "URL deve ser HTTPS valida", 400);
            }
            $updates[] = "url = ?";
            $params[] = $url;
        }

        if (isset($input['events']) && is_array($input['events'])) {
            foreach ($input['events'] as $evt) {
                if (!in_array($evt, $allowedEvents, true)) {
                    response(false, null, "Evento invalido: {$evt}", 400);
                }
            }
            $eventsArray = '{' . implode(',', array_map(function($e) { return '"' . $e . '"'; }, $input['events'])) . '}';
            $updates[] = "events = ?::text[]";
            $params[] = $eventsArray;
        }

        if (isset($input['is_active'])) {
            $updates[] = "is_active = ?";
            $params[] = (bool)$input['is_active'];
        }

        if (isset($input['secret'])) {
            $secret = trim($input['secret']);
            if (strlen($secret) > 64) {
                response(false, null, "Secret muito longo (max 64)", 400);
            }
            $updates[] = "secret = ?";
            $params[] = $secret;
        }

        if (empty($updates)) {
            response(false, null, "Nenhum campo para atualizar", 400);
        }

        $params[] = $webhookId;
        $stmt = $db->prepare("UPDATE sb_webhooks SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        om_audit()->log('webhook_updated', 'sb_webhooks', $webhookId, null, null, $admin_id);

        response(true, ['id' => $webhookId], "Webhook atualizado");
    }

    // =================== DELETE ===================
    if ($method === 'DELETE') {
        $webhookId = (int)($_GET['id'] ?? 0);
        if (!$webhookId) response(false, null, "id obrigatorio", 400);

        $stmt = $db->prepare("SELECT id FROM sb_webhooks WHERE id = ?");
        $stmt->execute([$webhookId]);
        if (!$stmt->fetch()) {
            response(false, null, "Webhook nao encontrado", 404);
        }

        // Delete deliveries first (foreign key safety)
        $stmt = $db->prepare("DELETE FROM sb_webhook_deliveries WHERE webhook_id = ?");
        $stmt->execute([$webhookId]);

        $stmt = $db->prepare("DELETE FROM sb_webhooks WHERE id = ?");
        $stmt->execute([$webhookId]);

        om_audit()->log('webhook_deleted', 'sb_webhooks', $webhookId, null, null, $admin_id);

        response(true, null, "Webhook removido");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/webhooks] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// ── Helper ──

function ensureWebhookTables(PDO $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $db->exec("
        CREATE TABLE IF NOT EXISTS sb_webhooks (
            id SERIAL PRIMARY KEY,
            url VARCHAR(2048) NOT NULL,
            events TEXT[] NOT NULL,
            secret VARCHAR(64) NOT NULL,
            is_active BOOLEAN NOT NULL DEFAULT true,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS sb_webhook_deliveries (
            id SERIAL PRIMARY KEY,
            webhook_id INT NOT NULL REFERENCES sb_webhooks(id) ON DELETE CASCADE,
            event_type VARCHAR(100) NOT NULL,
            payload JSONB,
            response_code INT,
            response_body TEXT,
            duration_ms INT,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_webhook_deliveries_webhook ON sb_webhook_deliveries(webhook_id, created_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_webhook_deliveries_status ON sb_webhook_deliveries(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_webhooks_active ON sb_webhooks(is_active) WHERE is_active = true");
}
