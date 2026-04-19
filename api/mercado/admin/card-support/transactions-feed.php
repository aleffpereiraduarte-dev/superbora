<?php
/**
 * GET /admin/card-support/transactions-feed.php
 *
 * Realtime-ish feed of transactions with filters.
 *
 * Query:
 *   limit           - default 100 (max 500)
 *   min_amount, max_amount
 *   merchant        - ILIKE match
 *   category        - exact match
 *   card_id         - int
 *   customer_id     - int
 *   status          - all|approved|declined|flagged|reversed|disputed
 *   flagged         - 1 only flagged
 *   since           - timestamp, for polling
 *
 * Response.data: { transactions: [...], last_id }
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];

    $limit       = min(500, max(10, (int)($_GET['limit'] ?? 100)));
    $minAmount   = (float)($_GET['min_amount'] ?? 0);
    $maxAmount   = (float)($_GET['max_amount'] ?? 0);
    $merchant    = trim((string)($_GET['merchant'] ?? ''));
    $category    = trim((string)($_GET['category'] ?? ''));
    $cardId      = (int)($_GET['card_id']    ?? 0);
    $customerId  = (int)($_GET['customer_id'] ?? 0);
    $status      = (string)($_GET['status'] ?? 'all');
    $flagged     = (int)($_GET['flagged'] ?? 0) === 1;
    $since       = trim((string)($_GET['since'] ?? ''));

    $conditions = ['1=1'];
    $params = [];

    if ($minAmount > 0) { $conditions[] = 't.amount >= ?'; $params[] = $minAmount; }
    if ($maxAmount > 0) { $conditions[] = 't.amount <= ?'; $params[] = $maxAmount; }
    if ($merchant !== '') { $conditions[] = 't.merchant ILIKE ?'; $params[] = '%' . $merchant . '%'; }
    if ($category !== '') { $conditions[] = 't.category = ?';     $params[] = $category; }
    if ($cardId > 0)     { $conditions[] = 't.card_id = ?';      $params[] = $cardId; }
    if ($customerId > 0) { $conditions[] = 't.customer_id = ?';  $params[] = $customerId; }
    if ($status !== 'all' && $status !== '') {
        $conditions[] = 't.status = ?'; $params[] = $status;
    }
    if ($flagged) {
        $conditions[] = "t.status IN ('flagged','declined')";
    }
    if ($since !== '') {
        $conditions[] = 't.transaction_date > ?';
        $params[] = $since;
    }

    $whereSql = implode(' AND ', $conditions);

    $sql = "
        SELECT t.id, t.card_id, t.customer_id, t.amount, t.merchant, t.category,
               t.installments, t.installment_number, t.transaction_date, t.status,
               t.bill_id, t.external_id,
               cc.card_last4, cc.card_brand,
               c.name AS customer_name, c.phone
        FROM om_credit_card_transactions t
        LEFT JOIN om_credit_cards cc ON cc.id = t.card_id
        LEFT JOIN om_customers c ON c.customer_id = t.customer_id
        WHERE {$whereSql}
        ORDER BY t.transaction_date DESC, t.id DESC
        LIMIT ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($params, [$limit]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $transactions = array_map(function ($r) {
        return [
            'id'                 => (int)$r['id'],
            'card_id'            => (int)$r['card_id'],
            'customer_id'        => (int)$r['customer_id'],
            'customer_name'      => $r['customer_name'],
            'phone'              => $r['phone'],
            'card_last4'         => $r['card_last4'],
            'card_brand'         => $r['card_brand'],
            'amount'             => (float)$r['amount'],
            'merchant'           => $r['merchant'],
            'category'           => $r['category'],
            'installments'       => (int)$r['installments'],
            'installment_number' => (int)$r['installment_number'],
            'transaction_date'   => $r['transaction_date'],
            'status'             => $r['status'],
            'bill_id'            => $r['bill_id'] ? (int)$r['bill_id'] : null,
            'external_id'        => $r['external_id'],
        ];
    }, $rows);

    // Live stats
    $statsRow = $db->query("
        SELECT
            COUNT(*) FILTER (WHERE transaction_date >= CURRENT_DATE)              AS today_count,
            COALESCE(SUM(amount) FILTER (WHERE transaction_date >= CURRENT_DATE), 0) AS today_volume,
            COUNT(*) FILTER (WHERE status = 'flagged')                            AS flagged_count,
            COUNT(*) FILTER (WHERE status = 'declined')                           AS declined_count,
            COUNT(*) FILTER (WHERE transaction_date >= NOW() - INTERVAL '1 hour') AS last_hour_count
        FROM om_credit_card_transactions
    ")->fetch(PDO::FETCH_ASSOC);

    response(true, [
        'transactions' => $transactions,
        'last_id'      => $transactions[0]['id'] ?? 0,
        'stats'        => [
            'today_count'    => (int)$statsRow['today_count'],
            'today_volume'   => (float)$statsRow['today_volume'],
            'flagged_count'  => (int)$statsRow['flagged_count'],
            'declined_count' => (int)$statsRow['declined_count'],
            'last_hour_count'=> (int)$statsRow['last_hour_count'],
        ],
    ]);
} catch (Exception $e) {
    error_log('[card-support-transactions-feed] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar feed: ' . $e->getMessage(), 500);
}
