<?php
/**
 * GET /admin/cards/list.php
 *
 * List all issued (or offered) credit cards with filters.
 *
 * Query params:
 *   status        - pending_approval|pre_approved|active|blocked|cancelled|rejected|declined_by_customer|offer_expired|all (default all)
 *   search        - matches customer name / email / phone / cpf / card_last4
 *   date_from     - YYYY-MM-DD (created_at >=)
 *   date_to       - YYYY-MM-DD (created_at <=)
 *   limit_min     - numeric, filter credit_limit >=
 *   limit_max     - numeric, filter credit_limit <=
 *   page          - default 1
 *   per_page      - default 25, max 100
 *
 * Response.data: { stats: {...}, cards: [...], total, page, per_page }
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

    $validStatuses = [
        'pending_approval', 'pre_approved', 'active', 'blocked',
        'cancelled', 'rejected', 'declined_by_customer', 'offer_expired', 'all',
    ];
    $status   = $_GET['status']   ?? 'all';
    $status   = in_array($status, $validStatuses, true) ? $status : 'all';
    $search   = trim((string)($_GET['search']   ?? ''));
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo   = $_GET['date_to']   ?? null;
    $limitMin = isset($_GET['limit_min']) ? (float)$_GET['limit_min'] : null;
    $limitMax = isset($_GET['limit_max']) ? (float)$_GET['limit_max'] : null;
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 25)));
    $offset   = ($page - 1) * $perPage;

    $conditions = ['1=1'];
    $params = [];

    if ($status !== 'all') {
        $conditions[] = 'cc.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $conditions[] = '(c.name ILIKE ? OR c.email ILIKE ? OR c.phone ILIKE ? OR c.cpf ILIKE ? OR cc.card_last4 ILIKE ?)';
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($dateFrom) {
        $conditions[] = 'cc.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo) {
        $conditions[] = 'cc.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }
    if ($limitMin !== null) {
        $conditions[] = 'cc.credit_limit >= ?';
        $params[] = $limitMin;
    }
    if ($limitMax !== null) {
        $conditions[] = 'cc.credit_limit <= ?';
        $params[] = $limitMax;
    }

    $whereSql = implode(' AND ', $conditions);

    // Aggregate stats (independent of filters)
    $statsRow = $db->query("
        SELECT
            COUNT(*)                                                              AS total_cards,
            COUNT(*) FILTER (WHERE status = 'active')                             AS active_cards,
            COUNT(*) FILTER (WHERE status = 'pre_approved')                       AS pre_approved_cards,
            COUNT(*) FILTER (WHERE status = 'blocked')                            AS blocked_cards,
            COUNT(*) FILTER (WHERE status = 'declined_by_customer')               AS declined_cards,
            COUNT(*) FILTER (WHERE status = 'cancelled')                          AS cancelled_cards,
            COALESCE(SUM(credit_limit) FILTER (WHERE status = 'active'), 0)       AS total_limit_granted,
            COALESCE(SUM(used_limit)   FILTER (WHERE status = 'active'), 0)       AS total_used,
            COALESCE(SUM(credit_limit) FILTER (WHERE status = 'pre_approved'), 0) AS pending_offer_limit
        FROM om_credit_cards
    ")->fetch(PDO::FETCH_ASSOC);

    // Overdue bills + fees
    $extraStats = $db->query("
        SELECT
            COUNT(*) FILTER (WHERE due_date < CURRENT_DATE AND status != 'paid') AS overdue_bills,
            COALESCE(SUM(total_amount) FILTER (WHERE due_date < CURRENT_DATE AND status != 'paid'), 0) AS overdue_amount,
            COUNT(*) FILTER (WHERE status = 'paid')                              AS paid_bills,
            COALESCE(SUM(paid_amount) FILTER (WHERE status = 'paid'), 0)         AS total_collected
        FROM om_credit_card_bills
    ")->fetch(PDO::FETCH_ASSOC);

    // Count filtered
    $countSql = "SELECT COUNT(*)
        FROM om_credit_cards cc
        LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
        WHERE {$whereSql}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalFiltered = (int)$countStmt->fetchColumn();

    // Fetch rows
    $sql = "
        SELECT cc.id, cc.customer_id, cc.card_brand, cc.card_last4, cc.credit_limit, cc.used_limit,
               cc.status, cc.virtual, cc.score, cc.created_at, cc.approved_at, cc.activated_at,
               cc.blocked_at, cc.block_reason, cc.offer_created_at, cc.offer_expires_at,
               cc.offer_terms_interest_rate, cc.offer_terms_annual_fee,
               cc.accepted_at, cc.declined_at, cc.cancelled_at, cc.cancel_reason,
               cc.offered_by_admin_id, cc.credit_evaluation_id,
               c.name AS customer_name, c.email, c.phone, c.cpf
        FROM om_credit_cards cc
        LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
        WHERE {$whereSql}
        ORDER BY cc.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cards = array_map(function ($r) {
        $cpf = preg_replace('/\D/', '', (string)($r['cpf'] ?? ''));
        $cpfMasked = strlen($cpf) === 11
            ? '***.***.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2)
            : null;
        $limit = (float)$r['credit_limit'];
        $used  = (float)$r['used_limit'];
        return [
            'id'                 => (int)$r['id'],
            'customer_id'        => (int)$r['customer_id'],
            'customer_name'      => $r['customer_name'],
            'email'              => $r['email'],
            'phone'              => $r['phone'],
            'cpf_masked'         => $cpfMasked,
            'card_brand'         => $r['card_brand'],
            'card_last4'         => $r['card_last4'],
            'masked_number'      => '**** **** **** ' . ($r['card_last4'] ?: '0000'),
            'status'             => $r['status'],
            'virtual'            => (bool)$r['virtual'],
            'score'              => $r['score'] !== null ? (int)$r['score'] : null,
            'credit_limit'       => $limit,
            'used_limit'         => $used,
            'available_limit'    => max(0, $limit - $used),
            'utilization'        => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
            'created_at'         => $r['created_at'],
            'approved_at'        => $r['approved_at'],
            'activated_at'       => $r['activated_at'],
            'blocked_at'         => $r['blocked_at'],
            'block_reason'       => $r['block_reason'],
            'cancelled_at'       => $r['cancelled_at'],
            'cancel_reason'      => $r['cancel_reason'],
            'offer_created_at'   => $r['offer_created_at'],
            'offer_expires_at'   => $r['offer_expires_at'],
            'offer_interest_rate'=> $r['offer_terms_interest_rate'] !== null ? (float)$r['offer_terms_interest_rate'] : null,
            'offer_annual_fee'   => $r['offer_terms_annual_fee']    !== null ? (float)$r['offer_terms_annual_fee']    : null,
            'accepted_at'        => $r['accepted_at'],
            'declined_at'        => $r['declined_at'],
            'offered_by_admin_id'=> $r['offered_by_admin_id'] ? (int)$r['offered_by_admin_id'] : null,
            'credit_evaluation_id'=> $r['credit_evaluation_id'] ? (int)$r['credit_evaluation_id'] : null,
        ];
    }, $rows);

    $totalCards = (int)($statsRow['total_cards'] ?? 0);
    $activeCards = (int)($statsRow['active_cards'] ?? 0);
    $totalLimit = (float)($statsRow['total_limit_granted'] ?? 0);
    $totalUsed = (float)($statsRow['total_used'] ?? 0);
    $totalBills = (int)($extraStats['paid_bills'] ?? 0) + (int)($extraStats['overdue_bills'] ?? 0);
    $defaultRate = $totalBills > 0 ? round(((int)$extraStats['overdue_bills'] / $totalBills) * 100, 1) : 0;

    // Estimated fee revenue: 3% simple of used (MVP heuristic until real settlement is wired)
    $feeRevenue = round($totalUsed * 0.03, 2);

    response(true, [
        'stats' => [
            'total_cards'         => $totalCards,
            'active_cards'        => $activeCards,
            'pre_approved_cards'  => (int)($statsRow['pre_approved_cards']  ?? 0),
            'blocked_cards'       => (int)($statsRow['blocked_cards']       ?? 0),
            'declined_cards'      => (int)($statsRow['declined_cards']      ?? 0),
            'cancelled_cards'     => (int)($statsRow['cancelled_cards']     ?? 0),
            'total_limit_granted' => $totalLimit,
            'total_used'          => $totalUsed,
            'utilization_rate'    => $totalLimit > 0 ? round(($totalUsed / $totalLimit) * 100, 1) : 0,
            'pending_offer_limit' => (float)($statsRow['pending_offer_limit'] ?? 0),
            'overdue_bills'       => (int)($extraStats['overdue_bills']       ?? 0),
            'overdue_amount'      => (float)($extraStats['overdue_amount']     ?? 0),
            'paid_bills'          => (int)($extraStats['paid_bills']           ?? 0),
            'total_collected'     => (float)($extraStats['total_collected']    ?? 0),
            'default_rate'        => $defaultRate,
            'fee_revenue'         => $feeRevenue,
        ],
        'cards'    => $cards,
        'total'    => $totalFiltered,
        'page'     => $page,
        'per_page' => $perPage,
    ]);
} catch (Exception $e) {
    error_log('[admin-cards-list] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar cartoes', 500);
}
