<?php
/**
 * GET /api/mercado/gift-cards/balance.php
 * Returns customer's gift card balance
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    // Check if gift cards table exists
    $tableExists = $db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'om_market_gift_cards')")->fetchColumn();

    if (!$tableExists) {
        // Gift cards feature not yet set up — return zero balance gracefully
        response(true, [
            'balance' => 0,
            'redeemed_history' => [],
            'purchased_cards' => [],
        ]);
        exit;
    }

    // Sum of remaining balance from all redeemed gift cards for this customer
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(balance), 0) as total_balance,
               COALESCE(SUM(amount), 0) as total_redeemed
        FROM om_market_gift_cards
        WHERE redeemed_by = ? AND status = 'redeemed'
    ");
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();
    $balance = (float)$row['total_balance'];
    $totalRedeemed = (float)$row['total_redeemed'];

    // Check if transactions table exists for detailed spent tracking
    $txTableExists = $db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'om_market_gift_card_transactions')")->fetchColumn();

    if ($txTableExists) {
        $stmtSpent = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_spent
            FROM om_market_gift_card_transactions
            WHERE customer_id = ? AND type = 'spent'
        ");
        $stmtSpent->execute([$customerId]);
        $totalSpent = (float)$stmtSpent->fetch()['total_spent'];
    } else {
        // Derive spent from difference between redeemed amounts and remaining balances
        $totalSpent = $totalRedeemed - $balance;
    }

    // Get history of redeemed cards
    $stmt = $db->prepare("
        SELECT code, amount, redeemed_at
        FROM om_market_gift_cards
        WHERE redeemed_by = ? AND status = 'redeemed'
        ORDER BY redeemed_at DESC
        LIMIT 20
    ");
    $stmt->execute([$customerId]);
    $history = $stmt->fetchAll();

    // Get cards purchased by this customer
    $stmt = $db->prepare("
        SELECT code, amount, balance, recipient_name, status, created_at
        FROM om_market_gift_cards
        WHERE buyer_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$customerId]);
    $purchased = $stmt->fetchAll();

    response(true, [
        'balance' => $balance,
        'total_redeemed' => $totalRedeemed,
        'total_spent' => $totalSpent,
        'redeemed_history' => $history,
        'purchased_cards' => $purchased,
    ]);

} catch (Exception $e) {
    error_log("[API Gift Card Balance] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar saldo", 500);
}
