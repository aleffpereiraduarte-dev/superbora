<?php
/**
 * GoServiceClient - Bridge between PHP app and Go GPS/Dispatch microservice
 *
 * Replaces direct Redis GEOADD and SQL Haversine queries with Go service calls.
 * The Go service handles: GPS ingestion, driver search, dispatch, surge pricing, WebSocket.
 *
 * Usage:
 *   $go = GoServiceClient::getInstance();
 *   $go->updateDriverGPS($driverId, $lat, $lng, $heading, $speed, $rideId);
 *   $drivers = $go->findNearbyDrivers($lat, $lng, 5.0, 'standard');
 *   $result = $go->dispatch($rideId, $passengerId, $originLat, $originLng, $destLat, $destLng);
 */

class GoServiceClient
{
    private static ?GoServiceClient $instance = null;
    private string $baseUrl;
    private int $timeout;

    private function __construct()
    {
        // Go service runs on localhost:8500 on every server
        $this->baseUrl = getenv('GO_SERVICE_URL') ?: 'http://127.0.0.1:8500';
        $this->timeout = 5; // seconds
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Update driver GPS position
     * Replaces: redisUpdateDriverLocation() + SQL INSERT into tracking tables
     * Go service handles: Redis GEOADD + H3 indexing + write-behind to PostgreSQL
     *
     * @return array{status: string, h3: string, ts: int}
     */
    public function updateDriverGPS(
        int $driverId,
        float $lat,
        float $lng,
        float $heading = 0,
        float $speed = 0,
        ?int $rideId = null,
        float $accuracy = 0,
        float $battery = 0
    ): array {
        return $this->post('/gps/update', [
            'driver_id' => $driverId,
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'speed' => $speed,
            'ride_id' => $rideId ?? 0,
            'accuracy' => $accuracy,
            'battery' => $battery,
        ]);
    }

    /**
     * Batch upload GPS points (for offline buffer sync)
     *
     * @param array $points Array of GPS points
     * @return array{status: string, processed: int}
     */
    public function batchGPS(array $points): array
    {
        return $this->post('/gps/batch', $points);
    }

    /**
     * Find nearby available drivers
     * Replaces: redisFindNearbyDrivers() using GEORADIUS
     * Go service uses: Redis GEOSEARCH + pipeline metadata fetch + filtering
     *
     * @return array{drivers: array, count: int}
     */
    public function findNearbyDrivers(
        float $lat,
        float $lng,
        float $radiusKm = 5.0,
        ?string $vehicleType = null
    ): array {
        $params = [
            'lat' => $lat,
            'lng' => $lng,
            'radius' => $radiusKm,
        ];
        if ($vehicleType) {
            $params['vehicle_type'] = $vehicleType;
        }
        return $this->get('/drivers/nearby', $params);
    }

    /**
     * Dispatch - find and rank best drivers for a ride
     * Replaces: BoraUmDisco::processarNovaCorrida() with SQL Haversine
     * Go service uses: Redis GEOSEARCH + H3 surge + weighted scoring
     *
     * @return array{status: string, drivers: array, count: int}
     */
    public function dispatch(
        int $rideId,
        int $passengerId,
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng,
        ?string $vehicleType = null,
        bool $preferFemale = false,
        bool $needsPet = false,
        bool $needsAccessibility = false,
        bool $isDelivery = false,
        ?int $favoriteDriver = null
    ): array {
        return $this->post('/dispatch/find', [
            'ride_id' => $rideId,
            'passenger_id' => $passengerId,
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'dest_lat' => $destLat,
            'dest_lng' => $destLng,
            'vehicle_type' => $vehicleType ?? '',
            'prefer_female' => $preferFemale,
            'needs_pet' => $needsPet,
            'needs_accessibility' => $needsAccessibility,
            'is_delivery' => $isDelivery,
            'favorite_driver' => $favoriteDriver ?? 0,
        ]);
    }

    /**
     * Get surge pricing for a location
     * Replaces: PHP surge calculation with SQL queries
     * Go service uses: H3 cell demand/supply ratio
     *
     * @return array{surge_rate: float, cell: string}
     */
    public function getSurge(float $lat, float $lng): array
    {
        return $this->post('/dispatch/surge', [
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    /**
     * Mark driver as online
     * Replaces: Redis GEOADD + hash set in redis.php
     */
    public function driverOnline(
        int $driverId,
        float $lat,
        float $lng,
        string $vehicleType = 'standard',
        float $rating = 5.0,
        string $gender = 'M',
        bool $acceptsPet = false,
        float $acceptRate = 0.9,
        bool $accessibility = false,
        bool $isExecutive = false
    ): array {
        return $this->post('/driver/online', [
            'driver_id' => $driverId,
            'lat' => $lat,
            'lng' => $lng,
            'vehicle_type' => $vehicleType,
            'rating' => $rating,
            'gender' => $gender,
            'aceita_pet' => $acceptsPet,
            'is_available' => true,
            'accept_rate' => $acceptRate,
            'accessibility' => $accessibility,
            'is_executive' => $isExecutive,
        ]);
    }

    /**
     * Mark driver as offline
     * Replaces: Redis ZREM + DEL in redis.php
     */
    public function driverOffline(int $driverId): array
    {
        return $this->post('/driver/offline', [
            'driver_id' => $driverId,
        ]);
    }

    /**
     * Get Go service metrics
     */
    public function getMetrics(): array
    {
        return $this->get('/metrics');
    }

    /**
     * Check Go service health
     */
    public function isHealthy(): bool
    {
        try {
            $result = $this->get('/health');
            return ($result['status'] ?? '') === 'healthy';
        } catch (\Exception $e) {
            return false;
        }
    }

    // === HTTP helpers ===

    private function post(string $path, array $data): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("[GoService] POST $path failed: $error");
            return ['status' => 'error', 'error' => $error];
        }

        if ($httpCode >= 400) {
            error_log("[GoService] POST $path HTTP $httpCode: $response");
            return ['status' => 'error', 'http_code' => $httpCode, 'response' => $response];
        }

        return json_decode($response, true) ?? ['status' => 'error', 'raw' => $response];
    }

    private function get(string $path, array $params = []): array
    {
        $url = $this->baseUrl . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("[GoService] GET $path failed: $error");
            return ['status' => 'error', 'error' => $error];
        }

        return json_decode($response, true) ?? ['status' => 'error', 'raw' => $response];
    }
}
