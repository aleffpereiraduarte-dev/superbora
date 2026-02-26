<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];

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
    error_log("[admin/dispatch] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
