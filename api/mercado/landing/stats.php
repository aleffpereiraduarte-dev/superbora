<?php
/**
 * Landing Page Stats — Dados reais para a página inicial
 * GET /api/mercado/landing/stats.php
 * Público (sem auth), cached 5 minutos
 */
require __DIR__ . "/../config/database.php";

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Method not allowed", 405);
}

$db = getDB();

// Cache por 5 minutos
$cacheKey = 'landing_stats';
$cached = null;
if (function_exists('apcu_fetch')) {
    $cached = apcu_fetch($cacheKey);
}
if ($cached) {
    header('X-Cache: HIT');
    response(true, $cached);
}

// Produtos ativos
$produtos = (int)$db->query("SELECT COUNT(*) FROM om_market_products WHERE status::text = '1'")->fetchColumn();

// Lojas ativas
$lojas = (int)$db->query("SELECT COUNT(*) FROM om_market_partners WHERE status::text = '1'")->fetchColumn();

// Pedidos entregues
$entregues = (int)$db->query("SELECT COUNT(*) FROM om_market_orders WHERE status = 'entregue'")->fetchColumn();

// Total pedidos
$totalPedidos = (int)$db->query("SELECT COUNT(*) FROM om_market_orders")->fetchColumn();

// Clientes
$clientes = (int)$db->query("SELECT COUNT(*) FROM om_market_customers")->fetchColumn();

// Rating médio
$rating = $db->query("SELECT ROUND(AVG(rating)::numeric, 1) FROM om_market_reviews WHERE rating > 0")->fetchColumn();
$rating = $rating ? (float)$rating : 4.7;

// Total avaliações
$totalAvaliacoes = (int)$db->query("SELECT COUNT(*) FROM om_market_reviews WHERE rating > 0")->fetchColumn();

// Tempo médio entrega
$tempoEntrega = (int)$db->query("SELECT COALESCE(ROUND(AVG(delivery_time_min)::numeric, 0), 30) FROM om_market_partners WHERE delivery_time_min > 0")->fetchColumn();

// Frete
$frete = $db->query("SELECT MIN(taxa_entrega) as min_fee, MAX(taxa_entrega) as max_fee, ROUND(AVG(taxa_entrega)::numeric, 2) as avg_fee FROM om_market_partners WHERE taxa_entrega > 0")->fetch();

// Cidades (deduplicate accent variations, prefer accented version)
$stmt = $db->query("SELECT city, OCTET_LENGTH(city) as blen FROM (SELECT DISTINCT TRIM(city) as city FROM om_market_partners WHERE city IS NOT NULL AND city != '') sub ORDER BY blen DESC, city");
$seen = [];
$cidades = [];
foreach ($stmt as $row) {
    $c = $row['city'];
    $key = mb_strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $c));
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $cidades[] = $c;
    }
}
sort($cidades);

// Top reviews (com comentário)
$stmt = $db->query("
    SELECT r.rating, r.comment,
           COALESCE(c.name, 'Cliente SuperBora') as customer_name,
           COALESCE(p.trade_name, 'Loja') as store_name,
           COALESCE(p.city, '') as city
    FROM om_market_reviews r
    LEFT JOIN om_market_customers c ON c.customer_id = r.customer_id
    LEFT JOIN om_market_partners p ON p.partner_id = r.partner_id
    WHERE r.comment IS NOT NULL AND LENGTH(r.comment) > 10
    ORDER BY r.rating DESC, r.created_at DESC
    LIMIT 3
");
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top produtos (diversificados — um por categoria, populares)
$stmt = $db->query("
    SELECT DISTINCT ON (COALESCE(p.category_id, 0))
           p.name, p.price, p.image,
           COALESCE(c.name, 'Geral') as category
    FROM om_market_products p
    LEFT JOIN om_market_categories c ON c.category_id = p.category_id
    WHERE p.status::text = '1' AND p.price > 0 AND LENGTH(p.name) > 3
    ORDER BY COALESCE(p.category_id, 0), p.product_id ASC
");
$allProds = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Shuffle for variety, take 4
if (count($allProds) > 4) {
    shuffle($allProds);
    $topProdutos = array_slice($allProds, 0, 4);
} else {
    $topProdutos = $allProds;
}

// Categorias
$stmt = $db->query("SELECT name, icon FROM om_market_categories WHERE status::text = '1' ORDER BY sort_order ASC, name ASC LIMIT 8");
$categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [
    'produtos_ativos' => $produtos,
    'lojas_ativas' => $lojas,
    'pedidos_entregues' => $entregues,
    'total_pedidos' => $totalPedidos,
    'clientes' => $clientes,
    'rating_medio' => $rating,
    'total_avaliacoes' => $totalAvaliacoes,
    'tempo_entrega_medio' => $tempoEntrega,
    'frete' => [
        'minimo' => (float)($frete['min_fee'] ?? 0),
        'maximo' => (float)($frete['max_fee'] ?? 0),
        'medio' => (float)($frete['avg_fee'] ?? 0),
    ],
    'cidades' => $cidades,
    'reviews' => $reviews,
    'top_produtos' => $topProdutos,
    'categorias' => $categorias,
];

// Cache por 5 min
if (function_exists('apcu_store')) {
    apcu_store($cacheKey, $data, 300);
}

header('X-Cache: MISS');
response(true, $data);
