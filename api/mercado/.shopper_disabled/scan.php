<?php
require_once __DIR__ . "/../config/auth.php";
try {
    $db = getDB(); $auth = requireShopperAuth(); $shopper_id = $auth["uid"];
    if ($_SERVER["REQUEST_METHOD"] !== "POST") { response(false, null, "Metodo nao permitido", 405); }
    $input = getInput(); $barcode = trim($input["barcode"] ?? ""); $order_id = (int)($input["order_id"] ?? 0);
    if (!$barcode) { response(false, null, "barcode e obrigatorio", 400); }
    $stmt = $db->prepare("SELECT product_id, name, description, price, image, category, barcode, weight, unit FROM om_market_products_base WHERE barcode = ? LIMIT 1");
    $stmt->execute([$barcode]); $product = $stmt->fetch();
    if (!$product) { $stmt = $db->prepare("SELECT product_id, name, description, price, image, category, barcode FROM om_market_products WHERE barcode = ? LIMIT 1"); $stmt->execute([$barcode]); $product = $stmt->fetch(); }
    if (!$product) { response(false, null, "Produto nao encontrado com este codigo de barras", 404); }
    $in_order = false; $order_item = null;
    if ($order_id) {
        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND shopper_id = ?"); $stmt->execute([$order_id, $shopper_id]);
        if (!$stmt->fetch()) { response(false, null, "Pedido nao encontrado ou nao pertence a voce", 403); }
        $stmt = $db->prepare("SELECT id, name, quantity, price, collected FROM om_market_order_items WHERE order_id = ? AND product_id = ?"); $stmt->execute([$order_id, $product["product_id"]]); $item = $stmt->fetch();
        if ($item) { $in_order = true; $order_item = ["item_id" => (int)$item["id"], "name" => $item["name"], "quantity" => (int)$item["quantity"], "price" => floatval($item["price"]), "collected" => (bool)$item["collected"]]; }
    }
    response(true, ["product" => ["product_id" => (int)$product["product_id"], "name" => $product["name"], "description" => $product["description"] ?? null, "price" => floatval($product["price"]), "image" => $product["image"] ?? null, "category" => $product["category"] ?? null, "barcode" => $product["barcode"], "weight" => $product["weight"] ?? null, "unit" => $product["unit"] ?? null], "in_order" => $in_order, "order_item" => $order_item], $in_order ? "Produto encontrado e esta no pedido!" : "Produto encontrado");
} catch (Exception $e) { error_log("[shopper/scan] Erro: " . $e->getMessage()); response(false, null, "Erro ao escanear produto", 500); }
