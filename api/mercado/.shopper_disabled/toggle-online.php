<?php
/**
 * POST /api/mercado/shopper/toggle-online.php
 * Alterna status online/offline do shopper
 * Body: { "is_online": true/false } (opcional - se omitido, faz toggle)
 */
require_once __DIR__ . "/../config/auth.php";
try {
    $db = getDB(); $auth = requireShopperAuth(); $shopper_id = $auth["uid"];
    if ($_SERVER["REQUEST_METHOD"] !== "POST") { response(false, null, "Metodo nao permitido", 405); }
    $stmt = $db->prepare("SELECT is_online, disponivel, pedido_atual_id, status FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]); $shopper = $stmt->fetch();
    if (!$shopper) { response(false, null, "Shopper nao encontrado", 404); }
    if ((int)$shopper["status"] !== 1) { response(false, null, "Sua conta nao esta ativa. Entre em contato com o suporte.", 403); }
    $input = getInput();
    $current_online = (bool)($shopper["is_online"] ?? $shopper["disponivel"] ?? false);
    $new_online = isset($input["is_online"]) ? (bool)$input["is_online"] : !$current_online;
    if (!$new_online && $shopper["pedido_atual_id"]) {
        response(false, null, "Voce nao pode ficar offline com um pedido ativo. Finalize o pedido primeiro.", 400);
    }
    $stmt = $db->prepare("UPDATE om_market_shoppers SET is_online = ?, disponivel = ?, last_login = NOW() WHERE shopper_id = ?");
    $stmt->execute([(int)$new_online, (int)$new_online, $shopper_id]);
    logAudit("update", "shopper", $shopper_id, ["is_online" => $current_online], ["is_online" => $new_online], "Status online alterado pelo shopper #$shopper_id");
    response(true, ["is_online" => $new_online, "disponivel" => $new_online, "message" => $new_online ? "Voce esta online e disponivel para pedidos!" : "Voce esta offline."], $new_online ? "Voce ficou online" : "Voce ficou offline");
} catch (Exception $e) { error_log("[shopper/toggle-online] Erro: " . $e->getMessage()); response(false, null, "Erro ao alterar status", 500); }
