<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        // Recent logins
        $stmt = $db->query("
            SELECT action, entity_type, entity_id, ip_address, created_at, description
            FROM om_audit_log
            WHERE action = 'login'
            ORDER BY created_at DESC LIMIT 20
        ");
        $recent_logins = $stmt->fetchAll();

        // Admin users
        $stmt = $db->query("
            SELECT admin_id, name, email, role, status, is_rh
            FROM om_admins
            ORDER BY name ASC
        ");
        $admins = $stmt->fetchAll();

        // Active tokens count
        $stmt = $db->query("SELECT COUNT(*) as total FROM om_auth_tokens WHERE revoked = 0 AND expires_at > NOW()");
        $active_tokens = (int)$stmt->fetch()['total'];

        response(true, [
            'recent_logins' => $recent_logins,
            'admins' => $admins,
            'active_tokens' => $active_tokens
        ], "Dados de seguranca");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $action = $input['action'] ?? '';
        $target_id = (int)($input['target_id'] ?? 0);
        $value = $input['value'] ?? '';

        if (!$action) response(false, null, "action obrigatoria", 400);

        switch ($action) {
            case 'revoke_token':
                if (!$target_id) response(false, null, "target_id obrigatorio", 400);
                $user_type = $input['user_type'] ?? '';
                $valid_types = ['customer', 'partner', 'shopper', 'admin'];
                if (!in_array($user_type, $valid_types)) {
                    response(false, null, "user_type obrigatorio (customer, partner, shopper, admin)", 400);
                }
                // Only rh can revoke admin tokens
                if ($user_type === 'admin' && ($payload['type'] ?? '') !== 'rh') {
                    response(false, null, "Apenas RH pode revogar tokens de admin", 403);
                }
                $stmt = $db->prepare("
                    UPDATE om_auth_tokens SET revoked = 1, revoked_at = NOW()
                    WHERE user_id = ? AND user_type = ? AND revoked = 0
                ");
                $stmt->execute([$target_id, $user_type]);
                om_audit()->log('update', 'security', $target_id, null, ['action' => 'revoke_tokens', 'user_type' => $user_type]);
                response(true, null, "Tokens revogados");
                break;

            case 'change_role':
                if (!$target_id || !$value) response(false, null, "target_id e value obrigatorios", 400);
                $valid_roles = ['admin', 'manager', 'support'];
                if (!in_array($value, $valid_roles)) response(false, null, "Role invalida", 400);

                // SECURITY: Only rh can change roles — prevent privilege escalation
                if ($payload['type'] !== 'rh') {
                    response(false, null, "Apenas RH pode alterar roles", 403);
                }
                if ((int)$target_id === (int)$admin_id) {
                    response(false, null, "Voce nao pode alterar sua propria role", 403);
                }

                $stmt = $db->prepare("SELECT role FROM om_admins WHERE admin_id = ?");
                $stmt->execute([$target_id]);
                $old = $stmt->fetch();

                $stmt = $db->prepare("UPDATE om_admins SET role = ? WHERE admin_id = ?");
                $stmt->execute([$value, $target_id]);

                om_audit()->log('update', 'admin', $target_id, ['role' => $old['role'] ?? ''], ['role' => $value]);
                response(true, null, "Role atualizada");
                break;

            case 'block_ip':
                if (!$value) response(false, null, "IP (value) obrigatorio", 400);
                // Validate IP format
                if (!filter_var($value, FILTER_VALIDATE_IP)) {
                    response(false, null, "IP invalido", 400);
                }
                // Table om_blocked_ips created via migration
                $stmtBlock = $db->prepare("
                    INSERT INTO om_blocked_ips (ip_address, blocked_by, reason, created_at)
                    VALUES (?, ?, ?, NOW())
                    ON CONFLICT (ip_address) DO NOTHING
                ");
                $stmtBlock->execute([$value, $admin_id, $input['reason'] ?? 'Blocked by admin']);
                om_audit()->log('create', 'security', null, null, ['action' => 'block_ip', 'ip' => $value]);
                response(true, null, "IP bloqueado");
                break;

            default:
                response(false, null, "Action invalida. Use: revoke_token, change_role, block_ip", 400);
        }
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/seguranca] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
