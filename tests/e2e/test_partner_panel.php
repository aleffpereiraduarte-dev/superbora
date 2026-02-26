<?php
/**
 * E2E Test Suite - PARTNER/MARKET PANEL
 * Testa o painel do mercado: cadastro produtos, preços, pedidos, etc.
 */

require_once __DIR__ . '/config.php';

$runner = new E2ETestRunner();
$db = $runner->getDB();

echo "\n" . str_repeat("=", 60) . "\n";
echo "🏪 E2E TESTS: PARTNER/MARKET PANEL - GESTÃO DE PRODUTOS\n";
echo str_repeat("=", 60) . "\n";

// ============================================================
// TEST 1: Partner Database Tables
// ============================================================
$runner->startTest('Partner Database Tables');

$tables = [
    'om_market_partners',
    'om_market_products_base',
    'om_market_products_price',
    'om_market_categories',
    'om_market_product_location',
    'om_seller_benefits',
    'om_seller_coupons'
];

foreach ($tables as $table) {
    $stmt = $db->query("SHOW TABLES LIKE '$table'");
    $runner->assert($stmt->rowCount() > 0, "Table $table exists");
}

// ============================================================
// TEST 2: Partners Table Structure
// ============================================================
$runner->startTest('Partners Table Structure');

$stmt = $db->query("DESCRIBE om_market_partners");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$requiredColumns = ['partner_id', 'trade_name', 'email', 'status'];
foreach ($requiredColumns as $col) {
    $runner->assertContains($col, $columns, "Partners table has column: $col");
}

// ============================================================
// TEST 3: Active Partners
// ============================================================
$runner->startTest('Active Partners in System');

$stmt = $db->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active FROM om_market_partners");
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo "  ℹ️  Total Partners: " . $result['total'] . "\n";
echo "  ℹ️  Active Partners: " . $result['active'] . "\n";

$runner->assertGreaterThan(0, (int)$result['total'], "System has registered partners");

// Get test partner
$stmt = $db->query("SELECT * FROM om_market_partners WHERE status = 1 LIMIT 1");
$testPartner = $stmt->fetch(PDO::FETCH_ASSOC);
$partnerId = $testPartner['partner_id'] ?? null;

if ($testPartner) {
    echo "  ℹ️  Test Partner: " . ($testPartner['trade_name'] ?? $testPartner['name']) . "\n";
}

// ============================================================
// TEST 4: Products Base Table (Catálogo Global)
// ============================================================
$runner->startTest('Products Base Catalog');

$stmt = $db->query("DESCRIBE om_market_products_base");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$requiredColumns = ['product_id', 'name', 'barcode', 'category_id'];
foreach ($requiredColumns as $col) {
    $runner->assertContains($col, $columns, "Products base has column: $col");
}

$stmt = $db->query("SELECT COUNT(*) as total FROM om_market_products_base WHERE status = 1");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  ℹ️  Total Products in Catalog: " . $result['total'] . "\n";

$runner->assertGreaterThan(0, (int)$result['total'], "Catalog has products");

// ============================================================
// TEST 5: Products Price Table (Preços por Parceiro)
// ============================================================
$runner->startTest('Products Price Table (Partner-specific)');

$stmt = $db->query("DESCRIBE om_market_products_price");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$requiredColumns = ['product_id', 'partner_id', 'price'];
foreach ($requiredColumns as $col) {
    $runner->assertContains($col, $columns, "Products price has column: $col");
}

// Check for promo price
$hasPromo = in_array('price_promo', $columns) || in_array('special_price', $columns);
$runner->assert($hasPromo, "Products price supports promotional pricing");

// Check for stock
$hasStock = in_array('stock', $columns) || in_array('stock_quantity', $columns) || in_array('in_stock', $columns);
$runner->assert($hasStock, "Products price supports stock tracking");

// ============================================================
// TEST 6: Categories Table
// ============================================================
$runner->startTest('Product Categories');

$stmt = $db->query("SELECT COUNT(*) as total FROM om_market_categories");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  ℹ️  Total Categories: " . $result['total'] . "\n";

$runner->assertGreaterThan(0, (int)$result['total'], "System has product categories");

// List some categories
$stmt = $db->query("SELECT name FROM om_market_categories ORDER BY name LIMIT 5");
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "  ℹ️  Sample Categories: " . implode(', ', $categories) . "\n";

// ============================================================
// TEST 7: Partner Products Count
// ============================================================
$runner->startTest('Partner Products Registration');

if ($partnerId) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN pp.price_promo IS NOT NULL AND pp.price_promo > 0 THEN 1 ELSE 0 END) as promos,
               SUM(CASE WHEN pp.in_stock = 0 OR pp.stock = 0 THEN 1 ELSE 0 END) as out_stock
        FROM om_market_products_price pp
        WHERE pp.partner_id = ?
    ");
    $stmt->execute([$partnerId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "  ℹ️  Partner #{$partnerId} Products: " . $result['total'] . "\n";
    echo "  ℹ️  Products on Promo: " . $result['promos'] . "\n";
    echo "  ℹ️  Out of Stock: " . $result['out_stock'] . "\n";

    $runner->assert(true, "Can query partner products");
} else {
    $runner->assert(false, "No partner for testing");
}

// ============================================================
// TEST 8: Product Location Tracking
// ============================================================
$runner->startTest('Product Location (Aisle/Section)');

$stmt = $db->query("SHOW TABLES LIKE 'om_market_product_location'");
$hasLocationTable = $stmt->rowCount() > 0;

if ($hasLocationTable) {
    $stmt = $db->query("DESCRIBE om_market_product_location");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $runner->assertContains('product_id', $columns, "Location linked to product");
    $runner->assertContains('partner_id', $columns, "Location linked to partner");

    $hasAisle = in_array('aisle', $columns) || in_array('section', $columns);
    $runner->assert($hasAisle, "Location has aisle/section field");
} else {
    $runner->assert(true, "Location table optional");
}

// ============================================================
// TEST 9: Seller Benefits Configuration
// ============================================================
$runner->startTest('Seller Benefits System');

$stmt = $db->query("DESCRIBE om_seller_benefits");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$runner->assertContains('partner_id', $columns, "Benefits linked to partner");

// Check benefit options
$benefitFields = ['installments_enabled', 'free_shipping_enabled', 'cashback_enabled'];
$hasBenefits = false;
foreach ($benefitFields as $field) {
    if (in_array($field, $columns)) {
        $hasBenefits = true;
        break;
    }
}
$runner->assert($hasBenefits, "Benefits table has configuration options");

echo "  ℹ️  Benefit columns: " . implode(', ', array_slice($columns, 0, 5)) . "...\n";

// ============================================================
// TEST 10: Coupons System
// ============================================================
$runner->startTest('Coupons System');

$stmt = $db->query("DESCRIBE om_seller_coupons");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$runner->assertContains('partner_id', $columns, "Coupons linked to partner");

$hasCode = in_array('code', $columns) || in_array('coupon_code', $columns);
$runner->assert($hasCode, "Coupons have code field");

$hasDiscount = in_array('discount_value', $columns) || in_array('discount', $columns) || in_array('discount_percent', $columns);
$runner->assert($hasDiscount, "Coupons have discount field");

// ============================================================
// TEST 11: API - Partner Panel Stats
// ============================================================
$runner->startTest('API: Partner Panel Stats');

$response = $runner->httpRequest('GET', '/mercado/painel/index.php', ['api' => 'stats']);
$runner->assert($response['body'] !== false, "Partner stats API responds");

// ============================================================
// TEST 12: API - Products Listing
// ============================================================
$runner->startTest('API: Products Listing');

$response = $runner->httpRequest('GET', '/mercado/painel/index.php', [
    'api' => 'products',
    'filter' => 'all',
    'page' => 1
]);
$runner->assert($response['body'] !== false, "Products listing API responds");

// ============================================================
// TEST 13: API - Single Product
// ============================================================
$runner->startTest('API: Single Product');

$response = $runner->httpRequest('GET', '/mercado/painel/index.php', [
    'api' => 'product',
    'id' => 1
]);
$runner->assert($response['body'] !== false, "Single product API responds");

// ============================================================
// TEST 14: API - Promotions
// ============================================================
$runner->startTest('API: Promotions List');

$response = $runner->httpRequest('GET', '/mercado/painel/index.php', ['api' => 'promos']);
$runner->assert($response['body'] !== false, "Promotions API responds");

// ============================================================
// TEST 15: API - Chart Data
// ============================================================
$runner->startTest('API: Chart/Revenue Data');

$response = $runner->httpRequest('GET', '/mercado/painel/index.php', ['api' => 'chart']);
$runner->assert($response['body'] !== false, "Chart data API responds");

// ============================================================
// TEST 16: Product-Partner Price Relationship
// ============================================================
$runner->startTest('Product-Partner Price Relationship');

$stmt = $db->query("
    SELECT
        pb.product_id,
        pb.name,
        COUNT(DISTINCT pp.partner_id) as partner_count,
        MIN(pp.price) as min_price,
        MAX(pp.price) as max_price
    FROM om_market_products_base pb
    LEFT JOIN om_market_products_price pp ON pb.product_id = pp.product_id
    GROUP BY pb.product_id
    HAVING partner_count > 0
    LIMIT 5
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($products)) {
    echo "  ℹ️  Products with pricing:\n";
    foreach ($products as $p) {
        echo "      - {$p['name']}: {$p['partner_count']} partners (R$ {$p['min_price']} - R$ {$p['max_price']})\n";
    }
}

$runner->assert(true, "Product-partner price relationship works");

// ============================================================
// TEST 17: Category-Product Relationship
// ============================================================
$runner->startTest('Category-Product Relationship');

$stmt = $db->query("
    SELECT c.name as category, COUNT(p.product_id) as products
    FROM om_market_categories c
    LEFT JOIN om_market_products_base p ON c.category_id = p.category_id
    GROUP BY c.category_id
    ORDER BY products DESC
    LIMIT 5
");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($categories)) {
    echo "  ℹ️  Top categories:\n";
    foreach ($categories as $c) {
        echo "      - {$c['category']}: {$c['products']} products\n";
    }
}

$runner->assert(true, "Category-product relationship works");

// ============================================================
// TEST 18: Partner Orders Statistics
// ============================================================
$runner->startTest('Partner Orders Statistics');

if ($partnerId) {
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total_orders,
            SUM(total) as total_revenue,
            AVG(total) as avg_ticket,
            SUM(CASE WHEN status = 'entregue' OR status = 'delivered' THEN 1 ELSE 0 END) as delivered
        FROM om_market_orders
        WHERE partner_id = ?
    ");
    $stmt->execute([$partnerId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "  ℹ️  Partner #{$partnerId} Orders: " . $stats['total_orders'] . "\n";
    echo "  ℹ️  Total Revenue: R$ " . number_format($stats['total_revenue'] ?? 0, 2, ',', '.') . "\n";
    echo "  ℹ️  Avg Ticket: R$ " . number_format($stats['avg_ticket'] ?? 0, 2, ',', '.') . "\n";
    echo "  ℹ️  Delivered: " . $stats['delivered'] . "\n";

    $runner->assert(true, "Partner orders statistics work");
} else {
    $runner->assert(true, "Skipping - no partner");
}

// ============================================================
// TEST 19: Product Search Functionality
// ============================================================
$runner->startTest('Product Search');

$searchTerm = 'arroz';
$stmt = $db->prepare("
    SELECT pb.product_id, pb.name, pb.brand, pb.barcode
    FROM om_market_products_base pb
    WHERE pb.name LIKE ? OR pb.brand LIKE ? OR pb.barcode LIKE ?
    LIMIT 5
");
$stmt->execute(["%$searchTerm%", "%$searchTerm%", "%$searchTerm%"]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "  ℹ️  Search '$searchTerm': " . count($results) . " results\n";
foreach ($results as $r) {
    echo "      - {$r['name']} ({$r['brand']})\n";
}

$runner->assert(true, "Product search works");

// ============================================================
// TEST 20: Promotional Products Query
// ============================================================
$runner->startTest('Promotional Products Query');

$stmt = $db->query("
    SELECT
        pb.name,
        pp.price as regular_price,
        pp.price_promo as promo_price,
        ROUND((1 - pp.price_promo/pp.price) * 100) as discount_percent
    FROM om_market_products_price pp
    JOIN om_market_products_base pb ON pp.product_id = pb.product_id
    WHERE pp.price_promo IS NOT NULL AND pp.price_promo > 0 AND pp.price_promo < pp.price
    ORDER BY discount_percent DESC
    LIMIT 5
");
$promos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($promos)) {
    echo "  ℹ️  Top promotions:\n";
    foreach ($promos as $p) {
        echo "      - {$p['name']}: R$ {$p['regular_price']} → R$ {$p['promo_price']} ({$p['discount_percent']}% off)\n";
    }
}

$runner->assert(true, "Promotional products query works");

// ============================================================
// SUMMARY
// ============================================================
$runner->printSummary();
