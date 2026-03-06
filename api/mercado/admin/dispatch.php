<?php
/**
 * /api/mercado/admin/dispatch.php
 *
 * Dispatch management endpoint.
 *
 * GET: List unassigned orders + available shoppers.
 *
 * POST (default): Assign a shopper to an unassigned order.
 *   Body: { order_id, shopper_id }
 *
 * POST action=reassign: Reassign an order that already has a shopper.
 *   Body: { action: "reassign", order_id, new_shopper_id, reason }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/notify.php";

// Load WebSocket broadcast if available
$wsFile = __DIR__ . "/../helpers/ws-customer-broadcast.php";
if (file_exists($wsFile)) {
    require_once $wsFile;
}

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        // Unassigned orders
        $stmt = $db->query("
            SELECT o.order_id, o.status, o.total, o.delivery_address, o.created_at,
                   p.name as partner_name, p.address as partner_address,
                   c.firstname as customer_name
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
            WHERE o.status IN ('ready', 'confirmed', 'preparing')
              AND (o.shopper_id IS NULL OR o.shopper_id = 0)
            ORDER BY o.created_at ASC
        ");
        $unassigned = $stmt->fetchAll();

        // Available shoppers
        $stmt = $db->query("
            SELECT s.shopper_id, s.name, s.phone, s.rating, s.is_online,
                   (SELECT COUNT(*) FROM om_market_orders o2
                    WHERE o2.shopper_id = s.shopper_id AND o2.status IN ('collecting','in_transit')) as active_orders
            FROM om_market_shoppers s
            WHERE s.is_online = 1 AND s.status = '1'
            ORDER BY s.rating DESC
        ");
        $shoppers = $stmt->fetchAll();

        response(true, [
            'unassigned_orders' => $unassigned,
            'available_shoppers' => $shoppers
        ], "Dados de despacho");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        // ── Reassign: transfer order from one shopper to another ──
        if ($action === 'reassign') {
            $order_id = (int)($input['order_id'] ?? 0);
            $new_shopper_id = (int)($input['new_shopper_id'] ?? 0);
            $reason = strip_tags(trim($input['reason'] ?? ''));

            if (!$order_id) response(false, null, "order_id obrigatorio", 400);
            if (!$new_shopper_id) response(false, null, "new_shopper_id obrigatorio", 400);
            if (!$reason) response(false, null, "reason obrigatorio", 400);

            $db->beginTransaction();
            try {
                // Lock order row
                $stmt = $db->prepare("
                    SELECT o.order_id, o.status, o.shopper_id, o.customer_id, o.partner_id,
                           o.order_number
                    FROM om_market_orders o
                    WHERE o.order_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$order_id]);
                $order = $stmt->fetch();

                if (!$order) {
                    $db->rollBack();
                    response(false, null, "Pedido nao encontrado", 404);
                }

                $old_shopper_id = (int)($order['shopper_id'] ?? 0);

                // Must have an existing shopper to reassign
                if (!$old_shopper_id) {
                    $db->rollBack();
                    response(false, null, "Pedido nao tem shopper atribuido. Use atribuicao normal.", 400);
                }

                // Cannot reassign to the same shopper
                if ($old_shopper_id === $new_shopper_id) {
                    $db->rollBack();
                    response(false, null, "Novo shopper e o mesmo que o atual", 400);
                }

                // Order must be in a reassignable status
                $reassignable = ['collecting', 'in_transit', 'confirmed', 'preparing', 'ready'];
                if (!in_array($order['status'], $reassignable)) {
                    $db->rollBack();
                    response(false, null, "Pedido nao pode ser reatribuido (status: {$order['status']})", 409);
                }

                // Verify new shopper exists and is active
                $stmt = $db->prepare("SELECT shopper_id, name, phone, status, is_online FROM om_market_shoppers WHERE shopper_id = ?");
                $stmt->execute([$new_shopper_id]);
                $new_shopper = $stmt->fetch();
                if (!$new_shopper || $new_shopper['status'] != 1) {
                    $db->rollBack();
                    response(false, null, "Novo shopper nao disponivel", 400);
                }

                // Get old shopper info for notifications
                $stmt = $db->prepare("SELECT shopper_id, name, phone FROM om_market_shoppers WHERE shopper_id = ?");
                $stmt->execute([$old_shopper_id]);
                $old_shopper = $stmt->fetch();
                $old_shopper_name = $old_shopper['name'] ?? "Shopper #{$old_shopper_id}";

                // Update order with new shopper
                $stmt = $db->prepare("
                    UPDATE om_market_orders
                    SET shopper_id = ?, updated_at = NOW()
                    WHERE order_id = ?
                ");
                $stmt->execute([$new_shopper_id, $order_id]);

                // Timeline entry
                $desc = "Shopper reatribuido: {$old_shopper_name} -> {$new_shopper['name']}. Motivo: {$reason}";
                $stmt = $db->prepare("
                    INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                    VALUES (?, ?, ?, 'admin', ?, NOW())
                ");
                $stmt->execute([$order_id, $order['status'], $desc, $admin_id]);

                // Audit log
                om_audit()->log(
                    'dispatch_reassign',
                    'order',
                    $order_id,
                    ['shopper_id' => $old_shopper_id],
                    ['shopper_id' => $new_shopper_id, 'reason' => $reason],
                    "Shopper reatribuido de {$old_shopper_name} para {$new_shopper['name']}. Motivo: {$reason}"
                );

                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }

            // Notifications (outside transaction — non-critical)
            $orderNum = $order['order_number'] ?? "#{$order_id}";
            $customer_id = (int)$order['customer_id'];

            // Notify old shopper
            try {
                _sendExpoPush($db, $old_shopper_id, 'shopper',
                    "Pedido {$orderNum} reatribuido",
                    "O pedido foi reatribuido a outro shopper. Motivo: {$reason}",
                    ['order_id' => $order_id, 'type' => 'order_reassigned']
                );
            } catch (Exception $e) {
                error_log("[dispatch/reassign] Notify old shopper error: " . $e->getMessage());
            }

            // Notify new shopper
            try {
                _sendExpoPush($db, $new_shopper_id, 'shopper',
                    "Novo pedido atribuido: {$orderNum}",
                    "Voce recebeu o pedido {$orderNum}. Verifique os detalhes no app.",
                    ['order_id' => $order_id, 'type' => 'order_assigned']
                );
            } catch (Exception $e) {
                error_log("[dispatch/reassign] Notify new shopper error: " . $e->getMessage());
            }

            // Notify customer
            if ($customer_id) {
                try {
                    notifyCustomer(
                        $db,
                        $customer_id,
                        "Shopper atualizado",
                        "Seu pedido {$orderNum} foi atribuido a {$new_shopper['name']}.",
                        "/tracking/{$order_id}",
                        ['order_id' => $order_id, 'type' => 'shopper_reassigned']
                    );
                } catch (Exception $e) {
                    error_log("[dispatch/reassign] Notify customer error: " . $e->getMessage());
                }
            }

            // WebSocket broadcast
            if (function_exists('wsBroadcastToCustomer')) {
                try {
                    if ($customer_id) {
                        wsBroadcastToCustomer($customer_id, 'order_update', [
                            'order_id' => $order_id,
                            'status' => $order['status'],
                            'shopper_id' => $new_shopper_id,
                            'shopper_name' => $new_shopper['name'],
                            'event' => 'shopper_reassigned',
                        ]);
                    }
                    wsBroadcastToOrder($order_id, 'order_update', [
                        'order_id' => $order_id,
                        'status' => $order['status'],
                        'shopper_id' => $new_shopper_id,
                        'shopper_name' => $new_shopper['name'],
                        'event' => 'shopper_reassigned',
                    ]);
                } catch (Exception $e) {
                    error_log("[dispatch/reassign] WS broadcast error: " . $e->getMessage());
                }
            }

            response(true, [
                'order_id' => $order_id,
                'old_shopper_id' => $old_shopper_id,
                'old_shopper_name' => $old_shopper_name,
                'new_shopper_id' => $new_shopper_id,
                'new_shopper_name' => $new_shopper['name'],
            ], "Shopper reatribuido com sucesso");
        }

        // ── Default: assign shopper to unassigned order ──
        $order_id = (int)($input['order_id'] ?? 0);
        $shopper_id = (int)($input['shopper_id'] ?? 0);

        if (!$order_id || !$shopper_id) response(false, null, "order_id e shopper_id obrigatorios", 400);

        $db->beginTransaction();
        try {
            // Lock order row to prevent race condition in concurrent assignment
            $stmt = $db->prepare("SELECT status, shopper_id FROM om_market_orders WHERE order_id = ? FOR UPDATE");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch();
            if (!$order) {
                $db->rollBack();
                response(false, null, "Pedido nao encontrado", 404);
            }

            if ($order['shopper_id'] && (int)$order['shopper_id'] !== 0) {
                $db->rollBack();
                response(false, null, "Pedido ja atribuido a outro shopper", 409);
            }

            // Verify shopper
            $stmt = $db->prepare("SELECT status, is_online, name FROM om_market_shoppers WHERE shopper_id = ?");
            $stmt->execute([$shopper_id]);
            $shopper = $stmt->fetch();
            if (!$shopper || $shopper['status'] != 1) {
                $db->rollBack();
                response(false, null, "Shopper nao disponivel", 400);
            }

            // Conditional assign — only if still unassigned (belt-and-suspenders)
            $stmt = $db->prepare("
                UPDATE om_market_orders
                SET shopper_id = ?, status = 'collecting', updated_at = NOW()
                WHERE order_id = ? AND (shopper_id IS NULL OR shopper_id = 0)
            ");
            $stmt->execute([$shopper_id, $order_id]);

            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                response(false, null, "Pedido ja atribuido a outro shopper", 409);
            }

            // Timeline
            $desc = "Pedido atribuido a {$shopper['name']} pelo admin";
            $stmt = $db->prepare("
                INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                VALUES (?, 'collecting', ?, 'admin', ?, NOW())
            ");
            $stmt->execute([$order_id, $desc, $admin_id]);

            om_audit()->log('update', 'order', $order_id, null, ['shopper_id' => $shopper_id], 'Dispatch manual');

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        response(true, ['order_id' => $order_id, 'shopper_id' => $shopper_id], "Pedido atribuido");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/dispatch] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
