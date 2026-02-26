<?php
/**
 * ====================================================================
 * POST /api/mercado/tests/seed.php
 * ====================================================================
 *
 * Test data seed/reset endpoint for automated testing.
 *
 * SECURITY GATES:
 * 1. APP_ENV must be 'staging' or 'test' (rejects 'production')
 * 2. X-Test-Secret header must match TEST_SEED_SECRET from .env
 * 3. POST method only
 *
 * Actions:
 *   seed   — Creates test customer, partner, and products (all is_test=1)
 *   reset  — Deletes ONLY rows with is_test=1
 *   status — Counts existing test records
 *
 * Body: { "action": "seed" | "reset" | "status" }
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// ── Gate 1: Environment check ────────────────────────────────────────────────
$appEnv = strtolower($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production');
if (!in_array($appEnv, ['staging', 'test'], true)) {
    http_response_code(403);
    exit(json_encode([
        'success' => false,
        'message' => 'Seed endpoint disabled in production environment',
    ]));
}

// ── Gate 2: Secret header check ──────────────────────────────────────────────
$expectedSecret = $_ENV['TEST_SEED_SECRET'] ?? getenv('TEST_SEED_SECRET') ?: '';
$providedSecret = $_SERVER['HTTP_X_TEST_SECRET'] ?? '';

if (empty($expectedSecret) || !hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(401);
    exit(json_encode([
        'success' => false,
        'message' => 'Invalid or missing X-Test-Secret header',
    ]));
}

// ── Gate 3: POST only ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'POST only']));
}

// ── Main ─────────────────────────────────────────────────────────────────────
try {
    $db = getDB();
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    // Ensure is_test columns exist
    ensureTestColumns($db);

    switch ($action) {
        case 'seed':
            echo json_encode(seedTestData($db));
            break;
        case 'reset':
            echo json_encode(resetTestData($db));
            break;
        case 'status':
            echo json_encode(getTestStatus($db));
            break;
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action. Use: seed, reset, status',
            ]);
    }
} catch (Exception $e) {
    error_log('[seed] Error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Seed operation failed',
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// FUNCTIONS
// ═════════════════════════════════════════════════════════════════════════════

function ensureTestColumns(PDO $db): void
{
    $tables = [
        'om_customers' => 'is_test',
        'om_market_partners' => 'is_test',
        'om_market_products' => 'is_test',
    ];
    foreach ($tables as $table => $col) {
        try {
            $db->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$col} SMALLINT DEFAULT 0");
        } catch (Exception $e) {
            // Column likely already exists
        }
    }
}

function seedTestData(PDO $db): array
{
    $testEmail = 'test+automation@superbora.test';
    $testPassword = 'TestSenha@2026!';
    $passwordHash = password_hash($testPassword, PASSWORD_ARGON2ID);

    // ── 1. Create test customer ──────────────────────────────────────────────
    $stmt = $db->prepare("SELECT customer_id FROM om_customers WHERE email = ?");
    $stmt->execute([$testEmail]);
    $existingCustomer = $stmt->fetchColumn();

    if ($existingCustomer) {
        $customerId = (int)$existingCustomer;
        $db->prepare("UPDATE om_customers SET password_hash = ?, is_test = 1 WHERE customer_id = ?")
            ->execute([$passwordHash, $customerId]);
    } else {
        $db->prepare("
            INSERT INTO om_customers (name, email, password_hash, phone, is_active, is_test, created_at)
            VALUES (?, ?, ?, ?, 1, 1, NOW())
        ")->execute([
            'Test Automation User',
            $testEmail,
            $passwordHash,
            '11999990000',
        ]);
        $stmt = $db->prepare("SELECT customer_id FROM om_customers WHERE email = ?");
        $stmt->execute([$testEmail]);
        $customerId = (int)$stmt->fetchColumn();
    }

    // ── 2. Create test partner (store) ───────────────────────────────────────
    $testPartnerName = 'Mercado Teste Automatizado';
    $stmt = $db->prepare("SELECT partner_id FROM om_market_partners WHERE name = ? AND is_test = 1");
    $stmt->execute([$testPartnerName]);
    $existingPartner = $stmt->fetchColumn();

    if ($existingPartner) {
        $partnerId = (int)$existingPartner;
    } else {
        $db->prepare("
            INSERT INTO om_market_partners (name, status, categoria, lat, lng, delivery_fee, is_test, created_at)
            VALUES (?, 'active', 'mercado', -23.5505, -46.6333, 5.00, 1, NOW())
        ")->execute([$testPartnerName]);
        $stmt = $db->prepare("SELECT partner_id FROM om_market_partners WHERE name = ? AND is_test = 1");
        $stmt->execute([$testPartnerName]);
        $partnerId = (int)$stmt->fetchColumn();
    }

    // ── 3. Create test products ──────────────────────────────────────────────
    $products = [
        ['Leite Integral Teste 1L', 6.99, 100],
        ['Pao Frances Teste 50g', 0.75, 500],
        ['Arroz Teste 5kg', 24.90, 50],
    ];

    $productIds = [];
    foreach ($products as [$name, $price, $qty]) {
        $stmt = $db->prepare("SELECT id FROM om_market_products WHERE name = ? AND partner_id = ? AND is_test = 1");
        $stmt->execute([$name, $partnerId]);
        $existingProduct = $stmt->fetchColumn();

        if ($existingProduct) {
            $productIds[] = (int)$existingProduct;
            $db->prepare("UPDATE om_market_products SET price = ?, quantity = ?, status = 1 WHERE id = ?")
                ->execute([$price, $qty, $existingProduct]);
        } else {
            $db->prepare("
                INSERT INTO om_market_products (partner_id, name, price, quantity, status, is_test, date_added)
                VALUES (?, ?, ?, ?, 1, 1, NOW())
            ")->execute([$partnerId, $name, $price, $qty]);
            $stmt = $db->prepare("SELECT id FROM om_market_products WHERE name = ? AND partner_id = ? AND is_test = 1");
            $stmt->execute([$name, $partnerId]);
            $pid = (int)$stmt->fetchColumn();
            $productIds[] = $pid;
        }
    }

    return [
        'success' => true,
        'message' => 'Test data seeded successfully',
        'data' => [
            'customer' => [
                'id' => $customerId,
                'email' => $testEmail,
                'password' => $testPassword,
                'name' => 'Test Automation User',
            ],
            'partner' => [
                'id' => $partnerId,
                'name' => $testPartnerName,
            ],
            'products' => array_map(function ($id, $idx) use ($products) {
                return [
                    'id' => $id,
                    'name' => $products[$idx][0],
                    'price' => $products[$idx][1],
                ];
            }, $productIds, array_keys($productIds)),
        ],
    ];
}

function resetTestData(PDO $db): array
{
    $deleted = [];

    // Order matters: delete child rows first, then parents
    $tables = [
        'om_market_order_items' => 'order_id IN (SELECT order_id FROM om_market_orders WHERE customer_id IN (SELECT customer_id FROM om_customers WHERE is_test = 1))',
        'om_market_orders' => 'customer_id IN (SELECT customer_id FROM om_customers WHERE is_test = 1)',
        'om_market_cart' => 'customer_id IN (SELECT customer_id FROM om_customers WHERE is_test = 1)',
        'om_market_products' => 'is_test = 1',
        'om_market_partners' => 'is_test = 1',
        'om_auth_tokens' => "user_id IN (SELECT customer_id FROM om_customers WHERE is_test = 1) AND user_type = 'customer'",
        'om_market_push_tokens' => "user_id IN (SELECT customer_id FROM om_customers WHERE is_test = 1) AND user_type = 'customer'",
        'om_customers' => 'is_test = 1',
    ];

    foreach ($tables as $table => $condition) {
        try {
            $count = $db->exec("DELETE FROM {$table} WHERE {$condition}");
            $deleted[$table] = $count ?: 0;
        } catch (Exception $e) {
            // Table might not exist yet or column missing — skip
            $deleted[$table] = 'skipped';
        }
    }

    return [
        'success' => true,
        'message' => 'Test data reset complete',
        'data' => ['deleted' => $deleted],
    ];
}

function getTestStatus(PDO $db): array
{
    $counts = [];
    $tables = [
        'om_customers' => 'is_test = 1',
        'om_market_partners' => 'is_test = 1',
        'om_market_products' => 'is_test = 1',
    ];

    foreach ($tables as $table => $condition) {
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM {$table} WHERE {$condition}");
            $counts[$table] = (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            $counts[$table] = 0;
        }
    }

    // Check for test orders
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM om_market_orders WHERE customer_id IN (SELECT customer_id FROM om_customers WHERE is_test = 1)");
        $counts['om_market_orders'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $counts['om_market_orders'] = 0;
    }

    return [
        'success' => true,
        'message' => 'Test data status',
        'data' => ['counts' => $counts],
    ];
}
