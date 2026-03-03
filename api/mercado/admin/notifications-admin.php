<?php
/**
 * /api/mercado/admin/notifications-admin.php
 *
 * iFood-level admin notification management endpoint.
 *
 * POST (action=send_to_customer): Send push notification to specific customer.
 *   Fields: customer_id, title, body, data (optional JSON)
 *
 * POST (action=send_to_partner): Send push notification to specific partner.
 *   Fields: partner_id, title, body, data (optional JSON)
 *
 * POST (action=broadcast): Send to all customers or all partners.
 *   Fields: target (customers/partners), title, body, data (optional JSON)
 *
 * GET: List sent notifications with stats.
 *   Params: page, limit, target_type (customer/partner/broadcast)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/NotificationSender.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    $sender = NotificationSender::getInstance($db);

    // =================== GET ===================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $target_type = trim($_GET['target_type'] ?? '');

        // List sent notifications from om_market_notifications (admin-originated)
        $where = ["1=1"];
        $params = [];

        if ($target_type !== '') {
            $allowed_types = ['customer', 'partner', 'broadcast'];
            if (in_array($target_type, $allowed_types, true)) {
                if ($target_type === 'broadcast') {
                    $where[] = "n.recipient_id = 0";
                } else {
                    $where[] = "n.recipient_type = ?";
                    $params[] = $target_type;
                }
            }
        }

        $where_sql = implode(' AND ', $where);

        // Count total
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_notifications n WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        // Fetch recent notifications
        $stmt = $db->prepare("
            SELECT n.id, n.recipient_id, n.recipient_type, n.title, n.message,
                   n.data, n.is_read, n.sent_at,
                   CASE
                       WHEN n.recipient_type = 'customer' THEN c.name
                       WHEN n.recipient_type = 'partner' THEN p.name
                       ELSE NULL
                   END as recipient_name
            FROM om_market_notifications n
            LEFT JOIN om_customers c ON n.recipient_type = 'customer' AND n.recipient_id = c.customer_id
            LEFT JOIN om_market_partners p ON n.recipient_type = 'partner' AND n.recipient_id = p.partner_id
            WHERE {$where_sql}
            ORDER BY n.sent_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();

        // Recent broadcast campaigns
        $campaigns = [];
        try {
            $stmt = $db->query("
                SELECT id, title, body, segment_type, status,
                       total_sent, total_opened, total_clicked,
                       scheduled_at, sent_at, created_at
                FROM om_push_campaigns
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $campaigns = $stmt->fetchAll();
        } catch (Exception $e) {
            // Table may not exist
        }

        // Stats
        $stmt = $db->query("
            SELECT COUNT(*) as total_sent,
                   SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as total_read,
                   COUNT(DISTINCT recipient_id) as unique_recipients
            FROM om_market_notifications
            WHERE sent_at >= CURRENT_DATE - INTERVAL '30 days'
        ");
        $notif_stats = $stmt->fetch();

        response(true, [
            'notifications' => $notifications,
            'campaigns' => $campaigns,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
            'stats' => [
                'total_sent_30d' => (int)($notif_stats['total_sent'] ?? 0),
                'total_read_30d' => (int)($notif_stats['total_read'] ?? 0),
                'unique_recipients_30d' => (int)($notif_stats['unique_recipients'] ?? 0),
            ],
        ], "Notificacoes listadas");
    }

    // =================== POST ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        if (!$action) response(false, null, "Campo 'action' obrigatorio", 400);

        // ── Send to specific customer ──
        if ($action === 'send_to_customer') {
            $customer_id = (int)($input['customer_id'] ?? 0);
            $title = strip_tags(trim($input['title'] ?? ''));
            $body = strip_tags(trim($input['body'] ?? ''));
            $data = $input['data'] ?? [];

            if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);
            if (!$title) response(false, null, "title obrigatorio", 400);
            if (!$body) response(false, null, "body obrigatorio", 400);

            // Verify customer exists
            $stmt = $db->prepare("SELECT customer_id, name FROM om_customers WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            if (!$customer) response(false, null, "Cliente nao encontrado", 404);

            // Ensure data is an array
            if (is_string($data)) {
                $data = json_decode($data, true) ?: [];
            }
            $data['sent_by_admin'] = $admin_id;

            $result = $sender->notifyCustomer($customer_id, $title, $body, $data);

            om_audit()->log(
                'notification_send',
                'customer',
                $customer_id,
                null,
                ['title' => $title, 'body' => substr($body, 0, 200)],
                "Notificacao enviada para cliente '{$customer['name']}'"
            );

            response(true, [
                'customer_id' => $customer_id,
                'customer_name' => $customer['name'],
                'sent' => $result['sent'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'admin_id' => $admin_id,
            ], "Notificacao enviada para o cliente");
        }

        // ── Send to specific partner ──
        if ($action === 'send_to_partner') {
            $partner_id = (int)($input['partner_id'] ?? 0);
            $title = strip_tags(trim($input['title'] ?? ''));
            $body = strip_tags(trim($input['body'] ?? ''));
            $data = $input['data'] ?? [];

            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);
            if (!$title) response(false, null, "title obrigatorio", 400);
            if (!$body) response(false, null, "body obrigatorio", 400);

            // Verify partner exists
            $stmt = $db->prepare("SELECT partner_id, name FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$partner_id]);
            $partner = $stmt->fetch();
            if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

            // Ensure data is an array
            if (is_string($data)) {
                $data = json_decode($data, true) ?: [];
            }
            $data['sent_by_admin'] = $admin_id;

            $result = $sender->notifyPartner($partner_id, $title, $body, $data);

            om_audit()->log(
                'notification_send',
                OmAudit::ENTITY_PARTNER,
                $partner_id,
                null,
                ['title' => $title, 'body' => substr($body, 0, 200)],
                "Notificacao enviada para parceiro '{$partner['name']}'"
            );

            response(true, [
                'partner_id' => $partner_id,
                'partner_name' => $partner['name'],
                'sent' => $result['sent'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'admin_id' => $admin_id,
            ], "Notificacao enviada para o parceiro");
        }

        // ── Broadcast to all customers or all partners ──
        if ($action === 'broadcast') {
            $target = trim($input['target'] ?? '');
            $title = strip_tags(trim($input['title'] ?? ''));
            $body = strip_tags(trim($input['body'] ?? ''));
            $data = $input['data'] ?? [];

            if (!$target) response(false, null, "target obrigatorio (customers ou partners)", 400);
            if (!$title) response(false, null, "title obrigatorio", 400);
            if (!$body) response(false, null, "body obrigatorio", 400);

            $allowed_targets = ['customers', 'partners'];
            if (!in_array($target, $allowed_targets, true)) {
                response(false, null, "target invalido. Permitidos: customers, partners", 400);
            }

            // Ensure data is an array
            if (is_string($data)) {
                $data = json_decode($data, true) ?: [];
            }
            $data['sent_by_admin'] = $admin_id;
            $data['broadcast'] = true;

            $user_type = $target === 'customers' ? 'customer' : 'partner';

            // Get all push tokens for the target group
            $stmt = $db->prepare("
                SELECT DISTINCT user_id, token
                FROM om_market_push_tokens
                WHERE user_type = ?
                ORDER BY user_id
            ");
            $stmt->execute([$user_type]);
            $all_tokens = $stmt->fetchAll();

            $total_sent = 0;
            $total_failed = 0;

            // Group tokens by user_id and send individually via NotificationSender
            // This ensures in-app notifications are stored per user
            $users_by_id = [];
            foreach ($all_tokens as $row) {
                $uid = (int)$row['user_id'];
                if (!isset($users_by_id[$uid])) {
                    $users_by_id[$uid] = true;
                }
            }

            // Send to each unique user (NotificationSender handles token lookup + in-app storage)
            foreach (array_keys($users_by_id) as $uid) {
                try {
                    if ($user_type === 'customer') {
                        $result = $sender->notifyCustomer($uid, $title, $body, $data);
                    } else {
                        $result = $sender->notifyPartner($uid, $title, $body, $data);
                    }
                    $total_sent += ($result['sent'] ?? 0);
                    $total_failed += ($result['failed'] ?? 0);
                } catch (Exception $e) {
                    $total_failed++;
                    error_log("[admin/notifications-admin] Broadcast error for {$user_type} #{$uid}: " . $e->getMessage());
                }
            }

            // Log broadcast in campaigns table if available
            try {
                $stmt = $db->prepare("
                    INSERT INTO om_push_campaigns
                        (title, body, data, segment_type, status, total_sent, sent_at, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 'sent', ?, NOW(), ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $title,
                    $body,
                    json_encode($data, JSON_UNESCAPED_UNICODE),
                    $target === 'customers' ? 'all_customers' : 'all_partners',
                    $total_sent,
                    $admin_id,
                ]);
            } catch (Exception $e) {
                // Table may not exist yet; don't fail the broadcast
                error_log("[admin/notifications-admin] Could not log campaign: " . $e->getMessage());
            }

            om_audit()->log(
                'notification_broadcast',
                'system',
                null,
                null,
                [
                    'target' => $target,
                    'title' => $title,
                    'total_users' => count($users_by_id),
                    'total_sent' => $total_sent,
                ],
                "Broadcast '{$title}' enviado para " . count($users_by_id) . " {$target}"
            );

            response(true, [
                'target' => $target,
                'total_users' => count($users_by_id),
                'total_sent' => $total_sent,
                'total_failed' => $total_failed,
                'admin_id' => $admin_id,
            ], "Broadcast enviado com sucesso");
        }

        response(false, null, "Acao invalida: {$action}", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/notifications-admin] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
