<?php
/**
 * API: Calcular Tarifa da Corrida
 * POST /api/transporte/corrida/calcular.php
 * 
 * Body: {
 *   "origem_lat": -23.5505,
 *   "origem_lng": -46.6333,
 *   "destino_lat": -23.5629,
 *   "destino_lng": -46.6544,
 *   "tipo_veiculo": "economico",
 *   "cupom": "BEMVINDO"
 * }
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

function calcularDistancia($lat1, $lng1, $lat2, $lng2) {
    $R = 6371; // Raio da Terra em km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

function estimarTempo($distancia_km) {
    // Velocidade média urbana: 25 km/h
    return ceil(($distancia_km / 25) * 60);
}

try {
    $input = getInput();
    $db = getDB();

    $origem_lat = $input["origem_lat"] ?? null;
    $origem_lng = $input["origem_lng"] ?? null;
    $destino_lat = $input["destino_lat"] ?? null;
    $destino_lng = $input["destino_lng"] ?? null;
    $tipo_veiculo = $input["tipo_veiculo"] ?? "economico";
    $cupom_codigo = $input["cupom"] ?? null;

    if (!$origem_lat || !$origem_lng || !$destino_lat || !$destino_lng) {
        response(false, null, "Coordenadas obrigatórias", 400);
    }

    // Calcular distância
    $distancia = calcularDistancia($origem_lat, $origem_lng, $destino_lat, $destino_lng);
    $tempo_estimado = estimarTempo($distancia);

    // Buscar tarifa (com cache de 1 hora)
    $tarifa = CacheHelper::remember("tarifa_{$tipo_veiculo}", 3600, function() use ($db, $tipo_veiculo) {
        $stmt = $db->prepare("SELECT * FROM boraum_tarifas
                              WHERE tipo_veiculo = ? AND ativo = 1
                              ORDER BY cidade IS NULL LIMIT 1");
        $stmt->execute([$tipo_veiculo]);
        return $stmt->fetch();
    });
    
    if (!$tarifa) {
        // Tarifa padrão
        $tarifa = [
            "bandeirada" => 5.00,
            "km_valor" => 1.80,
            "minuto_valor" => 0.40,
            "valor_minimo" => 8.00,
            "multiplicador_noturno" => 1.30,
            "comissao_plataforma" => 20
        ];
    }
    
    // Calcular valor
    $valor_km = $distancia * $tarifa["km_valor"];
    $valor_tempo = $tempo_estimado * $tarifa["minuto_valor"];
    $valor_total = $tarifa["bandeirada"] + $valor_km + $valor_tempo;
    
    // Aplicar mínimo
    $valor_total = max($valor_total, $tarifa["valor_minimo"]);
    
    // Multiplicador noturno (23h - 6h)
    $hora = (int) date("H");
    $multiplicador = 1.0;
    if ($hora >= 23 || $hora < 6) {
        $multiplicador = $tarifa["multiplicador_noturno"];
        $valor_total *= $multiplicador;
    }
    
    // Aplicar cupom
    $desconto = 0;
    $cupom_valido = null;
    if ($cupom_codigo) {
        $stmt = $db->prepare("SELECT * FROM boraum_cupons
                             WHERE codigo = ? AND ativo = 1
                             AND (data_inicio IS NULL OR data_inicio <= NOW())
                             AND (data_fim IS NULL OR data_fim >= NOW())");
        $stmt->execute([$cupom_codigo]);
        $cupom = $stmt->fetch();

        if ($cupom) {
            if ($cupom["tipo"] == "percentual") {
                $desconto = $valor_total * ($cupom["valor"] / 100);
            } else {
                $desconto = $cupom["valor"];
            }
            $cupom_valido = $cupom;
        }
    }
    
    $valor_final = max($valor_total - $desconto, $tarifa["valor_minimo"]);
    
    response(true, [
        "distancia_km" => round($distancia, 2),
        "tempo_estimado_min" => $tempo_estimado,
        "valor_bruto" => round($valor_total, 2),
        "desconto" => round($desconto, 2),
        "valor_final" => round($valor_final, 2),
        "tipo_veiculo" => $tipo_veiculo,
        "multiplicador" => $multiplicador,
        "cupom" => $cupom_valido ? [
            "codigo" => $cupom_valido["codigo"],
            "desconto" => round($desconto, 2)
        ] : null,
        "tarifa" => [
            "bandeirada" => $tarifa["bandeirada"],
            "km" => $tarifa["km_valor"],
            "minuto" => $tarifa["minuto_valor"]
        ]
    ]);
    
} catch (Exception $e) {
    error_log("[transporte/corrida/calcular] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
