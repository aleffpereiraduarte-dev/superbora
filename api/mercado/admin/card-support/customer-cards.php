<?php
/**
 * GET /admin/card-support/customer-cards.php
 *
 * List customers who have at least one credit card.
 *
 * Query:
 *   search        - name/email/phone/cpf/last4
 *   status        - active|blocked|cancelled|pre_approved|overdue|all
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

    $search   = trim((string)($_GET['search']   ?? ''));
    $status   = (string)($_GET['status']   ?? 'all');
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
        if ($status === 'overdue') {
            $conditions[] = "EXISTS (SELECT 1 FROM om_credit_card_bills b
                                     WHERE b.card_id = cc.id
                                       AND b.due_date < CURRENT_DATE
                                       AND b.status != 'paid')";
        } else {
            $conditions[] = 'cc.status = ?';
            $params[] = $status;
        }
    }
    $whereSql = implode(' AND ', $conditions);

    // Group by customer, keep the most-relevant card (active first, then most recent)
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
                 cc.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each customer also load a quick count of their cards and overdue flag
    $customers = [];
    if ($rows) {
        $customerIds = array_map(fn($r) => (int)$r['customer_id'], $rows);
        $ph = implode(',', array_fill(0, count($customerIds), '?'));

        $cardCountStmt = $db->prepare("
            SELECT customer_id, COUNT(*) AS n_cards
            FROM om_credit_cards
            WHERE customer_id IN ($ph)
            GROUP BY customer_id
        ");
        $cardCountStmt->execute($customerIds);
        $cardCounts = [];
        foreach ($cardCountStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cardCounts[(int)$r['customer_id']] = (int)$r['n_cards'];
        }

        $overdueStmt = $db->prepare("
            SELECT customer_id, COUNT(*) AS n
            FROM om_credit_card_bills
            WHERE customer_id IN ($ph)
              AND due_date < CURRENT_DATE
              AND status != 'paid'
            GROUP BY customer_id
        ");
        $overdueStmt->execute($customerIds);
        $overdueMap = [];
        foreach ($overdueStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $overdueMap[(int)$r['customer_id']] = (int)$r['n'];
        }

        foreach ($rows as $r) {
            $cid = (int)$r['customer_id'];
            $limit = (float)$r['credit_limit'];
            $used  = (float)$r['used_limit'];
            $customers[] = [
                'customer_id'    => $cid,
                'customer_name'  => $r['customer_name'],
                'email'          => $r['email'],
                'phone'          => $r['phone'],
                'cpf_masked'     => cardSupportMaskCpf($r['cpf']),
                'customer_since' => $r['customer_since'],
                'card_id'        => (int)$r['card_id'],
                'card_brand'     => $r['card_brand'],
                'card_last4'     => $r['card_last4'],
                'masked_number'  => '**** **** **** ' . ($r['card_last4'] ?: '0000'),
                'credit_limit'   => $limit,
                'used_limit'     => $used,
                'available_limit'=> max(0, $limit - $used),
                'utilization'    => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
                'status'         => $r['status'],
                'virtual'        => (bool)$r['virtual'],
                'score'          => $r['score'] !== null ? (int)$r['score'] : null,
                'created_at'     => $r['created_at'],
                'activated_at'   => $r['activated_at'],
                'blocked_at'     => $r['blocked_at'],
                'total_cards'    => $cardCounts[$cid] ?? 1,
                'has_overdue'    => isset($overdueMap[$cid]) && $overdueMap[$cid] > 0,
                'overdue_count'  => $overdueMap[$cid] ?? 0,
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
    error_log('[card-support-customer-cards] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar clientes', 500);
}
