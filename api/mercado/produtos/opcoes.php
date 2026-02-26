<?php
/**
 * /api/mercado/produtos/opcoes.php
 * CRUD completo para grupos de opcoes e opcoes de produto
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
require_once __DIR__ . "/../helpers/csrf.php";

try {
    session_start();
    verifyCsrf();
    $db = getDB();

    $mercado_id = $_SESSION['mercado_id'] ?? 0;

    // GET - listar (pode ser publico para o app)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $product_id = (int)($_GET['product_id'] ?? 0);
        if (!$product_id) {
            response(false, null, "product_id obrigatorio", 400);
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
            $stmt = $db->prepare("SELECT * FROM om_product_options WHERE group_id = ? ORDER BY sort_order, id");
            $stmt->execute([$group['id']]);
            $group['options'] = $stmt->fetchAll();
        }
        unset($group);

        response(true, ["groups" => $groups]);
    }

    // POST - requer autenticacao
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    if (!$mercado_id) {
        response(false, null, "Nao autorizado", 401);
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
        $stmt->execute([$product_id, $mercado_id]);
        if (!$stmt->fetch()) {
            response(false, null, "Produto nao encontrado", 404);
        }

        $stmt = $db->prepare("INSERT INTO om_product_option_groups (product_id, partner_id, name, required, min_select, max_select, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id");
        $stmt->execute([$product_id, $mercado_id, $name, $required, $min_select, $max_select, $sort_order]);

        response(true, ["id" => (int)$stmt->fetchColumn()], "Grupo criado");
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
        $stmt->execute([$name, $required, $min_select, $max_select, $sort_order, $group_id, $mercado_id]);

        response(true, null, "Grupo atualizado");
    }

    if ($action === 'delete_group') {
        $group_id = (int)($input['group_id'] ?? 0);
        if (!$group_id) {
            response(false, null, "group_id obrigatorio", 400);
        }

        $stmt = $db->prepare("DELETE FROM om_product_option_groups WHERE id = ? AND partner_id = ?");
        $stmt->execute([$group_id, $mercado_id]);

        response(true, null, "Grupo removido");
    }

    // === OPCOES ===

    if ($action === 'create_option') {
        $group_id = (int)($input['group_id'] ?? 0);
        $name = trim(substr($input['name'] ?? '', 0, 150));
        $price_extra = max(0, floatval($input['price_extra'] ?? 0));
        $sort_order = (int)($input['sort_order'] ?? 0);

        if (!$group_id || !$name) {
            response(false, null, "group_id e name obrigatorios", 400);
        }

        // Verificar que o grupo pertence ao parceiro
        $stmt = $db->prepare("SELECT id FROM om_product_option_groups WHERE id = ? AND partner_id = ?");
        $stmt->execute([$group_id, $mercado_id]);
        if (!$stmt->fetch()) {
            response(false, null, "Grupo nao encontrado", 404);
        }

        $stmt = $db->prepare("INSERT INTO om_product_options (group_id, name, price_extra, sort_order) VALUES (?, ?, ?, ?) RETURNING id");
        $stmt->execute([$group_id, $name, $price_extra, $sort_order]);

        response(true, ["id" => (int)$stmt->fetchColumn()], "Opcao criada");
    }

    if ($action === 'update_option') {
        $option_id = (int)($input['option_id'] ?? 0);
        $name = trim(substr($input['name'] ?? '', 0, 150));
        $price_extra = max(0, floatval($input['price_extra'] ?? 0));
        $sort_order = (int)($input['sort_order'] ?? 0);

        if (!$option_id || !$name) {
            response(false, null, "option_id e name obrigatorios", 400);
        }

        // Verificar ownership via join
        $stmt = $db->prepare("
            UPDATE om_product_options o
            INNER JOIN om_product_option_groups g ON o.group_id = g.id
            SET o.name = ?, o.price_extra = ?, o.sort_order = ?
            WHERE o.id = ? AND g.partner_id = ?
        ");
        $stmt->execute([$name, $price_extra, $sort_order, $option_id, $mercado_id]);

        response(true, null, "Opcao atualizada");
    }

    if ($action === 'delete_option') {
        $option_id = (int)($input['option_id'] ?? 0);
        if (!$option_id) {
            response(false, null, "option_id obrigatorio", 400);
        }

        $stmt = $db->prepare("
            DELETE o FROM om_product_options o
            INNER JOIN om_product_option_groups g ON o.group_id = g.id
            WHERE o.id = ? AND g.partner_id = ?
        ");
        $stmt->execute([$option_id, $mercado_id]);

        response(true, null, "Opcao removida");
    }

    if ($action === 'toggle_option') {
        $option_id = (int)($input['option_id'] ?? 0);
        if (!$option_id) {
            response(false, null, "option_id obrigatorio", 400);
        }

        $stmt = $db->prepare("
            UPDATE om_product_options o
            SET o.available = CASE WHEN o.available::text = '1' THEN '0' ELSE '1' END
            FROM om_product_option_groups g
            WHERE o.group_id = g.id AND o.id = ? AND g.partner_id = ?
        ");
        $stmt->execute([$option_id, $mercado_id]);

        response(true, null, "Disponibilidade atualizada");
    }

    if (!$action) {
        response(false, null, "action obrigatorio", 400);
    }

} catch (Exception $e) {
    error_log("[opcoes] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar opcoes", 500);
}
