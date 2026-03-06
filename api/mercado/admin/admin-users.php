<?php
/**
 * /api/mercado/admin/admin-users.php
 *
 * CRUD for admin users (support agents).
 *
 * GET: List admins with pagination and search.
 *   Params: page, limit, search
 *   Returns: admin list with id, name, email, role, status, is_rh, last_login, created_at
 *
 * POST action=create: Create new admin.
 *   Body: { action: "create", name, email, password, role }
 *
 * POST action=edit: Update admin fields.
 *   Body: { action: "edit", admin_id, name?, email?, role?, status? }
 *
 * POST action=reset_password: Reset admin password.
 *   Body: { action: "reset_password", admin_id, new_password }
 *
 * POST action=deactivate: Deactivate admin.
 *   Body: { action: "deactivate", admin_id }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    // Only manager or rh roles can manage admin users
    $adminRole = $payload['role'] ?? '';
    $adminType = $payload['type'] ?? '';
    if ($adminType !== 'rh' && !in_array($adminRole, ['manager', 'rh'], true)) {
        response(false, null, "Apenas gerentes e RH podem gerenciar usuarios admin", 403);
    }

    // =================== GET: List admins ===================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = ["1=1"];
        $params = [];

        if ($search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $where[] = "(a.name ILIKE ? OR a.email ILIKE ?)";
            $s = "%{$escaped}%";
            $params[] = $s;
            $params[] = $s;
        }

        $where_sql = implode(' AND ', $where);

        // Count total
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_admins a WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        // Fetch admins (never expose password hash)
        $stmt = $db->prepare("
            SELECT a.id, a.name, a.email, a.role, a.status, a.is_rh,
                   a.last_login, a.created_at
            FROM om_admins a
            WHERE {$where_sql}
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $admins = $stmt->fetchAll();

        // Format fields
        foreach ($admins as &$adm) {
            $adm['id'] = (int)$adm['id'];
            $adm['status'] = (int)$adm['status'];
            $adm['is_rh'] = (bool)($adm['is_rh'] ?? false);
        }
        unset($adm);

        response(true, [
            'admins' => $admins,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ], "Admins listados");
    }

    // =================== POST: Actions ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        if (!$action) response(false, null, "Campo 'action' obrigatorio", 400);

        // ── Create admin ──
        if ($action === 'create') {
            $name = strip_tags(trim($input['name'] ?? ''));
            $email = strip_tags(trim($input['email'] ?? ''));
            $password = $input['password'] ?? '';
            $role = strip_tags(trim($input['role'] ?? 'support'));

            if (!$name) response(false, null, "name obrigatorio", 400);
            if (!$email) response(false, null, "email obrigatorio", 400);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) response(false, null, "email invalido", 400);
            if (!$password) response(false, null, "password obrigatorio", 400);
            if (strlen($password) < 8) response(false, null, "password deve ter no minimo 8 caracteres", 400);

            // Whitelist roles
            $allowed_roles = ['support', 'manager', 'rh', 'viewer'];
            if (!in_array($role, $allowed_roles, true)) {
                response(false, null, "role invalido. Permitidos: " . implode(', ', $allowed_roles), 400);
            }

            // Check email uniqueness
            $stmt = $db->prepare("SELECT id FROM om_admins WHERE LOWER(email) = LOWER(?)");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                response(false, null, "Email ja cadastrado", 409);
            }

            // Hash password with Argon2id
            $password_hash = password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3,
            ]);

            $stmt = $db->prepare("
                INSERT INTO om_admins (name, email, password, role, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 1, NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([$name, $email, $password_hash, $role]);
            $row = $stmt->fetch();
            $new_admin_id = $row ? (int)$row['id'] : 0;

            om_audit()->log(
                'admin_create',
                'admin',
                $new_admin_id,
                null,
                ['name' => $name, 'email' => $email, 'role' => $role],
                "Admin criado: {$name} ({$email}) com role {$role}"
            );

            response(true, [
                'admin_id' => $new_admin_id,
                'name' => $name,
                'email' => $email,
                'role' => $role,
            ], "Admin criado com sucesso");
        }

        // ── Edit admin ──
        if ($action === 'edit') {
            $target_id = (int)($input['admin_id'] ?? 0);
            if (!$target_id) response(false, null, "admin_id obrigatorio", 400);

            // Fetch existing admin
            $stmt = $db->prepare("SELECT id, name, email, role, status FROM om_admins WHERE id = ?");
            $stmt->execute([$target_id]);
            $existing = $stmt->fetch();
            if (!$existing) response(false, null, "Admin nao encontrado", 404);

            $old_data = $existing;
            $updates = [];
            $params = [];

            // Name
            if (isset($input['name'])) {
                $name = strip_tags(trim($input['name']));
                if ($name) {
                    $updates[] = "name = ?";
                    $params[] = $name;
                }
            }

            // Email
            if (isset($input['email'])) {
                $email = strip_tags(trim($input['email']));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    response(false, null, "email invalido", 400);
                }
                // Check uniqueness (excluding self)
                $stmt = $db->prepare("SELECT id FROM om_admins WHERE LOWER(email) = LOWER(?) AND id != ?");
                $stmt->execute([$email, $target_id]);
                if ($stmt->fetch()) {
                    response(false, null, "Email ja cadastrado por outro admin", 409);
                }
                $updates[] = "email = ?";
                $params[] = $email;
            }

            // Role
            if (isset($input['role'])) {
                $role = strip_tags(trim($input['role']));
                $allowed_roles = ['support', 'manager', 'rh', 'viewer'];
                if (!in_array($role, $allowed_roles, true)) {
                    response(false, null, "role invalido. Permitidos: " . implode(', ', $allowed_roles), 400);
                }
                $updates[] = "role = ?";
                $params[] = $role;
            }

            // Status
            if (isset($input['status'])) {
                $status = (int)$input['status'];
                $updates[] = "status = ?";
                $params[] = $status;
            }

            if (empty($updates)) {
                response(false, null, "Nenhum campo para atualizar", 400);
            }

            $updates[] = "updated_at = NOW()";
            $params[] = $target_id;

            $sql = "UPDATE om_admins SET " . implode(', ', $updates) . " WHERE id = ?";
            $db->prepare($sql)->execute($params);

            // Build new_data for audit
            $new_data = [];
            if (isset($input['name'])) $new_data['name'] = $input['name'];
            if (isset($input['email'])) $new_data['email'] = $input['email'];
            if (isset($input['role'])) $new_data['role'] = $input['role'];
            if (isset($input['status'])) $new_data['status'] = (int)$input['status'];

            om_audit()->log(
                'admin_edit',
                'admin',
                $target_id,
                ['name' => $old_data['name'], 'email' => $old_data['email'], 'role' => $old_data['role'], 'status' => $old_data['status']],
                $new_data,
                "Admin #{$target_id} atualizado"
            );

            response(true, [
                'admin_id' => $target_id,
                'updated_fields' => array_keys($new_data),
            ], "Admin atualizado com sucesso");
        }

        // ── Reset password ──
        if ($action === 'reset_password') {
            $target_id = (int)($input['admin_id'] ?? 0);
            $new_password = $input['new_password'] ?? '';

            if (!$target_id) response(false, null, "admin_id obrigatorio", 400);
            if (!$new_password) response(false, null, "new_password obrigatorio", 400);
            if (strlen($new_password) < 8) response(false, null, "new_password deve ter no minimo 8 caracteres", 400);

            // Verify admin exists
            $stmt = $db->prepare("SELECT id, name, email FROM om_admins WHERE id = ?");
            $stmt->execute([$target_id]);
            $target = $stmt->fetch();
            if (!$target) response(false, null, "Admin nao encontrado", 404);

            // Hash new password with Argon2id
            $password_hash = password_hash($new_password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3,
            ]);

            $stmt = $db->prepare("UPDATE om_admins SET password = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$password_hash, $target_id]);

            om_audit()->log(
                'admin_reset_password',
                'admin',
                $target_id,
                null,
                null,
                "Senha resetada para admin '{$target['name']}' ({$target['email']})"
            );

            response(true, [
                'admin_id' => $target_id,
            ], "Senha resetada com sucesso");
        }

        // ── Deactivate admin ──
        if ($action === 'deactivate') {
            $target_id = (int)($input['admin_id'] ?? 0);
            if (!$target_id) response(false, null, "admin_id obrigatorio", 400);

            // Cannot deactivate yourself
            if ($target_id === $admin_id) {
                response(false, null, "Voce nao pode desativar sua propria conta", 400);
            }

            $stmt = $db->prepare("SELECT id, name, email, status FROM om_admins WHERE id = ?");
            $stmt->execute([$target_id]);
            $target = $stmt->fetch();
            if (!$target) response(false, null, "Admin nao encontrado", 404);

            if ((int)$target['status'] === 0) {
                response(false, null, "Admin ja esta desativado", 400);
            }

            $stmt = $db->prepare("UPDATE om_admins SET status = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$target_id]);

            om_audit()->log(
                'admin_deactivate',
                'admin',
                $target_id,
                ['status' => (int)$target['status']],
                ['status' => 0],
                "Admin '{$target['name']}' ({$target['email']}) desativado"
            );

            response(true, [
                'admin_id' => $target_id,
                'name' => $target['name'],
            ], "Admin desativado com sucesso");
        }

        response(false, null, "Acao invalida: {$action}", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/admin-users] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
