<?php
/**
 * API: Validar Seguro Obrigatório no Checkout
 *
 * POST /api/checkout/validar-seguro.php
 *
 * Verifica se seguro é obrigatório baseado em:
 * - Valor total do pedido
 * - Distância até ponto de apoio mais próximo
 * - Tipo de produtos (eletrônicos, jóias, etc.)
 *
 * Retorna valor do seguro e se é obrigatório
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(dirname(__DIR__)) . '/config.php';

// Configurações de seguro
define('INSURANCE_CONFIG', [
    // Valor mínimo para seguro obrigatório (sem ponto de apoio)
    'min_value_remote' => 1000.00,

    // Valor mínimo para seguro obrigatório (com ponto de apoio distante > 50km)
    'min_value_distant' => 2000.00,

    // Valor mínimo para oferecer seguro opcional
    'min_value_optional' => 500.00,

    // Taxa do seguro (% do valor)
    'rate_standard' => 2.5,    // 2.5% para produtos padrão
    'rate_electronics' => 3.5,  // 3.5% para eletrônicos
    'rate_jewelry' => 5.0,      // 5.0% para jóias

    // Distância máxima para considerar "com cobertura" (km)
    'max_support_distance' => 50,

    // Categorias de alto valor
    'high_value_categories' => [
        'electronics' => ['smartphone', 'celular', 'notebook', 'tablet', 'tv', 'console', 'camera', 'iphone', 'samsung', 'xiaomi', 'macbook', 'playstation', 'xbox', 'airpods'],
        'jewelry' => ['joia', 'relogio', 'ouro', 'prata', 'diamante', 'anel', 'colar']
    ]
]);

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $input = json_decode(file_get_contents('php://input'), true);

    // Dados do pedido
    $orderValue = (float)($input['order_value'] ?? 0);
    $products = $input['products'] ?? [];
    $customerLat = (float)($input['latitude'] ?? 0);
    $customerLng = (float)($input['longitude'] ?? 0);
    $city = trim($input['city'] ?? '');
    $state = strtoupper(trim($input['state'] ?? ''));

    if ($orderValue <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valor do pedido obrigatório']);
        exit;
    }

    // 1. Buscar ponto de apoio mais próximo
    $supportPointDistance = null;
    $hasSupportPoint = false;

    if ($customerLat && $customerLng) {
        // Buscar na tabela de pontos de apoio (se existir)
        try {
            $stmt = $pdo->prepare("
                SELECT
                    (6371 * acos(
                        cos(radians(?)) * cos(radians(latitude)) *
                        cos(radians(longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(latitude))
                    )) AS distance_km
                FROM om_support_points
                WHERE is_active = 1
                ORDER BY distance_km ASC
                LIMIT 1
            ");
            $stmt->execute([$customerLat, $customerLng, $customerLat]);
            $nearest = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($nearest) {
                $supportPointDistance = round($nearest['distance_km'], 1);
                $hasSupportPoint = $supportPointDistance <= INSURANCE_CONFIG['max_support_distance'];
            }
        } catch (PDOException $e) {
            // Tabela não existe, considerar sem ponto de apoio
            $hasSupportPoint = false;
        }
    }

    // 2. Detectar categoria dos produtos
    $productCategory = 'standard';
    $categoryMultiplier = INSURANCE_CONFIG['rate_standard'];

    foreach ($products as $product) {
        $productName = strtolower($product['name'] ?? '');

        // Verificar se é eletrônico
        foreach (INSURANCE_CONFIG['high_value_categories']['electronics'] as $keyword) {
            if (strpos($productName, $keyword) !== false) {
                $productCategory = 'electronics';
                $categoryMultiplier = max($categoryMultiplier, INSURANCE_CONFIG['rate_electronics']);
                break;
            }
        }

        // Verificar se é jóia
        foreach (INSURANCE_CONFIG['high_value_categories']['jewelry'] as $keyword) {
            if (strpos($productName, $keyword) !== false) {
                $productCategory = 'jewelry';
                $categoryMultiplier = max($categoryMultiplier, INSURANCE_CONFIG['rate_jewelry']);
                break;
            }
        }
    }

    // 3. Determinar se seguro é obrigatório
    $insuranceRequired = false;
    $reason = '';

    if (!$hasSupportPoint) {
        // Sem ponto de apoio próximo
        if ($orderValue >= INSURANCE_CONFIG['min_value_remote']) {
            $insuranceRequired = true;
            $reason = "Sua região não possui ponto de apoio próximo. Para pedidos acima de R$ " .
                      number_format(INSURANCE_CONFIG['min_value_remote'], 2, ',', '.') .
                      ", o seguro é obrigatório para sua proteção.";
        }
    } elseif ($supportPointDistance > 30) {
        // Ponto de apoio distante (30-50km)
        if ($orderValue >= INSURANCE_CONFIG['min_value_distant']) {
            $insuranceRequired = true;
            $reason = "O ponto de apoio mais próximo está a {$supportPointDistance}km. " .
                      "Para pedidos de alto valor, recomendamos fortemente o seguro.";
        }
    }

    // Forçar seguro para jóias de alto valor
    if ($productCategory === 'jewelry' && $orderValue >= 500) {
        $insuranceRequired = true;
        $reason = "Para produtos de joalheria, o seguro é obrigatório para sua proteção.";
    }

    // 4. Calcular valor do seguro
    $insuranceValue = round($orderValue * ($categoryMultiplier / 100), 2);
    $minInsurance = 5.00; // Mínimo de R$ 5,00
    $insuranceValue = max($insuranceValue, $minInsurance);

    // 5. Montar resposta
    $response = [
        'success' => true,
        'insurance' => [
            'required' => $insuranceRequired,
            'reason' => $reason,
            'value' => $insuranceValue,
            'rate_percent' => $categoryMultiplier,
            'coverage_value' => $orderValue,
            'product_category' => $productCategory
        ],
        'delivery_info' => [
            'has_support_point' => $hasSupportPoint,
            'nearest_support_km' => $supportPointDistance,
            'city' => $city,
            'state' => $state
        ]
    ];

    // Mensagem amigável
    if ($insuranceRequired) {
        $response['message'] = "Seguro obrigatório: R$ " . number_format($insuranceValue, 2, ',', '.');
        $response['alert'] = [
            'type' => 'warning',
            'title' => 'Seguro de Entrega Obrigatório',
            'message' => $reason,
            'action' => 'O valor do seguro será adicionado automaticamente ao seu pedido.'
        ];
    } elseif ($orderValue >= INSURANCE_CONFIG['min_value_optional']) {
        $response['message'] = "Seguro opcional disponível: R$ " . number_format($insuranceValue, 2, ',', '.');
        $response['suggestion'] = [
            'type' => 'info',
            'title' => 'Proteja sua Compra',
            'message' => "Por apenas R$ " . number_format($insuranceValue, 2, ',', '.') .
                        " você garante cobertura total contra danos e extravios.",
            'action' => 'Adicionar Seguro'
        ];
    } else {
        $response['message'] = "Seguro não aplicável para este pedido.";
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    error_log("[checkout/validar-seguro] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
