<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/substituir-item.php
 * Substitui um item indisponível por outro similar
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticação de Shopper APROVADO pelo RH
 * Header: Authorization: Bearer <token>
 *
 * Body: {
 *   "order_id": 10,
 *   "item_id": 5,
 *   "produto_substituto": {
 *     "nome": "Leite Integral Marca B",
 *     "preco": 5.99,
 *     "quantidade": 2,
 *     "barcode": "7891234567890"
 *   }
 * }
 */

require_once __DIR__ . "/../config/auth.php";

try {
    $input = getInput();
    $db = getDB();

    // Autenticação
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    $order_id = (int)($input["order_id"] ?? 0);
    $item_id = (int)($input["item_id"] ?? 0);
    $substituto = $input["produto_substituto"] ?? null;

    if (!$order_id || !$item_id) {
        response(false, null, "order_id e item_id são obrigatórios", 400);
    }

    if (!$substituto || !isset($substituto['nome'])) {
        response(false, null, "Dados do produto substituto são obrigatórios", 400);
    }

    // Sanitizar nome do substituto
    $substituto['nome'] = strip_tags(trim($substituto['nome']));
    // Validar preco do substituto (prevenir manipulacao)
    $preco_sub = floatval($substituto['preco'] ?? 0);
    if ($preco_sub <= 0 || $preco_sub > 99999) {
        response(false, null, "Preço do substituto inválido", 400);
    }

    // Verificar ownership
    $stmt = $db->prepare("SELECT shopper_id, status FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido || $pedido['shopper_id'] != $shopper_id) {
        response(false, null, "Você não tem permissão para este pedido", 403);
    }

    if (!in_array($pedido['status'], ['aceito', 'coletando', 'confirmed', 'shopping'])) {
        response(false, null, "Pedido não está em fase de coleta", 400);
    }

    // Verificar item original
    $stmt = $db->prepare("SELECT * FROM om_market_order_items WHERE id = ? AND order_id = ?");
    $stmt->execute([$item_id, $order_id]);
    $item_original = $stmt->fetch();

    if (!$item_original) {
        response(false, null, "Item não encontrado neste pedido", 404);
    }

    // Atualizar item com substituição
    $stmt = $db->prepare("
        UPDATE om_market_order_items SET
            status = 'replaced',
            substituted = 1,
            substitute_name = ?,
            substitute_price = ?,
            replacement_name = ?,
            replacement_price = ?,
            replacement_reason = 'indisponivel',
            collected = 1,
            collected_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $substituto['nome'],
        floatval($substituto['preco'] ?? 0),
        $substituto['nome'],
        floatval($substituto['preco'] ?? 0),
        $item_id
    ]);

    // Log
    logAudit('update', 'order_item', $item_id,
        ['status' => 'pendente'],
        ['status' => 'substituido', 'substituto' => $substituto],
        "Item substituído por shopper #$shopper_id"
    );

    response(true, [
        "item_id" => $item_id,
        "status" => "substituido",
        "original" => [
            "nome" => $item_original['name'] ?? 'N/A',
            "preco" => floatval($item_original['price'])
        ],
        "substituto" => $substituto
    ], "Item substituído com sucesso!");

} catch (Exception $e) {
    error_log("[substituir-item] Erro: " . $e->getMessage());
    response(false, null, "Erro ao substituir item. Tente novamente.", 500);
}
