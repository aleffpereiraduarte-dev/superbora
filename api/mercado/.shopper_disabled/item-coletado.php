<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/item-coletado.php
 * Marca um item do pedido como coletado
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticação de Shopper APROVADO pelo RH
 * Header: Authorization: Bearer <token>
 *
 * Body: {
 *   "order_id": 10,
 *   "item_id": 5,
 *   "coletado": true,
 *   "quantidade_real": 2  // opcional
 * }
 *
 * SEGURANÇA:
 * - ✅ Autenticação obrigatória
 * - ✅ Verificação de ownership (shopper do pedido)
 * - ✅ Proteção contra divisão por zero
 * - ✅ Prepared statements
 */

require_once __DIR__ . "/../config/auth.php";

try {
    $input = getInput();
    $db = getDB();

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICAÇÃO
    // ═══════════════════════════════════════════════════════════════════
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    $order_id = (int)($input["order_id"] ?? 0);
    $item_id = (int)($input["item_id"] ?? 0);
    $coletado = $input["coletado"] ?? true;
    $qtd_real = isset($input["quantidade_real"]) ? (int)$input["quantidade_real"] : null;

    if (!$order_id || !$item_id) {
        response(false, null, "order_id e item_id são obrigatórios", 400);
    }

    // Verificar se o pedido pertence a este shopper
    $stmt = $db->prepare("SELECT shopper_id, status FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        response(false, null, "Pedido não encontrado", 404);
    }

    if ($pedido['shopper_id'] != $shopper_id) {
        response(false, null, "Você não tem permissão para modificar este pedido", 403);
    }

    // Verificar se pedido está em status de coleta
    if (!in_array($pedido['status'], ['aceito', 'coletando', 'confirmed', 'shopping'])) {
        response(false, null, "Pedido não está em fase de coleta. Status: " . $pedido['status'], 400);
    }

    // Verificar se item pertence ao pedido
    $stmt = $db->prepare("SELECT id, quantity FROM om_market_order_items WHERE id = ? AND order_id = ?");
    $stmt->execute([$item_id, $order_id]);
    $item = $stmt->fetch();

    if (!$item) {
        response(false, null, "Item não encontrado neste pedido", 404);
    }

    // Atualizar item
    $sql = "UPDATE om_market_order_items SET
            collected = ?,
            collected_quantity = COALESCE(?, quantity),
            collected_at = NOW()
            WHERE id = ?";

    $stmt = $db->prepare($sql);
    $stmt->execute([$coletado ? 1 : 0, $qtd_real, $item_id]);

    // Verificar progresso - PROTEÇÃO CONTRA DIVISÃO POR ZERO
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $total = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_order_items WHERE order_id = ? AND collected = 1");
    $stmt->execute([$order_id]);
    $coletados = (int)$stmt->fetchColumn();

    // Proteção contra divisão por zero
    $porcentagem = $total > 0 ? round(($coletados / $total) * 100) : 0;

    // Se status ainda é 'aceito' e começou a coletar, atualizar para 'coletando'
    if (in_array($pedido['status'], ['aceito', 'confirmed']) && $coletados > 0) {
        $stmt = $db->prepare("UPDATE om_market_orders SET status = 'coletando', started_collecting_at = NOW(), updated_at = NOW(), date_modified = NOW() WHERE order_id = ?");
        $stmt->execute([$order_id]);
    }

    response(true, [
        "item_id" => $item_id,
        "coletado" => (bool)$coletado,
        "quantidade_real" => $qtd_real ?? $item['quantity'],
        "progresso" => "$coletados/$total",
        "porcentagem" => $porcentagem,
        "coleta_completa" => $coletados === $total
    ], $coletado ? "Item marcado como coletado!" : "Item desmarcado.");

} catch (Exception $e) {
    error_log("[item-coletado] Erro: " . $e->getMessage());
    response(false, null, "Erro ao atualizar item. Tente novamente.", 500);
}
