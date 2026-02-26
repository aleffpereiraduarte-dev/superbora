<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/admin/auth/session.php
 * Verifica sessao do administrador, retorna dados ou null
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Header: Authorization: Bearer {token}
 * Retorna dados do admin autenticado incluindo role e permissoes
 */

require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();

    if (!$token) {
        response(true, ["authenticated" => false, "admin" => null]);
    }

    $payload = om_auth()->validateToken($token);

    // Aceitar tanto 'admin' quanto 'rh' como tipos validos
    if (!$payload || !in_array($payload['type'], [OmAuth::USER_TYPE_ADMIN, OmAuth::USER_TYPE_RH])) {
        response(true, ["authenticated" => false, "admin" => null]);
    }

    // Buscar dados atualizados do admin
    $stmt = $db->prepare("
        SELECT admin_id, name, email, role, status, is_rh, created_at, last_login
        FROM om_admins
        WHERE admin_id = ?
    ");
    $stmt->execute([$payload['uid']]);
    $admin = $stmt->fetch();

    if (!$admin) {
        response(true, ["authenticated" => false, "admin" => null]);
    }

    // Verificar se conta esta ativa
    $status = (int)($admin['status'] ?? 1);

    if ($status !== 1) {
        response(true, ["authenticated" => false, "admin" => null, "reason" => "account_inactive"]);
    }

    // Permissoes baseadas no role
    $isRh = (bool)($admin['is_rh'] ?? false);
    $role = $admin['role'] ?? 'admin';
    $permissions = getAdminPermissions($role, $isRh);

    response(true, [
        "authenticated" => true,
        "admin" => [
            "id" => (int)$admin['admin_id'],
            "nome" => $admin['name'],
            "email" => $admin['email'],
            "role" => $role,
            "is_rh" => $isRh,
            "permissions" => $permissions,
            "criado_em" => $admin['created_at'],
            "ultimo_login" => $admin['last_login']
        ]
    ]);

} catch (Exception $e) {
    error_log("[admin/auth/session] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar sessao", 500);
}

/**
 * Retorna permissoes baseadas no role do admin
 */
function getAdminPermissions(string $role, bool $isRh): array {
    $base = [
        'view_dashboard' => true,
        'view_orders' => true,
        'view_partners' => true,
        'view_shoppers' => true,
    ];

    if ($role === 'manager' || $isRh) {
        $base['manage_partners'] = true;
        $base['manage_shoppers'] = true;
        $base['manage_orders'] = true;
        $base['view_reports'] = true;
        $base['manage_finances'] = true;
    }

    if ($isRh) {
        $base['manage_admins'] = true;
        $base['manage_rh'] = true;
        $base['approve_partners'] = true;
        $base['approve_shoppers'] = true;
        $base['system_settings'] = true;
    }

    return $base;
}
