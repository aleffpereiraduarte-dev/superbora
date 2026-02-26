<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/finalizar-coleta.php
 * Shopper finalizou a coleta e vai para o caixa/entrega
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
require_once __DIR__ . "/../helpers/delivery.php";

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

    if ($pedido['shopper_id'] != $shopper_id) {
        response(false, null, "Você não tem permissão para este pedido", 403);
    }

    // Validar transição de estado
    try {
        om_status()->validateOrderTransition($pedido['status'], 'coleta_finalizada');
    } catch (InvalidArgumentException $e) {
        error_log("[finalizar-coleta] Transicao invalida: " . $e->getMessage());
        response(false, null, 'Operacao nao permitida para o status atual do pedido', 409);
    }

    // Verificar se todos itens foram coletados (ou marcados como indisponível)
    $stmt = $db->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN collected = 1 OR status = 'indisponivel' THEN 1 ELSE 0 END) as processados
        FROM om_market_order_items
        WHERE order_id = ?
    ");
    $stmt->execute([$order_id]);
    $progresso = $stmt->fetch();

    if ($progresso['total'] > $progresso['processados']) {
        $faltam = $progresso['total'] - $progresso['processados'];
        response(false, [
            "total" => $progresso['total'],
            "processados" => $progresso['processados'],
            "faltam" => $faltam
        ], "Ainda faltam $faltam itens para processar", 400);
    }

    // Atualizar status
    $stmt = $db->prepare("
        UPDATE om_market_orders SET
            status = 'coleta_finalizada',
            finished_collecting_at = NOW()
        WHERE order_id = ?
    ");
    $stmt->execute([$order_id]);

    // Log
    logAudit('update', 'order', $order_id,
        ['status' => $pedido['status']],
        ['status' => 'coleta_finalizada'],
        "Coleta finalizada por shopper #$shopper_id"
    );

    // Auto-dispatch para BoraUm se aplicavel
    $entrega = null;
    $partner_categoria = $pedido['partner_categoria'] ?? '';
    $categorias_mercado = array_filter(['mercado', 'supermercado', ''], 'strlen');
    if ($partner_categoria && !in_array($partner_categoria, $categorias_mercado)) {
        $entrega = dispatchToBoraUm($db, $pedido);
    }

    response(true, [
        "order_id" => $order_id,
        "status" => "coleta_finalizada",
        "finalizado_em" => date('c'),
        "entrega" => $entrega,
        "proximo_passo" => "Vá ao caixa para finalizar a compra e depois inicie a entrega"
    ], "Coleta finalizada! Agora vá ao caixa.");

} catch (Exception $e) {
    error_log("[finalizar-coleta] Erro: " . $e->getMessage());
    response(false, null, "Erro ao finalizar coleta. Tente novamente.", 500);
}
