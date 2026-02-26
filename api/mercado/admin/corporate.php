<?php
/**
 * GET/POST /api/mercado/admin/corporate.php
 * Gestao administrativa de contas corporativas
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    $method = $_SERVER['REQUEST_METHOD'];

    // Check if corporate tables exist
    $tableCheck = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'om_corporate_accounts')");
    $tablesExist = $tableCheck->fetchColumn();

    if (!$tablesExist) {
        response(true, [
            'accounts' => [],
            'stats' => ['total' => 0, 'pending' => 0, 'active' => 0, 'suspended' => 0],
            'message' => 'Modulo corporativo ainda nao configurado'
        ]);
    }

    // GET - Listar contas corporativas
    if ($method === 'GET') {
        $status = $_GET['status'] ?? null;
        $corporateId = (int)($_GET['id'] ?? 0);

        // Detalhes de uma conta especifica
        if ($corporateId) {
            $stmt = $db->prepare("SELECT * FROM om_corporate_accounts WHERE id = ?");
            $stmt->execute([$corporateId]);
            $account = $stmt->fetch();

            if (!$account) {
                response(false, null, "Conta nao encontrada", 404);
            }

            // Buscar funcionarios
            $stmt = $db->prepare("
                SELECT id, name, email, department, employee_id, status,
                       daily_limit, monthly_limit, current_month_spent, joined_at
                FROM om_corporate_employees
                WHERE corporate_id = ?
                ORDER BY name ASC
            ");
            $stmt->execute([$corporateId]);
            $employees = $stmt->fetchAll();

            // Buscar pedidos recentes
            $stmt = $db->prepare("
                SELECT co.*, o.status as order_status, e.name as employee_name
                FROM om_corporate_orders co
                INNER JOIN om_market_orders o ON co.order_id = o.order_id
                INNER JOIN om_corporate_employees e ON co.employee_id = e.id
                WHERE co.corporate_id = ?
                ORDER BY co.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$corporateId]);
            $recentOrders = $stmt->fetchAll();

            // Buscar faturas
            $stmt = $db->prepare("
                SELECT * FROM om_corporate_invoices
                WHERE corporate_id = ?
                ORDER BY reference_month DESC
                LIMIT 12
            ");
            $stmt->execute([$corporateId]);
            $invoices = $stmt->fetchAll();

            response(true, [
                'account' => $account,
                'employees' => $employees,
                'recent_orders' => $recentOrders,
                'invoices' => $invoices
            ]);
        }

        // Listar todas as contas
        $where = "1=1";
        $params = [];

        if ($status) {
            $where .= " AND status = ?";
            $params[] = $status;
        }

        $stmt = $db->prepare("
            SELECT c.*,
                (SELECT COUNT(*) FROM om_corporate_employees WHERE corporate_id = c.id AND status = 'active') as employee_count,
                (SELECT SUM(amount) FROM om_corporate_orders WHERE corporate_id = c.id AND EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM NOW())) as month_spent
            FROM om_corporate_accounts c
            WHERE $where
            ORDER BY c.created_at DESC
        ");
        $stmt->execute($params);
        $accounts = $stmt->fetchAll();

        // Estatisticas
        $stats = [];
        $stmt = $db->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended
            FROM om_corporate_accounts
        ");
        $stats = $stmt->fetch();

        response(true, [
            'accounts' => $accounts,
            'stats' => $stats
        ]);
    }

    // POST - Aprovar, suspender ou atualizar conta
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'update';
        $corporateId = (int)($input['corporate_id'] ?? 0);

        if (!$corporateId) {
            response(false, null, "ID da empresa obrigatorio", 400);
        }

        if ($action === 'approve') {
            $stmt = $db->prepare("
                UPDATE om_corporate_accounts
                SET status = 'active', approved_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$corporateId]);

            // TODO: Enviar email de aprovacao

            response(true, ['message' => 'Conta corporativa aprovada!']);
        }

        if ($action === 'suspend') {
            $reason = trim($input['reason'] ?? '');

            $stmt = $db->prepare("
                UPDATE om_corporate_accounts
                SET status = 'suspended', notes = CONCAT(COALESCE(notes, ''), '\nSuspenso: ', ?)
                WHERE id = ?
            ");
            $stmt->execute([$reason, $corporateId]);

            response(true, ['message' => 'Conta suspensa']);
        }

        if ($action === 'update_limits') {
            $monthlyLimit = (float)($input['monthly_limit'] ?? 0);
            $perEmployeeLimit = (float)($input['per_employee_limit'] ?? 0);

            $stmt = $db->prepare("
                UPDATE om_corporate_accounts
                SET monthly_limit = ?, per_employee_limit = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$monthlyLimit, $perEmployeeLimit, $corporateId]);

            response(true, ['message' => 'Limites atualizados']);
        }

        if ($action === 'generate_invoice') {
            $referenceMonth = $input['reference_month'] ?? date('Y-m');

            // Calcular total do mes
            $stmt = $db->prepare("
                SELECT SUM(amount) as total
                FROM om_corporate_orders
                WHERE corporate_id = ?
                AND TO_CHAR(created_at, 'YYYY-MM') = ?
            ");
            $stmt->execute([$corporateId, $referenceMonth]);
            $total = $stmt->fetchColumn() ?: 0;

            if ($total <= 0) {
                response(false, null, "Nenhum valor a faturar neste periodo", 400);
            }

            // Criar fatura
            $dueDate = date('Y-m-d', strtotime('+15 days'));

            $stmt = $db->prepare("
                INSERT INTO om_corporate_invoices
                (corporate_id, reference_month, amount, due_date, status)
                VALUES (?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$corporateId, $referenceMonth, $total, $dueDate]);

            response(true, [
                'invoice_id' => (int)$db->lastInsertId(),
                'amount' => (float)$total,
                'due_date' => $dueDate,
                'message' => 'Fatura gerada!'
            ]);
        }

        if ($action === 'mark_invoice_paid') {
            $invoiceId = (int)($input['invoice_id'] ?? 0);

            if (!$invoiceId) {
                response(false, null, "ID da fatura obrigatorio", 400);
            }

            $stmt = $db->prepare("
                UPDATE om_corporate_invoices
                SET status = 'paid', paid_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$invoiceId]);

            response(true, ['message' => 'Fatura marcada como paga']);
        }

        response(false, null, "Acao invalida", 400);
    }

} catch (Exception $e) {
    error_log("[admin/corporate] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar contas corporativas", 500);
}
