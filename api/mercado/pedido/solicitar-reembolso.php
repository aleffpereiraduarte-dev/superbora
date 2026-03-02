<?php
/**
 * POST /api/mercado/pedido/solicitar-reembolso.php
 * Cliente solicita reembolso para pedidos entregues com problemas.
 *
 * Body: {
 *   "order_id": 123,
 *   "reason": "Produtos vieram errados",
 *   "items": [{"product_id": 45, "quantity": 1}],   // opcional: itens especificos
 *   "photos": ["https://..."]                         // opcional: fotos como evidencia
 * }
 *
 * Regras:
 * - Apenas pedidos entregues (status = 'entregue')
 * - Dentro de 24 horas da entrega
 * - Auto-aprovado se total < R$30
 * - Caso contrario, status = 'pending' para revisao admin
 * - Cliente so pode solicitar 1 vez por pedido
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once __DIR__ . "/../helpers/notify.php";
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $customer_id = requireCustomerAuth();

    $input = getInput();
    $order_id = intval($input['order_id'] ?? 0);
    $reason = strip_tags(trim(substr($input['reason'] ?? '', 0, 1000)));
    $items = $input['items'] ?? [];
    $photos = $input['photos'] ?? [];

    // Validacoes basicas
    if (!$order_id) response(false, null, "order_id obrigatorio", 400);
    if (empty($reason)) response(false, null, "Motivo do reembolso obrigatorio", 400);
    if (strlen($reason) < 10) response(false, null, "Descreva o motivo com mais detalhes (min. 10 caracteres)", 400);

    // Sanitize photos array (max 5 photos, must be valid URLs)
    $validPhotos = [];
    if (is_array($photos)) {
        foreach (array_slice($photos, 0, 5) as $photo) {
            $photo = trim($photo);
            if ($photo && preg_match('#^https?://#i', $photo)) {
                $validPhotos[] = $photo;
            }
        }
    }

    // Buscar pedido do cliente
    $stmtOrder = $db->prepare("
        SELECT o.order_id, o.order_number, o.status, o.total, o.subtotal, o.delivery_fee,
               o.delivered_at, o.date_added, o.customer_id, o.partner_id,
               o.forma_pagamento, o.payment_method
        FROM om_market_orders o
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmtOrder->execute([$order_id, $customer_id]);
    $order = $stmtOrder->fetch();

    if (!$order) response(false, null, "Pedido nao encontrado", 404);

    // Validar status — apenas pedidos entregues
    if ($order['status'] !== 'entregue') {
        response(false, null, "Apenas pedidos entregues podem solicitar reembolso. Status atual: " . $order['status'], 400);
    }

    // Validar prazo — 24 horas apos entrega
    $deliveredAt = $order['delivered_at'] ?: $order['date_added'];
    $hoursSinceDelivery = (time() - strtotime($deliveredAt)) / 3600;
    if ($hoursSinceDelivery > 24) {
        response(false, null, "O prazo para solicitar reembolso expirou (24 horas apos a entrega)", 400);
    }

    // Verificar se ja existe solicitacao (preliminary check before transaction)
    $stmtExisting = $db->prepare("
        SELECT refund_id, status FROM om_market_refunds
        WHERE order_id = ? AND customer_id = ? AND status IN ('pending', 'approved')
        LIMIT 1
    ");
    $stmtExisting->execute([$order_id, $customer_id]);
    $existing = $stmtExisting->fetch();
    if ($existing) {
        $statusLabel = $existing['status'] === 'pending' ? 'em analise' : 'aprovada';
        response(false, null, "Ja existe uma solicitacao de reembolso {$statusLabel} para este pedido", 400);
    }

    // Buscar itens do pedido
    $stmtItems = $db->prepare("
        SELECT item_id, product_id, product_name, name, quantity, price, total
        FROM om_market_order_items
        WHERE order_id = ?
    ");
    $stmtItems->execute([$order_id]);
    $orderItems = $stmtItems->fetchAll();

    // Build lookup
    $itemLookup = [];
    foreach ($orderItems as $oi) {
        $key = (int)($oi['item_id'] ?? $oi['product_id']);
        $itemLookup[$key] = $oi;
        // Also index by product_id for flexibility
        $itemLookup[(int)$oi['product_id']] = $oi;
    }

    // Calcular valor do reembolso
    $refundAmount = 0;
    $refundItems = [];

    if (!empty($items) && is_array($items)) {
        // Reembolso parcial — itens especificos
        foreach ($items as $reqItem) {
            $itemId = (int)($reqItem['item_id'] ?? $reqItem['product_id'] ?? 0);
            $qty = (int)($reqItem['quantity'] ?? 1);

            if (!$itemId || !isset($itemLookup[$itemId])) continue;

            $oi = $itemLookup[$itemId];
            $maxQty = (int)$oi['quantity'];
            $qty = min($qty, $maxQty);
            if ($qty <= 0) continue;

            $itemTotal = (float)$oi['price'] * $qty;
            $refundAmount += $itemTotal;

            $refundItems[] = [
                'item_id' => (int)($oi['item_id'] ?? $oi['product_id']),
                'product_id' => (int)$oi['product_id'],
                'name' => $oi['product_name'] ?: ($oi['name'] ?? ''),
                'quantity' => $qty,
                'price' => (float)$oi['price'],
                'total' => $itemTotal,
            ];
        }
    } else {
        // Reembolso total — todos os itens
        foreach ($orderItems as $oi) {
            $itemTotal = (float)($oi['total'] ?? ((float)$oi['price'] * (int)$oi['quantity']));
            $refundAmount += $itemTotal;

            $refundItems[] = [
                'item_id' => (int)($oi['item_id'] ?? $oi['product_id']),
                'product_id' => (int)$oi['product_id'],
                'name' => $oi['product_name'] ?: ($oi['name'] ?? ''),
                'quantity' => (int)$oi['quantity'],
                'price' => (float)$oi['price'],
                'total' => $itemTotal,
            ];
        }
    }

    if ($refundAmount <= 0) {
        response(false, null, "Nenhum item valido selecionado para reembolso", 400);
    }

    // Cap refund at order total
    $orderTotal = (float)($order['total'] ?? 0);
    $refundAmount = min($refundAmount, $orderTotal);

    // Verificar se nao excede valor ja reembolsado
    $stmtAlready = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_market_refunds WHERE order_id = ? AND status NOT IN ('failed', 'rejected')");
    $stmtAlready->execute([$order_id]);
    $alreadyRefunded = (float)$stmtAlready->fetchColumn();

    if ($refundAmount + $alreadyRefunded > $orderTotal) {
        $available = max(0, $orderTotal - $alreadyRefunded);
        if ($available <= 0) {
            response(false, null, "Este pedido ja foi totalmente reembolsado", 400);
        }
        $refundAmount = $available;
    }

    // Auto-aprovacao: abaixo de R$30
    $autoApproveThreshold = 30.00;
    $status = $refundAmount < $autoApproveThreshold ? 'approved' : 'pending';

    // ─── Transaction com lock ───
    $db->beginTransaction();

    try {
        // Lock order row to prevent concurrent refund creation
        $stmtLock = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? FOR UPDATE");
        $stmtLock->execute([$order_id]);

        // Re-check for existing refund inside transaction
        $stmtExisting2 = $db->prepare("
            SELECT refund_id, status FROM om_market_refunds
            WHERE order_id = ? AND customer_id = ? AND status IN ('pending', 'approved')
            LIMIT 1
        ");
        $stmtExisting2->execute([$order_id, $customer_id]);
        if ($stmtExisting2->fetch()) {
            $db->rollBack();
            response(false, null, "Ja existe uma solicitacao de reembolso para este pedido", 400);
        }

        // Dados extras (fotos + itens)
        $itemsJson = json_encode($refundItems, JSON_UNESCAPED_UNICODE);

        // Inserir reembolso
        $stmt = $db->prepare("
            INSERT INTO om_market_refunds (order_id, customer_id, amount, reason, items_json, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            RETURNING refund_id
        ");
        $stmt->execute([$order_id, $customer_id, $refundAmount, $reason, $itemsJson, $status]);
        $refundId = (int)$stmt->fetch()['refund_id'];

        // Se auto-aprovado, marcar reviewed_at
        if ($status === 'approved') {
            $db->prepare("UPDATE om_market_refunds SET reviewed_at = NOW(), admin_note = 'Auto-aprovado (valor abaixo de R$ 30,00)' WHERE refund_id = ?")
               ->execute([$refundId]);
        }

        // Add timeline entry
        try {
            $desc = $status === 'approved'
                ? "Reembolso de R$ " . number_format($refundAmount, 2, ',', '.') . " auto-aprovado"
                : "Reembolso de R$ " . number_format($refundAmount, 2, ',', '.') . " solicitado pelo cliente";

            $db->prepare("
                INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
                VALUES (?, 'refund_requested', ?, 'customer', NOW())
            ")->execute([$order_id, $desc]);
        } catch (Exception $e) {
            // Table may not exist, not critical
        }

        $db->commit();

        // Salvar fotos como evidencia na tabela de disputes (se existir)
        // Done AFTER commit so FK failures don't poison the refund transaction
        if (!empty($validPhotos)) {
            foreach ($validPhotos as $photoUrl) {
                try {
                    $db->prepare("
                        INSERT INTO om_dispute_evidence (dispute_id, order_id, customer_id, photo_url, caption, created_at)
                        VALUES (?, ?, ?, ?, 'Evidencia reembolso', NOW())
                    ")->execute([$refundId, $order_id, $customer_id, $photoUrl]);
                } catch (Exception $e) {
                    // Table may not exist or FK issue, not critical — refund already committed
                    error_log("[solicitar-reembolso] Erro salvar foto: " . $e->getMessage());
                }
            }
        }

        // WebSocket broadcast (non-blocking, after commit)
        try {
            wsBroadcastToCustomer($customer_id, 'refund_update', [
                'order_id' => $order_id,
                'refund_id' => $refundId,
                'status' => $status,
                'amount' => $refundAmount,
            ]);
        } catch (\Throwable $e) {}

        // Notificar parceiro
        $partnerId = (int)($order['partner_id'] ?? 0);
        if ($partnerId) {
            try {
                notifyPartner($db, $partnerId,
                    'Solicitacao de reembolso',
                    "Cliente solicitou reembolso de R$ " . number_format($refundAmount, 2, ',', '.') . " no pedido #{$order['order_number']}. Motivo: $reason",
                    '/painel/mercado/pedidos.php'
                );
            } catch (Exception $e) {}
        }

        $statusLabel = $status === 'approved' ? 'aprovado automaticamente' : 'enviado para analise';

        error_log("[solicitar-reembolso] Pedido #{$order_id} refund_id=$refundId amount=R\${$refundAmount} status=$status reason=$reason");

        response(true, [
            'refund' => [
                'id' => $refundId,
                'order_id' => $order_id,
                'amount' => $refundAmount,
                'amount_formatted' => 'R$ ' . number_format($refundAmount, 2, ',', '.'),
                'status' => $status,
                'items' => $refundItems,
                'photos' => $validPhotos,
                'reason' => $reason,
            ]
        ], "Reembolso {$statusLabel}");

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[solicitar-reembolso] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar solicitacao de reembolso", 500);
}
