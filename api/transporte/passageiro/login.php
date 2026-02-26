<?php
/**
 * POST /api/transporte/passageiro/login.php
 */
require_once __DIR__ . "/../config/database.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

try {
    $input = getInput();
    $db = getDB();
    
    $telefone = $input["telefone"] ?? "";
    $senha = $input["senha"] ?? "";
    
    if (!$telefone || !$senha) {
        response(false, null, "Telefone e senha obrigatórios", 400);
    }
    
    // Prepared statement para prevenir SQL Injection
    $stmt = $db->prepare("SELECT * FROM boraum_passageiros WHERE telefone = ?");
    $stmt->execute([$telefone]);
    $passageiro = $stmt->fetch();
    
    if (!$passageiro) {
        response(false, null, "Passageiro não encontrado", 404);
    }
    
    if (!password_verify($senha, $passageiro["senha_hash"])) {
        response(false, null, "Senha incorreta", 401);
    }
    
    if ($passageiro["status"] !== "ativo") {
        response(false, null, "Conta bloqueada", 403);
    }
    
    $token = bin2hex(random_bytes(32));
    
    response(true, [
        "passageiro_id" => $passageiro["id"],
        "nome" => $passageiro["nome"],
        "telefone" => $passageiro["telefone"],
        "foto" => $passageiro["foto"],
        "token" => $token
    ], "Login realizado!");
    
} catch (Exception $e) {
    error_log("[transporte/passageiro/login] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
