<?php
/**
 * POST /api/auth/registro.php
 * Registro de usuários com validações de segurança
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once dirname(dirname(__DIR__)) . '/includes/env_loader.php';

// Conexão
try {
    $db = new PDO(
        "mysql:host=" . env('DB_HOSTNAME', 'localhost') . ";dbname=" . env('DB_DATABASE', '') . ";charset=utf8mb4",
        env('DB_USERNAME', ''),
        env('DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erro de conexão"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: $_POST;

$tipo = $input["tipo"] ?? "passageiro";
$nome = trim($input["nome"] ?? "");
$email = trim($input["email"] ?? "");
$telefone = preg_replace("/[^0-9]/", "", $input["telefone"] ?? "");
$cpf = preg_replace("/[^0-9]/", "", $input["cpf"] ?? "");
$senha = $input["senha"] ?? "";

$erros = [];

// ═══════════════════════════════════════════════════════════════
// VALIDAÇÕES
// ═══════════════════════════════════════════════════════════════

// Nome obrigatório
if (empty($nome)) {
    $erros[] = "Nome é obrigatório";
} elseif (strlen($nome) < 3) {
    $erros[] = "Nome deve ter pelo menos 3 caracteres";
} elseif (strlen($nome) > 100) {
    $erros[] = "Nome muito longo (máximo 100 caracteres)";
}

// Email - validar formato se fornecido
if (!empty($email)) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = "Formato de email inválido";
    } elseif (strlen($email) > 100) {
        $erros[] = "Email muito longo";
    }
}

// Telefone obrigatório e válido
if (empty($telefone)) {
    $erros[] = "Telefone é obrigatório";
} elseif (strlen($telefone) < 10 || strlen($telefone) > 11) {
    $erros[] = "Telefone inválido (deve ter 10 ou 11 dígitos com DDD)";
} elseif (strlen($telefone) === 11 && $telefone[2] !== "9") {
    $erros[] = "Celular deve começar com 9 após o DDD";
}

// CPF - validar se fornecido
if (!empty($cpf)) {
    if (strlen($cpf) !== 11) {
        $erros[] = "CPF deve ter 11 dígitos";
    } elseif (preg_match("/^(\d)\1{10}$/", $cpf)) {
        $erros[] = "CPF inválido";
    } else {
        // Validar dígitos verificadores
        $cpfValido = true;
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += $cpf[$i] * (($t + 1) - $i);
            }
            $resto = ($soma * 10) % 11;
            if ($resto === 10) $resto = 0;
            if ($cpf[$t] != $resto) {
                $cpfValido = false;
                break;
            }
        }
        if (!$cpfValido) {
            $erros[] = "CPF inválido";
        }
    }
}

// Senha obrigatória e forte
if (empty($senha)) {
    $erros[] = "Senha é obrigatória";
} elseif (strlen($senha) < 6) {
    $erros[] = "Senha deve ter pelo menos 6 caracteres";
} elseif (strlen($senha) > 50) {
    $erros[] = "Senha muito longa";
} elseif (preg_match("/^[0-9]+$/", $senha) && strlen($senha) < 8) {
    $erros[] = "Senha apenas numérica deve ter pelo menos 8 dígitos";
}

// Retornar erros se houver
if (!empty($erros)) {
    http_response_code(400);
    echo json_encode([
        "success" => false, 
        "message" => $erros[0],
        "errors" => $erros
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// VERIFICAR DUPLICAÇÃO
// ═══════════════════════════════════════════════════════════════

$tabela = $tipo === "motorista" ? "boraum_motoristas" : "boraum_passageiros";

// Verificar telefone duplicado
$existe = $db->prepare("SELECT id FROM $tabela WHERE telefone = ?");
$existe->execute([$telefone]);
if ($existe->fetch()) {
    http_response_code(409);
    echo json_encode(["success" => false, "message" => "Este telefone já está cadastrado"]);
    exit;
}

// Verificar email duplicado (se fornecido)
if (!empty($email)) {
    $existe = $db->prepare("SELECT id FROM $tabela WHERE email = ?");
    $existe->execute([$email]);
    if ($existe->fetch()) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Este email já está cadastrado"]);
        exit;
    }
}

// Verificar CPF duplicado (se fornecido)
if (!empty($cpf)) {
    $existe = $db->prepare("SELECT id FROM $tabela WHERE cpf = ?");
    $existe->execute([$cpf]);
    if ($existe->fetch()) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Este CPF já está cadastrado"]);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════
// CRIAR USUÁRIO
// ═══════════════════════════════════════════════════════════════

$senhaHash = password_hash($senha, PASSWORD_DEFAULT);

try {
    $stmt = $db->prepare("INSERT INTO $tabela (nome, email, telefone, cpf, senha, status, created_at) 
                          VALUES (?, ?, ?, ?, ?, 1, NOW())");
    $stmt->execute([$nome, $email ?: null, $telefone, $cpf ?: null, $senhaHash]);
    
    $userId = $db->lastInsertId();
    
    echo json_encode([
        "success" => true,
        "message" => "Cadastro realizado com sucesso!",
        "data" => [
            "user_id" => $userId,
            "tipo" => $tipo,
            "nome" => $nome,
            "telefone" => $telefone
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erro ao criar cadastro. Tente novamente."]);
}
