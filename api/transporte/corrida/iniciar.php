<?php
/**
 * API: Iniciar Corrida (Motorista chegou, passageiro embarcou)
 * POST /api/transporte/corrida/iniciar.php
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
    
    $corrida_id = intval($input["corrida_id"] ?? 0);
    $motorista_id = intval($input["motorista_id"] ?? 0);

    // Verificar corrida (prepared statement)
    $stmtCorrida = $db->prepare("SELECT * FROM boraum_corridas WHERE id = ? AND motorista_id = ?");
    $stmtCorrida->execute([$corrida_id, $motorista_id]);
    $corrida = $stmtCorrida->fetch();

    if (!$corrida) {
        response(false, null, "Corrida não encontrada", 404);
    }

    if ($corrida["status"] !== "aceita" && $corrida["status"] !== "aguardando") {
        response(false, null, "Corrida não pode ser iniciada", 400);
    }

    // Iniciar corrida (prepared statement)
    $stmtIniciar = $db->prepare("UPDATE boraum_corridas SET status = 'em_andamento', iniciada_em = NOW() WHERE id = ?");
    $stmtIniciar->execute([$corrida_id]);
    
    response(true, [
        "corrida_id" => $corrida_id,
        "status" => "em_andamento",
        "iniciada_em" => date("c")
    ], "Corrida iniciada! Boa viagem!");
    
} catch (Exception $e) {
    error_log("[transporte/corrida/iniciar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
