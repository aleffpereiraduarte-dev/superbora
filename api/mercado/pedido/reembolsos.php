<?php
/**
 * GET /api/mercado/pedido/reembolsos.php
 * Lista historico de reembolsos do cliente autenticado.
 *
 * Query params:
 *   ?page=1&limit=20  — paginacao
 *   ?status=pending    — filtro por status (pending, approved, rejected, processed)
 *
 * Retorna: [{refund_id, order_id, order_number, amount, status, reason, created_at, reviewed_at}]
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $customer_id = requireCustomerAuth();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $statusFilter = trim($_GET['status'] ?? '');

    // Build WHERE clause
    $where = ["r.customer_id = ?"];
    $params = [$customer_id];

    $validStatuses = ['pending', 'approved', 'rejected', 'processed', 'failed'];
    if ($statusFilter && in_array($statusFilter, $validStatuses, true)) {
        $where[] = "r.status = ?";
        $params[] = $statusFilter;
    }

    $whereSQL = implode(" AND ", $where);

    // Count total
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_refunds r WHERE {$whereSQL}");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    // Fetch refunds with order info
    $stmtFetch = $db->prepare("
        SELECT r.refund_id, r.order_id, r.amount, r.reason, r.items_json,
               r.status, r.admin_note, r.created_at, r.reviewed_at,
               o.order_number, o.total as order_total, o.partner_name, o.delivered_at,
               p.trade_name as partner_trade_name
        FROM om_market_refunds r
        LEFT JOIN om_market_orders o ON r.order_id = o.order_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE {$whereSQL}
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmtFetch->execute($params);
    $refunds = $stmtFetch->fetchAll();

    $result = [];
    foreach ($refunds as $r) {
        $statusLabels = [
            'pending' => 'Em analise',
            'approved' => 'Aprovado',
            'rejected' => 'Recusado',
            'processed' => 'Processado',
            'failed' => 'Falhou',
        ];

        $result[] = [
            'refund_id' => (int)$r['refund_id'],
            'order_id' => (int)$r['order_id'],
            'order_number' => $r['order_number'] ?? '',
            'partner_name' => $r['partner_trade_name'] ?: ($r['partner_name'] ?? ''),
            'amount' => (float)$r['amount'],
            'amount_formatted' => 'R$ ' . number_format((float)$r['amount'], 2, ',', '.'),
            'order_total' => (float)($r['order_total'] ?? 0),
            'reason' => $r['reason'],
            'items' => json_decode($r['items_json'] ?? '[]', true) ?: [],
            'status' => $r['status'],
            'status_label' => $statusLabels[$r['status']] ?? $r['status'],
            'admin_note' => $r['admin_note'],
            'delivered_at' => $r['delivered_at'],
            'created_at' => $r['created_at'],
            'reviewed_at' => $r['reviewed_at'],
        ];
    }

    // Summary stats for the customer
    $stmtStats = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status IN ('approved', 'processed') THEN 1 ELSE 0 END) as approved_count,
            COALESCE(SUM(CASE WHEN status IN ('approved', 'processed') THEN amount ELSE 0 END), 0) as total_refunded
        FROM om_market_refunds
        WHERE customer_id = ?
    ");
    $stmtStats->execute([$customer_id]);
    $stats = $stmtStats->fetch();

    response(true, [
        'refunds' => $result,
        'stats' => [
            'total' => (int)($stats['total'] ?? 0),
            'pending' => (int)($stats['pending_count'] ?? 0),
            'approved' => (int)($stats['approved_count'] ?? 0),
            'total_refunded' => (float)($stats['total_refunded'] ?? 0),
            'total_refunded_formatted' => 'R$ ' . number_format((float)($stats['total_refunded'] ?? 0), 2, ',', '.'),
        ],
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int)ceil($total / $limit) ?: 1,
        ]
    ]);

} catch (Exception $e) {
    error_log("[pedido/reembolsos] Erro: " . $e->getMessage());
    response(false, null, "Erro ao listar reembolsos", 500);
}
