<?php
/**
 * GET /api/mercado/boraum/produto.php
 *
 * Detalhe de um produto com todos os grupos de opcao.
 *
 * Parametros:
 *   id - ID do produto (obrigatorio)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
setCorsHeaders();
$db = getDB();
$user = requirePassageiro($db);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido. Use GET.", 405);
}

try {
    $productId = (int)($_GET['id'] ?? 0);
    if ($productId <= 0) {
        response(false, null, "Parametro 'id' e obrigatorio.", 400);
    }

    // ===================================================================
    // 1. Buscar produto + nome da loja
    // ===================================================================
    $stmtProduct = $db->prepare("
        SELECT
            pr.id, pr.name, pr.description, pr.price, pr.special_price,
            pr.image, pr.unit, pr.quantity, pr.available, pr.partner_id,
            pa.name AS partner_name, pa.trade_name AS partner_trade_name
        FROM om_market_products pr
        INNER JOIN om_market_partners pa ON pa.partner_id = pr.partner_id
        WHERE pr.id = ? AND pr.status = '1'
        LIMIT 1
    ");
    $stmtProduct->execute([$productId]);
    $product = $stmtProduct->fetch();

    if (!$product) {
        response(false, null, "Produto nao encontrado ou indisponivel.", 404);
    }

    $preco = (float)$product['price'];
    $specialPrice = ($product['special_price'] !== null && (float)$product['special_price'] > 0 && (float)$product['special_price'] < $preco)
        ? round((float)$product['special_price'], 2)
        : null;

    // ===================================================================
    // 2. Buscar grupos de opcao + opcoes
    // ===================================================================
    $stmtGroups = $db->prepare("
        SELECT
            g.id AS group_id, g.name AS group_name, g.required,
            g.min_select, g.max_select, g.sort_order AS group_sort,
            o.id AS option_id, o.name AS option_name, o.image AS option_image,
            o.description AS option_description, o.price_extra,
            o.available AS option_available, o.sort_order AS option_sort
        FROM om_product_option_groups g
        LEFT JOIN om_product_options o ON g.id = o.group_id
        WHERE g.product_id = ? AND g.active = 1
        ORDER BY g.sort_order, g.id, o.sort_order, o.id
    ");
    $stmtGroups->execute([$productId]);
    $optRows = $stmtGroups->fetchAll();

    // Agrupar por group_id
    $groupsMap = [];
    foreach ($optRows as $row) {
        $gid = (int)$row['group_id'];

        if (!isset($groupsMap[$gid])) {
            $groupsMap[$gid] = [
                'id'          => $gid,
                'nome'        => $row['group_name'],
                'obrigatorio' => (bool)$row['required'],
                'min'         => (int)$row['min_select'],
                'max'         => (int)$row['max_select'],
                'opcoes'      => [],
            ];
        }

        // Adicionar opcao se existir (LEFT JOIN pode trazer null)
        if ($row['option_id'] !== null) {
            $groupsMap[$gid]['opcoes'][] = [
                'id'          => (int)$row['option_id'],
                'nome'        => $row['option_name'],
                'imagem'      => $row['option_image'] ?: null,
                'descricao'   => $row['option_description'] ?: null,
                'preco_extra' => round((float)($row['price_extra'] ?? 0), 2),
                'disponivel'  => (bool)($row['option_available'] ?? true),
            ];
        }
    }

    $optionGroups = array_values($groupsMap);

    // ===================================================================
    // 3. Montar resposta
    // ===================================================================
    response(true, [
        'id'            => (int)$product['id'],
        'nome'          => $product['name'],
        'descricao'     => $product['description'] ?? null,
        'preco'         => round($preco, 2),
        'preco_promo'   => $specialPrice,
        'imagem'        => $product['image'] ?: null,
        'unidade'       => $product['unit'] ?? 'un',
        'estoque'       => (int)($product['quantity'] ?? 0),
        'disponivel'    => (bool)($product['available'] ?? true),
        'loja'          => [
            'id'   => (int)$product['partner_id'],
            'nome' => $product['partner_name'] ?? $product['partner_trade_name'],
        ],
        'option_groups' => $optionGroups,
    ]);

} catch (Exception $e) {
    error_log("[BoraUm produto.php] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar produto. Tente novamente.", 500);
}
