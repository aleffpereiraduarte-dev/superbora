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
        $include_inactive = ($_GET['include_inactive'] ?? '0') === '1';
        $parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : null;

        $where = ["1=1"];
        $params = [];

        if (!$include_inactive) {
            $where[] = "status = '1'";
        }

        if ($parent_id !== null) {
            $where[] = "parent_id = ?";
            $params[] = $parent_id;
        }

        $where_sql = implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT category_id, parent_id, name, slug, icon, image, sort_order, status
            FROM om_market_categories
            WHERE {$where_sql}
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->execute($params);
        $categories = $stmt->fetchAll();

        // Get product counts per category
        $stmt2 = $db->query("
            SELECT category_id, COUNT(*) as product_count
            FROM om_market_products
            WHERE status = '1'
            GROUP BY category_id
        ");
        $counts = [];
        foreach ($stmt2->fetchAll() as $row) {
            $counts[(int)$row['category_id']] = (int)$row['product_count'];
        }

        foreach ($categories as &$cat) {
            $cat['product_count'] = $counts[(int)$cat['category_id']] ?? 0;
        }

        response(true, [
            'categories' => $categories,
            'total' => count($categories)
        ], "Categorias listadas");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $name = trim($input['name'] ?? '');
        $icon = trim($input['icon'] ?? '');
        $sort_order = (int)($input['sort_order'] ?? 0);
        $status = isset($input['status']) ? (int)$input['status'] : 1;
        $parent_id = (int)($input['parent_id'] ?? 0);
        $slug = trim($input['slug'] ?? '');
        $image = trim($input['image'] ?? '');

        if (!$name) {
            response(false, null, "Nome da categoria e obrigatorio", 400);
        }

        // Auto-generate slug if empty
        if (!$slug) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $name)));
            $slug = trim($slug, '-');
        }

        // Check for duplicate name
        $stmt = $db->prepare("SELECT category_id FROM om_market_categories WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            response(false, null, "Ja existe uma categoria com este nome", 400);
        }

        $stmt = $db->prepare("
            INSERT INTO om_market_categories (parent_id, name, slug, icon, image, sort_order, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$parent_id, $name, $slug, $icon, $image, $sort_order, $status]);
        $new_id = (int)$db->lastInsertId();

        om_audit()->log('create', 'category', $new_id, null, ['name' => $name]);
        response(true, ['category_id' => $new_id], "Categoria criada com sucesso");

    } elseif ($_SERVER["REQUEST_METHOD"] === "PUT") {
        $input = getInput();
        $category_id = (int)($input['category_id'] ?? 0);

        if (!$category_id) {
            response(false, null, "category_id e obrigatorio", 400);
        }

        // Check if category exists
        $stmt = $db->prepare("SELECT * FROM om_market_categories WHERE category_id = ?");
        $stmt->execute([$category_id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            response(false, null, "Categoria nao encontrada", 404);
        }

        $name = trim($input['name'] ?? $existing['name']);
        $icon = trim($input['icon'] ?? $existing['icon']);
        $sort_order = isset($input['sort_order']) ? (int)$input['sort_order'] : (int)$existing['sort_order'];
        $status = isset($input['status']) ? (int)$input['status'] : (int)$existing['status'];
        $parent_id = isset($input['parent_id']) ? (int)$input['parent_id'] : (int)$existing['parent_id'];
        $slug = trim($input['slug'] ?? $existing['slug']);
        $image = trim($input['image'] ?? $existing['image']);

        if (!$name) {
            response(false, null, "Nome da categoria e obrigatorio", 400);
        }

        // Check for duplicate name (excluding current)
        $stmt = $db->prepare("SELECT category_id FROM om_market_categories WHERE name = ? AND category_id != ? LIMIT 1");
        $stmt->execute([$name, $category_id]);
        if ($stmt->fetch()) {
            response(false, null, "Ja existe outra categoria com este nome", 400);
        }

        $stmt = $db->prepare("
            UPDATE om_market_categories
            SET parent_id = ?, name = ?, slug = ?, icon = ?, image = ?, sort_order = ?, status = ?
            WHERE category_id = ?
        ");
        $stmt->execute([$parent_id, $name, $slug, $icon, $image, $sort_order, $status, $category_id]);

        om_audit()->log('update', 'category', $category_id, null, ['name' => $name]);
        response(true, ['category_id' => $category_id], "Categoria atualizada com sucesso");

    } elseif ($_SERVER["REQUEST_METHOD"] === "DELETE") {
        $category_id = (int)($_GET['id'] ?? $_GET['category_id'] ?? 0);

        if (!$category_id) {
            response(false, null, "category_id e obrigatorio", 400);
        }

        // Check if category exists
        $stmt = $db->prepare("SELECT * FROM om_market_categories WHERE category_id = ?");
        $stmt->execute([$category_id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            response(false, null, "Categoria nao encontrada", 404);
        }

        // Soft delete: set status = '0'
        $stmt = $db->prepare("UPDATE om_market_categories SET status = '0' WHERE category_id = ?");
        $stmt->execute([$category_id]);

        om_audit()->log('delete', 'category', $category_id, null, ['name' => $existing['name']]);
        response(true, null, "Categoria desativada com sucesso");

    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/categories] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
