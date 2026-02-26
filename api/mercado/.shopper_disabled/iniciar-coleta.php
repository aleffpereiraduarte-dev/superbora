<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/iniciar-coleta.php
 * Shopper chegou no mercado e inicia a coleta dos itens
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticação de Shopper APROVADO pelo RH
 * Header: Authorization: Bearer <token>
 *
 * Body: { "order_id": 10 }
 *
 * SEGURANÇA:
 * - ✅ Autenticação obrigatória
 * - ✅ Verificação de ownership
 * - ✅ Validação de transição de estado
 * - ✅ Prepared statements
 */

require_once __DIR__ . "/../config/auth.php";

try {
    $input = getInput();
    $db = getDB();

    // Autenticação
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    $order_id = (int)($input["order_id"] ?? 0);

    if (!$order_id) {
        response(false, null, "order_id é obrigatório", 400);
    }

    // Verificar pedido e ownership
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        response(false, null, "Pedido não encontrado", 404);
    }

    if ((int)$pedido['shopper_id'] !== (int)$shopper_id) {
        response(false, null, "Você não tem permissão para este pedido", 403);
    }

    // Validar transição de estado
    try {
        om_status()->validateOrderTransition($pedido['status'], 'coletando');
    } catch (InvalidArgumentException $e) {
        error_log("[iniciar-coleta] Transicao invalida: " . $e->getMessage());
        response(false, null, 'Operacao nao permitida para o status atual do pedido', 409);
    }

    // Atualizar status
    $stmt = $db->prepare("
        UPDATE om_market_orders SET
            status = 'coletando',
            started_collecting_at = NOW()
        WHERE order_id = ?
    ");
    $stmt->execute([$order_id]);

    // Log
    logAudit('update', 'order', $order_id,
        ['status' => $pedido['status']],
        ['status' => 'coletando'],
        "Coleta iniciada por shopper #$shopper_id"
    );

    // ═══════════════════════════════════════════════════════════════════
    // NOTIFICACOES EM TEMPO REAL
    // ═══════════════════════════════════════════════════════════════════
    if (function_exists('om_realtime')) {
        om_realtime()->coletaIniciada(
            $order_id,
            (int)$pedido['partner_id'],
            (int)$pedido['customer_id'],
            $shopper_id
        );
    }

    // Buscar itens
    $stmt = $db->prepare("
        SELECT i.*, p.name, p.image, p.barcode
        FROM om_market_order_items i
        INNER JOIN om_market_products p ON i.product_id = p.product_id
        WHERE i.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $itens = $stmt->fetchAll();

    response(true, [
        "order_id" => $order_id,
        "status" => "coletando",
        "iniciado_em" => date('c'),
        "total_itens" => count($itens),
        "itens" => array_map(function($item) {
            return [
                "id" => $item["id"],
                "nome" => $item["name"],
                "quantidade" => $item["quantity"],
                "preco" => floatval($item["price"]),
                "imagem" => $item["image"],
                "barcode" => $item["barcode"],
                "coletado" => (bool)$item["collected"]
            ];
        }, $itens)
    ], "Coleta iniciada! Escaneie os produtos.");

} catch (Exception $e) {
    error_log("[iniciar-coleta] Erro: " . $e->getMessage());
    response(false, null, "Erro ao iniciar coleta. Tente novamente.", 500);
}
