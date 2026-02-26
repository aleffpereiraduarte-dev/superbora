<?php
/**
 * GET /api/mercado/parceiro/check-premium.php?lat=X&lng=Y
 *
 * Verifica se entrega BoraUm Premium esta disponivel na regiao informada.
 * Consulta shoppers online dentro de 15km usando formula Haversine.
 *
 * Publico (sem autenticacao), com rate limit de 10 req/min por IP.
 *
 * Response: {
 *   "success": true,
 *   "data": {
 *     "premium_available": true,
 *     "drivers_nearby": 3
 *   }
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";

// Rate limit: 10 consultas por minuto por IP
if (!RateLimiter::check(10, 60)) {
    exit;
}

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Use GET com ?lat=X&lng=Y", 405);
}

// =========================================================================
// VALIDACAO DE PARAMETROS
// =========================================================================
$lat = $_GET['lat'] ?? null;
$lng = $_GET['lng'] ?? null;

if ($lat === null || $lng === null) {
    response(false, null, "Parametros lat e lng sao obrigatorios.", 400);
}

$lat = floatval($lat);
$lng = floatval($lng);

if ($lat < -90 || $lat > 90) {
    response(false, null, "Latitude invalida. Deve estar entre -90 e 90.", 400);
}

if ($lng < -180 || $lng > 180) {
    response(false, null, "Longitude invalida. Deve estar entre -180 e 180.", 400);
}

// =========================================================================
// CONSULTAR SHOPPERS PROXIMOS (HAVERSINE - 15KM)
// =========================================================================
$raio_km = 15;

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT COUNT(*) AS drivers_nearby
        FROM (
            SELECT s.shopper_id,
                (6371 * ACOS(
                    COS(RADIANS(?)) * COS(RADIANS(s.latitude)) *
                    COS(RADIANS(s.longitude) - RADIANS(?)) +
                    SIN(RADIANS(?)) * SIN(RADIANS(s.latitude))
                )) AS distance_km
            FROM om_market_shoppers s
            WHERE s.is_online = 1
              AND s.disponivel = 1
              AND s.status = '1'
              AND s.latitude IS NOT NULL
              AND s.longitude IS NOT NULL
        ) AS nearby
        WHERE distance_km <= ?
    ");
    $stmt->execute([$lat, $lng, $lat, $raio_km]);
    $result = $stmt->fetch();

    $drivers_nearby = (int)($result['drivers_nearby'] ?? 0);
    $premium_available = $drivers_nearby > 0;
    // Return boolean instead of exact count to avoid leaking operational data
    $has_drivers_nearby = $drivers_nearby > 0;

    response(true, [
        'premium_available' => $premium_available,
        'has_drivers_nearby' => $has_drivers_nearby,
    ], $premium_available
        ? "BoraUm Premium disponivel na sua regiao."
        : "Nenhum entregador Premium disponivel nesta regiao no momento.");

} catch (Exception $e) {
    error_log("[check-premium] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar disponibilidade Premium.", 500);
}
