<?php
/**
 * Store Penalties Management API
 *
 * GET: List penalties, single detail, rules, store summary
 * POST: Create penalty, auto-detect, deduct from repasse
 * PUT: Update status, dispute, resolve
 *
 * Query params (GET):
 *   partner_id: filter by store
 *   status: filter by status (comma-separated)
 *   category: filter by category
 *   severity: filter by severity
 *   period: today|week|month|quarter (default: month)
 *   page, limit: pagination (default: 1, 20)
 *   id: single penalty detail
 *   action: rules|store_summary
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Admin auth
    $payload = om_auth()->requireAdmin();
    $adminId = $payload['uid'] ?? 0;

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        handleGet($db);
    } elseif ($method === 'POST') {
        handlePost($db, $adminId);
    } elseif ($method === 'PUT') {
        handlePut($db, $adminId);
    } else {
        response(false, null, 'Metodo nao permitido', 405);
    }
} catch (PDOException $e) {
    error_log("[Store Penalties] DB error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
} catch (Exception $e) {
    if (in_array(http_response_code(), [401, 403], true)) {
        exit;
    }
    error_log("[Store Penalties] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}

/* ===================== GET ===================== */

function handleGet(PDO $db): void {
    $action = $_GET['action'] ?? '';

    if ($action === 'rules') {
        getRules($db);
        return;
    }

    if ($action === 'store_summary' && !empty($_GET['partner_id'])) {
        getStoreSummary($db, (int)$_GET['partner_id']);
        return;
    }

    // Single penalty detail
    if (!empty($_GET['id'])) {
        getSinglePenalty($db, (int)$_GET['id']);
        return;
    }

    // List penalties with filters
    listPenalties($db);
}

function getRules(PDO $db): void {
    $stmt = dbQuery($db, "SELECT * FROM om_store_penalty_rules WHERE active = true ORDER BY id");
    $rules = $stmt->fetchAll();
    response(true, ['rules' => $rules]);
}

function getSinglePenalty(PDO $db, int $id): void {
    $stmt = dbQuery($db, "
        SELECT sp.*,
               o.order_number, o.customer_name, o.customer_phone, o.status AS order_status,
               o.total AS real_order_total, o.subtotal, o.payment_method,
               p.name AS partner_name, p.trade_name AS partner_trade_name
        FROM om_store_penalties sp
        LEFT JOIN om_orders o ON o.order_id = sp.order_id
        LEFT JOIN om_market_partners p ON p.partner_id = sp.partner_id
        WHERE sp.id = ?
    ", [$id]);

    $penalty = $stmt->fetch();
    if (!$penalty) {
        response(false, null, 'Penalidade nao encontrada', 404);
    }

    // Get penalty rule for this category
    $ruleStmt = dbQuery($db, "SELECT * FROM om_store_penalty_rules WHERE category = ?", [$penalty['category']]);
    $penalty['rule'] = $ruleStmt->fetch() ?: null;

    // Decode photos JSON
    $penalty['photos'] = json_decode($penalty['photos'] ?? '[]', true);

    response(true, $penalty);
}

function listPenalties(PDO $db): void {
    $partnerId = !empty($_GET['partner_id']) ? (int)$_GET['partner_id'] : null;
    $statusFilter = $_GET['status'] ?? '';
    $categoryFilter = $_GET['category'] ?? '';
    $severityFilter = $_GET['severity'] ?? '';
    $period = $_GET['period'] ?? 'month';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Build date range
    $dateRange = buildDateRange($period);
    $conditions = ["sp.created_at >= ?"];
    $params = [$dateRange];

    if ($partnerId) {
        $conditions[] = "sp.partner_id = ?";
        $params[] = $partnerId;
    }

    if ($statusFilter && $statusFilter !== 'all') {
        $statuses = array_map('trim', explode(',', $statusFilter));
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $conditions[] = "sp.status IN ($placeholders)";
        $params = array_merge($params, $statuses);
    }

    if ($categoryFilter && $categoryFilter !== 'all') {
        $conditions[] = "sp.category = ?";
        $params[] = $categoryFilter;
    }

    if ($severityFilter && $severityFilter !== 'all') {
        $conditions[] = "sp.severity = ?";
        $params[] = $severityFilter;
    }

    $where = implode(' AND ', $conditions);

    // Summary stats
    $summaryStmt = dbQuery($db, "
        SELECT
            COUNT(*) AS total_penalties,
            COALESCE(SUM(sp.penalty_amount), 0) AS total_amount,
            COALESCE(SUM(sp.refund_to_customer), 0) AS total_refunded,
            COALESCE(SUM(CASE WHEN sp.deducted_from_repasse = false AND sp.status IN ('opened','confirmed') THEN sp.penalty_amount ELSE 0 END), 0) AS pending_deduction,
            COALESCE(SUM(CASE WHEN sp.status = 'opened' THEN 1 ELSE 0 END), 0) AS opened_count,
            COALESCE(SUM(CASE WHEN sp.status = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed_count,
            COALESCE(SUM(CASE WHEN sp.status = 'deducted' THEN 1 ELSE 0 END), 0) AS deducted_count,
            COALESCE(SUM(CASE WHEN sp.store_disputed = true THEN 1 ELSE 0 END), 0) AS disputed_count
        FROM om_store_penalties sp
        WHERE $where
    ", $params);
    $summary = $summaryStmt->fetch();

    // Category breakdown
    $catStmt = dbQuery($db, "
        SELECT sp.category, COUNT(*) AS count, COALESCE(SUM(sp.penalty_amount), 0) AS amount
        FROM om_store_penalties sp
        WHERE $where
        GROUP BY sp.category
        ORDER BY count DESC
    ", $params);
    $summary['by_category'] = $catStmt->fetchAll();

    // Top offending stores
    $topStoresStmt = dbQuery($db, "
        SELECT sp.partner_id,
               p.name AS partner_name,
               p.trade_name,
               COUNT(*) AS total_penalties,
               COALESCE(SUM(sp.penalty_amount), 0) AS total_amount,
               COALESCE(AVG(sp.penalty_amount), 0) AS avg_penalty
        FROM om_store_penalties sp
        LEFT JOIN om_market_partners p ON p.partner_id = sp.partner_id
        WHERE $where
        GROUP BY sp.partner_id, p.name, p.trade_name
        ORDER BY total_penalties DESC
        LIMIT 10
    ", $params);
    $topStores = $topStoresStmt->fetchAll();

    // Calculate health scores for top stores
    foreach ($topStores as &$store) {
        $store['health_score'] = calculateHealthScore($db, (int)$store['partner_id']);
    }
    unset($store);

    // Problem rate: penalties / total orders in period
    $orderCountStmt = dbQuery($db, "SELECT COUNT(*) FROM om_orders WHERE created_at >= ?", [$dateRange]);
    $totalOrders = (int)$orderCountStmt->fetchColumn();
    $summary['problem_rate'] = $totalOrders > 0
        ? round(((int)$summary['total_penalties'] / $totalOrders) * 100, 2)
        : 0;
    $summary['total_orders'] = $totalOrders;

    // Penalties list
    $listStmt = dbQuery($db, "
        SELECT sp.id, sp.order_id, sp.partner_id, sp.reported_by, sp.category, sp.severity,
               sp.title, sp.penalty_amount, sp.refund_to_customer, sp.order_total,
               sp.status, sp.store_disputed, sp.deducted_from_repasse, sp.created_at,
               o.order_number, o.customer_name,
               p.name AS partner_name, p.trade_name AS partner_trade_name
        FROM om_store_penalties sp
        LEFT JOIN om_orders o ON o.order_id = sp.order_id
        LEFT JOIN om_market_partners p ON p.partner_id = sp.partner_id
        WHERE $where
        ORDER BY sp.created_at DESC
        LIMIT ? OFFSET ?
    ", array_merge($params, [$limit, $offset]));
    $penalties = $listStmt->fetchAll();

    // Total count for pagination
    $countStmt = dbQuery($db, "SELECT COUNT(*) FROM om_store_penalties sp WHERE $where", $params);
    $total = (int)$countStmt->fetchColumn();

    // Rules (for create modal)
    $rulesStmt = dbQuery($db, "SELECT * FROM om_store_penalty_rules WHERE active = true ORDER BY id");
    $rules = $rulesStmt->fetchAll();

    response(true, [
        'summary' => $summary,
        'top_stores' => $topStores,
        'penalties' => $penalties,
        'rules' => $rules,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit),
        ],
    ]);
}

function getStoreSummary(PDO $db, int $partnerId): void {
    // Partner info
    $partnerStmt = dbQuery($db, "SELECT partner_id, name, trade_name, logo FROM om_market_partners WHERE partner_id = ?", [$partnerId]);
    $partner = $partnerStmt->fetch();
    if (!$partner) {
        response(false, null, 'Loja nao encontrada', 404);
    }

    // Overall penalty stats
    $statsStmt = dbQuery($db, "
        SELECT
            COUNT(*) AS total_penalties,
            COALESCE(SUM(penalty_amount), 0) AS total_amount,
            COALESCE(SUM(refund_to_customer), 0) AS total_refunded,
            COALESCE(SUM(CASE WHEN deducted_from_repasse = false AND status IN ('opened','confirmed') THEN penalty_amount ELSE 0 END), 0) AS pending_deduction
        FROM om_store_penalties
        WHERE partner_id = ?
    ", [$partnerId]);
    $stats = $statsStmt->fetch();

    // Category breakdown
    $catStmt = dbQuery($db, "
        SELECT category, COUNT(*) AS count, COALESCE(SUM(penalty_amount), 0) AS amount
        FROM om_store_penalties
        WHERE partner_id = ?
        GROUP BY category
        ORDER BY count DESC
    ", [$partnerId]);
    $stats['categories'] = $catStmt->fetchAll();

    // Last 3 months trend (monthly aggregation)
    $trendStmt = dbQuery($db, "
        SELECT
            TO_CHAR(created_at, 'YYYY-MM') AS month,
            COUNT(*) AS count,
            COALESCE(SUM(penalty_amount), 0) AS amount
        FROM om_store_penalties
        WHERE partner_id = ? AND created_at >= NOW() - INTERVAL '3 months'
        GROUP BY TO_CHAR(created_at, 'YYYY-MM')
        ORDER BY month
    ", [$partnerId]);
    $stats['monthly_trend'] = $trendStmt->fetchAll();

    // Current month deductions
    $currentMonthStmt = dbQuery($db, "
        SELECT
            COUNT(*) AS count,
            COALESCE(SUM(penalty_amount), 0) AS amount,
            COALESCE(SUM(CASE WHEN deducted_from_repasse = true THEN penalty_amount ELSE 0 END), 0) AS deducted,
            COALESCE(SUM(CASE WHEN deducted_from_repasse = false AND status IN ('opened','confirmed') THEN penalty_amount ELSE 0 END), 0) AS pending
        FROM om_store_penalties
        WHERE partner_id = ? AND created_at >= DATE_TRUNC('month', NOW())
    ", [$partnerId]);
    $stats['current_month'] = $currentMonthStmt->fetch();

    // Health score
    $stats['health_score'] = calculateHealthScore($db, $partnerId);

    // Total orders for this partner
    $orderCountStmt = dbQuery($db, "SELECT COUNT(*) FROM om_orders WHERE partner_id = ? AND created_at >= NOW() - INTERVAL '3 months'", [$partnerId]);
    $stats['total_orders_3m'] = (int)$orderCountStmt->fetchColumn();

    // Recent penalties
    $recentStmt = dbQuery($db, "
        SELECT sp.*, o.order_number, o.customer_name
        FROM om_store_penalties sp
        LEFT JOIN om_orders o ON o.order_id = sp.order_id
        WHERE sp.partner_id = ?
        ORDER BY sp.created_at DESC
        LIMIT 20
    ", [$partnerId]);
    $stats['recent_penalties'] = $recentStmt->fetchAll();

    response(true, [
        'partner' => $partner,
        'stats' => $stats,
    ]);
}

function calculateHealthScore(PDO $db, int $partnerId): int {
    // Health = 100 - (penalty_rate * weight)
    // Penalty rate over last 90 days
    $stmt = dbQuery($db, "
        SELECT COUNT(*) AS penalty_count
        FROM om_store_penalties
        WHERE partner_id = ? AND created_at >= NOW() - INTERVAL '90 days'
          AND status NOT IN ('resolved')
    ", [$partnerId]);
    $penaltyCount = (int)$stmt->fetchColumn();

    $orderStmt = dbQuery($db, "
        SELECT COUNT(*) FROM om_orders
        WHERE partner_id = ? AND created_at >= NOW() - INTERVAL '90 days'
    ", [$partnerId]);
    $orderCount = (int)$orderStmt->fetchColumn();

    if ($orderCount === 0) return 100;

    $penaltyRate = ($penaltyCount / $orderCount) * 100;
    // Each 1% penalty rate = -10 health points
    $score = 100 - (int)($penaltyRate * 10);
    return max(0, min(100, $score));
}

/* ===================== POST ===================== */

function handlePost(PDO $db, int $adminId): void {
    $input = getInput();
    $action = $_GET['action'] ?? $input['action'] ?? '';

    if ($action === 'auto_detect') {
        autoDetectPenalties($db, $adminId);
        return;
    }

    if ($action === 'deduct_from_repasse') {
        deductFromRepasse($db, $adminId, $input);
        return;
    }

    // Create penalty
    createPenalty($db, $adminId, $input);
}

function createPenalty(PDO $db, int $adminId, array $input): void {
    $orderId = (int)($input['order_id'] ?? 0);
    $partnerId = (int)($input['partner_id'] ?? 0);
    $category = trim($input['category'] ?? '');
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $photos = $input['photos'] ?? [];
    $reportedBy = trim($input['reported_by'] ?? 'admin');
    $reportedById = (int)($input['reported_by_id'] ?? $adminId);
    $severity = trim($input['severity'] ?? '');
    $customPenalty = isset($input['penalty_amount']) ? (float)$input['penalty_amount'] : null;
    $customRefund = isset($input['refund_to_customer']) ? (float)$input['refund_to_customer'] : null;

    // Validate required fields
    if (!$orderId || !$partnerId || !$category || !$title) {
        response(false, null, 'Campos obrigatorios: order_id, partner_id, category, title', 400);
    }

    // Validate category exists
    $ruleStmt = dbQuery($db, "SELECT * FROM om_store_penalty_rules WHERE category = ? AND active = true", [$category]);
    $rule = $ruleStmt->fetch();
    if (!$rule) {
        response(false, null, 'Categoria invalida', 400);
    }

    // Validate reported_by
    $validReporters = ['customer', 'driver', 'admin', 'system'];
    if (!in_array($reportedBy, $validReporters, true)) {
        response(false, null, 'reported_by invalido', 400);
    }

    // Get order info
    $orderStmt = dbQuery($db, "SELECT order_id, total, subtotal, partner_id, customer_id FROM om_orders WHERE order_id = ?", [$orderId]);
    $order = $orderStmt->fetch();
    if (!$order) {
        response(false, null, 'Pedido nao encontrado', 404);
    }

    // Verify partner matches order
    if ((int)$order['partner_id'] !== $partnerId) {
        response(false, null, 'Partner nao corresponde ao pedido', 400);
    }

    $orderTotal = (float)$order['total'];

    // Auto-calculate penalty based on rules
    if ($customPenalty !== null) {
        $penaltyAmount = $customPenalty;
    } else {
        $penaltyAmount = calculatePenaltyAmount($rule, $orderTotal);
    }

    // Auto-calculate refund
    if ($customRefund !== null) {
        $refundAmount = $customRefund;
    } else {
        $refundAmount = $rule['refund_customer'] ? $penaltyAmount : 0;
    }

    // Auto-determine severity if not provided
    if (!$severity) {
        $severity = determineSeverity($rule, $penaltyAmount, $orderTotal);
    }

    // Validate severity
    $validSeverities = ['low', 'medium', 'high', 'critical'];
    if (!in_array($severity, $validSeverities, true)) {
        $severity = 'medium';
    }

    // Auto-apply? (skip admin review)
    $status = $rule['auto_apply'] ? 'confirmed' : 'opened';

    $db->beginTransaction();
    try {
        $stmt = dbQuery($db, "
            INSERT INTO om_store_penalties
                (order_id, partner_id, reported_by, reported_by_id, category, severity,
                 title, description, photos, order_total, penalty_amount, refund_to_customer, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)
            RETURNING id
        ", [
            $orderId, $partnerId, $reportedBy, $reportedById, $category, $severity,
            $title, $description, json_encode($photos), $orderTotal, $penaltyAmount,
            $refundAmount, $status
        ]);

        $penaltyId = $stmt->fetchColumn();

        // If refund > 0 and rule says refund customer, credit as cashback
        if ($refundAmount > 0 && $rule['refund_customer']) {
            $customerId = (int)$order['customer_id'];
            if ($customerId > 0) {
                // Credit cashback to customer
                dbQuery($db, "
                    INSERT INTO om_market_cashback (customer_id, order_id, amount, type, description, created_at)
                    VALUES (?, ?, ?, 'credit', ?, NOW())
                    ON CONFLICT DO NOTHING
                ", [
                    $customerId, $orderId, $refundAmount,
                    "Reembolso: $title (Penalidade #$penaltyId)"
                ]);
            }
        }

        $db->commit();

        response(true, [
            'id' => (int)$penaltyId,
            'penalty_amount' => $penaltyAmount,
            'refund_to_customer' => $refundAmount,
            'status' => $status,
        ], 'Penalidade criada com sucesso');

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function autoDetectPenalties(PDO $db, int $adminId): void {
    $created = 0;

    // 1. Orders with rating <= 2 stars (last 7 days, no existing penalty)
    $lowRatingStmt = dbQuery($db, "
        SELECT o.order_id, o.partner_id, o.total, o.customer_name,
               r.rating, r.comment
        FROM om_orders o
        INNER JOIN om_market_order_ratings r ON r.order_id = o.order_id
        LEFT JOIN om_store_penalties sp ON sp.order_id = o.order_id
        WHERE r.rating <= 2
          AND o.created_at >= NOW() - INTERVAL '7 days'
          AND sp.id IS NULL
          AND o.status = 'entregue'
        LIMIT 50
    ");

    while ($row = $lowRatingStmt->fetch()) {
        $category = 'bad_quality';
        $title = "Avaliacao baixa ({$row['rating']} estrelas) - {$row['customer_name']}";
        $description = $row['comment'] ?: 'Cliente deu avaliacao baixa sem comentario';

        $ruleStmt = dbQuery($db, "SELECT * FROM om_store_penalty_rules WHERE category = ?", [$category]);
        $rule = $ruleStmt->fetch();
        if (!$rule) continue;

        $penaltyAmount = calculatePenaltyAmount($rule, (float)$row['total']);

        dbQuery($db, "
            INSERT INTO om_store_penalties
                (order_id, partner_id, reported_by, reported_by_id, category, severity,
                 title, description, order_total, penalty_amount, refund_to_customer, status)
            VALUES (?, ?, 'system', ?, ?, 'low', ?, ?, ?, ?, 0, 'opened')
        ", [
            $row['order_id'], $row['partner_id'], $adminId, $category,
            $title, $description, $row['total'], $penaltyAmount
        ]);
        $created++;
    }

    // 2. Orders cancelled by store after accepting (last 7 days)
    $cancelledStmt = dbQuery($db, "
        SELECT o.order_id, o.partner_id, o.total, o.customer_name, o.cancel_reason
        FROM om_orders o
        LEFT JOIN om_store_penalties sp ON sp.order_id = o.order_id AND sp.category = 'store_cancelled'
        WHERE o.status = 'cancelado'
          AND o.cancelled_by = 'partner'
          AND o.created_at >= NOW() - INTERVAL '7 days'
          AND sp.id IS NULL
        LIMIT 50
    ");

    $cancelRule = dbQuery($db, "SELECT * FROM om_store_penalty_rules WHERE category = 'store_cancelled'")->fetch();
    if ($cancelRule) {
        while ($row = $cancelledStmt->fetch()) {
            $penaltyAmount = calculatePenaltyAmount($cancelRule, (float)$row['total']);
            dbQuery($db, "
                INSERT INTO om_store_penalties
                    (order_id, partner_id, reported_by, reported_by_id, category, severity,
                     title, description, order_total, penalty_amount, refund_to_customer, status)
                VALUES (?, ?, 'system', ?, 'store_cancelled', 'medium',
                        ?, ?, ?, ?, 0, ?)
            ", [
                $row['order_id'], $row['partner_id'], $adminId,
                "Loja cancelou pedido - {$row['customer_name']}",
                $row['cancel_reason'] ?: 'Loja cancelou o pedido apos aceitar',
                $row['total'], $penaltyAmount,
                $cancelRule['auto_apply'] ? 'confirmed' : 'opened'
            ]);
            $created++;
        }
    }

    response(true, ['created' => $created], "Auto-deteccao concluida: $created penalidades criadas");
}

function deductFromRepasse(PDO $db, int $adminId, array $input): void {
    $partnerId = (int)($input['partner_id'] ?? 0);
    $penaltyIds = $input['penalty_ids'] ?? [];
    $repassePeriod = trim($input['repasse_period'] ?? '');

    if (!$partnerId || empty($penaltyIds) || !$repassePeriod) {
        response(false, null, 'Campos obrigatorios: partner_id, penalty_ids, repasse_period', 400);
    }

    // Validate penalty_ids are integers
    $penaltyIds = array_map('intval', $penaltyIds);
    $penaltyIds = array_filter($penaltyIds, fn($id) => $id > 0);
    if (empty($penaltyIds)) {
        response(false, null, 'penalty_ids invalidos', 400);
    }

    $placeholders = implode(',', array_fill(0, count($penaltyIds), '?'));

    $db->beginTransaction();
    try {
        // Lock and verify penalties belong to this partner and are not already deducted
        $checkStmt = dbQuery($db, "
            SELECT id, penalty_amount, status, deducted_from_repasse
            FROM om_store_penalties
            WHERE id IN ($placeholders) AND partner_id = ?
            FOR UPDATE
        ", array_merge($penaltyIds, [$partnerId]));
        $found = $checkStmt->fetchAll();

        if (count($found) !== count($penaltyIds)) {
            $db->rollBack();
            response(false, null, 'Algumas penalidades nao encontradas ou nao pertencem a esta loja', 400);
        }

        $totalDeducted = 0;
        $alreadyDeducted = 0;
        foreach ($found as $p) {
            if ($p['deducted_from_repasse']) {
                $alreadyDeducted++;
                continue;
            }
            $totalDeducted += (float)$p['penalty_amount'];
        }

        // Mark as deducted
        dbQuery($db, "
            UPDATE om_store_penalties
            SET deducted_from_repasse = true,
                deducted_at = NOW(),
                repasse_period = ?,
                status = 'deducted',
                updated_at = NOW()
            WHERE id IN ($placeholders) AND partner_id = ? AND deducted_from_repasse = false
        ", array_merge([$repassePeriod], $penaltyIds, [$partnerId]));

        $db->commit();

        response(true, [
            'total_deducted' => $totalDeducted,
            'penalties_deducted' => count($penaltyIds) - $alreadyDeducted,
            'already_deducted' => $alreadyDeducted,
            'repasse_period' => $repassePeriod,
        ], 'Deducao registrada com sucesso');

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/* ===================== PUT ===================== */

function handlePut(PDO $db, int $adminId): void {
    $input = getInput();
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);

    if (!$id) {
        response(false, null, 'ID obrigatorio', 400);
    }

    $db->beginTransaction();
    try {
        // Lock penalty
        $stmt = dbQuery($db, "
            SELECT * FROM om_store_penalties WHERE id = ? FOR UPDATE
        ", [$id]);
        $penalty = $stmt->fetch();

        if (!$penalty) {
            $db->rollBack();
            response(false, null, 'Penalidade nao encontrada', 404);
        }

        $updates = [];
        $params = [];

        // Status change
        if (isset($input['status'])) {
            $validStatuses = ['opened', 'confirmed', 'deducted', 'resolved', 'cancelled'];
            $newStatus = trim($input['status']);
            if (!in_array($newStatus, $validStatuses, true)) {
                $db->rollBack();
                response(false, null, 'Status invalido', 400);
            }
            $updates[] = "status = ?";
            $params[] = $newStatus;

            if ($newStatus === 'resolved') {
                $updates[] = "resolved_by = ?";
                $params[] = $adminId;
                $updates[] = "resolved_at = NOW()";
            }
        }

        // Store dispute
        if (isset($input['store_disputed'])) {
            $updates[] = "store_disputed = ?";
            $params[] = (bool)$input['store_disputed'];
        }
        if (isset($input['store_response'])) {
            $updates[] = "store_response = ?";
            $params[] = trim($input['store_response']);
        }

        // Resolution
        if (isset($input['resolution'])) {
            $updates[] = "resolution = ?";
            $params[] = trim($input['resolution']);
        }

        // Severity change
        if (isset($input['severity'])) {
            $validSeverities = ['low', 'medium', 'high', 'critical'];
            if (in_array($input['severity'], $validSeverities, true)) {
                $updates[] = "severity = ?";
                $params[] = $input['severity'];
            }
        }

        // Penalty amount override
        if (isset($input['penalty_amount'])) {
            $updates[] = "penalty_amount = ?";
            $params[] = (float)$input['penalty_amount'];
        }

        // Refund amount override
        if (isset($input['refund_to_customer'])) {
            $updates[] = "refund_to_customer = ?";
            $params[] = (float)$input['refund_to_customer'];
        }

        if (empty($updates)) {
            $db->rollBack();
            response(false, null, 'Nenhuma alteracao enviada', 400);
        }

        $updates[] = "updated_at = NOW()";
        $params[] = $id;

        $sql = "UPDATE om_store_penalties SET " . implode(', ', $updates) . " WHERE id = ?";
        dbQuery($db, $sql, $params);

        $db->commit();

        // Re-fetch updated penalty
        $updatedStmt = dbQuery($db, "SELECT * FROM om_store_penalties WHERE id = ?", [$id]);
        $updated = $updatedStmt->fetch();

        response(true, $updated, 'Penalidade atualizada com sucesso');

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/* ===================== HELPERS ===================== */

function buildDateRange(string $period): string {
    switch ($period) {
        case 'today':
            return date('Y-m-d 00:00:00');
        case 'week':
            return date('Y-m-d 00:00:00', strtotime('-7 days'));
        case 'quarter':
            return date('Y-m-d 00:00:00', strtotime('-90 days'));
        case 'year':
            return date('Y-m-d 00:00:00', strtotime('-365 days'));
        case 'month':
        default:
            return date('Y-m-d 00:00:00', strtotime('-30 days'));
    }
}

function calculatePenaltyAmount(array $rule, float $orderTotal): float {
    switch ($rule['penalty_type']) {
        case 'full_order':
            return $orderTotal;
        case 'percent':
            $percent = (float)$rule['default_penalty_percent'];
            return round(($percent / 100) * $orderTotal, 2);
        case 'fixed':
            return (float)$rule['default_penalty_fixed'];
        default:
            return 0;
    }
}

function determineSeverity(array $rule, float $penaltyAmount, float $orderTotal): string {
    // Based on category and impact
    $category = $rule['category'];
    $criticalCategories = ['expired_food', 'unhygienic'];
    $highCategories = ['wrong_order', 'wrong_items'];

    if (in_array($category, $criticalCategories, true)) {
        return 'critical';
    }
    if (in_array($category, $highCategories, true)) {
        return 'high';
    }
    if ($penaltyAmount > 50 || ($orderTotal > 0 && ($penaltyAmount / $orderTotal) > 0.5)) {
        return 'high';
    }
    if ($penaltyAmount > 20) {
        return 'medium';
    }
    return 'low';
}

