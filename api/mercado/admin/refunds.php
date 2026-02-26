<?php
/**
 * Admin Refund Management API
 *
 * GET  /api/mercado/admin/refunds.php - List refunds (filters: status, date_from, date_to, page)
 * PUT  /api/mercado/admin/refunds.php - Approve or reject refund
 *      Body: { "id": X, "status": "approved"|"rejected", "note": "..." }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = $payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        // =================== GET: List refunds ===================
        $status = trim($_GET['status'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = ["1=1"];
        $params = [];

        $validStatuses = ['pending', 'approved', 'rejected', 'processed'];
        if ($status && in_array($status, $validStatuses)) {
            $where[] = "r.status = ?";
            $params[] = $status;
        }

        if ($dateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $where[] = "DATE(r.created_at) >= ?";
            $params[] = $dateFrom;
        }

        if ($dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $where[] = "DATE(r.created_at) <= ?";
            $params[] = $dateTo;
        }

        $whereSQL = implode(" AND ", $where);

        // Count
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_refunds r WHERE {$whereSQL}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // Fetch — use actual om_market_refunds schema (refund_id, order_id, amount, reason, status, etc.)
        $stmt = $db->prepare("
            SELECT r.refund_id as id, r.order_id, r.amount, r.reason, r.reason_details,
                   r.status, r.refund_method, r.processed_by, r.processed_at, r.created_at,
                   o.order_number, o.total as order_total, o.partner_name,
                   o.customer_name, o.customer_phone, o.delivered_at,
                   p.trade_name as partner_trade_name
            FROM om_market_refunds r
            LEFT JOIN om_market_orders o ON r.order_id = o.order_id
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE {$whereSQL}
            ORDER BY
                CASE r.status WHEN 'pending' THEN 0 ELSE 1 END,
                r.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        $stmt->execute($params);
        $refunds = $stmt->fetchAll();

        $result = [];
        foreach ($refunds as $r) {
            $result[] = [
                'id' => (int)$r['id'],
                'order_id' => (int)$r['order_id'],
                'order_number' => $r['order_number'],
                'customer_name' => $r['customer_name'],
                'customer_phone' => $r['customer_phone'],
                'partner_name' => $r['partner_trade_name'] ?: $r['partner_name'],
                'amount' => (float)$r['amount'],
                'order_total' => (float)($r['order_total'] ?? 0),
                'reason' => $r['reason'],
                'reason_details' => $r['reason_details'],
                'status' => $r['status'],
                'refund_method' => $r['refund_method'],
                'processed_by' => $r['processed_by'] ? (int)$r['processed_by'] : null,
                'processed_at' => $r['processed_at'],
                'delivered_at' => $r['delivered_at'],
                'created_at' => $r['created_at'],
            ];
        }

        // Stats
        $stmtStats = $db->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed_count,
                COALESCE(SUM(CASE WHEN status IN ('approved', 'processed') THEN amount ELSE 0 END), 0) as total_approved_amount
            FROM om_market_refunds
        ");
        $stats = $stmtStats->fetch();

        response(true, [
            'refunds' => $result,
            'stats' => [
                'total' => (int)$stats['total'],
                'pending' => (int)$stats['pending_count'],
                'approved' => (int)$stats['approved_count'],
                'rejected' => (int)$stats['rejected_count'],
                'processed' => (int)$stats['processed_count'],
                'total_approved_amount' => (float)$stats['total_approved_amount'],
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit) ?: 1,
            ]
        ]);

    } elseif ($_SERVER["REQUEST_METHOD"] === "PUT") {
        // =================== PUT: Approve or reject ===================
        $input = getInput();
        $refundId = (int)($input['id'] ?? 0);
        $newStatus = trim($input['status'] ?? '');
        $note = trim($input['note'] ?? '');

        if (!$refundId) response(false, null, "ID do reembolso obrigatorio", 400);
        if (!in_array($newStatus, ['approved', 'rejected'])) {
            response(false, null, "Status deve ser 'approved' ou 'rejected'", 400);
        }

        $db->beginTransaction();

        // Lock refund row with FOR UPDATE to prevent TOCTOU race between concurrent admins
        $stmt = $db->prepare("SELECT * FROM om_market_refunds WHERE refund_id = ? FOR UPDATE");
        $stmt->execute([$refundId]);
        $refund = $stmt->fetch();

        if (!$refund) {
            $db->rollBack();
            response(false, null, "Reembolso nao encontrado", 404);
        }

        if ($refund['status'] !== 'pending') {
            $db->rollBack();
            response(false, null, "Este reembolso ja foi processado (status: {$refund['status']})", 400);
        }

        // Update refund status with status guard (belt-and-suspenders)
        $stmt = $db->prepare("
            UPDATE om_market_refunds
            SET status = ?, reason_details = COALESCE(reason_details, '') || ? , processed_by = ?, processed_at = NOW()
            WHERE refund_id = ? AND status = 'pending'
        ");
        $noteAppend = $note ? ("\n[Admin] " . $note) : '';
        $stmt->execute([$newStatus, $noteAppend, $adminId, $refundId]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Reembolso ja foi processado por outro administrador", 409);
        }

        // If approved, add timeline entry to the order
        $orderId = (int)$refund['order_id'];
        if ($newStatus === 'approved') {
            $desc = "Reembolso de R$ " . number_format((float)$refund['amount'], 2, ',', '.') . " aprovado";
            if ($note) $desc .= " - {$note}";

            try {
                $db->prepare("
                    INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                    VALUES (?, 'refund_approved', ?, 'admin', ?, NOW())
                ")->execute([$orderId, $desc, $adminId]);
            } catch (Exception $e) {
                // Timeline table may not exist
            }
        } else {
            $desc = "Reembolso recusado";
            if ($note) $desc .= " - {$note}";

            try {
                $db->prepare("
                    INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                    VALUES (?, 'refund_rejected', ?, 'admin', ?, NOW())
                ")->execute([$orderId, $desc, $adminId]);
            } catch (Exception $e) {
                // Timeline table may not exist
            }
        }

        $db->commit();

        $action = $newStatus === 'approved' ? 'approve' : 'reject';
        om_audit()->log($action, 'refund', $refundId, ['status' => 'pending'], ['status' => $newStatus, 'note' => $note], $desc);

        $msg = $newStatus === 'approved' ? 'Reembolso aprovado' : 'Reembolso recusado';
        response(true, [
            'id' => $refundId,
            'status' => $newStatus,
            'order_id' => $orderId,
        ], $msg);

    } else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/refunds] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
