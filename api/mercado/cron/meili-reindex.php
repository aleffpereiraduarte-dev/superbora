<?php
/**
 * CRON: Meilisearch reindex
 *
 * Full reindex of products + stores to Meilisearch.
 * Run every 30min to keep search results fresh.
 *
 * Usage: php meili-reindex.php [--products-only|--stores-only]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';

$lockFile = '/tmp/superbora_cron_meili_reindex.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another instance running.\n";
    exit(0);
}

$meiliHost = $_ENV['MEILI_HOST'] ?? getenv('MEILI_HOST') ?: 'http://127.0.0.1:7700';
$meiliKey = $_ENV['MEILI_ADMIN_KEY'] ?? getenv('MEILI_ADMIN_KEY') ?: '';
if (!$meiliKey) {
    echo "MEILI_ADMIN_KEY not set\n";
    exit(1);
}

$only = $argv[1] ?? '';

$log = function(string $msg) { echo "[" . date('H:i:s') . "] $msg\n"; };

function meiliReq(string $method, string $path, ?array $body = null): array {
    global $meiliHost, $meiliKey;
    $ch = curl_init($meiliHost . $path);
    $headers = ['Authorization: Bearer ' . $meiliKey, 'Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($res, true) ?: $res];
}

$db = getDB();
$log("=== Meili reindex started ===");

// ─── Ensure indexes + settings ────────────────────────────────
function ensureIndex(string $name, string $primaryKey, array $searchable, array $filterable, array $sortable) {
    global $log;
    $res = meiliReq('GET', "/indexes/{$name}");
    if ($res['code'] === 404) {
        $log("Creating index: {$name}");
        meiliReq('POST', '/indexes', ['uid' => $name, 'primaryKey' => $primaryKey]);
        sleep(1);
    }
    meiliReq('PATCH', "/indexes/{$name}/settings", [
        'searchableAttributes' => $searchable,
        'filterableAttributes' => $filterable,
        'sortableAttributes' => $sortable,
        'typoTolerance' => [
            'enabled' => true,
            'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
        ],
    ]);
}

ensureIndex('products', 'id',
    ['name', 'description', 'category_name', 'partner_name'],
    ['partner_id', 'available', 'status', 'category_name'],
    ['price', 'partner_rating']
);
ensureIndex('stores', 'id',
    ['name', 'trade_name', 'city', 'categoria', 'description'],
    ['categoria', 'city', 'state', 'status'],
    ['rating']
);

// ─── Index products (batch 500) ────────────────────────────────
if ($only !== '--stores-only') {
    $log("Querying products...");
    $stmt = $db->query("
        SELECT p.product_id AS id, p.name, p.description, p.price, p.special_price,
               p.image, p.unit, p.quantity, p.partner_id, p.category_id,
               c.name AS category_name,
               mp.name AS partner_name, mp.logo AS partner_logo,
               mp.rating AS partner_rating,
               mp.delivery_fee AS partner_delivery_fee,
               mp.delivery_time_min AS partner_delivery_time,
               mp.is_open AS partner_is_open,
               COALESCE(p.available::text, '1') AS available_raw,
               p.status
        FROM om_market_products p
        INNER JOIN om_market_partners mp ON mp.partner_id = p.partner_id AND mp.status::text = '1'
        LEFT JOIN om_market_categories c ON c.category_id = p.category_id
        WHERE p.status::text = '1'
          AND (p.image IS NOT NULL AND TRIM(p.image) != '')
    ");

    $batch = [];
    $total = 0;
    while ($row = $stmt->fetch()) {
        $row['id'] = (int)$row['id'];
        $row['price'] = (float)$row['price'];
        $row['special_price'] = $row['special_price'] ? (float)$row['special_price'] : null;
        $row['partner_id'] = (int)$row['partner_id'];
        $row['partner_rating'] = (float)($row['partner_rating'] ?? 0);
        $row['partner_delivery_fee'] = (float)($row['partner_delivery_fee'] ?? 0);
        $row['partner_delivery_time'] = (int)($row['partner_delivery_time'] ?? 0);
        $row['partner_is_open'] = (int)($row['partner_is_open'] ?? 0) === 1;
        $row['available'] = in_array(strtolower((string)$row['available_raw']), ['1', 't', 'true', 'yes'], true);
        unset($row['available_raw']);
        $batch[] = $row;
        if (count($batch) >= 500) {
            $res = meiliReq('POST', '/indexes/products/documents', $batch);
            $total += count($batch);
            $batch = [];
        }
    }
    if (!empty($batch)) {
        meiliReq('POST', '/indexes/products/documents', $batch);
        $total += count($batch);
    }
    $log("Sent {$total} products to Meilisearch");
}

// ─── Index stores ──────────────────────────────────────────────
if ($only !== '--products-only') {
    $log("Querying stores...");
    $stmt = $db->query("
        SELECT partner_id AS id, name, trade_name, categoria, city, state,
               logo, banner, description, rating, delivery_fee, delivery_time_min,
               is_open, lat, lng, status
        FROM om_market_partners
        WHERE status::text = '1'
    ");
    $batch = [];
    $total = 0;
    while ($row = $stmt->fetch()) {
        $row['id'] = (int)$row['id'];
        $row['rating'] = (float)($row['rating'] ?? 0);
        $row['delivery_fee'] = (float)($row['delivery_fee'] ?? 0);
        $row['delivery_time_min'] = (int)($row['delivery_time_min'] ?? 0);
        $row['is_open'] = (int)($row['is_open'] ?? 0) === 1;
        $row['lat'] = $row['lat'] ? (float)$row['lat'] : null;
        $row['lng'] = $row['lng'] ? (float)$row['lng'] : null;
        $batch[] = $row;
        if (count($batch) >= 500) {
            meiliReq('POST', '/indexes/stores/documents', $batch);
            $total += count($batch);
            $batch = [];
        }
    }
    if (!empty($batch)) {
        meiliReq('POST', '/indexes/stores/documents', $batch);
        $total += count($batch);
    }
    $log("Sent {$total} stores to Meilisearch");
}

$log("=== Done ===");
