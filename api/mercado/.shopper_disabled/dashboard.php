<?php
/**
 * GET /api/mercado/shopper/dashboard.php
 * Retorna dados do dashboard do shopper
 */
require_once __DIR__ . "/../config/auth.php";
try {
    $db = getDB();
    $auth = requireShopperAuth();
    $shopper_id = $auth["uid"];
    $stmt = $db->prepare("SELECT shopper_id, name, rating, is_online, disponivel, pedido_atual_id FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $shopper = $stmt->fetch();
    if (!$shopper) { response(false, null, "Shopper nao encontrado", 404); }
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_shopper_transactions WHERE shopper_id = ? AND type IN ('earning','gorjeta','bonus') AND DATE(created_at) = CURRENT_DATE");
    $stmt->execute([$shopper_id]);
    $ganhos_hoje = floatval($stmt->fetchColumn());
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_shopper_transactions WHERE shopper_id = ? AND type IN ('earning','gorjeta','bonus') AND DATE_TRUNC('week', created_at) = DATE_TRUNC('week', CURRENT_DATE)");
    $stmt->execute([$shopper_id]);
    $ganhos_semana = floatval($stmt->fetchColumn());
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_shopper_transactions WHERE shopper_id = ? AND type IN ('earning','gorjeta','bonus') AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE) AND EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM CURRENT_DATE)");
    $stmt->execute([$shopper_id]);
    $ganhos_mes = floatval($stmt->fetchColumn());
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE shopper_id = ? AND DATE(created_at) = CURRENT_DATE");
    $stmt->execute([$shopper_id]);
    $pedidos_hoje = (int)$stmt->fetchColumn();
    $avaliacao_media = floatval($shopper["rating"] ?? 5.0);
    $active_order = null;
    if ($shopper["pedido_atual_id"]) {
        $stmt = $db->prepare("SELECT o.order_id, o.status, o.total, o.delivery_address, o.created_at, p.name as partner_name, p.logo as partner_logo, p.address as partner_address, (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_items, (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id AND collected = 1) as collected_items FROM om_market_orders o LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id WHERE o.order_id = ? AND o.status NOT IN ('entregue','cancelado','cancelled')");
        $stmt->execute([$shopper["pedido_atual_id"]]);
        $order = $stmt->fetch();
        if ($order) { $active_order = ["order_id" => (int)$order["order_id"], "status" => $order["status"], "total" => floatval($order["total"]), "delivery_address" => $order["delivery_address"], "partner_name" => $order["partner_name"], "partner_logo" => $order["partner_logo"], "partner_address" => $order["partner_address"], "total_items" => (int)$order["total_items"], "collected_items" => (int)$order["collected_items"], "created_at" => $order["created_at"]]; }
    }
    $xp = 0; $level = "Iniciante";
    $stmt = $db->prepare("SELECT COALESCE(SUM(progress), 0) as total_progress, MAX(level) as max_level FROM om_shopper_achievements WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $achievements = $stmt->fetch();
    if ($achievements && $achievements["total_progress"]) { $xp = (int)$achievements["total_progress"]; }
    $streak_days = 0;
    $stmt = $db->prepare("SELECT DISTINCT DATE(created_at) as dia FROM om_market_orders WHERE shopper_id = ? AND status = 'entregue' ORDER BY dia DESC LIMIT 60");
    $stmt->execute([$shopper_id]);
    $dias = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($dias)) { foreach ($dias as $i => $dia) { $expected = date('Y-m-d', strtotime("-{$i} days")); if ($dia === $expected) { $streak_days++; } else { break; } } }
    response(true, ["ganhos_hoje" => $ganhos_hoje, "ganhos_hoje_formatado" => "R$ " . number_format($ganhos_hoje, 2, ',', '.'), "ganhos_semana" => $ganhos_semana, "ganhos_semana_formatado" => "R$ " . number_format($ganhos_semana, 2, ',', '.'), "ganhos_mes" => $ganhos_mes, "ganhos_mes_formatado" => "R$ " . number_format($ganhos_mes, 2, ',', '.'), "pedidos_hoje" => $pedidos_hoje, "avaliacao_media" => $avaliacao_media, "is_online" => (bool)($shopper["is_online"] ?? $shopper["disponivel"] ?? false), "active_order" => $active_order, "xp" => $xp, "level" => $level, "streak_days" => $streak_days], "Dashboard carregado com sucesso");
} catch (Exception $e) {
    error_log("[shopper/dashboard] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar dashboard", 500);
}
