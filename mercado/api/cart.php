<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API DO CARRINHO - OneMundo Mercado
 * ══════════════════════════════════════════════════════════════════════════════
 * 
 * UNIFICADO: Funciona com index.php, carrinho.php, checkout.php
 * 
 * Ações:
 * - add: Adicionar produto
 * - update: Atualizar quantidade  
 * - remove: Remover produto
 * - clear: Limpar carrinho
 * - get: Retornar carrinho
 * - save: Salvar carrinho completo (usado pelo index.php)
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Sessão com nome do OpenCart
session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inicializar carrinho
if (!isset($_SESSION["market_cart"])) {
    $_SESSION["market_cart"] = [];
}

// Input
$input_raw = file_get_contents("php://input");
$input = json_decode($input_raw, true) ?? [];
$action = $input["action"] ?? $_GET["action"] ?? "get";
$cart = &$_SESSION["market_cart"];

// Processar ação
switch ($action) {
    
    // ========================================================================
    // ADD - Adicionar produto ao carrinho
    // ========================================================================
    case "add":
        $pid = (int)($input["product_id"] ?? $input["id"] ?? 0);
        $qty = (int)($input["qty"] ?? $input["quantity"] ?? 1);
        
        if ($pid > 0) {
            $key = "p" . $pid;
            
            if (isset($cart[$key])) {
                // Já existe - aumentar quantidade
                $cart[$key]["qty"] += $qty;
            } else {
                // Novo item
                $cart[$key] = [
                    "id" => $pid,
                    "product_id" => $pid,
                    "name" => $input["name"] ?? "Produto",
                    "price" => (float)($input["price"] ?? 0),
                    "price_promo" => (float)($input["price_promo"] ?? 0),
                    "image" => $input["image"] ?? "",
                    "qty" => $qty
                ];
            }
        }
        break;
    
    // ========================================================================
    // UPDATE - Atualizar quantidade
    // ========================================================================
    case "update":
        $pid = (int)($input["product_id"] ?? $input["id"] ?? 0);
        $qty = isset($input["qty"]) ? (int)$input["qty"] : null;
        $delta = (int)($input["delta"] ?? 0);
        
        if ($pid > 0) {
            $key = "p" . $pid;
            
            if (isset($cart[$key])) {
                if ($qty !== null) {
                    // Quantidade absoluta
                    $cart[$key]["qty"] = $qty;
                } else if ($delta !== 0) {
                    // Delta (incremento/decremento)
                    $cart[$key]["qty"] += $delta;
                }
                
                // Remover se qty <= 0
                if ($cart[$key]["qty"] <= 0) {
                    unset($cart[$key]);
                }
            }
        }
        break;
    
    // ========================================================================
    // REMOVE - Remover produto
    // ========================================================================
    case "remove":
        $pid = (int)($input["product_id"] ?? $input["id"] ?? 0);
        if ($pid > 0) {
            $key = "p" . $pid;
            unset($cart[$key]);
        }
        break;
    
    // ========================================================================
    // CLEAR - Limpar carrinho
    // ========================================================================
    case "clear":
        $cart = [];
        $_SESSION["market_cart"] = [];
        $_SESSION["cart"] = [];
        // Limpar também variáveis relacionadas
        unset($_SESSION["shipping_address_id"]);
        unset($_SESSION["payment_method"]);
        break;
    
    // ========================================================================
    // SAVE - Salvar carrinho completo (usado pelo index.php)
    // ========================================================================
    case "save":
        $new_cart = $input["cart"] ?? [];
        
        if (is_array($new_cart) && !empty($new_cart)) {
            $cart = [];
            
            foreach ($new_cart as $key => $item) {
                // Aceitar tanto array indexado quanto associativo
                $pid = (int)($item["product_id"] ?? $item["id"] ?? $key);
                
                if ($pid > 0) {
                    $cart["p" . $pid] = [
                        "id" => $pid,
                        "product_id" => $pid,
                        "name" => $item["name"] ?? "Produto",
                        "price" => (float)($item["price"] ?? 0),
                        "price_promo" => (float)($item["price_promo"] ?? 0),
                        "image" => $item["image"] ?? "",
                        "qty" => max(1, (int)($item["qty"] ?? $item["quantity"] ?? 1))
                    ];
                }
            }
        }
        break;
    
    // ========================================================================
    // GET - Retornar carrinho
    // ========================================================================
    case "get":
    case "list":
    default:
        // Apenas retorna os dados
        break;
}

// Calcular totais
$count = 0;
$subtotal = 0;
$items = [];

foreach ($cart as $key => $item) {
    $qty = (int)($item["qty"] ?? 1);
    $price = ($item["price_promo"] ?? 0) > 0 && $item["price_promo"] < $item["price"]
        ? (float)$item["price_promo"]
        : (float)($item["price"] ?? 0);
    
    $count += $qty;
    $subtotal += $price * $qty;
    
    // Normalizar item para output
    $items[] = [
        "id" => (int)($item["id"] ?? $item["product_id"]),
        "product_id" => (int)($item["product_id"] ?? $item["id"]),
        "name" => $item["name"] ?? "",
        "price" => (float)($item["price"] ?? 0),
        "price_promo" => (float)($item["price_promo"] ?? 0),
        "image" => $item["image"] ?? "",
        "qty" => $qty,
        "final_price" => $price,
        "line_total" => $price * $qty
    ];
}

// Frete
$frete_gratis_min = 99;
$frete_valor = 7.99;
$frete = $subtotal >= $frete_gratis_min ? 0 : $frete_valor;
$falta_frete = max(0, $frete_gratis_min - $subtotal);
$total = $subtotal + $frete;

// Resposta
echo json_encode([
    "success" => true,
    "action" => $action,
    "count" => $count,
    "items" => $items,
    "subtotal" => round($subtotal, 2),
    "shipping" => round($frete, 2),
    "shipping_free_min" => $frete_gratis_min,
    "shipping_remaining" => round($falta_frete, 2),
    "total" => round($total, 2),
    // Compatibilidade legado
    "data" => [
        "count" => $count,
        "items" => $items,
        "totals" => [
            "subtotal" => round($subtotal, 2),
            "shipping" => round($frete, 2),
            "total" => round($total, 2)
        ]
    ]
]);
