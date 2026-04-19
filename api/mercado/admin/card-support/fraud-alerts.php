<?php
/**
 * GET|POST /admin/card-support/fraud-alerts.php
 *
 * GET: List fraud alerts (transactions flagged/declined or tickets category=fraud)
 *   Query: status=active|resolved|all, limit=50
 *
 * POST: { action: approve|block|review, transaction_id?|ticket_id?, reason? }
 */

require_once __DIR__ . "/_common.php";

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];
    $adminId = $ctx['admin_id'];

    if ($method === 'GET') {
        $status = (string)($_GET['status'] ?? 'active');
        $limit  = min(200, max(5, (int)($_GET['limit'] ?? 50)));

        $statusFilter = $status === 'active' ? "t.status IN ('flagged','declined')" : "1=1";

        // Flagged transactions
        $stmt = $db->prepare("
            SELECT t.id, t.card_id, t.customer_id, t.amount, t.merchant, t.category,
                   t.transaction_date, t.status,
                   cc.card_last4,
                   c.name AS customer_name, c.phone
            FROM om_credit_card_transactions t
            LEFT JOIN om_credit_cards cc ON cc.id = t.card_id
            LEFT JOIN om_customers c ON c.customer_id = t.customer_id
            WHERE {$statusFilter}
            ORDER BY t.transaction_date DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $alerts = array_map(function ($r) {
            $rules = [];
            if ((float)$r['amount'] > 1000) $rules[] = 'Valor alto';
            if ($r['status'] === 'declined') $rules[] = 'Recusada';
            if ($r['status'] === 'flagged') $rules[] = 'Marcada por sistema';
            $score = min(100, 20 + ((float)$r['amount'] > 500 ? 25 : 10) + ($r['status'] === 'declined' ? 40 : 15));
            return [
                'id'               => (int)$r['id'],
                'type'             => 'transaction',
                'card_id'          => (int)$r['card_id'],
                'customer_id'      => (int)$r['customer_id'],
                'customer_name'    => $r['customer_name'],
                'phone'            => $r['phone'],
                'card_last4'       => $r['card_last4'],
                'amount'           => (float)$r['amount'],
                'merchant'         => $r['merchant'],
                'category'         => $r['category'],
                'transaction_date' => $r['transaction_date'],
                'tx_status'        => $r['status'],
                'risk_score'       => $score,
                'rules_triggered'  => $rules,
                'ai_reasoning'     => 'Regras heurísticas: ' . implode(', ', $rules),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Fraud tickets
        $stmt = $db->prepare("
            SELECT t.id, t.customer_id, t.card_id, t.subject, t.description,
                   t.priority, t.status, t.created_at,
                   c.name AS customer_name
            FROM om_card_support_tickets t
            LEFT JOIN om_customers c ON c.customer_id = t.customer_id
            WHERE t.category = 'fraud'
              " . ($status === 'active' ? "AND t.status IN ('open','in_progress')" : "") . "
            ORDER BY t.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $tickets = array_map(function ($r) {
            return [
                'id'            => (int)$r['id'],
                'type'          => 'ticket',
                'customer_id'   => (int)$r['customer_id'],
                'card_id'       => $r['card_id'] ? (int)$r['card_id'] : null,
                'customer_name' => $r['customer_name'],
                'subject'       => $r['subject'],
                'description'   => $r['description'],
                'priority'      => $r['priority'],
                'status'        => $r['status'],
                'created_at'    => $r['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $summary = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE t.status IN ('flagged','declined') AND t.transaction_date >= CURRENT_DATE) AS today_flagged,
                COUNT(*) FILTER (WHERE t.status IN ('flagged','declined') AND t.transaction_date >= NOW() - INTERVAL '7 days') AS week_flagged,
                COALESCE(SUM(t.amount) FILTER (WHERE t.status IN ('flagged','declined') AND t.transaction_date >= NOW() - INTERVAL '30 days'), 0) AS month_volume_flagged
            FROM om_credit_card_transactions t
        ")->fetch(PDO::FETCH_ASSOC);

        response(true, [
            'alerts'  => $alerts,
            'tickets' => $tickets,
            'summary' => [
                'today_flagged'        => (int)$summary['today_flagged'],
                'week_flagged'         => (int)$summary['week_flagged'],
                'month_volume_flagged' => (float)$summary['month_volume_flagged'],
            ],
        ]);
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = (string)($body['action'] ?? '');
        $txId = (int)($body['transaction_id'] ?? 0);
        $reason = (string)($body['reason'] ?? '');
        if ($txId <= 0) response(false, null, 'transaction_id obrigatorio', 400);

        $stmt = $db->prepare("
            SELECT card_id, customer_id, amount FROM om_credit_card_transactions WHERE id = ?
        ");
        $stmt->execute([$txId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tx) response(false, null, 'Transacao nao encontrada', 404);

        $newStatus = null;
        if ($action === 'approve')      $newStatus = 'approved';
        elseif ($action === 'block')    $newStatus = 'declined';
        elseif ($action === 'mark_fraud') $newStatus = 'flagged';
        elseif ($action === 'reverse')    $newStatus = 'reversed';
        else response(false, null, 'Acao invalida', 400);

        $upd = $db->prepare("UPDATE om_credit_card_transactions SET status = ? WHERE id = ?");
        $upd->execute([$newStatus, $txId]);

        logCardSupportEvent($db, (int)$tx['card_id'], (int)$tx['customer_id'], 'fraud_' . $action, $adminId, [
            'transaction_id' => $txId,
            'new_status'     => $newStatus,
            'amount'         => (float)$tx['amount'],
        ], $reason);

        response(true, ['status' => $newStatus]);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[card-support-fraud-alerts] ' . $e->getMessage());
    response(false, null, 'Erro em fraude: ' . $e->getMessage(), 500);
}
