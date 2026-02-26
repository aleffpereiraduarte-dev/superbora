<?php
/**
 * GET/POST /api/mercado/admin/disputes.php
 * Admin dispute management — view, resolve, escalate, assign disputes
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/guards.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];
    $adminName = $payload['name'] ?? 'Admin';

    $method = $_SERVER['REQUEST_METHOD'];

    // Check if dispute tables exist
    $tableCheck = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'om_order_disputes')");
    if (!$tableCheck->fetchColumn()) {
        response(true, [
            'disputes' => [],
            'stats' => ['total' => 0, 'open' => 0, 'in_review' => 0, 'escalated' => 0, 'resolved' => 0],
            'pagination' => ['page' => 1, 'limit' => 20, 'total' => 0, 'pages' => 0],
            'message' => 'Modulo de disputas ainda nao configurado'
        ]);
    }

    // GET — stats, list, detail
    if ($method === 'GET') {
        $view = $_GET['view'] ?? 'list';
        $disputeId = (int)($_GET['id'] ?? 0);

        // Stats
        if ($view === 'stats') {
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('open','awaiting_evidence') THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'in_review' THEN 1 ELSE 0 END) as in_review,
                    SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) as escalated,
                    SUM(CASE WHEN status IN ('auto_resolved','resolved','closed') THEN 1 ELSE 0 END) as resolved,
                    SUM(CASE WHEN severity = 'critical' AND status NOT IN ('auto_resolved','resolved','closed') THEN 1 ELSE 0 END) as critical_open,
                    SUM(CASE WHEN is_suspicious = TRUE THEN 1 ELSE 0 END) as suspicious,
                    COALESCE(SUM(approved_amount), 0) as total_refunded,
                    COALESCE(SUM(credit_amount), 0) as total_credits
                FROM om_order_disputes
            ");
            $stats = $stmt->fetch();

            // SLA metrics
            $stmtSla = $db->query("
                SELECT
                    ROUND(AVG(EXTRACT(EPOCH FROM (COALESCE(resolved_at, NOW()) - created_at)) / 3600)::numeric, 1) as avg_resolution_hours,
                    COUNT(CASE WHEN resolved_at IS NOT NULL AND EXTRACT(EPOCH FROM (resolved_at - created_at)) < 86400 THEN 1 END) as resolved_24h,
                    COUNT(CASE WHEN resolved_at IS NOT NULL THEN 1 END) as total_resolved
                FROM om_order_disputes
                WHERE created_at > NOW() - INTERVAL '30 days'
            ");
            $sla = $stmtSla->fetch();
            $stats['avg_resolution_hours'] = (float)($sla['avg_resolution_hours'] ?? 0);
            $stats['resolved_24h_pct'] = $sla['total_resolved'] > 0
                ? round(($sla['resolved_24h'] / $sla['total_resolved']) * 100, 1)
                : 0;

            // Last 7 days chart
            $stmtChart = $db->query("
                SELECT DATE(created_at) as dia, COUNT(*) as total,
                    SUM(CASE WHEN auto_resolved = TRUE THEN 1 ELSE 0 END) as auto_resolved
                FROM om_order_disputes
                WHERE created_at > NOW() - INTERVAL '7 days'
                GROUP BY DATE(created_at)
                ORDER BY dia
            ");
            $stats['chart'] = $stmtChart->fetchAll();

            // Top categories
            $stmtTop = $db->query("
                SELECT category, subcategory, COUNT(*) as total
                FROM om_order_disputes
                WHERE created_at > NOW() - INTERVAL '30 days'
                GROUP BY category, subcategory
                ORDER BY total DESC
                LIMIT 10
            ");
            $stats['top_categories'] = $stmtTop->fetchAll();

            response(true, ['stats' => $stats]);
        }

        // Detail
        if ($disputeId) {
            $stmt = $db->prepare("
                SELECT d.*,
                    c.nome as customer_name, c.email as customer_email, c.celular as customer_phone,
                    p.nome as partner_name,
                    o.total as order_total_real, o.status as order_status, o.date_added as order_date
                FROM om_order_disputes d
                LEFT JOIN om_market_customers c ON c.customer_id = d.customer_id
                LEFT JOIN om_market_partners p ON p.partner_id = d.partner_id
                LEFT JOIN om_market_orders o ON o.order_id = d.order_id
                WHERE d.dispute_id = ?
            ");
            $stmt->execute([$disputeId]);
            $dispute = $stmt->fetch();
            if (!$dispute) response(false, null, "Disputa nao encontrada", 404);

            // Timeline
            $stmtTl = $db->prepare("
                SELECT id, dispute_id, action, actor_type, actor_id, description, created_at
                FROM om_dispute_timeline
                WHERE dispute_id = ? ORDER BY created_at ASC
            ");
            $stmtTl->execute([$disputeId]);
            $timeline = $stmtTl->fetchAll();

            // Evidence
            $stmtEv = $db->prepare("
                SELECT id, dispute_id, type, file_url, description, created_at
                FROM om_dispute_evidence
                WHERE dispute_id = ? ORDER BY created_at ASC
            ");
            $stmtEv->execute([$disputeId]);
            $evidence = $stmtEv->fetchAll();

            response(true, [
                'dispute' => $dispute,
                'timeline' => $timeline,
                'evidence' => $evidence
            ]);
        }

        // List
        $status = $_GET['status'] ?? null;
        $severity = $_GET['severity'] ?? null;
        $category = $_GET['category'] ?? null;
        $period = $_GET['period'] ?? '30d';
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = "1=1";
        $params = [];

        if ($status) {
            if ($status === 'open') {
                $where .= " AND d.status IN ('open','awaiting_evidence')";
            } else {
                $where .= " AND d.status = ?";
                $params[] = $status;
            }
        }
        if ($severity) {
            $where .= " AND d.severity = ?";
            $params[] = $severity;
        }
        if ($category) {
            $where .= " AND d.category = ?";
            $params[] = $category;
        }

        $periodMap = ['7d' => '7 days', '30d' => '30 days', '90d' => '90 days', 'all' => null];
        $intervalStr = $periodMap[$period] ?? '30 days';
        if ($intervalStr) {
            $where .= " AND d.created_at > NOW() - INTERVAL '$intervalStr'";
        }

        if ($search) {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $where .= " AND (CAST(d.dispute_id AS TEXT) ILIKE ? OR CAST(d.order_id AS TEXT) ILIKE ? OR d.description ILIKE ?)";
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
        }

        // Count
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_order_disputes d WHERE $where");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // List
        $stmt = $db->prepare("
            SELECT d.*,
                c.nome as customer_name, c.email as customer_email,
                p.nome as partner_name
            FROM om_order_disputes d
            LEFT JOIN om_market_customers c ON c.customer_id = d.customer_id
            LEFT JOIN om_market_partners p ON p.partner_id = d.partner_id
            WHERE $where
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
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        $stmt->execute($params);
        $disputes = $stmt->fetchAll();

        response(true, [
            'disputes' => $disputes,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $total > 0 ? ceil($total / $limit) : 0
            ]
        ]);
    }

    // POST — resolve, escalate, assign
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';
        $disputeId = (int)($input['dispute_id'] ?? 0);

        if (!$disputeId) response(false, null, "dispute_id obrigatorio", 400);

        // Verify dispute exists
        $stmt = $db->prepare("SELECT dispute_id, order_id, customer_id, partner_id, status, severity, category, subcategory, requested_amount, approved_amount, credit_amount, compensation_type FROM om_order_disputes WHERE dispute_id = ?");
        $stmt->execute([$disputeId]);
        $dispute = $stmt->fetch();
        if (!$dispute) response(false, null, "Disputa nao encontrada", 404);

        $db->beginTransaction();

        if ($action === 'resolve') {
            $approvedAmount = (float)($input['approved_amount'] ?? 0);
            $creditAmount = (float)($input['credit_amount'] ?? 0);
            $compensationType = trim($input['compensation_type'] ?? 'refund');
            $resolutionNote = trim($input['resolution_note'] ?? '');

            if (empty($resolutionNote)) {
                $db->rollBack();
                response(false, null, "Nota de resolucao obrigatoria", 400);
            }

            $db->prepare("
                UPDATE om_order_disputes
                SET status = 'resolved', approved_amount = ?, credit_amount = ?,
                    compensation_type = ?, resolution_type = 'manual',
                    resolution_note = ?, resolved_at = NOW(), updated_at = NOW()
                WHERE dispute_id = ?
            ")->execute([$approvedAmount, $creditAmount, $compensationType, $resolutionNote, $disputeId]);

            // Timeline
            $desc = "Resolvido por $adminName. ";
            if ($approvedAmount > 0) $desc .= "Reembolso: R$ " . number_format($approvedAmount, 2, ',', '.') . ". ";
            if ($creditAmount > 0) $desc .= "Credito: R$ " . number_format($creditAmount, 2, ',', '.') . ". ";
            $desc .= $resolutionNote;

            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                VALUES (?, 'resolved', 'admin', ?, ?, NOW())
            ")->execute([$disputeId, $adminId, $desc]);

            // Create refund if amount > 0
            if ($approvedAmount > 0) {
                try {
                    $db->prepare("
                        INSERT INTO om_market_refunds (order_id, amount, reason, status, processed_by, processed_at, created_at)
                        VALUES (?, ?, ?, 'approved', ?, NOW(), NOW())
                    ")->execute([
                        $dispute['order_id'], $approvedAmount,
                        "Disputa #{$disputeId}: " . $resolutionNote,
                        $adminName
                    ]);
                } catch (Exception $e) {
                    error_log("[admin/disputes] Refund insert failed: " . $e->getMessage());
                }
            }

            // Add credit to wallet
            if ($creditAmount > 0) {
                try {
                    guard_wallet_credit($db, (int)$dispute['customer_id'], $creditAmount, (int)$dispute['order_id'], "Credito disputa #{$disputeId}", "dispute:{$disputeId}");
                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("[admin/disputes] Wallet credit failed: " . $e->getMessage());
                    response(false, null, "Erro ao creditar carteira do cliente", 500);
                }
            }

            // Notify customer
            try {
                $db->prepare("
                    INSERT INTO om_market_notifications (customer_id, titulo, mensagem, tipo, referencia_tipo, referencia_id, created_at)
                    VALUES (?, ?, ?, 'dispute', 'dispute', ?, NOW())
                ")->execute([
                    $dispute['customer_id'],
                    "Disputa #{$disputeId} resolvida",
                    $resolutionNote,
                    $disputeId
                ]);
            } catch (Exception $e) {
                error_log("[admin/disputes] Notification failed: " . $e->getMessage());
            }

            $db->commit();
            response(true, ['message' => 'Disputa resolvida!']);
        }

        if ($action === 'escalate') {
            $reason = trim($input['reason'] ?? 'Escalado para nivel superior');

            $db->prepare("
                UPDATE om_order_disputes SET status = 'escalated', updated_at = NOW()
                WHERE dispute_id = ?
            ")->execute([$disputeId]);

            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                VALUES (?, 'escalated', 'admin', ?, ?, NOW())
            ")->execute([$disputeId, $adminId, "Escalado por $adminName: $reason"]);

            $db->commit();
            response(true, ['message' => 'Disputa escalada']);
        }

        if ($action === 'assign') {
            $assignTo = trim($input['assign_to'] ?? '');
            if (empty($assignTo)) {
                $db->rollBack();
                response(false, null, "assign_to obrigatorio", 400);
            }

            $db->prepare("
                UPDATE om_order_disputes SET status = 'in_review', updated_at = NOW()
                WHERE dispute_id = ?
            ")->execute([$disputeId]);

            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                VALUES (?, 'assigned', 'admin', ?, ?, NOW())
            ")->execute([$disputeId, $adminId, "Atribuido a $assignTo por $adminName"]);

            $db->commit();
            response(true, ['message' => "Disputa atribuida a $assignTo"]);
        }

        if ($action === 'add_note') {
            $note = trim($input['note'] ?? '');
            if (empty($note)) {
                $db->rollBack();
                response(false, null, "Nota obrigatoria", 400);
            }

            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                VALUES (?, 'note', 'admin', ?, ?, NOW())
            ")->execute([$disputeId, $adminId, "$adminName: $note"]);

            $db->commit();
            response(true, ['message' => 'Nota adicionada']);
        }

        $db->rollBack();
        response(false, null, "Acao invalida", 400);
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/disputes] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar disputas", 500);
}
