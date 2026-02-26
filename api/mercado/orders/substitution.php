<?php
/**
 * /api/mercado/orders/substitution.php
 *
 * Item substitution system for orders.
 *
 * GET  ?order_id=X
 *   - List substitution requests for an order
 *   - Customer, partner, or shopper auth
 *
 * POST { order_id, item_id, substitute_product_id, substitute_product_name, substitute_price, shopper_note }
 *   - Create a substitution request (partner/shopper only)
 *   - Status = pending
 *
 * PUT { substitution_id, action: "accept"|"reject" }
 *   - Customer resolves a substitution
 *   - Accept: updates order item to substitute
 *   - Reject: marks item as removed, adjusts order total
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Authenticate
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload) response(false, null, "Token invalido", 401);

    $userType = $payload['type'] ?? '';
    $userId = (int)($payload['uid'] ?? 0);

    $method = $_SERVER['REQUEST_METHOD'];

    // =================== GET: List substitutions ===================
    if ($method === 'GET') {
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) response(false, null, "order_id obrigatorio", 400);

        // Verify access
        verifySubAccess($db, $orderId, $userType, $userId);

        // Get substitutions from the dedicated table
        $stmt = $db->prepare("
            SELECT s.*,
                   oi.product_name as original_item_name,
                   oi.price as original_price,
                   oi.quantity as original_quantity,
                   oi.image as original_image,
                   oi.product_id as original_product_id_from_item
            FROM om_market_order_substitutions s
            LEFT JOIN om_market_order_items oi ON s.item_id = oi.item_id
            WHERE s.order_id = ?
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$orderId]);
        $subs = $stmt->fetchAll();

        // Also check order_items that have substitution info but no entry in substitutions table
        $stmtItems = $db->prepare("
            SELECT item_id, order_id, product_id, product_name, price, quantity, image,
                   substitute_product_id, substitute_name, substitute_price,
                   status, substituted, customer_approved, customer_approved_at,
                   replacement_id, replacement_name, replacement_price, replacement_reason
            FROM om_market_order_items
            WHERE order_id = ? AND (status = 'not_found' OR status = 'replaced' OR substituted = 1)
        ");
        $stmtItems->execute([$orderId]);
        $itemSubs = $stmtItems->fetchAll();

        $formatted = [];

        // Format from substitutions table
        foreach ($subs as $s) {
            $formatted[] = [
                'id' => (int)$s['id'],
                'order_id' => (int)$s['order_id'],
                'item_id' => (int)$s['item_id'],
                'original_product_id' => (int)($s['original_product_id'] ?: $s['original_product_id_from_item']),
                'original_item_name' => $s['original_product_name'] ?: $s['original_item_name'],
                'original_price' => (float)$s['original_price'],
                'original_quantity' => (int)($s['original_quantity'] ?: 1),
                'original_image' => $s['original_image'] ?? null,
                'suggested_product_id' => (int)$s['substitute_product_id'],
                'suggested_item_name' => $s['substitute_product_name'],
                'suggested_price' => (float)$s['substitute_price'],
                'status' => mapSubStatus($s['status']),
                'shopper_note' => $s['shopper_note'],
                'customer_response' => $s['customer_response'],
                'resolved_at' => $s['responded_at'],
                'created_at' => $s['created_at'],
                'source' => 'substitution_table',
            ];
        }

        // Also include inline substitutions from order_items not yet in the substitutions table
        $existingItemIds = array_column($subs, 'item_id');
        foreach ($itemSubs as $item) {
            if (in_array($item['item_id'], $existingItemIds)) continue;

            $sugName = $item['substitute_name'] ?: $item['replacement_name'];
            $sugPrice = (float)($item['substitute_price'] ?: $item['replacement_price']);
            $sugId = (int)($item['substitute_product_id'] ?: $item['replacement_id']);

            if (!$sugName && !$sugId) continue; // No substitution data

            $status = 'pending';
            if ($item['customer_approved'] == 1) $status = 'accepted';
            if ($item['status'] === 'replaced' && $item['substituted']) $status = 'accepted';

            $formatted[] = [
                'id' => 0, // No dedicated substitution record
                'order_id' => (int)$item['order_id'],
                'item_id' => (int)$item['item_id'],
                'original_product_id' => (int)$item['product_id'],
                'original_item_name' => $item['product_name'],
                'original_price' => (float)$item['price'],
                'original_quantity' => (int)$item['quantity'],
                'original_image' => $item['image'] ?? null,
                'suggested_product_id' => $sugId,
                'suggested_item_name' => $sugName,
                'suggested_price' => $sugPrice,
                'status' => $status,
                'shopper_note' => $item['replacement_reason'] ?? null,
                'customer_response' => null,
                'resolved_at' => $item['customer_approved_at'],
                'created_at' => null,
                'source' => 'order_items',
            ];
        }

        $pendingCount = count(array_filter($formatted, fn($s) => $s['status'] === 'pending'));

        response(true, [
            'substitutions' => $formatted,
            'total' => count($formatted),
            'pending_count' => $pendingCount,
        ]);
    }

    // =================== POST: Create substitution request ===================
    if ($method === 'POST') {
        // Only partner/shopper can create substitution requests
        $allowedTypes = ['shopper', 'parceiro', 'partner'];
        if (!in_array($userType, $allowedTypes)) {
            response(false, null, "Apenas a loja pode sugerir substituicoes", 403);
        }

        $input = getInput();
        $orderId = (int)($input['order_id'] ?? 0);
        $itemId = (int)($input['item_id'] ?? 0);
        $subProductId = (int)($input['substitute_product_id'] ?? 0);
        $subProductName = strip_tags(trim(substr($input['substitute_product_name'] ?? '', 0, 255)));
        $subPrice = (float)($input['substitute_price'] ?? 0);
        $shopperNote = strip_tags(trim(substr($input['shopper_note'] ?? '', 0, 500)));

        if (!$orderId) response(false, null, "order_id obrigatorio", 400);
        if (!$itemId) response(false, null, "item_id obrigatorio", 400);
        if (empty($subProductName)) response(false, null, "Nome do substituto obrigatorio", 400);
        if ($subPrice <= 0) response(false, null, "Preco do substituto obrigatorio", 400);

        // Verify order belongs to this partner/shopper
        verifySubAccess($db, $orderId, $userType, $userId);

        // Verify item belongs to this order
        $itemStmt = $db->prepare("SELECT * FROM om_market_order_items WHERE item_id = ? AND order_id = ?");
        $itemStmt->execute([$itemId, $orderId]);
        $item = $itemStmt->fetch();
        if (!$item) response(false, null, "Item nao encontrado neste pedido", 404);

        // Check if a pending substitution already exists for this item
        $existsStmt = $db->prepare("
            SELECT id FROM om_market_order_substitutions
            WHERE order_id = ? AND item_id = ? AND status = 'pending'
        ");
        $existsStmt->execute([$orderId, $itemId]);
        if ($existsStmt->fetch()) {
            response(false, null, "Ja existe uma substituicao pendente para este item", 400);
        }

        // Insert substitution
        $stmt = $db->prepare("
            INSERT INTO om_market_order_substitutions
            (order_id, item_id, original_product_id, original_product_name,
             substitute_product_id, substitute_product_name, substitute_price,
             status, shopper_note, customer_preference, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'contact_me', NOW())
        ");
        $stmt->execute([
            $orderId,
            $itemId,
            (int)$item['product_id'],
            $item['product_name'] ?: $item['name'],
            $subProductId,
            $subProductName,
            $subPrice,
            $shopperNote
        ]);

        $subId = (int)$db->lastInsertId();

        // Update item status to not_found
        $updateItem = $db->prepare("UPDATE om_market_order_items SET status = 'not_found' WHERE item_id = ?");
        $updateItem->execute([$itemId]);

        // Send a system chat message to notify customer
        try {
            $db->prepare("
                INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, message_type, chat_type, is_read, created_at)
                VALUES (?, 'system', 0, 'Sistema', ?, 'status', 'customer', 0, NOW())
            ")->execute([
                $orderId,
                "O item \"{$item['product_name']}\" nao esta disponivel. A loja sugeriu \"{$subProductName}\" como substituto (R\$ " . number_format($subPrice, 2, ',', '.') . "). Por favor, aceite ou recuse a substituicao."
            ]);
        } catch (Exception $e) {
            // Non-critical, continue
        }

        response(true, [
            'substitution_id' => $subId,
            'order_id' => $orderId,
            'item_id' => $itemId,
            'status' => 'pending',
        ], "Substituicao solicitada");
    }

    // =================== PUT: Accept or reject substitution ===================
    if ($method === 'PUT') {
        // Only customer can accept/reject
        if ($userType !== 'customer') {
            response(false, null, "Apenas o cliente pode aceitar ou recusar substituicoes", 403);
        }

        $input = getInput();
        $subId = (int)($input['substitution_id'] ?? 0);
        $itemId = (int)($input['item_id'] ?? 0);
        $action = $input['action'] ?? '';

        if (!in_array($action, ['accept', 'reject'])) {
            response(false, null, "Acao invalida. Use 'accept' ou 'reject'", 400);
        }

        // Two paths: substitution table record or direct item_id
        if ($subId > 0) {
            // From substitutions table
            $stmt = $db->prepare("
                SELECT s.*, oi.price as original_price, oi.quantity as original_qty, oi.item_id as oi_item_id
                FROM om_market_order_substitutions s
                LEFT JOIN om_market_order_items oi ON s.item_id = oi.item_id
                WHERE s.id = ? AND s.status = 'pending'
            ");
            $stmt->execute([$subId]);
            $sub = $stmt->fetch();
            if (!$sub) response(false, null, "Substituicao nao encontrada ou ja resolvida", 404);

            // Verify customer owns this order and order is in active state
            $orderStmt = $db->prepare("SELECT order_id, status FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
            $orderStmt->execute([$sub['order_id'], $userId]);
            $orderRow = $orderStmt->fetch();
            if (!$orderRow) response(false, null, "Acesso negado", 403);

            $activeStatuses = ['aceito', 'coletando', 'confirmed', 'shopping', 'preparando', 'pending', 'novo'];
            if (!in_array($orderRow['status'], $activeStatuses)) {
                response(false, null, "Nao e possivel modificar substituicao de pedido com status '{$orderRow['status']}'", 400);
            }

            $orderId = (int)$sub['order_id'];
            $theItemId = (int)$sub['item_id'];

            if ($action === 'accept') {
                // Atomic update: only succeed if still pending (prevents double-processing)
                $updateSub = $db->prepare("UPDATE om_market_order_substitutions SET status = 'approved', responded_at = NOW(), customer_response = 'accepted' WHERE id = ? AND status = 'pending'");
                $updateSub->execute([$subId]);
                if ($updateSub->rowCount() === 0) {
                    response(false, null, "Substituicao ja foi processada por outra requisicao", 409);
                }

                // Update order item with substitute info
                $db->prepare("
                    UPDATE om_market_order_items
                    SET product_id = COALESCE(?, product_id),
                        product_name = ?,
                        name = ?,
                        price = ?,
                        total = ? * quantity,
                        total_price = ? * quantity,
                        unit_price = ?,
                        substitute_product_id = product_id,
                        substitute_name = product_name,
                        substitute_price = ?,
                        substituted = 1,
                        customer_approved = 1,
                        customer_approved_at = NOW(),
                        status = 'replaced'
                    WHERE item_id = ?
                ")->execute([
                    $sub['substitute_product_id'] ?: null,
                    $sub['substitute_product_name'],
                    $sub['substitute_product_name'],
                    $sub['substitute_price'],
                    $sub['substitute_price'],
                    $sub['substitute_price'],
                    $sub['substitute_price'],
                    $sub['substitute_price'],
                    $theItemId
                ]);

                // Recalculate order total
                recalculateOrderTotal($db, $orderId);

                $msg = "Substituicao aceita";
            } else {
                // Atomic reject: only succeed if still pending (prevents double-processing)
                $rejectSub = $db->prepare("UPDATE om_market_order_substitutions SET status = 'rejected', responded_at = NOW(), customer_response = 'rejected' WHERE id = ? AND status = 'pending'");
                $rejectSub->execute([$subId]);
                if ($rejectSub->rowCount() === 0) {
                    response(false, null, "Substituicao ja foi processada por outra requisicao", 409);
                }

                // Mark item as removed (set quantity to 0 or mark status)
                $db->prepare("
                    UPDATE om_market_order_items
                    SET status = 'not_found',
                        customer_approved = 0,
                        customer_approved_at = NOW(),
                        quantity = 0,
                        total = 0,
                        total_price = 0
                    WHERE item_id = ?
                ")->execute([$theItemId]);

                // Recalculate order total
                recalculateOrderTotal($db, $orderId);

                $msg = "Substituicao recusada. Item removido do pedido.";
            }

            // Send system chat message
            try {
                $chatMsg = $action === 'accept'
                    ? "O cliente aceitou a substituicao: \"{$sub['substitute_product_name']}\" no lugar de \"{$sub['original_product_name']}\"."
                    : "O cliente recusou a substituicao de \"{$sub['original_product_name']}\". Item removido do pedido.";

                $db->prepare("
                    INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, message_type, chat_type, is_read, created_at)
                    VALUES (?, 'system', 0, 'Sistema', ?, 'status', 'customer', 0, NOW())
                ")->execute([$orderId, $chatMsg]);
            } catch (Exception $e) {}

            response(true, [
                'substitution_id' => $subId,
                'action' => $action,
                'new_status' => $action === 'accept' ? 'approved' : 'rejected',
            ], $msg);

        } elseif ($itemId > 0) {
            // Direct item_id path (for inline substitutions from order_items)
            $stmt = $db->prepare("
                SELECT oi.*, o.customer_id, o.status as order_status
                FROM om_market_order_items oi
                JOIN om_market_orders o ON oi.order_id = o.order_id
                WHERE oi.item_id = ? AND o.customer_id = ?
            ");
            $stmt->execute([$itemId, $userId]);
            $item = $stmt->fetch();
            if (!$item) response(false, null, "Item nao encontrado", 404);

            $activeStatuses2 = ['aceito', 'coletando', 'confirmed', 'shopping', 'preparando', 'pending', 'novo'];
            if (!in_array($item['order_status'], $activeStatuses2)) {
                response(false, null, "Nao e possivel modificar substituicao de pedido com status '{$item['order_status']}'", 400);
            }

            $orderId = (int)$item['order_id'];

            $subName = $item['substitute_name'] ?: $item['replacement_name'];
            $subPrice = (float)($item['substitute_price'] ?: $item['replacement_price']);

            if ($action === 'accept') {
                $db->prepare("
                    UPDATE om_market_order_items
                    SET product_name = COALESCE(?, product_name),
                        name = COALESCE(?, name),
                        price = COALESCE(?, price),
                        total = COALESCE(?, price) * quantity,
                        total_price = COALESCE(?, price) * quantity,
                        customer_approved = 1,
                        customer_approved_at = NOW(),
                        status = 'replaced'
                    WHERE item_id = ?
                ")->execute([$subName, $subName, $subPrice ?: null, $subPrice ?: null, $subPrice ?: null, $itemId]);

                recalculateOrderTotal($db, $orderId);
                response(true, ['item_id' => $itemId, 'action' => 'accept'], "Substituicao aceita");
            } else {
                $db->prepare("
                    UPDATE om_market_order_items
                    SET customer_approved = 0,
                        customer_approved_at = NOW(),
                        quantity = 0,
                        total = 0,
                        total_price = 0,
                        status = 'not_found'
                    WHERE item_id = ?
                ")->execute([$itemId]);

                recalculateOrderTotal($db, $orderId);
                response(true, ['item_id' => $itemId, 'action' => 'reject'], "Item removido do pedido");
            }
        } else {
            response(false, null, "substitution_id ou item_id obrigatorio", 400);
        }
    }

    // OPTIONS
    if ($method === 'OPTIONS') {
        response(true, null, "OK");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[API Order Substitution] Erro: " . $e->getMessage());
    response(false, null, "Erro no sistema de substituicao", 500);
}

// =================== Helper functions ===================

function verifySubAccess(PDO $db, int $orderId, string $userType, int $userId): void {
    if ($userType === 'customer') {
        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmt->execute([$orderId, $userId]);
    } elseif (in_array($userType, ['shopper', 'parceiro', 'partner'])) {
        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND (partner_id = ? OR shopper_id = ?)");
        $stmt->execute([$orderId, $userId, $userId]);
    } else {
        response(false, null, "Tipo de usuario nao autorizado", 403);
    }

    if (!$stmt->fetch()) {
        response(false, null, "Acesso negado a este pedido", 403);
    }
}

function mapSubStatus(string $status): string {
    $map = [
        'pending' => 'pending',
        'approved' => 'accepted',
        'rejected' => 'rejected',
        'auto_approved' => 'accepted',
    ];
    return $map[$status] ?? $status;
}

function recalculateOrderTotal(PDO $db, int $orderId): void {
    try {
        // Sum up active items
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(
                CASE
                    WHEN quantity > 0 THEN price * quantity
                    ELSE 0
                END
            ), 0) as new_subtotal
            FROM om_market_order_items
            WHERE order_id = ?
        ");
        $stmt->execute([$orderId]);
        $result = $stmt->fetch();
        $newSubtotal = (float)$result['new_subtotal'];

        // Get current order to preserve delivery fee etc
        $orderStmt = $db->prepare("SELECT delivery_fee, tip_amount, coupon_discount, service_fee FROM om_market_orders WHERE order_id = ?");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();

        $deliveryFee = (float)($order['delivery_fee'] ?? 0);
        $tip = (float)($order['tip_amount'] ?? 0);
        $discount = (float)($order['coupon_discount'] ?? 0);
        $serviceFee = (float)($order['service_fee'] ?? 0);

        $newTotal = $newSubtotal + $deliveryFee + $tip + $serviceFee - $discount;
        if ($newTotal < 0) $newTotal = 0;

        $db->prepare("
            UPDATE om_market_orders
            SET subtotal = ?, total = ?, date_modified = NOW()
            WHERE order_id = ?
        ")->execute([$newSubtotal, $newTotal, $orderId]);
    } catch (Exception $e) {
        error_log("[Substitution] Erro ao recalcular total: " . $e->getMessage());
    }
}
