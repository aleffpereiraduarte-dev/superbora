<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();
try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    $month = date('Y-m-01');
    $stats = dbQuery($db, "SELECT COUNT(*) as orders, COALESCE(SUM(total),0) as revenue FROM om_market_orders WHERE partner_id = ? AND date_added >= ? AND status NOT IN ('cancelado','recusado')", [$partnerId, $month])->fetch();
    
    response(true, [
        'overview' => [
            'reach' => (int)$stats['orders'] * 3,
            'impressions' => (int)$stats['orders'] * 8,
            'conversions' => (int)$stats['orders'],
            'revenue' => round((float)$stats['revenue'], 2),
            'roi' => 0,
        ],
        'channels' => [
            ['name' => 'SuperBora App', 'reach' => (int)$stats['orders'] * 3, 'conversions' => (int)$stats['orders']],
            ['name' => 'Push', 'reach' => 0, 'conversions' => 0],
            ['name' => 'WhatsApp', 'reach' => 0, 'conversions' => 0],
        ],
        'campaigns' => [],
        'budget' => ['total' => 0, 'spent' => 0, 'available' => 0],
    ]);
} catch (Exception $e) {
    error_log("[marketing-dashboard] Error: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
