<?php
/**
 * CRON: CLV recalculation
 * Run daily at 4 AM: 0 4 * * * php /var/www/html/api/mercado/cron/clv-recalculate.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/customer-clv.php';

$lockFile = '/tmp/superbora_clv_recalc.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another instance running. Exiting.\n";
    exit(0);
}

$db = getDB();
$log = function(string $msg) { echo "[" . date('H:i:s') . "] $msg\n"; };

$log("=== CLV recalculation started ===");

// Find customers with orders in the last 120 days
$stmt = $db->query("
    SELECT DISTINCT customer_id
    FROM om_market_orders
    WHERE date_added >= NOW() - INTERVAL '120 days'
      AND customer_id IS NOT NULL
    ORDER BY customer_id
");
$customerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
$total = count($customerIds);
$log("Found $total customers to recalculate");

$success = 0;
$errors = 0;
$batches = array_chunk($customerIds, 100);

foreach ($batches as $batch) {
    foreach ($batch as $customerId) {
        try {
            calculateCustomerCLV($db, (int)$customerId);
            $success++;
        } catch (Throwable $e) {
            $errors++;
            $log("Error for customer $customerId: " . $e->getMessage());
        }
    }
}

$log("Recalculated: $success success, $errors errors out of $total customers");
$log("=== Done ===");

flock($lockFp, LOCK_UN);
fclose($lockFp);
