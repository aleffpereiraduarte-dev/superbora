<?php
/**
 * GET /admin/cards/detail.php?card_id=123
 *
 * Full detail of a credit card:
 *   - customer info
 *   - card info (masked)
 *   - linked credit evaluation (AI score + reasoning)
 *   - transaction history (last 100)
 *   - bill history (last 24)
 *   - timeline of events (admin actions, system events)
 *
 * Response.data:
 *   {
 *     card:      {...},
 *     customer:  {...},
 *     evaluation:{...} | null,
 *     transactions: [...],
 *     bills: [...],
 *     events: [...]
 *   }
 */

require_once __DIR__ . "/../../customer/card/_common.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    om_auth()->requireAdmin();
    ensureCardTables($db);

    $cardId = (int)($_GET['card_id'] ?? 0);
    if ($cardId <= 0) response(false, null, 'card_id obrigatorio', 400);

    $stmt = $db->prepare("
        SELECT cc.*, c.name AS customer_name, c.email, c.phone, c.cpf, c.created_at AS customer_since
        FROM om_credit_cards cc
        LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
        WHERE cc.id = ?
        LIMIT 1
    ");
    $stmt->execute([$cardId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) response(false, null, 'Cartao nao encontrado', 404);

    $cpf = preg_replace('/\D/', '', (string)($row['cpf'] ?? ''));
    $cpfMasked = strlen($cpf) === 11
        ? '***.***.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2)
        : null;

    $limit = (float)$row['credit_limit'];
    $used  = (float)$row['used_limit'];

    $card = [
        'id'                  => (int)$row['id'],
        'customer_id'         => (int)$row['customer_id'],
        'card_brand'          => $row['card_brand'],
        'card_last4'          => $row['card_last4'],
        'masked_number'       => '**** **** **** ' . ($row['card_last4'] ?: '0000'),
        'expires_at'          => $row['expires_at'],
        'credit_limit'        => $limit,
        'used_limit'          => $used,
        'available_limit'     => max(0, $limit - $used),
        'utilization'         => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
        'status'              => $row['status'],
        'virtual'             => (bool)$row['virtual'],
        'declared_income'     => $row['declared_income'] !== null ? (float)$row['declared_income'] : null,
        'score'               => $row['score'] !== null ? (int)$row['score'] : null,
        'closing_day'         => (int)($row['closing_day'] ?? 5),
        'due_day'             => (int)($row['due_day']     ?? 15),
        'created_at'          => $row['created_at'],
        'approved_at'         => $row['approved_at'],
        'activated_at'        => $row['activated_at'],
        'blocked_at'          => $row['blocked_at'],
        'block_reason'        => $row['block_reason'],
        'cancelled_at'        => $row['cancelled_at'],
        'cancel_reason'       => $row['cancel_reason'],
        'offer_created_at'    => $row['offer_created_at']    ?? null,
        'offer_expires_at'    => $row['offer_expires_at']    ?? null,
        'offer_interest_rate' => isset($row['offer_terms_interest_rate']) && $row['offer_terms_interest_rate'] !== null ? (float)$row['offer_terms_interest_rate'] : null,
        'offer_annual_fee'    => isset($row['offer_terms_annual_fee']) && $row['offer_terms_annual_fee'] !== null ? (float)$row['offer_terms_annual_fee'] : null,
        'accepted_at'         => $row['accepted_at']         ?? null,
        'declined_at'         => $row['declined_at']         ?? null,
        'offered_by_admin_id' => !empty($row['offered_by_admin_id']) ? (int)$row['offered_by_admin_id'] : null,
        'credit_evaluation_id'=> !empty($row['credit_evaluation_id']) ? (int)$row['credit_evaluation_id'] : null,
    ];

    $customer = [
        'customer_id'    => (int)$row['customer_id'],
        'name'           => $row['customer_name'],
        'email'          => $row['email'],
        'phone'          => $row['phone'],
        'cpf_masked'     => $cpfMasked,
        'customer_since' => $row['customer_since'],
    ];

    // Latest credit evaluation for this customer (or tied one)
    $evaluation = null;
    try {
        if (!empty($card['credit_evaluation_id'])) {
            $stmt = $db->prepare("SELECT * FROM om_credit_evaluations WHERE id = ? LIMIT 1");
            $stmt->execute([$card['credit_evaluation_id']]);
        } else {
            $stmt = $db->prepare("SELECT * FROM om_credit_evaluations WHERE customer_id = ? ORDER BY evaluated_at DESC LIMIT 1");
            $stmt->execute([$card['customer_id']]);
        }
        $ev = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ev) {
            $evaluation = [
                'id'                => (int)$ev['id'],
                'overall_score'     => (int)$ev['overall_score'],
                'recommended_limit' => (float)$ev['recommended_limit'],
                'final_decision'    => $ev['final_decision'],
                'final_limit'       => (float)$ev['final_limit'],
                'ai_decision'       => $ev['ai_decision'],
                'ai_reasoning'      => $ev['ai_reasoning'],
                'ai_red_flags'      => $ev['ai_red_flags'] ? json_decode($ev['ai_red_flags'], true) : [],
                'ai_positive_signals' => $ev['ai_positive_signals'] ? json_decode($ev['ai_positive_signals'], true) : [],
                'factor_scores'     => [
                    'app_usage'        => (int)$ev['app_usage_score'],
                    'purchase_behavior'=> (int)$ev['purchase_behavior_score'],
                    'payment_behavior' => (int)$ev['payment_behavior_score'],
                    'external_data'    => (int)$ev['external_data_score'],
                    'social_signals'   => (int)$ev['social_signals_score'],
                ],
                'admin_override'    => (bool)$ev['admin_override'],
                'admin_override_notes' => $ev['admin_override_notes'],
                'evaluated_at'      => $ev['evaluated_at'],
            ];
        }
    } catch (Exception $e) {
        // evaluations table may not exist in some environments
        error_log('[admin-cards-detail evaluation] ' . $e->getMessage());
    }

    // Transactions (last 100)
    $stmt = $db->prepare("
        SELECT id, amount, merchant, category, installments, installment_number,
               bill_id, transaction_date, status
        FROM om_credit_card_transactions
        WHERE card_id = ?
        ORDER BY transaction_date DESC
        LIMIT 100
    ");
    $stmt->execute([$cardId]);
    $transactions = array_map(function ($t) {
        return [
            'id'                 => (int)$t['id'],
            'amount'             => (float)$t['amount'],
            'merchant'           => $t['merchant'],
            'category'           => $t['category'],
            'installments'       => (int)$t['installments'],
            'installment_number' => (int)$t['installment_number'],
            'bill_id'            => $t['bill_id'] ? (int)$t['bill_id'] : null,
            'transaction_date'   => $t['transaction_date'],
            'status'             => $t['status'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Bills (last 24 months)
    $stmt = $db->prepare("
        SELECT id, period_start, period_end, due_date, total_amount, minimum_amount,
               paid_amount, status, closed_at, paid_at
        FROM om_credit_card_bills
        WHERE card_id = ?
        ORDER BY period_start DESC
        LIMIT 24
    ");
    $stmt->execute([$cardId]);
    $bills = array_map(function ($b) {
        $overdue = !in_array($b['status'], ['paid'], true) && !empty($b['due_date']) && strtotime($b['due_date']) < strtotime('today');
        return [
            'id'             => (int)$b['id'],
            'period_start'   => $b['period_start'],
            'period_end'     => $b['period_end'],
            'due_date'       => $b['due_date'],
            'total_amount'   => (float)$b['total_amount'],
            'minimum_amount' => (float)$b['minimum_amount'],
            'paid_amount'    => (float)$b['paid_amount'],
            'status'         => $b['status'],
            'is_overdue'     => $overdue,
            'closed_at'      => $b['closed_at'],
            'paid_at'        => $b['paid_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Timeline events
    $stmt = $db->prepare("
        SELECT id, event_type, actor_type, actor_id, payload, notes, created_at
        FROM om_credit_card_events
        WHERE card_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$cardId]);
    $events = array_map(function ($ev) {
        return [
            'id'          => (int)$ev['id'],
            'event_type'  => $ev['event_type'],
            'actor_type'  => $ev['actor_type'],
            'actor_id'    => $ev['actor_id'] ? (int)$ev['actor_id'] : null,
            'payload'     => $ev['payload'] ? json_decode($ev['payload'], true) : null,
            'notes'       => $ev['notes'],
            'created_at'  => $ev['created_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    response(true, [
        'card'         => $card,
        'customer'     => $customer,
        'evaluation'   => $evaluation,
        'transactions' => $transactions,
        'bills'        => $bills,
        'events'       => $events,
    ]);
} catch (Exception $e) {
    error_log('[admin-cards-detail] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar detalhes', 500);
}
