<?php
/**
 * API DE PRODUTO - OneMundo Mercado
 * Retorna dados do produto com informacoes de preco e imagens
 */

// Rate limiting simples
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_time = time();
$rate_limit_window = 60;
$max_requests = 100;

if (!isset($_SESSION['api_requests'])) {
    $_SESSION['api_requests'] = [];
}

$_SESSION['api_requests'] = array_filter($_SESSION['api_requests'], function($timestamp) use ($current_time, $rate_limit_window) {
    return ($current_time - $timestamp) < $rate_limit_window;
});

if (count($_SESSION['api_requests']) >= $max_requests) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded']);
    exit;
}

$_SESSION['api_requests'][] = $current_time;

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Conexao com banco de dados usando config principal
require_once __DIR__ . '/../config.php';

try {
    $pdo = getDB();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro de conexao com banco']);
    exit;
}

// Validar ID do produto
$product_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => [
        'min_range' => 1,
        'max_range' => PHP_INT_MAX
    ]
]);

if ($product_id === false || $product_id === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID do produto deve ser um numero inteiro positivo']);
    exit;
}

// Partner ID
$partner_id = $_SESSION['market_partner_id'] ?? 1;

// Verificar cache primeiro
$cache_key = 'product_' . $product_id . '_' . $partner_id;
$cache_file = '/tmp/' . $cache_key . '.json';
$cache_duration = 300;

$product = null;
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_duration) {
    $cached = file_get_contents($cache_file);
    if ($cached) {
        $product = json_decode($cached, true);
    }
}

if (!$product) {
    // Buscar produto da tabela om_market_products
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.product_id,
                p.partner_id,
                p.name,
                p.description,
                p.price,
                p.special_price as price_promo,
                p.quantity as stock,
                p.image,
                p.category,
                p.category_id,
                p.unit,
                p.barcode,
                p.sku,
                c.name as category_name
            FROM om_market_products p
            LEFT JOIN om_market_categories c ON p.category_id = c.category_id
            WHERE p.product_id = ? AND p.partner_id = ? AND p.status = '1'
            LIMIT 1
        ");
        $stmt->execute([$product_id, $partner_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        // Tentar sem partner_id se nao encontrar
        if (!$product) {
            $stmt = $pdo->prepare("
                SELECT
                    p.product_id,
                    p.partner_id,
                    p.name,
                    p.description,
                    p.price,
                    p.special_price as price_promo,
                    p.quantity as stock,
                    p.image,
                    p.category,
                    p.category_id,
                    p.unit,
                    p.barcode,
                    p.sku,
                    c.name as category_name
                FROM om_market_products p
                LEFT JOIN om_market_categories c ON p.category_id = c.category_id
                WHERE p.product_id = ? AND p.status = '1'
                LIMIT 1
            ");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log('Product query error: ' . $e->getMessage());
        $product = null;
    }

    // Salvar em cache se encontrou
    if ($product) {
        @file_put_contents($cache_file, json_encode($product));
    }
}

if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Produto nao encontrado']);
    exit;
}

// Formatar resposta
$product['price'] = (float)$product['price'];
$product['price_promo'] = $product['price_promo'] ? (float)$product['price_promo'] : null;
$product['stock'] = (int)$product['stock'];

// Calcular preco final
$product['price_final'] = ($product['price_promo'] && $product['price_promo'] < $product['price'])
    ? $product['price_promo']
    : $product['price'];

$product['has_discount'] = $product['price_promo'] && $product['price_promo'] < $product['price'];
$product['discount_percent'] = $product['has_discount']
    ? round((1 - $product['price_promo'] / $product['price']) * 100)
    : 0;

$product['in_stock'] = $product['stock'] > 0;

// Formatar imagem
if ($product['image']) {
    $product['image_url'] = (preg_match("/^https?:\/\//", $product['image'])) ? $product['image'] : "/mercado/uploads/products/" . $product['image'];
} else {
    $product['image_url'] = "/mercado/assets/img/no-image.png";
}

// Limpar descricao de HTML
if (!empty($product['description'])) {
    $product['description'] = strip_tags($product['description']);
}

// Buscar produtos relacionados
$related = [];
try {
    if ($product['category_id']) {
        $stmt = $pdo->prepare("
            SELECT p.product_id, p.name, p.image, p.price, p.special_price as price_promo
            FROM om_market_products p
            WHERE p.category_id = ? AND p.product_id != ? AND p.status = '1' AND p.price > 0
            ORDER BY RANDOM()
            LIMIT 8
        ");
        $stmt->execute([$product['category_id'], $product_id]);
        $related = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Se nao encontrou por categoria, buscar por partner
    if (count($related) < 4) {
        $stmt = $pdo->prepare("
            SELECT p.product_id, p.name, p.image, p.price, p.special_price as price_promo
            FROM om_market_products p
            WHERE p.partner_id = ? AND p.product_id != ? AND p.status = '1' AND p.price > 0
            ORDER BY RANDOM()
            LIMIT ?
        ");
        $limit = 8 - count($related);
        $stmt->execute([$product['partner_id'], $product_id, $limit]);
        $byPartner = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $existingIds = array_column($related, 'product_id');
        foreach ($byPartner as $p) {
            if (!in_array($p['product_id'], $existingIds)) {
                $related[] = $p;
            }
        }
    }
} catch (PDOException $e) {
    // Ignorar erro de relacionados
}

// Formatar relacionados
foreach ($related as &$r) {
    $r['price'] = (float)$r['price'];
    $r['price_promo'] = $r['price_promo'] ? (float)$r['price_promo'] : null;
    $r['price_final'] = ($r['price_promo'] && $r['price_promo'] < $r['price'])
        ? $r['price_promo']
        : $r['price'];
    $r['has_discount'] = $r['price_promo'] && $r['price_promo'] < $r['price'];
    if ($r['image']) {
        $r['image_url'] = (preg_match("/^https?:\/\//", $r['image'])) ? $r['image'] : "/mercado/uploads/products/" . $r['image'];
    } else {
        $r['image_url'] = "/mercado/assets/img/no-image.png";
    }
}

// Resposta final
echo json_encode([
    'success' => true,
    'product' => $product,
    'related' => $related
], JSON_UNESCAPED_UNICODE);
