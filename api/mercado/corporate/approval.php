<?php
/**
 * /api/mercado/corporate/approval.php
 * GET  -> List orders pending approval for the admin's company
 * POST -> Approve or reject a pending order  { order_id, action: "approve"|"reject" }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $customerId = 0;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && ($payload['type'] ?? '') === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }
    if (!$customerId) response(false, null, 'Nao autorizado', 401);

    $stmt = $db->prepare("SELECT id AS employee_id, account_id, role FROM corporate_employees WHERE user_id = :uid AND active = true");
    $stmt->execute([':uid' => $customerId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp || !in_array($emp['role'], ['admin', 'manager'], true)) {
        response(false, null, 'Acesso restrito', 403);
    }
    $accountId = (int)$emp['account_id'];

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $stmt = $db->prepare(
            "SELECT co.id, co.order_id, co.amount, co.created_at,
                    e.user_id AS employee_user_id, c.name AS employee_name
             FROM corporate_orders co
             JOIN corporate_employees e ON e.id = co.employee_id
             LEFT JOIN om_customers c ON c.customer_id = e.user_id
             WHERE co.account_id = :aid AND co.status = 'pending'
             ORDER BY co.created_at ASC"
        );
        $stmt->execute([':aid' => $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['amount'] = (float)$r['amount'];
        response(true, ['pending' => $rows, 'count' => count($rows)]);
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $orderId = (int)($input['order_id'] ?? 0);
        $action = strtolower(trim($input['action'] ?? ''));
        if (!$orderId || !in_array($action, ['approve', 'reject'], true)) {
            response(false, null, 'order_id e action obrigatorios', 400);
        }

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $db->prepare(
            "UPDATE corporate_orders
             SET status = :st, approved_by = :ab, approved_at = NOW()
             WHERE id = :id AND account_id = :aid AND status = 'pending'"
        );
        $stmt->execute([
            ':st' => $newStatus,
            ':ab' => (int)$emp['employee_id'],
            ':id' => $orderId,
            ':aid' => $accountId,
        ]);
        if ($stmt->rowCount() === 0) {
            response(false, null, 'Pedido nao encontrado ou ja processado', 404);
        }
        response(true, ['status' => $newStatus]);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[corporate/approval] ' . $e->getMessage());
    response(false, null, 'Erro', 500);
}
