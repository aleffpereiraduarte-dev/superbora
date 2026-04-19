<?php
/**
 * Cron: Cache Warmer (2-stage)
 *
 * Stage 1 (LOCAL): hits 127.0.0.1 to populate the R2 cache layer fast.
 *   No SSL overhead, no load balancer, no rate limit. ~150 URLs in <10s.
 *
 * Stage 2 (EDGE):  hits the public Cloudflare URL only for the most popular
 *   GLOBAL feeds (hits, descontos). This populates the Cloudflare edge cache
 *   so the very next user worldwide gets HIT. Per-partner pages get warmed
 *   organically when the first user touches them, then stay warm via SWR.
 *
 * Schedule: every 2 minutes via /etc/cron.d/superbora-cache-warmer
 * Run manually: php cron/cache-warmer.php [partner_limit]
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$start = microtime(true);
$partnerLimit = max(1, min(200, (int)($argv[1] ?? 50)));

// Discover top partners by recent activity
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

// ============================================================
// STAGE 1 — LOCAL HITS (127.0.0.1 with Host header)
// Populates the R2 cache layer for ALL partners — fast, no SSL
// ============================================================
$localBase = 'http://127.0.0.1';
$localHostHeader = 'Host: superbora.com.br';

$localUrls = [];
foreach ($partners as $pid) {
    $localUrls[] = "/api/mercado/produtos/listar.php?partner_id={$pid}&page=1&limit=50";
    $localUrls[] = "/api/mercado/store/banners.php?partner_id={$pid}";
    $localUrls[] = "/api/mercado/store/featured.php?partner_id={$pid}";
    $localUrls[] = "/api/mercado/store/popular-products.php?partner_id={$pid}&limit=10";
}

$localOk = 0;
$localFail = 0;

foreach (array_chunk($localUrls, 10) as $batch) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($batch as $path) {
        $ch = curl_init($localBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => [
                $localHostHeader,
                'X-Cache-Warmer: 1',
                'User-Agent: SuperBora-Cache-Warmer/1.0',
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.3);
    } while ($running > 0);
    foreach ($handles as $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        if ($code === 200 && strlen($body) > 10) $localOk++; else $localFail++;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}

// ============================================================
// STAGE 2 — EDGE HITS (Cloudflare public URL)
// Populates Cloudflare edge for global feeds + top 10 partners
// ============================================================
$edgeBase = 'https://superbora.com.br';
// Edge stage hits ONLY the global feeds — they're fast and serve everyone.
// Per-partner pages are populated organically (R2 already warm via Stage 1,
// so first user gets 100ms, then Cloudflare caches and rest gets 30ms).
$edgeUrls = [
    "/api/mercado/intelligence/hits.php?max_price=20&limit=20",
    "/api/mercado/intelligence/hits.php?max_price=20&limit=10",
    "/api/mercado/intelligence/hits.php?max_price=50&limit=20",
    "/api/mercado/intelligence/descontos.php?min_discount=10&limit=20",
    "/api/mercado/intelligence/descontos.php?min_discount=20&limit=20",
    "/api/mercado/intelligence/descontos.php?min_discount=30&limit=20",
];

$edgeOk = 0;
$edgeFail = 0;

foreach (array_chunk($edgeUrls, 4) as $batch) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($batch as $path) {
        $ch = curl_init($edgeBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'X-Cache-Warmer: 1',
                'User-Agent: SuperBora-Cache-Warmer/1.0',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.5);
    } while ($running > 0);
    foreach ($handles as $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        if ($code === 200 && strlen($body) > 10) $edgeOk++; else $edgeFail++;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}

$elapsed = round(microtime(true) - $start, 2);
$totalLocal = count($localUrls);
$totalEdge = count($edgeUrls);
$msg = "[cache-warmer] partners={$partnerLimit} local={$localOk}/{$totalLocal} edge={$edgeOk}/{$totalEdge} elapsed={$elapsed}s";
if (PHP_SAPI === 'cli') fwrite(STDOUT, $msg . "\n");
error_log($msg);
