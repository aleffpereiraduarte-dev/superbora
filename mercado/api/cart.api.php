<?php
/**
 * ONEMUNDO MERCADO - API DO CARRINHO
 * Suporta: add, update, remove, clear, get
 */
header('Content-Type: application/json');
header('X-API-Version: 1.0');
header('Access-Control-Allow-Origin: https://seudominio.com');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configurar sessão segura
session_set_cookie_params([
    'lifetime' => 3600,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

// Inicializar carrinho se não existir
if (!isset($_SESSION['market_cart'])) {
    $_SESSION['market_cart'] = [];
}

$cart = &$_SESSION['market_cart'];

// Rate limiting simples
$client_ip = $_SERVER['REMOTE_ADDR'];
$rate_limit_key = 'cart_api_' . $client_ip;
if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'time' => time()];
}
if (time() - $_SESSION[$rate_limit_key]['time'] < 60 && $_SESSION[$rate_limit_key]['count'] > 100) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded']);
    exit;
}
$_SESSION[$rate_limit_key]['count']++;

// Receber dados
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}
// Validar CSRF token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

$action = $input['action'] ?? $_GET['action'] ?? '';

// Funções auxiliares
function calcularTotais(&$cart) {
    $subtotal = 0;
    $itemCount = 0;
    
    foreach ($cart as $item) {
        $subtotal += ($item['price'] ?? 0) * ($item['qty'] ?? 1);
        $itemCount += ($item['qty'] ?? 1);
    }
    
    $frete_gratis = $subtotal >= 99;
    $frete = $frete_gratis ? 0 : 5.99;
    $total = $subtotal + $frete;
    
    return [
        'subtotal' => round($subtotal, 2),
        'itemCount' => $itemCount,
        'uniqueItems' => count($cart),
        'frete' => round($frete, 2),
        'frete_gratis' => $frete_gratis,
        'total' => round($total, 2),
        'falta_frete_gratis' => $frete_gratis ? 0 : round(99 - $subtotal, 2)
    ];
}

function gerarIdProduto($product_id, $extras = [], $obs = '') {
    // ID único considera produto + extras + observações
    $key = $product_id;
    if (!empty($extras)) {
        sort($extras);
        $key .= '_' . md5(implode(',', $extras));
    }
    if (!empty($obs)) {
        $key .= '_' . md5($obs);
    }
    return $key;
}

// Processar ações
switch ($action) {
    
    // ========================================
    // ADICIONAR ITEM
    // ========================================
    case 'add':
        $product_id = $input['product_id'] ?? null;
        
        if (!$product_id) {
            error_log('Cart API: Missing product_id from IP: ' . $_SERVER['REMOTE_ADDR']);
            echo json_encode(['success' => false, 'error' => 'ID do produto não informado']);
            exit;
        }
        
        $name = filter_var($input['name'] ?? 'Produto', FILTER_SANITIZE_STRING);
        $price = max(0, floatval($input['price'] ?? 0));
        $price_original = max(0, floatval($input['price_original'] ?? $price));
        $image = filter_var($input['image'] ?? '/image/placeholder.jpg', FILTER_VALIDATE_URL) ?: '/image/placeholder.jpg';
        $qty = max(1, min(999, intval($input['qty'] ?? 1)));
        $extras = $input['extras'] ?? [];
        $obs = $input['obs'] ?? '';
        $unit = $input['unit'] ?? '';
        
        // Gerar ID único (considerando extras e obs)
        $cart_id = gerarIdProduto($product_id, $extras, $obs);
        
        if (isset($cart[$cart_id])) {
            // Produto já existe, aumentar quantidade
            $cart[$cart_id]['qty'] += $qty;
        } else {
            // Novo produto
            $cart[$cart_id] = [
                'product_id' => $product_id,
                'name' => $name,
                'price' => $price,
                'price_original' => $price_original,
                'image' => $image,
                'qty' => $qty,
                'extras' => $extras,
                'obs' => $obs,
                'unit' => $unit,
                'added_at' => time()
            ];
        }
        
        // Cache de totais para evitar recálculos desnecessários
if (!isset($_SESSION['cart_totals_cache']) || $_SESSION['cart_modified']) {
    $totais = calcularTotais($cart);
    $_SESSION['cart_totals_cache'] = $totais;
    $_SESSION['cart_modified'] = false;
} else {
    $totais = $_SESSION['cart_totals_cache'];
}
        
        echo json_encode([
            'success' => true,
            'message' => 'Produto adicionado',
            'cartCount' => $totais['itemCount'],
            'cart' => $cart,
            'totals' => $totais
        ]);
        break;
    
    // ========================================
    // ATUALIZAR QUANTIDADE (delta: +1 ou -1)
    // ========================================
    case 'update':
        $product_id = $input['product_id'] ?? null;
        $delta = intval($input['delta'] ?? 0);
        $new_qty = isset($input['qty']) ? intval($input['qty']) : null;
        
        if (!$product_id) {
            error_log('Cart API: Missing product_id from IP: ' . $_SERVER['REMOTE_ADDR']);
            echo json_encode(['success' => false, 'error' => 'ID do produto não informado']);
            exit;
        }
        
        if (!isset($cart[$product_id])) {
            echo json_encode(['success' => false, 'error' => 'Produto não encontrado no carrinho']);
            exit;
        }
        
        if ($new_qty !== null) {
            // Definir quantidade específica
            $cart[$product_id]['qty'] = max(0, $new_qty);
        } else {
            // Aplicar delta
            $cart[$product_id]['qty'] += $delta;
        }
        
        // Remover se quantidade <= 0
        if ($cart[$product_id]['qty'] <= 0) {
            unset($cart[$product_id]);
        }
        
        // Cache de totais para evitar recálculos desnecessários
if (!isset($_SESSION['cart_totals_cache']) || $_SESSION['cart_modified']) {
    $totais = calcularTotais($cart);
    $_SESSION['cart_totals_cache'] = $totais;
    $_SESSION['cart_modified'] = false;
} else {
    $totais = $_SESSION['cart_totals_cache'];
}
        
        echo json_encode([
            'success' => true,
            'message' => 'Carrinho atualizado',
            'cartCount' => $totais['itemCount'],
            'cart' => $cart,
            'totals' => $totais
        ]);
        break;
    
    // ========================================
    // REMOVER ITEM ESPECÍFICO
    // ========================================
    case 'remove':
        $product_id = $input['product_id'] ?? null;
        
        if (!$product_id) {
            error_log('Cart API: Missing product_id from IP: ' . $_SERVER['REMOTE_ADDR']);
            echo json_encode(['success' => false, 'error' => 'ID do produto não informado']);
            exit;
        }
        
        if (isset($cart[$product_id])) {
            unset($cart[$product_id]);
        }
        
        // Cache de totais para evitar recálculos desnecessários
if (!isset($_SESSION['cart_totals_cache']) || $_SESSION['cart_modified']) {
    $totais = calcularTotais($cart);
    $_SESSION['cart_totals_cache'] = $totais;
    $_SESSION['cart_modified'] = false;
} else {
    $totais = $_SESSION['cart_totals_cache'];
}
        
        echo json_encode([
            'success' => true,
            'message' => 'Produto removido',
            'cartCount' => $totais['itemCount'],
            'cart' => $cart,
            'totals' => $totais
        ]);
        break;
    
    // ========================================
    // LIMPAR CARRINHO
    // ========================================
    case 'clear':
        $cart = [];
        $_SESSION['market_cart'] = [];
        
        echo json_encode([
            'success' => true,
            'message' => 'Carrinho limpo',
            'cartCount' => 0,
            'cart' => [],
            'totals' => calcularTotais($cart)
        ]);
        break;
    
    // ========================================
    // OBTER CARRINHO
    // ========================================
    case 'get':
    default:
        // Cache de totais para evitar recálculos desnecessários
if (!isset($_SESSION['cart_totals_cache']) || $_SESSION['cart_modified']) {
    $totais = calcularTotais($cart);
    $_SESSION['cart_totals_cache'] = $totais;
    $_SESSION['cart_modified'] = false;
} else {
    $totais = $_SESSION['cart_totals_cache'];
}
        
        echo json_encode([
            'success' => true,
            'cart' => $cart,
            'cartCount' => $totais['itemCount'],
            'totals' => $totais
        ]);
        break;
}
