<?php
/**
 * /api/mercado/corporate/departments.php  -  CRUD for corporate departments.
 * GET    -> List all departments for the admin's company
 * POST   -> Create a new department  { name, monthly_budget }
 * PUT    -> Update a department      { id, name, monthly_budget }
 * DELETE -> Remove a department      { id }
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

    // Verify admin role
    $stmt = $db->prepare("SELECT account_id, role FROM corporate_employees WHERE user_id = :uid AND active = true");
    $stmt->execute([':uid' => $customerId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp || !in_array($emp['role'], ['admin', 'manager'], true)) {
        response(false, null, 'Acesso restrito a administradores', 403);
    }
    $accountId = (int)$emp['account_id'];

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $stmt = $db->prepare(
            "SELECT id, name, monthly_budget, spent_this_month, created_at
             FROM corporate_departments WHERE account_id = :aid ORDER BY name"
        );
        $stmt->execute([':aid' => $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['monthly_budget'] = (float)$r['monthly_budget'];
            $r['spent_this_month'] = (float)$r['spent_this_month'];
        }
        response(true, ['departments' => $rows]);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($method === 'POST') {
        $name = trim($input['name'] ?? '');
        $budget = (float)($input['monthly_budget'] ?? 0);
        if ($name === '') response(false, null, 'Nome obrigatorio', 400);

        $stmt = $db->prepare(
            "INSERT INTO corporate_departments (account_id, name, monthly_budget)
             VALUES (:aid, :name, :budget) RETURNING id"
        );
        $stmt->execute([':aid' => $accountId, ':name' => $name, ':budget' => $budget]);
        response(true, ['id' => (int)$stmt->fetchColumn()]);
    }

    if ($method === 'PUT') {
        $id = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $budget = (float)($input['monthly_budget'] ?? 0);
        if (!$id) response(false, null, 'ID obrigatorio', 400);

        $stmt = $db->prepare(
            "UPDATE corporate_departments SET name = :name, monthly_budget = :budget
             WHERE id = :id AND account_id = :aid"
        );
        $stmt->execute([':name' => $name, ':budget' => $budget, ':id' => $id, ':aid' => $accountId]);
        response(true, ['updated' => true]);
    }

    if ($method === 'DELETE') {
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) response(false, null, 'ID obrigatorio', 400);

        $stmt = $db->prepare("DELETE FROM corporate_departments WHERE id = :id AND account_id = :aid");
        $stmt->execute([':id' => $id, ':aid' => $accountId]);
        response(true, ['deleted' => true]);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[corporate/departments] ' . $e->getMessage());
    response(false, null, 'Erro', 500);
}
