<?php
/**
 * GET /api/mercado/geolocate.php
 * Geolocalizacao por IP usando ip-api.com (gratis, 45 req/min)
 * Cache de 1h por IP para nao estourar rate limit
 * Retorna: { city, state, lat, lng, country }
 */
require_once __DIR__ . "/config/database.php";
require_once dirname(__DIR__) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=3600');

try {
    // Detect client IP — prefer Cloudflare trusted header
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    // SECURITY: Validate IP format to prevent SSRF via malformed values
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = '';
    }

    // For local/private IPs, return a default response
    $isPrivate = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($isPrivate || empty($ip)) {
        response(true, [
            'city' => '',
            'state' => '',
            'lat' => 0,
            'lng' => 0,
            'country' => 'BR',
            'source' => 'default'
        ]);
    }

    // Cache by IP for 1 hour
    $cacheKey = "geoip_" . md5($ip);

    $data = CacheHelper::remember($cacheKey, 3600, function() use ($ip) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 5]
        ]);

        $url = "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,message,country,countryCode,region,regionName,city,lat,lon&lang=pt-BR";
        $raw = @file_get_contents($url, false, $ctx);

        if ($raw === false) {
            return null;
        }

        $json = json_decode($raw, true);
        if (!$json || $json['status'] !== 'success') {
            return null;
        }

        return [
            'city' => $json['city'] ?? '',
            'state' => $json['region'] ?? '',
            'lat' => (float)($json['lat'] ?? 0),
            'lng' => (float)($json['lon'] ?? 0),
            'country' => $json['countryCode'] ?? 'BR',
            'source' => 'ip'
        ];
    });

    if ($data === null) {
        // Fallback — do not reveal primary market location
        $data = [
            'city' => '',
            'state' => '',
            'lat' => 0,
            'lng' => 0,
            'country' => 'BR',
            'source' => 'default'
        ];
    }

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Geolocate] Erro: " . $e->getMessage());
    response(false, null, "Erro ao detectar localizacao.", 500);
}
