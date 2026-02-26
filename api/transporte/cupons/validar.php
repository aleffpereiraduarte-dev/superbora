<?php
/**
 * GET /api/transporte/cupons/validar.php?codigo=BEMVINDO
 */
require_once __DIR__ . "/../config/database.php";

try {
    $db = getDB();
    
    $codigo = $_GET["codigo"] ?? "";
    
    // Prepared statement para prevenir SQL Injection
    $stmt = $db->prepare("SELECT * FROM boraum_cupons WHERE codigo = ? AND ativo = 1");
    $stmt->execute([$codigo]);
    $cupom = $stmt->fetch();
    
    if (!$cupom) {
        response(false, null, "Cupom inválido ou expirado", 404);
    }
    
    // Verificar validade
    if ($cupom["data_fim"] && strtotime($cupom["data_fim"]) < time()) {
        response(false, null, "Cupom expirado", 400);
    }
    
    response(true, [
        "codigo" => $cupom["codigo"],
        "descricao" => $cupom["descricao"],
        "tipo" => $cupom["tipo"],
        "valor" => floatval($cupom["valor"]),
        "valido" => true
    ]);
    
} catch (Exception $e) {
    error_log("[transporte/cupons/validar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
