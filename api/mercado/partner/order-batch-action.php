<?php
/**
 * POST /api/mercado/partner/order-batch-action.php
 * Batch action on multiple orders (e.g. accept all pending)
 *
 * Body: { order_ids: [1,2,3], action: "confirmar" }
 * Auth: Bearer token
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);
    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    $input = getInput();
    $order_ids = $input['order_ids'] ?? [];
    $action = trim($input['action'] ?? '');

    if (!is_array($order_ids) || empty($order_ids)) {
        response(false, null, "Nenhum pedido selecionado", 400);
    }

    $validActions = ['confirmar', 'preparando', 'pronto', 'cancelar'];
    if (!in_array($action, $validActions)) {
        response(false, null, "Acao invalida. Use: " . implode(', ', $validActions), 400);
    }

    // Limit batch size
    $order_ids = array_slice(array_map('intval', $order_ids), 0, 50);

    // Status transition map
    $statusMap = [
        'confirmar' => ['from' => ['pendente', 'pending'], 'to' => 'aceito'],
        'preparando' => ['from' => ['aceito', 'confirmed', 'confirmado'], 'to' => 'preparando'],
        'pronto' => ['from' => ['preparando'], 'to' => 'pronto'],
        'cancelar' => ['from' => ['pendente', 'pending', 'aceito', 'confirmed', 'confirmado', 'preparando'], 'to' => 'cancelado'],
    ];

    $transition = $statusMap[$action];
    $fromStatuses = $transition['from'];
    $toStatus = $transition['to'];

    // Build placeholders for IN clause
    $idPlaceholders = implode(',', array_fill(0, count($order_ids), '?'));
    $statusPlaceholders = implode(',', array_fill(0, count($fromStatuses), '?'));

    // Verify orders belong to partner and are in correct status
    $params = array_merge($order_ids, [$partner_id], $fromStatuses);
    $stmt = $db->prepare("
        SELECT order_id FROM om_market_orders
        WHERE order_id IN ({$idPlaceholders})
        AND partner_id = ?
        AND status IN ({$statusPlaceholders})
    ");
    $stmt->execute($params);
    $validOrders = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($validOrders)) {
        response(false, null, "Nenhum pedido valido para esta acao", 400);
    }

    // Bug 15 fix: Add status condition to UPDATE to prevent TOCTOU race
    $updatePlaceholders = implode(',', array_fill(0, count($validOrders), '?'));
    $updateStatusPlaceholders = implode(',', array_fill(0, count($fromStatuses), '?'));
    $updateParams = array_merge([$toStatus], $validOrders, [$partner_id], $fromStatuses);
    $stmt = $db->prepare("
        UPDATE om_market_orders
        SET status = ?, date_modified = NOW()
        WHERE order_id IN ({$updatePlaceholders})
        AND partner_id = ?
        AND status IN ({$updateStatusPlaceholders})
    ");
    $stmt->execute($updateParams);

    $updatedCount = $stmt->rowCount();

    if ($updatedCount === 0) {
        response(false, null, "Nenhum pedido atualizado. Os pedidos podem ter sido alterados por outra acao.", 409);
    }

    // Financial compensation for batch cancel — wrapped in a single transaction for atomicity
    if ($action === 'cancelar') {
        require_once __DIR__ . '/../helpers/cashback.php';
        // Fetch order details for compensation
        $compPlaceholders = implode(',', array_fill(0, count($validOrders), '?'));
        $stmtComp = $db->prepare("
            SELECT order_id, customer_id, coupon_id, loyalty_points_used, cashback_discount
            FROM om_market_orders
            WHERE order_id IN ({$compPlaceholders})
        ");
        $stmtComp->execute($validOrders);
        $cancelledOrders = $stmtComp->fetchAll(PDO::FETCH_ASSOC);

        $db->beginTransaction();
        try {
            foreach ($cancelledOrders as $co) {
                $coId = (int)$co['order_id'];
                $coCustId = (int)($co['customer_id'] ?? 0);

                // 1. Restore coupon usage
                $coCoupon = (int)($co['coupon_id'] ?? 0);
                if ($coCoupon && $coCustId) {
                    $db->prepare("DELETE FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ? AND order_id = ?")
                       ->execute([$coCoupon, $coCustId, $coId]);
                    $db->prepare("UPDATE om_market_coupons SET current_uses = GREATEST(0, current_uses - 1) WHERE id = ?")->execute([$coCoupon]);
                }

                // 2. Credit back loyalty points
                $coPoints = (int)($co['loyalty_points_used'] ?? 0);
                if ($coPoints > 0 && $coCustId) {
                    $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points + ?, updated_at = NOW() WHERE customer_id = ?")
                       ->execute([$coPoints, $coCustId]);
                    $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at) VALUES (?, ?, 'refund', 'order_cancelled', ?, ?, NOW())")
                       ->execute([$coCustId, $coPoints, $coId, "Estorno cancelamento lote pedido #{$coId}"]);
                }

                // 3. Reverse cashback
                $coCashback = (float)($co['cashback_discount'] ?? 0);
                if ($coCashback > 0) {
                    refundCashback($db, $coId);
                }

                // 4. Restore stock
                $stmtItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
                $stmtItems->execute([$coId]);
                $items = $stmtItems->fetchAll();
                foreach ($items as $item) {
                    $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                       ->execute([$item['quantity'], $item['product_id']]);
                }

                error_log("[order-batch-action] CANCEL COMPENSATION order #{$coId}: coupon={$coCoupon} points={$coPoints} cashback=R\${$coCashback} partner={$partner_id}");
            }
            $db->commit();
        } catch (Exception $compErr) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("[order-batch-action] BATCH CANCEL COMPENSATION FAILED: " . $compErr->getMessage());
        }
    }

    // Audit log
    foreach ($validOrders as $oid) {
        om_audit()->log('order', $oid, "batch_{$action}", [
            'partner_id' => $partner_id,
            'new_status' => $toStatus,
            'batch' => true
        ]);
    }

    $skipped = count($order_ids) - count($validOrders);

    // Pusher: notificar parceiro sobre atualizacao em lote de pedidos
    try {
        foreach ($validOrders as $oid) {
            PusherService::orderUpdate($partner_id, [
                'id' => $oid,
                'status' => $toStatus,
                'action' => $action
            ]);
            PusherService::orderStatusUpdate($oid, $toStatus, "Status atualizado para $toStatus");
        }
    } catch (Exception $pusherErr) {
        error_log("[order-batch-action] Pusher erro: " . $pusherErr->getMessage());
    }

    response(true, [
        'updated' => $updatedCount,
        'skipped' => $skipped,
        'action' => $action,
        'new_status' => $toStatus,
        'order_ids' => $validOrders,
    ], "Acao realizada em {$updatedCount} pedido(s)");

} catch (Exception $e) {
    error_log("[order-batch-action] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar acao em lote", 500);
}
