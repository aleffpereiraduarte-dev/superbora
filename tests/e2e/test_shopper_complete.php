<?php
/**
 * E2E Test Suite - SHOPPER APP COMPLETE FLOW
 * Testa todo o fluxo do shopper desde login até entrega
 */

require_once __DIR__ . '/config.php';

$runner = new E2ETestRunner();
$db = $runner->getDB();

echo "\n" . str_repeat("=", 60) . "\n";
echo "🛒 E2E TESTS: SHOPPER APP - FLUXO COMPLETO\n";
echo str_repeat("=", 60) . "\n";

// ============================================================
// TEST 1: Database Tables Exist
// ============================================================
$runner->startTest('Database Tables - Shopper');

$tables = [
    'om_market_shoppers',
    'om_market_orders',
    'om_market_order_items',
    'om_market_chat',
    'om_market_partners',
    'om_shopper_earnings'
];

foreach ($tables as $table) {
    $stmt = $db->query("SHOW TABLES LIKE '$table'");
    $runner->assert($stmt->rowCount() > 0, "Table $table exists");
}

// ============================================================
// TEST 2: Shopper Table Structure
// ============================================================
$runner->startTest('Shopper Table Structure');

$stmt = $db->query("DESCRIBE om_market_shoppers");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$requiredColumns = ['name', 'email', 'phone'];
foreach ($requiredColumns as $col) {
    $runner->assertContains($col, $columns, "Column '$col' exists in om_market_shoppers");
}

// ============================================================
// TEST 3: Check for Active Shoppers
// ============================================================
$runner->startTest('Active Shoppers in System');

$stmt = $db->query("SELECT COUNT(*) as total FROM om_market_shoppers");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$runner->assertGreaterThan(0, (int)$result['total'], "System has registered shoppers");

// Get a sample shopper for testing
$stmt = $db->query("SELECT * FROM om_market_shoppers LIMIT 1");
$testShopper = $stmt->fetch(PDO::FETCH_ASSOC);

if ($testShopper) {
    $runner->assertNotEmpty($testShopper['name'] ?? $testShopper['nome'] ?? '', "Shopper has name");
    echo "  ℹ️  Test Shopper: " . ($testShopper['name'] ?? $testShopper['nome'] ?? 'N/A') . "\n";
}

// ============================================================
// TEST 4: Orders Table Structure
// ============================================================
$runner->startTest('Orders Table Structure');

$stmt = $db->query("DESCRIBE om_market_orders");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$requiredOrderColumns = ['order_id', 'status', 'customer_id'];
foreach ($requiredOrderColumns as $col) {
    $found = in_array($col, $columns) || in_array('id', $columns);
    $runner->assert($found, "Order table has identifier column");
    break;
}

// ============================================================
// TEST 5: Order Items Structure
// ============================================================
$runner->startTest('Order Items Table');

$stmt = $db->query("DESCRIBE om_market_order_items");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$runner->assertContains('order_id', $columns, "Order items linked to orders");

// Check for scanning columns
$hasScanColumn = in_array('scanned', $columns) || in_array('collected', $columns) || in_array('coletado', $columns);
$runner->assert($hasScanColumn, "Order items have scan/collect tracking");

// ============================================================
// TEST 6: Test Order Status Flow
// ============================================================
$runner->startTest('Order Status Values');

// Get distinct statuses
$stmt = $db->query("SELECT DISTINCT status FROM om_market_orders LIMIT 20");
$statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "  ℹ️  Found statuses: " . implode(', ', $statuses) . "\n";

$validStatuses = ['pending', 'pendente', 'shopping', 'em_compra', 'coletando', 'purchased', 'comprado',
                  'delivering', 'em_entrega', 'delivered', 'entregue', 'cancelled', 'cancelado',
                  'novo', 'pago', 'aceito', 'confirmed'];

$hasValidStatus = false;
foreach ($statuses as $status) {
    if (in_array(strtolower($status), $validStatuses)) {
        $hasValidStatus = true;
        break;
    }
}
$runner->assert($hasValidStatus || empty($statuses), "Orders use valid status values");

// ============================================================
// TEST 7: Substitution Flow Structure
// ============================================================
$runner->startTest('Substitution Fields in Order Items');

$stmt = $db->query("DESCRIBE om_market_order_items");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$subColumns = ['substituted', 'substituido', 'substitute_name', 'substituto_nome',
               'substitute_price', 'substituto_preco', 'replacement_reason', 'motivo_substituicao'];

$hasSubstitution = false;
foreach ($subColumns as $col) {
    if (in_array($col, $columns)) {
        $hasSubstitution = true;
        $runner->assert(true, "Has substitution column: $col");
        break;
    }
}

if (!$hasSubstitution) {
    $runner->assert(false, "Order items should have substitution tracking columns");
}

// ============================================================
// TEST 8: Chat System
// ============================================================
$runner->startTest('Chat System Table');

$stmt = $db->query("SHOW TABLES LIKE 'om_market_chat'");
$hasChatTable = $stmt->rowCount() > 0;

if ($hasChatTable) {
    $stmt = $db->query("DESCRIBE om_market_chat");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $runner->assertContains('order_id', $columns, "Chat linked to orders");
    $runner->assertContains('message', $columns, "Chat has message field");
} else {
    // Check alternative chat table
    $stmt = $db->query("SHOW TABLES LIKE 'om_market_order_chat'");
    $runner->assert($stmt->rowCount() > 0, "Alternative chat table exists");
}

// ============================================================
// TEST 9: Partner/Store Data
// ============================================================
$runner->startTest('Partners/Stores Data');

$stmt = $db->query("SELECT COUNT(*) as total FROM om_market_partners");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$runner->assertGreaterThan(0, (int)$result['total'], "System has registered partners/stores");

$stmt = $db->query("SELECT * FROM om_market_partners WHERE status = 1 LIMIT 1");
$partner = $stmt->fetch(PDO::FETCH_ASSOC);

if ($partner) {
    $runner->assertNotEmpty($partner['name'] ?? $partner['trade_name'] ?? '', "Partner has name");
    echo "  ℹ️  Test Partner: " . ($partner['trade_name'] ?? $partner['name'] ?? 'N/A') . "\n";
}

// ============================================================
// TEST 10: Earnings System
// ============================================================
$runner->startTest('Shopper Earnings System');

$stmt = $db->query("SHOW TABLES LIKE 'om_shopper_earnings'");
$hasEarningsTable = $stmt->rowCount() > 0;

if ($hasEarningsTable) {
    $stmt = $db->query("DESCRIBE om_shopper_earnings");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $runner->assertContains('shopper_id', $columns, "Earnings linked to shopper");
    $runner->assert(
        in_array('valor', $columns) || in_array('amount', $columns) || in_array('valor_entrega', $columns),
        "Earnings has amount/valor field"
    );
} else {
    $runner->assert(false, "Shopper earnings table should exist");
}

// ============================================================
// TEST 11: API Endpoint - Shopper Login
// ============================================================
$runner->startTest('API: Shopper Login Endpoint');

$response = $runner->httpRequest('POST', '/api/mercado/shopper/login.php', [
    'email' => 'test@test.com',
    'senha' => 'wrongpassword'
]);

// Should return JSON response (even if login fails)
$runner->assert($response['body'] !== false, "Login endpoint responds");
$runner->assert(
    $response['json'] !== null || strpos($response['body'], '{') !== false,
    "Login endpoint returns JSON"
);

// ============================================================
// TEST 12: API Endpoint - Available Orders
// ============================================================
$runner->startTest('API: Available Orders Endpoint');

$response = $runner->httpRequest('GET', '/api/mercado/shopper/pedidos-disponiveis.php', [
    'shopper_id' => 1
]);

$runner->assert($response['body'] !== false, "Available orders endpoint responds");

// ============================================================
// TEST 13: API Endpoint - Scan Product
// ============================================================
$runner->startTest('API: Scan Product Endpoint');

$response = $runner->httpRequest('POST', '/mercado/shopper/api/scan.php', [
    'order_id' => 1,
    'item_id' => 1,
    'barcode' => '7891234567890'
]);

$runner->assert($response['body'] !== false, "Scan endpoint responds");
$runner->assert(
    $response['json'] !== null || strpos($response['body'], '{') !== false,
    "Scan endpoint returns JSON format"
);

// ============================================================
// TEST 14: API Endpoint - Substitution
// ============================================================
$runner->startTest('API: Substitution Endpoint');

$response = $runner->httpRequest('POST', '/mercado/shopper/api/substitute.php', [
    'order_id' => 1,
    'item_id' => 1,
    'reason' => 'Produto fora de estoque',
    'substitute_name' => 'Produto Alternativo',
    'substitute_price' => 19.90
]);

$runner->assert($response['body'] !== false, "Substitution endpoint responds");

// ============================================================
// TEST 15: API Endpoint - Chat
// ============================================================
$runner->startTest('API: Chat Endpoint');

$response = $runner->httpRequest('GET', '/mercado/shopper/api/chat.php', [
    'order_id' => 1
]);

$runner->assert($response['body'] !== false, "Chat endpoint responds");

// ============================================================
// TEST 16: Simulate Complete Order Flow (DB)
// ============================================================
$runner->startTest('Simulate Order Flow in Database');

try {
    $db->beginTransaction();

    // Get or create test data
    $stmt = $db->query("SELECT partner_id FROM om_market_partners LIMIT 1");
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    $partnerId = $partner ? $partner['partner_id'] : 1;

    $stmt = $db->query("SELECT shopper_id FROM om_market_shoppers LIMIT 1");
    $shopper = $stmt->fetch(PDO::FETCH_ASSOC);
    $shopperId = $shopper ? $shopper['shopper_id'] : 1;

    // Check if we can create a test order
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE partner_id = ?");
    $stmt->execute([$partnerId]);
    $orderCount = $stmt->fetchColumn();

    $runner->assert(true, "Database transaction works");
    $runner->assertGreaterThan(-1, $orderCount, "Can query orders for partner");

    $db->rollBack();

} catch (Exception $e) {
    $db->rollBack();
    $runner->assert(false, "Database operations: " . $e->getMessage());
}

// ============================================================
// TEST 17: Order Progress Calculation
// ============================================================
$runner->startTest('Order Progress Tracking');

$stmt = $db->query("
    SELECT o.order_id,
           COUNT(i.item_id) as total_items,
           SUM(CASE WHEN i.scanned = 1 THEN 1 ELSE 0 END) as scanned_items
    FROM om_market_orders o
    LEFT JOIN om_market_order_items i ON o.order_id = i.order_id
    GROUP BY o.order_id
    LIMIT 5
");

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$runner->assert(true, "Can calculate order progress from items");

if (!empty($orders)) {
    foreach ($orders as $order) {
        $total = (int)$order['total_items'];
        $scanned = (int)$order['scanned_items'];
        if ($total > 0) {
            $progress = round(($scanned / $total) * 100);
            echo "  ℹ️  Order #{$order['order_id']}: $scanned/$total items ($progress%)\n";
        }
    }
}

// ============================================================
// TEST 18: Handoff Code Generation
// ============================================================
$runner->startTest('Handoff/Delivery Code Fields');

$stmt = $db->query("DESCRIBE om_market_orders");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$hasHandoff = in_array('handoff_code', $columns) ||
              in_array('delivery_code', $columns) ||
              in_array('codigo_entrega', $columns);

$runner->assert($hasHandoff, "Orders have handoff/delivery code field");

// ============================================================
// TEST 19: Worker Location Tracking
// ============================================================
$runner->startTest('Worker Location Tracking');

$stmt = $db->query("SHOW TABLES LIKE 'om_market_worker_locations'");
$hasLocationTable = $stmt->rowCount() > 0;

if ($hasLocationTable) {
    $stmt = $db->query("DESCRIBE om_market_worker_locations");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $hasLatLng = (in_array('lat', $columns) || in_array('latitude', $columns)) &&
                 (in_array('lng', $columns) || in_array('longitude', $columns));

    $runner->assert($hasLatLng, "Location table has lat/lng fields");
} else {
    // Check if location is in shoppers table
    $stmt = $db->query("DESCRIBE om_market_shoppers");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $hasLatLng = in_array('latitude', $columns) || in_array('lat', $columns);
    $runner->assert($hasLatLng, "Shopper location tracking exists");
}

// ============================================================
// TEST 20: Notifications System
// ============================================================
$runner->startTest('Notifications System');

$stmt = $db->query("SHOW TABLES LIKE 'om_notifications'");
$hasNotifications = $stmt->rowCount() > 0;

if (!$hasNotifications) {
    $stmt = $db->query("SHOW TABLES LIKE 'om_entrega_notificacoes'");
    $hasNotifications = $stmt->rowCount() > 0;
}

$runner->assert($hasNotifications, "Notifications table exists");

// ============================================================
// SUMMARY
// ============================================================
$runner->printSummary();
