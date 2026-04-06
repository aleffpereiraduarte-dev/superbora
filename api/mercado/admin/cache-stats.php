<?php
/**
 * /api/mercado/admin/cache-stats.php
 *
 * Cache statistics endpoint for admin dashboard.
 *
 * GET — returns comprehensive cache analytics:
 *   hit_rate       — percentage of requests served from cache
 *   by_status      — breakdown of HIT, MISS, BYPASS, EXPIRED, etc.
 *   disk_usage     — per-zone disk usage
 *   cached_objects — estimated file count per zone
 *   top_cached     — most frequently cached URL patterns (from recent log)
 *   uptime         — time since last cache purge (approximated)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();

    $stats = [];

    // ── 1. Parse cache log for hit rates ──
    $cacheLog = '/var/log/nginx/cache.log';
    $statusCounts = [
        'HIT' => 0,
        'MISS' => 0,
        'BYPASS' => 0,
        'EXPIRED' => 0,
        'STALE' => 0,
        'UPDATING' => 0,
        'REVALIDATED' => 0,
        '-' => 0,
    ];
    $totalRequests = 0;
    $topUrls = [];

    if (file_exists($cacheLog)) {
        // Read last 50,000 lines for analysis (efficient for large logs)
        $lines = [];
        $fp = fopen($cacheLog, 'r');
        if ($fp) {
            // Seek to approximately the last 5MB
            $fileSize = filesize($cacheLog);
            $seekPos = max(0, $fileSize - 5 * 1024 * 1024);
            fseek($fp, $seekPos);

            // Skip partial first line if we seeked
            if ($seekPos > 0) {
                fgets($fp);
            }

            while (($line = fgets($fp)) !== false) {
                $totalRequests++;

                // Extract cache status: "cache=HIT" or "cache=MISS" etc.
                if (preg_match('/cache[=:](\w+|-)\s/', $line, $m)) {
                    $status = strtoupper($m[1]);
                    if (isset($statusCounts[$status])) {
                        $statusCounts[$status]++;
                    } else {
                        $statusCounts[$status] = 1;
                    }
                }

                // Extract URL for top cached
                if (preg_match('/cache[=:]HIT/', $line) && preg_match('/"(GET|HEAD)\s+([^\s"]+)/', $line, $um)) {
                    $url = $um[2];
                    // Normalize: remove query string for grouping
                    $urlBase = strtok($url, '?');
                    $topUrls[$urlBase] = ($topUrls[$urlBase] ?? 0) + 1;
                }
            }
            fclose($fp);
        }
    }

    // Calculate hit rate
    $cacheableRequests = $statusCounts['HIT'] + $statusCounts['MISS'] + $statusCounts['EXPIRED'] + $statusCounts['STALE'] + $statusCounts['UPDATING'] + $statusCounts['REVALIDATED'];
    $hitRate = $cacheableRequests > 0
        ? round(($statusCounts['HIT'] + $statusCounts['STALE'] + $statusCounts['REVALIDATED']) / $cacheableRequests * 100, 1)
        : 0;

    $stats['hit_rate'] = $hitRate;
    $stats['total_requests_analyzed'] = $totalRequests;
    $stats['by_status'] = $statusCounts;

    // Top 15 cached URLs
    arsort($topUrls);
    $stats['top_cached_urls'] = array_slice($topUrls, 0, 15, true);

    // ── 2. Disk usage per zone ──
    $cacheDirs = [
        'superbora' => '/var/cache/nginx/superbora',
        'api'       => '/var/cache/nginx/api_cache',
        'static'    => '/var/cache/nginx/static_cache',
    ];

    $diskUsage = [];
    $totalFiles = 0;
    foreach ($cacheDirs as $name => $dir) {
        if (is_dir($dir)) {
            $size = trim(shell_exec("du -sb " . escapeshellarg($dir) . " 2>/dev/null | cut -f1"));
            $sizeHuman = trim(shell_exec("du -sh " . escapeshellarg($dir) . " 2>/dev/null | cut -f1"));
            $files = (int)trim(shell_exec("find " . escapeshellarg($dir) . " -type f 2>/dev/null | wc -l"));
            $totalFiles += $files;
            $diskUsage[$name] = [
                'size_bytes'  => (int)$size,
                'size_human'  => $sizeHuman ?: '0',
                'files'       => $files,
            ];
        } else {
            $diskUsage[$name] = [
                'size_bytes'  => 0,
                'size_human'  => '0',
                'files'       => 0,
            ];
        }
    }
    $stats['disk_usage'] = $diskUsage;
    $stats['total_cached_objects'] = $totalFiles;

    // Total disk
    $totalDisk = trim(shell_exec("du -sh /var/cache/nginx/ 2>/dev/null | cut -f1"));
    $stats['total_disk_usage'] = $totalDisk ?: '0';

    // ── 3. Cache zone limits (for dashboard progress bars) ──
    $stats['zone_limits'] = [
        'superbora' => ['max_size' => '2g', 'inactive' => '7d'],
        'api'       => ['max_size' => '500m', 'inactive' => '1h'],
        'static'    => ['max_size' => '1g', 'inactive' => '30d'],
    ];

    // ── 4. Request rate (from log timestamps) ──
    if ($totalRequests > 0 && file_exists($cacheLog)) {
        // Get first and last timestamp from our analyzed portion
        $firstLine = trim(shell_exec("head -1 " . escapeshellarg($cacheLog) . " 2>/dev/null"));
        $lastLine = trim(shell_exec("tail -1 " . escapeshellarg($cacheLog) . " 2>/dev/null"));

        if (preg_match('/\[(\d{2}\/\w+\/\d{4}:\d{2}:\d{2}:\d{2})/', $firstLine, $fm) &&
            preg_match('/\[(\d{2}\/\w+\/\d{4}:\d{2}:\d{2}:\d{2})/', $lastLine, $lm)) {
            $first = strtotime(str_replace('/', ' ', str_replace(':', ' ', $fm[1], 1)));
            $last = strtotime(str_replace('/', ' ', str_replace(':', ' ', $lm[1], 1)));
            $spanSeconds = max(1, $last - $first);
            $stats['requests_per_second'] = round($totalRequests / $spanSeconds, 1);
            $stats['log_span_hours'] = round($spanSeconds / 3600, 1);
        }
    }

    response(true, $stats);

} catch (Exception $e) {
    response(false, null, "Erro: " . $e->getMessage(), 500);
}
