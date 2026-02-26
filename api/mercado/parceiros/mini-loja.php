<?php
/**
 * GET /api/mercado/parceiros/mini-loja.php?id=X&category_id=Y&page=1&q=
 * Composite API: partner details + categories + promotions + paginated products
 * Single request for mini-store modal
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=120');

try {
    $partner_id = (int)($_GET["id"] ?? 0);
    if (!$partner_id) {
        response(false, null, "ID do parceiro obrigatório", 400);
    }

    $category_id = isset($_GET["category_id"]) && $_GET["category_id"] !== "" ? (int)$_GET["category_id"] : null;
    $page = max(1, (int)($_GET["page"] ?? 1));
    $q = isset($_GET["q"]) ? trim($_GET["q"]) : null;
    $limit = 30;
    $offset = ($page - 1) * $limit;

    // B5: Filtros avancados de produto
    $price_min = isset($_GET["price_min"]) && $_GET["price_min"] !== "" ? (float)$_GET["price_min"] : null;
    $price_max = isset($_GET["price_max"]) && $_GET["price_max"] !== "" ? (float)$_GET["price_max"] : null;
    $on_sale = isset($_GET["on_sale"]) && $_GET["on_sale"] === "1";

    $cacheKey = "mini_loja_" . md5(json_encode([$partner_id, $category_id, $page, $q, $price_min, $price_max, $on_sale]));

    $data = CacheHelper::remember($cacheKey, 120, function() use ($partner_id, $category_id, $page, $q, $limit, $offset, $price_min, $price_max, $on_sale) {
        $db = getDB();

        // 1. Partner details
        $stmt = $db->prepare("
            SELECT p.partner_id, p.name, p.trade_name, p.logo, p.categoria,
                   p.address, p.city, p.state, p.phone,
                   p.open_time, p.close_time, p.is_open,
                   p.rating, p.delivery_fee, p.delivery_time_min,
                   p.min_order, p.free_delivery_above,
                   p.description, p.banner
            FROM om_market_partners p
            WHERE p.partner_id = ? AND p.status::text = '1'
        ");
        $stmt->execute([$partner_id]);
        $partner = $stmt->fetch();

        if (!$partner) {
            return null;
        }

        $parceiro = [
            "id" => (int)$partner["partner_id"],
            "nome" => $partner["name"] ?? $partner["trade_name"] ?? "",
            "logo" => $partner["logo"] ?? null,
            "banner" => $partner["banner"] ?? null,
            "categoria" => $partner["categoria"] ?? "mercado",
            "descricao" => $partner["description"] ?? "",
            "endereco" => $partner["address"] ?? "",
            "cidade" => $partner["city"] ?? "",
            "estado" => $partner["state"] ?? "",
            "telefone" => $partner["phone"] ?? "",
            "horario_abertura" => $partner["open_time"] ?? null,
            "horario_fechamento" => $partner["close_time"] ?? null,
            "aberto" => (int)($partner["is_open"] ?? 0) === 1,
            "avaliacao" => (float)($partner["rating"] ?? 5.0),
            "taxa_entrega" => (float)($partner["delivery_fee"] ?? 0),
            "tempo_estimado" => (int)($partner["delivery_time_min"] ?? 60),
            "pedido_minimo" => (float)($partner["min_order"] ?? 0),
            "entrega_gratis_acima" => $partner["free_delivery_above"] ? (float)$partner["free_delivery_above"] : null,
            "horario_funcionamento" => [
                "abertura" => $partner["open_time"] ?? null,
                "fechamento" => $partner["close_time"] ?? null
            ]
        ];

        // 2. Categories with product count
        $stmt = $db->prepare("
            SELECT c.category_id, c.name,
                   COUNT(pr.product_id) as total_produtos
            FROM om_market_categories c
            INNER JOIN om_market_products pr ON pr.category_id = c.category_id
            WHERE pr.partner_id = ? AND pr.status::text = '1' AND (pr.available::text = '1' OR pr.available IS NULL)
            GROUP BY c.category_id, c.name
            HAVING COUNT(pr.product_id) > 0
            ORDER BY c.name
        ");
        $stmt->execute([$partner_id]);
        $categorias = [];
        foreach ($stmt->fetchAll() as $cat) {
            $categorias[] = [
                "id" => (int)$cat["category_id"],
                "nome" => $cat["name"],
                "total" => (int)$cat["total_produtos"]
            ];
        }

        // 3. Promotions (products with special_price > 0 and special_price < price)
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price, p.special_price, p.image, p.unit, p.quantity
            FROM om_market_products p
            WHERE p.partner_id = ? AND p.status::text = '1'
              AND (p.available::text = '1' OR p.available IS NULL)
              AND p.special_price IS NOT NULL AND p.special_price > 0 AND p.special_price < p.price
            ORDER BY (1 - p.special_price / NULLIF(p.price, 0)) DESC
            LIMIT 20
        ");
        $stmt->execute([$partner_id]);
        $promocoes = [];
        foreach ($stmt->fetchAll() as $promo) {
            $promocoes[] = [
                "id" => (int)$promo["product_id"],
                "nome" => $promo["name"],
                "preco" => (float)$promo["price"],
                "preco_promo" => (float)$promo["special_price"],
                "desconto" => $promo["price"] > 0 ? round((1 - $promo["special_price"] / $promo["price"]) * 100) : 0,
                "imagem" => $promo["image"],
                "unidade" => $promo["unit"] ?? "un",
                "estoque" => (int)($promo["quantity"] ?? 999)
            ];
        }

        // 4. Products (paginated, filtered by category/search)
        $where = ["p.partner_id = ?", "p.status::text = '1'", "(p.available::text = '1' OR p.available IS NULL)"];
        $params = [$partner_id];

        if ($category_id) {
            $where[] = "p.category_id = ?";
            $params[] = $category_id;
        }
        if ($q) {
            $qEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $q);
            $where[] = "(p.name LIKE ? OR p.description LIKE ?)";
            $params[] = "%{$qEscaped}%";
            $params[] = "%{$qEscaped}%";
        }

        // B5: Filtros avancados
        if ($price_min !== null) {
            $where[] = "COALESCE(p.special_price, p.price) >= ?";
            $params[] = $price_min;
        }
        if ($price_max !== null) {
            $where[] = "COALESCE(p.special_price, p.price) <= ?";
            $params[] = $price_max;
        }
        if ($on_sale) {
            $where[] = "p.special_price IS NOT NULL AND p.special_price > 0 AND p.special_price < p.price";
        }

        $whereSQL = implode(" AND ", $where);

        // Count total
        $countStmt = $db->prepare("SELECT COUNT(*) FROM om_market_products p WHERE $whereSQL");
        $countStmt->execute($params);
        $total_produtos = (int)$countStmt->fetchColumn();

        // Fetch products
        $sql = "SELECT p.product_id, p.name, p.description, p.price, p.special_price,
                       p.image, p.unit, p.quantity, p.category_id,
                       c.name as categoria_nome
                FROM om_market_products p
                LEFT JOIN om_market_categories c ON p.category_id = c.category_id
                WHERE $whereSQL
                ORDER BY p.name
                LIMIT ? OFFSET ?";

        $productParams = array_merge($params, [$limit, $offset]);
        $stmt = $db->prepare($sql);
        $stmt->execute($productParams);
        $rawProdutos = $stmt->fetchAll();

        // Fetch option groups for these products
        $productIds = array_map(function($p) { return $p["product_id"]; }, $rawProdutos);
        $optionGroups = [];
        if (!empty($productIds)) {
            $ph = implode(',', array_fill(0, count($productIds), '?'));
            $stmtOg = $db->prepare("
                SELECT g.*, o.id as option_id, o.name as option_name, o.price_extra, o.available as option_available, o.sort_order as option_sort
                FROM om_product_option_groups g
                LEFT JOIN om_product_options o ON g.id = o.group_id
                WHERE g.product_id IN ($ph) AND g.active::text = '1'
                ORDER BY g.sort_order, g.id, o.sort_order, o.id
            ");
            $stmtOg->execute($productIds);
            foreach ($stmtOg->fetchAll() as $row) {
                $pid = $row['product_id'];
                $gid = $row['id'];
                if (!isset($optionGroups[$pid][$gid])) {
                    $optionGroups[$pid][$gid] = [
                        'id' => $gid,
                        'name' => $row['name'],
                        'required' => (bool)$row['required'],
                        'min_select' => (int)$row['min_select'],
                        'max_select' => (int)$row['max_select'],
                        'options' => []
                    ];
                }
                if ($row['option_id'] && $row['option_available']) {
                    $optionGroups[$pid][$gid]['options'][] = [
                        'id' => (int)$row['option_id'],
                        'name' => $row['option_name'],
                        'price_extra' => (float)$row['price_extra']
                    ];
                }
            }
        }

        $produtos = [];
        foreach ($rawProdutos as $p) {
            $preco = (float)$p["price"];
            $promoPreco = $p["special_price"] ? (float)$p["special_price"] : null;
            $emPromocao = $promoPreco && $promoPreco > 0 && $promoPreco < $preco;
            $pid = (int)$p["product_id"];
            $groups = isset($optionGroups[$pid]) ? array_values($optionGroups[$pid]) : [];

            $produtos[] = [
                "id" => $pid,
                "nome" => $p["name"],
                "descricao" => $p["description"] ?? "",
                "preco" => $preco,
                "preco_promo" => $emPromocao ? $promoPreco : null,
                "imagem" => $p["image"],
                "categoria" => $p["categoria_nome"],
                "categoria_id" => (int)$p["category_id"],
                "unidade" => $p["unit"] ?? "un",
                "estoque" => (int)($p["quantity"] ?? 999),
                "disponivel" => ((int)($p["quantity"] ?? 999)) > 0,
                "option_groups" => $groups
            ];
        }

        return [
            "parceiro" => $parceiro,
            "categorias" => $categorias,
            "promocoes" => $promocoes,
            "produtos" => [
                "total" => $total_produtos,
                "pagina" => $page,
                "por_pagina" => $limit,
                "itens" => $produtos
            ]
        ];
    });

    if ($data === null) {
        response(false, null, "Estabelecimento não encontrado", 404);
    }

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Mini-Loja] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar dados do estabelecimento", 500);
}
