<?php
/**
 * GET/POST/DELETE /api/mercado/corporate/employees.php
 * Gestao de funcionarios da conta corporativa
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Autenticacao pode ser admin da empresa ou admin da plataforma
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload) {
        response(false, null, "Nao autorizado", 401);
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $corporateId = (int)($_GET['corporate_id'] ?? $_POST['corporate_id'] ?? 0);

    if (!$corporateId) {
        response(false, null, "ID da empresa obrigatorio", 400);
    }

    // Verificar se tem acesso a essa empresa
    $userType = $payload['type'] ?? null;
    $userId = $payload['uid'] ?? 0;

    // Apenas admin da plataforma ou admin da empresa podem acessar
    if ($userType === 'admin') {
        // Admin da plataforma tem acesso total
    } elseif ($userType === 'corporate_admin') {
        // Verificar se o admin pertence a essa empresa
        $stmt = $db->prepare("SELECT id FROM om_corporate_accounts WHERE id = ? AND admin_user_id = ?");
        $stmt->execute([$corporateId, $userId]);
        if (!$stmt->fetch()) {
            response(false, null, "Sem permissao para essa empresa", 403);
        }
    } else {
        response(false, null, "Acesso negado. Requer admin ou admin corporativo", 403);
    }

    // GET - Listar funcionarios
    if ($method === 'GET') {
        $status = $_GET['status'] ?? null;

        $where = "corporate_id = ?";
        $params = [$corporateId];

        if ($status) {
            $where .= " AND status = ?";
            $params[] = $status;
        }

        $stmt = $db->prepare("
            SELECT id, name, email, phone, department, employee_id,
                   daily_limit, monthly_limit, current_month_spent,
                   status, joined_at, created_at
            FROM om_corporate_employees
            WHERE $where
            ORDER BY name ASC
        ");
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        // Estatisticas
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(current_month_spent) as total_spent
            FROM om_corporate_employees
            WHERE corporate_id = ?
        ");
        $stmt->execute([$corporateId]);
        $stats = $stmt->fetch();

        response(true, [
            'employees' => array_map(function($e) {
                return [
                    'id' => (int)$e['id'],
                    'name' => $e['name'],
                    'email' => $e['email'],
                    'phone' => $e['phone'],
                    'department' => $e['department'],
                    'employee_id' => $e['employee_id'],
                    'limits' => [
                        'daily' => $e['daily_limit'] ? (float)$e['daily_limit'] : null,
                        'monthly' => $e['monthly_limit'] ? (float)$e['monthly_limit'] : null,
                    ],
                    'current_month_spent' => (float)$e['current_month_spent'],
                    'status' => $e['status'],
                    'joined_at' => $e['joined_at'],
                ];
            }, $employees),
            'stats' => [
                'total' => (int)$stats['total'],
                'active' => (int)$stats['active'],
                'total_spent' => (float)$stats['total_spent'],
            ]
        ]);
    }

    // POST - Adicionar/atualizar funcionario
    if ($method === 'POST') {
        $input = getInput();

        $employeeDbId = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $input['phone'] ?? '');
        $department = trim($input['department'] ?? '');
        $employeeId = trim($input['employee_id'] ?? '');
        $dailyLimit = isset($input['daily_limit']) ? (float)$input['daily_limit'] : null;
        $monthlyLimit = isset($input['monthly_limit']) ? (float)$input['monthly_limit'] : null;

        if (empty($name) || empty($email)) {
            response(false, null, "Nome e email obrigatorios", 400);
        }

        // Validacao de tamanho do nome (min 3, max 100 caracteres)
        $nameLen = mb_strlen($name, 'UTF-8');
        if ($nameLen < 3 || $nameLen > 100) {
            response(false, null, "Nome deve ter entre 3 e 100 caracteres", 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            response(false, null, "Email invalido", 400);
        }

        // Validacao de telefone (formato brasileiro: 10-11 digitos)
        if (!empty($phone)) {
            $phoneLen = strlen($phone);
            if ($phoneLen < 10 || $phoneLen > 11) {
                response(false, null, "Telefone deve ter 10 ou 11 digitos (formato brasileiro)", 400);
            }
        }

        if ($employeeDbId) {
            // Atualizar
            $stmt = $db->prepare("
                UPDATE om_corporate_employees SET
                    name = ?, email = ?, phone = ?, department = ?,
                    employee_id = ?, daily_limit = ?, monthly_limit = ?,
                    updated_at = NOW()
                WHERE id = ? AND corporate_id = ?
            ");
            $stmt->execute([
                $name, $email, $phone, $department,
                $employeeId, $dailyLimit, $monthlyLimit,
                $employeeDbId, $corporateId
            ]);

            response(true, ['message' => 'Funcionario atualizado!']);
        }

        // Transação para evitar race condition no check-then-insert do email
        $db->beginTransaction();
        try {
            // Verificar se email ja existe nessa empresa (com lock)
            $stmt = $db->prepare("
                SELECT id FROM om_corporate_employees
                WHERE corporate_id = ? AND email = ?
                FOR UPDATE
            ");
            $stmt->execute([$corporateId, $email]);
            if ($stmt->fetch()) {
                $db->rollBack();
                response(false, null, "Email ja cadastrado nessa empresa", 400);
            }

            // Criar convite
            $inviteToken = bin2hex(random_bytes(32));

            $stmt = $db->prepare("
                INSERT INTO om_corporate_employees
                (corporate_id, name, email, phone, department, employee_id,
                 daily_limit, monthly_limit, invite_token, invite_sent_at, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')
            ");
            $stmt->execute([
                $corporateId, $name, $email, $phone, $department,
                $employeeId, $dailyLimit, $monthlyLimit, $inviteToken
            ]);

            $newEmployeeId = (int)$db->lastInsertId();
            $db->commit();
        } catch (Exception $txEx) {
            $db->rollBack();
            throw $txEx;
        }

        // TODO: Enviar email de convite

        response(true, [
            'employee_id' => $newEmployeeId,
            'message' => 'Convite enviado para ' . $email
        ]);
    }

    // DELETE - Remover funcionario
    if ($method === 'DELETE') {
        $employeeDbId = (int)($_GET['id'] ?? 0);

        if (!$employeeDbId) {
            response(false, null, "ID do funcionario obrigatorio", 400);
        }

        $stmt = $db->prepare("
            UPDATE om_corporate_employees
            SET status = 'suspended'
            WHERE id = ? AND corporate_id = ?
        ");
        $stmt->execute([$employeeDbId, $corporateId]);

        response(true, ['message' => 'Funcionario removido']);
    }

} catch (Exception $e) {
    error_log("[corporate/employees] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar funcionarios", 500);
}
