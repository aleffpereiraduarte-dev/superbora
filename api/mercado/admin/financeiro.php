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

    $period = $_GET["period"] ?? "month";

    // Whitelist date filter — parameterized interval to prevent SQL injection
    $intervalMap = [
        "today" => null,
        "week" => "7 days",
        "year" => "1 year",
        "month" => "30 days"
    ];
    $intervalValue = $intervalMap[$period] ?? $intervalMap["month"];

    if ($intervalValue === null) {
        // "today" — use DATE() = CURRENT_DATE
        $date_sql = "DATE(o.created_at) = CURRENT_DATE";
        $date_params = [];
    } else {
        $date_sql = "o.created_at >= CURRENT_DATE - CAST(? AS INTERVAL)";
        $date_params = [$intervalValue];
    }

    // Revenue
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as receita
        FROM om_market_orders o
        WHERE {$date_sql} AND status NOT IN ('cancelled', 'cancelado', 'refunded')
    ");
    $stmt->execute($date_params);
    $receita = (float)$stmt->fetch()["receita"];

    // Commissions (platform_fee from orders)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(COALESCE(platform_fee, service_fee, 0)), 0) as comissoes
        FROM om_market_orders o
        WHERE {$date_sql} AND status NOT IN ('cancelled', 'cancelado', 'refunded')
    ");
    $stmt->execute($date_params);
    $comissoes = (float)$stmt->fetch()["comissoes"];

    // Delivery fees
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(delivery_fee), 0) as taxas
        FROM om_market_orders o
        WHERE {$date_sql} AND status NOT IN ('cancelled', 'cancelado', 'refunded')
    ");
    $stmt->execute($date_params);
    $taxas_entrega = (float)$stmt->fetch()["taxas"];

    // Refunds
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as reembolsos
        FROM om_market_orders o
        WHERE {$date_sql} AND status IN ('refunded')
    ");
    $stmt->execute($date_params);
    $reembolsos = (float)$stmt->fetch()["reembolsos"];

    $lucro_liquido = $comissoes + $taxas_entrega - $reembolsos;

    response(true, [
        "period" => $period,
        "receita" => $receita,
        "comissoes" => $comissoes,
        "taxas_entrega" => $taxas_entrega,
        "reembolsos" => $reembolsos,
        "lucro_liquido" => $lucro_liquido
    ], "Dados financeiros");
} catch (Exception $e) {
    error_log("[admin/financeiro] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
