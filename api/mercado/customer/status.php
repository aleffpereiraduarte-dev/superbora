<?php
/**
 * GET /api/mercado/customer/status.php
 * Returns lightweight customer status: order count, first-order eligibility, active coupons
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token nao fornecido", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Nao autorizado", 401);
    $customerId = (int)$payload['uid'];

    // Fetch customer name
    $stmtCustomer = $db->prepare("SELECT name FROM om_customers WHERE customer_id = ? AND is_active = '1'");
    $stmtCustomer->execute([$customerId]);
    $customerRow = $stmtCustomer->fetch();
    $customerName = $customerRow ? $customerRow['name'] : '';

    // Count completed orders (non-cancelled)
    $stmtOrders = $db->prepare("
        SELECT COUNT(*) as cnt FROM om_market_orders
        WHERE customer_id = ? AND status NOT IN ('cancelled', 'cancelado')
    ");
    $stmtOrders->execute([$customerId]);
    $orderCount = (int)$stmtOrders->fetch()['cnt'];

    // Check for active personal coupons (BEMVINDO or INDICA)
    $activeCoupons = [];
    try {
        $stmtCoupons = $db->prepare("
            SELECT code, discount_type, discount_value, valid_until
            FROM om_market_coupons
            WHERE (code = ? OR code = ?) AND status = 'active' AND (valid_until IS NULL OR valid_until > NOW())
        ");
        $welcomeCode = 'BEMVINDO' . $customerId;
        $indicaCode = 'INDICA' . $customerId;
        $stmtCoupons->execute([$welcomeCode, $indicaCode]);
        $activeCoupons = $stmtCoupons->fetchAll();
    } catch (Exception $e) {
        // Table may not exist yet
    }

    $isNewUser = ($orderCount === 0);

    response(true, [
        'nome' => $customerName,
        'order_count' => $orderCount,
        'is_new_user' => $isNewUser,
        'active_coupons' => $activeCoupons,
        'show_welcome_banner' => $isNewUser,
    ]);

} catch (Exception $e) {
    error_log("[API Customer Status] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar status", 500);
}
