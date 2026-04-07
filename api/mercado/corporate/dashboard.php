<?php
/**
 * GET /api/mercado/corporate/dashboard.php
 * Returns the dashboard for the authenticated corporate admin/employee.
 *
 * Returns:
 *   - account info, role
 *   - budget usage (total, this month, percent)
 *   - top departments by spending
 *   - employees count
 *   - pending approvals count
 *   - recent orders
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

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
    if (!$customerId) {
        response(false, null, 'Nao autorizado', 401);
    }

    // Find corporate role for this user
    $stmt = $db->prepare(
        "SELECT e.id AS employee_id, e.account_id, e.role, e.monthly_limit,
                e.spent_this_month, e.daily_allowance, e.department_id,
                a.company_name, a.cnpj, a.monthly_budget,
                a.spent_this_month AS account_spent, a.status
         FROM corporate_employees e
         JOIN corporate_accounts a ON a.id = e.account_id
         WHERE e.user_id = :uid AND e.active = true"
    );
    $stmt->execute([':uid' => $customerId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        response(true, ['is_corporate' => false]);
    }

    $accountId = (int)$emp['account_id'];
    $isAdmin = $emp['role'] === 'admin' || $emp['role'] === 'manager';

    // Department info
    $department = null;
    if ($emp['department_id']) {
        $stmt = $db->prepare("SELECT id, name, monthly_budget, spent_this_month FROM corporate_departments WHERE id = :id");
        $stmt->execute([':id' => $emp['department_id']]);
        $department = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($department) {
            $department['monthly_budget'] = (float)$department['monthly_budget'];
            $department['spent_this_month'] = (float)$department['spent_this_month'];
        }
    }

    $result = [
        'is_corporate' => true,
        'role' => $emp['role'],
        'account' => [
            'id' => $accountId,
            'company_name' => $emp['company_name'],
            'cnpj' => $emp['cnpj'],
            'monthly_budget' => (float)$emp['monthly_budget'],
            'spent_this_month' => (float)$emp['account_spent'],
            'budget_remaining' => max(0, (float)$emp['monthly_budget'] - (float)$emp['account_spent']),
            'budget_used_pct' => $emp['monthly_budget'] > 0
                ? round(($emp['account_spent'] / $emp['monthly_budget']) * 100, 1)
                : 0,
        ],
        'employee' => [
            'id' => (int)$emp['employee_id'],
            'monthly_limit' => (float)$emp['monthly_limit'],
            'spent_this_month' => (float)$emp['spent_this_month'],
            'limit_remaining' => max(0, (float)$emp['monthly_limit'] - (float)$emp['spent_this_month']),
            'daily_allowance' => (float)$emp['daily_allowance'],
        ],
        'department' => $department,
    ];

    if ($isAdmin) {
        // Top departments
        $stmt = $db->prepare(
            "SELECT id, name, monthly_budget, spent_this_month
             FROM corporate_departments
             WHERE account_id = :aid
             ORDER BY spent_this_month DESC LIMIT 5"
        );
        $stmt->execute([':aid' => $accountId]);
        $depts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($depts as &$d) {
            $d['monthly_budget'] = (float)$d['monthly_budget'];
            $d['spent_this_month'] = (float)$d['spent_this_month'];
        }
        $result['top_departments'] = $depts;

        // Employees count
        $stmt = $db->prepare("SELECT COUNT(*) FROM corporate_employees WHERE account_id = :aid AND active = true");
        $stmt->execute([':aid' => $accountId]);
        $result['employees_count'] = (int)$stmt->fetchColumn();

        // Pending approvals
        $stmt = $db->prepare("SELECT COUNT(*) FROM corporate_orders WHERE account_id = :aid AND status = 'pending'");
        $stmt->execute([':aid' => $accountId]);
        $result['pending_approvals'] = (int)$stmt->fetchColumn();

        // Recent orders (last 10)
        $stmt = $db->prepare(
            "SELECT co.id, co.order_id, co.amount, co.status, co.created_at,
                    e.user_id, c.name AS employee_name
             FROM corporate_orders co
             JOIN corporate_employees e ON e.id = co.employee_id
             LEFT JOIN om_customers c ON c.customer_id = e.user_id
             WHERE co.account_id = :aid
             ORDER BY co.created_at DESC LIMIT 10"
        );
        $stmt->execute([':aid' => $accountId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as &$o) {
            $o['amount'] = (float)$o['amount'];
        }
        $result['recent_orders'] = $orders;
    }

    response(true, $result);
} catch (Exception $e) {
    error_log('[corporate/dashboard] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar dashboard', 500);
}
