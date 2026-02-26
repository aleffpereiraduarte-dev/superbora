<?php
/**
 * GET /api/mercado/shopper/ganhos.php
 * Retorna ganhos do shopper por periodo (hoje, semana, mes)
 */
require_once __DIR__ . "/../config/auth.php";
try {
    $db = getDB(); $auth = requireShopperAuth(); $shopper_id = $auth["uid"];
    $period = $_GET["period"] ?? "hoje";
    if (!in_array($period, ["hoje", "semana", "mes"])) { $period = "hoje"; }
    switch ($period) { case "hoje": $df = "AND DATE(created_at) = CURRENT_DATE"; break; case "semana": $df = "AND DATE_TRUNC('week', created_at) = DATE_TRUNC('week', CURRENT_DATE)"; break; case "mes": $df = "AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE) AND EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM CURRENT_DATE)"; break; }
    $stmt = $db->prepare("SELECT type, COALESCE(SUM(amount), 0) as total FROM om_shopper_transactions WHERE shopper_id = ? AND type IN ('earning','gorjeta','bonus') $df GROUP BY type");
    $stmt->execute([$shopper_id]); $by_type = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $base = floatval($by_type["earning"] ?? 0); $gorjetas = floatval($by_type["gorjeta"] ?? 0); $bonus = floatval($by_type["bonus"] ?? 0); $total_ganhos = $base + $gorjetas + $bonus;
    $stmt = $db->prepare("SELECT id, order_id, type, amount, description, created_at FROM om_shopper_transactions WHERE shopper_id = ? $df ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$shopper_id]); $transactions = $stmt->fetchAll();
    $tx_result = array_map(function($t) { return ["id" => (int)$t["id"], "order_id" => $t["order_id"] ? (int)$t["order_id"] : null, "type" => $t["type"], "amount" => floatval($t["amount"]), "amount_formatado" => ($t["amount"] >= 0 ? "+" : "") . "R$ " . number_format(abs($t["amount"]), 2, ",", "."), "description" => $t["description"], "created_at" => $t["created_at"]]; }, $transactions);
    $df_o = str_replace("created_at", "o.created_at", $df);
    $stmt = $db->prepare("SELECT o.order_id, o.rating, o.rating_comment, o.created_at, p.name as partner_name FROM om_market_orders o LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id WHERE o.shopper_id = ? AND o.rating IS NOT NULL AND o.rating > 0 $df_o ORDER BY o.created_at DESC LIMIT 10");
    $stmt->execute([$shopper_id]);
    $avaliacoes = array_map(function($a) { return ["order_id" => (int)$a["order_id"], "rating" => floatval($a["rating"]), "comment" => $a["rating_comment"], "partner_name" => $a["partner_name"], "date" => $a["created_at"]]; }, $stmt->fetchAll());
    response(true, ["period" => $period, "total_ganhos" => $total_ganhos, "total_ganhos_formatado" => "R$ " . number_format($total_ganhos, 2, ",", "."), "base" => $base, "gorjetas" => $gorjetas, "bonus" => $bonus, "transactions" => $tx_result, "avaliacoes" => $avaliacoes], "Ganhos carregados");
} catch (Exception $e) { error_log("[shopper/ganhos] Erro: " . $e->getMessage()); response(false, null, "Erro ao buscar ganhos", 500); }
