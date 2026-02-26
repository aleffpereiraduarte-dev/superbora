<?php
/**
 * Cron: Re-index products in Meilisearch
 * Run every 15 minutes to keep search index fresh
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/search.php';

// SECURITY: Cron auth guard — header only, no GET param
if (php_sapi_name() !== 'cli') {
    $cronKey = $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    $expectedKey = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
    if (empty($expectedKey) || empty($cronKey) || !hash_equals($expectedKey, $cronKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

try {
    $db = getDB();
    $search = SearchService::getInstance();

    $result = $search->reindexAll($db);
    echo date('Y-m-d H:i:s') . " - Reindexed: {$result['indexed']} / {$result['total']} products\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("[search-reindex] " . $e->getMessage());
}
