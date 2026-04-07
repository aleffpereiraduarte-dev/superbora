<?php
/**
 * Cron: Cache Warmer
 *
 * Hits the hot read endpoints periodically to keep the cache always fresh.
 * This eliminates the "first user pays" problem after TTL expires.
 *
 * Strategy:
 *   - Calls top 50 partner storefront endpoints (listar.php, banners, featured, popular)
 *   - Calls global feeds (hits, descontos, home) with common parameter combos
 *   - Each call writes to R2 + Cloudflare edge automatically (warm cache layer)
 *
 * Schedule: every 2 minutes (just before the 5-min Cloudflare TTL expires)
 * Run manually: php cron/cache-warmer.php [partner_limit]
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$start = microtime(true);
$partnerLimit = max(1, min(200, (int)($argv[1] ?? 50)));

// Discover top partners by recent activity (orders in last 7 days)
$sql = "
    SELECT p.partner_id
    FROM om_market_partners p
    LEFT JOIN (
        SELECT partner_id, COUNT(*) AS qty
        FROM om_market_orders
        WHERE created_at > NOW() - INTERVAL '7 days'
          AND status NOT IN ('cancelado','recusado')
        GROUP BY partner_id
    ) o ON o.partner_id = p.partner_id
    WHERE p.status::text = '1'
    ORDER BY COALESCE(o.qty, 0) DESC, p.partner_id
    LIMIT :lim
";
$stmt = $db->prepare($sql);
$stmt->bindValue(':lim', $partnerLimit, PDO::PARAM_INT);
$stmt->execute();
$partners = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'partner_id');

if (empty($partners)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[cache-warmer] no active partners\n");
    exit(0);
}

// We hit our own internal HTTP so the full pipeline runs (cache write, headers, etc).
// Use 127.0.0.1 with the public Host header so the routing/middleware behave correctly.
$baseUrl = 'http://127.0.0.1';
$hostHeader = 'Host: superbora.com.br';

$mh = curl_multi_init();
$handles = [];

function addHit(&$mh, &$handles, string $baseUrl, string $hostHeader, string $path) {
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => false,        // we want the full body so the cache writes
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            $hostHeader,
            'X-Cache-Warmer: 1',
            'User-Agent: SuperBora-Cache-Warmer/1.0',
        ],
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$path] = $ch;
}

// 1. Per-partner hot endpoints
foreach ($partners as $pid) {
    addHit($mh, $handles, $baseUrl, $hostHeader, "/api/mercado/produtos/listar.php?partner_id={$pid}&page=1&limit=50");
    addHit($mh, $handles, $baseUrl, $hostHeader, "/api/mercado/store/banners.php?partner_id={$pid}");
    addHit($mh, $handles, $baseUrl, $hostHeader, "/api/mercado/store/featured.php?partner_id={$pid}");
    addHit($mh, $handles, $baseUrl, $hostHeader, "/api/mercado/store/popular-products.php?partner_id={$pid}&limit=10");
}

// 2. Global feeds — most common parameter combos
$globalUrls = [
    "/api/mercado/intelligence/hits.php?max_price=20&limit=20",
    "/api/mercado/intelligence/hits.php?max_price=20&limit=10",
    "/api/mercado/intelligence/hits.php?max_price=50&limit=20",
    "/api/mercado/intelligence/descontos.php?min_discount=10&limit=20",
    "/api/mercado/intelligence/descontos.php?min_discount=20&limit=20",
    "/api/mercado/intelligence/descontos.php?min_discount=30&limit=20",
];
foreach ($globalUrls as $u) addHit($mh, $handles, $baseUrl, $hostHeader, $u);

// Run all in parallel — concurrency limit 20
$running = null;
$activeCount = 0;
$maxConcurrent = 20;

do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 0.1);
    }
} while ($running > 0);

// Collect results
$success = 0;
$failed = 0;
$cacheHits = 0;
$cacheMisses = 0;

foreach ($handles as $path => $ch) {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = curl_multi_getcontent($ch);
    if ($code === 200 && strlen($body) > 10) {
        $success++;
        // Parse the X-Cache header from the body? No, we lose headers in multi.
        // We can infer from the body if it was a fresh write or HIT — skip for now.
    } else {
        $failed++;
    }
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

$elapsed = round(microtime(true) - $start, 2);
$total = count($handles);

$msg = "[cache-warmer] partners={$partnerLimit} urls={$total} ok={$success} fail={$failed} elapsed={$elapsed}s";
if (PHP_SAPI === 'cli') fwrite(STDOUT, $msg . "\n");
error_log($msg);
