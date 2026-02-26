<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API DO CARRINHO - OneMundo Mercado
 * ══════════════════════════════════════════════════════════════════════════════
 * 
 * CORRIGIDO: Adicionado action 'save' que o index.php envia
 * 
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Iniciar sessão com o mesmo nome do OpenCart
session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION["market_cart"])) $_SESSION["market_cart"] = [];

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $input["action"] ?? $_GET["action"] ?? "get";
$cart = &$_SESSION["market_cart"];

switch ($action) {
    
    // ========================================================================
    // SAVE - Recebe carrinho completo do index.php
    // ========================================================================
    case "save":
        $new_cart = $input["cart"] ?? [];
        if (!empty($new_cart)) {
            $cart = [];
            foreach ($new_cart as $key => $item) {
                $pid = $item["product_id"] ?? $key;
                $cart["p" . $pid] = [
                    "product_id" => (int)$pid,
                    "name" => $item["name"] ?? "Produto",
                    "price" => (float)($item["price"] ?? 0),
                    "price_promo" => (float)($item["price_promo"] ?? 0),
                    "image" => $item["image"] ?? "",
                    "qty" => (int)($item["qty"] ?? 1)
                ];
            }
        }
        break;
    
    // ========================================================================
    // ADD - Adicionar produto
    // ========================================================================
    case "add":
        $pid = (int)($input["product_id"] ?? 0);
        if ($pid > 0) {
            $key = "p" . $pid;
            if (isset($cart[$key])) {
                $cart[$key]["qty"]++;
            } else {
                $cart[$key] = [
                    "product_id" => $pid,
                    "name" => $input["name"] ?? "Produto",
                    "price" => (float)($input["price"] ?? 0),
                    "price_promo" => (float)($input["price_promo"] ?? 0),
                    "image" => $input["image"] ?? "",
                    "qty" => (int)($input["quantity"] ?? 1)
                ];
            }
        }
        break;
    
    // ========================================================================
    // UPDATE - Atualizar quantidade
    // ========================================================================
    case "update":
        $pid = (int)($input["product_id"] ?? 0);
        $key = $input["key"] ?? ($pid > 0 ? "p" . $pid : "");
        $delta = (int)($input["delta"] ?? 0);
        $qty = isset($input["quantity"]) ? (int)$input["quantity"] : null;
        
        if (isset($cart[$key])) {
            if ($qty !== null) {
                $cart[$key]["qty"] = $qty;
            } else {
                $cart[$key]["qty"] += $delta;
            }
            if ($cart[$key]["qty"] <= 0) unset($cart[$key]);
        }
        break;
    
    // ========================================================================
    // REMOVE - Remover produto
    // ========================================================================
    case "remove":
        $pid = (int)($input["product_id"] ?? 0);
        $key = $input["key"] ?? ($pid > 0 ? "p" . $pid : "");
        unset($cart[$key]);
        break;
    
    // ========================================================================
    // CLEAR - Limpar carrinho
    // ========================================================================
    case "clear":
        $cart = [];
        break;
    
    // ========================================================================
    // GET/LIST - Retornar carrinho
    // ========================================================================
    case "get":
    case "list":
    default:
        // Apenas retorna os dados abaixo
        break;
}

// Calcular totais
$count = 0; 
$subtotal = 0;
foreach ($cart as $item) {
    $count += $item["qty"];
    $price = $item["price_promo"] > 0 ? $item["price_promo"] : $item["price"];
    $subtotal += $price * $item["qty"];
}

$frete = $subtotal >= 99 ? 0 : 5.99;
$frete_gratis_falta = max(0, 99 - $subtotal);

echo json_encode([
    "success" => true,
    "action" => $action,
    "cartCount" => $count,
    "cart" => array_values($cart),
    "items" => array_values($cart), // Alias para compatibilidade
    "totals" => [
        "subtotal" => round($subtotal, 2), 
        "frete" => $frete, 
        "frete_gratis_falta" => round($frete_gratis_falta, 2),
        "total" => round($subtotal + $frete, 2)
    ]
]);
