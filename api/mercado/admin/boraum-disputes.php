<?php
/**
 * BoraUm Dispute/Ticket Management API
 *
 * Tracks and manages disputes with BoraUm for delivery issues:
 * damaged orders, driver cancellations, late deliveries, overcharges, etc.
 *
 * GET: List disputes (with filters, pagination, summary stats)
 * GET ?id=N: Single dispute with comments/timeline
 * POST: Create new dispute
 * POST ?action=comment: Add comment to dispute
 * POST ?action=auto_detect: System auto-creates dispute (called by webhook)
 * PUT: Update dispute (status, resolution, refund, etc.)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Admin auth: JWT admin token
    $adminPayload = om_auth()->requireAdmin();
    $adminId = (int)($adminPayload['uid'] ?? $adminPayload['user_id'] ?? 0);

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
    error_log("[BoraUm Disputes] DB error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
} catch (Exception $e) {
    if (in_array(http_response_code(), [401, 403], true)) {
        exit;
    }
    error_log("[BoraUm Disputes] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}

// =============================================================================
// GET handlers
// =============================================================================

function handleGet(PDO $db): void {
    $disputeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($disputeId) {
        getDisputeDetail($db, $disputeId);
    } else {
        getDisputeList($db);
    }
}

function getDisputeDetail(PDO $db, int $disputeId): void {
    // Fetch dispute with order and store info
    $stmt = $db->prepare("
        SELECT d.*,
               o.order_number,
               o.customer_name,
               o.customer_phone,
               o.partner_name,
               o.partner_id,
               o.total AS current_order_total,
               o.delivery_fee AS current_delivery_fee,
               o.status AS order_status,
               o.payment_method,
               o.date_added AS order_date,
               e.motorista_nome AS driver_name,
               e.motorista_telefone AS driver_phone,
               e.boraum_status AS delivery_status,
               e.distancia_km
        FROM om_boraum_disputes d
        LEFT JOIN om_market_orders o ON d.order_id = o.order_id
        LEFT JOIN om_entregas e ON d.entrega_id = e.id
        WHERE d.id = ?
    ");
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) {
        response(false, null, 'Disputa nao encontrada', 404);
    }

    // Decode photos JSON
    $dispute['photos'] = json_decode($dispute['photos'] ?? '[]', true) ?: [];

    // Cast numeric fields
    $dispute['id'] = (int)$dispute['id'];
    $dispute['order_id'] = (int)$dispute['order_id'];
    $dispute['entrega_id'] = $dispute['entrega_id'] ? (int)$dispute['entrega_id'] : null;
    $dispute['opened_by_id'] = $dispute['opened_by_id'] ? (int)$dispute['opened_by_id'] : null;
    $dispute['resolved_by'] = $dispute['resolved_by'] ? (int)$dispute['resolved_by'] : null;
    $dispute['order_total'] = $dispute['order_total'] !== null ? round((float)$dispute['order_total'], 2) : null;
    $dispute['delivery_cost'] = $dispute['delivery_cost'] !== null ? round((float)$dispute['delivery_cost'], 2) : null;
    $dispute['refund_requested'] = $dispute['refund_requested'] !== null ? round((float)$dispute['refund_requested'], 2) : null;
    $dispute['refund_approved'] = $dispute['refund_approved'] !== null ? round((float)$dispute['refund_approved'], 2) : null;
    $dispute['refund_paid'] = $dispute['refund_paid'] !== null ? round((float)$dispute['refund_paid'], 2) : null;
    $dispute['distancia_km'] = $dispute['distancia_km'] !== null ? round((float)$dispute['distancia_km'], 2) : null;

    // Fetch comments/timeline
    $stmtComments = $db->prepare("
        SELECT id, dispute_id, author_type, author_id, author_name, comment, attachments, created_at
        FROM om_boraum_dispute_comments
        WHERE dispute_id = ?
        ORDER BY created_at ASC
    ");
    $stmtComments->execute([$disputeId]);
    $comments = $stmtComments->fetchAll(PDO::FETCH_ASSOC);

    foreach ($comments as &$c) {
        $c['id'] = (int)$c['id'];
        $c['dispute_id'] = (int)$c['dispute_id'];
        $c['author_id'] = $c['author_id'] ? (int)$c['author_id'] : null;
        $c['attachments'] = json_decode($c['attachments'] ?? '[]', true) ?: [];
    }
    unset($c);

    $dispute['comments'] = $comments;

    response(true, $dispute);
}

function getDisputeList(PDO $db): void {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Build filters
    $where = [];
    $params = [];

    // Status filter (comma-separated)
    if (!empty($_GET['status'])) {
        $statuses = array_filter(array_map('trim', explode(',', $_GET['status'])));
        if ($statuses) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "d.status IN ($placeholders)";
            $params = array_merge($params, $statuses);
        }
    }

    // Category filter (comma-separated)
    if (!empty($_GET['category'])) {
        $categories = array_filter(array_map('trim', explode(',', $_GET['category'])));
        if ($categories) {
            $placeholders = implode(',', array_fill(0, count($categories), '?'));
            $where[] = "d.category IN ($placeholders)";
            $params = array_merge($params, $categories);
        }
    }

    // Severity filter
    if (!empty($_GET['severity'])) {
        $severities = array_filter(array_map('trim', explode(',', $_GET['severity'])));
        if ($severities) {
            $placeholders = implode(',', array_fill(0, count($severities), '?'));
            $where[] = "d.severity IN ($placeholders)";
            $params = array_merge($params, $severities);
        }
    }

    // Period filter
    $period = $_GET['period'] ?? '';
    switch ($period) {
        case 'today':
            $where[] = "DATE(d.created_at) = CURRENT_DATE";
            break;
        case 'week':
            $where[] = "d.created_at >= (CURRENT_DATE - INTERVAL '7 days')";
            break;
        case 'month':
            $where[] = "d.created_at >= (CURRENT_DATE - INTERVAL '30 days')";
            break;
        case 'custom':
            if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
                $sd = $_GET['start_date'];
                $ed = $_GET['end_date'];
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed)) {
                    $where[] = "DATE(d.created_at) BETWEEN ? AND ?";
                    $params[] = $sd;
                    $params[] = $ed;
                }
            }
            break;
    }

    // Search by order number or title
    if (!empty($_GET['search'])) {
        $search = '%' . trim($_GET['search']) . '%';
        $where[] = "(d.title ILIKE ? OR o.order_number ILIKE ? OR o.partner_name ILIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // === SUMMARY STATS (all disputes matching filters, no pagination) ===
    $summaryParams = $params; // same filters, no limit/offset
    $summaryStmt = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN d.status = 'opened' THEN 1 ELSE 0 END) AS opened,
            SUM(CASE WHEN d.status = 'investigating' THEN 1 ELSE 0 END) AS investigating,
            SUM(CASE WHEN d.status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
            SUM(CASE WHEN d.status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN d.status = 'escalated' THEN 1 ELSE 0 END) AS escalated,
            COALESCE(SUM(CASE WHEN d.refund_requested THEN 1 ELSE 0 END), 0) AS total_refund_requested,
            COALESCE(SUM(CASE WHEN d.refund_approved THEN 1 ELSE 0 END), 0) AS total_refund_approved,
            COALESCE(SUM(CASE WHEN d.refund_paid THEN 1 ELSE 0 END), 0) AS total_refund_paid,
            COALESCE(SUM(CASE WHEN d.status IN ('opened', 'investigating', 'escalated') THEN CASE WHEN d.refund_requested THEN 1 ELSE 0 END ELSE 0 END), 0) AS pending_refund,
            SUM(CASE WHEN d.severity = 'critical' THEN 1 ELSE 0 END) AS critical_count,
            SUM(CASE WHEN d.severity = 'high' THEN 1 ELSE 0 END) AS high_count
        FROM om_boraum_disputes d
        LEFT JOIN om_market_orders o ON d.order_id = o.order_id
        $whereClause
    ");
    $summaryStmt->execute($summaryParams);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    // Category breakdown
    $catStmt = $db->prepare("
        SELECT d.category, COUNT(*) AS count
        FROM om_boraum_disputes d
        LEFT JOIN om_market_orders o ON d.order_id = o.order_id
        $whereClause
        GROUP BY d.category
        ORDER BY count DESC
    ");
    $catStmt->execute($summaryParams);
    $categoryBreakdown = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    // Who pays breakdown
    $payerStmt = $db->prepare("
        SELECT d.who_pays, COUNT(*) AS count, COALESCE(SUM(CASE WHEN d.refund_approved THEN 1 ELSE 0 END), 0) AS total_amount
        FROM om_boraum_disputes d
        LEFT JOIN om_market_orders o ON d.order_id = o.order_id
        $whereClause
        AND d.who_pays IS NOT NULL
        GROUP BY d.who_pays
        ORDER BY total_amount DESC
    ");
    $payerStmt->execute($summaryParams);
    $payerBreakdown = $payerStmt->fetchAll(PDO::FETCH_ASSOC);

    // === DISPUTES LIST (paginated) ===
    $listParams = array_merge($params, [$limit, $offset]);
    $listStmt = $db->prepare("
        SELECT d.id, d.order_id, d.entrega_id, d.boraum_delivery_id,
               d.opened_by, d.category, d.severity, d.title,
               d.order_total, d.delivery_cost, d.refund_requested, d.refund_approved, d.refund_paid,
               d.who_pays, d.status, d.boraum_accepted,
               d.created_at, d.updated_at, d.resolved_at,
               o.order_number, o.partner_name, o.customer_name,
               (SELECT COUNT(*) FROM om_boraum_dispute_comments c WHERE c.dispute_id = d.id) AS comment_count
        FROM om_boraum_disputes d
        LEFT JOIN om_market_orders o ON d.order_id = o.order_id
        $whereClause
        ORDER BY
            CASE d.severity
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
                ELSE 5
            END,
            d.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $listStmt->execute($listParams);
    $disputes = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast types
    foreach ($disputes as &$d) {
        $d['id'] = (int)$d['id'];
        $d['order_id'] = (int)$d['order_id'];
        $d['order_total'] = $d['order_total'] !== null ? round((float)$d['order_total'], 2) : null;
        $d['delivery_cost'] = $d['delivery_cost'] !== null ? round((float)$d['delivery_cost'], 2) : null;
        $d['refund_requested'] = $d['refund_requested'] !== null ? round((float)$d['refund_requested'], 2) : null;
        $d['refund_approved'] = $d['refund_approved'] !== null ? round((float)$d['refund_approved'], 2) : null;
        $d['refund_paid'] = $d['refund_paid'] !== null ? round((float)$d['refund_paid'], 2) : null;
        $d['comment_count'] = (int)$d['comment_count'];
    }
    unset($d);

    // Total count for pagination
    $countStmt = $db->prepare("
        SELECT COUNT(*) AS total
        FROM om_boraum_disputes d
        LEFT JOIN om_market_orders o ON d.order_id = o.order_id
        $whereClause
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['total'];

    response(true, [
        'summary' => [
            'total' => (int)$summary['total'],
            'opened' => (int)$summary['opened'],
            'investigating' => (int)$summary['investigating'],
            'resolved' => (int)$summary['resolved'],
            'rejected' => (int)$summary['rejected'],
            'escalated' => (int)$summary['escalated'],
            'total_refund_requested' => round((float)$summary['total_refund_requested'], 2),
            'total_refund_approved' => round((float)$summary['total_refund_approved'], 2),
            'total_refund_paid' => round((float)$summary['total_refund_paid'], 2),
            'pending_refund' => round((float)$summary['pending_refund'], 2),
            'critical_count' => (int)$summary['critical_count'],
            'high_count' => (int)$summary['high_count'],
        ],
        'category_breakdown' => $categoryBreakdown,
        'payer_breakdown' => $payerBreakdown,
        'disputes' => $disputes,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit),
        ],
    ]);
}

// =============================================================================
// POST handlers
// =============================================================================

function handlePost(PDO $db, int $adminId): void {
    $action = $_GET['action'] ?? '';
    $input = getInput();

    switch ($action) {
        case 'comment':
            addComment($db, $input, $adminId);
            break;
        case 'auto_detect':
            autoDetect($db, $input);
            break;
        default:
            createDispute($db, $input, $adminId);
            break;
    }
}

function createDispute(PDO $db, array $input, int $adminId): void {
    $orderId = (int)($input['order_id'] ?? 0);
    $category = trim($input['category'] ?? '');
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $photos = $input['photos'] ?? [];
    $refundRequested = isset($input['refund_requested']) ? round((float)$input['refund_requested'], 2) : null;
    $severity = $input['severity'] ?? 'medium';
    $openedBy = $input['opened_by'] ?? 'admin';
    $openedById = (int)($input['opened_by_id'] ?? $adminId);

    // Validate required fields
    if (!$orderId) {
        response(false, null, 'order_id obrigatorio', 400);
    }

    $validCategories = ['damaged', 'lost', 'late', 'wrong_delivery', 'driver_behavior', 'cancelled_by_driver', 'food_spilled', 'not_delivered', 'overcharged', 'other'];
    if (!in_array($category, $validCategories)) {
        response(false, null, 'Categoria invalida. Validas: ' . implode(', ', $validCategories), 400);
    }

    if (empty($title)) {
        response(false, null, 'Titulo obrigatorio', 400);
    }

    $validSeverities = ['low', 'medium', 'high', 'critical'];
    if (!in_array($severity, $validSeverities)) {
        $severity = 'medium';
    }

    $validOpeners = ['customer', 'store', 'admin', 'system'];
    if (!in_array($openedBy, $validOpeners)) {
        $openedBy = 'admin';
    }

    // Validate photos are actual URLs
    if (!is_array($photos)) {
        $photos = [];
    }
    $photos = array_filter($photos, function($url) {
        return is_string($url) && preg_match('#^https?://#i', $url);
    });

    // Fetch order info to auto-fill financial data
    $stmt = $db->prepare("SELECT order_id, total, delivery_fee, order_number FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        response(false, null, 'Pedido nao encontrado', 404);
    }

    $orderTotal = round((float)($order['total'] ?? 0), 2);
    $deliveryCost = round((float)($order['delivery_fee'] ?? 0), 2);

    // Fetch entrega info
    $entregaId = null;
    $boraumDeliveryId = null;
    $stmtE = $db->prepare("SELECT id, boraum_delivery_id, valor_frete FROM om_entregas WHERE referencia_id = ? AND origem_sistema = 'mercado' ORDER BY id DESC LIMIT 1");
    $stmtE->execute([$orderId]);
    $entrega = $stmtE->fetch(PDO::FETCH_ASSOC);

    if ($entrega) {
        $entregaId = (int)$entrega['id'];
        $boraumDeliveryId = $entrega['boraum_delivery_id'];
        // Use actual BoraUm cost if available
        if ($entrega['valor_frete']) {
            $deliveryCost = round((float)$entrega['valor_frete'], 2);
        }
    }

    // Check for duplicate dispute on same order + category
    $stmtDup = $db->prepare("
        SELECT id FROM om_boraum_disputes
        WHERE order_id = ? AND category = ? AND status NOT IN ('resolved', 'rejected')
        LIMIT 1
    ");
    $stmtDup->execute([$orderId, $category]);
    if ($stmtDup->fetch()) {
        response(false, null, 'Ja existe uma disputa aberta para este pedido nesta categoria', 409);
    }

    // Insert dispute
    $stmt = $db->prepare("
        INSERT INTO om_boraum_disputes
            (order_id, entrega_id, boraum_delivery_id, opened_by, opened_by_id,
             category, severity, title, description, photos,
             order_total, delivery_cost, refund_requested, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'opened', NOW(), NOW())
        RETURNING id
    ");
    $stmt->execute([
        $orderId, $entregaId, $boraumDeliveryId,
        $openedBy, $openedById,
        $category, $severity, $title, $description, json_encode($photos),
        $orderTotal, $deliveryCost, $refundRequested,
    ]);
    $newId = (int)$stmt->fetch()['id'];

    // Auto-add system comment for creation
    $db->prepare("
        INSERT INTO om_boraum_dispute_comments (dispute_id, author_type, author_id, author_name, comment, created_at)
        VALUES (?, 'system', NULL, 'Sistema', ?, NOW())
    ")->execute([
        $newId,
        "Disputa #{$newId} aberta por {$openedBy}" . ($openedById ? " (ID: {$openedById})" : "") . ". Pedido #{$order['order_number']}. Categoria: {$category}. Severidade: {$severity}."
    ]);

    error_log("[BoraUm Disputes] Dispute #{$newId} created for order #{$orderId} by {$openedBy} - category: {$category}");

    response(true, ['id' => $newId, 'status' => 'opened'], 'Disputa criada com sucesso');
}

function addComment(PDO $db, array $input, int $adminId): void {
    $disputeId = (int)($input['dispute_id'] ?? 0);
    $comment = trim($input['comment'] ?? '');
    $authorType = $input['author_type'] ?? 'admin';
    $authorName = trim($input['author_name'] ?? '');
    $attachments = $input['attachments'] ?? [];

    if (!$disputeId) {
        response(false, null, 'dispute_id obrigatorio', 400);
    }
    if (empty($comment)) {
        response(false, null, 'Comentario obrigatorio', 400);
    }

    // Verify dispute exists
    $stmt = $db->prepare("SELECT id, status FROM om_boraum_disputes WHERE id = ?");
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch();
    if (!$dispute) {
        response(false, null, 'Disputa nao encontrada', 404);
    }

    $validAuthorTypes = ['admin', 'customer', 'store', 'boraum', 'system'];
    if (!in_array($authorType, $validAuthorTypes)) {
        $authorType = 'admin';
    }

    if (!is_array($attachments)) {
        $attachments = [];
    }
    $attachments = array_filter($attachments, function($url) {
        return is_string($url) && preg_match('#^https?://#i', $url);
    });

    $authorId = ($authorType === 'admin') ? $adminId : (int)($input['author_id'] ?? 0);

    $stmtInsert = $db->prepare("
        INSERT INTO om_boraum_dispute_comments (dispute_id, author_type, author_id, author_name, comment, attachments, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmtInsert->execute([
        $disputeId, $authorType, $authorId ?: null, $authorName ?: null,
        $comment, json_encode($attachments)
    ]);
    $commentId = (int)$stmtInsert->fetch()['id'];

    // Update dispute updated_at
    $db->prepare("UPDATE om_boraum_disputes SET updated_at = NOW() WHERE id = ?")->execute([$disputeId]);

    response(true, ['comment_id' => $commentId], 'Comentario adicionado');
}

function autoDetect(PDO $db, array $input): void {
    // Called by webhook or cron to auto-create disputes
    $orderId = (int)($input['order_id'] ?? 0);
    $category = trim($input['category'] ?? '');
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $severity = $input['severity'] ?? 'medium';
    $entregaId = isset($input['entrega_id']) ? (int)$input['entrega_id'] : null;
    $boraumDeliveryId = $input['boraum_delivery_id'] ?? null;

    if (!$orderId || !$category || !$title) {
        response(false, null, 'order_id, category e title obrigatorios', 400);
    }

    // Check for duplicate
    $stmtDup = $db->prepare("
        SELECT id FROM om_boraum_disputes
        WHERE order_id = ? AND category = ? AND status NOT IN ('resolved', 'rejected')
        LIMIT 1
    ");
    $stmtDup->execute([$orderId, $category]);
    if ($dup = $stmtDup->fetch()) {
        response(true, ['id' => (int)$dup['id'], 'duplicate' => true], 'Disputa ja existe');
        return;
    }

    // Fetch order info
    $stmtO = $db->prepare("SELECT total, delivery_fee, order_number FROM om_market_orders WHERE order_id = ?");
    $stmtO->execute([$orderId]);
    $order = $stmtO->fetch();
    $orderTotal = $order ? round((float)$order['total'], 2) : null;
    $deliveryFee = $order ? round((float)$order['delivery_fee'], 2) : null;

    // Fetch entrega if not provided
    if (!$entregaId) {
        $stmtE = $db->prepare("SELECT id, boraum_delivery_id, valor_frete FROM om_entregas WHERE referencia_id = ? AND origem_sistema = 'mercado' ORDER BY id DESC LIMIT 1");
        $stmtE->execute([$orderId]);
        $entrega = $stmtE->fetch();
        if ($entrega) {
            $entregaId = (int)$entrega['id'];
            $boraumDeliveryId = $boraumDeliveryId ?: $entrega['boraum_delivery_id'];
            if ($entrega['valor_frete']) {
                $deliveryFee = round((float)$entrega['valor_frete'], 2);
            }
        }
    }

    $refundRequested = $deliveryFee; // Default: request refund of delivery cost
    $whoPays = null;

    // Set defaults based on category
    if ($category === 'cancelled_by_driver') {
        $whoPays = 'boraum';
        $severity = 'high';
    } elseif ($category === 'late') {
        $whoPays = null; // To be determined
        $severity = $severity ?: 'low';
    } elseif (in_array($category, ['damaged', 'food_spilled', 'lost', 'not_delivered'])) {
        $whoPays = 'boraum';
        $severity = 'high';
        $refundRequested = $orderTotal; // Full order refund
    }

    $stmtInsert = $db->prepare("
        INSERT INTO om_boraum_disputes
            (order_id, entrega_id, boraum_delivery_id, opened_by, opened_by_id,
             category, severity, title, description,
             order_total, delivery_cost, refund_requested, who_pays, status, created_at, updated_at)
        VALUES (?, ?, ?, 'system', NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'opened', NOW(), NOW())
        RETURNING id
    ");
    $stmtInsert->execute([
        $orderId, $entregaId, $boraumDeliveryId,
        $category, $severity, $title, $description,
        $orderTotal, $deliveryFee, $refundRequested, $whoPays,
    ]);
    $newId = (int)$stmtInsert->fetch()['id'];

    // System comment
    $db->prepare("
        INSERT INTO om_boraum_dispute_comments (dispute_id, author_type, author_name, comment, created_at)
        VALUES (?, 'system', 'Sistema', ?, NOW())
    ")->execute([
        $newId,
        "Disputa auto-detectada pelo sistema. Categoria: {$category}. " . ($description ?: 'Sem descricao adicional.')
    ]);

    error_log("[BoraUm Disputes] Auto-detected dispute #{$newId} for order #{$orderId} - category: {$category}");

    response(true, ['id' => $newId, 'status' => 'opened'], 'Disputa auto-criada');
}

// =============================================================================
// PUT handler
// =============================================================================

function handlePut(PDO $db, int $adminId): void {
    $input = getInput();
    $disputeId = (int)($input['id'] ?? 0);

    if (!$disputeId) {
        response(false, null, 'id obrigatorio', 400);
    }

    // Verify dispute exists
    $stmt = $db->prepare("SELECT * FROM om_boraum_disputes WHERE id = ? FOR UPDATE");
    $db->beginTransaction();

    try {
        $stmt->execute([$disputeId]);
        $dispute = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dispute) {
            $db->rollBack();
            response(false, null, 'Disputa nao encontrada', 404);
        }

        $updates = [];
        $params = [];
        $changeLog = [];

        // Status change
        if (isset($input['status'])) {
            $validStatuses = ['opened', 'investigating', 'resolved', 'rejected', 'escalated'];
            if (in_array($input['status'], $validStatuses)) {
                $updates[] = "status = ?";
                $params[] = $input['status'];
                $changeLog[] = "Status: {$dispute['status']} -> {$input['status']}";

                if (in_array($input['status'], ['resolved', 'rejected'])) {
                    $updates[] = "resolved_at = NOW()";
                    $updates[] = "resolved_by = ?";
                    $params[] = $adminId;
                }
            }
        }

        // Resolution text
        if (isset($input['resolution'])) {
            $updates[] = "resolution = ?";
            $params[] = trim($input['resolution']);
            $changeLog[] = "Resolucao atualizada";
        }

        // Refund approved
        if (isset($input['refund_approved'])) {
            $updates[] = "refund_approved = ?";
            $params[] = round((float)$input['refund_approved'], 2);
            $changeLog[] = "Reembolso aprovado: R$ " . number_format((float)$input['refund_approved'], 2, ',', '.');
        }

        // Refund paid
        if (isset($input['refund_paid'])) {
            $updates[] = "refund_paid = ?";
            $params[] = round((float)$input['refund_paid'], 2);
            $changeLog[] = "Reembolso pago: R$ " . number_format((float)$input['refund_paid'], 2, ',', '.');
        }

        // Who pays
        if (isset($input['who_pays'])) {
            $validPayers = ['boraum', 'superbora', 'split', 'customer', 'none'];
            if (in_array($input['who_pays'], $validPayers)) {
                $updates[] = "who_pays = ?";
                $params[] = $input['who_pays'];
                $changeLog[] = "Responsavel: {$input['who_pays']}";
            }
        }

        // Severity
        if (isset($input['severity'])) {
            $validSeverities = ['low', 'medium', 'high', 'critical'];
            if (in_array($input['severity'], $validSeverities)) {
                $updates[] = "severity = ?";
                $params[] = $input['severity'];
                $changeLog[] = "Severidade: {$dispute['severity']} -> {$input['severity']}";
            }
        }

        // BoraUm response
        if (isset($input['boraum_ticket_id'])) {
            $updates[] = "boraum_ticket_id = ?";
            $params[] = trim($input['boraum_ticket_id']);
            $changeLog[] = "Ticket BoraUm: {$input['boraum_ticket_id']}";
        }
        if (isset($input['boraum_response'])) {
            $updates[] = "boraum_response = ?";
            $params[] = trim($input['boraum_response']);
            $changeLog[] = "Resposta BoraUm registrada";
        }
        if (isset($input['boraum_accepted'])) {
            $updates[] = "boraum_accepted = ?";
            $params[] = (bool)$input['boraum_accepted'];
            $changeLog[] = "BoraUm aceitou: " . ($input['boraum_accepted'] ? 'Sim' : 'Nao');
        }

        if (empty($updates)) {
            $db->rollBack();
            response(false, null, 'Nenhuma alteracao fornecida', 400);
        }

        $updates[] = "updated_at = NOW()";
        $params[] = $disputeId;

        $sql = "UPDATE om_boraum_disputes SET " . implode(', ', $updates) . " WHERE id = ?";
        $db->prepare($sql)->execute($params);

        // Log changes as a system comment
        if ($changeLog) {
            $db->prepare("
                INSERT INTO om_boraum_dispute_comments (dispute_id, author_type, author_id, author_name, comment, created_at)
                VALUES (?, 'system', ?, 'Admin', ?, NOW())
            ")->execute([
                $disputeId,
                $adminId,
                "Atualizacao: " . implode('. ', $changeLog) . "."
            ]);
        }

        $db->commit();

        error_log("[BoraUm Disputes] Dispute #{$disputeId} updated by admin #{$adminId}: " . implode(', ', $changeLog));

        response(true, ['id' => $disputeId], 'Disputa atualizada');
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
