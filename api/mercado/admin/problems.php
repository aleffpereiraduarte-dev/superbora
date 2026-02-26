<?php
/**
 * GET/POST /api/mercado/admin/problems.php
 * Admin visibility into customer + shopper order problems
 * Table: om_order_problems (shared by customer help.php and shopper problemas.php)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

/**
 * Escape LIKE/ILIKE special characters for safe use in parameterized patterns.
 */
function escapeLike(string $value): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];
    $adminName = $payload['name'] ?? 'Admin';

    $method = $_SERVER['REQUEST_METHOD'];

    // Check if table exists
    $tableCheck = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'om_order_problems')");
    if (!$tableCheck->fetchColumn()) {
        response(true, [
            'problems' => [],
            'stats' => ['total' => 0, 'open' => 0, 'resolved' => 0, 'customer_count' => 0, 'shopper_count' => 0],
            'pagination' => ['page' => 1, 'limit' => 20, 'total' => 0, 'pages' => 0],
            'message' => 'Tabela om_order_problems nao existe ainda'
        ]);
        // response() calls exit, but return for clarity
    }

    // GET
    if ($method === 'GET') {
        $view = $_GET['view'] ?? 'list';
        $problemId = (int)($_GET['id'] ?? 0);

        // Stats
        if ($view === 'stats') {
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('open','aberto','in_progress','em_analise') THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status IN ('resolved','resolvido','closed') THEN 1 ELSE 0 END) as resolved,
                    SUM(CASE WHEN customer_id IS NOT NULL AND (shopper_id IS NULL OR shopper_id = 0) THEN 1 ELSE 0 END) as customer_count,
                    SUM(CASE WHEN shopper_id IS NOT NULL AND shopper_id > 0 THEN 1 ELSE 0 END) as shopper_count
                FROM om_order_problems
            ");
            $stats = $stmt->fetch();

            // By severity (if column exists)
            try {
                $stmtSev = $db->query("
                    SELECT COALESCE(severity, 'medium') as severity, COUNT(*) as total
                    FROM om_order_problems
                    WHERE created_at > NOW() - INTERVAL '30 days'
                    GROUP BY severity
                    ORDER BY total DESC
                ");
                $stats['by_severity'] = $stmtSev->fetchAll();
            } catch (Exception $e) {
                $stats['by_severity'] = [];
            }

            // By category
            try {
                $stmtCat = $db->query("
                    SELECT COALESCE(category, problem_type, 'other') as category, COUNT(*) as total
                    FROM om_order_problems
                    WHERE created_at > NOW() - INTERVAL '30 days'
                    GROUP BY COALESCE(category, problem_type, 'other')
                    ORDER BY total DESC
                    LIMIT 10
                ");
                $stats['by_category'] = $stmtCat->fetchAll();
            } catch (Exception $e) {
                $stats['by_category'] = [];
            }

            // Chart last 14 days
            $stmtChart = $db->query("
                SELECT DATE(created_at) as dia, COUNT(*) as total,
                    SUM(CASE WHEN status IN ('resolved','resolvido','closed') THEN 1 ELSE 0 END) as resolved
                FROM om_order_problems
                WHERE created_at > NOW() - INTERVAL '14 days'
                GROUP BY DATE(created_at)
                ORDER BY dia
            ");
            $stats['chart'] = $stmtChart->fetchAll();

            response(true, ['stats' => $stats]);
        }

        // Detail
        if ($problemId) {
            // BUG FIX: c.id -> c.customer_id (PK of om_market_customers)
            // BUG FIX: c.nome -> c.name, c.celular -> c.phone, pt.nome -> pt.name
            $stmt = $db->prepare("
                SELECT p.problem_id, p.order_id, p.customer_id, p.shopper_id, p.status,
                    p.description, p.problem_type, p.category, p.subcategory, p.severity,
                    p.photo_urls, p.photo_evidence, p.resolution_note, p.resolved_at,
                    p.created_at, p.updated_at,
                    c.name as customer_name, c.email as customer_email, c.phone as customer_phone,
                    o.order_id as order_number, o.total as order_total, o.status as order_status, o.date_added as order_date,
                    pt.name as partner_name
                FROM om_order_problems p
                LEFT JOIN om_market_customers c ON c.customer_id = p.customer_id
                LEFT JOIN om_market_orders o ON o.order_id = p.order_id
                LEFT JOIN om_market_partners pt ON pt.partner_id = o.partner_id
                WHERE p.problem_id = ?
            ");
            $stmt->execute([$problemId]);
            $problem = $stmt->fetch();
            if (!$problem) response(false, null, "Problema nao encontrado", 404);

            // If shopper problem, get shopper info
            // BUG FIX: id -> shopper_id, nome -> name, celular -> phone
            $shopper = null;
            if (!empty($problem['shopper_id'])) {
                $stmtS = $db->prepare("SELECT shopper_id, name, email, phone FROM om_market_shoppers WHERE shopper_id = ?");
                $stmtS->execute([$problem['shopper_id']]);
                $shopper = $stmtS->fetch();
            }

            // Determine source
            $problem['source'] = (!empty($problem['shopper_id']) && $problem['shopper_id'] > 0) ? 'shopper' : 'customer';

            response(true, [
                'problem' => $problem,
                'shopper' => $shopper
            ]);
        }

        // List
        $source = $_GET['source'] ?? 'all';
        // Validate source against whitelist
        if (!in_array($source, ['all', 'customer', 'shopper'], true)) {
            $source = 'all';
        }
        $status = $_GET['status'] ?? null;
        $severity = $_GET['severity'] ?? null;
        $category = $_GET['category'] ?? null;
        $search = trim($_GET['search'] ?? '');
        $period = $_GET['period'] ?? '30d';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = "1=1";
        $params = [];

        // Source filter
        if ($source === 'customer') {
            $where .= " AND p.customer_id IS NOT NULL AND (p.shopper_id IS NULL OR p.shopper_id = 0)";
        } elseif ($source === 'shopper') {
            $where .= " AND p.shopper_id IS NOT NULL AND p.shopper_id > 0";
        }

        if ($status) {
            if ($status === 'open') {
                $where .= " AND p.status IN ('open','aberto','in_progress','em_analise')";
            } elseif ($status === 'resolved') {
                $where .= " AND p.status IN ('resolved','resolvido','closed')";
            } else {
                $where .= " AND p.status = ?";
                $params[] = $status;
            }
        }

        if ($severity) {
            $where .= " AND p.severity = ?";
            $params[] = $severity;
        }

        if ($category) {
            $where .= " AND COALESCE(p.category, p.problem_type) = ?";
            $params[] = $category;
        }

        // BUG FIX: Use parameterized interval instead of string interpolation
        $periodMap = ['7d' => '7 days', '30d' => '30 days', '90d' => '90 days', 'all' => null];
        $intervalStr = $periodMap[$period] ?? '30 days';
        if ($intervalStr) {
            $where .= " AND p.created_at > NOW() - CAST(? AS INTERVAL)";
            $params[] = $intervalStr;
        }

        // BUG FIX: Escape LIKE wildcards in search input + fix c.nome -> c.name
        if ($search) {
            $escapedSearch = escapeLike($search);
            $where .= " AND (c.name ILIKE ? OR CAST(p.problem_id AS TEXT) LIKE ? OR CAST(p.order_id AS TEXT) LIKE ? OR p.description ILIKE ?)";
            $params[] = "%{$escapedSearch}%";
            $params[] = "%{$escapedSearch}%";
            $params[] = "%{$escapedSearch}%";
            $params[] = "%{$escapedSearch}%";
        }

        // Count
        // BUG FIX: c.id -> c.customer_id
        $stmtCount = $db->prepare("
            SELECT COUNT(*) FROM om_order_problems p
            LEFT JOIN om_market_customers c ON c.customer_id = p.customer_id
            WHERE $where
        ");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // List
        // BUG FIX: c.id -> c.customer_id, c.nome -> c.name, pt.nome -> pt.name
        // BUG FIX: parameterize LIMIT/OFFSET instead of interpolation
        $listParams = $params;
        $listParams[] = $limit;
        $listParams[] = $offset;
        $stmt = $db->prepare("
            SELECT p.problem_id, p.order_id, p.customer_id, p.status, p.description, p.created_at,
                COALESCE(p.category, p.problem_type, 'other') as category,
                p.photo_evidence,
                c.name as customer_name, c.email as customer_email,
                o.total as order_total, o.status as order_status,
                pt.name as partner_name,
                CASE WHEN p.shopper_id IS NOT NULL AND p.shopper_id > 0 THEN 'shopper' ELSE 'customer' END as source
            FROM om_order_problems p
            LEFT JOIN om_market_customers c ON c.customer_id = p.customer_id
            LEFT JOIN om_market_orders o ON o.order_id = p.order_id
            LEFT JOIN om_market_partners pt ON pt.partner_id = o.partner_id
            WHERE $where
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($listParams);
        $problems = $stmt->fetchAll();

        // Try to add severity/subcategory/photo_urls if columns exist
        // BUG FIX: Use parameterized query instead of implode() SQL injection
        try {
            $ids = array_map(fn($p) => (int)$p['problem_id'], $problems);
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmtExtra = $db->prepare("
                    SELECT problem_id, severity, subcategory, photo_urls, shopper_id, resolution_note, resolved_at
                    FROM om_order_problems
                    WHERE problem_id IN ($placeholders)
                ");
                $stmtExtra->execute($ids);
                $extras = [];
                foreach ($stmtExtra->fetchAll() as $row) {
                    $extras[$row['problem_id']] = $row;
                }
                foreach ($problems as &$p) {
                    $pid = $p['problem_id'];
                    if (isset($extras[$pid])) {
                        $p['severity'] = $extras[$pid]['severity'] ?? 'medium';
                        $p['subcategory'] = $extras[$pid]['subcategory'] ?? null;
                        $p['photo_urls'] = $extras[$pid]['photo_urls'] ?? null;
                        $p['shopper_id'] = $extras[$pid]['shopper_id'] ?? null;
                        $p['resolution_note'] = $extras[$pid]['resolution_note'] ?? null;
                        $p['resolved_at'] = $extras[$pid]['resolved_at'] ?? null;
                    }
                }
                unset($p);
            }
        } catch (Exception $e) {
            // Columns may not exist yet -- that's fine
        }

        response(true, [
            'problems' => $problems,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $total > 0 ? ceil($total / $limit) : 0
            ]
        ]);
    }

    // POST
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';
        $problemId = (int)($input['problem_id'] ?? 0);

        if (!$problemId) response(false, null, "problem_id obrigatorio", 400);

        // Validate action against whitelist before any DB work
        $validActions = ['resolve', 'in_progress', 'escalate_to_dispute'];
        if (!in_array($action, $validActions, true)) {
            response(false, null, "Acao invalida. Validas: " . implode(', ', $validActions), 400);
        }

        $stmt = $db->prepare("SELECT problem_id, order_id, customer_id, shopper_id, status, description, problem_type, category, subcategory, severity, photo_urls, photo_evidence, resolution_note, resolved_at, created_at, updated_at FROM om_order_problems WHERE problem_id = ?");
        $stmt->execute([$problemId]);
        $problem = $stmt->fetch();
        if (!$problem) response(false, null, "Problema nao encontrado", 404);

        if ($action === 'resolve') {
            $resolutionNote = trim($input['resolution_note'] ?? '');
            if (empty($resolutionNote)) response(false, null, "Nota de resolucao obrigatoria", 400);
            // Limit resolution note length
            $resolutionNote = mb_substr($resolutionNote, 0, 5000, 'UTF-8');

            // Try update with resolution_note column, fallback to resolution
            try {
                $db->prepare("
                    UPDATE om_order_problems
                    SET status = 'resolved', resolution_note = ?, resolved_at = NOW()
                    WHERE problem_id = ?
                ")->execute([$resolutionNote, $problemId]);
            } catch (Exception $e) {
                $db->prepare("
                    UPDATE om_order_problems
                    SET status = 'resolved', resolution = ?
                    WHERE problem_id = ?
                ")->execute([$resolutionNote, $problemId]);
            }

            // Notify customer
            // BUG FIX: Use correct column names for om_market_notifications table
            // (recipient_id, recipient_type, title, message, data, is_read, sent_at)
            $customerId = $problem['customer_id'] ?? null;
            if ($customerId) {
                try {
                    $db->prepare("
                        INSERT INTO om_market_notifications (recipient_id, recipient_type, title, message, data, is_read, sent_at)
                        VALUES (?, 'customer', ?, ?, ?, 0, NOW())
                    ")->execute([
                        $customerId,
                        "Problema #{$problemId} resolvido",
                        $resolutionNote,
                        json_encode(['type' => 'suporte', 'reference_type' => 'problem', 'reference_id' => $problemId], JSON_UNESCAPED_UNICODE)
                    ]);
                } catch (Exception $e) {
                    error_log("[admin/problems] Notification failed: " . $e->getMessage());
                }
            }

            response(true, ['message' => 'Problema resolvido']);
        }

        if ($action === 'in_progress') {
            // Only transition from open/aberto states (prevent overwriting resolved/closed)
            $currentStatus = $problem['status'] ?? '';
            $canTransition = in_array($currentStatus, ['open', 'aberto'], true);
            if (!$canTransition) {
                response(false, null, "Problema com status '{$currentStatus}' nao pode ser alterado para em analise", 400);
            }

            $db->prepare("UPDATE om_order_problems SET status = 'in_progress' WHERE problem_id = ? AND status IN ('open','aberto')")
                ->execute([$problemId]);
            response(true, ['message' => 'Problema em analise']);
        }

        if ($action === 'escalate_to_dispute') {
            // Prevent escalating already resolved/closed problems
            $currentStatus = $problem['status'] ?? '';
            if (in_array($currentStatus, ['resolved', 'resolvido', 'closed'], true)) {
                response(false, null, "Problema ja resolvido/fechado nao pode ser escalado", 400);
            }

            $db->beginTransaction();

            try {
                $category = $problem['category'] ?? $problem['problem_type'] ?? 'other';
                $description = $problem['description'] ?? '';
                $orderId = $problem['order_id'] ?? 0;
                $customerId = $problem['customer_id'] ?? 0;

                // Get partner_id from order
                $partnerId = 0;
                if ($orderId) {
                    $stmtOrd = $db->prepare("SELECT partner_id FROM om_market_orders WHERE order_id = ?");
                    $stmtOrd->execute([$orderId]);
                    $partnerId = (int)($stmtOrd->fetchColumn() ?: 0);
                }

                // Create dispute
                $stmtDisp = $db->prepare("
                    INSERT INTO om_order_disputes (order_id, customer_id, partner_id, category, subcategory, description, severity, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'medium', 'in_review', NOW(), NOW())
                    RETURNING dispute_id
                ");
                $escalationDesc = "Escalado do problema #{$problemId}: " . mb_substr($description, 0, 2000, 'UTF-8');
                $stmtDisp->execute([$orderId, $customerId, $partnerId, $category, $category, $escalationDesc]);
                $disputeId = (int)$stmtDisp->fetchColumn();

                if (!$disputeId) {
                    throw new Exception("Failed to create dispute - no dispute_id returned");
                }

                // Timeline
                $db->prepare("
                    INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                    VALUES (?, 'created', 'admin', ?, ?, NOW())
                ")->execute([$disputeId, $adminId, "Escalado do problema #{$problemId} por " . mb_substr($adminName, 0, 100, 'UTF-8')]);

                // Close original problem
                try {
                    $db->prepare("UPDATE om_order_problems SET status = 'closed', resolution_note = ? WHERE problem_id = ?")
                        ->execute(["Escalado para disputa #{$disputeId}", $problemId]);
                } catch (Exception $e) {
                    $db->prepare("UPDATE om_order_problems SET status = 'closed', resolution = ? WHERE problem_id = ?")
                        ->execute(["Escalado para disputa #{$disputeId}", $problemId]);
                }

                $db->commit();
                response(true, ['message' => "Escalado para disputa #{$disputeId}", 'dispute_id' => $disputeId]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // This should be unreachable due to early validation, but kept as safety net
        response(false, null, "Acao invalida", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/problems] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
