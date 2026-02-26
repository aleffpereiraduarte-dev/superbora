<?php
/**
 * GET /api/mercado/group-order/status.php
 * Returns full state of a group order
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Require authentication
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $authPayload = om_auth()->validateToken($token);
    if (!$authPayload || $authPayload['type'] !== 'customer') {
        response(false, null, "Token invalido", 401);
    }
    $authCustomerId = (int)$authPayload['uid'];

    $shareCode = strtoupper(trim($_GET['code'] ?? ''));
    $groupOrderId = (int)($_GET['group_order_id'] ?? 0);

    if (!$shareCode && !$groupOrderId) {
        response(false, null, "code ou group_order_id obrigatorio", 400);
    }

    // Find group order
    if ($shareCode) {
        $stmt = $db->prepare("
            SELECT g.*, p.name as partner_name, p.logo, p.banner, p.categoria
            FROM om_market_group_orders g
            JOIN om_market_partners p ON p.partner_id = g.partner_id
            WHERE g.share_code = ?
        ");
        $stmt->execute([$shareCode]);
    } else {
        $stmt = $db->prepare("
            SELECT g.*, p.name as partner_name, p.logo, p.banner, p.categoria
            FROM om_market_group_orders g
            JOIN om_market_partners p ON p.partner_id = g.partner_id
            WHERE g.id = ?
        ");
        $stmt->execute([$groupOrderId]);
    }
    $group = $stmt->fetch();

    if (!$group) response(false, null, "Pedido em grupo nao encontrado", 404);

    // Verify the authenticated customer is the creator or a participant of this group order
    $isCreator = ((int)$group['creator_id'] === $authCustomerId);
    if (!$isCreator) {
        $partStmt = $db->prepare("SELECT id FROM om_market_group_order_participants WHERE group_order_id = ? AND customer_id = ?");
        $partStmt->execute([$group['id'], $authCustomerId]);
        if (!$partStmt->fetch()) {
            response(false, null, "Voce nao tem acesso a este pedido em grupo", 403);
        }
    }

    // Auto-expire
    if ($group['status'] === 'active' && strtotime($group['expires_at']) < time()) {
        $db->prepare("UPDATE om_market_group_orders SET status = 'expired' WHERE id = ?")->execute([$group['id']]);
        $group['status'] = 'expired';
    }

    // Get participants
    $stmt = $db->prepare("
        SELECT p.id, p.customer_id, p.guest_name, p.joined_at,
               c.name as customer_name
        FROM om_market_group_order_participants p
        LEFT JOIN om_customers c ON c.customer_id = p.customer_id
        WHERE p.group_order_id = ?
        ORDER BY p.joined_at ASC
    ");
    $stmt->execute([$group['id']]);
    $participants = $stmt->fetchAll();

    // Get items grouped by participant
    $stmt = $db->prepare("
        SELECT i.*, p.customer_id, p.guest_name, c.name as customer_name
        FROM om_market_group_order_items i
        JOIN om_market_group_order_participants p ON p.id = i.participant_id
        LEFT JOIN om_customers c ON c.customer_id = p.customer_id
        WHERE i.group_order_id = ?
        ORDER BY i.id ASC
    ");
    $stmt->execute([$group['id']]);
    $items = $stmt->fetchAll();

    $total = 0;
    $formattedItems = [];
    foreach ($items as $item) {
        $itemTotal = (float)$item['price'] * (int)$item['quantity'];
        $total += $itemTotal;
        $formattedItems[] = [
            'id' => (int)$item['id'],
            'participant_id' => (int)$item['participant_id'],
            'participant_name' => $item['customer_name'] ?: $item['guest_name'] ?: 'Participante',
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'],
            'quantity' => (int)$item['quantity'],
            'price' => (float)$item['price'],
            'notes' => $item['notes'],
            'total' => $itemTotal,
        ];
    }

    $formattedParticipants = [];
    foreach ($participants as $p) {
        $formattedParticipants[] = [
            'id' => (int)$p['id'],
            'name' => $p['customer_name'] ?: $p['guest_name'] ?: 'Participante',
            'is_creator' => (int)$p['customer_id'] === (int)$group['creator_id'],
            'joined_at' => $p['joined_at'],
        ];
    }

    // Get creator name
    $creatorStmt = $db->prepare("SELECT name FROM om_customers WHERE customer_id = ?");
    $creatorStmt->execute([$group['creator_id']]);
    $creator = $creatorStmt->fetch();

    response(true, [
        'group_order_id' => (int)$group['id'],
        'share_code' => $group['share_code'],
        'status' => $group['status'],
        'creator_name' => $creator ? $creator['name'] : 'Organizador',
        'creator_id' => (int)$group['creator_id'],
        'partner' => [
            'id' => (int)$group['partner_id'],
            'nome' => $group['partner_name'],
            'logo' => $group['logo'],
            'banner' => $group['banner'],
            'categoria' => $group['categoria'],
        ],
        'participants' => $formattedParticipants,
        'items' => $formattedItems,
        'total' => $total,
        'item_count' => count($formattedItems),
        'expires_at' => $group['expires_at'],
        'created_at' => $group['created_at'],
    ]);

} catch (Exception $e) {
    error_log("[API Group Order Status] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar status do pedido em grupo", 500);
}
