<?php
/**
 * API - Mapa de Cobertura
 *
 * GET /api/cobertura/mapa.php - Retorna áreas atendidas
 * GET /api/cobertura/mapa.php?lat=X&lng=Y - Verifica cobertura em coordenada
 * GET /api/cobertura/mapa.php?city=X&state=Y - Verifica cobertura em cidade
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(dirname(__DIR__)) . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Criar tabela de áreas de cobertura
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_coverage_areas (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        city VARCHAR(100) NOT NULL,
        state CHAR(2) NOT NULL,
        service_type VARCHAR(20) DEFAULT 'ambos',
        center_lat DECIMAL(10, 8) NOT NULL,
        center_lng DECIMAL(11, 8) NOT NULL,
        radius_km DECIMAL(5, 2) DEFAULT 10.00,
        polygon_coords JSON DEFAULT NULL,
        is_active SMALLINT DEFAULT 1,
        drivers_count INT DEFAULT 0,
        shoppers_count INT DEFAULT 0,
        avg_wait_minutes INT DEFAULT 15,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_coverage_city_state ON om_coverage_areas(city, state)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_coverage_coords ON om_coverage_areas(center_lat, center_lng)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_coverage_active ON om_coverage_areas(is_active)");

    // Inserir algumas áreas de exemplo se tabela vazia
    $count = $pdo->query("SELECT COUNT(*) FROM om_coverage_areas")->fetchColumn();
    if ($count == 0) {
        $pdo->exec("INSERT INTO om_coverage_areas (name, city, state, service_type, center_lat, center_lng, radius_km, drivers_count, shoppers_count) VALUES
            ('Boa Vista Centro', 'Boa Vista', 'RR', 'ambos', 2.8235, -60.6758, 15, 12, 8),
            ('Manaus Centro', 'Manaus', 'AM', 'ambos', -3.1190, -60.0217, 20, 45, 32),
            ('Manaus Zona Norte', 'Manaus', 'AM', 'transporte', -3.0500, -60.0100, 12, 15, 0),
            ('Belém Centro', 'Belém', 'PA', 'ambos', -1.4558, -48.4902, 18, 38, 25),
            ('Macapá Centro', 'Macapá', 'AP', 'ambos', 0.0356, -51.0705, 12, 8, 5)
        ");
    }

    // ========== Verificar cobertura por coordenadas ==========
    if (isset($_GET['lat']) && isset($_GET['lng'])) {
        $lat = (float)$_GET['lat'];
        $lng = (float)$_GET['lng'];
        $serviceType = $_GET['service'] ?? 'transporte';

        // Fórmula de Haversine para calcular distância
        $stmt = $pdo->prepare("
            SELECT *,
                   (6371 * acos(
                       cos(radians(?)) * cos(radians(center_lat)) *
                       cos(radians(center_lng) - radians(?)) +
                       sin(radians(?)) * sin(radians(center_lat))
                   )) AS distance_km
            FROM om_coverage_areas
            WHERE is_active = 1
              AND (service_type = ? OR service_type = 'ambos')
            HAVING distance_km <= radius_km
            ORDER BY distance_km ASC
            LIMIT 1
        ");
        $stmt->execute([$lat, $lng, $lat, $serviceType]);
        $area = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($area) {
            echo json_encode([
                'success' => true,
                'covered' => true,
                'area' => [
                    'name' => $area['name'],
                    'city' => $area['city'],
                    'state' => $area['state'],
                    'distance_km' => round($area['distance_km'], 2),
                    'drivers_available' => (int)$area['drivers_count'],
                    'shoppers_available' => (int)$area['shoppers_count'],
                    'avg_wait_minutes' => (int)$area['avg_wait_minutes']
                ],
                'message' => "Ótimo! Atendemos essa região."
            ]);
        } else {
            // Buscar área mais próxima
            $stmt = $pdo->prepare("
                SELECT city, state,
                       (6371 * acos(
                           cos(radians(?)) * cos(radians(center_lat)) *
                           cos(radians(center_lng) - radians(?)) +
                           sin(radians(?)) * sin(radians(center_lat))
                       )) AS distance_km
                FROM om_coverage_areas
                WHERE is_active = 1
                ORDER BY distance_km ASC
                LIMIT 1
            ");
            $stmt->execute([$lat, $lng, $lat]);
            $nearest = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'covered' => false,
                'nearest_city' => $nearest ? $nearest['city'] . '/' . $nearest['state'] : null,
                'distance_to_nearest_km' => $nearest ? round($nearest['distance_km'], 1) : null,
                'message' => "Ainda não atendemos essa região. " .
                    ($nearest ? "A área mais próxima é {$nearest['city']}/{$nearest['state']} ({$nearest['distance_km']} km)." : "")
            ]);
        }
        exit;
    }

    // ========== Verificar cobertura por cidade ==========
    if (isset($_GET['city'])) {
        $city = trim($_GET['city']);
        $state = isset($_GET['state']) ? strtoupper(trim($_GET['state'])) : null;
        $serviceType = $_GET['service'] ?? null;

        $where = "is_active = 1 AND city LIKE ?";
        $params = ["%{$city}%"];

        if ($state) {
            $where .= " AND state = ?";
            $params[] = $state;
        }

        if ($serviceType && in_array($serviceType, ['mercado', 'transporte'])) {
            $where .= " AND (service_type = ? OR service_type = 'ambos')";
            $params[] = $serviceType;
        }

        $stmt = $pdo->prepare("SELECT * FROM om_coverage_areas WHERE $where ORDER BY name");
        $stmt->execute($params);
        $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($areas)) {
            echo json_encode([
                'success' => true,
                'covered' => true,
                'areas' => array_map(function($a) {
                    return [
                        'name' => $a['name'],
                        'city' => $a['city'],
                        'state' => $a['state'],
                        'service_type' => $a['service_type'],
                        'radius_km' => (float)$a['radius_km'],
                        'drivers' => (int)$a['drivers_count'],
                        'shoppers' => (int)$a['shoppers_count'],
                        'center' => [
                            'lat' => (float)$a['center_lat'],
                            'lng' => (float)$a['center_lng']
                        ]
                    ];
                }, $areas),
                'message' => "Encontramos " . count($areas) . " área(s) de cobertura em {$city}."
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'covered' => false,
                'areas' => [],
                'message' => "Ainda não atendemos {$city}. Cadastre-se para ser notificado quando chegarmos!"
            ]);
        }
        exit;
    }

    // ========== Listar todas as áreas de cobertura ==========
    $stmt = $pdo->query("
        SELECT id, name, city, state, service_type, center_lat, center_lng, radius_km,
               drivers_count, shoppers_count, avg_wait_minutes
        FROM om_coverage_areas
        WHERE is_active = 1
        ORDER BY state, city, name
    ");
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar por estado
    $byState = [];
    foreach ($areas as $area) {
        $state = $area['state'];
        if (!isset($byState[$state])) {
            $byState[$state] = [];
        }
        $byState[$state][] = [
            'id' => (int)$area['id'],
            'name' => $area['name'],
            'city' => $area['city'],
            'service_type' => $area['service_type'],
            'center' => [
                'lat' => (float)$area['center_lat'],
                'lng' => (float)$area['center_lng']
            ],
            'radius_km' => (float)$area['radius_km'],
            'drivers' => (int)$area['drivers_count'],
            'shoppers' => (int)$area['shoppers_count'],
            'avg_wait' => (int)$area['avg_wait_minutes']
        ];
    }

    // Estatísticas
    $totalDrivers = array_sum(array_column($areas, 'drivers_count'));
    $totalShoppers = array_sum(array_column($areas, 'shoppers_count'));
    $citiesCount = count(array_unique(array_map(fn($a) => $a['city'].$a['state'], $areas)));

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_areas' => count($areas),
            'cities_covered' => $citiesCount,
            'states_covered' => count($byState),
            'total_drivers' => $totalDrivers,
            'total_shoppers' => $totalShoppers
        ],
        'coverage_by_state' => $byState
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("[cobertura/mapa] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
