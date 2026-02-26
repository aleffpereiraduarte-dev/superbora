<?php
/**
 * GET /api/mercado/produtos/listar.php?partner_id=1&category_id=2
 * Lista produtos do mercado
 * Otimizado com cache (TTL: 5 min) e prepared statements
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=300');

try {
    $partner_id = (int)($_GET["partner_id"] ?? 0);
    $category_id = isset($_GET["category_id"]) && $_GET["category_id"] !== "" ? (int)$_GET["category_id"] : null;
    $busca = isset($_GET["q"]) ? trim($_GET["q"]) : null;
    $pagina = max(1, (int)($_GET["pagina"] ?? 1));
    $limite = min(100, max(1, (int)($_GET["limite"] ?? 50)));
    $offset = ($pagina - 1) * $limite;

    // Cache key baseado nos parâmetros
    $cacheKey = "mercado_produtos_" . md5(json_encode([
        $partner_id, $category_id, $busca, $pagina, $limite
    ]));

    $data = CacheHelper::remember($cacheKey, 300, function() use ($partner_id, $category_id, $busca, $pagina, $limite, $offset) {
        $db = getDB();

        $where = ["p.status::text = '1'", "(p.available::text = '1' OR p.available IS NULL)"];
        $params = [];

        if ($partner_id) {
            $where[] = "p.partner_id = ?";
            $params[] = $partner_id;
        }
        if ($category_id) {
            $where[] = "p.category_id = ?";
            $params[] = $category_id;
        }
        if ($busca) {
            $buscaEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $busca);
            $where[] = "(p.name ILIKE ? OR p.description ILIKE ?)";
            $params[] = "%{$buscaEscaped}%";
            $params[] = "%{$buscaEscaped}%";
        }

        $whereSQL = implode(" AND ", $where);

        // Query com LIMIT/OFFSET interpolados (valores já são int, seguro)
        $sql = "SELECT p.id, p.product_id, p.name, p.description, p.price, p.special_price,
                       p.image, p.unit, p.quantity, p.category_id, p.available, p.partner_id,
                       c.name as categoria_nome
                FROM om_market_products p
                LEFT JOIN om_market_categories c ON p.category_id = c.category_id
                WHERE $whereSQL
                ORDER BY p.name
                LIMIT ? OFFSET ?";

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$limite, $offset]));
        $produtos = $stmt->fetchAll();

        // Count total
        $countStmt = $db->prepare("SELECT COUNT(*) FROM om_market_products p WHERE $whereSQL");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        // Buscar opcoes para cada produto
        $productIds = array_map(function($p) {
            return $p["product_id"] ?? $p["id"];
        }, $produtos);

        $optionGroups = [];
        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmtGroups = $db->prepare("
                SELECT g.*, o.id as option_id, o.name as option_name, o.price_extra, o.available as option_available, o.sort_order as option_sort
                FROM om_product_option_groups g
                LEFT JOIN om_product_options o ON g.id = o.group_id
                WHERE g.product_id IN ($placeholders) AND g.active = 1
                ORDER BY g.sort_order, g.id, o.sort_order, o.id
            ");
            $stmtGroups->execute($productIds);
            $rows = $stmtGroups->fetchAll();

            foreach ($rows as $row) {
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
                        'price_extra' => floatval($row['price_extra'])
                    ];
                }
            }
        }

        return [
            "total" => (int)$total,
            "pagina" => $pagina,
            "produtos" => array_map(function($p) use ($optionGroups) {
                $pid = $p["product_id"] ?? $p["id"];
                $groups = isset($optionGroups[$pid]) ? array_values($optionGroups[$pid]) : [];

                return [
                    "id" => $pid,
                    "nome" => $p["name"],
                    "descricao" => $p["description"],
                    "preco" => floatval($p["price"]),
                    "preco_original" => floatval($p["special_price"] ?: $p["price"]),
                    "imagem" => $p["image"],
                    "categoria" => $p["categoria_nome"],
                    "unidade" => $p["unit"] ?? "un",
                    "estoque" => $p["quantity"] ?? 999,
                    "disponivel" => ($p["quantity"] ?? 999) > 0,
                    "option_groups" => $groups
                ];
            }, $produtos)
        ];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Mercado Produtos Listar] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar produtos. Tente novamente.", 500);
}
