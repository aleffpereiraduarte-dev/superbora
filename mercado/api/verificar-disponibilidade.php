<?php
/**
 * Verificar disponibilidade de shoppers na região
 * Cenário Crítico #31
 */

require_once __DIR__ . '/../config/database.php';

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit;
}

function getDB() {
    return getPDO();
}

$input = $_GET ?: json_decode(file_get_contents("php://input"), true) ?: [];
$lat = floatval($input['lat'] ?? 0);
$lng = floatval($input['lng'] ?? 0);
$partner_id = intval($input['partner_id'] ?? 0);
$raio_km = intval($input['raio_km'] ?? 10);

try {
    $db = getDB();

    // Buscar shoppers disponíveis na região
    $shoppers = [];

    if ($lat && $lng) {
        $sql = "SELECT shopper_id, name, phone, rating,
                (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distancia
                FROM om_market_shoppers
                WHERE online = 1 AND disponivel = 1 AND status = 'ativo'
                AND latitude IS NOT NULL
                HAVING distancia <= ?
                ORDER BY distancia ASC
                LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->execute([$lat, $lng, $lat, $raio_km]);
        $shoppers = $stmt->fetchAll();
    } else {
        // Busca geral
        $sql = "SELECT shopper_id, name, phone, rating FROM om_market_shoppers
                WHERE online = 1 AND disponivel = 1 AND status = 'ativo' LIMIT 20";
        $shoppers = $db->query($sql)->fetchAll();
    }

    $total = count($shoppers);

    if ($total === 0) {
        // Sem shoppers na região
        echo json_encode([
            "success" => false,
            "disponivel" => false,
            "total_shoppers" => 0,
            "shoppers" => [],
            "message" => "Ainda não temos shoppers disponíveis na sua região",
            "alternativas" => [
                "mensagem" => "Estamos expandindo para sua cidade!",
                "acoes" => [
                    ["tipo" => "notificar", "label" => "Avise-me quando disponível", "endpoint" => "/api/notificacoes/alertar-cobertura.php"],
                    ["tipo" => "expandir_raio", "label" => "Buscar em área maior"],
                    ["tipo" => "agendar", "label" => "Agendar para outro momento"]
                ],
                "em_breve" => "Cadastre seu email para ser notificado quando tivermos cobertura na sua região!"
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            "success" => true,
            "disponivel" => true,
            "total_shoppers" => $total,
            "shoppers" => $shoppers,
            "message" => "$total shopper(s) disponível(is) na região"
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "disponivel" => false,
        "message" => "Erro ao verificar disponibilidade",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
