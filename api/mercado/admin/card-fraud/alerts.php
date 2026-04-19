<?php
/**
 * GET  /admin/card-fraud/alerts.php
 *   Query: action=blocked|challenged|reviewed|approved|all,
 *          status=pending|resolved|escalated|all,
 *          min_score, from, to, search, page, limit
 *   Returns: { alerts: [...], pagination: { page, limit, total, pages } }
 *
 * GET  /admin/card-fraud/alerts.php?id=<alert_id>
 *   Returns full alert detail including rules_triggered + AI reasoning.
 *
 * POST /admin/card-fraud/alerts.php
 *   Body: { id, decision: "approve"|"block"|"lock_card"|"mark_reviewed", notes }
 *   Admin override: resolves alert + optionally locks card / unlocks it.
 */

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../helpers/card-fraud-detector.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();
    $adminId = (int)($payload['uid'] ?? $payload['user_id'] ?? 0);
    $adminName = (string)($payload['name'] ?? $payload['username'] ?? 'Admin');

    // Ensure tables even if migration hasn't run
    new CardFraudDetector($db);

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            handleDetail($db, $id);
        }

        $action   = $_GET['action']   ?? 'all';   // blocked/challenged/reviewed/approved
        $status   = $_GET['status']   ?? 'all';
        $minScore = (int)($_GET['min_score'] ?? 0);
        $search   = trim((string)($_GET['search'] ?? ''));
        $from     = $_GET['from']     ?? null;
        $to       = $_GET['to']       ?? null;
        $limit    = min(100, max(1, (int)($_GET['limit'] ?? 25)));
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $offset   = ($page - 1) * $limit;

        $where = ['1=1'];
        $args  = [];

        if ($action !== 'all' && in_array($action, ['block','blocked','challenge','challenged','review','reviewed','approve','approved'], true)) {
            // Map plural to singular
            $map = [
                'blocked' => 'block', 'challenged' => 'challenge',
                'reviewed' => 'review', 'approved' => 'approve',
            ];
            $norm = $map[$action] ?? $action;
            $where[] = 'a.action_taken = ?';
            $args[]  = $norm;
        }
        if ($status !== 'all') {
            $where[] = 'a.status = ?';
            $args[]  = $status;
        }
        if ($minScore > 0) {
            $where[] = 'a.risk_score >= ?';
            $args[]  = $minScore;
        }
        if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'a.created_at >= ?'; $args[] = $from . ' 00:00:00';
        }
        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'a.created_at <= ?'; $args[] = $to . ' 23:59:59';
        }
        if ($search !== '') {
            $where[] = '(a.merchant ILIKE ? OR CAST(a.card_id AS TEXT) = ? OR CAST(a.customer_id AS TEXT) = ? OR a.ip_address = ?)';
            $like = '%' . $search . '%';
            $args = array_merge($args, [$like, $search, $search, $search]);
        }

        $whereSql = implode(' AND ', $where);

        // Total
        $totalStmt = $db->prepare("SELECT COUNT(*) FROM om_card_fraud_alerts a WHERE {$whereSql}");
        $totalStmt->execute($args);
        $total = (int)$totalStmt->fetchColumn();

        // Rows (JOIN customer name — soft)
        $sql = "
            SELECT a.id, a.transaction_id, a.card_id, a.customer_id, a.amount, a.merchant,
                   a.category, a.ip_address, a.device_id, a.risk_score, a.rules_score, a.ai_score,
                   a.action_taken, a.status, a.admin_override, a.admin_decision,
                   a.customer_response, a.created_at, a.resolved_at,
                   c.name, c.email
            FROM om_card_fraud_alerts a
            LEFT JOIN om_customers c ON c.customer_id = a.customer_id
            WHERE {$whereSql}
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $args2 = array_merge($args, [$limit, $offset]);
        $stmt = $db->prepare($sql);
        $stmt->execute($args2);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $alerts = array_map(function ($r) {
            return [
                'id'              => (int)$r['id'],
                'transaction_id'  => $r['transaction_id'] ? (int)$r['transaction_id'] : null,
                'card_id'         => (int)$r['card_id'],
                'customer_id'     => (int)$r['customer_id'],
                'customer_name'   => trim($r['name'] ?? '') ?: null,
                'customer_email'  => $r['email'] ?? null,
                'amount'          => (float)$r['amount'],
                'merchant'        => $r['merchant'],
                'category'        => $r['category'],
                'ip_address'      => $r['ip_address'],
                'device_id'       => $r['device_id'],
                'risk_score'      => (int)$r['risk_score'],
                'rules_score'     => (int)$r['rules_score'],
                'ai_score'        => (int)$r['ai_score'],
                'action_taken'    => $r['action_taken'],
                'status'          => $r['status'],
                'admin_override'  => (bool)$r['admin_override'],
                'admin_decision'  => $r['admin_decision'],
                'customer_response' => $r['customer_response'],
                'created_at'      => $r['created_at'],
                'resolved_at'     => $r['resolved_at'],
            ];
        }, $rows);

        response(true, [
            'alerts' => $alerts,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $alertId = (int)($input['id'] ?? 0);
        $decision = trim((string)($input['decision'] ?? ''));
        $notes = substr(trim((string)($input['notes'] ?? '')), 0, 2000);

        if (!$alertId || !in_array($decision, ['approve', 'block', 'lock_card', 'unlock_card', 'mark_reviewed'], true)) {
            response(false, null, 'Parametros invalidos', 400);
        }

        $alertStmt = $db->prepare("SELECT * FROM om_card_fraud_alerts WHERE id = ? LIMIT 1");
        $alertStmt->execute([$alertId]);
        $alert = $alertStmt->fetch(PDO::FETCH_ASSOC);
        if (!$alert) {
            response(false, null, 'Alerta nao encontrado', 404);
        }

        $db->beginTransaction();
        try {
            $updates = [
                'admin_override' => 'true',
                'admin_id'       => (int)$adminId,
                'admin_decision' => $decision,
                'admin_notes'    => $notes,
            ];

            if ($decision === 'approve') {
                $updates['status']      = 'resolved';
                $updates['resolved_at'] = 'NOW()';
            } elseif ($decision === 'mark_reviewed') {
                $updates['status']      = 'resolved';
                $updates['resolved_at'] = 'NOW()';
            } elseif ($decision === 'block') {
                $updates['status']      = 'resolved';
                $updates['resolved_at'] = 'NOW()';
                // Reverse the transaction if present
                if (!empty($alert['transaction_id'])) {
                    reverseTransaction($db, (int)$alert['transaction_id'], (int)$alert['card_id']);
                }
            } elseif ($decision === 'lock_card') {
                $updates['status']      = 'resolved';
                $updates['resolved_at'] = 'NOW()';
                $db->prepare("
                    UPDATE om_credit_cards
                    SET status = 'blocked', blocked_at = NOW(),
                        block_reason = 'Fraude confirmada pelo admin'
                    WHERE id = ? AND status = 'active'
                ")->execute([$alert['card_id']]);
            } elseif ($decision === 'unlock_card') {
                $updates['status']      = 'resolved';
                $updates['resolved_at'] = 'NOW()';
                $db->prepare("
                    UPDATE om_credit_cards
                    SET status = 'active', blocked_at = NULL, block_reason = NULL
                    WHERE id = ? AND status = 'blocked'
                ")->execute([$alert['card_id']]);
            }

            // Build dynamic UPDATE (NOW() is a raw expression)
            $sets = []; $args = [];
            foreach ($updates as $col => $val) {
                if ($val === 'NOW()') { $sets[] = "{$col} = NOW()"; }
                else                  { $sets[] = "{$col} = ?"; $args[] = $val; }
            }
            $args[] = $alertId;
            $sql = "UPDATE om_card_fraud_alerts SET " . implode(', ', $sets) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($args);

            // Event log
            $db->prepare("
                INSERT INTO om_credit_card_events
                  (card_id, customer_id, event_type, actor_type, actor_id, payload, notes)
                VALUES (?, ?, ?, 'admin', ?, ?, ?)
            ")->execute([
                $alert['card_id'], $alert['customer_id'],
                'fraud_admin_' . $decision, $adminId,
                json_encode(['alert_id' => $alertId, 'decision' => $decision], JSON_UNESCAPED_UNICODE),
                $notes ?: null,
            ]);

            $db->commit();
            response(true, ['ok' => true, 'decision' => $decision, 'alert_id' => $alertId]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[admin-card-fraud] ' . $e->getMessage());
            response(false, null, 'Erro ao aplicar decisao', 500);
        }
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[admin-card-fraud] ' . $e->getMessage());
    response(false, null, 'Erro ao processar requisicao', 500);
}

// ──────────────────────────────────────────────────────────────
function handleDetail(PDO $db, int $id): void {
    $stmt = $db->prepare("
        SELECT a.*,
               c.name, c.email, c.phone,
               cc.card_last4, cc.card_brand, cc.status AS card_status, cc.credit_limit
        FROM om_card_fraud_alerts a
        LEFT JOIN om_customers c ON c.customer_id = a.customer_id
        LEFT JOIN om_credit_cards cc ON cc.id = a.card_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) response(false, null, 'Alerta nao encontrado', 404);

    // Recent transactions on same card
    $tx = $db->prepare("
        SELECT id, amount, merchant, category, transaction_date, status, risk_score, fraud_action
        FROM om_credit_card_transactions
        WHERE card_id = ?
        ORDER BY transaction_date DESC
        LIMIT 20
    ");
    $tx->execute([$a['card_id']]);
    $recentTx = $tx->fetchAll(PDO::FETCH_ASSOC);

    // Other alerts on this customer
    $alts = $db->prepare("
        SELECT id, risk_score, action_taken, status, merchant, amount, created_at
        FROM om_card_fraud_alerts
        WHERE customer_id = ? AND id <> ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $alts->execute([$a['customer_id'], $id]);
    $otherAlerts = $alts->fetchAll(PDO::FETCH_ASSOC);

    $rulesTrig = json_decode($a['rules_triggered'] ?? '[]', true) ?: [];
    $aiResp    = $a['ai_response'] ? (json_decode($a['ai_response'], true) ?: null) : null;

    response(true, [
        'alert' => [
            'id'              => (int)$a['id'],
            'transaction_id'  => $a['transaction_id'] ? (int)$a['transaction_id'] : null,
            'card_id'         => (int)$a['card_id'],
            'card_last4'      => $a['card_last4'],
            'card_brand'      => $a['card_brand'],
            'card_status'     => $a['card_status'],
            'credit_limit'    => $a['credit_limit'] !== null ? (float)$a['credit_limit'] : null,
            'customer_id'     => (int)$a['customer_id'],
            'customer_name'   => trim($a['name'] ?? '') ?: null,
            'customer_email'  => $a['email'],
            'customer_phone'  => $a['phone'],
            'amount'          => (float)$a['amount'],
            'merchant'        => $a['merchant'],
            'category'        => $a['category'],
            'ip_address'      => $a['ip_address'],
            'user_agent'      => $a['user_agent'],
            'device_id'       => $a['device_id'],
            'lat'             => $a['lat'] !== null ? (float)$a['lat'] : null,
            'lng'             => $a['lng'] !== null ? (float)$a['lng'] : null,
            'risk_score'      => (int)$a['risk_score'],
            'rules_score'     => (int)$a['rules_score'],
            'ai_score'        => (int)$a['ai_score'],
            'action_taken'    => $a['action_taken'],
            'status'          => $a['status'],
            'rules_triggered' => $rulesTrig,
            'ai_reasoning'    => $a['ai_reasoning'],
            'ai_response'     => $aiResp,
            'admin_override'  => (bool)$a['admin_override'],
            'admin_id'        => $a['admin_id'] ? (int)$a['admin_id'] : null,
            'admin_decision'  => $a['admin_decision'],
            'admin_notes'     => $a['admin_notes'],
            'customer_response' => $a['customer_response'],
            'created_at'      => $a['created_at'],
            'resolved_at'     => $a['resolved_at'],
        ],
        'recent_transactions' => $recentTx,
        'other_alerts'        => $otherAlerts,
    ]);
}

/**
 * Reverse a transaction when admin confirms fraud.
 */
function reverseTransaction(PDO $db, int $txId, int $cardId): void {
    $tx = $db->prepare("SELECT amount, status FROM om_credit_card_transactions WHERE id = ? LIMIT 1");
    $tx->execute([$txId]);
    $row = $tx->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    if (($row['status'] ?? '') === 'reversed') return;

    $db->prepare("UPDATE om_credit_card_transactions SET status = 'reversed' WHERE id = ?")->execute([$txId]);
    // Return limit
    $db->prepare("UPDATE om_credit_cards SET used_limit = GREATEST(0, used_limit - ?) WHERE id = ?")
       ->execute([(float)$row['amount'], $cardId]);
}
