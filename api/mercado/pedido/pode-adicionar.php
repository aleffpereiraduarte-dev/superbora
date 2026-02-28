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
        SELECT status, customer_id, date_added FROM om_market_orders WHERE order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || (int)$order['customer_id'] !== $customer_id) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Can only add items if order is in early stages
    $addableStatuses = ['pendente', 'confirmado', 'aceito'];
    $statusOk = in_array($order['status'], $addableStatuses);

    // 30-minute time window from order creation
    $canAdd = false;
    $motivo = null;
    $tempoRestante = null;

    if (!$statusOk) {
        $motivo = "Pedido nao pode ser editado (status: {$order['status']})";
    } else {
        $createdAt = new DateTime($order['date_added']);
        $now = new DateTime();
        $limitTime = (clone $createdAt)->modify('+30 minutes');
        $diff = $now->diff($limitTime);

        if ($now > $limitTime) {
            $motivo = "Tempo limite excedido";
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

    $data = ['pode_adicionar' => $canAdd];
    if ($motivo) $data['motivo'] = $motivo;
    if ($tempoRestante) $data['tempo_restante'] = $tempoRestante;

    response(true, $data);

} catch (Exception $e) {
    error_log("[pedido/pode-adicionar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
