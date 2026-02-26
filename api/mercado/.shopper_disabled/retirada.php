<?php
/**
 * GET/POST /api/mercado/shopper/retirada.php
 * GET: Retorna dados de retirada do pedido (QR code, codigo_entrega, dados do cliente)
 * POST: Confirma retirada, atualiza status para entregue, libera shopper
 */
require_once __DIR__ . "/../config/auth.php";
try {
    $db = getDB(); $auth = requireShopperAuth(); $shopper_id = $auth["uid"];
    $method = $_SERVER["REQUEST_METHOD"];
    if ($method === "GET") {
        $order_id = (int)($_GET["order_id"] ?? 0);
        if (!$order_id) { response(false, null, "order_id e obrigatorio", 400); }
        $stmt = $db->prepare("SELECT o.order_id, o.status, o.total, o.delivery_fee, o.delivery_address, o.codigo_entrega, o.customer_name, o.customer_phone, o.created_at, p.name as partner_name, p.logo as partner_logo, p.address as partner_address, (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_items, (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id AND collected = 1) as collected_items FROM om_market_orders o LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id WHERE o.order_id = ? AND o.shopper_id = ?");
        $stmt->execute([$order_id, $shopper_id]); $order = $stmt->fetch();
        if (!$order) { response(false, null, "Pedido nao encontrado ou nao pertence a voce", 403); }
        $phone = $order["customer_phone"] ?? "";
        $phone_masked = strlen($phone) >= 8 ? substr($phone, 0, 4) . "****" . substr($phone, -2) : $phone;
        response(true, ["order_id" => (int)$order["order_id"], "status" => $order["status"], "total" => floatval($order["total"]), "delivery_fee" => floatval($order["delivery_fee"] ?? 0), "delivery_address" => $order["delivery_address"], "codigo_entrega" => $order["codigo_entrega"], "customer_name" => $order["customer_name"], "customer_phone_masked" => $phone_masked, "partner_name" => $order["partner_name"], "partner_logo" => $order["partner_logo"], "partner_address" => $order["partner_address"], "total_items" => (int)$order["total_items"], "collected_items" => (int)$order["collected_items"], "created_at" => $order["created_at"]], "Dados de retirada carregados");
    } elseif ($method === "POST") {
        $input = getInput(); $order_id = (int)($input["order_id"] ?? 0); $codigo = trim($input["codigo_entrega"] ?? ""); $photo = trim($input["photo"] ?? "");
        if (!$order_id) { response(false, null, "order_id e obrigatorio", 400); }
        $db->beginTransaction();
        try {
            // Buscar pedido com lock (FOR UPDATE previne race condition)
            $stmt = $db->prepare("SELECT order_id, status, codigo_entrega, is_pickup FROM om_market_orders WHERE order_id = ? AND shopper_id = ? FOR UPDATE");
            $stmt->execute([$order_id, $shopper_id]); $order = $stmt->fetch();
            if (!$order) { $db->rollBack(); response(false, null, "Pedido nao encontrado ou nao pertence a voce", 403); }
            if (in_array($order["status"], ["entregue", "delivered", "retirado", "cancelado", "cancelled"])) { $db->rollBack(); response(false, null, "Pedido ja foi finalizado", 400); }
            if ($order["codigo_entrega"] && $codigo !== $order["codigo_entrega"]) { $db->rollBack(); response(false, null, "Codigo de entrega incorreto", 400); }
            $isPickup = (bool)($order['is_pickup'] ?? false);
            $finalStatus = $isPickup ? 'retirado' : 'entregue';
            $statusAnterior = $order['status'];
            $stmt = $db->prepare("UPDATE om_market_orders SET status = ?, delivered_at = NOW(), updated_at = NOW(), date_modified = NOW() WHERE order_id = ? AND status = ?");
            $stmt->execute([$finalStatus, $order_id, $statusAnterior]);
            if ($stmt->rowCount() === 0) { $db->rollBack(); response(false, null, "Pedido ja foi atualizado por outra operacao", 409); }
            $stmt = $db->prepare("UPDATE om_market_shoppers SET disponivel = 1, is_online = 1, pedido_atual_id = NULL WHERE shopper_id = ?");
            $stmt->execute([$shopper_id]);
            if ($photo) {
                $stmt = $db->prepare("UPDATE om_market_orders SET delivery_photo = ? WHERE order_id = ?");
                $stmt->execute([$photo, $order_id]);
            }
            $db->commit();
        } catch (Exception $e) { $db->rollBack(); throw $e; }
        $msg = $isPickup ? "Retirada confirmada com sucesso!" : "Entrega confirmada com sucesso!";
        logAudit("update", "order", $order_id, ["status" => $order["status"]], ["status" => $finalStatus], "Retirada confirmada pelo shopper #$shopper_id");
        response(true, ["order_id" => $order_id, "status" => $finalStatus, "delivered_at" => date("c")], $msg);
    } else { response(false, null, "Metodo nao permitido", 405); }
} catch (Exception $e) { error_log("[shopper/retirada] Erro: " . $e->getMessage()); response(false, null, "Erro ao processar retirada", 500); }
