<?php
/**
 * Collections API - Curated product/store collections for discovery
 * GET /vitrine/colecoes.php - List active collections
 * GET /vitrine/colecoes.php?id=X - Single collection with items
 * GET /vitrine/colecoes.php?slug=X - Collection by slug
 */

require_once __DIR__ . '/../config/database.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Método não permitido', 405);
}

$db = getDB();
$collectionId = $_GET['id'] ?? null;
$slug = $_GET['slug'] ?? null;
$tipo = $_GET['tipo'] ?? null; // 'produtos', 'lojas', 'categorias'
$limit = min((int)($_GET['limit'] ?? 20), 50);
$offset = (int)($_GET['offset'] ?? 0);

// Single collection by ID or slug
if ($collectionId || $slug) {
    $where = $collectionId ? 'c.id = ?' : 'c.slug = ?';
    $param = $collectionId ?: $slug;

    $stmt = $db->prepare("
        SELECT c.id, c.titulo, c.subtitulo, c.descricao, c.slug, c.tipo,
               c.imagem_url, c.cor_fundo, c.cor_texto, c.icone,
               c.posicao, c.ativo, c.destaque,
               c.data_inicio, c.data_fim,
               c.created_at, c.updated_at
        FROM om_market_colecoes c
        WHERE {$where} AND c.ativo = true
          AND (c.data_inicio IS NULL OR c.data_inicio <= NOW())
          AND (c.data_fim IS NULL OR c.data_fim >= NOW())
        LIMIT 1
    ");
    $stmt->execute([$param]);
    $collection = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$collection) {
        response(false, null, 'Coleção não encontrada', 404);
    }

    // Fetch items based on collection type
    $items = [];
    if ($collection['tipo'] === 'produtos') {
        $stmt = $db->prepare("
            SELECT ci.posicao,
                   p.id as product_id, p.name, p.description, p.price, p.price_promo,
                   p.image_url, p.stock_quantity, p.is_active,
                   s.id as store_id, s.name as store_name
            FROM om_market_colecao_items ci
            JOIN om_market_products p ON p.id = ci.item_id
            JOIN om_market_stores s ON s.id = p.store_id
            WHERE ci.colecao_id = ? AND ci.tipo = 'produto'
              AND p.is_active = true AND s.is_active = true
            ORDER BY ci.posicao ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$collection['id'], $limit, $offset]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($collection['tipo'] === 'lojas') {
        $stmt = $db->prepare("
            SELECT ci.posicao,
                   s.id as store_id, s.name, s.description, s.logo_url, s.banner_url,
                   s.category, s.rating, s.delivery_fee, s.delivery_time_min,
                   s.is_open, s.address_city
            FROM om_market_colecao_items ci
            JOIN om_market_stores s ON s.id = ci.item_id
            WHERE ci.colecao_id = ? AND ci.tipo = 'loja'
              AND s.is_active = true
            ORDER BY ci.posicao ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$collection['id'], $limit, $offset]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $collection['items'] = $items;

    // Count total items
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_colecao_items WHERE colecao_id = ?");
    $stmt->execute([$collection['id']]);
    $collection['total_items'] = (int)$stmt->fetchColumn();

    response(true, $collection);
}

// List all active collections
$conditions = [
    "c.ativo = true",
    "(c.data_inicio IS NULL OR c.data_inicio <= NOW())",
    "(c.data_fim IS NULL OR c.data_fim >= NOW())"
];
$params = [];

if ($tipo) {
    $conditions[] = "c.tipo = ?";
    $params[] = $tipo;
}

$where = implode(' AND ', $conditions);

$stmt = $db->prepare("
    SELECT c.id, c.titulo, c.subtitulo, c.descricao, c.slug, c.tipo,
           c.imagem_url, c.cor_fundo, c.cor_texto, c.icone,
           c.posicao, c.destaque,
           c.data_inicio, c.data_fim,
           (SELECT COUNT(*) FROM om_market_colecao_items ci WHERE ci.colecao_id = c.id) as total_items,
           c.created_at
    FROM om_market_colecoes c
    WHERE {$where}
    ORDER BY c.destaque DESC, c.posicao ASC, c.created_at DESC
    LIMIT ? OFFSET ?
");
$params[] = $limit;
$params[] = $offset;
$stmt->execute($params);
$collections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// For each collection, fetch preview items (first 4)
foreach ($collections as &$col) {
    if ($col['tipo'] === 'produtos') {
        $stmt = $db->prepare("
            SELECT p.id, p.name, p.price, p.price_promo, p.image_url, s.name as store_name
            FROM om_market_colecao_items ci
            JOIN om_market_products p ON p.id = ci.item_id
            JOIN om_market_stores s ON s.id = p.store_id
            WHERE ci.colecao_id = ? AND ci.tipo = 'produto'
              AND p.is_active = true AND s.is_active = true
            ORDER BY ci.posicao ASC
            LIMIT 4
        ");
        $stmt->execute([$col['id']]);
        $col['preview_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($col['tipo'] === 'lojas') {
        $stmt = $db->prepare("
            SELECT s.id, s.name, s.logo_url, s.rating, s.delivery_fee, s.delivery_time_min
            FROM om_market_colecao_items ci
            JOIN om_market_stores s ON s.id = ci.item_id
            WHERE ci.colecao_id = ? AND ci.tipo = 'loja'
              AND s.is_active = true
            ORDER BY ci.posicao ASC
            LIMIT 4
        ");
        $stmt->execute([$col['id']]);
        $col['preview_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Count total
$stmt = $db->prepare("SELECT COUNT(*) FROM om_market_colecoes c WHERE {$where}");
$stmtParams = array_slice($params, 0, -2); // remove limit/offset
$stmt->execute($stmtParams);
$total = (int)$stmt->fetchColumn();

response(true, [
    'collections' => $collections,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset
]);
