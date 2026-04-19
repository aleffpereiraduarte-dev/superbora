<?php
/**
 * CRON: Backfill lat/lng for partners without coordinates.
 * Runs every hour, geocodes up to 30 partners per run (Nominatim rate limits).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/geocoder.php';

$lockFile = '/tmp/superbora_cron_geocode_backfill.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) { echo "Another instance running\n"; exit(0); }

$db = getDB();
$log = fn($m) => print("[" . date('H:i:s') . "] $m\n");

$log("=== Geocode backfill started ===");

$stmt = $db->query("
    SELECT partner_id, name, COALESCE(street, address, endereco) AS street,
           COALESCE(number, address_number) AS number,
           COALESCE(neighborhood, bairro) AS neighborhood,
           COALESCE(city, cidade) AS city,
           COALESCE(state, estado) AS state,
           cep
    FROM om_market_partners
    WHERE (lat IS NULL OR lng IS NULL OR lat = 0 OR lng = 0)
      AND status::text = '1'
      AND (COALESCE(street, address, endereco) IS NOT NULL OR cep IS NOT NULL)
    ORDER BY partner_id DESC
    LIMIT 30
");
$partners = $stmt->fetchAll();
$log("Queued " . count($partners) . " partners");

$done = 0;
$fail = 0;
foreach ($partners as $p) {
    $coords = geocodeAddress([
        'street' => $p['street'] ?? '',
        'number' => $p['number'] ?? '',
        'neighborhood' => $p['neighborhood'] ?? '',
        'city' => $p['city'] ?? '',
        'state' => $p['state'] ?? '',
        'cep' => $p['cep'] ?? '',
    ]);

    if ($coords) {
        $u = $db->prepare("UPDATE om_market_partners SET lat = ?, lng = ? WHERE partner_id = ?");
        $u->execute([$coords['lat'], $coords['lng'], (int)$p['partner_id']]);
        $done++;
        $log("#{$p['partner_id']} {$p['name']} -> lat={$coords['lat']} lng={$coords['lng']}");
    } else {
        $fail++;
        $log("#{$p['partner_id']} {$p['name']} -> SKIP (no coords found)");
    }
}

$log("=== Done. Geocoded {$done}, failed {$fail} ===");
