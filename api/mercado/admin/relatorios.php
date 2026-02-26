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

    $type = $_GET['type'] ?? 'vendas';
    $date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $date_to = $_GET['date_to'] ?? date('Y-m-d');

    $data = [];

    if ($type === 'vendas') {
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date,
                   COUNT(*) as pedidos,
                   SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total ELSE 0 END) as receita,
                   SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelados
            FROM om_market_orders
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$date_from, $date_to]);
        $data = $stmt->fetchAll();

    } elseif ($type === 'entregas') {
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date,
                   COUNT(*) as total,
                   SUM(CASE WHEN status = 'entregue' THEN 1 ELSE 0 END) as entregues,
                   SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelados,
                   AVG(delivery_fee) as avg_fee
            FROM om_market_orders
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$date_from, $date_to]);
        $data = $stmt->fetchAll();

    } elseif ($type === 'financeiro') {
        $stmt = $db->prepare("
            SELECT DATE(o.created_at) as date,
                   COALESCE(SUM(o.total), 0) as receita,
                   COALESCE(SUM(o.delivery_fee), 0) as taxas_entrega,
                   COALESCE(SUM(s.commission), 0) as comissoes
            FROM om_market_orders o
            LEFT JOIN om_market_sales s ON o.order_id = s.order_id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
              AND o.status NOT IN ('cancelled', 'refunded')
            GROUP BY DATE(o.created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$date_from, $date_to]);
        $data = $stmt->fetchAll();
    } else {
        response(false, null, "Tipo invalido. Use: vendas, entregas, financeiro", 400);
    }

    om_audit()->log('export', 'report', null, null, [
        'type' => $type,
        'period' => "{$date_from} - {$date_to}"
    ]);

    response(true, [
        'type' => $type,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'data' => $data
    ], "Relatorio gerado");
} catch (Exception $e) {
    error_log("[admin/relatorios] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
