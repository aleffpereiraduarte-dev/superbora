<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API DE CUPONS - OneMundo Mercado
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Ações:
 * - validate: Validar cupom
 * - apply: Aplicar cupom ao carrinho
 * - remove: Remover cupom
 * - list: Listar cupons disponíveis
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Conectar ao banco
require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

// Sessão
session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$customer_id = $_SESSION['customer_id'] ?? 0;
$partner_id = $_SESSION['market_partner_id'] ?? 1;

// Input
$input_raw = file_get_contents('php://input');
$input = json_decode($input_raw, true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? 'list';
$code = strtoupper(trim($input['code'] ?? $_GET['code'] ?? $_POST['code'] ?? ''));

// Calcular subtotal do carrinho
function getCartSubtotal() {
    $cart = $_SESSION['cart'] ?? $_SESSION['market_cart'] ?? [];
    $subtotal = 0;
    foreach ($cart as $item) {
        $qty = $item['qty'] ?? 1;
        $price = ($item['price_promo'] ?? 0) > 0 && $item['price_promo'] < $item['price']
            ? $item['price_promo']
            : ($item['price'] ?? 0);
        $subtotal += $price * $qty;
    }
    return $subtotal;
}

// Verificar se cliente já fez pedido
function isFirstOrder($pdo, $customer_id) {
    if (!$customer_id) return true;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status NOT IN ('cancelled', 'refunded')");
        $stmt->execute([$customer_id]);
        return $stmt->fetchColumn() == 0;
    } catch (Exception $e) {
        return true;
    }
}

// Contar usos do cupom pelo cliente
function getCouponUsesByCustomer($pdo, $coupon_id, $customer_id) {
    if (!$customer_id) return 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_coupon_history WHERE coupon_id = ? AND customer_id = ?");
        $stmt->execute([$coupon_id, $customer_id]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

switch ($action) {

    // ========================================================================
    // VALIDATE - Validar cupom
    // ========================================================================
    case 'validate':
        if (!$code) {
            echo json_encode(['success' => false, 'error' => 'Informe o código do cupom']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM om_market_coupons WHERE code = ? AND status = 'active'");
        $stmt->execute([$code]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$coupon) {
            echo json_encode(['success' => false, 'error' => 'Cupom inválido ou expirado']);
            exit;
        }

        // Verificar data
        $now = date('Y-m-d H:i:s');
        if ($coupon['valid_from'] && $now < $coupon['valid_from']) {
            echo json_encode(['success' => false, 'error' => 'Cupom ainda não está válido']);
            exit;
        }
        if ($coupon['valid_until'] && $now > $coupon['valid_until']) {
            echo json_encode(['success' => false, 'error' => 'Cupom expirado']);
            exit;
        }

        // Verificar usos totais
        if ($coupon['max_uses'] && $coupon['current_uses'] >= $coupon['max_uses']) {
            echo json_encode(['success' => false, 'error' => 'Cupom esgotado']);
            exit;
        }

        // Verificar usos por cliente
        if ($coupon['max_uses_per_user'] && $customer_id) {
            $customer_uses = getCouponUsesByCustomer($pdo, $coupon['id'], $customer_id);
            if ($customer_uses >= $coupon['max_uses_per_user']) {
                echo json_encode(['success' => false, 'error' => 'Você já utilizou este cupom']);
                exit;
            }
        }

        // Verificar primeira compra
        if ($coupon['first_order_only'] && !isFirstOrder($pdo, $customer_id)) {
            echo json_encode(['success' => false, 'error' => 'Cupom válido apenas para primeira compra']);
            exit;
        }

        // Verificar valor mínimo
        $subtotal = getCartSubtotal();
        if ($coupon['min_order_value'] > 0 && $subtotal < $coupon['min_order_value']) {
            echo json_encode([
                'success' => false,
                'error' => 'Pedido mínimo de R$ ' . number_format($coupon['min_order_value'], 2, ',', '.') . ' para usar este cupom'
            ]);
            exit;
        }

        // Calcular desconto
        $discount = 0;
        switch ($coupon['discount_type']) {
            case 'percentage':
                $discount = $subtotal * ($coupon['discount_value'] / 100);
                if ($coupon['max_discount'] && $discount > $coupon['max_discount']) {
                    $discount = $coupon['max_discount'];
                }
                break;
            case 'fixed':
                $discount = min($coupon['discount_value'], $subtotal);
                break;
            case 'free_delivery':
                $discount = 0; // Frete será zerado no checkout
                break;
            case 'cashback':
                $discount = 0; // Cashback é aplicado depois
                break;
        }

        echo json_encode([
            'success' => true,
            'valid' => true,
            'coupon' => [
                'id' => $coupon['id'],
                'code' => $coupon['code'],
                'name' => $coupon['name'],
                'description' => $coupon['description'],
                'type' => $coupon['discount_type'],
                'discount_value' => (float)$coupon['discount_value'],
                'discount_calculated' => round($discount, 2),
                'min_order' => (float)$coupon['min_order_value'],
                'max_discount' => $coupon['max_discount'] ? (float)$coupon['max_discount'] : null
            ]
        ]);
        break;

    // ========================================================================
    // APPLY - Aplicar cupom ao carrinho
    // ========================================================================
    case 'apply':
        if (!$code) {
            echo json_encode(['success' => false, 'error' => 'Informe o código do cupom']);
            exit;
        }

        // Validar primeiro
        $stmt = $pdo->prepare("SELECT * FROM om_market_coupons WHERE code = ? AND status = 'active'");
        $stmt->execute([$code]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$coupon) {
            echo json_encode(['success' => false, 'error' => 'Cupom inválido']);
            exit;
        }

        // Validações (mesmas do validate)
        $now = date('Y-m-d H:i:s');
        if (($coupon['valid_from'] && $now < $coupon['valid_from']) ||
            ($coupon['valid_until'] && $now > $coupon['valid_until'])) {
            echo json_encode(['success' => false, 'error' => 'Cupom expirado']);
            exit;
        }

        $subtotal = getCartSubtotal();
        if ($coupon['min_order_value'] > 0 && $subtotal < $coupon['min_order_value']) {
            echo json_encode([
                'success' => false,
                'error' => 'Pedido mínimo de R$ ' . number_format($coupon['min_order_value'], 2, ',', '.')
            ]);
            exit;
        }

        // Calcular desconto
        $discount = 0;
        switch ($coupon['discount_type']) {
            case 'percentage':
                $discount = $subtotal * ($coupon['discount_value'] / 100);
                if ($coupon['max_discount'] && $discount > $coupon['max_discount']) {
                    $discount = $coupon['max_discount'];
                }
                break;
            case 'fixed':
                $discount = min($coupon['discount_value'], $subtotal);
                break;
            case 'free_delivery':
                $discount = 0;
                break;
            case 'cashback':
                $discount = 0;
                break;
        }

        // Salvar na sessão
        $_SESSION['applied_coupon'] = [
            'coupon_id' => $coupon['id'],
            'code' => $coupon['code'],
            'name' => $coupon['name'],
            'type' => $coupon['discount_type'],
            'discount' => round($discount, 2),
            'free_shipping' => $coupon['discount_type'] === 'free_delivery',
            'cashback' => $coupon['discount_type'] === 'cashback' ? $coupon['discount_value'] : 0
        ];

        echo json_encode([
            'success' => true,
            'message' => 'Cupom aplicado com sucesso!',
            'coupon' => $_SESSION['applied_coupon'],
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'total' => round($subtotal - $discount, 2)
        ]);
        break;

    // ========================================================================
    // REMOVE - Remover cupom
    // ========================================================================
    case 'remove':
        unset($_SESSION['applied_coupon']);

        $subtotal = getCartSubtotal();

        echo json_encode([
            'success' => true,
            'message' => 'Cupom removido',
            'subtotal' => round($subtotal, 2),
            'discount' => 0,
            'total' => round($subtotal, 2)
        ]);
        break;

    // ========================================================================
    // LIST - Listar cupons disponíveis
    // ========================================================================
    case 'list':
    default:
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            SELECT id, code, name, description, discount_type, discount_value,
                   min_order_value, max_discount, valid_until, first_order_only
            FROM om_market_coupons
            WHERE status = 'active'
              AND (valid_from IS NULL OR valid_from <= ?)
              AND (valid_until IS NULL OR valid_until >= ?)
              AND (max_uses IS NULL OR current_uses < max_uses)
            ORDER BY discount_value DESC
        ");
        $stmt->execute([$now, $now]);
        $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Formatar
        $formatted = [];
        foreach ($coupons as $c) {
            $formatted[] = [
                'id' => (int)$c['id'],
                'code' => $c['code'],
                'name' => $c['name'],
                'description' => $c['description'],
                'type' => $c['discount_type'],
                'discount' => (float)$c['discount_value'],
                'min_order' => (float)$c['min_order_value'],
                'max_discount' => $c['max_discount'] ? (float)$c['max_discount'] : null,
                'expires' => $c['valid_until'],
                'first_order_only' => (bool)$c['first_order_only']
            ];
        }

        // Cupom aplicado atualmente
        $applied = $_SESSION['applied_coupon'] ?? null;

        echo json_encode([
            'success' => true,
            'count' => count($formatted),
            'coupons' => $formatted,
            'applied_coupon' => $applied
        ]);
        break;
}
