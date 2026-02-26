<?php
/**
 * GET /api/mercado/partner/stats.php
 * Dashboard stats for partner panel
 * Returns: total_produtos, promos_ativas, receita_mes, pedidos_mes
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    // Total de produtos vinculados ao parceiro
    $stmtProdutos = $db->prepare("
        SELECT COUNT(*) FROM om_market_products_price
        WHERE partner_id = ? AND status = '1'
    ");
    $stmtProdutos->execute([$partner_id]);
    $total_produtos = (int)$stmtProdutos->fetchColumn();

    // Promocoes ativas
    $stmtPromos = $db->prepare("
        SELECT COUNT(*) FROM om_partner_promotions
        WHERE partner_id = ? AND active = 1 AND end_date >= NOW()
    ");
    $stmtPromos->execute([$partner_id]);
    $promos_ativas = (int)$stmtPromos->fetchColumn();

    // Receita do mes (tabela de vendas)
    $stmtReceita = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM om_market_sales
        WHERE partner_id = ?
          AND EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM NOW())
          AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM NOW())
    ");
    $stmtReceita->execute([$partner_id]);
    $receita_mes = (float)$stmtReceita->fetchColumn();

    // Pedidos do mes
    $stmtPedidos = $db->prepare("
        SELECT COUNT(*) FROM om_market_orders
        WHERE partner_id = ?
          AND EXTRACT(MONTH FROM date_added) = EXTRACT(MONTH FROM NOW())
          AND EXTRACT(YEAR FROM date_added) = EXTRACT(YEAR FROM NOW())
    ");
    $stmtPedidos->execute([$partner_id]);
    $pedidos_mes = (int)$stmtPedidos->fetchColumn();

    // Pedidos de hoje
    $stmtPedidosHoje = $db->prepare("
        SELECT COUNT(*) FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) = CURRENT_DATE
    ");
    $stmtPedidosHoje->execute([$partner_id]);
    $pedidos_hoje = (int)$stmtPedidosHoje->fetchColumn();

    // Receita de hoje
    $stmtReceitaHoje = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM om_market_sales
        WHERE partner_id = ?
          AND DATE(created_at) = CURRENT_DATE
    ");
    $stmtReceitaHoje->execute([$partner_id]);
    $receita_hoje = (float)$stmtReceitaHoje->fetchColumn();

    // Pedidos pendentes (aguardando aceitacao)
    $stmtPendentes = $db->prepare("
        SELECT COUNT(*) FROM om_market_orders
        WHERE partner_id = ?
          AND status IN ('pending', 'new', 'aguardando')
    ");
    $stmtPendentes->execute([$partner_id]);
    $pendentes = (int)$stmtPendentes->fetchColumn();

    response(true, [
        "total_produtos" => $total_produtos,
        "promos_ativas" => $promos_ativas,
        "receita_mes" => round($receita_mes, 2),
        "pedidos_mes" => $pedidos_mes,
        "pedidos_hoje" => $pedidos_hoje,
        "receita_hoje" => round($receita_hoje, 2),
        "pendentes" => $pendentes
    ], "Stats carregadas");

} catch (Exception $e) {
    error_log("[partner/stats] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
