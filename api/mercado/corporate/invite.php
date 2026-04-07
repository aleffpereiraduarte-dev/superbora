<?php
/**
 * POST /api/mercado/corporate/invite.php
 * Generate an invite link for a new corporate employee.
 * Body: { email, phone, department_id, monthly_limit, daily_allowance }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    if (!$customerId) response(false, null, 'Nao autorizado', 401);

    $stmt = $db->prepare("SELECT account_id, role FROM corporate_employees WHERE user_id = :uid AND active = true");
    $stmt->execute([':uid' => $customerId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp || !in_array($emp['role'], ['admin', 'manager'], true)) {
        response(false, null, 'Acesso restrito', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = trim($input['email'] ?? '');
    $phone = preg_replace('/[^0-9]/', '', $input['phone'] ?? '');
    $deptId = isset($input['department_id']) ? (int)$input['department_id'] : null;
    $monthlyLimit = (float)($input['monthly_limit'] ?? 500);
    $dailyAllowance = (float)($input['daily_allowance'] ?? 30);

    if (!$email && !$phone) {
        response(false, null, 'Informe email ou telefone', 400);
    }

    $invToken = bin2hex(random_bytes(24));

    $stmt = $db->prepare(
        "INSERT INTO corporate_invites
         (account_id, department_id, email, phone, invite_token, monthly_limit, daily_allowance)
         VALUES (:aid, :did, :em, :ph, :tok, :ml, :da)
         RETURNING id, expires_at"
    );
    $stmt->execute([
        ':aid' => (int)$emp['account_id'],
        ':did' => $deptId,
        ':em' => $email ?: null,
        ':ph' => $phone ?: null,
        ':tok' => $invToken,
        ':ml' => $monthlyLimit,
        ':da' => $dailyAllowance,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    response(true, [
        'invite_id' => (int)$row['id'],
        'invite_url' => 'https://superbora.com.br/corporativo/aceitar/' . $invToken,
        'expires_at' => $row['expires_at'],
    ]);
} catch (Exception $e) {
    error_log('[corporate/invite] ' . $e->getMessage());
    response(false, null, 'Erro ao criar convite', 500);
}
