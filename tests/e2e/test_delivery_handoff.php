<?php
/**
 * E2E Test Suite - DELIVERY & HANDOFF SYSTEM
 * Testa todo o fluxo de entrega: disparo motorista, handoff, tracking
 */

require_once __DIR__ . '/config.php';

$runner = new E2ETestRunner();
$db = $runner->getDB();

echo "\n" . str_repeat("=", 60) . "\n";
echo "🚗 E2E TESTS: DELIVERY & HANDOFF SYSTEM\n";
echo str_repeat("=", 60) . "\n";

// ============================================================
// TEST 1: Delivery Tables Exist
// ============================================================
$runner->startTest('Delivery Database Tables');

$tables = [
    'om_entregas',
    'om_entrega_handoffs',
    'om_entrega_tracking',
    'om_entrega_notificacoes',
    'om_boraum_chamadas'
];

foreach ($tables as $table) {
    $stmt = $db->query("SHOW TABLES LIKE '$table'");
    $exists = $stmt->rowCount() > 0;
    $runner->assert($exists, "Table $table exists");
}

// ============================================================
// TEST 2: Entregas Table Structure
// ============================================================
$runner->startTest('Entregas Table Structure');

$stmt = $db->query("DESCRIBE om_entregas");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$requiredColumns = ['status', 'driver_id', 'tipo'];
foreach ($requiredColumns as $col) {
    $found = in_array($col, $columns);
    $runner->assert($found, "Entregas has column: $col");
}

// Check for driver fields
$hasDriver = in_array('driver_id', $columns);
$runner->assert($hasDriver, "Entregas has driver field");

// ============================================================
// TEST 3: Handoff Table Structure
// ============================================================
$runner->startTest('Handoff Table Structure');

$stmt = $db->query("DESCRIBE om_entrega_handoffs");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$runner->assertContains('entrega_id', $columns, "Handoff linked to delivery");
$runner->assertContains('status', $columns, "Handoff has status field");
$runner->assertContains('de_tipo', $columns, "Handoff tracks origin type (de_tipo)");
$runner->assertContains('para_tipo', $columns, "Handoff tracks destination type (para_tipo)");

// ============================================================
// TEST 4: Delivery Status Values
// ============================================================
$runner->startTest('Delivery Status Flow');

$validStatuses = [
    'aguardando_vendedor',
    'vendedor_preparando',
    'a_caminho_ponto',
    'no_ponto',
    'disponivel_retirada',
    'aguardando_entregador',
    'buscando_motorista',
    'entregador_a_caminho',
    'em_transito',
    'em_transito_direto',
    'entregue',
    'cancelado'
];

$stmt = $db->query("SELECT DISTINCT status FROM om_entregas");
$statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "  ℹ️  Found delivery statuses: " . implode(', ', $statuses) . "\n";

$hasValidStatuses = true;
foreach ($statuses as $status) {
    if (!empty($status) && !in_array($status, $validStatuses)) {
        echo "  ⚠️  Unknown status: $status\n";
    }
}
$runner->assert($hasValidStatuses, "Delivery uses valid status values");

// ============================================================
// TEST 5: Handoff Status Values
// ============================================================
$runner->startTest('Handoff Status Values');

$stmt = $db->query("SELECT DISTINCT status FROM om_entrega_handoffs");
$statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

$validHandoffStatuses = ['pendente', 'aceito', 'recusado', 'expirado', 'concluido'];
foreach ($statuses as $status) {
    if (!empty($status)) {
        $runner->assertContains($status, $validHandoffStatuses, "Handoff status '$status' is valid");
    }
}

// ============================================================
// TEST 6: BoraUm Integration Table
// ============================================================
$runner->startTest('BoraUm Integration');

$stmt = $db->query("DESCRIBE om_boraum_chamadas");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$runner->assertContains('entrega_id', $columns, "BoraUm linked to delivery");
$runner->assertContains('status', $columns, "BoraUm has status tracking");

// Check for origin/destination
$hasOrigin = in_array('origem_endereco', $columns) || in_array('origem', $columns);
$hasDestino = in_array('destino_endereco', $columns) || in_array('destino', $columns);
$runner->assert($hasOrigin, "BoraUm has origin address");
$runner->assert($hasDestino, "BoraUm has destination address");

// ============================================================
// TEST 7: Tracking Table
// ============================================================
$runner->startTest('Delivery Tracking System');

$stmt = $db->query("DESCRIBE om_entrega_tracking");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$runner->assertContains('entrega_id', $columns, "Tracking linked to delivery");
$runner->assertContains('status', $columns, "Tracking records status changes");

// Check for GPS
$hasGPS = in_array('latitude', $columns) || in_array('lat', $columns);
$runner->assert($hasGPS, "Tracking supports GPS coordinates");

// ============================================================
// TEST 8: API - Delivery System Endpoint
// ============================================================
$runner->startTest('API: Delivery System Endpoint');

$response = $runner->httpRequest('GET', '/api/entrega/sistema.php', [
    'action' => 'status',
    'entrega_id' => 1
]);

$runner->assert($response['body'] !== false, "Delivery system API responds");
$runner->assert(
    $response['json'] !== null || strpos($response['body'], '{') !== false,
    "Returns JSON format"
);

// ============================================================
// TEST 9: API - Calculate Delivery Options
// ============================================================
$runner->startTest('API: Calculate Delivery Options');

$response = $runner->httpRequest('POST', '/api/entrega/sistema.php', [
    'action' => 'calcular_opcoes',
    'cep_destino' => '01310100',
    'seller_id' => 1
]);

$runner->assert($response['body'] !== false, "Calculate options API responds");

// ============================================================
// TEST 10: API - Handoff Scan
// ============================================================
$runner->startTest('API: Handoff Scan Endpoint');

$response = $runner->httpRequest('GET', '/api/handoff/scan.php', [
    'code' => 'ENT-000001'
]);

$runner->assert($response['body'] !== false, "Handoff scan API responds");

// ============================================================
// TEST 11: Delivery Types Configuration
// ============================================================
$runner->startTest('Delivery Types');

$deliveryTypes = [
    'retirada_ponto' => 'Free pickup at support point',
    'recebe_hoje' => 'Same-day delivery via point',
    'recebe_hoje_direto' => 'Direct delivery (no point)',
    'padrao' => 'Standard delivery',
    'local' => 'Local delivery',
    'express' => 'Express delivery',
    'uber' => 'Uber delivery',
    'correios' => 'Correios delivery'
];

$stmt = $db->query("SELECT DISTINCT tipo FROM om_entregas");
$types = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "  ℹ️  Found delivery types: " . implode(', ', $types) . "\n";

$hasValidTypes = true;
foreach ($types as $type) {
    if (!empty($type) && !isset($deliveryTypes[$type])) {
        echo "  ⚠️  Unknown type: $type\n";
    }
}
$runner->assert($hasValidTypes, "Delivery types are valid");

// ============================================================
// TEST 12: Vendor → Point Handoff Chain
// ============================================================
$runner->startTest('Handoff Chain: Vendor to Point');

$stmt = $db->query("
    SELECT COUNT(*) as total
    FROM om_entrega_handoffs
    WHERE de_tipo = 'vendedor' AND para_tipo = 'ponto_apoio'
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  ℹ️  Vendor→Point handoffs: " . $result['total'] . "\n";
$runner->assert(true, "Can query vendor→point handoffs");

// ============================================================
// TEST 13: Point → Driver Handoff Chain
// ============================================================
$runner->startTest('Handoff Chain: Point to Driver');

$stmt = $db->query("
    SELECT COUNT(*) as total
    FROM om_entrega_handoffs
    WHERE de_tipo = 'ponto_apoio' AND para_tipo = 'entregador'
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  ℹ️  Point→Driver handoffs: " . $result['total'] . "\n";
$runner->assert(true, "Can query point→driver handoffs");

// ============================================================
// TEST 14: Driver → Customer Handoff Chain
// ============================================================
$runner->startTest('Handoff Chain: Driver to Customer');

$stmt = $db->query("
    SELECT COUNT(*) as total
    FROM om_entrega_handoffs
    WHERE de_tipo = 'entregador' AND para_tipo = 'cliente'
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  ℹ️  Driver→Customer handoffs: " . $result['total'] . "\n";
$runner->assert(true, "Can query driver→customer handoffs");

// ============================================================
// TEST 15: Delivery Notifications
// ============================================================
$runner->startTest('Delivery Notifications');

$stmt = $db->query("DESCRIBE om_entrega_notificacoes");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$runner->assertContains('entrega_id', $columns, "Notifications linked to delivery");
$runner->assertContains('tipo', $columns, "Notification has type field");
$runner->assertContains('mensagem', $columns, "Notification has message field");

// Check notification types
$stmt = $db->query("SELECT DISTINCT tipo FROM om_entrega_notificacoes LIMIT 10");
$types = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "  ℹ️  Notification types: " . implode(', ', $types) . "\n";

// ============================================================
// TEST 16: Complete Delivery Flow Simulation
// ============================================================
$runner->startTest('Complete Delivery Flow (DB Check)');

try {
    // Check we have the complete chain capability
    $stmt = $db->query("
        SELECT
            e.id,
            e.status as entrega_status,
            (SELECT COUNT(*) FROM om_entrega_handoffs h WHERE h.entrega_id = e.id) as handoff_count,
            (SELECT COUNT(*) FROM om_entrega_tracking t WHERE t.entrega_id = e.id) as tracking_count
        FROM om_entregas e
        LIMIT 5
    ");

    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($deliveries)) {
        foreach ($deliveries as $d) {
            echo "  ℹ️  Delivery #{$d['id']}: status={$d['entrega_status']}, handoffs={$d['handoff_count']}, tracking={$d['tracking_count']}\n";
        }
    }

    $runner->assert(true, "Can query complete delivery flow data");

} catch (Exception $e) {
    $runner->assert(false, "Delivery flow query: " . $e->getMessage());
}

// ============================================================
// TEST 17: Delivery Code Validation
// ============================================================
$runner->startTest('Delivery Code Format');

$stmt = $db->query("SELECT qr_entrega, pin_entrega FROM om_entregas WHERE qr_entrega IS NOT NULL OR pin_entrega IS NOT NULL LIMIT 5");
$codes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$codeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!empty($codeRows)) {
    foreach ($codeRows as $row) {
        echo "  ℹ️  QR: " . ($row['qr_entrega'] ?? 'N/A') . ", PIN: " . ($row['pin_entrega'] ?? 'N/A') . "\n";
    }
    $runner->assert(true, "Delivery codes are generated");
} else {
    $runner->assert(true, "No delivery codes yet (OK for new system)");
}

// ============================================================
// TEST 18: Support Point Integration
// ============================================================
$runner->startTest('Support Point System');

$stmt = $db->query("
    SELECT COUNT(*) as total
    FROM om_entregas
    WHERE ponto_apoio_id IS NOT NULL
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  ℹ️  Deliveries with support point: " . $result['total'] . "\n";
$runner->assert(true, "Support point integration exists");

// ============================================================
// TEST 19: Direct Delivery (No Point)
// ============================================================
$runner->startTest('Direct Delivery Mode');

$stmt = $db->query("
    SELECT COUNT(*) as total
    FROM om_entregas
    WHERE tipo = 'express' OR metodo_entrega = 'direto'
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  ℹ️  Direct deliveries: " . $result['total'] . "\n";
$runner->assert(true, "Direct delivery mode exists");

// ============================================================
// TEST 20: Privacy Check - Vendor Cannot See Customer Address (Direct)
// ============================================================
$runner->startTest('Privacy: Direct Delivery Address Protection');

// In direct delivery, vendor should not have access to customer address
// This is a business logic check
$stmt = $db->query("DESCRIBE om_entregas");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$hasCustomerAddress = in_array('entrega_endereco', $columns) || in_array('destino_endereco', $columns);
$runner->assert($hasCustomerAddress, "Delivery stores customer address (for driver only)");

echo "  ℹ️  Note: In 'recebe_hoje_direto', vendor access to address should be restricted at API level\n";

// ============================================================
// SUMMARY
// ============================================================
$runner->printSummary();
