<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/iniciar-entrega.php
 * Shopper saiu do mercado e está indo entregar
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
require_once dirname(__DIR__, 3) . "/includes/classes/PushNotification.php";

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
        om_status()->validateOrderTransition($pedido['status'], 'em_entrega');
    } catch (InvalidArgumentException $e) {
        error_log("[iniciar-entrega] Transicao invalida: " . $e->getMessage());
        response(false, null, 'Operacao nao permitida para o status atual do pedido', 409);
    }

    // Atualizar status
    $stmt = $db->prepare("
        UPDATE om_market_orders SET
            status = 'em_entrega',
            started_delivery_at = NOW()
        WHERE order_id = ?
    ");
    $stmt->execute([$order_id]);

    // Log
    logAudit('update', 'order', $order_id,
        ['status' => $pedido['status']],
        ['status' => 'em_entrega'],
        "Entrega iniciada por shopper #$shopper_id"
    );

    // ═══════════════════════════════════════════════════════════════════
    // NOTIFICACOES EM TEMPO REAL
    // ═══════════════════════════════════════════════════════════════════
    // Buscar nome do shopper
    $stmt = $db->prepare("SELECT name FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $shopperData = $stmt->fetch();
    $shopper_nome = $shopperData['name'] ?? "Shopper #$shopper_id";

    if (function_exists('om_realtime')) {
        om_realtime()->entregaIniciada(
            $order_id,
            (int)$pedido['partner_id'],
            (int)$pedido['customer_id'],
            $shopper_id,
            $shopper_nome
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // PUSH NOTIFICATIONS (FCM) - Notifica cliente
    // ═══════════════════════════════════════════════════════════════════
    try {
        PushNotification::getInstance()->setDb($db);
        om_push()->notifyDeliveryStarted(
            $order_id,
            (int)$pedido['customer_id']
        );
    } catch (Exception $pushError) {
        // Log but don't fail the request
        error_log("[iniciar-entrega] Push notification error: " . $pushError->getMessage());
    }

    response(true, [
        "order_id" => $order_id,
        "status" => "em_entrega",
        "iniciado_em" => date('c'),
        "endereco_entrega" => $pedido['delivery_address'],
        "codigo_entrega" => $pedido['codigo_entrega'],
        "cliente" => [
            "nome" => $pedido['customer_name'],
            "telefone" => $pedido['customer_phone']
        ]
    ], "Entrega iniciada! Siga para o endereço do cliente.");

} catch (Exception $e) {
    error_log("[iniciar-entrega] Erro: " . $e->getMessage());
    response(false, null, "Erro ao iniciar entrega. Tente novamente.", 500);
}
