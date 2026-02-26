<?php
/**
 * API Cálculo de Frete - OneMundo
 *
 * POST /api/shipping/calculate.php - Calcular opções de frete
 *
 * Parâmetros:
 * - cep: CEP de destino (obrigatório)
 * - products: Array de produtos [{id, quantity}] (obrigatório)
 *
 * Retorna múltiplas opções de frete (PAC, SEDEX, etc.)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../database.php';
    require_once __DIR__ . '/../cache/CacheHelper.php';
    require_once __DIR__ . '/../rate-limit/RateLimiter.php';

    // Rate limit
    RateLimiter::check(30, 60);

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $cep = preg_replace('/\D/', '', $input['cep'] ?? '');
    $products = $input['products'] ?? [];

    // Validações
    if (empty($cep) || strlen($cep) !== 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'CEP inválido. Informe 8 dígitos.']);
        exit;
    }

    if (empty($products) || !is_array($products)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Lista de produtos é obrigatória']);
        exit;
    }

    $pdo = getConnection();

    // CEP de origem (configurável)
    $cepOrigem = defined('STORE_CEP') ? STORE_CEP : '01310100'; // SP - Paulista

    // Calcular peso e dimensões totais
    $totalWeight = 0;
    $maxLength = 0;
    $maxWidth = 0;
    $totalHeight = 0;
    $totalValue = 0;

    $productIds = array_column($products, 'id');
    $quantities = array_column($products, 'quantity', 'id');

    if (empty($productIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Produtos inválidos']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("
        SELECT product_id, price, weight, length, width, height
        FROM " . DB_PREFIX . "product
        WHERE product_id IN ($placeholders) AND status = 1
    ");
    $stmt->execute($productIds);
    $dbProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dbProducts as $product) {
        $qty = (int)($quantities[$product['product_id']] ?? 1);

        $weight = (float)$product['weight'] ?: 0.3; // Mínimo 300g
        $length = (float)$product['length'] ?: 16;  // Mínimo 16cm
        $width = (float)$product['width'] ?: 11;    // Mínimo 11cm
        $height = (float)$product['height'] ?: 2;   // Mínimo 2cm

        $totalWeight += $weight * $qty;
        $maxLength = max($maxLength, $length);
        $maxWidth = max($maxWidth, $width);
        $totalHeight += $height * $qty;
        $totalValue += (float)$product['price'] * $qty;
    }

    // Garantir dimensões mínimas dos Correios
    $totalWeight = max(0.3, $totalWeight);
    $maxLength = max(16, min(105, $maxLength));
    $maxWidth = max(11, min(105, $maxWidth));
    $totalHeight = max(2, min(105, $totalHeight));

    // Verificar cache
    $cacheKey = "shipping_{$cep}_{$totalWeight}_{$maxLength}_{$maxWidth}_{$totalHeight}";
    $cached = CacheHelper::get($cacheKey);

    if ($cached) {
        echo json_encode($cached);
        exit;
    }

    // Calcular frete (simulação - em produção usar API dos Correios)
    $shippingOptions = [];

    // Distância baseada no CEP (simplificado)
    $distance = calculateDistance($cepOrigem, $cep);

    // PAC
    $pacDays = ceil($distance / 500) + 3; // Base de 3 dias + distância
    $pacPrice = 15 + ($totalWeight * 2) + ($distance / 100);

    if ($totalValue >= 200) {
        $pacPrice *= 0.9; // 10% desconto para compras maiores
    }

    $shippingOptions[] = [
        'id' => 'pac',
        'name' => 'PAC',
        'carrier' => 'Correios',
        'description' => 'Entrega econômica',
        'price' => round($pacPrice, 2),
        'price_formatted' => 'R$ ' . number_format($pacPrice, 2, ',', '.'),
        'days' => $pacDays,
        'delivery_estimate' => "até {$pacDays} dias úteis",
        'delivery_date' => date('Y-m-d', strtotime("+{$pacDays} weekdays"))
    ];

    // SEDEX
    $sedexDays = max(1, ceil($distance / 1000) + 1);
    $sedexPrice = 25 + ($totalWeight * 4) + ($distance / 50);

    if ($totalValue >= 300) {
        $sedexPrice *= 0.85; // 15% desconto
    }

    $shippingOptions[] = [
        'id' => 'sedex',
        'name' => 'SEDEX',
        'carrier' => 'Correios',
        'description' => 'Entrega expressa',
        'price' => round($sedexPrice, 2),
        'price_formatted' => 'R$ ' . number_format($sedexPrice, 2, ',', '.'),
        'days' => $sedexDays,
        'delivery_estimate' => "até {$sedexDays} dias úteis",
        'delivery_date' => date('Y-m-d', strtotime("+{$sedexDays} weekdays"))
    ];

    // Frete Grátis (para compras acima de R$ 299)
    if ($totalValue >= 299) {
        array_unshift($shippingOptions, [
            'id' => 'free',
            'name' => 'Frete Grátis',
            'carrier' => 'OneMundo',
            'description' => 'Compras acima de R$ 299',
            'price' => 0,
            'price_formatted' => 'Grátis',
            'days' => $pacDays + 2,
            'delivery_estimate' => "até " . ($pacDays + 2) . " dias úteis",
            'delivery_date' => date('Y-m-d', strtotime("+" . ($pacDays + 2) . " weekdays")),
            'highlight' => true
        ]);
    }

    // Retirada na loja (se disponível)
    $shippingOptions[] = [
        'id' => 'pickup',
        'name' => 'Retirar na Loja',
        'carrier' => 'OneMundo',
        'description' => 'Av. Paulista, 1000 - São Paulo',
        'price' => 0,
        'price_formatted' => 'Grátis',
        'days' => 0,
        'delivery_estimate' => 'Disponível em 2h',
        'delivery_date' => date('Y-m-d')
    ];

    // Ordenar por preço
    usort($shippingOptions, function($a, $b) {
        return $a['price'] <=> $b['price'];
    });

    $response = [
        'success' => true,
        'cep' => $cep,
        'cep_formatted' => substr($cep, 0, 5) . '-' . substr($cep, 5),
        'total_weight' => $totalWeight,
        'total_value' => $totalValue,
        'shipping_options' => $shippingOptions,
        'cheapest' => $shippingOptions[0]['id'],
        'fastest' => 'sedex'
    ];

    // Cache por 1 hora
    CacheHelper::set($cacheKey, $response, 3600);

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("Shipping API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao calcular frete']);
} catch (Exception $e) {
    http_response_code(500);
    error_log("[shipping/calculate] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}

/**
 * Calcula distância aproximada entre CEPs
 * (Simplificado - em produção usar API de geocoding)
 */
function calculateDistance($cep1, $cep2) {
    // Regiões por prefixo do CEP
    $regions = [
        '0' => ['lat' => -23.55, 'lng' => -46.63], // SP Capital
        '1' => ['lat' => -23.55, 'lng' => -46.63], // SP Capital
        '2' => ['lat' => -22.90, 'lng' => -43.17], // RJ
        '3' => ['lat' => -19.91, 'lng' => -43.93], // MG
        '4' => ['lat' => -12.97, 'lng' => -38.50], // BA
        '5' => ['lat' => -8.05, 'lng' => -34.88],  // PE
        '6' => ['lat' => -3.71, 'lng' => -38.54],  // CE
        '7' => ['lat' => -15.78, 'lng' => -47.92], // DF
        '8' => ['lat' => -25.43, 'lng' => -49.27], // PR
        '9' => ['lat' => -30.03, 'lng' => -51.23]  // RS
    ];

    $region1 = $regions[$cep1[0]] ?? $regions['0'];
    $region2 = $regions[$cep2[0]] ?? $regions['0'];

    // Fórmula de Haversine simplificada
    $latDiff = abs($region1['lat'] - $region2['lat']);
    $lngDiff = abs($region1['lng'] - $region2['lng']);

    $distance = sqrt(pow($latDiff * 111, 2) + pow($lngDiff * 85, 2)); // km aproximados

    return round($distance);
}
