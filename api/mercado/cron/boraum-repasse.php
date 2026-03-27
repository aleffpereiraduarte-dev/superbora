#!/usr/bin/env php
<?php
/**
 * CRON: BoraUm Weekly Billing Report
 *
 * Runs every Monday at 9 AM:
 *   0 9 * * 1 php /var/www/html/api/mercado/cron/boraum-repasse.php
 *
 * OR via HTTP with cron secret:
 *   curl "https://superbora.com.br/api/mercado/cron/boraum-repasse.php?key=SECRET"
 *
 * What it does:
 * 1. Fetches billing summary from BoraUm API for the previous week (Mon-Sun)
 * 2. Logs the result to om_boraum_billing table
 * 3. Logs summary for admin review
 * 4. Does NOT auto-pay (manual PIX for now)
 */

$isCli = php_sapi_name() === 'cli';

// Load environment
$envPath = __DIR__ . '/../../../.env';
if (file_exists($envPath)) {
    $envFile = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Auth: CLI runs without secret check, HTTP requires cron secret
if (!$isCli) {
    $cronSecret = $_ENV['CRON_SECRET'] ?? '';
    $providedSecret = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
    if (!$cronSecret || !hash_equals($cronSecret, $providedSecret)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: application/json; charset=utf-8');
}

// ─── Concurrent execution guard ─────────────────────────────
$lockFile = '/tmp/superbora_boraum_repasse.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    $msg = "Another instance is running. Exiting.";
    if ($isCli) { echo "[" . date('c') . "] SKIP: $msg\n"; }
    else { echo json_encode(['success' => false, 'message' => $msg]); }
    exit(0);
}

$startTime = microtime(true);
$log = function(string $msg) use ($isCli) {
    $line = "[" . date('c') . "] $msg";
    if ($isCli) echo "$line\n";
    error_log("[boraum-repasse] $msg");
};

$log("=== BoraUm Weekly Billing Report started ===");

try {
    // ─── Database connection ─────────────────────────────────
    $dbHost = $_ENV["DB_HOSTNAME"] ?? getenv("DB_HOSTNAME") ?: "localhost";
    $dbPort = $_ENV["DB_PORT"] ?? getenv("DB_PORT") ?: "5432";
    $dbName = $_ENV["DB_NAME"] ?? getenv("DB_NAME") ?: "love1";
    $dbUser = $_ENV["DB_USERNAME"] ?? getenv("DB_USERNAME") ?: "";
    $dbPass = $_ENV["DB_PASSWORD"] ?? getenv("DB_PASSWORD") ?: "";

    $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
    $db = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    $db->exec("SET client_encoding TO 'UTF8'");

    // ─── Ensure om_boraum_billing table exists ───────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_boraum_billing (
            id SERIAL PRIMARY KEY,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            total_deliveries INT NOT NULL DEFAULT 0,
            completed_deliveries INT NOT NULL DEFAULT 0,
            cancelled_deliveries INT NOT NULL DEFAULT 0,
            total_boraum_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_superbora_margin DECIMAL(12,2) NOT NULL DEFAULT 0,
            net_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            raw_api_response JSONB,
            local_summary JSONB,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            paid_at TIMESTAMP,
            notes TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_boraum_billing_period ON om_boraum_billing(period_start, period_end)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_boraum_billing_status ON om_boraum_billing(status)");

    // ─── Calculate previous week (Monday to Sunday) ──────────
    // If today is Monday, the previous week is last Monday to last Sunday
    $today = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));

    // Go back to the most recent Monday before today (or today if Monday)
    $thisMondayOrPast = clone $today;
    if ((int)$thisMondayOrPast->format('N') !== 1) {
        $thisMondayOrPast->modify('last Monday');
    }

    // Previous week: the Monday before that, to Sunday
    $periodStart = clone $thisMondayOrPast;
    $periodStart->modify('-7 days');
    $periodEnd = clone $periodStart;
    $periodEnd->modify('+6 days');

    $periodStartStr = $periodStart->format('Y-m-d');
    $periodEndStr = $periodEnd->format('Y-m-d');

    $log("Period: $periodStartStr to $periodEndStr");

    // ─── Check for duplicate billing ─────────────────────────
    $stmtDup = $db->prepare("SELECT id FROM om_boraum_billing WHERE period_start = ? AND period_end = ? LIMIT 1");
    $stmtDup->execute([$periodStartStr, $periodEndStr]);
    if ($stmtDup->fetch()) {
        $log("Billing report already exists for $periodStartStr to $periodEndStr — skipping");
        if (!$isCli) {
            echo json_encode(['success' => true, 'message' => 'Already exists', 'period_start' => $periodStartStr, 'period_end' => $periodEndStr]);
        }
        exit(0);
    }

    // ─── Fetch billing from BoraUm API ──────────────────────
    $boraumApiUrl = $_ENV['BORAUM_API_URL'] ?? 'https://boraum.com.br/api';
    $boraumApiKey = $_ENV['BORAUM_API_KEY'] ?? '';
    $boraumPartnerId = $_ENV['BORAUM_PARTNER_ID'] ?? '';

    $apiResponse = null;
    $apiError = null;

    if ($boraumApiKey) {
        $url = rtrim($boraumApiUrl, '/') . "/partner/billing/summary?period_start={$periodStartStr}&period_end={$periodEndStr}";
        $log("Calling BoraUm API: $url");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $boraumApiKey,
                'Accept: application/json',
                'X-Partner-ID: ' . $boraumPartnerId,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $apiError = "cURL error: $curlError";
            $log("ERROR: $apiError");
        } elseif ($httpCode !== 200) {
            $apiError = "HTTP $httpCode: $result";
            $log("ERROR: BoraUm API returned HTTP $httpCode");
        } else {
            $apiResponse = json_decode($result, true);
            if (!$apiResponse) {
                $apiError = "Invalid JSON response";
                $log("ERROR: Invalid JSON from BoraUm API");
            } else {
                $log("BoraUm API response received successfully");
            }
        }
    } else {
        $log("WARNING: BORAUM_API_KEY not configured — using local data only");
    }

    // ─── Calculate local summary from our own data ──────────
    $log("Calculating local delivery summary from om_entregas...");

    // Total deliveries dispatched to BoraUm in this period
    $stmtTotal = $db->prepare("
        SELECT COUNT(*) FROM om_entregas
        WHERE boraum_delivery_id IS NOT NULL
          AND created_at >= ?
          AND created_at < (? ::date + interval '1 day')
    ");
    $stmtTotal->execute([$periodStartStr, $periodEndStr]);
    $totalDeliveries = (int)$stmtTotal->fetchColumn();

    // Completed deliveries
    $stmtCompleted = $db->prepare("
        SELECT COUNT(*) FROM om_entregas
        WHERE boraum_delivery_id IS NOT NULL
          AND boraum_status = 'delivered'
          AND created_at >= ?
          AND created_at < (? ::date + interval '1 day')
    ");
    $stmtCompleted->execute([$periodStartStr, $periodEndStr]);
    $completedDeliveries = (int)$stmtCompleted->fetchColumn();

    // Cancelled deliveries
    $stmtCancelled = $db->prepare("
        SELECT COUNT(*) FROM om_entregas
        WHERE boraum_delivery_id IS NOT NULL
          AND boraum_status = 'cancelled'
          AND created_at >= ?
          AND created_at < (? ::date + interval '1 day')
    ");
    $stmtCancelled->execute([$periodStartStr, $periodEndStr]);
    $cancelledDeliveries = (int)$stmtCancelled->fetchColumn();

    // Total delivery fees collected from customers for BoraUm orders
    $stmtFees = $db->prepare("
        SELECT
            COALESCE(SUM(o.delivery_fee), 0) AS total_delivery_fees,
            COALESCE(SUM(o.service_fee), 0) AS total_service_fees,
            COALESCE(SUM(o.express_fee), 0) AS total_express_fees,
            COALESCE(SUM(o.subtotal), 0) AS total_subtotal,
            COALESCE(SUM(o.total), 0) AS total_order_value,
            COALESCE(SUM(o.commission_amount), 0) AS total_commission
        FROM om_market_orders o
        INNER JOIN om_entregas e ON e.referencia_id = o.order_id AND e.origem_sistema = 'mercado'
        WHERE e.boraum_delivery_id IS NOT NULL
          AND e.boraum_status = 'delivered'
          AND o.status = 'entregue'
          AND o.delivered_at >= ?
          AND o.delivered_at < (? ::date + interval '1 day')
    ");
    $stmtFees->execute([$periodStartStr, $periodEndStr]);
    $fees = $stmtFees->fetch();

    // BoraUm cost: from the API response if available, otherwise estimate from delivery fees
    $totalBoraumCost = 0;
    if ($apiResponse && isset($apiResponse['total_cost'])) {
        $totalBoraumCost = (float)$apiResponse['total_cost'];
    } elseif ($apiResponse && isset($apiResponse['data']['total_cost'])) {
        $totalBoraumCost = (float)$apiResponse['data']['total_cost'];
    } else {
        // Estimate: delivery fees collected minus our margin
        // We charge customers delivery_fee but BoraUm charges us less
        $totalBoraumCost = (float)($fees['total_delivery_fees'] ?? 0);
        $log("Using estimated BoraUm cost from delivery fees: R$ " . number_format($totalBoraumCost, 2, ',', '.'));
    }

    // SuperBora margin: commission + service fees + express fees - BoraUm cost
    $totalCommission = (float)($fees['total_commission'] ?? 0);
    $totalServiceFees = (float)($fees['total_service_fees'] ?? 0);
    $totalExpressFees = (float)($fees['total_express_fees'] ?? 0);
    $totalDeliveryFees = (float)($fees['total_delivery_fees'] ?? 0);

    // Net revenue = commission + service fee + express fee
    // BoraUm cost is what we owe them (roughly = delivery_fee we collected, minus our markup)
    $totalSuperboraMargin = round($totalCommission + $totalServiceFees + $totalExpressFees, 2);

    // Net amount owed to BoraUm = their cost minus what we keep
    $netAmount = round($totalBoraumCost, 2);

    $localSummary = [
        'total_deliveries' => $totalDeliveries,
        'completed_deliveries' => $completedDeliveries,
        'cancelled_deliveries' => $cancelledDeliveries,
        'total_delivery_fees_collected' => (float)($fees['total_delivery_fees'] ?? 0),
        'total_service_fees' => $totalServiceFees,
        'total_express_fees' => $totalExpressFees,
        'total_subtotal' => (float)($fees['total_subtotal'] ?? 0),
        'total_order_value' => (float)($fees['total_order_value'] ?? 0),
        'total_commission' => $totalCommission,
        'total_superbora_margin' => $totalSuperboraMargin,
        'total_boraum_cost' => $totalBoraumCost,
        'net_amount' => $netAmount,
        'api_available' => !empty($apiResponse),
    ];

    $log("Summary: {$completedDeliveries}/{$totalDeliveries} completed, {$cancelledDeliveries} cancelled");
    $log("BoraUm cost: R$ " . number_format($totalBoraumCost, 2, ',', '.'));
    $log("SuperBora margin: R$ " . number_format($totalSuperboraMargin, 2, ',', '.'));
    $log("Net amount owed to BoraUm: R$ " . number_format($netAmount, 2, ',', '.'));

    // ─── Insert billing record ───────────────────────────────
    $notes = $apiError ? "API error: $apiError" : null;

    $stmtInsert = $db->prepare("
        INSERT INTO om_boraum_billing
            (period_start, period_end, total_deliveries, completed_deliveries, cancelled_deliveries,
             total_boraum_cost, total_superbora_margin, net_amount, raw_api_response, local_summary,
             status, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
    ");
    $stmtInsert->execute([
        $periodStartStr,
        $periodEndStr,
        $totalDeliveries,
        $completedDeliveries,
        $cancelledDeliveries,
        $totalBoraumCost,
        $totalSuperboraMargin,
        $netAmount,
        $apiResponse ? json_encode($apiResponse, JSON_UNESCAPED_UNICODE) : null,
        json_encode($localSummary, JSON_UNESCAPED_UNICODE),
        $notes,
    ]);

    $billingId = $db->lastInsertId();
    $log("Billing record #$billingId created successfully");

    // ─── Log summary for admin ───────────────────────────────
    error_log(sprintf(
        "[boraum-repasse] WEEKLY REPORT: Period %s to %s | Deliveries: %d total, %d completed, %d cancelled | "
        . "BoraUm cost: R$ %s | SuperBora margin: R$ %s | Net owed: R$ %s | Status: pending (manual PIX)",
        $periodStartStr, $periodEndStr,
        $totalDeliveries, $completedDeliveries, $cancelledDeliveries,
        number_format($totalBoraumCost, 2, ',', '.'),
        number_format($totalSuperboraMargin, 2, ',', '.'),
        number_format($netAmount, 2, ',', '.')
    ));

    $elapsed = round((microtime(true) - $startTime) * 1000);
    $log("=== Completed in {$elapsed}ms ===");

    if (!$isCli) {
        echo json_encode([
            'success' => true,
            'billing_id' => (int)$billingId,
            'period_start' => $periodStartStr,
            'period_end' => $periodEndStr,
            'total_deliveries' => $totalDeliveries,
            'completed_deliveries' => $completedDeliveries,
            'cancelled_deliveries' => $cancelledDeliveries,
            'total_boraum_cost' => $totalBoraumCost,
            'total_superbora_margin' => $totalSuperboraMargin,
            'net_amount' => $netAmount,
            'status' => 'pending',
        ]);
    }

} catch (Exception $e) {
    $log("FATAL ERROR: " . $e->getMessage());
    error_log("[boraum-repasse] FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString());

    if (!$isCli) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Internal error']);
    }
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
