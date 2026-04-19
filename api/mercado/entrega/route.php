<?php
/**
 * GET /api/mercado/entrega/route.php?origin=lat,lng&destination=lat,lng[&waypoints=lat,lng;lat,lng]
 *
 * Returns driving route polyline between two points via OSRM (OpenStreetMap Routing).
 * Used by tracking screen to snap polyline to actual streets (iFood-style).
 *
 * Response:
 *   {
 *     success: true,
 *     data: {
 *       polyline: [[lat,lng], ...],   // decoded path
 *       distance_m: 3821,              // meters
 *       duration_s: 428                // seconds
 *     }
 *   }
 *
 * Cache: 120s per origin-destination pair (rounded to 3 decimals ~111m precision)
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/cache.php";

setCorsHeaders();

header('Cache-Control: public, max-age=30');

function decodePolyline(string $encoded): array {
    $points = [];
    $index = 0;
    $len = strlen($encoded);
    $lat = 0;
    $lng = 0;

    while ($index < $len) {
        $shift = 0;
        $result = 0;
        do {
            if ($index >= $len) break 2;
            $b = ord($encoded[$index++]) - 63;
            $result |= ($b & 0x1f) << $shift;
            $shift += 5;
        } while ($b >= 0x20);
        $dlat = (($result & 1) ? ~($result >> 1) : ($result >> 1));
        $lat += $dlat;

        $shift = 0;
        $result = 0;
        do {
            if ($index >= $len) break 2;
            $b = ord($encoded[$index++]) - 63;
            $result |= ($b & 0x1f) << $shift;
            $shift += 5;
        } while ($b >= 0x20);
        $dlng = (($result & 1) ? ~($result >> 1) : ($result >> 1));
        $lng += $dlng;

        $points[] = [$lat / 1e5, $lng / 1e5];
    }

    return $points;
}

function parseCoord(string $s): ?array {
    $parts = explode(',', $s);
    if (count($parts) !== 2) return null;
    $lat = (float)$parts[0];
    $lng = (float)$parts[1];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    if ($lat === 0.0 && $lng === 0.0) return null;
    return [$lat, $lng];
}

try {
    $origin = parseCoord($_GET['origin'] ?? '');
    $dest   = parseCoord($_GET['destination'] ?? '');

    if (!$origin || !$dest) {
        response(false, null, "origin e destination obrigatorios (lat,lng)", 400);
    }

    $waypoints = [];
    if (!empty($_GET['waypoints'])) {
        foreach (explode(';', $_GET['waypoints']) as $wp) {
            $parsed = parseCoord($wp);
            if ($parsed) $waypoints[] = $parsed;
        }
    }

    $cache = OmCache::getInstance();
    $cacheKey = sprintf(
        'route:%s,%s:%s,%s:%s',
        round($origin[0], 3), round($origin[1], 3),
        round($dest[0], 3), round($dest[1], 3),
        md5(json_encode($waypoints))
    );
    $cached = $cache->get($cacheKey);
    if ($cached !== null) {
        response(true, $cached);
    }

    $coords = [$origin];
    foreach ($waypoints as $wp) $coords[] = $wp;
    $coords[] = $dest;

    $pathStr = implode(';', array_map(fn($c) => "{$c[1]},{$c[0]}", $coords));
    $url = "https://router.project-osrm.org/route/v1/driving/{$pathStr}?overview=full&geometries=polyline&steps=false";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT => 'SuperBora/1.0',
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$body) {
        $fallback = [
            'polyline' => [$origin, ...$waypoints, $dest],
            'distance_m' => 0,
            'duration_s' => 0,
            'fallback' => true,
        ];
        response(true, $fallback);
    }

    $data = json_decode($body, true);
    if (($data['code'] ?? '') !== 'Ok' || empty($data['routes'][0])) {
        $fallback = [
            'polyline' => [$origin, ...$waypoints, $dest],
            'distance_m' => 0,
            'duration_s' => 0,
            'fallback' => true,
        ];
        response(true, $fallback);
    }

    $route = $data['routes'][0];
    $points = decodePolyline($route['geometry']);

    $result = [
        'polyline' => $points,
        'distance_m' => (int)$route['distance'],
        'duration_s' => (int)$route['duration'],
        'fallback' => false,
    ];

    $cache->set($cacheKey, $result, 120);
    response(true, $result);

} catch (Exception $e) {
    error_log("[entrega/route] " . $e->getMessage());
    response(false, null, "Erro ao calcular rota", 500);
}
