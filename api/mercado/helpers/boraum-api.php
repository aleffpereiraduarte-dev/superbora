<?php
/**
 * BoraUm API Helper
 *
 * Shared helper for calling BoraUm partner APIs (availability, quotes, etc.)
 * Used by delivery-config.php and vitrine.php.
 */

require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

/**
 * Generic BoraUm API call
 *
 * @param string $method  HTTP method (GET or POST)
 * @param string $path    API path (e.g. /api/partner/availability)
 * @param array|null $data  POST body data (null for GET)
 * @param int $timeout    cURL timeout in seconds
 * @return array|null     Decoded JSON response or null on failure
 */
function boraUmAPI(string $method, string $path, ?array $data = null, int $timeout = 3): ?array {
    // Load API key from env
    $apiKey = $_ENV['BORAUM_API_KEY'] ?? getenv('BORAUM_API_KEY') ?: '';

    // Fallback: parse .env file
    if (!$apiKey) {
        static $envCache = null;
        if ($envCache === null) {
            $envCache = [];
            $envFile = dirname(__DIR__, 3) . '/.env';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos(trim($line), '#') === 0) continue;
                    if (strpos($line, '=') !== false) {
                        list($k, $v) = explode('=', $line, 2);
                        $envCache[trim($k)] = trim(trim($v), '"\'');
                    }
                }
            }
        }
        $apiKey = $envCache['BORAUM_API_KEY'] ?? '';
    }

    if (!$apiKey) return null;

    $url = 'https://boraum.com.br' . $path;

    // For GET requests with data, append as query params
    if ($method === 'GET' && $data) {
        $url .= '?' . http_build_query($data);
        $data = null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min($timeout, 3),
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);
    if ($method === 'POST' && $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("[boraum-api] cURL error ($method $path): $curlErr");
        return null;
    }

    if ($code >= 200 && $code < 300 && $resp) {
        return json_decode($resp, true);
    }

    error_log("[boraum-api] HTTP $code for $method $path: " . substr($resp ?: '', 0, 300));
    return null;
}

/**
 * Get BoraUm driver availability near a location.
 * Cached for 5 minutes.
 *
 * @param float $lat
 * @param float $lng
 * @param float $radiusKm
 * @return array|null  ['moto' => int, 'carro' => int, 'bicicleta' => int, ...] or null
 */
function getBoraUmAvailability(float $lat, float $lng, float $radiusKm = 10): ?array {
    if ($lat == 0 || $lng == 0) return null;

    // Round coords to 2 decimals for cache grouping (~1km precision)
    $cLat = round($lat, 2);
    $cLng = round($lng, 2);
    $cacheKey = "boraum_avail_{$cLat}_{$cLng}";

    $cached = CacheHelper::get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $result = boraUmAPI('GET', '/api/partner/availability', [
        'lat' => $lat,
        'lng' => $lng,
        'radius_km' => $radiusKm,
    ]);

    if ($result !== null) {
        CacheHelper::set($cacheKey, $result, 300); // 5 min cache
    }

    return $result;
}

/**
 * Get BoraUm delivery quote for a specific pickup/dropoff pair.
 * Cached for 5 minutes by partner + customer location.
 *
 * @param int $partnerId    For cache key
 * @param float $pickupLat
 * @param float $pickupLng
 * @param float $dropoffLat
 * @param float $dropoffLng
 * @return array|null  Quote data with prices per vehicle type, or null on failure
 */
function getBoraUmQuote(int $partnerId, float $pickupLat, float $pickupLng, float $dropoffLat, float $dropoffLng): ?array {
    if ($pickupLat == 0 || $pickupLng == 0 || $dropoffLat == 0 || $dropoffLng == 0) return null;

    // Round customer coords to 3 decimals (~100m precision) for cache
    $cLat = round($dropoffLat, 3);
    $cLng = round($dropoffLng, 3);
    $cacheKey = "boraum_quote_{$partnerId}_{$cLat}_{$cLng}";

    $cached = CacheHelper::get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $result = boraUmAPI('POST', '/api/partner/deliveries/quote', [
        'pickup' => [
            'lat' => $pickupLat,
            'lng' => $pickupLng,
        ],
        'dropoff' => [
            'lat' => $dropoffLat,
            'lng' => $dropoffLng,
        ],
    ]);

    if ($result !== null) {
        CacheHelper::set($cacheKey, $result, 300); // 5 min cache
    }

    return $result;
}

/**
 * Calculate SuperBora margin based on distance.
 *
 * @param float $distanciaKm
 * @return float  Margin in R$
 */
function calcularMargemSuperBora(float $distanciaKm): float {
    if ($distanciaKm <= 3) return 3.0;
    if ($distanciaKm <= 6) return 4.0;
    return 5.0; // 6+ km
}

/**
 * Calculate delivery fee for client that GUARANTEES SuperBora profit.
 * Uses BoraUm quote price + ensures margem mínima.
 *
 * @param float $custoBoraUm  Raw BoraUm cost
 * @param float $subtotal     Order subtotal (for 18% commission calc)
 * @param float $distanciaKm  Distance
 * @return array ['taxa_cliente' => float, 'lucro' => float, 'saudavel' => bool]
 */
function calcularTaxaEntregaSegura(float $custoBoraUm, float $subtotal, float $distanciaKm): array {
    require_once dirname(dirname(__DIR__)) . '/includes/classes/OmPricing.php';

    $margem = calcularMargemSuperBora($distanciaKm);
    $taxaBase = $custoBoraUm + $margem;

    // Verify P&L
    $pl = OmPricing::calcularPLBoraUm($subtotal, $custoBoraUm, $taxaBase);

    if (!$pl['saudavel']) {
        // Increase fee to guarantee minimum margin
        $taxaBase = $pl['taxa_minima_cliente'];
        $pl = OmPricing::calcularPLBoraUm($subtotal, $custoBoraUm, $taxaBase);
    }

    return [
        'taxa_cliente' => round($taxaBase, 2),
        'custo_boraum' => $custoBoraUm,
        'lucro_superbora' => $pl['lucro_superbora'],
        'saudavel' => $pl['saudavel'],
        'comissao_18pct' => $pl['comissao_18pct'],
    ];
}

/**
 * Pick the cheapest available vehicle from a BoraUm quote response.
 *
 * @param array $quote  The quote API response
 * @return array|null   ['vehicle_type' => string, 'price' => float, 'quote_id' => string] or null
 */
function pickCheapestVehicle(array $quote): ?array {
    $options = $quote['options'] ?? $quote['vehicles'] ?? $quote['quotes'] ?? [];

    if (empty($options) && isset($quote['price'])) {
        // Simple format: single price
        return [
            'vehicle_type' => $quote['vehicle_type'] ?? 'moto',
            'price' => (float)$quote['price'],
            'quote_id' => $quote['quote_id'] ?? $quote['id'] ?? null,
        ];
    }

    $cheapest = null;
    foreach ($options as $opt) {
        $available = $opt['available'] ?? $opt['drivers_available'] ?? true;
        if (!$available) continue;

        $price = (float)($opt['price'] ?? $opt['cost'] ?? PHP_FLOAT_MAX);
        if ($cheapest === null || $price < $cheapest['price']) {
            $cheapest = [
                'vehicle_type' => $opt['vehicle_type'] ?? $opt['type'] ?? 'moto',
                'price' => $price,
                'quote_id' => $opt['quote_id'] ?? $quote['quote_id'] ?? $quote['id'] ?? null,
            ];
        }
    }

    return $cheapest;
}

/**
 * Check if any drivers are available from an availability response.
 *
 * @param array $availability  The availability API response
 * @return bool
 */
function hasAvailableDrivers(array $availability): bool {
    // Check direct count fields
    $types = ['moto', 'carro', 'bicicleta', 'van'];
    foreach ($types as $type) {
        if (isset($availability[$type]) && (int)$availability[$type] > 0) {
            return true;
        }
    }

    // Check nested drivers/vehicles array
    $drivers = $availability['drivers'] ?? $availability['vehicles'] ?? $availability['available'] ?? [];
    if (is_array($drivers)) {
        foreach ($drivers as $d) {
            $count = $d['count'] ?? $d['available'] ?? 0;
            if ((int)$count > 0) return true;
        }
    }

    // Check total field
    if (isset($availability['total']) && (int)$availability['total'] > 0) {
        return true;
    }

    return false;
}

/**
 * Get current surge multiplier based on active time-based rules.
 * Checks om_surge_rules table for matching rules and manual override.
 *
 * @param PDO $db
 * @return float  Multiplier (1.0 = no surge)
 */
function getSurgeMultiplier(PDO $db): float {
    // First check manual override in om_config
    try {
        $stmt = $db->query("SELECT valor FROM om_config WHERE chave = 'surge_manual_override' LIMIT 1");
        $row = $stmt->fetch();
        if ($row) {
            $data = json_decode($row['valor'], true);
            if ($data && isset($data['expires_at'])) {
                if (strtotime($data['expires_at']) > time()) {
                    return max(1.0, (float)($data['multiplier'] ?? 1.0));
                }
                // Expired - clean up
                $db->exec("DELETE FROM om_config WHERE chave = 'surge_manual_override'");
            }
        }
    } catch (Exception $e) {
        // Config table might not have the key, continue to rules
    }

    // Check auto rules
    try {
        $now = date('H:i');
        $dayOfWeek = strtolower(date('D')); // mon, tue, wed, etc.

        $stmt = $db->prepare("
            SELECT multiplier, days
            FROM om_surge_rules
            WHERE active = true
            AND hours_start <= ?::time
            AND hours_end >= ?::time
            ORDER BY multiplier DESC
        ");
        $stmt->execute([$now, $now]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $rule) {
            $days = $rule['days'] ?? 'all';
            if ($days === 'all') {
                return max(1.0, (float)$rule['multiplier']);
            }

            // Check day ranges like "mon-fri" or "sat-sun"
            $parts = array_map('trim', explode('-', $days));
            if (count($parts) === 2) {
                $dayOrder = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
                $start = $dayOrder[$parts[0]] ?? 0;
                $end = $dayOrder[$parts[1]] ?? 0;
                $current = $dayOrder[$dayOfWeek] ?? 0;
                if ($current >= $start && $current <= $end) {
                    return max(1.0, (float)$rule['multiplier']);
                }
            }

            // Check comma-separated list
            $dayList = array_map('trim', explode(',', $days));
            if (in_array($dayOfWeek, $dayList)) {
                return max(1.0, (float)$rule['multiplier']);
            }
        }
    } catch (Exception $e) {
        // Table might not exist yet, return no surge
        error_log("[getSurgeMultiplier] Error: " . $e->getMessage());
    }

    return 1.0;
}
