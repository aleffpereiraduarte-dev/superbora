<?php
/**
 * POST /api/mercado/pedidos/cancelar-motivo.php
 * Cancelar pedido com motivo categorizado (estilo iFood)
 *
 * GET — Retorna opcoes de motivo de cancelamento
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

$CANCEL_REASONS = [
    'mudei_ideia' => ['label' => 'Mudei de ideia', 'refund' => 'none', 'penalty' => false],
    'demora_preparo' => ['label' => 'Demora no preparo', 'refund' => 'full', 'penalty' => false],
    'demora_entrega' => ['label' => 'Demora na entrega', 'refund' => 'full', 'penalty' => false],
    'pedido_errado' => ['label' => 'Fiz o pedido errado', 'refund' => 'partial', 'penalty' => false],
    'problema_pagamento' => ['label' => 'Problema no pagamento', 'refund' => 'full', 'penalty' => false],
    'loja_fechou' => ['label' => 'Loja fechou / sem estoque', 'refund' => 'full', 'penalty' => false],
    'endereco_errado' => ['label' => 'Endereco errado', 'refund' => 'partial', 'penalty' => false],
    'duplicado' => ['label' => 'Pedido duplicado', 'refund' => 'full', 'penalty' => false],
    'outro' => ['label' => 'Outro motivo', 'refund' => 'review', 'penalty' => false],
];

try {
    $db = getDB();

    // GET: retornar opcoes de cancelamento
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $customerId = getCustomerIdFromToken();
        if (!$customerId) response(false, null, 'Nao autorizado', 401);

        $orderId = (int)($_GET['order_id'] ?? 0);
        $reasons = array_map(function($key, $r) {
            return ['code' => $key, 'label' => $r['label']];
        }, array_keys($CANCEL_REASONS), $CANCEL_REASONS);

        // Verificar se pedido pode ser cancelado
        $canCancel = true;
        $message = '';
        if ($orderId) {
            $stmt = $db->prepare("SELECT status, delivering_at FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
            $stmt->execute([$orderId, $customerId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order) {
                $nonCancellable = ['entregue', 'cancelado', 'cancelled'];
                if (in_array($order['status'], $nonCancellable)) {
                    $canCancel = false;
                    $message = 'Pedido nao pode mais ser cancelado';
                }
                if ($order['delivering_at']) {
                    $message = 'Pedido ja esta a caminho. O cancelamento pode nao gerar reembolso total.';
                }
            }
        }

        response(true, ['reasons' => $reasons, 'can_cancel' => $canCancel, 'message' => $message]);
    }

    // POST: efetuar cancelamento
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $customerId = getCustomerIdFromToken();
        if (!$customerId) response(false, null, 'Nao autorizado', 401);

        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = (int)($input['order_id'] ?? 0);
        $reasonCode = $input['reason_code'] ?? 'outro';
        $reasonText = trim($input['reason_text'] ?? '');

        if (!$orderId) response(false, null, 'order_id obrigatorio', 400);

        // Verificar pedido
        $stmt = $db->prepare("SELECT order_id, status, total, delivery_fee, subtotal, payment_method, delivering_at, partner_id
            FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) response(false, null, 'Pedido nao encontrado', 404);

        $nonCancellable = ['entregue', 'cancelado', 'cancelled'];
        if (in_array($order['status'], $nonCancellable)) {
            response(false, null, 'Pedido nao pode mais ser cancelado', 400);
        }

        $reasonInfo = $CANCEL_REASONS[$reasonCode] ?? $CANCEL_REASONS['outro'];
        $reasonLabel = $reasonInfo['label'] . ($reasonText ? " - {$reasonText}" : '');

        // Determinar reembolso
        $refundType = $reasonInfo['refund'];
        $refundAmount = 0;
        if ($order['delivering_at']) $refundType = 'partial'; // Ja saiu pra entrega

        switch ($refundType) {
            case 'full':
                $refundAmount = (float)$order['total'];
                break;
            case 'partial':
                $refundAmount = (float)$order['subtotal']; // Devolve subtotal, nao frete
                break;
            case 'review':
                $refundAmount = 0; // Analise manual
                break;
        }

        $db->beginTransaction();

        $db->prepare("UPDATE om_market_orders SET
            status = 'cancelado',
            cancelled_at = NOW(),
            cancellation_reason = ?,
            cancel_category = ?,
            refund_status = CASE WHEN ? > 0 THEN 'pending' ELSE refund_status END,
            refund_amount = ?,
            date_modified = NOW()
            WHERE order_id = ?")
            ->execute([$reasonLabel, $reasonCode, $refundAmount, $refundAmount, $orderId]);

        // Restaurar estoque
        $items = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
        $items->execute([$orderId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $db->prepare("UPDATE om_market_products SET quantity = quantity + ?, stock = stock + ? WHERE product_id = ?")
                ->execute([$item['quantity'], $item['quantity'], $item['product_id']]);
        }

        // Devolver pontos de fidelidade usados (best-effort, table may not have status column)
        try {
            $db->prepare("DELETE FROM om_market_loyalty_transactions WHERE reference_id = CAST(? AS TEXT) AND type = 'redeem'")
                ->execute([$orderId]);
        } catch (Exception $loyaltyEx) {
            // Non-critical: loyalty table schema may differ
            error_log('[CancelarMotivo] Loyalty reversal skipped: ' . $loyaltyEx->getMessage());
        }

        $db->commit();

        response(true, [
            'order_id' => $orderId,
            'status' => 'cancelado',
            'reason' => $reasonLabel,
            'refund_amount' => $refundAmount,
            'refund_status' => $refundAmount > 0 ? 'pending' : null,
            'message' => $refundAmount > 0
                ? "Pedido cancelado. Reembolso de R$" . number_format($refundAmount, 2, ',', '.') . " sera processado."
                : "Pedido cancelado.",
        ]);
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[CancelarMotivo] " . $e->getMessage());
    response(false, null, 'Erro ao cancelar', 500);
}
