<?php
/**
 * API: Aceitar Corrida (Motorista)
 * POST /api/transporte/corrida/aceitar.php
 * 
 * Body: { "corrida_id": 123, "motorista_id": 1 }
 */

require_once __DIR__ . "/../config/database.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

try {
    $input = getInput();
    $db = getDB();
    
    $corrida_id = $input["corrida_id"] ?? 0;
    $motorista_id = $input["motorista_id"] ?? 0;
    
    if (!$corrida_id || !$motorista_id) {
        response(false, null, "corrida_id e motorista_id obrigatórios", 400);
    }

    // Verificar se corrida existe e está buscando (prepared statement)
    $stmtCorrida = $db->prepare("SELECT * FROM boraum_corridas WHERE id = ?");
    $stmtCorrida->execute([$corrida_id]);
    $corrida = $stmtCorrida->fetch();

    if (!$corrida) {
        response(false, null, "Corrida não encontrada", 404);
    }

    if ($corrida["status"] !== "buscando") {
        response(false, null, "Corrida já foi aceita por outro motorista", 409);
    }

    // Verificar motorista (prepared statement)
    $stmtMot = $db->prepare("SELECT * FROM boraum_motoristas WHERE id = ? AND status = 'aprovado'");
    $stmtMot->execute([$motorista_id]);
    $motorista = $stmtMot->fetch();

    if (!$motorista) {
        response(false, null, "Motorista não encontrado", 404);
    }

    // Aceitar corrida (prepared statement)
    $sql = "UPDATE boraum_corridas SET motorista_id = ?, status = 'aceita', aceita_em = NOW() WHERE id = ? AND status = 'buscando'";

    $stmt = $db->prepare($sql);
    $result = $stmt->execute([$motorista_id, $corrida_id]);

    if ($stmt->rowCount() == 0) {
        response(false, null, "Corrida já foi aceita", 409);
    }

    // Buscar passageiro (prepared statement)
    $stmtPass = $db->prepare("SELECT nome, telefone, foto, nota_media FROM boraum_passageiros WHERE id = ?");
    $stmtPass->execute([$corrida["passageiro_id"]]);
    $passageiro = $stmtPass->fetch();
    
    // TODO: Enviar push para passageiro
    
    response(true, [
        "corrida_id" => $corrida_id,
        "status" => "aceita",
        "passageiro" => [
            "nome" => $passageiro["nome"] ?? "Passageiro",
            "telefone" => $passageiro["telefone"] ?? "",
            "foto" => $passageiro["foto"],
            "nota" => $passageiro["nota_media"] ?? 5.0
        ],
        "origem" => [
            "lat" => $corrida["origem_lat"],
            "lng" => $corrida["origem_lng"],
            "endereco" => $corrida["origem_endereco"]
        ],
        "destino" => [
            "lat" => $corrida["destino_lat"],
            "lng" => $corrida["destino_lng"],
            "endereco" => $corrida["destino_endereco"]
        ],
        "valor" => $corrida["valor_total"]
    ], "Corrida aceita! Vá até o passageiro.");
    
} catch (Exception $e) {
    error_log("[transporte/corrida/aceitar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
