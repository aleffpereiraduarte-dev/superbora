<?php
/**
 * /api/mercado/partner/categories.php
 * GET  - List all active categories for dropdowns
 * POST - Create a new category { name, parent_id? }
 * PUT  - Update category { category_id, name } — only categories created by this partner
 * DELETE ?id=X - Delete category — only categories created by this partner
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];
    $method = $_SERVER["REQUEST_METHOD"];

    // ── GET: Listar categorias ativas ──
    if ($method === "GET") {
        $stmt = $db->prepare("
            SELECT
                c.category_id,
                c.name,
                c.parent_id,
                c.image,
                c.status,
                c.created_by_partner_id,
                p.name as parent_name
            FROM om_market_categories c
            LEFT JOIN om_market_categories p ON p.category_id = c.parent_id
            WHERE c.status = 1
            ORDER BY c.parent_id ASC, c.name ASC
        ");
        $stmt->execute();
        $categories = $stmt->fetchAll();

        $items = [];
        foreach ($categories as $cat) {
            $items[] = [
                "category_id" => (int)$cat['category_id'],
                "name" => $cat['name'],
                "parent_id" => $cat['parent_id'] ? (int)$cat['parent_id'] : null,
                "parent_name" => $cat['parent_name'],
                "image" => $cat['image'],
                "is_own" => ((int)($cat['created_by_partner_id'] ?? 0) === $partner_id),
                "full_name" => $cat['parent_name']
                    ? $cat['parent_name'] . " > " . $cat['name']
                    : $cat['name']
            ];
        }

        response(true, [
            "items" => $items,
            "total" => count($items)
        ], "Categorias listadas");
    }

    // ── POST: Criar nova categoria ──
    elseif ($method === "POST") {
        $input = getInput();
        $name = trim($input['name'] ?? $input['nome'] ?? '');
        $parent_id = isset($input['parent_id']) ? (int)$input['parent_id'] : 0;

        if (empty($name)) {
            response(false, null, "Nome da categoria e obrigatorio", 400);
        }

        if (mb_strlen($name) > 100) {
            response(false, null, "Nome muito longo (max 100 caracteres)", 400);
        }

        // Verificar se ja existe categoria com mesmo nome (case-insensitive)
        $stmtCheck = $db->prepare("
            SELECT category_id FROM om_market_categories
            WHERE LOWER(name) = LOWER(?) AND parent_id = ? AND status = 1
        ");
        $stmtCheck->execute([$name, $parent_id]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            // Retornar a existente em vez de criar duplicata
            response(true, [
                "category_id" => (int)$existing['category_id'],
                "name" => $name,
                "parent_id" => $parent_id ?: null,
                "already_existed" => true
            ], "Categoria ja existe");
        }

        // Validar parent_id se fornecido
        if ($parent_id > 0) {
            $stmtParent = $db->prepare("SELECT category_id FROM om_market_categories WHERE category_id = ? AND status = 1");
            $stmtParent->execute([$parent_id]);
            if (!$stmtParent->fetch()) {
                response(false, null, "Categoria pai nao encontrada", 404);
            }
        }

        // Gerar slug
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)));
        $slug = trim($slug, '-');

        // Pegar o maior sort_order
        $stmtSort = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_sort FROM om_market_categories");
        $stmtSort->execute();
        $nextSort = (int)$stmtSort->fetch()['next_sort'];

        $stmt = $db->prepare("
            INSERT INTO om_market_categories (name, slug, parent_id, sort_order, status, created_by_partner_id)
            VALUES (?, ?, ?, ?, 1, ?)
            RETURNING category_id
        ");
        $stmt->execute([$name, $slug, $parent_id, $nextSort, $partner_id]);
        $category_id = (int)$stmt->fetchColumn();

        om_audit()->log(
            OmAudit::ACTION_CREATE,
            'category',
            $category_id,
            null,
            ['name' => $name, 'parent_id' => $parent_id],
            "Categoria '{$name}' criada pelo parceiro #{$partner_id}",
            'partner',
            $partner_id
        );

        response(true, [
            "category_id" => $category_id,
            "name" => $name,
            "parent_id" => $parent_id ?: null,
            "slug" => $slug,
            "full_name" => $name
        ], "Categoria criada com sucesso");
    }

    // ── PUT: Atualizar categoria (somente as criadas por este parceiro) ──
    elseif ($method === "PUT") {
        $input = getInput();
        $category_id = (int)($input['category_id'] ?? $input['id'] ?? 0);
        $name = trim($input['name'] ?? $input['nome'] ?? '');

        if (!$category_id) {
            response(false, null, "ID da categoria e obrigatorio", 400);
        }
        if (empty($name)) {
            response(false, null, "Nome da categoria e obrigatorio", 400);
        }
        if (mb_strlen($name) > 100) {
            response(false, null, "Nome muito longo (max 100 caracteres)", 400);
        }

        // Verificar se existe E pertence a este parceiro
        $stmtCheck = $db->prepare("SELECT category_id, created_by_partner_id FROM om_market_categories WHERE category_id = ?");
        $stmtCheck->execute([$category_id]);
        $cat = $stmtCheck->fetch();
        if (!$cat) {
            response(false, null, "Categoria nao encontrada", 404);
        }

        // Only allow editing categories created by this partner
        if ((int)($cat['created_by_partner_id'] ?? 0) !== $partner_id) {
            response(false, null, "Sem permissao para editar esta categoria", 403);
        }

        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)));
        $slug = trim($slug, '-');

        $stmt = $db->prepare("UPDATE om_market_categories SET name = ?, slug = ? WHERE category_id = ? AND created_by_partner_id = ?");
        $stmt->execute([$name, $slug, $category_id, $partner_id]);

        om_audit()->log(
            OmAudit::ACTION_UPDATE,
            'category',
            $category_id,
            null,
            ['name' => $name],
            "Categoria #{$category_id} atualizada pelo parceiro #{$partner_id}",
            'partner',
            $partner_id
        );

        response(true, [
            "category_id" => $category_id,
            "name" => $name,
            "slug" => $slug
        ], "Categoria atualizada");
    }

    // ── DELETE: Remover categoria (somente as criadas por este parceiro) ──
    elseif ($method === "DELETE") {
        $category_id = (int)($_GET['id'] ?? 0);

        if (!$category_id) {
            response(false, null, "ID da categoria e obrigatorio", 400);
        }

        // Verificar se existe E pertence a este parceiro
        $stmtCheck = $db->prepare("SELECT name, created_by_partner_id FROM om_market_categories WHERE category_id = ?");
        $stmtCheck->execute([$category_id]);
        $cat = $stmtCheck->fetch();
        if (!$cat) {
            response(false, null, "Categoria nao encontrada", 404);
        }

        // Only allow deleting categories created by this partner
        if ((int)($cat['created_by_partner_id'] ?? 0) !== $partner_id) {
            response(false, null, "Sem permissao para excluir esta categoria", 403);
        }

        // Verificar se tem produtos usando
        $stmtProducts = $db->prepare("SELECT COUNT(*) as total FROM om_market_products_base WHERE category_id = ?");
        $stmtProducts->execute([$category_id]);
        $productCount = (int)$stmtProducts->fetch()['total'];

        if ($productCount > 0) {
            response(false, null, "Nao e possivel excluir: {$productCount} produto(s) usam esta categoria", 400);
        }

        // Verificar se tem subcategorias
        $stmtSub = $db->prepare("SELECT COUNT(*) as total FROM om_market_categories WHERE parent_id = ? AND status = 1");
        $stmtSub->execute([$category_id]);
        $subCount = (int)$stmtSub->fetch()['total'];

        if ($subCount > 0) {
            response(false, null, "Nao e possivel excluir: categoria tem {$subCount} subcategoria(s)", 400);
        }

        // Soft delete (status = 0) — with partner_id guard
        $stmt = $db->prepare("UPDATE om_market_categories SET status = 0 WHERE category_id = ? AND created_by_partner_id = ?");
        $stmt->execute([$category_id, $partner_id]);

        om_audit()->log(
            OmAudit::ACTION_DELETE,
            'category',
            $category_id,
            null,
            ['name' => $cat['name']],
            "Categoria '{$cat['name']}' removida pelo parceiro #{$partner_id}",
            'partner',
            $partner_id
        );

        response(true, ["category_id" => $category_id], "Categoria removida");
    }

    else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/categories] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
