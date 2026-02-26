<?php
/**
 * GET/POST/DELETE /api/mercado/group-order/items.php
 * Manage items in a group order
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'OPTIONS') {
        header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
        exit;
    }

    // GET - list all items (requires auth; customer must be a participant)
    if ($method === 'GET') {
        $token = om_auth()->getTokenFromRequest();
        if (!$token) response(false, null, "Autenticacao necessaria", 401);
        $getPayload = om_auth()->validateToken($token);
        if (!$getPayload) response(false, null, "Token invalido", 401);
        if ($getPayload['type'] !== 'customer') response(false, null, "Tipo de token invalido", 403);
        $getCustomerId = (int)($getPayload['uid'] ?? 0);

        $groupOrderId = (int)($_GET['group_order_id'] ?? 0);
        if (!$groupOrderId) response(false, null, "group_order_id obrigatorio", 400);

        // Verify customer is a participant of this group order
        $partCheck = $db->prepare("SELECT id FROM om_market_group_order_participants WHERE group_order_id = ? AND customer_id = ?");
        $partCheck->execute([$groupOrderId, $getCustomerId]);
        if (!$partCheck->fetch()) {
            // Also allow the group order creator
            $creatorCheck = $db->prepare("SELECT id FROM om_market_group_orders WHERE id = ? AND creator_id = ?");
            $creatorCheck->execute([$groupOrderId, $getCustomerId]);
            if (!$creatorCheck->fetch()) {
                response(false, null, "Voce nao e participante deste pedido em grupo", 403);
            }
        }

        $stmt = $db->prepare("
            SELECT i.*,
                   p.customer_id, p.guest_name,
                   c.name as customer_name
            FROM om_market_group_order_items i
            JOIN om_market_group_order_participants p ON p.id = i.participant_id
            LEFT JOIN om_customers c ON c.customer_id = p.customer_id
            WHERE i.group_order_id = ?
            ORDER BY i.id ASC
        ");
        $stmt->execute([$groupOrderId]);
        $items = $stmt->fetchAll();

        // Group by participant
        $byParticipant = [];
        $total = 0;
        foreach ($items as $item) {
            $pid = (int)$item['participant_id'];
            if (!isset($byParticipant[$pid])) {
                $byParticipant[$pid] = [
                    'participant_id' => $pid,
                    'name' => $item['customer_name'] ?: $item['guest_name'] ?: 'Participante',
                    'items' => [],
                    'subtotal' => 0,
                ];
            }
            $itemTotal = (float)$item['price'] * (int)$item['quantity'];
            $byParticipant[$pid]['items'][] = [
                'id' => (int)$item['id'],
                'product_id' => (int)$item['product_id'],
                'product_name' => $item['product_name'],
                'quantity' => (int)$item['quantity'],
                'price' => (float)$item['price'],
                'notes' => $item['notes'],
                'total' => $itemTotal,
            ];
            $byParticipant[$pid]['subtotal'] += $itemTotal;
            $total += $itemTotal;
        }

        response(true, [
            'participants' => array_values($byParticipant),
            'total' => $total,
            'item_count' => count($items),
        ]);
    }

    // POST - add item
    if ($method === 'POST') {
        $input = getInput();
        // Require auth
        $token = om_auth()->getTokenFromRequest();
        if (!$token) response(false, null, "Autenticacao necessaria", 401);
        $tokenPayload = om_auth()->validateToken($token);
        if (!$tokenPayload) response(false, null, "Token invalido", 401);
        if ($tokenPayload['type'] !== 'customer') response(false, null, "Tipo de token invalido", 403);
        $authCustomerId = (int)($tokenPayload['uid'] ?? 0);

        $groupOrderId = (int)($input['group_order_id'] ?? 0);
        $participantId = (int)($input['participant_id'] ?? 0);
        $productId = (int)($input['product_id'] ?? 0);
        $productName = trim($input['product_name'] ?? '');
        $quantity = max(1, (int)($input['quantity'] ?? 1));
        $notes = trim($input['notes'] ?? '');

        if (!$groupOrderId || !$participantId || !$productId) {
            response(false, null, "group_order_id, participant_id e product_id obrigatorios", 400);
        }
        if (empty($productName)) response(false, null, "product_name obrigatorio", 400);

        // Server-side price lookup — never trust client-supplied price
        $stmtPrice = $db->prepare("SELECT price, special_price FROM om_market_products WHERE product_id = ?");
        $stmtPrice->execute([$productId]);
        $productData = $stmtPrice->fetch();
        if (!$productData) response(false, null, "Produto nao encontrado", 404);
        $price = (float)($productData['special_price'] ?: $productData['price']);
        if ($price <= 0) response(false, null, "Preco do produto invalido", 400);

        // Verify group order is active
        $check = $db->prepare("SELECT status, expires_at FROM om_market_group_orders WHERE id = ?");
        $check->execute([$groupOrderId]);
        $group = $check->fetch();
        if (!$group || $group['status'] !== 'active') {
            response(false, null, "Pedido em grupo nao esta ativo", 400);
        }
        if (strtotime($group['expires_at']) < time()) {
            response(false, null, "Pedido em grupo expirou", 400);
        }

        // Verify participant belongs to this group order and to the authenticated user
        $pCheck = $db->prepare("SELECT id, customer_id FROM om_market_group_order_participants WHERE id = ? AND group_order_id = ?");
        $pCheck->execute([$participantId, $groupOrderId]);
        $participant = $pCheck->fetch();
        if (!$participant) response(false, null, "Participante nao encontrado", 404);
        if ((int)$participant['customer_id'] !== $authCustomerId) {
            response(false, null, "Voce so pode adicionar itens como seu proprio participante", 403);
        }

        $stmt = $db->prepare("
            INSERT INTO om_market_group_order_items (group_order_id, participant_id, product_id, product_name, quantity, price, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$groupOrderId, $participantId, $productId, $productName, $quantity, $price, $notes]);

        response(true, [
            'item_id' => (int)$db->lastInsertId(),
        ], "Item adicionado!");
    }

    // DELETE - remove item
    if ($method === 'DELETE') {
        $itemId = (int)($_GET['item_id'] ?? 0);
        if (!$itemId) response(false, null, "item_id obrigatorio", 400);

        // Require auth
        $token = om_auth()->getTokenFromRequest();
        if (!$token) response(false, null, "Autenticacao necessaria", 401);
        $tokenPayload = om_auth()->validateToken($token);
        if (!$tokenPayload) response(false, null, "Token invalido", 401);
        $authUserId = (int)($tokenPayload['uid'] ?? 0);

        // Look up item and group order
        $stmt = $db->prepare("
            SELECT i.*, g.creator_id, p.customer_id as participant_customer_id
            FROM om_market_group_order_items i
            JOIN om_market_group_orders g ON g.id = i.group_order_id
            JOIN om_market_group_order_participants p ON p.id = i.participant_id
            WHERE i.id = ?
        ");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();

        if (!$item) response(false, null, "Item nao encontrado", 404);

        // Allow removal only by item owner or group order creator
        $isOwner = (int)$item['participant_customer_id'] === $authUserId;
        $isCreator = (int)$item['creator_id'] === $authUserId;
        if (!$isOwner && !$isCreator) {
            response(false, null, "Voce so pode remover seus proprios itens", 403);
        }

        $db->prepare("DELETE FROM om_market_group_order_items WHERE id = ?")->execute([$itemId]);

        response(true, null, "Item removido!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[API Group Order Items] Erro: " . $e->getMessage());
    response(false, null, "Erro ao gerenciar itens", 500);
}
