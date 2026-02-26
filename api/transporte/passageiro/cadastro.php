<?php
/**
 * POST /api/transporte/passageiro/cadastro.php
 */
require_once __DIR__ . "/../config/database.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

try {
    $input = getInput();
    $db = getDB();
    
    $nome = $input["nome"] ?? "";
    $email = $input["email"] ?? "";
    $telefone = $input["telefone"] ?? "";
    $cpf = $input["cpf"] ?? "";
    $senha = $input["senha"] ?? "";
    
    if (!$nome || !$telefone || !$senha) {
        response(false, null, "Nome, telefone e senha obrigatórios", 400);
    }
    
    // Verificar se já existe (prepared statement)
    $stmt = $db->prepare("SELECT id FROM boraum_passageiros WHERE telefone = ?");
    $stmt->execute([$telefone]);
    $existe = $stmt->fetch();
    if ($existe) {
        response(false, null, "Telefone já cadastrado", 409);
    }
    
    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
    
    $sql = "INSERT INTO boraum_passageiros (nome, email, telefone, cpf, senha_hash, status, created_at)
            VALUES (?, ?, ?, ?, ?, \"ativo\", NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$nome, $email, $telefone, $cpf, $senha_hash]);
    
    $id = $stmt->fetchColumn();
    
    response(true, [
        "passageiro_id" => $id,
        "nome" => $nome,
        "telefone" => $telefone
    ], "Cadastro realizado!");
    
} catch (Exception $e) {
    error_log("[transporte/passageiro/cadastro] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
