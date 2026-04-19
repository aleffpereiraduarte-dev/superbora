<?php
/**
 * GET /admin/cards/eligible.php
 *
 * Lists customers eligible for pre-approval based on their most recent
 * credit evaluation. Excludes customers that already have a non-terminal
 * card (pre_approved, active, blocked, pending_approval).
 *
 * Query params:
 *   min_score   - default 700
 *   max_score   - default 1000
 *   page        - default 1
 *   per_page    - default 50, max 200
 *   search      - matches customer name / email / phone
 *
 * Response.data: { eligible: [...], total, page, per_page, summary: {...} }
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

    // Evaluations table must exist — if the scoring engine was not deployed
    // yet, we return an empty list rather than 500.
    try {
        $db->query("SELECT 1 FROM om_credit_evaluations LIMIT 1");
    } catch (Exception $e) {
        response(true, [
            'eligible' => [], 'total' => 0, 'page' => 1, 'per_page' => 0,
            'summary' => ['total_eligible' => 0, 'avg_score' => 0, 'avg_limit' => 0],
        ]);
    }

    $minScore = max(0, min(1000, (int)($_GET['min_score'] ?? 700)));
    $maxScore = max(0, min(1000, (int)($_GET['max_score'] ?? 1000)));
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(200, max(5, (int)($_GET['per_page'] ?? 50)));
    $search   = trim((string)($_GET['search'] ?? ''));
    $offset   = ($page - 1) * $perPage;

    $conds = ['l.overall_score BETWEEN ? AND ?', "l.final_decision != 'negado'"];
    $params = [$minScore, $maxScore];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $conds[] = '(c.name ILIKE ? OR c.email ILIKE ? OR c.phone ILIKE ?)';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $whereSql = implode(' AND ', $conds);

    // Count
    $countSql = "
        WITH latest AS (
            SELECT DISTINCT ON (customer_id) customer_id, overall_score, recommended_limit, final_decision
            FROM om_credit_evaluations
            ORDER BY customer_id, evaluated_at DESC
        )
        SELECT COUNT(*)
        FROM latest l
        LEFT JOIN om_customers c ON c.customer_id = l.customer_id
        WHERE {$whereSql}
          AND NOT EXISTS (
              SELECT 1 FROM om_credit_cards cc
              WHERE cc.customer_id = l.customer_id
                AND cc.status IN ('pending_approval','pre_approved','active','blocked')
          )
    ";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // Summary over all eligible (no pagination)
    $summaryRow = $db->prepare("
        WITH latest AS (
            SELECT DISTINCT ON (customer_id) customer_id, overall_score, recommended_limit, final_decision
            FROM om_credit_evaluations
            ORDER BY customer_id, evaluated_at DESC
        )
        SELECT COUNT(*) AS total_eligible,
               COALESCE(AVG(l.overall_score), 0)::int AS avg_score,
               COALESCE(AVG(l.recommended_limit), 0)::numeric(12,2) AS avg_limit
        FROM latest l
        LEFT JOIN om_customers c ON c.customer_id = l.customer_id
        WHERE {$whereSql}
          AND NOT EXISTS (
              SELECT 1 FROM om_credit_cards cc
              WHERE cc.customer_id = l.customer_id
                AND cc.status IN ('pending_approval','pre_approved','active','blocked')
          )
    ");
    $summaryRow->execute($params);
    $summary = $summaryRow->fetch(PDO::FETCH_ASSOC) ?: ['total_eligible' => 0, 'avg_score' => 0, 'avg_limit' => 0];

    // Rows
    $sql = "
        WITH latest AS (
            SELECT DISTINCT ON (customer_id) customer_id, overall_score, recommended_limit, final_decision, ai_reasoning, evaluated_at
            FROM om_credit_evaluations
            ORDER BY customer_id, evaluated_at DESC
        )
        SELECT l.customer_id, l.overall_score, l.recommended_limit, l.final_decision,
               l.ai_reasoning, l.evaluated_at,
               c.name, c.email, c.phone,
               (SELECT COUNT(*) FROM om_market_orders o
                WHERE o.customer_id = l.customer_id
                  AND o.status NOT IN ('cancelado','reembolsado')) AS orders_count,
               (SELECT COALESCE(SUM(o.total), 0) FROM om_market_orders o
                WHERE o.customer_id = l.customer_id
                  AND o.status NOT IN ('cancelado','reembolsado')) AS total_spent
        FROM latest l
        LEFT JOIN om_customers c ON c.customer_id = l.customer_id
        WHERE {$whereSql}
          AND NOT EXISTS (
              SELECT 1 FROM om_credit_cards cc
              WHERE cc.customer_id = l.customer_id
                AND cc.status IN ('pending_approval','pre_approved','active','blocked')
          )
        ORDER BY l.overall_score DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eligible = array_map(function ($r) {
        return [
            'customer_id'       => (int)$r['customer_id'],
            'name'              => $r['name'],
            'email'             => $r['email'],
            'phone'             => $r['phone'],
            'overall_score'     => (int)$r['overall_score'],
            'recommended_limit' => (float)$r['recommended_limit'],
            'final_decision'    => $r['final_decision'],
            'ai_reasoning'      => $r['ai_reasoning'],
            'orders_count'      => (int)$r['orders_count'],
            'total_spent'       => (float)$r['total_spent'],
            'evaluated_at'      => $r['evaluated_at'],
        ];
    }, $rows);

    response(true, [
        'eligible' => $eligible,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'summary'  => [
            'total_eligible' => (int)$summary['total_eligible'],
            'avg_score'      => (int)$summary['avg_score'],
            'avg_limit'      => (float)$summary['avg_limit'],
        ],
    ]);
} catch (Exception $e) {
    error_log('[admin-cards-eligible] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar elegiveis', 500);
}
