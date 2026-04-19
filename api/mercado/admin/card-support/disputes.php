<?php
/**
 * GET|POST /admin/card-support/disputes.php
 *
 * Manages disputes — modeled as fraud-category support tickets.
 *
 * GET:
 *   Query: status=all|open|in_progress|resolved|closed, category=all|...
 *   Response: { disputes: [...], stats: {...} }
 *
 * POST:
 *   Body: { id, action: assign|resolve|escalate|reject, assigned_to?, resolution?, reason? }
 */

require_once __DIR__ . "/_common.php";

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];
    $adminId = $ctx['admin_id'];

    // Ensure disputes table structure
    $db->exec("ALTER TABLE om_card_support_tickets ADD COLUMN IF NOT EXISTS resolution TEXT");
    $db->exec("ALTER TABLE om_card_support_tickets ADD COLUMN IF NOT EXISTS sla_hours INTEGER DEFAULT 48");
    $db->exec("ALTER TABLE om_card_support_tickets ADD COLUMN IF NOT EXISTS dispute_reason_code VARCHAR(40)");
    $db->exec("ALTER TABLE om_card_support_tickets ADD COLUMN IF NOT EXISTS disputed_transaction_id INTEGER");

    if ($method === 'GET') {
        $status = (string)($_GET['status'] ?? 'all');
        $limit  = min(200, max(10, (int)($_GET['limit'] ?? 100)));

        $conditions = ["(t.category = 'fraud' OR t.dispute_reason_code IS NOT NULL)"];
        $params = [];
        if ($status !== 'all' && $status !== '') {
            $conditions[] = 't.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $conditions);

        $stmt = $db->prepare("
            SELECT t.id, t.customer_id, t.card_id, t.subject, t.description,
                   t.category, t.priority, t.status, t.assigned_to,
                   t.created_at, t.updated_at, t.resolved_at,
                   t.resolution, t.sla_hours, t.dispute_reason_code,
                   t.disputed_transaction_id,
                   c.name AS customer_name, c.phone, c.email,
                   cc.card_last4,
                   EXTRACT(EPOCH FROM (NOW() - t.created_at))/3600.0 AS age_hours
            FROM om_card_support_tickets t
            LEFT JOIN om_customers c ON c.customer_id = t.customer_id
            LEFT JOIN om_credit_cards cc ON cc.id = t.card_id
            WHERE {$whereSql}
            ORDER BY CASE t.status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END,
                     t.created_at DESC
            LIMIT ?
        ");
        $stmt->execute(array_merge($params, [$limit]));
        $disputes = array_map(function ($r) {
            $sla = (int)($r['sla_hours'] ?? 48);
            $age = (float)$r['age_hours'];
            $slaLeft = $sla - $age;
            return [
                'id'                    => (int)$r['id'],
                'customer_id'           => (int)$r['customer_id'],
                'customer_name'         => $r['customer_name'],
                'phone'                 => $r['phone'],
                'email'                 => $r['email'],
                'card_id'               => $r['card_id'] ? (int)$r['card_id'] : null,
                'card_last4'            => $r['card_last4'],
                'subject'               => $r['subject'],
                'description'           => $r['description'],
                'category'              => $r['category'],
                'priority'              => $r['priority'],
                'status'                => $r['status'],
                'assigned_to'           => $r['assigned_to'] ? (int)$r['assigned_to'] : null,
                'created_at'            => $r['created_at'],
                'updated_at'            => $r['updated_at'],
                'resolved_at'           => $r['resolved_at'],
                'resolution'            => $r['resolution'],
                'reason_code'           => $r['dispute_reason_code'],
                'disputed_transaction_id' => $r['disputed_transaction_id'] ? (int)$r['disputed_transaction_id'] : null,
                'age_hours'             => round($age, 1),
                'sla_hours'             => $sla,
                'sla_hours_remaining'   => round($slaLeft, 1),
                'sla_breached'          => $slaLeft < 0,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stats = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE status IN ('open','in_progress'))          AS active,
                COUNT(*) FILTER (WHERE status = 'resolved')                        AS resolved,
                COUNT(*) FILTER (WHERE status = 'open' AND EXTRACT(EPOCH FROM (NOW()-created_at))/3600 > COALESCE(sla_hours,48)) AS sla_breached
            FROM om_card_support_tickets
            WHERE category = 'fraud'
        ")->fetch(PDO::FETCH_ASSOC);

        response(true, [
            'disputes' => $disputes,
            'stats'    => [
                'active'        => (int)$stats['active'],
                'resolved'      => (int)$stats['resolved'],
                'sla_breached'  => (int)$stats['sla_breached'],
            ],
        ]);
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($body['id'] ?? 0);
        $action = (string)($body['action'] ?? '');
        if ($id <= 0 || !$action) response(false, null, 'id e action obrigatorios', 400);

        $stmt = $db->prepare("SELECT customer_id, card_id FROM om_card_support_tickets WHERE id = ?");
        $stmt->execute([$id]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$t) response(false, null, 'Disputa nao encontrada', 404);

        if ($action === 'assign') {
            $asg = (int)($body['assigned_to'] ?? $adminId);
            $upd = $db->prepare("UPDATE om_card_support_tickets SET assigned_to = ?, status = 'in_progress', updated_at = NOW() WHERE id = ?");
            $upd->execute([$asg, $id]);
        } elseif ($action === 'resolve') {
            $res = (string)($body['resolution'] ?? '');
            $upd = $db->prepare("UPDATE om_card_support_tickets SET status = 'resolved', resolution = ?, resolved_at = NOW(), updated_at = NOW() WHERE id = ?");
            $upd->execute([$res, $id]);
        } elseif ($action === 'escalate') {
            $upd = $db->prepare("UPDATE om_card_support_tickets SET priority = 'urgent', updated_at = NOW() WHERE id = ?");
            $upd->execute([$id]);
        } elseif ($action === 'reject') {
            $res = (string)($body['reason'] ?? '');
            $upd = $db->prepare("UPDATE om_card_support_tickets SET status = 'closed', resolution = ?, resolved_at = NOW(), updated_at = NOW() WHERE id = ?");
            $upd->execute([$res, $id]);
        } else {
            response(false, null, 'Acao invalida', 400);
        }

        logCardSupportEvent($db, (int)($t['card_id'] ?? 0), (int)$t['customer_id'], 'dispute_' . $action, $adminId, ['ticket_id' => $id], (string)($body['resolution'] ?? $body['reason'] ?? ''));
        response(true, ['status' => 'ok']);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[card-support-disputes] ' . $e->getMessage());
    response(false, null, 'Erro em disputas: ' . $e->getMessage(), 500);
}
