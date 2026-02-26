<?php
/**
 * POST /api/mercado/produtos/toggle-estoque.php
 * Pausar/Reativar item (toggle disponibilidade)
 *
 * Body: { "product_id": 1, "disponivel": 0|1 }
 * Requer sessao de parceiro
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/csrf.php";

try {
    session_start();
    verifyCsrf();
    $db = getDB();

    $mercado_id = $_SESSION['mercado_id'] ?? 0;
    if (!$mercado_id) {
        response(false, null, "Nao autorizado", 401);
    }

    $input = getInput();
    $product_id = (int)($input['product_id'] ?? 0);
    $disponivel = isset($input['disponivel']) ? (int)$input['disponivel'] : null;

    if (!$product_id) {
        response(false, null, "product_id obrigatorio", 400);
    }

    // Verificar se o produto pertence ao parceiro
    $stmt = $db->prepare("SELECT product_id, available FROM om_market_products WHERE product_id = ? AND partner_id = ?");
    $stmt->execute([$product_id, $mercado_id]);
    $produto = $stmt->fetch();

    if (!$produto) {
        response(false, null, "Produto nao encontrado", 404);
    }

    // Se nao especificou, faz toggle
    if ($disponivel === null) {
        $disponivel = $produto['available'] ? 0 : 1;
    }
    $disponivel = $disponivel ? 1 : 0;

    $unavailable_at = $disponivel ? null : date('Y-m-d H:i:s');

    $stmt = $db->prepare("UPDATE om_market_products SET available = ?, unavailable_at = ? WHERE product_id = ? AND partner_id = ?");
    $stmt->execute([$disponivel, $unavailable_at, $product_id, $mercado_id]);

    // Limpar cache de produtos
    if (class_exists('CacheHelper')) {
        CacheHelper::forgetPattern("mercado_produtos_");
    }

    response(true, [
        "product_id" => $product_id,
        "available" => $disponivel,
        "unavailable_at" => $unavailable_at
    ], $disponivel ? "Produto reativado" : "Produto pausado");

} catch (Exception $e) {
    error_log("[toggle-estoque] Erro: " . $e->getMessage());
    response(false, null, "Erro ao atualizar disponibilidade", 500);
}
