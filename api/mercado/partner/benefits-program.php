<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();
try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    $stmt = dbQuery($db, "SELECT COUNT(*) as total_orders, COALESCE(SUM(total),0) as total_revenue FROM om_market_orders WHERE partner_id = ? AND status NOT IN ('cancelado','recusado')", [$partnerId]);
    $stats = $stmt->fetch();
    $orders = (int)$stats['total_orders'];
    $tier = $orders >= 2500 ? 'elite' : ($orders >= 1000 ? 'destaque' : ($orders >= 500 ? 'crescendo' : 'iniciante'));
    
    $benefits = [
        ['id' => 1, 'name' => 'Creditos de marketing', 'description' => 'R$50 em creditos de destaque', 'points' => 500, 'available' => true],
        ['id' => 2, 'name' => 'Comissao reduzida', 'description' => '2% de desconto por 30 dias', 'points' => 1000, 'available' => $orders >= 100],
        ['id' => 3, 'name' => 'Destaque na vitrine', 'description' => 'Posicao premium por 7 dias', 'points' => 750, 'available' => $orders >= 50],
        ['id' => 4, 'name' => 'Suporte prioritario', 'description' => 'Atendimento em ate 1h', 'points' => 300, 'available' => true],
    ];
    
    response(true, [
        'tier' => $tier,
        'total_orders' => $orders,
        'points' => $orders * 10,
        'benefits' => $benefits,
        'active_benefits' => [],
        'history' => [],
    ]);
} catch (Exception $e) {
    error_log("[benefits-program] Error: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
