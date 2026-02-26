<?php
/**
 * API: Cancelar Corrida
 * POST /api/transporte/corrida/cancelar.php
 * 
 * Body: { "corrida_id": 123, "cancelado_por": "passageiro", "motivo": "Desisti" }
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
    $cancelado_por = $input["cancelado_por"] ?? "passageiro";
    $motivo = $input["motivo"] ?? "";

    // Buscar corrida (prepared statement)
    $stmtCorrida = $db->prepare("SELECT * FROM boraum_corridas WHERE id = ?");
    $stmtCorrida->execute([$corrida_id]);
    $corrida = $stmtCorrida->fetch();
    
    if (!$corrida) {
        response(false, null, "Corrida não encontrada", 404);
    }
    
    if (in_array($corrida["status"], ["finalizada", "cancelada"])) {
        response(false, null, "Corrida não pode ser cancelada", 400);
    }
    
    // Calcular taxa de cancelamento
    $taxa = 0;
    if ($corrida["status"] == "aceita" || $corrida["status"] == "em_andamento") {
        $taxa = 5.00; // Taxa fixa
    }
    
    // Cancelar
    $sql = "UPDATE boraum_corridas SET 
            status = \"cancelada\",
            cancelado_por = ?,
            motivo_cancelamento = ?,
            taxa_cancelamento = ?,
            cancelada_em = NOW()
            WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$cancelado_por, $motivo, $taxa, $corrida_id]);
    
    response(true, [
        "corrida_id" => $corrida_id,
        "status" => "cancelada",
        "taxa_cancelamento" => $taxa
    ], $taxa > 0 ? "Corrida cancelada. Taxa de R$ " . number_format($taxa, 2, ",", ".") : "Corrida cancelada");
    
} catch (Exception $e) {
    error_log("[transporte/corrida/cancelar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
