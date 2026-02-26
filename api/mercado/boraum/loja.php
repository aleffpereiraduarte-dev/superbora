<?php
/**
 * GET /api/mercado/boraum/loja.php
 *
 * Detalhe de uma loja com cardapio completo (categorias, promocoes, produtos paginados).
 *
 * Parametros:
 *   id           - ID do parceiro (obrigatorio)
 *   lat, lng     - Coordenadas do usuario (opcional, calcula distancia)
 *   category_id  - Filtrar produtos por categoria
 *   q            - Busca por nome do produto
 *   page         - Pagina de produtos (default 1, 30 itens/pagina)
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
    $partnerId = (int)($_GET['id'] ?? 0);
    if ($partnerId <= 0) {
        response(false, null, "Parametro 'id' e obrigatorio.", 400);
    }

    $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
    $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
    $search = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 30;

    $hasCoords = ($lat !== null && $lng !== null);

    // ===================================================================
    // 1. Buscar dados do parceiro
    // ===================================================================
    $stmtPartner = $db->prepare("
        SELECT
            p.partner_id, p.name, p.trade_name, p.logo, p.banner, p.categoria,
            p.description, p.address, p.city, p.state, p.phone,
            p.open_time, p.close_time, p.is_open, p.rating,
            p.delivery_fee, p.delivery_time_min, p.min_order, p.free_delivery_above,
            p.lat, p.lng
        FROM om_market_partners p
        WHERE p.partner_id = ? AND p.status = '1'
        LIMIT 1
    ");
    $stmtPartner->execute([$partnerId]);
    $partner = $stmtPartner->fetch();

    if (!$partner) {
        response(false, null, "Loja nao encontrada ou inativa.", 404);
    }

    // Calcular distancia
    $distancia = null;
    if ($hasCoords && $partner['lat'] && $partner['lng']) {
        $distancia = 6371 * acos(
            min(1, max(-1,
                cos(deg2rad($lat)) * cos(deg2rad((float)$partner['lat'])) *
                cos(deg2rad((float)$partner['lng']) - deg2rad($lng))
                + sin(deg2rad($lat)) * sin(deg2rad((float)$partner['lat']))
            ))
        );
        $distancia = round($distancia, 1);
    }

    $parceiro = [
        'id'                    => (int)$partner['partner_id'],
        'nome'                  => $partner['name'] ?? $partner['trade_name'],
        'logo'                  => $partner['logo'] ?: null,
        'banner'                => $partner['banner'] ?: null,
        'categoria'             => $partner['categoria'] ?? null,
        'descricao'             => $partner['description'] ?? null,
        'aberto'                => (bool)($partner['is_open'] ?? false),
        'avaliacao'             => round((float)($partner['rating'] ?? 0), 1),
        'taxa_entrega'          => round((float)($partner['delivery_fee'] ?? 0), 2),
        'tempo_estimado'        => (int)($partner['delivery_time_min'] ?? 0),
        'pedido_minimo'         => round((float)($partner['min_order'] ?? 0), 2),
        'entrega_gratis_acima'  => $partner['free_delivery_above'] !== null ? round((float)$partner['free_delivery_above'], 2) : null,
        'distancia_km'          => $distancia,
        'horario'               => [
            'abertura'   => $partner['open_time'] ?? null,
            'fechamento' => $partner['close_time'] ?? null,
        ],
    ];

    // ===================================================================
    // 2. Categorias com contagem de produtos
    // ===================================================================
    $stmtCats = $db->prepare("
        SELECT
            c.category_id,
            c.name,
            COUNT(pr.id) AS total
        FROM om_market_categories c
        INNER JOIN om_market_products pr ON pr.category_id = c.category_id AND pr.status = '1'
        WHERE pr.partner_id = ?
        GROUP BY c.category_id, c.name
        ORDER BY c.name ASC
    ");
    $stmtCats->execute([$partnerId]);
    $catRows = $stmtCats->fetchAll();

    $categorias = [];
    foreach ($catRows as $c) {
        $categorias[] = [
            'id'    => (int)$c['category_id'],
            'nome'  => $c['name'],
            'total' => (int)$c['total'],
        ];
    }

    // ===================================================================
    // 3. Promocoes (special_price > 0 AND special_price < price)
    // ===================================================================
    $stmtPromos = $db->prepare("
        SELECT
            pr.id, pr.name, pr.price, pr.special_price, pr.image
        FROM om_market_products pr
        WHERE pr.partner_id = ?
          AND pr.status = '1'
          AND pr.special_price > 0
          AND pr.special_price < pr.price
        ORDER BY (1 - pr.special_price / pr.price) DESC
        LIMIT 20
    ");
    $stmtPromos->execute([$partnerId]);
    $promoRows = $stmtPromos->fetchAll();

    $promocoes = [];
    foreach ($promoRows as $pr) {
        $preco = (float)$pr['price'];
        $promoPrice = (float)$pr['special_price'];
        $desconto = $preco > 0 ? round((1 - $promoPrice / $preco) * 100) : 0;
        $promocoes[] = [
            'id'          => (int)$pr['id'],
            'nome'        => $pr['name'],
            'preco'       => round($preco, 2),
            'preco_promo' => round($promoPrice, 2),
            'desconto'    => (int)$desconto,
            'imagem'      => $pr['image'] ?: null,
        ];
    }

    // ===================================================================
    // 4. Produtos paginados (com filtros opcionais)
    // ===================================================================
    $prodWhere = ["pr.partner_id = ?", "pr.status = '1'"];
    $prodParams = [$partnerId];

    if ($categoryId !== null) {
        $prodWhere[] = "pr.category_id = ?";
        $prodParams[] = $categoryId;
    }

    if ($search !== '') {
        $searchEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $prodWhere[] = "pr.name ILIKE ?";
        $prodParams[] = '%' . $searchEscaped . '%';
    }

    $prodWhereSql = implode(' AND ', $prodWhere);

    // Total de produtos
    $stmtProdCount = $db->prepare("SELECT COUNT(*) FROM om_market_products pr WHERE {$prodWhereSql}");
    $stmtProdCount->execute($prodParams);
    $totalProdutos = (int)$stmtProdCount->fetchColumn();

    // Buscar pagina de produtos
    $offset = ($page - 1) * $perPage;
    $stmtProds = $db->prepare("
        SELECT
            pr.id, pr.name, pr.description, pr.price, pr.special_price,
            pr.image, pr.unit, pr.quantity, pr.category_id, pr.available,
            c.name AS category_name
        FROM om_market_products pr
        LEFT JOIN om_market_categories c ON c.category_id = pr.category_id
        WHERE {$prodWhereSql}
        ORDER BY c.name ASC, pr.name ASC
        LIMIT ? OFFSET ?
    ");
    $stmtProds->execute(array_merge($prodParams, [$perPage, $offset]));
    $prodRows = $stmtProds->fetchAll();

    // ===================================================================
    // 5. Option groups para os produtos desta pagina
    // ===================================================================
    $productIds = array_column($prodRows, 'id');
    $optionsByProduct = [];

    if (count($productIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmtOpts = $db->prepare("
            SELECT
                g.id AS group_id, g.product_id, g.name AS group_name,
                g.required, g.min_select, g.max_select, g.sort_order AS group_sort,
                o.id AS option_id, o.name AS option_name, o.image AS option_image,
                o.description AS option_description, o.price_extra, o.available AS option_available
            FROM om_product_option_groups g
            LEFT JOIN om_product_options o ON g.id = o.group_id
            WHERE g.product_id IN ({$placeholders}) AND g.active = 1
            ORDER BY g.sort_order, g.id, o.sort_order, o.id
        ");
        $stmtOpts->execute($productIds);
        $optRows = $stmtOpts->fetchAll();

        // Agrupar por product_id -> group_id
        foreach ($optRows as $opt) {
            $pid = (int)$opt['product_id'];
            $gid = (int)$opt['group_id'];

            if (!isset($optionsByProduct[$pid])) {
                $optionsByProduct[$pid] = [];
            }
            if (!isset($optionsByProduct[$pid][$gid])) {
                $optionsByProduct[$pid][$gid] = [
                    'id'          => $gid,
                    'nome'        => $opt['group_name'],
                    'obrigatorio' => (bool)$opt['required'],
                    'min'         => (int)$opt['min_select'],
                    'max'         => (int)$opt['max_select'],
                    'opcoes'      => [],
                ];
            }

            // Adicionar opcao (se existir - LEFT JOIN pode trazer null)
            if ($opt['option_id'] !== null) {
                $optionsByProduct[$pid][$gid]['opcoes'][] = [
                    'id'          => (int)$opt['option_id'],
                    'nome'        => $opt['option_name'],
                    'imagem'      => $opt['option_image'] ?: null,
                    'descricao'   => $opt['option_description'] ?: null,
                    'preco_extra' => round((float)($opt['price_extra'] ?? 0), 2),
                    'disponivel'  => (bool)($opt['option_available'] ?? true),
                ];
            }
        }
    }

    // Formatar lista de produtos
    $itens = [];
    foreach ($prodRows as $pr) {
        $pid = (int)$pr['id'];
        $preco = (float)$pr['price'];
        $specialPrice = ($pr['special_price'] !== null && (float)$pr['special_price'] > 0 && (float)$pr['special_price'] < $preco)
            ? round((float)$pr['special_price'], 2)
            : null;

        // Converter option groups de assoc para array indexado
        $groups = isset($optionsByProduct[$pid]) ? array_values($optionsByProduct[$pid]) : [];

        $itens[] = [
            'id'             => $pid,
            'nome'           => $pr['name'],
            'descricao'      => $pr['description'] ?? null,
            'preco'          => round($preco, 2),
            'preco_promo'    => $specialPrice,
            'imagem'         => $pr['image'] ?: null,
            'categoria'      => $pr['category_name'] ?? null,
            'categoria_id'   => $pr['category_id'] !== null ? (int)$pr['category_id'] : null,
            'unidade'        => $pr['unit'] ?? 'un',
            'estoque'        => (int)($pr['quantity'] ?? 0),
            'disponivel'     => (bool)($pr['available'] ?? true),
            'option_groups'  => $groups,
        ];
    }

    // ===================================================================
    // 6. Montar resposta final
    // ===================================================================
    response(true, [
        'parceiro'   => $parceiro,
        'categorias' => $categorias,
        'promocoes'  => $promocoes,
        'produtos'   => [
            'total'     => $totalProdutos,
            'pagina'    => $page,
            'por_pagina' => $perPage,
            'itens'     => $itens,
        ],
    ]);

} catch (Exception $e) {
    error_log("[BoraUm loja.php] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar loja. Tente novamente.", 500);
}
