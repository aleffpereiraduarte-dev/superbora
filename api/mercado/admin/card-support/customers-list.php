<?php
/**
 * GET /admin/card-support/customers-list.php
 *
 * Paginated, filtered list of customers with credit cards.
 *
 * Query:
 *   search       - name/email/phone/cpf/last4
 *   status       - all|active|blocked|cancelled|pre_approved|rejected
 *   score_min, score_max - 0..1000
 *   util_min, util_max  - 0..100
 *   tenure       - all|new|regular|mature|veteran   (< 30d / 30-90 / 90-365 / 365+)
 *   city, state  - filter
 *   has_overdue  - yes|no|all
 *   sort         - created|score|limit|utilization|overdue
 *   dir          - asc|desc
 *   page, per_page
 *
 * Response.data: { customers: [...], total, page, per_page }
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];

    $search   = trim((string)($_GET['search'] ?? ''));
    $status   = (string)($_GET['status'] ?? 'all');
    $scoreMin = (int)($_GET['score_min'] ?? 0);
    $scoreMax = (int)($_GET['score_max'] ?? 1000);
    $utilMin  = (float)($_GET['util_min'] ?? 0);
    $utilMax  = (float)($_GET['util_max'] ?? 100);
    $tenure   = (string)($_GET['tenure'] ?? 'all');
    $city     = trim((string)($_GET['city'] ?? ''));
    $state    = trim((string)($_GET['state'] ?? ''));
    $hasOver  = (string)($_GET['has_overdue'] ?? 'all');
    $sort     = (string)($_GET['sort'] ?? 'created');
    $dir      = strtoupper((string)($_GET['dir'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 25)));
    $offset   = ($page - 1) * $perPage;

    $conditions = ['1=1'];
    $params = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $conditions[] = '(c.name ILIKE ? OR c.email ILIKE ? OR c.phone ILIKE ? OR c.cpf ILIKE ? OR cc.card_last4 ILIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($status !== 'all' && $status !== '') {
        $conditions[] = 'cc.status = ?';
        $params[] = $status;
    }
    if ($scoreMin > 0) {
        $conditions[] = 'COALESCE(cc.score,0) >= ?';
        $params[] = $scoreMin;
    }
    if ($scoreMax < 1000) {
        $conditions[] = 'COALESCE(cc.score,0) <= ?';
        $params[] = $scoreMax;
    }
    if ($utilMin > 0) {
        $conditions[] = '(cc.credit_limit > 0 AND (cc.used_limit / cc.credit_limit * 100) >= ?)';
        $params[] = $utilMin;
    }
    if ($utilMax < 100) {
        $conditions[] = '(cc.credit_limit = 0 OR (cc.used_limit / cc.credit_limit * 100) <= ?)';
        $params[] = $utilMax;
    }
    if ($tenure !== 'all') {
        switch ($tenure) {
            case 'new':      $conditions[] = 'c.created_at >= NOW() - INTERVAL \'30 days\''; break;
            case 'regular':  $conditions[] = 'c.created_at BETWEEN NOW() - INTERVAL \'90 days\' AND NOW() - INTERVAL \'30 days\''; break;
            case 'mature':   $conditions[] = 'c.created_at BETWEEN NOW() - INTERVAL \'365 days\' AND NOW() - INTERVAL \'90 days\''; break;
            case 'veteran':  $conditions[] = 'c.created_at < NOW() - INTERVAL \'365 days\''; break;
        }
    }
    if ($city !== '') {
        try {
            $conditions[] = 'c.addr_city ILIKE ?';
            $params[] = '%' . $city . '%';
        } catch (Exception $e) { /* column may not exist */ }
    }
    if ($state !== '') {
        try {
            $conditions[] = 'c.addr_state ILIKE ?';
            $params[] = '%' . $state . '%';
        } catch (Exception $e) { /* column may not exist */ }
    }
    if ($hasOver === 'yes') {
        $conditions[] = "EXISTS (SELECT 1 FROM om_credit_card_bills b
                                  WHERE b.card_id = cc.id
                                    AND b.due_date < CURRENT_DATE
                                    AND b.status != 'paid')";
    } elseif ($hasOver === 'no') {
        $conditions[] = "NOT EXISTS (SELECT 1 FROM om_credit_card_bills b
                                      WHERE b.card_id = cc.id
                                        AND b.due_date < CURRENT_DATE
                                        AND b.status != 'paid')";
    }
    $whereSql = implode(' AND ', $conditions);

    $orderBy = 'cc.created_at';
    switch ($sort) {
        case 'score':       $orderBy = 'COALESCE(cc.score,0)'; break;
        case 'limit':       $orderBy = 'cc.credit_limit';      break;
        case 'utilization': $orderBy = '(cc.used_limit / NULLIF(cc.credit_limit,0))'; break;
        case 'overdue':     $orderBy = 'cc.created_at'; break;
    }

    $countSql = "
        SELECT COUNT(DISTINCT cc.customer_id)
        FROM om_credit_cards cc
        LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
        WHERE {$whereSql}
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT DISTINCT ON (cc.customer_id)
            cc.customer_id, cc.id AS card_id, cc.card_brand, cc.card_last4,
            cc.credit_limit, cc.used_limit, cc.status, cc.virtual,
            cc.score, cc.created_at, cc.activated_at, cc.blocked_at,
            c.name AS customer_name, c.email, c.phone, c.cpf, c.created_at AS customer_since
        FROM om_credit_cards cc
        LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
        WHERE {$whereSql}
        ORDER BY cc.customer_id,
                 CASE cc.status WHEN 'active' THEN 0 WHEN 'blocked' THEN 1 WHEN 'pre_approved' THEN 2 ELSE 3 END,
                 {$orderBy} {$dir}
        LIMIT ? OFFSET ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $customers = [];
    if ($rows) {
        $customerIds = array_map(fn($r) => (int)$r['customer_id'], $rows);
        $ph = implode(',', array_fill(0, count($customerIds), '?'));

        // Current bill info for each customer (latest open bill)
        $billStmt = $db->prepare("
            SELECT customer_id,
                   MAX(due_date) AS due_date,
                   SUM(total_amount - paid_amount) FILTER (WHERE status != 'paid') AS outstanding,
                   MAX(CASE WHEN due_date < CURRENT_DATE AND status != 'paid' THEN CURRENT_DATE - due_date ELSE 0 END) AS max_days_late
            FROM om_credit_card_bills
            WHERE customer_id IN ($ph)
            GROUP BY customer_id
        ");
        $billStmt->execute($customerIds);
        $billMap = [];
        foreach ($billStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $billMap[(int)$r['customer_id']] = $r;
        }

        // Last activity
        $actStmt = $db->prepare("
            SELECT customer_id, MAX(transaction_date) AS last_tx
            FROM om_credit_card_transactions
            WHERE customer_id IN ($ph)
            GROUP BY customer_id
        ");
        $actStmt->execute($customerIds);
        $actMap = [];
        foreach ($actStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $actMap[(int)$r['customer_id']] = $r['last_tx'];
        }

        foreach ($rows as $r) {
            $cid   = (int)$r['customer_id'];
            $limit = (float)$r['credit_limit'];
            $used  = (float)$r['used_limit'];
            $bi    = $billMap[$cid] ?? null;
            $daysLate = $bi ? (int)($bi['max_days_late'] ?? 0) : 0;

            $customers[] = [
                'customer_id'     => $cid,
                'customer_name'   => $r['customer_name'],
                'email'           => $r['email'],
                'phone'           => $r['phone'],
                'cpf_masked'      => cardSupportMaskCpf($r['cpf']),
                'customer_since'  => $r['customer_since'],
                'card_id'         => (int)$r['card_id'],
                'card_brand'      => $r['card_brand'],
                'card_last4'      => $r['card_last4'],
                'masked_number'   => '**** **** **** ' . ($r['card_last4'] ?: '0000'),
                'credit_limit'    => $limit,
                'used_limit'      => $used,
                'available_limit' => max(0, $limit - $used),
                'utilization'     => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
                'status'          => $r['status'],
                'virtual'         => (bool)$r['virtual'],
                'score'           => $r['score'] !== null ? (int)$r['score'] : null,
                'created_at'      => $r['created_at'],
                'activated_at'    => $r['activated_at'],
                'blocked_at'      => $r['blocked_at'],
                'current_bill_due'         => $bi['due_date']    ?? null,
                'current_bill_outstanding' => (float)($bi['outstanding'] ?? 0),
                'days_late'       => $daysLate,
                'last_activity'   => $actMap[$cid] ?? null,
            ];
        }
    }

    response(true, [
        'customers' => $customers,
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
    ]);
} catch (Exception $e) {
    error_log('[card-support-customers-list] ' . $e->getMessage());
    response(false, null, 'Erro ao listar clientes: ' . $e->getMessage(), 500);
}
