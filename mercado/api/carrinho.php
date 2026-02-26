<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO MERCADO - API DO CARRINHO
 * ══════════════════════════════════════════════════════════════════════════════
 * Endpoint: /mercado/api/carrinho.php
 * Métodos: add, update, remove, clear, set_tip, set_slot, apply_coupon, remove_coupon
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Sessão
session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Carregar configuracoes
require_once __DIR__ . '/../config.php';

// Conexao
try {
    $pdo = getDB();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro de conexao']);
    exit;
}

// Inicializar carrinhos
if (!isset($_SESSION['market_cart'])) {
    $_SESSION['market_cart'] = [];
}
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Funcao para sincronizar market_cart com cart (usado pelo index.php)
function syncToLegacyCart() {
    $_SESSION['cart'] = [];
    foreach ($_SESSION['market_cart'] as $item) {
        $pid = $item['product_id'] ?? $item['id'] ?? 0;
        if ($pid > 0) {
            $_SESSION['cart'][$pid] = $item;
        }
    }
}

// Ler dados
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

// Funções auxiliares
function getCartTotals() {
    $cart = $_SESSION['market_cart'] ?? [];
    $subtotal = 0;
    $total_items = 0;
    
    foreach ($cart as $item) {
        $qty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
        $price = (float)($item['price'] ?? 0);
        $price_promo = (float)($item['price_promo'] ?? 0);
        $final_price = ($price_promo > 0 && $price_promo < $price) ? $price_promo : $price;
        
        $subtotal += $final_price * $qty;
        $total_items += $qty;
    }
    
    return [
        'subtotal' => $subtotal,
        'total_items' => $total_items,
        'items_count' => count($cart)
    ];
}

// Processar ações
switch ($action) {
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // ADICIONAR PRODUTO
    // ═══════════════════════════════════════════════════════════════════════════════
    case 'add':
        $product_id = (int)($input['product_id'] ?? 0);
        $quantity = (int)($input['quantity'] ?? $input['qty'] ?? 1);
        $name = $input['name'] ?? '';
        $price = (float)($input['price'] ?? 0);
        $price_promo = (float)($input['price_promo'] ?? 0);
        $image = $input['image'] ?? '';
        $brand = $input['brand'] ?? '';
        $unit = $input['unit'] ?? 'un';
        
        if ($product_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Produto inválido']);
            exit;
        }
        
        // Se nao veio dados do produto, buscar no banco
        if (empty($name) || $price <= 0) {
            $partner_id = (int)($_SESSION['market_partner_id'] ?? 1);
            $stmt = $pdo->prepare("
                SELECT p.name, p.description as brand, p.image, p.unit,
                       p.price, p.special_price as price_promo
                FROM om_market_products p
                WHERE p.product_id = ? AND p.partner_id = ?
            ");
            $stmt->execute([$product_id, $partner_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                echo json_encode(['success' => false, 'message' => 'Produto nao encontrado']);
                exit;
            }

            $name = $product['name'];
            $brand = $product['brand'] ?? '';
            $price = (float)$product['price'];
            $price_promo = (float)($product['price_promo'] ?? 0);
            $image = $product['image'];
            $unit = $product['unit'] ?: 'un';
        }
        
        // Verificar se já existe no carrinho
        $found = false;
        foreach ($_SESSION['market_cart'] as $key => &$item) {
            $item_id = (int)($item['id'] ?? $item['product_id'] ?? 0);
            if ($item_id === $product_id) {
                $item['qty'] = ($item['qty'] ?? $item['quantity'] ?? 1) + $quantity;
                $found = true;
                break;
            }
        }
        unset($item);
        
        // Adicionar novo
        if (!$found) {
            $_SESSION['market_cart'][] = [
                'id' => $product_id,
                'product_id' => $product_id,
                'name' => $name,
                'brand' => $brand,
                'price' => $price,
                'price_promo' => $price_promo,
                'image' => $image,
                'unit' => $unit,
                'qty' => $quantity
            ];
        }
        
        syncToLegacyCart();
        $totals = getCartTotals();
        echo json_encode([
            'success' => true,
            'message' => 'Produto adicionado',
            'total_items' => $totals['total_items'],
            'subtotal' => $totals['subtotal']
        ]);
        break;
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // ATUALIZAR QUANTIDADE
    // ═══════════════════════════════════════════════════════════════════════════════
    case 'update':
        $product_id = (int)($input['product_id'] ?? 0);
        $quantity = (int)($input['quantity'] ?? $input['qty'] ?? 1);
        
        if ($quantity <= 0) {
            // Remover se quantidade <= 0
            foreach ($_SESSION['market_cart'] as $key => $item) {
                $item_id = (int)($item['id'] ?? $item['product_id'] ?? 0);
                if ($item_id === $product_id) {
                    unset($_SESSION['market_cart'][$key]);
                    $_SESSION['market_cart'] = array_values($_SESSION['market_cart']);
                    break;
                }
            }
        } else {
            // Atualizar quantidade
            foreach ($_SESSION['market_cart'] as $key => &$item) {
                $item_id = (int)($item['id'] ?? $item['product_id'] ?? 0);
                if ($item_id === $product_id) {
                    $item['qty'] = $quantity;
                    break;
                }
            }
            unset($item);
        }
        
        syncToLegacyCart();
        $totals = getCartTotals();
        echo json_encode([
            'success' => true,
            'message' => 'Carrinho atualizado',
            'total_items' => $totals['total_items'],
            'subtotal' => $totals['subtotal']
        ]);
        break;
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // REMOVER PRODUTO
    // ═══════════════════════════════════════════════════════════════════════════════
    case 'remove':
        $product_id = (int)($input['product_id'] ?? 0);
        
        foreach ($_SESSION['market_cart'] as $key => $item) {
            $item_id = (int)($item['id'] ?? $item['product_id'] ?? 0);
            if ($item_id === $product_id) {
                unset($_SESSION['market_cart'][$key]);
                $_SESSION['market_cart'] = array_values($_SESSION['market_cart']);
                break;
            }
        }
        
        syncToLegacyCart();
        $totals = getCartTotals();
        echo json_encode([
            'success' => true,
            'message' => 'Produto removido',
            'total_items' => $totals['total_items'],
            'subtotal' => $totals['subtotal']
        ]);
        break;
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // LIMPAR CARRINHO
    // ═══════════════════════════════════════════════════════════════════════════════
    case 'clear':
        $_SESSION['market_cart'] = [];
        $_SESSION['cart'] = [];
        $_SESSION['delivery_tip'] = 0;
        $_SESSION['delivery_slot'] = null;
        unset($_SESSION['coupon']);

        echo json_encode([
            'success' => true,
            'message' => 'Carrinho limpo',
            'total_items' => 0,
            'subtotal' => 0
        ]);
        break;
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // DEFINIR GORJETA
    // ═══════════════════════════════════════════════════════════════════════════════
    case 'set_tip':
        $tip = (float)($input['tip'] ?? 0);
        $_SESSION['delivery_tip'] = max(0, $tip);
        
        echo json_encode([
            'success' => true,
            'message' => 'Gorjeta definida',
            'tip' => $_SESSION['delivery_tip']
        ]);
        break;
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // DEFINIR SLOT DE ENTREGA
    // ═══════════════════════════════════════════════════════════════════════════════
    case 'set_slot':
        $slot_id = (int)($input['slot_id'] ?? 0);
        $slot_price = (float)($input['slot_price'] ?? 0);
        
        $_SESSION['delivery_slot'] = $slot_id;
        $_SESSION['delivery_slot_price'] = $slot_price;
        
        echo json_encode([
            'success' => true,
            'message' => 'Horário selecionado',
            'slot_id' => $slot_id,
            'slot_price' => $slot_price
        ]);
        break;
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // APLICAR CUPOM
    // ═══════════════════════════════════════════════════════════════════════════════
    case 'apply_coupon':
        $code = strtoupper(trim($input['code'] ?? ''));
        
        if (empty($code)) {
            echo json_encode(['success' => false, 'message' => 'Digite um código']);
            exit;
        }
        
        // Cupons válidos (pode expandir para tabela no banco)
        $valid_coupons = [
            'BEMVINDO10' => ['type' => 'percent', 'value' => 10, 'min_order' => 50],
            'FRETEGRATIS' => ['type' => 'fixed', 'value' => 9.99, 'min_order' => 0],
            'DESCONTO20' => ['type' => 'fixed', 'value' => 20, 'min_order' => 100],
            'PRIMEIRA' => ['type' => 'percent', 'value' => 15, 'min_order' => 80],
        ];
        
        if (!isset($valid_coupons[$code])) {
            echo json_encode(['success' => false, 'message' => 'Cupom inválido']);
            exit;
        }
        
        $coupon = $valid_coupons[$code];
        $totals = getCartTotals();
        
        if ($totals['subtotal'] < $coupon['min_order']) {
            echo json_encode([
                'success' => false, 
                'message' => 'Pedido mínimo de R$ ' . number_format($coupon['min_order'], 2, ',', '.')
            ]);
            exit;
        }
        
        $_SESSION['coupon'] = [
            'code' => $code,
            'type' => $coupon['type'],
            'value' => $coupon['value']
        ];
        
        $discount = $coupon['type'] === 'percent' 
            ? ($totals['subtotal'] * $coupon['value'] / 100)
            : $coupon['value'];
        
        echo json_encode([
            'success' => true,
            'message' => 'Cupom aplicado!',
            'discount' => $discount
        ]);
        break;
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // REMOVER CUPOM
    // ═══════════════════════════════════════════════════════════════════════════════
    case 'remove_coupon':
        unset($_SESSION['coupon']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Cupom removido'
        ]);
        break;
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // OBTER CARRINHO
    // ═══════════════════════════════════════════════════════════════════════════════
    case 'get':
    default:
        $totals = getCartTotals();
        
        echo json_encode([
            'success' => true,
            'cart' => $_SESSION['market_cart'],
            'total_items' => $totals['total_items'],
            'subtotal' => $totals['subtotal'],
            'tip' => $_SESSION['delivery_tip'] ?? 0,
            'slot' => $_SESSION['delivery_slot'] ?? null,
            'coupon' => $_SESSION['coupon'] ?? null
        ]);
        break;
}
