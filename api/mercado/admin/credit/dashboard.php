<?php
/**
 * GET /admin/credit/dashboard.php
 *
 * Admin dashboard for credit evaluations.
 *
 * Lists the most recent evaluation per customer (one row per customer, latest
 * only), with filter by decision and score range. Also returns aggregate
 * counters (pending / approved / denied / overridden) and the rolling average
 * score for the KPI header.
 *
 * Query params:
 *   decision   — aprovado | revisao | negado | all (default 'all')
 *   score_min  — integer 0..1000
 *   score_max  — integer 0..1000
 *   search     — matches customer name / email / cpf
 *   page       — default 1
 *   per_page   — default 25, max 100
 *
 * Response.data:
 *   {
 *     "stats": { "pending": 12, "approved": 240, "denied": 35, "overridden": 4,
 *                "avg_score": 618, "total": 287 },
 *     "evaluations": [
 *        {
 *          "id": 42, "customer_id": 99123, "customer_name": "Ana",
 *          "email": "...", "cpf_masked": "***.***.456-78",
 *          "overall_score": 742, "final_decision": "aprovado",
 *          "final_limit": 2500, "ai_reasoning": "...",
 *          "red_flags": [...], "positive_signals": [...],
 *          "factor_scores": {...}, "evaluated_at": "...",
 *          "next_eval_at": "...", "admin_override": false
 *        }
 *     ],
 *     "total": 287, "page": 1, "per_page": 25
 *   }
 */

require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    om_auth()->requireAdmin();

    // Make sure the table exists even if the migration has not run
    require_once __DIR__ . "/../../helpers/credit-scoring.php";
    (new CreditScoringEngine($db));

    $decision = $_GET['decision'] ?? 'all';
    $decision = in_array($decision, ['aprovado','revisao','negado','all'], true) ? $decision : 'all';
    $scoreMin = isset($_GET['score_min']) ? max(0,  (int)$_GET['score_min']) : 0;
    $scoreMax = isset($_GET['score_max']) ? min(1000,(int)$_GET['score_max']) : 1000;
    $search   = trim((string)($_GET['search'] ?? ''));
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 25)));
    $offset   = ($page - 1) * $perPage;

    // -----------------------------------------------------------------
    // Aggregate stats
    // -----------------------------------------------------------------
    $statsRow = $db->query("
        SELECT
            COUNT(DISTINCT customer_id) FILTER (WHERE final_decision = 'revisao')   AS pending,
            COUNT(DISTINCT customer_id) FILTER (WHERE final_decision = 'aprovado')  AS approved,
            COUNT(DISTINCT customer_id) FILTER (WHERE final_decision = 'negado')    AS denied,
            COUNT(DISTINCT customer_id) FILTER (WHERE admin_override = true)        AS overridden,
            COALESCE(ROUND(AVG(overall_score))::int, 0)                             AS avg_score,
            COUNT(DISTINCT customer_id)                                             AS total
        FROM om_credit_evaluations
    ")->fetch(PDO::FETCH_ASSOC);

    // -----------------------------------------------------------------
    // Latest-per-customer evaluation listing (with filters)
    // -----------------------------------------------------------------
    $conditions = [];
    $params = [];

    if ($decision !== 'all') {
        $conditions[] = "ce.final_decision = ?";
        $params[] = $decision;
    }
    $conditions[] = "ce.overall_score BETWEEN ? AND ?";
    $params[] = $scoreMin;
    $params[] = $scoreMax;

    if ($search !== '') {
        $like = '%' . $search . '%';
        $conditions[] = "(c.name ILIKE ? OR c.email ILIKE ? OR c.cpf ILIKE ?)";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

    // Use DISTINCT ON to keep only the most recent row per customer
    $sql = "
        WITH latest AS (
            SELECT DISTINCT ON (customer_id) *
            FROM om_credit_evaluations
            ORDER BY customer_id, evaluated_at DESC
        )
        SELECT ce.id, ce.customer_id, ce.evaluated_at,
               ce.overall_score, ce.app_usage_score, ce.purchase_behavior_score,
               ce.payment_behavior_score, ce.external_data_score, ce.social_signals_score,
               ce.recommended_limit, ce.final_decision, ce.final_limit,
               ce.ai_decision, ce.ai_reasoning, ce.ai_red_flags, ce.ai_positive_signals,
               ce.admin_override, ce.admin_override_by, ce.admin_override_limit,
               ce.admin_override_notes, ce.admin_override_at,
               ce.next_eval_at,
               c.name AS customer_name, c.email, c.phone, c.cpf
        FROM latest ce
        LEFT JOIN om_customers c ON c.customer_id = ce.customer_id
        {$whereSql}
        ORDER BY ce.evaluated_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count total matching (for pagination)
    $countSql = "
        WITH latest AS (
            SELECT DISTINCT ON (customer_id) *
            FROM om_credit_evaluations
            ORDER BY customer_id, evaluated_at DESC
        )
        SELECT COUNT(*)
        FROM latest ce
        LEFT JOIN om_customers c ON c.customer_id = ce.customer_id
        {$whereSql}
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalFiltered = (int)$countStmt->fetchColumn();

    // Shape output
    $evaluations = array_map(function ($r) {
        $cpf = preg_replace('/\D/', '', (string)($r['cpf'] ?? ''));
        $cpfMasked = strlen($cpf) === 11
            ? '***.***.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2)
            : null;
        return [
            'id'                 => (int)$r['id'],
            'customer_id'        => (int)$r['customer_id'],
            'customer_name'      => $r['customer_name'] ?? null,
            'email'              => $r['email'] ?? null,
            'phone'              => $r['phone'] ?? null,
            'cpf_masked'         => $cpfMasked,
            'overall_score'      => (int)$r['overall_score'],
            'factor_scores'      => [
                'app_usage'         => (int)$r['app_usage_score'],
                'purchase_behavior' => (int)$r['purchase_behavior_score'],
                'payment_behavior'  => (int)$r['payment_behavior_score'],
                'external_data'     => (int)$r['external_data_score'],
                'social_signals'    => (int)$r['social_signals_score'],
            ],
            'recommended_limit'  => (float)$r['recommended_limit'],
            'final_decision'     => $r['final_decision'],
            'final_limit'        => (float)$r['final_limit'],
            'ai_decision'        => $r['ai_decision'],
            'ai_reasoning'       => $r['ai_reasoning'],
            'red_flags'          => $r['ai_red_flags'] ? json_decode($r['ai_red_flags'], true) : [],
            'positive_signals'   => $r['ai_positive_signals'] ? json_decode($r['ai_positive_signals'], true) : [],
            'admin_override'     => (bool)$r['admin_override'],
            'admin_override_by'  => $r['admin_override_by'] ? (int)$r['admin_override_by'] : null,
            'admin_override_limit' => $r['admin_override_limit'] !== null ? (float)$r['admin_override_limit'] : null,
            'admin_override_notes' => $r['admin_override_notes'],
            'admin_override_at'  => $r['admin_override_at'],
            'evaluated_at'       => $r['evaluated_at'],
            'next_eval_at'       => $r['next_eval_at'],
        ];
    }, $rows);

    response(true, [
        'stats'       => [
            'pending'    => (int)($statsRow['pending']    ?? 0),
            'approved'   => (int)($statsRow['approved']   ?? 0),
            'denied'     => (int)($statsRow['denied']     ?? 0),
            'overridden' => (int)($statsRow['overridden'] ?? 0),
            'avg_score'  => (int)($statsRow['avg_score']  ?? 0),
            'total'      => (int)($statsRow['total']      ?? 0),
        ],
        'evaluations' => $evaluations,
        'total'       => $totalFiltered,
        'page'        => $page,
        'per_page'    => $perPage,
    ]);
} catch (Exception $e) {
    error_log('[admin-credit-dashboard] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar dashboard', 500);
}
