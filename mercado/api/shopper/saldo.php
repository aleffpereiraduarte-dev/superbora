<?php
/**
 * GET /mercado/api/shopper/saldo.php?shopper_id=1
 */
require_once __DIR__ . "/../config.php";

try {
    $db = getDB();
    $shopper_id = intval($_GET["shopper_id"] ?? 0);

    if (!$shopper_id) {
        response(false, null, "shopper_id obrigatório", 400);
    }

    $stmt = $db->prepare("SELECT shopper_id, name, saldo, total_orders FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $shopper = $stmt->fetch();

    if (!$shopper) {
        response(false, null, "Shopper não encontrado", 404);
    }

    // Ganhos - verificar se tabela existe
    $hoje = 0;
    $semana = 0;
    $pedidos_hoje = 0;

    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_shopper_earnings WHERE shopper_id = ? AND DATE(created_at) = CURRENT_DATE");
        $stmt->execute([$shopper_id]);
        $hoje = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_shopper_earnings WHERE shopper_id = ? AND YEARWEEK(created_at) = YEARWEEK(NOW())");
        $stmt->execute([$shopper_id]);
        $semana = $stmt->fetchColumn();
    } catch (Exception $e) {
        // Tabela não existe ou coluna diferente, usar dados dos pedidos
        $stmt = $db->prepare("SELECT COALESCE(SUM(delivery_fee), 0) FROM om_market_orders WHERE shopper_id = ? AND status = 'entregue' AND DATE(entrega_finalizada_em) = CURRENT_DATE");
        $stmt->execute([$shopper_id]);
        $hoje = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(delivery_fee), 0) FROM om_market_orders WHERE shopper_id = ? AND status = 'entregue' AND YEARWEEK(entrega_finalizada_em) = YEARWEEK(NOW())");
        $stmt->execute([$shopper_id]);
        $semana = $stmt->fetchColumn();
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE shopper_id = ? AND status = 'entregue' AND DATE(entrega_finalizada_em) = CURRENT_DATE");
    $stmt->execute([$shopper_id]);
    $pedidos_hoje = $stmt->fetchColumn();

    response(true, [
        "shopper_id" => $shopper["shopper_id"],
        "nome" => $shopper["name"],
        "saldo_disponivel" => floatval($shopper["saldo"] ?? 0),
        "ganhos_hoje" => floatval($hoje),
        "ganhos_semana" => floatval($semana),
        "pedidos_hoje" => (int)$pedidos_hoje,
        "total_pedidos" => (int)($shopper["total_orders"] ?? 0)
    ]);

} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
