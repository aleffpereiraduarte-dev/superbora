<?php
/**
 * GET /api/mercado/shopper/performance.php
 * Retorna metricas de performance do shopper
 */
require_once __DIR__ . "/../config/auth.php";
try {
    $db = getDB(); $auth = requireShopperAuth(); $shopper_id = $auth["uid"];
    $stmt = $db->prepare("SELECT orders_completed, orders_cancelled, avg_time_per_order, avg_rating, accuracy_rate FROM om_shopper_performance WHERE shopper_id = ? ORDER BY period_date DESC LIMIT 1");
    $stmt->execute([$shopper_id]); $perf = $stmt->fetch();
    $stmt = $db->prepare("SELECT rating FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]); $rating = floatval($stmt->fetchColumn() ?: 5.0);
    if ($perf) {
        $total_orders = (int)$perf["orders_completed"] + (int)$perf["orders_cancelled"];
        $completion_rate = $total_orders > 0 ? round(((int)$perf["orders_completed"] / $total_orders) * 100, 1) : 100;
        $acceptance_rate = floatval($perf["accuracy_rate"] ?? 100);
        $avg_delivery_time = floatval($perf["avg_time_per_order"]);
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_shopper_transactions WHERE shopper_id = ? AND type IN ('earning','gorjeta','bonus')"); $stmt->execute([$shopper_id]); $total_earnings = floatval($stmt->fetchColumn());
        $ranking_position = 0;
    }
    else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE shopper_id = ?"); $stmt->execute([$shopper_id]); $total_orders = (int)$stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE shopper_id = ? AND status = 'entregue'"); $stmt->execute([$shopper_id]); $completed = (int)$stmt->fetchColumn();
        $completion_rate = $total_orders > 0 ? round(($completed / $total_orders) * 100, 1) : 100; $acceptance_rate = 100.0;
        $stmt = $db->prepare("SELECT AVG(EXTRACT(EPOCH FROM (delivered_at - accepted_at)) / 60) FROM om_market_orders WHERE shopper_id = ? AND status = 'entregue' AND accepted_at IS NOT NULL AND delivered_at IS NOT NULL"); $stmt->execute([$shopper_id]); $avg_delivery_time = floatval($stmt->fetchColumn() ?: 0);
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_shopper_transactions WHERE shopper_id = ? AND type IN ('earning','gorjeta','bonus')"); $stmt->execute([$shopper_id]); $total_earnings = floatval($stmt->fetchColumn());
        $ranking_position = 0;
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_shoppers WHERE status = '1'"); $stmt->execute(); $total_shoppers = (int)$stmt->fetchColumn();
    if ($ranking_position === 0 && $total_shoppers > 0) { $stmt = $db->prepare("SELECT COUNT(*) + 1 FROM om_market_shoppers WHERE status = '1' AND rating > (SELECT COALESCE(rating, 0) FROM om_market_shoppers WHERE shopper_id = ?)"); $stmt->execute([$shopper_id]); $ranking_position = (int)$stmt->fetchColumn(); }
    response(true, ["acceptance_rate" => $acceptance_rate, "completion_rate" => $completion_rate, "avg_delivery_time" => round($avg_delivery_time, 1), "avg_delivery_time_label" => round($avg_delivery_time) . " min", "total_orders" => $total_orders, "total_earnings" => $total_earnings, "total_earnings_formatado" => "R$ " . number_format($total_earnings, 2, ",", "."), "rating" => $rating, "ranking_position" => $ranking_position, "total_shoppers" => $total_shoppers], "Performance carregada");
} catch (Exception $e) { error_log("[shopper/performance] Erro: " . $e->getMessage()); response(false, null, "Erro ao buscar performance", 500); }
