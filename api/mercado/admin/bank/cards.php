<?php
/**
 * GET /admin/bank/cards.php
 *
 * Paginated list of all cards with status/filter/search support.
 *
 * Query params:
 *   status  — active|pre_approved|blocked|pending_approval|cancelled|rejected|all
 *   search  — matches customer name/email/cpf
 *   page    — default 1
 *   per_page — default 25
 *
 * Returns: { cards: [...], total, page, per_page, summary }
 */
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../helpers/bank-brain.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    om_auth()->requireAdmin();

    $status  = $_GET['status'] ?? 'all';
    $search  = trim((string)($_GET['search'] ?? ''));
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(5, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $conditions = [];
    $params = [];
    if ($status !== 'all') {
        $conditions[] = "cc.status = ?";
        $params[] = $status;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $conditions[] = "(c.name ILIKE ? OR c.email ILIKE ? OR c.cpf ILIKE ?)";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $stmt = $db->prepare("
        SELECT cc.id, cc.customer_id, cc.card_brand, cc.card_last4, cc.credit_limit, cc.used_limit,
               cc.status, cc.virtual, cc.score, cc.created_at, cc.approved_at, cc.activated_at,
               cc.blocked_at, cc.block_reason, cc.offer_expires_at, cc.offer_terms_interest_rate,
               cc.offer_terms_annual_fee,
               c.name, c.email, c.phone, c.cpf
        FROM om_credit_cards cc
        LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
        {$where}
        ORDER BY cc.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM om_credit_cards cc
        LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
        {$where}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $cards = array_map(function ($r) {
        $cpf = preg_replace('/\D/', '', (string)($r['cpf'] ?? ''));
        return [
            'id'              => (int)$r['id'],
            'customer_id'     => (int)$r['customer_id'],
            'customer_name'   => $r['name'],
            'email'           => $r['email'],
            'cpf_masked'      => strlen($cpf) === 11 ? '***.***.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2) : null,
            'brand'           => $r['card_brand'],
            'last4'           => $r['card_last4'],
            'credit_limit'    => (float)$r['credit_limit'],
            'used_limit'      => (float)$r['used_limit'],
            'utilization'     => (float)$r['credit_limit'] > 0 ? round(100 * (float)$r['used_limit'] / (float)$r['credit_limit'], 1) : 0,
            'status'          => $r['status'],
            'virtual'         => (bool)$r['virtual'],
            'score'           => $r['score'] ? (int)$r['score'] : null,
            'interest_rate'   => $r['offer_terms_interest_rate'] !== null ? (float)$r['offer_terms_interest_rate'] : null,
            'annual_fee'      => $r['offer_terms_annual_fee'] !== null ? (float)$r['offer_terms_annual_fee'] : null,
            'created_at'      => $r['created_at'],
            'approved_at'     => $r['approved_at'],
            'activated_at'    => $r['activated_at'],
            'blocked_at'      => $r['blocked_at'],
            'block_reason'    => $r['block_reason'],
            'offer_expires_at'=> $r['offer_expires_at'],
        ];
    }, $rows);

    // Summary by status
    $summaryRow = $db->query("
        SELECT
            COUNT(*) FILTER (WHERE status = 'active') AS active,
            COUNT(*) FILTER (WHERE status = 'pre_approved') AS pre_approved,
            COUNT(*) FILTER (WHERE status = 'blocked') AS blocked,
            COUNT(*) FILTER (WHERE status = 'pending_approval') AS pending,
            COUNT(*) FILTER (WHERE status = 'rejected') AS rejected,
            COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled,
            COUNT(*) FILTER (WHERE status = 'declined_by_customer') AS declined_by_customer,
            COUNT(*) FILTER (WHERE status = 'offer_expired') AS offer_expired
        FROM om_credit_cards
    ")->fetch(PDO::FETCH_ASSOC);
    $summary = array_map('intval', $summaryRow);

    response(true, [
        'cards'    => $cards,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'summary'  => $summary,
    ]);
} catch (Exception $e) {
    error_log('[admin-bank-cards] ' . $e->getMessage());
    response(false, null, 'Erro ao listar cartoes', 500);
}
