<?php
/**
 * ============================================================================
 * ONEMUNDO MERCADO - PAGINA DE PRODUTO
 * ============================================================================
 * Exibe detalhes do produto ou redireciona para produto-view.php
 * Se for requisicao JSON, retorna dados da API
 * ============================================================================
 */

// Verificar se e uma requisicao de API (JSON)
$isApiRequest = (
    isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
) || (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

// Obter ID do produto
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Se nao for API, redirecionar para a pagina de visualizacao
if (!$isApiRequest) {
    if ($product_id) {
        header('Location: /mercado/produto-view.php?id=' . $product_id);
    } else {
        header('Location: /mercado/');
    }
    exit;
}

// === MODO API ===
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Conexao com banco de dados
$pdo = null;
$oc_root = dirname(__DIR__);

// Tentar config do OpenCart primeiro
if (file_exists($oc_root . '/config.php') && !defined('DB_DATABASE')) {
    require_once $oc_root . '/config.php';
}

// Tentar config local
if (!defined('DB_HOSTNAME') && file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
    if (function_exists('getPDO')) {
        try {
            $pdo = getPDO();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Erro de conexao com banco']);
            exit;
        }
    }
}

// Criar conexao PDO se ainda nao existe
if (!$pdo && defined('DB_HOSTNAME')) {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexao']);
        exit;
    }
}

if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Configuracao de banco nao encontrada']);
    exit;
}

// Validar ID
if (!$product_id) {
    echo json_encode(['success' => false, 'error' => 'ID do produto nao informado']);
    exit;
}

// Partner ID da sessao ou default
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$partner_id = $_SESSION['market_partner_id'] ?? 4;

// Buscar produto completo
$stmt = $pdo->prepare("
    SELECT
        pb.product_id,
        pb.name,
        pb.brand,
        pb.barcode,
        pb.image,
        pb.unit,
        pb.description,
        pb.ingredients,
        pb.nutrition_json,
        pb.category_id,
        pp.price,
        pp.price_promo,
        pp.stock,
        c.name as category_name
    FROM om_market_products_base pb
    JOIN om_market_products_price pp ON pb.product_id = pp.product_id
    LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
    WHERE pb.product_id = ? AND pp.partner_id = ? AND pp.status = 1
    LIMIT 1
");
$stmt->execute([$product_id, $partner_id]);
$product = $stmt->fetch();

if (!$product) {
    // Tentar sem filtro de partner
    $stmt = $pdo->prepare("
        SELECT
            pb.product_id,
            pb.name,
            pb.brand,
            pb.barcode,
            pb.image,
            pb.unit,
            pb.description,
            pb.ingredients,
            pb.nutrition_json,
            pb.category_id,
            pp.price,
            pp.price_promo,
            pp.stock,
            c.name as category_name
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
        WHERE pb.product_id = ? AND pp.status = 1
        LIMIT 1
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
}

if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Produto nao encontrado']);
    exit;
}

// Converter nutrition_json para array se necessario
if (!empty($product['nutrition_json']) && is_string($product['nutrition_json'])) {
    $product['nutrition_json'] = json_decode($product['nutrition_json'], true);
}

// Converter precos para float
$product['price'] = (float)$product['price'];
$product['price_promo'] = (float)$product['price_promo'];
$product['stock'] = (int)$product['stock'];

// Calcular preco final e desconto
$product['price_final'] = $product['price_promo'] > 0 && $product['price_promo'] < $product['price']
    ? $product['price_promo']
    : $product['price'];
$product['has_discount'] = $product['price_promo'] > 0 && $product['price_promo'] < $product['price'];
$product['discount_percent'] = $product['has_discount']
    ? round((1 - $product['price_promo'] / $product['price']) * 100)
    : 0;

// Buscar produtos relacionados (mesma categoria ou marca)
$related = [];

// Por categoria
if ($product['category_id']) {
    $stmt = $pdo->prepare("
        SELECT
            pb.product_id,
            pb.name,
            pb.brand,
            pb.image,
            pp.price,
            pp.price_promo
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        WHERE pb.category_id = ?
          AND pb.product_id != ?
          AND pp.status = 1
          AND pp.price > 0
        ORDER BY RANDOM()
        LIMIT 8
    ");
    $stmt->execute([$product['category_id'], $product_id]);
    $related = $stmt->fetchAll();
}

// Se nao encontrou por categoria, buscar por marca
if (count($related) < 4 && !empty($product['brand'])) {
    $stmt = $pdo->prepare("
        SELECT
            pb.product_id,
            pb.name,
            pb.brand,
            pb.image,
            pp.price,
            pp.price_promo
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        WHERE pb.brand = ?
          AND pb.product_id != ?
          AND pp.status = 1
          AND pp.price > 0
        ORDER BY RANDOM()
        LIMIT ?
    ");
    $limit = 8 - count($related);
    $stmt->execute([$product['brand'], $product_id, $limit]);
    $byBrand = $stmt->fetchAll();

    // Merge sem duplicatas
    $existingIds = array_column($related, 'product_id');
    foreach ($byBrand as $p) {
        if (!in_array($p['product_id'], $existingIds)) {
            $related[] = $p;
        }
    }
}

// Converter precos dos relacionados
foreach ($related as &$r) {
    $r['price'] = (float)$r['price'];
    $r['price_promo'] = (float)$r['price_promo'];
    $r['price_final'] = $r['price_promo'] > 0 && $r['price_promo'] < $r['price']
        ? $r['price_promo']
        : $r['price'];
    $r['has_discount'] = $r['price_promo'] > 0 && $r['price_promo'] < $r['price'];
}

// Resposta JSON
echo json_encode([
    'success' => true,
    'product' => $product,
    'related' => array_slice($related, 0, 8)
], JSON_UNESCAPED_UNICODE);
