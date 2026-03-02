<?php
/**
 * Multi-branch (multi-filial) management endpoint
 *
 * GET    - List branches in the partner's group (with stats)
 * POST   - Create group, add branch, remove branch
 * PUT    - Switch branch context
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // ─── GET: List branches ─────────────────────────────────────────
    if ($method === 'GET') {
        // Check if this partner belongs to any group
        $stmt = $db->prepare("
            SELECT m.group_id, m.role, g.name AS group_name, g.owner_partner_id, g.logo_url, g.created_at
            FROM om_partner_group_members m
            JOIN om_partner_groups g ON g.group_id = m.group_id
            WHERE m.partner_id = ?
            LIMIT 1
        ");
        $stmt->execute([$partnerId]);
        $membership = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$membership) {
            response(true, [
                'has_group' => false,
                'group' => null,
                'branches' => [],
                'stats' => null,
            ]);
        }

        $groupId = (int)$membership['group_id'];
        $isOwner = $membership['role'] === 'owner';

        // Get all branches with their stats
        $stmt = $db->prepare("
            SELECT p.partner_id, p.nome, p.cidade, p.is_open, p.rating,
                m.role,
                COALESCE(os.orders_today, 0) AS orders_today,
                COALESCE(os.revenue_today, 0) AS revenue_today
            FROM om_market_partners p
            JOIN om_partner_group_members m ON m.partner_id = p.partner_id
            LEFT JOIN LATERAL (
                SELECT COUNT(*) AS orders_today, COALESCE(SUM(total), 0) AS revenue_today
                FROM om_market_orders
                WHERE partner_id = p.partner_id AND DATE(created_at) = CURRENT_DATE
            ) os ON true
            WHERE m.group_id = ?
            ORDER BY p.nome
        ");
        $stmt->execute([$groupId]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast numeric fields
        foreach ($branches as &$b) {
            $b['partner_id'] = (int)$b['partner_id'];
            $b['is_open'] = (bool)$b['is_open'];
            $b['rating'] = $b['rating'] !== null ? round((float)$b['rating'], 1) : null;
            $b['orders_today'] = (int)$b['orders_today'];
            $b['revenue_today'] = round((float)$b['revenue_today'], 2);
        }
        unset($b);

        // Aggregate stats
        $totalStores = count($branches);
        $storesOnline = count(array_filter($branches, fn($b) => $b['is_open']));
        $totalOrders = array_sum(array_column($branches, 'orders_today'));
        $totalRevenue = array_sum(array_column($branches, 'revenue_today'));

        response(true, [
            'has_group' => true,
            'group' => [
                'group_id' => $groupId,
                'name' => $membership['group_name'],
                'logo_url' => $membership['logo_url'],
                'owner_partner_id' => (int)$membership['owner_partner_id'],
                'created_at' => $membership['created_at'],
            ],
            'is_owner' => $isOwner,
            'current_partner_id' => $partnerId,
            'branches' => $branches,
            'stats' => [
                'total_stores' => $totalStores,
                'stores_online' => $storesOnline,
                'total_orders' => $totalOrders,
                'total_revenue' => round($totalRevenue, 2),
            ],
        ]);
    }

    // ─── POST: Create group / Add branch / Remove branch ────────────
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';

        // --- Create group ---
        if ($action === 'create_group') {
            $name = trim($input['name'] ?? '');
            if (empty($name)) {
                response(false, null, "Nome do grupo e obrigatorio", 400);
            }
            if (strlen($name) > 200) {
                response(false, null, "Nome deve ter no maximo 200 caracteres", 400);
            }

            // Check if partner already belongs to a group
            $stmt = $db->prepare("SELECT id FROM om_partner_group_members WHERE partner_id = ? LIMIT 1");
            $stmt->execute([$partnerId]);
            if ($stmt->fetch()) {
                response(false, null, "Voce ja faz parte de um grupo", 400);
            }

            $db->beginTransaction();
            try {
                // Create the group
                $stmt = $db->prepare("
                    INSERT INTO om_partner_groups (name, owner_partner_id)
                    VALUES (?, ?)
                    RETURNING group_id
                ");
                $stmt->execute([$name, $partnerId]);
                $row = $stmt->fetch();
                $groupId = (int)$row['group_id'];

                // Add owner as member
                $stmt = $db->prepare("
                    INSERT INTO om_partner_group_members (group_id, partner_id, role)
                    VALUES (?, ?, 'owner')
                ");
                $stmt->execute([$groupId, $partnerId]);

                $db->commit();
                response(true, ['group_id' => $groupId], "Grupo criado com sucesso!");
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // --- Add branch ---
        if ($action === 'add_branch') {
            $branchPartnerId = intval($input['partner_id'] ?? 0);
            if (!$branchPartnerId) {
                response(false, null, "ID do parceiro e obrigatorio", 400);
            }

            // Verify caller is a group owner
            $stmt = $db->prepare("
                SELECT m.group_id
                FROM om_partner_group_members m
                JOIN om_partner_groups g ON g.group_id = m.group_id AND g.owner_partner_id = ?
                WHERE m.partner_id = ? AND m.role = 'owner'
                LIMIT 1
            ");
            $stmt->execute([$partnerId, $partnerId]);
            $ownerMembership = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ownerMembership) {
                response(false, null, "Somente o dono do grupo pode adicionar filiais", 403);
            }

            $groupId = (int)$ownerMembership['group_id'];

            // Verify the branch partner exists
            $stmt = $db->prepare("SELECT partner_id, nome FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$branchPartnerId]);
            $branchPartner = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$branchPartner) {
                response(false, null, "Parceiro nao encontrado", 404);
            }

            // Check if branch already belongs to a group
            $stmt = $db->prepare("SELECT id FROM om_partner_group_members WHERE partner_id = ? LIMIT 1");
            $stmt->execute([$branchPartnerId]);
            if ($stmt->fetch()) {
                response(false, null, "Este parceiro ja faz parte de um grupo", 400);
            }

            // Cannot add self (already owner)
            if ($branchPartnerId === $partnerId) {
                response(false, null, "Voce ja e o dono deste grupo", 400);
            }

            $stmt = $db->prepare("
                INSERT INTO om_partner_group_members (group_id, partner_id, role)
                VALUES (?, ?, 'branch')
            ");
            $stmt->execute([$groupId, $branchPartnerId]);

            response(true, [
                'partner_id' => $branchPartnerId,
                'name' => $branchPartner['nome'],
            ], "Filial adicionada com sucesso!");
        }

        // --- Remove branch ---
        if ($action === 'remove_branch') {
            $branchPartnerId = intval($input['partner_id'] ?? 0);
            if (!$branchPartnerId) {
                response(false, null, "ID do parceiro e obrigatorio", 400);
            }

            // Cannot remove self (owner)
            if ($branchPartnerId === $partnerId) {
                response(false, null, "Voce nao pode remover a si mesmo do grupo", 400);
            }

            // Verify caller is a group owner
            $stmt = $db->prepare("
                SELECT m.group_id
                FROM om_partner_group_members m
                JOIN om_partner_groups g ON g.group_id = m.group_id AND g.owner_partner_id = ?
                WHERE m.partner_id = ? AND m.role = 'owner'
                LIMIT 1
            ");
            $stmt->execute([$partnerId, $partnerId]);
            $ownerMembership = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ownerMembership) {
                response(false, null, "Somente o dono do grupo pode remover filiais", 403);
            }

            $groupId = (int)$ownerMembership['group_id'];

            $stmt = $db->prepare("
                DELETE FROM om_partner_group_members
                WHERE group_id = ? AND partner_id = ? AND role != 'owner'
            ");
            $stmt->execute([$groupId, $branchPartnerId]);

            if ($stmt->rowCount() === 0) {
                response(false, null, "Filial nao encontrada no grupo", 404);
            }

            response(true, null, "Filial removida do grupo!");
        }

        response(false, null, "Acao invalida", 400);
    }

    // ─── PUT: Switch branch or update group ─────────────────────────
    if ($method === 'PUT') {
        $input = getInput();
        $action = $input['action'] ?? '';

        if ($action === 'switch_branch') {
            $branchPartnerId = intval($input['partner_id'] ?? 0);
            if (!$branchPartnerId) {
                response(false, null, "ID do parceiro e obrigatorio", 400);
            }

            // Verify caller is a group owner
            $stmt = $db->prepare("
                SELECT m.group_id
                FROM om_partner_group_members m
                JOIN om_partner_groups g ON g.group_id = m.group_id AND g.owner_partner_id = ?
                WHERE m.partner_id = ? AND m.role = 'owner'
                LIMIT 1
            ");
            $stmt->execute([$partnerId, $partnerId]);
            $ownerMembership = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ownerMembership) {
                response(false, null, "Somente o dono do grupo pode alternar filiais", 403);
            }

            $groupId = (int)$ownerMembership['group_id'];

            // Verify target branch is in the same group
            $stmt = $db->prepare("
                SELECT m.partner_id, p.nome
                FROM om_partner_group_members m
                JOIN om_market_partners p ON p.partner_id = m.partner_id
                WHERE m.group_id = ? AND m.partner_id = ?
                LIMIT 1
            ");
            $stmt->execute([$groupId, $branchPartnerId]);
            $branch = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$branch) {
                response(false, null, "Filial nao encontrada neste grupo", 404);
            }

            // Return the branch partner_id for the frontend to use in API calls
            response(true, [
                'partner_id' => (int)$branch['partner_id'],
                'nome' => $branch['nome'],
            ], "Alternado para filial: " . $branch['nome']);
        }

        if ($action === 'update_group') {
            $name = trim($input['name'] ?? '');
            if (empty($name)) {
                response(false, null, "Nome do grupo e obrigatorio", 400);
            }
            if (strlen($name) > 200) {
                response(false, null, "Nome deve ter no maximo 200 caracteres", 400);
            }

            // Verify caller is a group owner
            $stmt = $db->prepare("
                SELECT g.group_id
                FROM om_partner_groups g
                WHERE g.owner_partner_id = ?
                LIMIT 1
            ");
            $stmt->execute([$partnerId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) {
                response(false, null, "Grupo nao encontrado", 404);
            }

            $stmt = $db->prepare("UPDATE om_partner_groups SET name = ? WHERE group_id = ?");
            $stmt->execute([$name, $group['group_id']]);

            response(true, null, "Grupo atualizado!");
        }

        response(false, null, "Acao invalida", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/branches] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
