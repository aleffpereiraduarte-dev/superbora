<?php
/**
 * /api/mercado/admin/product-admin.php
 *
 * Painel administrativo - Gerenciamento de produtos dos parceiros.
 *
 * GET  ?partner_id=X&page=1&search=  - Listar produtos de um parceiro com paginacao
 *
 * POST action=edit_price     { product_id, price, special_price? }                    - Atualizar preco
 * POST action=toggle_stock   { product_id, in_stock: bool }                           - Alternar disponibilidade
 * POST action=edit_product   { product_id, name?, description?, price?, category_id?, image? } - Editar produto
 * POST action=delete_product { product_id }                                           - Soft delete (status='deleted')
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

    // =================== GET ===================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $partner_id = (int)($_GET['partner_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $search = trim($_GET['search'] ?? '');
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);

        // Verificar se parceiro existe
        $stmt = $db->prepare("SELECT partner_id, name FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partner_id]);
        $partner = $stmt->fetch();
        if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

        $where = ["p.partner_id = ?"];
        $params = [$partner_id];

        // Excluir deletados por padrao
        $where[] = "COALESCE(p.status::text, '1') != 'deleted'";

        // Busca por nome
        if ($search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $where[] = "p.name ILIKE ?";
            $params[] = "%{$escaped}%";
        }

        $where_sql = implode(' AND ', $where);

        // Contagem total
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_products p WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        // Buscar produtos
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.description, p.price, p.special_price,
                   p.image, p.quantity, p.status, p.unit,
                   p.category_id,
                   c.name as category_name,
                   p.created_at, p.updated_at
            FROM om_market_products p
            LEFT JOIN om_market_categories c ON p.category_id = c.category_id
            WHERE {$where_sql}
            ORDER BY p.name ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        // Cast numeric fields
        foreach ($products as &$product) {
            $product['product_id'] = (int)$product['product_id'];
            $product['price'] = (float)$product['price'];
            $product['special_price'] = $product['special_price'] ? (float)$product['special_price'] : null;
            $product['quantity'] = (int)$product['quantity'];
            $product['category_id'] = $product['category_id'] ? (int)$product['category_id'] : null;
        }
        unset($product);

        response(true, [
            'partner' => $partner,
            'products' => $products,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit)
            ]
        ], "Produtos listados");
    }

    // =================== POST ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? $_GET['action'] ?? '');

        if (!$action) response(false, null, "Parametro 'action' obrigatorio", 400);

        // --- Atualizar preco ---
        if ($action === 'edit_price') {
            $product_id = (int)($input['product_id'] ?? 0);
            $price = isset($input['price']) ? (float)$input['price'] : null;
            $special_price = isset($input['special_price']) ? (float)$input['special_price'] : null;

            if (!$product_id) response(false, null, "product_id obrigatorio", 400);
            if ($price === null || $price < 0) response(false, null, "price obrigatorio e deve ser >= 0", 400);
            if ($special_price !== null && $special_price < 0) response(false, null, "special_price deve ser >= 0", 400);
            if ($special_price !== null && $special_price >= $price) response(false, null, "special_price deve ser menor que price", 400);

            $db->beginTransaction();

            // Buscar produto atual com lock
            $stmt = $db->prepare("
                SELECT product_id, partner_id, name, price, special_price
                FROM om_market_products
                WHERE product_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();
            if (!$product) {
                $db->rollBack();
                response(false, null, "Produto nao encontrado", 404);
            }

            $old_data = [
                'price' => (float)$product['price'],
                'special_price' => $product['special_price'] ? (float)$product['special_price'] : null
            ];

            $stmt = $db->prepare("
                UPDATE om_market_products
                SET price = ?,
                    special_price = ?,
                    updated_at = NOW()
                WHERE product_id = ?
            ");
            $stmt->execute([$price, $special_price, $product_id]);

            $db->commit();

            $new_data = [
                'price' => $price,
                'special_price' => $special_price
            ];

            // Registro de auditoria
            om_audit()->log(
                'product_edit_price',
                'product',
                $product_id,
                $old_data,
                $new_data,
                "Preco do produto '{$product['name']}' (parceiro #{$product['partner_id']}) alterado pelo admin #{$admin_id}"
            );

            response(true, [
                'product_id' => $product_id,
                'action' => 'edit_price',
                'old_price' => $old_data['price'],
                'new_price' => $price,
                'old_special_price' => $old_data['special_price'],
                'new_special_price' => $special_price
            ], "Preco atualizado com sucesso");
        }

        // --- Alternar disponibilidade ---
        if ($action === 'toggle_stock') {
            $product_id = (int)($input['product_id'] ?? 0);
            $in_stock = isset($input['in_stock']) ? (bool)$input['in_stock'] : null;

            if (!$product_id) response(false, null, "product_id obrigatorio", 400);
            if ($in_stock === null) response(false, null, "in_stock obrigatorio (true/false)", 400);

            $db->beginTransaction();

            // Buscar produto atual com lock
            $stmt = $db->prepare("
                SELECT product_id, partner_id, name, status, quantity
                FROM om_market_products
                WHERE product_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();
            if (!$product) {
                $db->rollBack();
                response(false, null, "Produto nao encontrado", 404);
            }

            $old_status = $product['status'];
            $old_quantity = (int)$product['quantity'];

            // Determinar novo status e quantidade
            $new_status = $in_stock ? '1' : '0';
            $new_quantity = $in_stock ? max(1, $old_quantity) : 0;

            $stmt = $db->prepare("
                UPDATE om_market_products
                SET status = ?,
                    quantity = ?,
                    updated_at = NOW()
                WHERE product_id = ?
            ");
            $stmt->execute([$new_status, $new_quantity, $product_id]);

            $db->commit();

            // Registro de auditoria
            om_audit()->log(
                'product_toggle_stock',
                'product',
                $product_id,
                ['status' => $old_status, 'quantity' => $old_quantity],
                ['status' => $new_status, 'quantity' => $new_quantity, 'in_stock' => $in_stock],
                "Disponibilidade do produto '{$product['name']}' (parceiro #{$product['partner_id']}) alterada para " . ($in_stock ? 'disponivel' : 'indisponivel') . " pelo admin #{$admin_id}"
            );

            response(true, [
                'product_id' => $product_id,
                'action' => 'toggle_stock',
                'in_stock' => $in_stock,
                'new_status' => $new_status,
                'new_quantity' => $new_quantity
            ], $in_stock ? "Produto marcado como disponivel" : "Produto marcado como indisponivel");
        }

        // --- Editar produto ---
        if ($action === 'edit_product') {
            $product_id = (int)($input['product_id'] ?? 0);

            if (!$product_id) response(false, null, "product_id obrigatorio", 400);

            $db->beginTransaction();

            // Buscar produto atual com lock
            $stmt = $db->prepare("
                SELECT product_id, partner_id, name, description, price, category_id, image
                FROM om_market_products
                WHERE product_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();
            if (!$product) {
                $db->rollBack();
                response(false, null, "Produto nao encontrado", 404);
            }

            // Campos editaveis
            $editable_fields = [
                'name' => 'name',
                'description' => 'description',
                'price' => 'price',
                'category_id' => 'category_id',
                'image' => 'image',
            ];

            $updates = [];
            $params = [];
            $old_data = [];
            $new_data = [];

            foreach ($editable_fields as $input_key => $db_column) {
                if (isset($input[$input_key]) && $input[$input_key] !== '') {
                    $value = $input[$input_key];

                    // Validar campos numericos
                    if ($input_key === 'price') {
                        $value = (float)$value;
                        if ($value < 0) {
                            $db->rollBack();
                            response(false, null, "price nao pode ser negativo", 400);
                        }
                    } elseif ($input_key === 'category_id') {
                        $value = (int)$value;
                        if ($value <= 0) {
                            $db->rollBack();
                            response(false, null, "category_id invalido", 400);
                        }
                    } else {
                        $value = trim($value);
                    }

                    $old_data[$db_column] = $product[$db_column] ?? null;
                    $new_data[$db_column] = $value;
                    $updates[] = "{$db_column} = ?";
                    $params[] = $value;
                }
            }

            if (empty($updates)) {
                $db->rollBack();
                response(false, null, "Nenhum campo fornecido para atualizar", 400);
            }

            $updates[] = "updated_at = NOW()";
            $params[] = $product_id;

            $sql = "UPDATE om_market_products SET " . implode(', ', $updates) . " WHERE product_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $db->commit();

            // Registro de auditoria
            om_audit()->log(
                'product_edit',
                'product',
                $product_id,
                $old_data,
                $new_data,
                "Produto '{$product['name']}' (parceiro #{$product['partner_id']}) editado pelo admin #{$admin_id}. Campos: " . implode(', ', array_keys($new_data))
            );

            response(true, [
                'product_id' => $product_id,
                'action' => 'edit_product',
                'updated_fields' => array_keys($new_data)
            ], "Produto atualizado com sucesso");
        }

        // --- Soft delete produto ---
        if ($action === 'delete_product') {
            $product_id = (int)($input['product_id'] ?? 0);

            if (!$product_id) response(false, null, "product_id obrigatorio", 400);

            $db->beginTransaction();

            // Buscar produto atual com lock
            $stmt = $db->prepare("
                SELECT product_id, partner_id, name, status
                FROM om_market_products
                WHERE product_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();
            if (!$product) {
                $db->rollBack();
                response(false, null, "Produto nao encontrado", 404);
            }

            if ($product['status'] === 'deleted') {
                $db->rollBack();
                response(false, null, "Produto ja esta deletado", 400);
            }

            $old_status = $product['status'];

            $stmt = $db->prepare("
                UPDATE om_market_products
                SET status = 'deleted',
                    updated_at = NOW()
                WHERE product_id = ?
            ");
            $stmt->execute([$product_id]);

            $db->commit();

            // Registro de auditoria
            om_audit()->log(
                'product_delete',
                'product',
                $product_id,
                ['status' => $old_status],
                ['status' => 'deleted'],
                "Produto '{$product['name']}' (parceiro #{$product['partner_id']}) deletado (soft) pelo admin #{$admin_id}"
            );

            response(true, [
                'product_id' => $product_id,
                'action' => 'delete_product',
                'old_status' => $old_status,
                'new_status' => 'deleted'
            ], "Produto deletado com sucesso");
        }

        response(false, null, "Acao POST invalida: {$action}", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/product-admin] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
