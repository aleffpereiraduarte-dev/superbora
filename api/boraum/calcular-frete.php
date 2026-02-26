<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Calcular Frete BoraUm (Entrega Local)
 * GET /api/boraum/calcular-frete.php?origem_lat=X&origem_lng=Y&destino_lat=X&destino_lng=Y
 * ══════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once dirname(__DIR__, 2) . '/database.php';

try {
    $pdo = getDB();

    // Aceitar GET ou POST
    $input = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (json_decode(file_get_contents('php://input'), true) ?: $_POST)
        : $_GET;

    $origem_lat = floatval($input['origem_lat'] ?? 0);
    $origem_lng = floatval($input['origem_lng'] ?? 0);
    $destino_lat = floatval($input['destino_lat'] ?? 0);
    $destino_lng = floatval($input['destino_lng'] ?? 0);
    $cidade = $input['cidade'] ?? 'São Paulo';

    if (!$origem_lat || !$origem_lng || !$destino_lat || !$destino_lng) {
        echo json_encode(['success' => false, 'error' => 'Coordenadas obrigatórias: origem_lat, origem_lng, destino_lat, destino_lng']);
        exit;
    }

    // Calcular distância (Haversine)
    $R = 6371; // km
    $dLat = deg2rad($destino_lat - $origem_lat);
    $dLng = deg2rad($destino_lng - $origem_lng);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($origem_lat)) * cos(deg2rad($destino_lat)) * sin($dLng/2) * sin($dLng/2);
    $distancia_km = $R * 2 * atan2(sqrt($a), sqrt(1-$a));

    // Buscar tarifas ativas
    $stmt = $pdo->prepare("
        SELECT * FROM boraum_tarifas
        WHERE ativo = 1
        ORDER BY bandeirada ASC
    ");
    $stmt->execute();
    $tarifas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tarifas)) {
        echo json_encode(['success' => false, 'error' => 'Nenhuma tarifa configurada']);
        exit;
    }

    // Calcular preço para cada tipo
    $opcoes = [];
    foreach ($tarifas as $t) {
        $km_cobrado = max($distancia_km, floatval($t['km_minimo']));
        $preco = floatval($t['bandeirada']) + ($km_cobrado * floatval($t['preco_km']));
        $preco = max($preco, floatval($t['valor_minimo']));

        // Tempo estimado (média 30km/h em cidade)
        $tempo_min = ceil(($distancia_km / 30) * 60);
        $tempo_min = max($tempo_min, 5); // Mínimo 5 minutos

        $opcoes[] = [
            'tipo' => $t['tipo_veiculo'],
            'nome' => ucfirst($t['tipo_veiculo']),
            'preco' => round($preco, 2),
            'preco_formatado' => 'R$ ' . number_format($preco, 2, ',', '.'),
            'tempo_estimado_min' => $tempo_min,
            'tempo_formatado' => $tempo_min . ' min',
            'distancia_km' => round($distancia_km, 1),
            'detalhes' => [
                'bandeirada' => floatval($t['bandeirada']),
                'preco_km' => floatval($t['preco_km']),
                'km_minimo' => floatval($t['km_minimo'])
            ]
        ];
    }

    echo json_encode([
        'success' => true,
        'distancia_km' => round($distancia_km, 2),
        'opcoes' => $opcoes,
        'origem' => [
            'lat' => $origem_lat,
            'lng' => $origem_lng
        ],
        'destino' => [
            'lat' => $destino_lat,
            'lng' => $destino_lng
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[boraum/calcular-frete] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
