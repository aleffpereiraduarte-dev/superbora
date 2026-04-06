<?php
/**
 * /api/mercado/admin/cache-purge.php
 *
 * Cache purge management endpoint for admin dashboard.
 *
 * POST — purge cache zones:
 *   action: purge_all | purge_api | purge_static | purge_url | status
 *   url_pattern: (required for purge_url) — glob pattern to match
 *
 * Rate limited: max 10 purges per hour per admin.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if (!$action) {
        response(false, null, "Acao obrigatoria: purge_all, purge_api, purge_static, purge_url, status", 400);
    }

    // Rate limiting: max 10 purges per hour (stored in /tmp)
    if ($action !== 'status') {
        $rateLimitFile = '/tmp/cache_purge_rate_' . $payload['user_id'];
        $rateData = [];
        if (file_exists($rateLimitFile)) {
            $rateData = json_decode(file_get_contents($rateLimitFile), true) ?: [];
        }
        // Remove entries older than 1 hour
        $oneHourAgo = time() - 3600;
        $rateData = array_filter($rateData, fn($ts) => $ts > $oneHourAgo);

        if (count($rateData) >= 10) {
            response(false, null, "Limite de purge excedido: maximo 10 por hora", 429);
        }

        $rateData[] = time();
        file_put_contents($rateLimitFile, json_encode(array_values($rateData)));
    }

    $cacheDirs = [
        'superbora' => '/var/cache/nginx/superbora',
        'api'       => '/var/cache/nginx/api_cache',
        'static'    => '/var/cache/nginx/static_cache',
    ];

    switch ($action) {
        case 'status':
            $status = [];
            foreach ($cacheDirs as $name => $dir) {
                if (is_dir($dir)) {
                    $size = trim(shell_exec("du -sh " . escapeshellarg($dir) . " 2>/dev/null | cut -f1"));
                    $files = (int)trim(shell_exec("find " . escapeshellarg($dir) . " -type f 2>/dev/null | wc -l"));
                    $status[$name] = [
                        'path'        => $dir,
                        'disk_usage'  => $size ?: '0',
                        'cached_files'=> $files,
                    ];
                } else {
                    $status[$name] = [
                        'path'        => $dir,
                        'disk_usage'  => '0',
                        'cached_files'=> 0,
                        'error'       => 'Directory not found',
                    ];
                }
            }

            // Total disk usage
            $totalSize = trim(shell_exec("du -sh /var/cache/nginx/ 2>/dev/null | cut -f1"));
            $status['total_disk_usage'] = $totalSize ?: '0';

            response(true, $status);
            break;

        case 'purge_all':
            foreach ($cacheDirs as $dir) {
                if (is_dir($dir)) {
                    shell_exec("rm -rf " . escapeshellarg($dir) . "/*");
                }
            }
            // Recreate dirs with correct ownership
            foreach ($cacheDirs as $dir) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                    chown($dir, 'www-data');
                    chgrp($dir, 'www-data');
                }
            }
            response(true, ['purged' => 'all', 'zones' => array_keys($cacheDirs)]);
            break;

        case 'purge_api':
            $dir = $cacheDirs['api'];
            if (is_dir($dir)) {
                shell_exec("rm -rf " . escapeshellarg($dir) . "/*");
            }
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                chown($dir, 'www-data');
                chgrp($dir, 'www-data');
            }
            response(true, ['purged' => 'api']);
            break;

        case 'purge_static':
            $dir = $cacheDirs['static'];
            if (is_dir($dir)) {
                shell_exec("rm -rf " . escapeshellarg($dir) . "/*");
            }
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                chown($dir, 'www-data');
                chgrp($dir, 'www-data');
            }
            response(true, ['purged' => 'static']);
            break;

        case 'purge_url':
            $urlPattern = $input['url_pattern'] ?? '';
            if (!$urlPattern) {
                response(false, null, "url_pattern obrigatorio para purge_url", 400);
            }
            // Sanitize: only allow alphanumeric, /, -, _, ., *, ?
            if (!preg_match('/^[a-zA-Z0-9\/\-\_\.\*\?\=\&]+$/', $urlPattern)) {
                response(false, null, "url_pattern contem caracteres invalidos", 400);
            }
            // Use grep to find matching cache files and delete them
            $purged = 0;
            foreach ($cacheDirs as $name => $dir) {
                if (is_dir($dir)) {
                    $escaped = escapeshellarg($urlPattern);
                    $files = trim(shell_exec("grep -rl " . $escaped . " " . escapeshellarg($dir) . " 2>/dev/null"));
                    if ($files) {
                        $fileList = explode("\n", $files);
                        foreach ($fileList as $file) {
                            $file = trim($file);
                            if ($file && strpos($file, $dir) === 0 && file_exists($file)) {
                                unlink($file);
                                $purged++;
                            }
                        }
                    }
                }
            }
            response(true, ['purged' => 'url', 'pattern' => $urlPattern, 'files_removed' => $purged]);
            break;

        default:
            response(false, null, "Acao invalida: $action", 400);
    }

} catch (Exception $e) {
    response(false, null, "Erro: " . $e->getMessage(), 500);
}
