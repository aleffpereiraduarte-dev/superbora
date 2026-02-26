<?php
/**
 * API: Solicitar Corrida (Passageiro)
 * POST /api/transporte/corrida/solicitar.php
 * 
 * Body: {
 *   "passageiro_id": 1,
 *   "origem_lat": -23.5505,
 *   "origem_lng": -46.6333,
 *   "origem_endereco": "Av Paulista, 1000",
 *   "destino_lat": -23.5629,
 *   "destino_lng": -46.6544,
 *   "destino_endereco": "Rua Augusta, 500",
 *   "tipo_veiculo": "economico",
 *   "forma_pagamento": "pix",
 *   "cupom": "BEMVINDO"
 * }
 */

require_once __DIR__ . "/../config/database.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

function calcularDistancia($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

function buscarMotoristasProximos($db, $lat, $lng, $tipo_veiculo, $raio_km = 5) {
    // Fórmula de Haversine no SQL
    $sql = "SELECT id, nome, foto, nota_media as nota, lat, lng, telefone,
            veiculo_modelo, veiculo_placa, veiculo_cor,
            (6371 * acos(cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat)))) AS distancia
            FROM boraum_motoristas
            WHERE online = 1
            AND status = 'aprovado'
            AND lat IS NOT NULL
            HAVING distancia <= ?
            ORDER BY distancia ASC
            LIMIT 10";

    $stmt = $db->prepare($sql);
    $stmt->execute([$lat, $lng, $lat, $raio_km]);
    return $stmt->fetchAll();
}

try {
    $input = getInput();
    $db = getDB();
    
    // Validar dados
    $passageiro_id = $input["passageiro_id"] ?? 0;
    $origem_lat = $input["origem_lat"] ?? null;
    $origem_lng = $input["origem_lng"] ?? null;
    $destino_lat = $input["destino_lat"] ?? null;
    $destino_lng = $input["destino_lng"] ?? null;
    $origem_endereco = $input["origem_endereco"] ?? "";
    $destino_endereco = $input["destino_endereco"] ?? "";
    $tipo_veiculo = $input["tipo_veiculo"] ?? "economico";
    $forma_pagamento = $input["forma_pagamento"] ?? "pix";
    $cupom_codigo = $input["cupom"] ?? null;
    
    if (!$origem_lat || !$origem_lng || !$destino_lat || !$destino_lng) {
        response(false, null, "Origem e destino obrigatórios", 400);
    }
    
    // Calcular distância e tempo
    $distancia = calcularDistancia($origem_lat, $origem_lng, $destino_lat, $destino_lng);
    $tempo_estimado = ceil(($distancia / 25) * 60);
    
    // Buscar tarifa (prepared statement)
    $stmtTarifa = $db->prepare("SELECT * FROM boraum_tarifas WHERE tipo_veiculo = ? AND ativo = 1 ORDER BY cidade IS NULL LIMIT 1");
    $stmtTarifa->execute([$tipo_veiculo]);
    $tarifa = $stmtTarifa->fetch();

    if (!$tarifa) {
        $tarifa = ["bandeirada" => 5.00, "km_valor" => 1.80, "minuto_valor" => 0.40, "valor_minimo" => 8.00, "comissao_plataforma" => 20];
    }

    // Calcular valor
    $valor_total = $tarifa["bandeirada"] + ($distancia * $tarifa["km_valor"]) + ($tempo_estimado * $tarifa["minuto_valor"]);
    $valor_total = max($valor_total, $tarifa["valor_minimo"]);

    // Aplicar cupom (prepared statement)
    $desconto = 0;
    $cupom_id = null;
    if ($cupom_codigo) {
        $stmtCupom = $db->prepare("SELECT * FROM boraum_cupons WHERE codigo = ? AND ativo = 1");
        $stmtCupom->execute([$cupom_codigo]);
        $cupom = $stmtCupom->fetch();
        if ($cupom) {
            $desconto = $cupom["tipo"] == "percentual" ? $valor_total * ($cupom["valor"] / 100) : $cupom["valor"];
            $cupom_id = $cupom["id"];
        }
    }
    
    $valor_final = max($valor_total - $desconto, $tarifa["valor_minimo"]);
    
    // Buscar motoristas próximos
    $motoristas = buscarMotoristasProximos($db, $origem_lat, $origem_lng, $tipo_veiculo);
    
    if (empty($motoristas)) {
        // Retornar 200 com mensagem informativa e alternativas (não 404)
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "success" => false,
            "disponivel" => false,
            "message" => "Ainda não temos motoristas disponíveis na sua região",
            "corrida_criada" => false,
            "detalhes" => [
                "origem" => $origem_endereco ?: "Lat: $origem_lat, Lng: $origem_lng",
                "destino" => $destino_endereco ?: "Lat: $destino_lat, Lng: $destino_lng",
                "distancia_km" => round($distancia, 2),
                "valor_estimado" => round($valor_final, 2)
            ],
            "alternativas" => [
                "mensagem" => "Estamos expandindo o BoraUm para sua cidade!",
                "acoes" => [
                    ["tipo" => "notificar", "label" => "Avise-me quando disponível", "endpoint" => "/api/notificacoes/alertar-cobertura.php"],
                    ["tipo" => "agendar", "label" => "Agendar para outro momento"],
                    ["tipo" => "expandir_raio", "label" => "Buscar em área maior (10km)"],
                    ["tipo" => "taxi_convencional", "label" => "Ver táxis convencionais"]
                ],
                "previsao_expansao" => "Em breve na sua cidade!"
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Criar corrida
    $sql = "INSERT INTO boraum_corridas (
                customer_id, passageiro_id, origem_lat, origem_lng, origem,
                destino_lat, destino_lng, destino,
                distancia_km, tempo_min, valor, desconto,
                cupom_id, forma_pagamento, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'buscando', NOW()) RETURNING id";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $passageiro_id, $passageiro_id, $origem_lat, $origem_lng, $origem_endereco,
        $destino_lat, $destino_lng, $destino_endereco,
        round($distancia, 2), $tempo_estimado, round($valor_final, 2), round($desconto, 2),
        $cupom_id, $forma_pagamento
    ]);
    
    $corrida_id = $stmt->fetchColumn();
    
    // TODO: Enviar push para motoristas próximos
    
    response(true, [
        "corrida_id" => $corrida_id,
        "status" => "buscando",
        "valor" => round($valor_final, 2),
        "desconto" => round($desconto, 2),
        "distancia_km" => round($distancia, 2),
        "tempo_estimado_min" => $tempo_estimado,
        "motoristas_proximos" => count($motoristas),
        "motoristas" => array_map(function($m) {
            return [
                "id" => $m["id"],
                "nome" => $m["nome"],
                "foto" => $m["foto"],
                "nota" => $m["nota"],
                "distancia_km" => round($m["distancia"], 2),
                "veiculo" => $m["veiculo_modelo"] . " - " . $m["veiculo_placa"]
            ];
        }, $motoristas)
    ], "Buscando motorista...");
    
} catch (Exception $e) {
    error_log("[transporte/corrida/solicitar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
