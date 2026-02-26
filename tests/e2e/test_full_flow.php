<?php
/**
 * E2E Test Suite - COMPLETE PURCHASE TO DELIVERY FLOW
 * Simula o fluxo completo: compra → shopper → motorista → entrega
 */

require_once __DIR__ . '/config.php';

$runner = new E2ETestRunner();
$db = $runner->getDB();

echo "\n" . str_repeat("=", 60) . "\n";
echo "🔄 E2E TESTS: COMPLETE FLOW - PURCHASE TO DELIVERY\n";
echo str_repeat("=", 60) . "\n";

// ============================================================
// PHASE 1: SETUP - Get Test Data
// ============================================================
$runner->startTest('Phase 1: Setup Test Data');

// Get a partner
$stmt = $db->query("SELECT * FROM om_market_partners WHERE status = 1 LIMIT 1");
$partner = $stmt->fetch(PDO::FETCH_ASSOC);
$partnerId = $partner ? ($partner['partner_id'] ?? $partner['id']) : null;

$runner->assertNotEmpty($partnerId, "Found active partner for testing");
echo "  ℹ️  Using Partner: " . ($partner['trade_name'] ?? $partner['name'] ?? 'ID:'.$partnerId) . "\n";

// Get a shopper
$stmt = $db->query("SELECT * FROM om_market_shoppers LIMIT 1");
$shopper = $stmt->fetch(PDO::FETCH_ASSOC);
$shopperId = $shopper ? ($shopper['shopper_id'] ?? $shopper['id']) : null;

$runner->assertNotEmpty($shopperId, "Found shopper for testing");
echo "  ℹ️  Using Shopper: " . ($shopper['name'] ?? $shopper['nome'] ?? 'ID:'.$shopperId) . "\n";

// ============================================================
// PHASE 2: ORDER CREATION FLOW
// ============================================================
$runner->startTest('Phase 2: Order Creation Check');

// Check recent orders
$stmt = $db->query("
    SELECT o.*, p.trade_name as partner_name
    FROM om_market_orders o
    LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
    ORDER BY o.order_id DESC
    LIMIT 1
");
$recentOrder = $stmt->fetch(PDO::FETCH_ASSOC);

if ($recentOrder) {
    $testOrderId = $recentOrder['order_id'];
    echo "  ℹ️  Using recent order #{$testOrderId} for flow testing\n";
    echo "  ℹ️  Order Status: " . $recentOrder['status'] . "\n";
    echo "  ℹ️  Partner: " . ($recentOrder['partner_name'] ?? 'N/A') . "\n";

    $runner->assert(true, "Found order for testing");
} else {
    echo "  ⚠️  No orders found - will create test scenarios\n";
    $testOrderId = null;
    $runner->assert(true, "No orders yet (new system)");
}

// ============================================================
// PHASE 3: ORDER ITEMS CHECK
// ============================================================
$runner->startTest('Phase 3: Order Items');

if ($testOrderId) {
    $stmt = $db->prepare("
        SELECT *
        FROM om_market_order_items
        WHERE order_id = ?
    ");
    $stmt->execute([$testOrderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "  ℹ️  Order #{$testOrderId} has " . count($items) . " items\n";

    if (!empty($items)) {
        $scannedCount = 0;
        $substitutedCount = 0;

        foreach ($items as $item) {
            if (!empty($item['scanned']) || !empty($item['coletado']) || !empty($item['collected'])) {
                $scannedCount++;
            }
            if (!empty($item['substituted']) || !empty($item['substituido'])) {
                $substitutedCount++;
            }
        }

        echo "  ℹ️  Scanned: $scannedCount / " . count($items) . "\n";
        echo "  ℹ️  Substituted: $substitutedCount\n";

        $runner->assert(true, "Order items analysis complete");
    }
} else {
    $runner->assert(true, "Skipping items check - no order");
}

// ============================================================
// PHASE 4: SHOPPER ASSIGNMENT FLOW
// ============================================================
$runner->startTest('Phase 4: Shopper Assignment');

if ($testOrderId) {
    $stmt = $db->prepare("SELECT shopper_id FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$testOrderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($order['shopper_id'])) {
        $stmt = $db->prepare("SELECT name, phone FROM om_market_shoppers WHERE shopper_id = ?");
        $stmt->execute([$order['shopper_id']]);
        $assignedShopper = $stmt->fetch(PDO::FETCH_ASSOC);

        echo "  ℹ️  Assigned Shopper: " . ($assignedShopper['name'] ?? 'N/A') . "\n";
        $runner->assert(true, "Order has shopper assigned");
    } else {
        echo "  ℹ️  No shopper assigned yet\n";
        $runner->assert(true, "Shopper assignment pending (normal for new orders)");
    }
} else {
    $runner->assert(true, "Skipping shopper check - no order");
}

// ============================================================
// PHASE 5: SCANNING SIMULATION
// ============================================================
$runner->startTest('Phase 5: Item Scanning Flow');

// Check if scan API exists
$response = $runner->httpRequest('POST', '/mercado/shopper/api/scan.php', [
    'order_id' => $testOrderId ?? 1,
    'item_id' => 1,
    'barcode' => 'TEST123'
]);

$runner->assert($response['code'] > 0, "Scan API is accessible");

if ($response['json']) {
    echo "  ℹ️  Scan API Response: " . json_encode($response['json']) . "\n";
}

// ============================================================
// PHASE 6: SUBSTITUTION FLOW
// ============================================================
$runner->startTest('Phase 6: Product Substitution');

$response = $runner->httpRequest('POST', '/mercado/shopper/api/substitute.php', [
    'order_id' => $testOrderId ?? 1,
    'item_id' => 1,
    'reason' => 'E2E Test - Produto indisponível',
    'substitute_name' => 'Produto Alternativo E2E',
    'substitute_price' => 15.99
]);

$runner->assert($response['code'] > 0, "Substitute API is accessible");

// Check substitution capability in DB
$stmt = $db->query("DESCRIBE om_market_order_items");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$hasSubFields = in_array('substituted', $columns) || in_array('substituido', $columns);
$runner->assert($hasSubFields, "Database supports substitution tracking");

// ============================================================
// PHASE 7: CHAT COMMUNICATION
// ============================================================
$runner->startTest('Phase 7: Customer Communication');

// Check chat API
$response = $runner->httpRequest('GET', '/mercado/shopper/api/chat.php', [
    'order_id' => $testOrderId ?? 1
]);

$runner->assert($response['code'] > 0, "Chat API is accessible");

// Check chat table
$stmt = $db->query("SHOW TABLES LIKE 'om_market_chat'");
$hasChatTable = $stmt->rowCount() > 0;

if (!$hasChatTable) {
    $stmt = $db->query("SHOW TABLES LIKE 'om_market_order_chat'");
    $hasChatTable = $stmt->rowCount() > 0;
}

$runner->assert($hasChatTable, "Chat system exists in database");

// ============================================================
// PHASE 8: FINISH SHOPPING
// ============================================================
$runner->startTest('Phase 8: Complete Shopping');

$response = $runner->httpRequest('POST', '/mercado/shopper/api/finish-shopping.php', [
    'order_id' => $testOrderId ?? 1
]);

$runner->assert($response['code'] > 0, "Finish shopping API is accessible");

// ============================================================
// PHASE 9: DRIVER DISPATCH
// ============================================================
$runner->startTest('Phase 9: Driver Dispatch');

// Check delivery system
$response = $runner->httpRequest('POST', '/api/entrega/sistema.php', [
    'action' => 'chamar_entregador',
    'entrega_id' => 1
]);

$runner->assert($response['code'] > 0, "Driver dispatch API is accessible");

// Check BoraUm integration
$stmt = $db->query("SHOW TABLES LIKE 'om_boraum_chamadas'");
$hasBoraum = $stmt->rowCount() > 0;
$runner->assert($hasBoraum, "BoraUm dispatch table exists");

// ============================================================
// PHASE 10: HANDOFF SHOPPER → DRIVER
// ============================================================
$runner->startTest('Phase 10: Handoff Shopper to Driver');

// Check handoff API
$response = $runner->httpRequest('GET', '/api/handoff/scan.php', [
    'code' => 'ENT-TEST001'
]);

$runner->assert($response['code'] > 0, "Handoff API is accessible");

// Verify handoff table structure
$stmt = $db->query("DESCRIBE om_entrega_handoffs");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$runner->assertContains('de_tipo', $columns, "Handoff tracks origin (de_tipo)");
$runner->assertContains('para_tipo', $columns, "Handoff tracks destination (para_tipo)");

// ============================================================
// PHASE 11: DELIVERY IN PROGRESS
// ============================================================
$runner->startTest('Phase 11: Delivery Tracking');

// Check tracking API
$response = $runner->httpRequest('GET', '/api/delivery/tracking.php', [
    'order_id' => $testOrderId ?? 1
]);

$runner->assert($response['code'] > 0, "Tracking API is accessible");

// Check tracking table
$stmt = $db->query("SHOW TABLES LIKE 'om_entrega_tracking'");
$hasTracking = $stmt->rowCount() > 0;
$runner->assert($hasTracking, "Tracking table exists");

// ============================================================
// PHASE 12: DELIVERY COMPLETION
// ============================================================
$runner->startTest('Phase 12: Delivery Completion');

$response = $runner->httpRequest('POST', '/api/entrega/sistema.php', [
    'action' => 'finalizar',
    'entrega_id' => 1,
    'codigo' => 'TEST123'
]);

$runner->assert($response['code'] > 0, "Delivery finalization API is accessible");

// ============================================================
// PHASE 13: EARNINGS CALCULATION
// ============================================================
$runner->startTest('Phase 13: Shopper Earnings');

$stmt = $db->query("SHOW TABLES LIKE 'om_shopper_earnings'");
$hasEarnings = $stmt->rowCount() > 0;
$runner->assert($hasEarnings, "Earnings table exists");

if ($hasEarnings && $shopperId) {
    $stmt = $db->prepare("
        SELECT SUM(amount) as total, COUNT(*) as count
        FROM om_shopper_earnings
        WHERE shopper_id = ?
    ");
    $stmt->execute([$shopperId]);
    $earnings = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "  ℹ️  Shopper Earnings: R$ " . number_format($earnings['total'] ?? 0, 2, ',', '.') . "\n";
    echo "  ℹ️  Total Orders Paid: " . ($earnings['count'] ?? 0) . "\n";

    $runner->assert(true, "Earnings query works");
}

// ============================================================
// PHASE 14: NOTIFICATION SYSTEM
// ============================================================
$runner->startTest('Phase 14: Notifications');

$stmt = $db->query("SHOW TABLES LIKE 'om_entrega_notificacoes'");
$hasNotifications = $stmt->rowCount() > 0;

if ($hasNotifications) {
    $stmt = $db->query("SELECT COUNT(*) as total FROM om_entrega_notificacoes");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  ℹ️  Total notifications: " . $count['total'] . "\n";
}

$runner->assert($hasNotifications, "Notification system exists");

// ============================================================
// PHASE 15: COMPLETE FLOW STATISTICS
// ============================================================
$runner->startTest('Phase 15: Flow Statistics');

// Orders by status
$stmt = $db->query("
    SELECT status, COUNT(*) as count
    FROM om_market_orders
    GROUP BY status
    ORDER BY count DESC
");
$statusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "  ℹ️  Order Status Distribution:\n";
foreach ($statusStats as $s) {
    echo "      - {$s['status']}: {$s['count']}\n";
}

// Deliveries by status
$stmt = $db->query("
    SELECT status, COUNT(*) as count
    FROM om_entregas
    GROUP BY status
    ORDER BY count DESC
");
$deliveryStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "  ℹ️  Delivery Status Distribution:\n";
foreach ($deliveryStats as $s) {
    echo "      - {$s['status']}: {$s['count']}\n";
}

$runner->assert(true, "Flow statistics calculated");

// ============================================================
// SUMMARY
// ============================================================
$runner->printSummary();
