<?php
/**
 * API: Status da Corrida (Polling)
 * GET /api/transporte/corrida/status.php?corrida_id=123
 * Otimizado com cache curto (TTL: 5 seg) e prepared statements
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

try {
    $corrida_id = (int)($_GET["corrida_id"] ?? 0);

    if (!$corrida_id) {
        response(false, null, "corrida_id obrigatório", 400);
    }

    $cacheKey = "corrida_status_{$corrida_id}";

    $corrida = CacheHelper::remember($cacheKey, 5, function() use ($corrida_id) {
        $db = getDB();
        $stmt = $db->prepare("SELECT c.*,
                           m.nome as motorista_nome, m.telefone as motorista_telefone,
                           m.foto as motorista_foto, m.nota as motorista_nota,
                           m.latitude as motorista_lat, m.longitude as motorista_lng,
                           m.veiculo_modelo, m.veiculo_placa, m.veiculo_cor
                           FROM boraum_corridas c
                           LEFT JOIN boraum_motoristas m ON c.motorista_id = m.id
                           WHERE c.id = ?");
        $stmt->execute([$corrida_id]);
        return $stmt->fetch();
    });
    
    if (!$corrida) {
        response(false, null, "Corrida não encontrada", 404);
    }
    
    $data = [
        "corrida_id" => $corrida["id"],
        "status" => $corrida["status"],
        "valor" => $corrida["valor_total"],
        "origem" => [
            "lat" => $corrida["origem_lat"],
            "lng" => $corrida["origem_lng"],
            "endereco" => $corrida["origem_endereco"]
        ],
        "destino" => [
            "lat" => $corrida["destino_lat"],
            "lng" => $corrida["destino_lng"],
            "endereco" => $corrida["destino_endereco"]
        ]
    ];
    
    // Adicionar motorista se tiver
    if ($corrida["motorista_id"]) {
        $data["motorista"] = [
            "id" => $corrida["motorista_id"],
            "nome" => $corrida["motorista_nome"],
            "telefone" => $corrida["motorista_telefone"],
            "foto" => $corrida["motorista_foto"],
            "nota" => $corrida["motorista_nota"],
            "latitude" => $corrida["motorista_lat"],
            "longitude" => $corrida["motorista_lng"],
            "veiculo" => [
                "modelo" => $corrida["veiculo_modelo"],
                "placa" => $corrida["veiculo_placa"],
                "cor" => $corrida["veiculo_cor"]
            ]
        ];
    }
    
    response(true, $data);
    
} catch (Exception $e) {
    error_log("[transporte/corrida/status] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
