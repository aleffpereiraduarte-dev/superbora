<?php
/**
 * Customer Refund API
 *
 * POST /api/mercado/orders/refund.php - Request a refund
 *   Body: { "order_id": X, "reason": "...", "items": [{ "item_id": Y, "quantity": Z }] }
 *
 * GET /api/mercado/orders/refund.php?order_id=X - Check refund status
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";

setCorsHeaders();

// SECURITY: Rate limiting — 5 refund requests/min per IP
if (!RateLimiter::check(5, 60)) {
    response(false, null, "Muitas solicitacoes. Tente novamente em 1 minuto.", 429);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    // Authenticate customer
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    // Table om_market_refunds created via migration

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        // =================== GET: Check refund status ===================
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) response(false, null, "order_id obrigatorio", 400);

        // Verify order belongs to customer
        $stmtOrder = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmtOrder->execute([$orderId, $customerId]);
        if (!$stmtOrder->fetch()) response(false, null, "Pedido nao encontrado", 404);

        $stmt = $db->prepare("
            SELECT refund_id, order_id, amount, reason, items_json, status, admin_note, created_at, reviewed_at
            FROM om_market_refunds
            WHERE order_id = ? AND customer_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$orderId, $customerId]);
        $refund = $stmt->fetch();

        if (!$refund) {
            response(true, ['refund' => null], "Nenhum reembolso encontrado");
        }

        response(true, [
            'refund' => [
                'id' => (int)$refund['refund_id'],
                'order_id' => (int)$refund['order_id'],
                'amount' => (float)$refund['amount'],
                'reason' => $refund['reason'],
                'items' => json_decode($refund['items_json'] ?? '[]', true),
                'status' => $refund['status'],
                'admin_note' => $refund['admin_note'],
                'created_at' => $refund['created_at'],
                'reviewed_at' => $refund['reviewed_at'],
            ]
        ]);

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        // =================== POST: Request refund ===================
        $input = getInput();
        $orderId = (int)($input['order_id'] ?? 0);
        $reason = strip_tags(trim($input['reason'] ?? ''));
        $items = $input['items'] ?? [];

        if (!$orderId) response(false, null, "order_id obrigatorio", 400);
        if (empty($reason)) response(false, null, "Motivo do reembolso obrigatorio", 400);
        if (strlen($reason) < 10) response(false, null, "Descreva o motivo com mais detalhes (min. 10 caracteres)", 400);

        // Get order
        $stmtOrder = $db->prepare("
            SELECT o.order_id, o.status, o.total, o.subtotal, o.delivery_fee,
                   o.delivered_at, o.date_added, o.customer_id
            FROM om_market_orders o
            WHERE o.order_id = ? AND o.customer_id = ?
        ");
        $stmtOrder->execute([$orderId, $customerId]);
        $order = $stmtOrder->fetch();

        if (!$order) response(false, null, "Pedido nao encontrado", 404);

        // Validate order status - only delivered orders
        if ($order['status'] !== 'entregue') {
            response(false, null, "Apenas pedidos entregues podem ser reembolsados", 400);
        }

        // Validate within 24 hours of delivery
        $deliveredAt = $order['delivered_at'] ?: $order['date_added'];
        $hoursSinceDelivery = (time() - strtotime($deliveredAt)) / 3600;
        if ($hoursSinceDelivery > 24) {
            response(false, null, "O prazo para solicitar reembolso expirou (24 horas apos a entrega)", 400);
        }

        // Check for existing pending/approved refund (preliminary check before transaction)
        $stmtExisting = $db->prepare("
            SELECT refund_id, status FROM om_market_refunds
            WHERE order_id = ? AND customer_id = ? AND status IN ('pending', 'approved')
            LIMIT 1
        ");
        $stmtExisting->execute([$orderId, $customerId]);
        $existing = $stmtExisting->fetch();
        if ($existing) {
            $statusLabel = $existing['status'] === 'pending' ? 'pendente' : 'aprovado';
            response(false, null, "Ja existe um reembolso {$statusLabel} para este pedido", 400);
        }

        // Get order items for validation and amount calculation
        $stmtItems = $db->prepare("
            SELECT oi.item_id, oi.product_id, oi.product_name, oi.name,
                   oi.quantity, oi.price, oi.total
            FROM om_market_order_items oi
            WHERE oi.order_id = ?
        ");
        $stmtItems->execute([$orderId]);
        $orderItems = $stmtItems->fetchAll();

        // Build lookup by item_id and product_id
        $itemLookup = [];
        foreach ($orderItems as $oi) {
            $key = $oi['item_id'] ?? $oi['product_id'];
            $itemLookup[$key] = $oi;
        }

        // Calculate refund amount
        $refundAmount = 0;
        $refundItems = [];

        if (!empty($items)) {
            // Partial refund - specific items
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
                    'item_id' => $itemId,
                    'product_id' => (int)($oi['product_id'] ?? $itemId),
                    'name' => $oi['product_name'] ?: $oi['name'],
                    'quantity' => $qty,
                    'price' => (float)$oi['price'],
                    'total' => $itemTotal,
                ];
            }
        } else {
            // Full refund - all items
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
        $refundAmount = min($refundAmount, (float)$order['total']);

        // Validate refund doesn't exceed order total minus already refunded
        // Note: order total already includes delivery_fee, so do not add it again
        $maxRefundable = (float)($order['total'] ?? 0);

        $stmtAlready = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_market_refunds WHERE order_id = ? AND status NOT IN ('failed', 'rejected')");
        $stmtAlready->execute([$orderId]);
        $alreadyRefunded = (float)$stmtAlready->fetchColumn();

        if ($refundAmount + $alreadyRefunded > $maxRefundable) {
            $available = $maxRefundable - $alreadyRefunded;
            response(false, null, "Reembolso excede valor maximo. Maximo disponivel: R$" . number_format($available, 2, ',', '.'), 400);
        }

        // Auto-approve refunds under R$30
        $autoApproveThreshold = 30.00;
        $status = $refundAmount < $autoApproveThreshold ? 'approved' : 'pending';

        $db->beginTransaction();

        // Lock the order row to prevent concurrent refund creation (race condition fix)
        $stmtLock = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? FOR UPDATE");
        $stmtLock->execute([$orderId]);

        // Re-check for existing refund inside the transaction with lock held
        $stmtExisting2 = $db->prepare("
            SELECT refund_id, status FROM om_market_refunds
            WHERE order_id = ? AND customer_id = ? AND status IN ('pending', 'approved')
            LIMIT 1
        ");
        $stmtExisting2->execute([$orderId, $customerId]);
        $existing2 = $stmtExisting2->fetch();
        if ($existing2) {
            $db->rollBack();
            $statusLabel2 = $existing2['status'] === 'pending' ? 'pendente' : 'aprovado';
            response(false, null, "Ja existe um reembolso {$statusLabel2} para este pedido", 400);
        }

        $stmt = $db->prepare("
            INSERT INTO om_market_refunds (order_id, customer_id, amount, reason, items_json, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            RETURNING refund_id
        ");
        $stmt->execute([
            $orderId,
            $customerId,
            $refundAmount,
            $reason,
            json_encode($refundItems, JSON_UNESCAPED_UNICODE),
            $status
        ]);
        $refundId = (int)$stmt->fetch()['refund_id'];

        // If auto-approved, update the refund reviewed_at
        if ($status === 'approved') {
            $db->prepare("UPDATE om_market_refunds SET reviewed_at = NOW(), admin_note = 'Auto-aprovado (valor abaixo de R$ 30,00)' WHERE refund_id = ?")
               ->execute([$refundId]);
        }

        // Add timeline entry
        try {
            $desc = $status === 'approved'
                ? "Reembolso de R$ " . number_format($refundAmount, 2, ',', '.') . " auto-aprovado"
                : "Reembolso de R$ " . number_format($refundAmount, 2, ',', '.') . " solicitado";

            $db->prepare("
                INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                VALUES (?, ?, ?, 'customer', ?, NOW())
            ")->execute([$orderId, 'refund_requested', $desc, $customerId]);
        } catch (Exception $e) {
            // Timeline table may not exist, not critical
        }

        $db->commit();

        om_audit()->log('create', 'refund', $refundId, null, [
            'order_id' => $orderId,
            'amount' => $refundAmount,
            'status' => $status,
            'reason' => $reason,
        ], "Reembolso solicitado");

        $statusLabel = $status === 'approved' ? 'aprovado automaticamente' : 'enviado para analise';

        response(true, [
            'refund' => [
                'id' => $refundId,
                'order_id' => $orderId,
                'amount' => $refundAmount,
                'status' => $status,
                'items' => $refundItems,
                'reason' => $reason,
            ]
        ], "Reembolso {$statusLabel}");

    } else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[orders/refund] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar reembolso", 500);
}
