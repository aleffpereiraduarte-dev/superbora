<?php
/**
 * Geocoder — resolve address to lat/lng via Nominatim (OpenStreetMap).
 *
 * Nominatim is free but has a 1 req/sec rate limit — respect it.
 * Results cached in Redis (7 days TTL) keyed by normalized address.
 */

if (!function_exists('geocodeAddress')) {

    /**
     * Try to geocode a Brazilian address. Returns ['lat' => float, 'lng' => float] or null.
     *
     * @param array $parts Required: street, city, state. Optional: number, neighborhood, cep.
     */
    function geocodeAddress(array $parts): ?array {
        $street = trim($parts['street'] ?? '');
        $number = trim($parts['number'] ?? '');
        $neighborhood = trim($parts['neighborhood'] ?? '');
        $city = trim($parts['city'] ?? '');
        $state = trim($parts['state'] ?? '');
        $cep = preg_replace('/\D/', '', $parts['cep'] ?? '');

        if (!$street || !$city) return null;

        // Build query — start specific, fall back to less specific if needed
        $queries = array_filter([
            // Most specific: street + number + city
            $number ? "{$street}, {$number}, {$city}, Brasil" : null,
            // Street + neighborhood + city
            $neighborhood ? "{$street}, {$neighborhood}, {$city}, Brasil" : null,
            // Street + city
            "{$street}, {$city}, Brasil",
            // CEP (ViaCEP fallback — CEPs often have known coordinates)
            strlen($cep) === 8 ? "{$cep}, Brasil" : null,
        ]);

        // Cache by normalized first query
        $cacheKey = 'geocode:' . md5(mb_strtolower(reset($queries)));
        if (class_exists('OmCache')) {
            $cache = OmCache::getInstance();
            $hit = $cache->get($cacheKey);
            if (is_array($hit) && isset($hit['lat'], $hit['lng'])) return $hit;
            if ($hit === false) return null; // previously failed
        }

        foreach ($queries as $q) {
            $coords = nominatimQuery($q);
            if ($coords) {
                // Sanity check: Brazilian latitude is between -33 and +5, lng -74 to -34
                if ($coords['lat'] < -35 || $coords['lat'] > 6) continue;
                if ($coords['lng'] < -75 || $coords['lng'] > -33) continue;
                if (isset($cache)) $cache->set($cacheKey, $coords, 7 * 86400);
                return $coords;
            }
            // Respect Nominatim's 1 req/sec rate limit
            usleep(1100000);
        }

        if (isset($cache)) $cache->set($cacheKey, false, 3600);
        return null;
    }

    function nominatimQuery(string $q): ?array {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => 'br',
            'q' => $q,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'SuperBora/1.0 (ops@superbora.com.br)',
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$res) return null;
        $data = json_decode($res, true);
        if (empty($data[0]['lat']) || empty($data[0]['lon'])) return null;

        return [
            'lat' => (float)$data[0]['lat'],
            'lng' => (float)$data[0]['lon'],
        ];
    }
}
