<?php
/**
 * GET /api/mercado/pedido/pode-adicionar.php?order_id=1
 * Verifica se o cliente pode adicionar mais itens ao pedido
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

try {
    $customer_id = requireCustomerAuth();
    $order_id = (int)($_GET["order_id"] ?? 0);
    if (!$order_id) response(false, null, "order_id obrigatorio", 400);

    $db = getDB();
    $stmt = $db->prepare("
        SELECT status, customer_id, date_added, is_pickup, delivery_type
        FROM om_market_orders WHERE order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || (int)$order['customer_id'] !== $customer_id) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Only BoraUm delivery orders can add items
    $isPickup = (bool)($order['is_pickup'] ?? false);
    $deliveryType = $order['delivery_type'] ?? '';

    if ($isPickup || $deliveryType === 'retirada') {
        response(true, [
            'pode_adicionar' => false,
            'motivo' => 'Não disponível para pedidos de retirada',
        ]);
    }

    if ($deliveryType === 'proprio') {
        response(true, [
            'pode_adicionar' => false,
            'motivo' => 'Disponível apenas para entregas BoraUm',
        ]);
    }

    if ($deliveryType !== 'boraum') {
        response(true, [
            'pode_adicionar' => false,
            'motivo' => 'Disponível apenas para entregas BoraUm',
        ]);
    }

    // Can add items until order goes out for delivery
    $addableStatuses = ['pendente', 'confirmado', 'aceito', 'preparando', 'em_preparo', 'pronto'];
    $statusOk = in_array($order['status'], $addableStatuses);

    // 30-minute time window from order creation
    $canAdd = false;
    $motivo = null;
    $tempoRestante = null;

    if (!$statusOk) {
        $motivo = "Pedido ja saiu para entrega";
    } else {
        $createdAt = new DateTime($order['date_added']);
        $now = new DateTime();
        $limitTime = (clone $createdAt)->modify('+30 minutes');

        if ($now > $limitTime) {
            $motivo = "Tempo limite para adicionar itens excedido";
        } else {
            $canAdd = true;
            $remainingSeconds = $limitTime->getTimestamp() - $now->getTimestamp();
            $tempoRestante = [
                'minutos' => floor($remainingSeconds / 60),
                'segundos' => $remainingSeconds % 60,
                'total_segundos' => $remainingSeconds,
            ];
        }
    }

    // Get partner name
    $partnerName = '';
    try {
        $stmtP = $db->prepare("SELECT trade_name, name FROM om_market_partners WHERE partner_id = (SELECT partner_id FROM om_market_orders WHERE order_id = ?)");
        $stmtP->execute([$order_id]);
        $p = $stmtP->fetch(PDO::FETCH_ASSOC);
        $partnerName = $p['trade_name'] ?: $p['name'] ?: '';
    } catch (Exception $e) {}

    $data = ['pode_adicionar' => $canAdd, 'partner_name' => $partnerName];
    if ($motivo) $data['motivo'] = $motivo;
    if ($tempoRestante) $data['tempo_restante'] = $tempoRestante;

    response(true, $data);

} catch (Exception $e) {
    error_log("[pedido/pode-adicionar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
