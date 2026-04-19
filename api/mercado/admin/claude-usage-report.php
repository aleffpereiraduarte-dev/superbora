<?php
/**
 * GET /api/mercado/admin/claude-usage-report.php
 *
 * Reads /var/log/superbora/claude-usage.log and produces aggregated breakdown:
 *   - Total cost last 24h / 7d / all-time
 *   - Top 20 callers (by cost)
 *   - Breakdown by URI/endpoint
 *   - Breakdown by hour (to spot spikes)
 *
 * Admin-only. JWT required.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    OmAuth::getInstance()->setDb(getDB());
    om_auth()->requireAdmin();

    $logFile = '/var/log/superbora/claude-usage.log';
    if (!is_readable($logFile)) {
        response(false, null, 'Log file not readable (ainda nao foi gerado)', 404);
    }

    $since = (int)($_GET['hours'] ?? 24);
    $cutoff = time() - $since * 3600;

    $totalCost = 0.0;
    $totalCalls = 0;
    $byCaller = [];
    $byUri = [];
    $byHour = [];
    $byModel = [];

    $fh = fopen($logFile, 'r');
    if (!$fh) response(false, null, 'Cannot open log', 500);

    while (($line = fgets($fh)) !== false) {
        $entry = json_decode(trim($line), true);
        if (!$entry) continue;

        $ts = strtotime($entry['ts'] ?? '');
        if (!$ts || $ts < $cutoff) continue;

        $cost = (float)($entry['cost_usd'] ?? 0);
        $caller = $entry['caller'] ?? 'unknown';
        $uri = $entry['uri'] ?? 'unknown';
        $model = $entry['model'] ?? 'unknown';
        $hour = date('Y-m-d H', $ts);

        $totalCost += $cost;
        $totalCalls++;
        $byCaller[$caller] = ($byCaller[$caller] ?? 0) + $cost;
        $byUri[$uri] = ($byUri[$uri] ?? 0) + $cost;
        $byHour[$hour] = ($byHour[$hour] ?? 0) + $cost;
        $byModel[$model] = ($byModel[$model] ?? 0) + $cost;
    }
    fclose($fh);

    arsort($byCaller);
    arsort($byUri);
    ksort($byHour);
    arsort($byModel);

    response(true, [
        'window_hours' => $since,
        'total_calls' => $totalCalls,
        'total_cost_usd' => round($totalCost, 4),
        'total_cost_brl' => round($totalCost * 5, 2), // rough FX
        'by_caller' => array_slice(array_map(
            fn($k, $v) => ['caller' => $k, 'cost_usd' => round($v, 4)],
            array_keys($byCaller),
            $byCaller
        ), 0, 20),
        'by_uri' => array_slice(array_map(
            fn($k, $v) => ['uri' => $k, 'cost_usd' => round($v, 4)],
            array_keys($byUri),
            $byUri
        ), 0, 20),
        'by_hour' => array_map(
            fn($k, $v) => ['hour' => $k, 'cost_usd' => round($v, 4)],
            array_keys($byHour),
            $byHour
        ),
        'by_model' => array_map(
            fn($k, $v) => ['model' => $k, 'cost_usd' => round($v, 4)],
            array_keys($byModel),
            $byModel
        ),
    ]);
}
catch (Exception $e) {
    error_log("[claude-usage-report] " . $e->getMessage());
    response(false, null, 'Erro', 500);
}
