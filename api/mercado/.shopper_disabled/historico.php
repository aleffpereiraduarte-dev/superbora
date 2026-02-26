<?php
/**
 * GET /api/mercado/shopper/historico.php
 * Retorna historico de pedidos finalizados do shopper com paginacao
 */
require_once __DIR__ . "/../config/auth.php";
try {
    $db = getDB(); $auth = requireShopperAuth(); $shopper_id = $auth["uid"];
    if ($_SERVER["REQUEST_METHOD"] !== "GET") { response(false, null, "Metodo nao permitido", 405); }
    $page = max(1, (int)($_GET["page"] ?? 1)); $limit = 20; $offset = ($page - 1) * $limit;
    $status_filter = trim($_GET["status"] ?? "");
    $valid_statuses = ["entregue", "cancelado", "cancelled"];
    $where_status = "o.status IN ('entregue','cancelado','cancelled')";
    $params = [$shopper_id];
    if ($status_filter && in_array($status_filter, $valid_statuses)) {
        $where_status = "o.status = ?";
        $params[] = $status_filter;
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders o WHERE o.shopper_id = ? AND $where_status");
    $stmt->execute($params); $total = (int)$stmt->fetchColumn();
    $sql = "SELECT o.order_id, o.total, o.delivery_fee, o.status, o.created_at, o.delivered_at, o.delivery_address, p.name as partner_name, p.logo as partner_logo, (SELECT COUNT(*) FROM om_market_order_items i WHERE i.order_id = o.order_id) as items_count FROM om_market_orders o LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id WHERE o.shopper_id = ? AND $where_status ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params); $orders = $stmt->fetchAll();
    $result = array_map(function($o) use ($db) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_shopper_transactions WHERE order_id = ? AND type IN ('earning','gorjeta','bonus')");
        $stmt->execute([$o["order_id"]]); $earning = floatval($stmt->fetchColumn());
        return ["order_id" => (int)$o["order_id"], "total" => floatval($o["total"]), "delivery_fee" => floatval($o["delivery_fee"] ?? 0), "earning" => $earning, "earning_formatado" => "R$ " . number_format($earning, 2, ",", "."), "status" => $o["status"], "items_count" => (int)$o["items_count"], "partner_name" => $o["partner_name"], "partner_logo" => $o["partner_logo"], "delivery_address" => $o["delivery_address"], "created_at" => $o["created_at"], "delivered_at" => $o["delivered_at"]];
    }, $orders);
    response(true, ["orders" => $result, "pagination" => ["page" => $page, "limit" => $limit, "total" => $total, "total_pages" => ceil($total / $limit), "has_more" => ($offset + $limit) < $total]], "Historico carregado");
} catch (Exception $e) { error_log("[shopper/historico] Erro: " . $e->getMessage()); response(false, null, "Erro ao carregar historico", 500); }
