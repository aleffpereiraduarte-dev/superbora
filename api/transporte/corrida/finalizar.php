<?php
/**
 * API: Finalizar Corrida
 * POST /api/transporte/corrida/finalizar.php
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

    // Buscar corrida (prepared statement)
    $stmtCorrida = $db->prepare("SELECT * FROM boraum_corridas WHERE id = ? AND motorista_id = ?");
    $stmtCorrida->execute([$corrida_id, $motorista_id]);
    $corrida = $stmtCorrida->fetch();
    
    if (!$corrida) {
        response(false, null, "Corrida não encontrada", 404);
    }
    
    if ($corrida["status"] !== "em_andamento") {
        response(false, null, "Corrida não está em andamento", 400);
    }
    
    // Calcular comissão
    $valor_corrida = $corrida["valor_total"];
    $comissao_pct = 20; // 20% padrão
    $comissao = $valor_corrida * ($comissao_pct / 100);
    $valor_motorista = $valor_corrida - $comissao;
    
    // Finalizar corrida
    $sql = "UPDATE boraum_corridas SET 
            status = \"finalizada\",
            finalizada_em = NOW(),
            comissao = ?,
            valor_motorista = ?
            WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$comissao, $valor_motorista, $corrida_id]);
    
    // Atualizar saldo do motorista (prepared statement)
    $stmtSaldo = $db->prepare("UPDATE boraum_motoristas SET saldo = saldo + ?, total_entregas = total_entregas + 1 WHERE id = ?");
    $stmtSaldo->execute([$valor_motorista, $motorista_id]);

    // Atualizar wallet (prepared statement)
    $stmtWallet = $db->prepare("UPDATE boraum_wallet SET saldo = saldo + ? WHERE motorista_id = ?");
    $stmtWallet->execute([$valor_motorista, $motorista_id]);

    // Registrar transação (prepared statement)
    $descricao = "Corrida #$corrida_id";
    $stmtTrans = $db->prepare("INSERT INTO boraum_wallet_transacoes (motorista_id, tipo, valor, descricao, corrida_id, created_at) VALUES (?, 'credito', ?, ?, ?, NOW())");
    $stmtTrans->execute([$motorista_id, $valor_motorista, $descricao, $corrida_id]);
    
    response(true, [
        "corrida_id" => $corrida_id,
        "status" => "finalizada",
        "valor_total" => $valor_corrida,
        "comissao" => round($comissao, 2),
        "valor_motorista" => round($valor_motorista, 2),
        "forma_pagamento" => $corrida["forma_pagamento"]
    ], "Corrida finalizada! R$ " . number_format($valor_motorista, 2, ",", ".") . " adicionado ao seu saldo.");
    
} catch (Exception $e) {
    error_log("[transporte/corrida/finalizar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
