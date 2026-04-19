<?php
/**
 * GET /admin/card-support/bills-list.php
 *
 * Paginated list of bills with flexible filters.
 *
 * Query:
 *   status        - all|open|partial|paid|overdue|closed
 *   month, year   - filter by period_start
 *   min_amount    - only bills >= amount
 *   search        - customer search
 *   minimum_only  - 1 to only show partial/minimum paid
 *   page, per_page
 *
 * Response.data: { bills: [...], total, totals: { sum, paid, outstanding }, page, per_page }
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];

    $status  = (string)($_GET['status'] ?? 'all');
    $month   = (int)($_GET['month'] ?? 0);
    $year    = (int)($_GET['year']  ?? 0);
    $minAmt  = (float)($_GET['min_amount'] ?? 0);
    $search  = trim((string)($_GET['search'] ?? ''));
    $minOnly = (int)($_GET['minimum_only'] ?? 0) === 1;
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
    $offset  = ($page - 1) * $perPage;

    $conditions = ['1=1'];
    $params = [];

    if ($status !== 'all' && $status !== '') {
        if ($status === 'overdue') {
            $conditions[] = "b.due_date < CURRENT_DATE AND b.status != 'paid'";
        } else {
            $conditions[] = 'b.status = ?';
            $params[] = $status;
        }
    }
    if ($month > 0 && $month <= 12 && $year >= 2020) {
        $conditions[] = 'EXTRACT(MONTH FROM b.period_start) = ? AND EXTRACT(YEAR FROM b.period_start) = ?';
        $params[] = $month;
        $params[] = $year;
    }
    if ($minAmt > 0) {
        $conditions[] = 'b.total_amount >= ?';
        $params[] = $minAmt;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $conditions[] = '(c.name ILIKE ? OR c.email ILIKE ? OR c.phone ILIKE ? OR c.cpf ILIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if ($minOnly) {
        $conditions[] = 'b.paid_amount < b.total_amount AND b.paid_amount > 0';
    }

    $whereSql = implode(' AND ', $conditions);

    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM om_credit_card_bills b
        LEFT JOIN om_customers c ON c.customer_id = b.customer_id
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sumsStmt = $db->prepare("
        SELECT
            COALESCE(SUM(b.total_amount), 0) AS sum_total,
            COALESCE(SUM(b.paid_amount),  0) AS sum_paid,
            COALESCE(SUM(b.total_amount - b.paid_amount), 0) AS sum_outstanding
        FROM om_credit_card_bills b
        LEFT JOIN om_customers c ON c.customer_id = b.customer_id
        WHERE {$whereSql}
    ");
    $sumsStmt->execute($params);
    $sums = $sumsStmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT
            b.id, b.card_id, b.customer_id, b.period_start, b.period_end,
            b.due_date, b.total_amount, b.minimum_amount, b.paid_amount,
            b.status, b.closed_at, b.paid_at,
            (b.total_amount - b.paid_amount) AS remaining,
            CASE WHEN b.due_date < CURRENT_DATE AND b.status != 'paid' THEN TRUE ELSE FALSE END AS is_overdue,
            GREATEST(0, CURRENT_DATE - b.due_date) AS days_late,
            c.name AS customer_name, c.email, c.phone,
            cc.card_last4, cc.card_brand
        FROM om_credit_card_bills b
        LEFT JOIN om_customers c ON c.customer_id = b.customer_id
        LEFT JOIN om_credit_cards cc ON cc.id = b.card_id
        WHERE {$whereSql}
        ORDER BY b.due_date DESC, b.id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $bills = array_map(function ($b) {
        return [
            'id'             => (int)$b['id'],
            'card_id'        => (int)$b['card_id'],
            'customer_id'    => (int)$b['customer_id'],
            'customer_name'  => $b['customer_name'],
            'email'          => $b['email'],
            'phone'          => $b['phone'],
            'card_last4'     => $b['card_last4'],
            'card_brand'     => $b['card_brand'],
            'period_start'   => $b['period_start'],
            'period_end'     => $b['period_end'],
            'due_date'       => $b['due_date'],
            'total_amount'   => (float)$b['total_amount'],
            'minimum_amount' => (float)$b['minimum_amount'],
            'paid_amount'    => (float)$b['paid_amount'],
            'remaining'      => (float)$b['remaining'],
            'status'         => $b['status'],
            'is_overdue'     => (bool)$b['is_overdue'],
            'days_late'      => (int)$b['days_late'],
            'closed_at'      => $b['closed_at'],
            'paid_at'        => $b['paid_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    response(true, [
        'bills'    => $bills,
        'total'    => $total,
        'totals'   => [
            'sum'         => (float)$sums['sum_total'],
            'paid'        => (float)$sums['sum_paid'],
            'outstanding' => (float)$sums['sum_outstanding'],
        ],
        'page'     => $page,
        'per_page' => $perPage,
    ]);
} catch (Exception $e) {
    error_log('[card-support-bills-list] ' . $e->getMessage());
    response(false, null, 'Erro ao listar faturas: ' . $e->getMessage(), 500);
}
