<?php
/**
 * /api/mercado/partner/opcoes.php
 * CRUD de grupos de opcoes e opcoes de produto (auth via token)
 *
 * GET    ?product_id=X           - Lista grupos e opcoes de um produto
 * POST   action=create_group     - Criar grupo de opcoes
 * POST   action=update_group     - Atualizar grupo
 * POST   action=delete_group     - Deletar grupo (cascade opcoes)
 * POST   action=create_option    - Criar opcao em um grupo
 * POST   action=update_option    - Atualizar opcao
 * POST   action=delete_option    - Deletar opcao
 * POST   action=toggle_option    - Toggle disponibilidade de opcao
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

    // GET - listar grupos e opcoes de um produto
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $product_id = (int)($_GET['product_id'] ?? 0);
        if (!$product_id) {
            response(false, null, "product_id obrigatorio", 400);
        }

        // Verificar que produto pertence ao parceiro
        $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$product_id, $partnerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Produto nao encontrado", 404);
        }

        $stmt = $db->prepare("
            SELECT g.*,
                   (SELECT COUNT(*) FROM om_product_options WHERE group_id = g.id) as options_count
            FROM om_product_option_groups g
            WHERE g.product_id = ? AND g.active = 1
            ORDER BY g.sort_order, g.id
        ");
        $stmt->execute([$product_id]);
        $groups = $stmt->fetchAll();

        foreach ($groups as &$group) {
            $group['id'] = (int)$group['id'];
            $group['required'] = (bool)$group['required'];
            $group['min_select'] = (int)$group['min_select'];
            $group['max_select'] = (int)$group['max_select'];
            $group['sort_order'] = (int)$group['sort_order'];
            $group['options_count'] = (int)$group['options_count'];

            $stmt = $db->prepare("SELECT * FROM om_product_options WHERE group_id = ? ORDER BY sort_order, id");
            $stmt->execute([$group['id']]);
            $options = $stmt->fetchAll();

            foreach ($options as &$opt) {
                $opt['id'] = (int)$opt['id'];
                $opt['price_extra'] = round((float)$opt['price_extra'], 2);
                $opt['available'] = (bool)($opt['available'] ?? true);
                $opt['sort_order'] = (int)($opt['sort_order'] ?? 0);
            }
            unset($opt);

            $group['options'] = $options;
        }
        unset($group);

        response(true, ["groups" => $groups]);
    }

    // POST - acoes CRUD
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $action = $input['action'] ?? '';

    // === GRUPO DE OPCOES ===

    if ($action === 'create_group') {
        $product_id = (int)($input['product_id'] ?? 0);
        $name = trim(substr($input['name'] ?? '', 0, 100));
        $required = (int)($input['required'] ?? 0);
        $min_select = (int)($input['min_select'] ?? 0);
        $max_select = max(1, (int)($input['max_select'] ?? 1));
        $sort_order = (int)($input['sort_order'] ?? 0);

        if (!$product_id || !$name) {
            response(false, null, "product_id e name obrigatorios", 400);
        }

        // Verificar que produto pertence ao parceiro
        $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$product_id, $partnerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Produto nao encontrado", 404);
        }

        $stmt = $db->prepare("INSERT INTO om_product_option_groups (product_id, partner_id, name, required, min_select, max_select, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$product_id, $partnerId, $name, $required, $min_select, $max_select, $sort_order]);

        response(true, ["id" => (int)$db->lastInsertId()], "Grupo criado");
    }

    if ($action === 'update_group') {
        $group_id = (int)($input['group_id'] ?? 0);
        $name = trim(substr($input['name'] ?? '', 0, 100));
        $required = (int)($input['required'] ?? 0);
        $min_select = (int)($input['min_select'] ?? 0);
        $max_select = max(1, (int)($input['max_select'] ?? 1));
        $sort_order = (int)($input['sort_order'] ?? 0);

        if (!$group_id || !$name) {
            response(false, null, "group_id e name obrigatorios", 400);
        }

        $stmt = $db->prepare("UPDATE om_product_option_groups SET name = ?, required = ?, min_select = ?, max_select = ?, sort_order = ? WHERE id = ? AND partner_id = ?");
        $stmt->execute([$name, $required, $min_select, $max_select, $sort_order, $group_id, $partnerId]);

        response(true, null, "Grupo atualizado");
    }

    if ($action === 'delete_group') {
        $group_id = (int)($input['group_id'] ?? 0);
        if (!$group_id) {
            response(false, null, "group_id obrigatorio", 400);
        }

        // Deletar opcoes do grupo primeiro
        $db->prepare("DELETE FROM om_product_options WHERE group_id IN (SELECT id FROM om_product_option_groups WHERE id = ? AND partner_id = ?)")->execute([$group_id, $partnerId]);

        $stmt = $db->prepare("DELETE FROM om_product_option_groups WHERE id = ? AND partner_id = ?");
        $stmt->execute([$group_id, $partnerId]);

        response(true, null, "Grupo removido");
    }

    // === OPCOES ===

    if ($action === 'create_option') {
        $group_id = (int)($input['group_id'] ?? 0);
        $name = trim(substr($input['name'] ?? '', 0, 150));
        $image = trim(substr($input['image'] ?? '', 0, 500)) ?: null;
        $description = trim(substr($input['description'] ?? '', 0, 300)) ?: null;
        $price_extra = max(0, floatval($input['price_extra'] ?? 0));
        $sort_order = (int)($input['sort_order'] ?? 0);

        if (!$group_id || !$name) {
            response(false, null, "group_id e name obrigatorios", 400);
        }

        // Verificar que o grupo pertence ao parceiro
        $stmt = $db->prepare("SELECT id FROM om_product_option_groups WHERE id = ? AND partner_id = ?");
        $stmt->execute([$group_id, $partnerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Grupo nao encontrado", 404);
        }

        $stmt = $db->prepare("INSERT INTO om_product_options (group_id, name, image, description, price_extra, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$group_id, $name, $image, $description, $price_extra, $sort_order]);

        response(true, ["id" => (int)$db->lastInsertId()], "Opcao criada");
    }

    if ($action === 'update_option') {
        $option_id = (int)($input['option_id'] ?? 0);
        $name = trim(substr($input['name'] ?? '', 0, 150));
        $image = isset($input['image']) ? (trim(substr($input['image'], 0, 500)) ?: null) : '__KEEP__';
        $description = isset($input['description']) ? (trim(substr($input['description'], 0, 300)) ?: null) : '__KEEP__';
        $price_extra = max(0, floatval($input['price_extra'] ?? 0));
        $sort_order = (int)($input['sort_order'] ?? 0);

        if (!$option_id || !$name) {
            response(false, null, "option_id e name obrigatorios", 400);
        }

        $setClauses = ["name = ?", "price_extra = ?", "sort_order = ?"];
        $params = [$name, $price_extra, $sort_order];

        if ($image !== '__KEEP__') {
            $setClauses[] = "image = ?";
            $params[] = $image;
        }
        if ($description !== '__KEEP__') {
            $setClauses[] = "description = ?";
            $params[] = $description;
        }

        $params[] = $option_id;
        $params[] = $partnerId;

        $setStr = implode(', ', $setClauses);
        $stmt = $db->prepare("
            UPDATE om_product_options SET {$setStr}
            WHERE id = ? AND group_id IN (SELECT id FROM om_product_option_groups WHERE partner_id = ?)
        ");
        $stmt->execute($params);

        response(true, null, "Opcao atualizada");
    }

    if ($action === 'delete_option') {
        $option_id = (int)($input['option_id'] ?? 0);
        if (!$option_id) {
            response(false, null, "option_id obrigatorio", 400);
        }

        $stmt = $db->prepare("
            DELETE FROM om_product_options
            WHERE id = ? AND group_id IN (SELECT id FROM om_product_option_groups WHERE partner_id = ?)
        ");
        $stmt->execute([$option_id, $partnerId]);

        response(true, null, "Opcao removida");
    }

    if ($action === 'toggle_option') {
        $option_id = (int)($input['option_id'] ?? 0);
        if (!$option_id) {
            response(false, null, "option_id obrigatorio", 400);
        }

        $stmt = $db->prepare("
            UPDATE om_product_options
            SET available = CASE WHEN available::text = '1' THEN '0' ELSE '1' END
            WHERE id = ? AND group_id IN (SELECT id FROM om_product_option_groups WHERE partner_id = ?)
        ");
        $stmt->execute([$option_id, $partnerId]);

        response(true, null, "Disponibilidade atualizada");
    }

    if (!$action) {
        response(false, null, "action obrigatorio", 400);
    }

} catch (Exception $e) {
    error_log("[partner/opcoes] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar opcoes", 500);
}
