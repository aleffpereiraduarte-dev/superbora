<?php
/**
 * GET /api/mercado/partner/team.php - Listar membros da equipe
 * POST /api/mercado/partner/team.php - Criar/atualizar membro
 * DELETE /api/mercado/partner/team.php?id=X - Desativar membro (soft delete)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    // Table om_partner_team must exist via migration
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Listar membros (excluindo password_hash por seguranca)
        $stmt = $db->prepare("
            SELECT id, name, email, role, status, last_login, created_at
            FROM om_partner_team
            WHERE partner_id = ?
            ORDER BY array_position(ARRAY['admin','gerente','atendente'], role), name ASC
        ");
        $stmt->execute([$partnerId]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(true, ['members' => $members]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $memberId = intval($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $role = $input['role'] ?? 'atendente';
        $password = $input['password'] ?? '';
        $status = isset($input['status']) ? intval($input['status']) : 1;

        if (empty($name) || empty($email)) {
            response(false, null, "Nome e email sao obrigatorios", 400);
        }

        if (strlen($name) > 100) {
            response(false, null, "Nome deve ter no maximo 100 caracteres", 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            response(false, null, "Email invalido", 400);
        }

        if (!in_array($role, ['admin', 'gerente', 'atendente'])) {
            response(false, null, "Funcao invalida", 400);
        }

        if ($memberId > 0) {
            // Update
            $sets = ["name = ?", "email = ?", "role = ?", "status = ?"];
            $params = [$name, $email, $role, $status];

            if (!empty($password)) {
                if (strlen($password) < 8 || !preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
                    response(false, null, "Senha deve ter no minimo 8 caracteres, com pelo menos uma letra e um numero", 400);
                }
                $sets[] = "password_hash = ?";
                $params[] = password_hash($password, PASSWORD_ARGON2ID);
            }

            $params[] = $memberId;
            $params[] = $partnerId;

            $stmt = $db->prepare("UPDATE om_partner_team SET " . implode(', ', $sets) . " WHERE id = ? AND partner_id = ?");
            $stmt->execute($params);
        } else {
            // Check duplicate email
            $stmt = $db->prepare("SELECT id FROM om_partner_team WHERE partner_id = ? AND email = ?");
            $stmt->execute([$partnerId, $email]);
            if ($stmt->fetch()) {
                response(false, null, "Ja existe um membro com este email", 400);
            }

            $passwordHash = null;
            if (!empty($password)) {
                if (strlen($password) < 8 || !preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
                    response(false, null, "Senha deve ter no minimo 8 caracteres, com pelo menos uma letra e um numero", 400);
                }
                $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
            }

            $stmt = $db->prepare("INSERT INTO om_partner_team (partner_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?) RETURNING id");
            $stmt->execute([$partnerId, $name, $email, $passwordHash, $role]);
            $row = $stmt->fetch();
            $memberId = (int)$row['id'];
        }

        response(true, ['id' => $memberId], "Membro salvo!");
    }

    if ($method === 'DELETE') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) response(false, null, "ID obrigatorio", 400);

        $stmt = $db->prepare("UPDATE om_partner_team SET status = '0' WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partnerId]);

        response(true, null, "Membro desativado!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/team] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
