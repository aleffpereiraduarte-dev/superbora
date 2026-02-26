<?php
/**
 * GET /api/mercado/boraum/lojas.php
 *
 * Lista lojas proximas ao usuario com filtros e ordenacao.
 *
 * Parametros:
 *   lat, lng       - Coordenadas do usuario (opcional, habilita distancia)
 *   raio           - Raio maximo em km (default 10, requer lat/lng)
 *   categoria      - Filtrar por tipo: mercado, restaurante, farmacia, loja, supermercado
 *   tag            - Filtrar por culinaria: acai, pizza, hamburguer, japonesa, etc (slug)
 *   busca          - Busca por nome da loja (LIKE)
 *   sort           - Ordenacao: distance (default c/ lat/lng), rating, delivery_time, price, name
 *   page           - Pagina (default 1)
 *   limit          - Itens por pagina (default 20, max 50)
 *   open_now       - Apenas abertas (1/0)
 *   free_delivery  - Apenas entrega gratis (1/0)
 *   rating_min     - Avaliacao minima (0-5)
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
    // --- Parametros ---
    $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
    $raio = max(1, min(100, (float)($_GET['raio'] ?? 10)));
    $categoria = trim($_GET['categoria'] ?? '');
    $tag = trim($_GET['tag'] ?? '');
    $busca = trim($_GET['busca'] ?? '');
    $sort = trim($_GET['sort'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $openNow = (int)($_GET['open_now'] ?? 0);
    $freeDelivery = (int)($_GET['free_delivery'] ?? 0);
    $ratingMin = max(0, min(5, (float)($_GET['rating_min'] ?? 0)));

    $hasCoords = ($lat !== null && $lng !== null);

    // Validar categoria
    $categoriasValidas = ['mercado', 'restaurante', 'farmacia', 'loja', 'supermercado'];
    if ($categoria !== '' && !in_array($categoria, $categoriasValidas, true)) {
        response(false, null, "Categoria invalida. Opcoes: " . implode(', ', $categoriasValidas), 400);
    }

    // --- Montar SQL ---
    $selectFields = "
        p.partner_id,
        p.name,
        p.trade_name,
        p.logo,
        p.banner,
        p.categoria,
        p.address,
        p.city,
        p.state,
        p.is_open,
        p.rating,
        p.delivery_fee,
        p.delivery_time_min,
        p.min_order,
        (SELECT COUNT(*) FROM om_market_products mp WHERE mp.partner_id = p.partner_id AND mp.status = '1') AS total_produtos
    ";

    // Params separados por posicao na SQL: SELECT, JOIN, WHERE, HAVING
    $selectParams = [];
    $joinParams = [];
    $whereParams = [];
    $havingParams = [];

    if ($hasCoords) {
        $selectFields .= ",
            (6371 * ACOS(
                LEAST(1, GREATEST(-1,
                    COS(RADIANS(?)) * COS(RADIANS(COALESCE(p.lat, 0))) * COS(RADIANS(COALESCE(p.lng, 0)) - RADIANS(?))
                    + SIN(RADIANS(?)) * SIN(RADIANS(COALESCE(p.lat, 0)))
                ))
            )) AS distancia
        ";
        $selectParams = [$lat, $lng, $lat];
    } else {
        $selectFields .= ", NULL AS distancia";
    }

    $where = ["p.status = '1'"];
    $having = [];
    $joinSql = '';

    // Filtro: tag de culinaria (acai, pizza, hamburguer, etc)
    if ($tag !== '') {
        $joinSql = "INNER JOIN om_partner_tag_links ptl ON ptl.partner_id = p.partner_id
                     INNER JOIN om_partner_tags pt ON pt.id = ptl.tag_id AND pt.slug = ?";
        $joinParams[] = $tag;
    }

    // Filtro: categoria do estabelecimento (mercado, restaurante, etc)
    if ($categoria !== '') {
        $where[] = "p.categoria = ?";
        $whereParams[] = $categoria;
    }

    // Filtro: busca por nome (escape LIKE wildcards)
    if ($busca !== '') {
        $buscaEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $busca);
        $where[] = "p.name ILIKE ?";
        $whereParams[] = '%' . $buscaEscaped . '%';
    }

    // Filtro: apenas abertas
    if ($openNow === 1) {
        $where[] = "p.is_open = 1";
    }

    // Filtro: entrega gratis
    if ($freeDelivery === 1) {
        $where[] = "(p.delivery_fee = 0 OR p.delivery_fee IS NULL)";
    }

    // Filtro: avaliacao minima
    if ($ratingMin > 0) {
        $where[] = "p.rating >= ?";
        $whereParams[] = $ratingMin;
    }

    // Filtro: raio (via HAVING, pois distancia e campo calculado)
    if ($hasCoords) {
        $having[] = "distancia <= ?";
        $havingParams[] = $raio;
    }

    // Ordem final dos params: SELECT → JOIN → WHERE → HAVING
    $params = array_merge($selectParams, $joinParams, $whereParams, $havingParams);

    $whereSql = implode(' AND ', $where);
    $havingSql = count($having) > 0 ? 'HAVING ' . implode(' AND ', $having) : '';

    // Ordenacao
    if ($sort === '' && $hasCoords) {
        $sort = 'distance';
    } elseif ($sort === '') {
        $sort = 'name';
    }

    // Order map: use unprefixed names for subquery compatibility
    $orderMapInner = [
        'distance'      => $hasCoords ? 'distancia ASC' : 'name ASC',
        'rating'        => 'rating DESC',
        'delivery_time' => 'delivery_time_min ASC',
        'price'         => 'delivery_fee ASC',
        'name'          => 'name ASC',
    ];
    $orderMapDirect = [
        'distance'      => $hasCoords ? 'distancia ASC' : 'p.name ASC',
        'rating'        => 'p.rating DESC',
        'delivery_time' => 'p.delivery_time_min ASC',
        'price'         => 'p.delivery_fee ASC',
        'name'          => 'p.name ASC',
    ];
    $orderSqlInner = $orderMapInner[$sort] ?? ($hasCoords ? 'distancia ASC' : 'name ASC');
    $orderSqlDirect = $orderMapDirect[$sort] ?? ($hasCoords ? 'distancia ASC' : 'p.name ASC');

    // --- Build queries using subquery for PostgreSQL HAVING compatibility ---
    // PostgreSQL does not allow column aliases in HAVING, so wrap in subquery
    $offset = ($page - 1) * $limit;

    if ($hasCoords) {
        // With coordinates: wrap in subquery so we can filter by distancia
        $innerSql = "
            SELECT {$selectFields}
            FROM om_market_partners p
            {$joinSql}
            WHERE {$whereSql}
        ";
        $innerParams = array_merge($selectParams, $joinParams, $whereParams);

        // Count query
        $countSql = "SELECT COUNT(*) AS total FROM ({$innerSql}) AS sub WHERE sub.distancia <= ?";
        $countParams = array_merge($innerParams, $havingParams);

        $stmtCount = $db->prepare($countSql);
        $stmtCount->execute($countParams);
        $total = (int)$stmtCount->fetchColumn();

        // Main query
        $sql = "SELECT * FROM ({$innerSql}) AS sub WHERE sub.distancia <= ? ORDER BY {$orderSqlInner} LIMIT ? OFFSET ?";
        $params = array_merge($innerParams, $havingParams, [$limit, $offset]);
    } else {
        // Without coordinates: simpler query, no HAVING needed
        $countSql = "SELECT COUNT(*) AS total FROM om_market_partners p {$joinSql} WHERE {$whereSql}";
        $countParams = array_merge($joinParams, $whereParams);

        $stmtCount = $db->prepare($countSql);
        $stmtCount->execute($countParams);
        $total = (int)$stmtCount->fetchColumn();

        $sql = "
            SELECT {$selectFields}
            FROM om_market_partners p
            {$joinSql}
            WHERE {$whereSql}
            ORDER BY {$orderSqlDirect}
            LIMIT ? OFFSET ?
        ";
        $params = array_merge($selectParams, $joinParams, $whereParams, [$limit, $offset]);
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // --- Buscar tags das lojas retornadas ---
    $partnerIds = array_column($rows, 'partner_id');
    $tagsByPartner = [];
    if (count($partnerIds) > 0) {
        $ph = implode(',', array_fill(0, count($partnerIds), '?'));
        $stmtTags = $db->prepare("
            SELECT ptl.partner_id, t.name, t.slug, t.icon
            FROM om_partner_tag_links ptl
            INNER JOIN om_partner_tags t ON t.id = ptl.tag_id AND t.active = 1
            WHERE ptl.partner_id IN ({$ph})
            ORDER BY t.sort_order
        ");
        $stmtTags->execute($partnerIds);
        foreach ($stmtTags->fetchAll() as $tRow) {
            $tagsByPartner[(int)$tRow['partner_id']][] = [
                'nome' => $tRow['name'],
                'slug' => $tRow['slug'],
                'icone' => $tRow['icon'],
            ];
        }
    }

    // --- Formatar resposta ---
    $lojas = [];
    foreach ($rows as $r) {
        $pid = (int)$r['partner_id'];
        $lojas[] = [
            'id'             => $pid,
            'nome'           => $r['name'] ?? $r['trade_name'],
            'logo'           => $r['logo'] ?: null,
            'banner'         => $r['banner'] ?: null,
            'categoria'      => $r['categoria'] ?? null,
            'tags'           => $tagsByPartner[$pid] ?? [],
            'endereco'       => $r['address'] ?? '',
            'cidade'         => $r['city'] ?? '',
            'estado'         => $r['state'] ?? '',
            'aberto'         => (bool)($r['is_open'] ?? false),
            'avaliacao'      => round((float)($r['rating'] ?? 0), 1),
            'taxa_entrega'   => round((float)($r['delivery_fee'] ?? 0), 2),
            'tempo_estimado' => (int)($r['delivery_time_min'] ?? 0),
            'pedido_minimo'  => round((float)($r['min_order'] ?? 0), 2),
            'total_produtos' => (int)$r['total_produtos'],
            'distancia_km'   => $r['distancia'] !== null ? round((float)$r['distancia'], 1) : null,
        ];
    }

    $totalPages = (int)ceil($total / $limit);

    response(true, [
        'total'        => $total,
        'pagina'       => $page,
        'por_pagina'   => $limit,
        'total_paginas' => $totalPages,
        'lojas'        => $lojas,
    ]);

} catch (Exception $e) {
    error_log("[BoraUm lojas.php] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar lojas. Tente novamente.", 500);
}
