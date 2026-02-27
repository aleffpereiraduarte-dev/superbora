<?php
/**
 * /api/mercado/admin/push-campaign.php
 * Push Campaigns Management (Admin only)
 *
 * GET                     - list campaigns
 * POST { create }         - create new campaign
 * POST { send, id }       - queue campaign for sending
 * POST { cancel, id }     - cancel scheduled campaign
 * GET ?id=X&stats=1       - get campaign stats
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Admin auth required
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    $userType = $payload['type'] ?? '';
    if (!in_array($userType, ['admin', 'partner'])) {
        response(false, null, "Acesso negado", 403);
    }

    $method = $_SERVER["REQUEST_METHOD"];

    // ── GET: list campaigns or stats ──
    if ($method === "GET") {
        $campaignId = intval($_GET['id'] ?? 0);

        if ($campaignId && !empty($_GET['stats'])) {
            // Stats for specific campaign
            $stmt = $db->prepare("
                SELECT c.*,
                    (SELECT COUNT(*) FROM om_push_campaign_sends WHERE campaign_id = c.id AND status = 'sent') as actual_sent,
                    (SELECT COUNT(*) FROM om_push_campaign_sends WHERE campaign_id = c.id AND status = 'failed') as actual_failed
                FROM om_push_campaigns c WHERE c.id = ?
            ");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch();
            if (!$campaign) response(false, null, "Campanha nao encontrada", 404);
            response(true, ['campaign' => $campaign]);
        }

        // List all campaigns
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(10, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $stmt = $db->prepare("
            SELECT id, title, body, segment_type, status, total_sent, total_opened,
                   scheduled_at, sent_at, created_at
            FROM om_push_campaigns
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $campaigns = $stmt->fetchAll();

        $total = (int)$db->query("SELECT COUNT(*) FROM om_push_campaigns")->fetchColumn();

        response(true, [
            'campaigns' => $campaigns,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
        ]);
    }

    // ── POST: create, send, or cancel ──
    if ($method === "POST") {
        $input = getInput();

        // Cancel campaign
        if (!empty($input['cancel'])) {
            $id = intval($input['id'] ?? 0);
            $stmt = $db->prepare("UPDATE om_push_campaigns SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status IN ('draft', 'scheduled')");
            $stmt->execute([$id]);
            response(true, ['id' => $id], "Campanha cancelada");
        }

        // Queue campaign for sending
        if (!empty($input['send'])) {
            $id = intval($input['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM om_push_campaigns WHERE id = ?");
            $stmt->execute([$id]);
            $campaign = $stmt->fetch();
            if (!$campaign) response(false, null, "Campanha nao encontrada", 404);
            if ($campaign['status'] !== 'draft') response(false, null, "Campanha ja foi enviada/agendada");

            // Build recipient list based on segment
            $recipients = _buildRecipientList($db, $campaign);
            $count = count($recipients);

            if ($count === 0) {
                response(false, null, "Nenhum destinatario encontrado para este segmento");
            }

            // Insert into send queue
            $db->beginTransaction();
            $insertStmt = $db->prepare("
                INSERT INTO om_push_campaign_sends (campaign_id, customer_id, push_token, status, created_at)
                VALUES (?, ?, ?, 'pending', NOW())
            ");
            foreach ($recipients as $r) {
                $insertStmt->execute([$id, $r['customer_id'], $r['token']]);
            }

            $db->prepare("UPDATE om_push_campaigns SET status = 'sending', total_sent = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$count, $id]);
            $db->commit();

            response(true, ['id' => $id, 'recipients' => $count], "Campanha em envio para {$count} destinatarios");
        }

        // Create new campaign
        $title = trim($input['title'] ?? '');
        $body = trim($input['body'] ?? '');
        if (!$title || !$body) response(false, null, "title e body obrigatorios");

        $segmentType = $input['segment_type'] ?? 'all';
        $segmentConfig = isset($input['segment_config']) ? json_encode($input['segment_config']) : '{}';
        $imageUrl = trim($input['image_url'] ?? '') ?: null;
        $data = isset($input['data']) ? json_encode($input['data']) : '{}';
        $scheduledAt = !empty($input['scheduled_at']) ? $input['scheduled_at'] : null;
        $status = $scheduledAt ? 'scheduled' : 'draft';

        $stmt = $db->prepare("
            INSERT INTO om_push_campaigns (title, body, image_url, data, segment_type, segment_config, status, scheduled_at, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            RETURNING id
        ");
        $stmt->execute([
            $title, $body, $imageUrl, $data,
            $segmentType, $segmentConfig, $status,
            $scheduledAt, (int)$payload['uid'],
        ]);
        $newId = $stmt->fetchColumn();

        // Preview recipient count
        $previewCount = _countRecipients($db, $segmentType, json_decode($segmentConfig, true));

        response(true, [
            'id' => $newId,
            'status' => $status,
            'estimated_recipients' => $previewCount,
        ], "Campanha criada");
    }

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log("[PushCampaign] Error: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// ── Helper: build recipient list ──
function _buildRecipientList(PDO $db, array $campaign): array {
    $segType = $campaign['segment_type'];
    $segConfig = json_decode($campaign['segment_config'] ?: '{}', true);

    $where = "pt.user_type = 'customer' AND pt.token IS NOT NULL AND pt.token != ''";
    $params = [];

    switch ($segType) {
        case 'city':
            $city = $segConfig['city'] ?? '';
            if ($city) {
                $where .= " AND EXISTS (SELECT 1 FROM om_market_orders o WHERE o.customer_id = pt.user_id AND LOWER(o.city) = LOWER(?) LIMIT 1)";
                $params[] = $city;
            }
            break;
        case 'inactive':
            $days = intval($segConfig['days_inactive'] ?? 30);
            $where .= " AND NOT EXISTS (SELECT 1 FROM om_market_orders o WHERE o.customer_id = pt.user_id AND o.created_at > NOW() - INTERVAL '{$days} days')";
            break;
        case 'high_value':
            $minSpent = floatval($segConfig['min_spent'] ?? 100);
            $where .= " AND EXISTS (SELECT 1 FROM om_market_orders o WHERE o.customer_id = pt.user_id GROUP BY o.customer_id HAVING SUM(o.total) >= ?)";
            $params[] = $minSpent;
            break;
        case 'all':
        default:
            break;
    }

    $stmt = $db->prepare("
        SELECT DISTINCT pt.user_id as customer_id, pt.token
        FROM om_market_push_tokens pt
        WHERE {$where}
        LIMIT 10000
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function _countRecipients(PDO $db, string $segType, array $segConfig): int {
    $where = "pt.user_type = 'customer' AND pt.token IS NOT NULL AND pt.token != ''";
    $params = [];

    switch ($segType) {
        case 'city':
            $city = $segConfig['city'] ?? '';
            if ($city) {
                $where .= " AND EXISTS (SELECT 1 FROM om_market_orders o WHERE o.customer_id = pt.user_id AND LOWER(o.city) = LOWER(?) LIMIT 1)";
                $params[] = $city;
            }
            break;
        case 'inactive':
            $days = intval($segConfig['days_inactive'] ?? 30);
            $where .= " AND NOT EXISTS (SELECT 1 FROM om_market_orders o WHERE o.customer_id = pt.user_id AND o.created_at > NOW() - INTERVAL '{$days} days')";
            break;
        case 'high_value':
            $minSpent = floatval($segConfig['min_spent'] ?? 100);
            $where .= " AND EXISTS (SELECT 1 FROM om_market_orders o WHERE o.customer_id = pt.user_id GROUP BY o.customer_id HAVING SUM(o.total) >= ?)";
            $params[] = $minSpent;
            break;
    }

    $stmt = $db->prepare("SELECT COUNT(DISTINCT pt.user_id) FROM om_market_push_tokens pt WHERE {$where}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}
