<?php
/**
 * GET /admin/card-support/delinquent.php
 *
 * Aging buckets + list of delinquent customers filtered by bucket.
 *
 * Query:
 *   bucket - all|current|1_5|6_15|16_30|31_60|61_90|90_plus
 *   search
 *   page, per_page
 *
 * Response.data = {
 *   buckets: [ { key, label, count, total, days_min, days_max }, ... ],
 *   total_delinquent_amount, total_delinquent_count,
 *   items: [ {...} ]
 * }
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];

    $bucket = (string)($_GET['bucket'] ?? 'all');
    $search = trim((string)($_GET['search'] ?? ''));
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $perPage= min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $bucketsDef = [
        ['key' => 'current', 'label' => 'Em dia',       'min' => null, 'max' => 0],
        ['key' => '1_5',     'label' => '1-5 dias',     'min' => 1,    'max' => 5],
        ['key' => '6_15',    'label' => '6-15 dias',    'min' => 6,    'max' => 15],
        ['key' => '16_30',   'label' => '16-30 dias',   'min' => 16,   'max' => 30],
        ['key' => '31_60',   'label' => '31-60 dias',   'min' => 31,   'max' => 60],
        ['key' => '61_90',   'label' => '61-90 dias',   'min' => 61,   'max' => 90],
        ['key' => '90_plus', 'label' => '90+ dias',     'min' => 91,   'max' => null],
    ];

    $rows = $db->query("
        SELECT
            CASE
                WHEN b.status = 'paid' OR b.due_date >= CURRENT_DATE THEN 'current'
                WHEN (CURRENT_DATE - b.due_date) BETWEEN 1 AND 5   THEN '1_5'
                WHEN (CURRENT_DATE - b.due_date) BETWEEN 6 AND 15  THEN '6_15'
                WHEN (CURRENT_DATE - b.due_date) BETWEEN 16 AND 30 THEN '16_30'
                WHEN (CURRENT_DATE - b.due_date) BETWEEN 31 AND 60 THEN '31_60'
                WHEN (CURRENT_DATE - b.due_date) BETWEEN 61 AND 90 THEN '61_90'
                ELSE '90_plus'
            END AS bucket_key,
            COUNT(DISTINCT b.customer_id)                AS n_customers,
            COUNT(*)                                     AS n_bills,
            COALESCE(SUM(b.total_amount - b.paid_amount), 0) AS total
        FROM om_credit_card_bills b
        WHERE b.status != 'paid'
        GROUP BY bucket_key
    ")->fetchAll(PDO::FETCH_ASSOC);

    $buckets = [];
    foreach ($bucketsDef as $def) {
        $found = null;
        foreach ($rows as $r) {
            if ($r['bucket_key'] === $def['key']) { $found = $r; break; }
        }
        $buckets[] = [
            'key'      => $def['key'],
            'label'    => $def['label'],
            'days_min' => $def['min'],
            'days_max' => $def['max'],
            'customers'=> $found ? (int)$found['n_customers'] : 0,
            'bills'    => $found ? (int)$found['n_bills']     : 0,
            'total'    => $found ? (float)$found['total']     : 0.0,
        ];
    }

    $totalDel = $db->query("
        SELECT COUNT(DISTINCT customer_id) AS n, COALESCE(SUM(total_amount - paid_amount), 0) AS t
        FROM om_credit_card_bills
        WHERE due_date < CURRENT_DATE AND status != 'paid'
    ")->fetch(PDO::FETCH_ASSOC);

    // Delinquent items
    $itemConditions = ['b.status != \'paid\'', 'b.due_date < CURRENT_DATE'];
    $itemParams = [];

    if ($bucket !== 'all' && $bucket !== 'current') {
        $def = null;
        foreach ($bucketsDef as $d) { if ($d['key'] === $bucket) { $def = $d; break; } }
        if ($def) {
            if ($def['min'] !== null) {
                $itemConditions[] = '(CURRENT_DATE - b.due_date) >= ?';
                $itemParams[] = $def['min'];
            }
            if ($def['max'] !== null) {
                $itemConditions[] = '(CURRENT_DATE - b.due_date) <= ?';
                $itemParams[] = $def['max'];
            }
        }
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $itemConditions[] = '(c.name ILIKE ? OR c.email ILIKE ? OR c.phone ILIKE ? OR c.cpf ILIKE ?)';
        array_push($itemParams, $like, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $itemConditions);

    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM om_credit_card_bills b
        LEFT JOIN om_customers c ON c.customer_id = b.customer_id
        WHERE {$whereSql}
    ");
    $countStmt->execute($itemParams);
    $totalItems = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT
            b.id AS bill_id, b.card_id, b.customer_id, b.due_date,
            b.period_start, b.period_end,
            b.total_amount, b.paid_amount, b.minimum_amount, b.status,
            (b.total_amount - b.paid_amount) AS outstanding,
            (CURRENT_DATE - b.due_date) AS days_late,
            c.name AS customer_name, c.email, c.phone, c.cpf,
            cc.card_last4
        FROM om_credit_card_bills b
        LEFT JOIN om_customers c ON c.customer_id = b.customer_id
        LEFT JOIN om_credit_cards cc ON cc.id = b.card_id
        WHERE {$whereSql}
        ORDER BY b.due_date ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($itemParams, [$perPage, $offset]));
    $items = array_map(function ($r) {
        return [
            'bill_id'       => (int)$r['bill_id'],
            'card_id'       => (int)$r['card_id'],
            'customer_id'   => (int)$r['customer_id'],
            'customer_name' => $r['customer_name'],
            'email'         => $r['email'],
            'phone'         => $r['phone'],
            'cpf_masked'    => cardSupportMaskCpf($r['cpf']),
            'card_last4'    => $r['card_last4'],
            'due_date'      => $r['due_date'],
            'period_start'  => $r['period_start'],
            'period_end'    => $r['period_end'],
            'total_amount'  => (float)$r['total_amount'],
            'paid_amount'   => (float)$r['paid_amount'],
            'minimum_amount'=> (float)$r['minimum_amount'],
            'outstanding'   => (float)$r['outstanding'],
            'status'        => $r['status'],
            'days_late'     => (int)$r['days_late'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    response(true, [
        'buckets'                 => $buckets,
        'total_delinquent_count'  => (int)$totalDel['n'],
        'total_delinquent_amount' => (float)$totalDel['t'],
        'items'                   => $items,
        'total'                   => $totalItems,
        'page'                    => $page,
        'per_page'                => $perPage,
    ]);
} catch (Exception $e) {
    error_log('[card-support-delinquent] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar inadimplencia: ' . $e->getMessage(), 500);
}
