<?php
/**
 * POST /api/mercado/shopper/ai-substituicao.php
 * Retorna sugestoes de substituicao para um produto indisponivel
 * Body: { "order_id": 123, "product_id": 456, "product_name": "Leite Integral", "category": "laticinios" }
 */
require_once __DIR__ . "/../config/auth.php";
try {
    $db = getDB(); $auth = requireShopperAuth(); $shopper_id = $auth["uid"];
    if ($_SERVER["REQUEST_METHOD"] !== "POST") { response(false, null, "Metodo nao permitido", 405); }
    $input = getInput();
    $order_id = (int)($input["order_id"] ?? 0);
    $product_id = (int)($input["product_id"] ?? 0);
    $product_name = trim($input["product_name"] ?? "");
    $category = trim($input["category"] ?? "");
    if (!$product_name && !$product_id) { response(false, null, "product_name ou product_id e obrigatorio", 400); }
    if ($order_id) {
        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
        $stmt->execute([$order_id, $shopper_id]);
        if (!$stmt->fetch()) { response(false, null, "Pedido nao encontrado ou nao pertence a voce", 403); }
    }
    if ($product_id && !$product_name) {
        $stmt = $db->prepare("SELECT name, category FROM om_market_products_base WHERE product_id = ? LIMIT 1");
        $stmt->execute([$product_id]); $orig = $stmt->fetch();
        if ($orig) { $product_name = $orig["name"]; if (!$category) $category = $orig["category"] ?? ""; }
        if (!$product_name) {
            $stmt = $db->prepare("SELECT name, category FROM om_market_products WHERE product_id = ? LIMIT 1");
            $stmt->execute([$product_id]); $orig = $stmt->fetch();
            if ($orig) { $product_name = $orig["name"]; if (!$category) $category = $orig["category"] ?? ""; }
        }
    }
    if (!$product_name) { response(false, null, "Produto nao encontrado", 404); }
    $suggestions = [];
    if ($category) {
        $stmt = $db->prepare("SELECT product_id, name, description, price, image, category, barcode, weight, unit FROM om_market_products_base WHERE category = ? AND name != ? AND product_id != ? ORDER BY name ASC LIMIT 6");
        $stmt->execute([$category, $product_name, $product_id ?: 0]);
        $suggestions = $stmt->fetchAll();
    }
    if (count($suggestions) < 3) {
        $words = array_filter(explode(" ", $product_name), function($w) { return strlen($w) >= 3; });
        if (!empty($words)) {
            $search_term = $words[0];
            $existing_ids = array_column($suggestions, "product_id");
            $existing_ids[] = $product_id ?: 0;
            $placeholders = implode(",", array_fill(0, count($existing_ids), "?"));
            $stmt = $db->prepare("SELECT product_id, name, description, price, image, category, barcode, weight, unit FROM om_market_products_base WHERE name LIKE ? AND product_id NOT IN ($placeholders) ORDER BY name ASC LIMIT ?");
            $params = ["%" . $search_term . "%"];
            $params = array_merge($params, $existing_ids);
            $params[] = 6 - count($suggestions);
            $stmt->execute($params);
            $extra = $stmt->fetchAll();
            $suggestions = array_merge($suggestions, $extra);
        }
    }
    $result = array_map(function($s) {
        return ["product_id" => (int)$s["product_id"], "name" => $s["name"], "description" => $s["description"] ?? null, "price" => floatval($s["price"]), "image" => $s["image"] ?? null, "category" => $s["category"] ?? null, "barcode" => $s["barcode"] ?? null, "weight" => $s["weight"] ?? null, "unit" => $s["unit"] ?? null, "similarity" => "sugestao"];
    }, $suggestions);
    logAudit("search", "substituicao", $product_id ?: null, null, ["product_name" => $product_name, "category" => $category, "results" => count($result)], "Busca de substituicao pelo shopper #$shopper_id");
    response(true, ["original_product" => $product_name, "original_category" => $category, "suggestions" => $result, "total" => count($result)], count($result) > 0 ? "Sugestoes de substituicao encontradas" : "Nenhuma sugestao encontrada para este produto");
} catch (Exception $e) { error_log("[shopper/ai-substituicao] Erro: " . $e->getMessage()); response(false, null, "Erro ao buscar sugestoes", 500); }
