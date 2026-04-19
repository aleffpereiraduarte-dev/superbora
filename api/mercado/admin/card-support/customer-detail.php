<?php
/**
 * GET /admin/card-support/customer-detail.php?customer_id=123
 *
 * Full customer card info for the support detail view.
 *
 * Response.data:
 *   {
 *     customer:   {...},
 *     cards:      [...],             // all cards for this customer
 *     current_card: {...} | null,    // active (or best) card
 *     bills:      [...],             // last 6 bills of current card
 *     transactions: [...],           // last 30 transactions across any card
 *     events:     [...],             // last 50 timeline events
 *     evaluation: {...} | null,      // latest AI credit evaluation
 *     notes:      [...],             // support notes
 *     tickets:    [...]              // recent support tickets
 *   }
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];

    $customerId = (int)($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) response(false, null, 'customer_id obrigatorio', 400);

    // Customer
    $stmt = $db->prepare("
        SELECT customer_id, name, email, phone, cpf, created_at AS customer_since,
               addr_city, addr_state
        FROM om_customers WHERE customer_id = ? LIMIT 1
    ");
    try {
        $stmt->execute([$customerId]);
        $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // fallback if addr_* columns dont exist
        $stmt = $db->prepare("SELECT customer_id, name, email, phone, cpf, created_at AS customer_since FROM om_customers WHERE customer_id = ? LIMIT 1");
        $stmt->execute([$customerId]);
        $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$cust) response(false, null, 'Cliente nao encontrado', 404);

    $customer = [
        'customer_id'    => (int)$cust['customer_id'],
        'name'           => $cust['name'],
        'email'          => $cust['email'],
        'phone'          => $cust['phone'],
        'cpf_masked'     => cardSupportMaskCpf($cust['cpf']),
        'customer_since' => $cust['customer_since'],
        'city'           => $cust['addr_city'] ?? null,
        'state'          => $cust['addr_state'] ?? null,
    ];

    // Cards
    $stmt = $db->prepare("
        SELECT * FROM om_credit_cards WHERE customer_id = ? ORDER BY
            CASE status WHEN 'active' THEN 0 WHEN 'blocked' THEN 1 WHEN 'pre_approved' THEN 2 ELSE 3 END,
            created_at DESC
    ");
    $stmt->execute([$customerId]);
    $cardRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cards = array_map(function ($r) {
        $limit = (float)$r['credit_limit'];
        $used  = (float)$r['used_limit'];
        return [
            'id'              => (int)$r['id'],
            'card_brand'      => $r['card_brand'],
            'card_last4'      => $r['card_last4'],
            'masked_number'   => '**** **** **** ' . ($r['card_last4'] ?: '0000'),
            'expires_at'      => $r['expires_at'],
            'status'          => $r['status'],
            'virtual'         => (bool)$r['virtual'],
            'credit_limit'    => $limit,
            'used_limit'      => $used,
            'available_limit' => max(0, $limit - $used),
            'utilization'     => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
            'score'           => $r['score'] !== null ? (int)$r['score'] : null,
            'closing_day'     => (int)($r['closing_day'] ?? 5),
            'due_day'         => (int)($r['due_day']     ?? 15),
            'created_at'      => $r['created_at'],
            'approved_at'     => $r['approved_at'],
            'activated_at'    => $r['activated_at'],
            'blocked_at'      => $r['blocked_at'],
            'block_reason'    => $r['block_reason'],
            'cancelled_at'    => $r['cancelled_at'] ?? null,
            'cancel_reason'   => $r['cancel_reason']  ?? null,
        ];
    }, $cardRows);

    $currentCard = $cards[0] ?? null;
    $currentCardId = $currentCard['id'] ?? null;

    // Bills (last 6) for current card
    $bills = [];
    if ($currentCardId) {
        $stmt = $db->prepare("
            SELECT id, period_start, period_end, due_date, total_amount,
                   minimum_amount, paid_amount, status, closed_at, paid_at
            FROM om_credit_card_bills
            WHERE card_id = ?
            ORDER BY period_start DESC
            LIMIT 6
        ");
        $stmt->execute([$currentCardId]);
        $bills = array_map(function ($b) {
            $overdue = $b['status'] !== 'paid' && !empty($b['due_date']) && strtotime($b['due_date']) < strtotime('today');
            return [
                'id'             => (int)$b['id'],
                'period_start'   => $b['period_start'],
                'period_end'     => $b['period_end'],
                'due_date'       => $b['due_date'],
                'total_amount'   => (float)$b['total_amount'],
                'minimum_amount' => (float)$b['minimum_amount'],
                'paid_amount'    => (float)$b['paid_amount'],
                'remaining'      => max(0, (float)$b['total_amount'] - (float)$b['paid_amount']),
                'status'         => $b['status'],
                'is_overdue'     => $overdue,
                'closed_at'      => $b['closed_at'],
                'paid_at'        => $b['paid_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // Transactions (last 30 across any card)
    $stmt = $db->prepare("
        SELECT t.id, t.card_id, t.amount, t.merchant, t.category,
               t.installments, t.installment_number, t.transaction_date, t.status,
               cc.card_last4
        FROM om_credit_card_transactions t
        LEFT JOIN om_credit_cards cc ON cc.id = t.card_id
        WHERE t.customer_id = ?
        ORDER BY t.transaction_date DESC
        LIMIT 30
    ");
    $stmt->execute([$customerId]);
    $transactions = array_map(function ($t) {
        return [
            'id'                 => (int)$t['id'],
            'card_id'            => (int)$t['card_id'],
            'card_last4'         => $t['card_last4'],
            'amount'             => (float)$t['amount'],
            'merchant'           => $t['merchant'],
            'category'           => $t['category'],
            'installments'       => (int)$t['installments'],
            'installment_number' => (int)$t['installment_number'],
            'transaction_date'   => $t['transaction_date'],
            'status'             => $t['status'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Events timeline (last 50)
    $stmt = $db->prepare("
        SELECT id, card_id, event_type, actor_type, actor_id, payload, metadata, notes, created_at
        FROM om_credit_card_events
        WHERE customer_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$customerId]);
    $events = array_map(function ($e) {
        $payload = null;
        if (!empty($e['payload'])) {
            try { $payload = json_decode($e['payload'], true); } catch (Exception $ex) { $payload = null; }
        }
        $meta = null;
        if (!empty($e['metadata'])) {
            try { $meta = json_decode($e['metadata'], true); } catch (Exception $ex) { $meta = null; }
        }
        return [
            'id'          => (int)$e['id'],
            'card_id'     => (int)$e['card_id'],
            'event_type'  => $e['event_type'],
            'actor_type'  => $e['actor_type'],
            'actor_id'    => $e['actor_id'] ? (int)$e['actor_id'] : null,
            'payload'     => $payload,
            'metadata'    => $meta,
            'notes'       => $e['notes'],
            'created_at'  => $e['created_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // AI Evaluation (latest, if exists)
    $evaluation = null;
    try {
        $stmt = $db->prepare("SELECT * FROM om_credit_evaluations WHERE customer_id = ? ORDER BY evaluated_at DESC LIMIT 1");
        $stmt->execute([$customerId]);
        $ev = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ev) {
            $evaluation = [
                'id'                  => (int)$ev['id'],
                'overall_score'       => (int)($ev['overall_score'] ?? 0),
                'recommended_limit'   => (float)($ev['recommended_limit'] ?? 0),
                'final_decision'      => $ev['final_decision'] ?? null,
                'final_limit'         => (float)($ev['final_limit'] ?? 0),
                'ai_decision'         => $ev['ai_decision'] ?? null,
                'ai_reasoning'        => $ev['ai_reasoning'] ?? null,
                'ai_red_flags'        => !empty($ev['ai_red_flags']) ? json_decode($ev['ai_red_flags'], true) : [],
                'ai_positive_signals' => !empty($ev['ai_positive_signals']) ? json_decode($ev['ai_positive_signals'], true) : [],
                'evaluated_at'        => $ev['evaluated_at'] ?? null,
            ];
        }
    } catch (Exception $e) { /* table missing is ok */ }

    // Notes
    $stmt = $db->prepare("
        SELECT id, card_id, agent_id, agent_name, note, visibility, created_at
        FROM om_card_support_notes
        WHERE customer_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$customerId]);
    $notes = array_map(function ($n) {
        return [
            'id'         => (int)$n['id'],
            'card_id'    => $n['card_id'] ? (int)$n['card_id'] : null,
            'agent_id'   => (int)$n['agent_id'],
            'agent_name' => $n['agent_name'],
            'note'       => $n['note'],
            'visibility' => $n['visibility'],
            'created_at' => $n['created_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Tickets (related to cards)
    $stmt = $db->prepare("
        SELECT id, card_id, subject, description, category, priority, status,
               assigned_to, created_by, created_at, resolved_at
        FROM om_card_support_tickets
        WHERE customer_id = ?
        ORDER BY
            CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END,
            created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$customerId]);
    $tickets = array_map(function ($t) {
        return [
            'id'          => (int)$t['id'],
            'card_id'     => $t['card_id'] ? (int)$t['card_id'] : null,
            'subject'     => $t['subject'],
            'description' => $t['description'],
            'category'    => $t['category'],
            'priority'    => $t['priority'],
            'status'      => $t['status'],
            'assigned_to' => $t['assigned_to'] ? (int)$t['assigned_to'] : null,
            'created_by'  => $t['created_by']  ? (int)$t['created_by']  : null,
            'created_at'  => $t['created_at'],
            'resolved_at' => $t['resolved_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Quick summary metrics
    $summary = [
        'total_cards'        => count($cards),
        'has_active_card'    => (bool)array_filter($cards, fn($c) => $c['status'] === 'active'),
        'total_limit'        => array_sum(array_map(fn($c) => $c['status'] === 'active' ? $c['credit_limit'] : 0, $cards)),
        'total_used'         => array_sum(array_map(fn($c) => $c['status'] === 'active' ? $c['used_limit']   : 0, $cards)),
        'overdue_bills'      => count(array_filter($bills, fn($b) => $b['is_overdue'])),
        'total_transactions' => count($transactions),
        'open_tickets'       => count(array_filter($tickets, fn($t) => in_array($t['status'], ['open', 'in_progress'], true))),
    ];

    response(true, [
        'customer'     => $customer,
        'summary'      => $summary,
        'cards'        => $cards,
        'current_card' => $currentCard,
        'bills'        => $bills,
        'transactions' => $transactions,
        'events'       => $events,
        'evaluation'   => $evaluation,
        'notes'        => $notes,
        'tickets'      => $tickets,
    ]);
} catch (Exception $e) {
    error_log('[card-support-customer-detail] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar detalhes', 500);
}
