<?php
/**
 * POST /api/auth/login.php
 * Login unificado - Smart Router
 * 
 * Se 'tipo' é especificado (passageiro/motorista/shopper): login BoraUm
 * Sem 'tipo': login SuperBora customer (oc_customer) via customer-login.php
 */

// Read input early
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;
$tipo = $input['tipo'] ?? null;

// Route: if tipo is specified and valid, use BoraUm login
if ($tipo && in_array($tipo, ['passageiro', 'motorista', 'shopper'], true)) {
    // BoraUm login logic
    require_once __DIR__ . "/config.php";

    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

    try {
        $email = $input["email"] ?? "";
        $telefone = $input["telefone"] ?? "";
        $senha = $input["senha"] ?? "";

        $identificador = $email ?: $telefone;
        if (!$identificador || !$senha) {
            response(false, null, "Email/telefone e senha obrigatórios", 400);
        }

        $db = getDB();
        $campo = $email ? "email" : "telefone";

        switch ($tipo) {
            case "motorista":
                $tabela = "boraum_motoristas";
                $id_campo = "id";
                $senha_campo = "senha";
                break;
            case "shopper":
                $tabela = "om_market_shoppers";
                $id_campo = "shopper_id";
                $senha_campo = "password";
                $campo = $email ? "email" : "phone";
                break;
            default:
                $tabela = "boraum_passageiros";
                $id_campo = "id";
                $senha_campo = "senha_hash";
        }

        $stmt = $db->prepare("SELECT * FROM $tabela WHERE $campo = ?");
        $stmt->execute([$identificador]);
        $user = $stmt->fetch();

        if (!$user) {
            response(false, null, "Usuário não encontrado", 404);
        }

        // Verificar senha com migração SHA1 → BCRYPT
        $senhaValida = false;
        $needsUpgrade = false;
        $senhaArmazenada = $user[$senha_campo] ?? '';

        if (strlen($senhaArmazenada) > 40 && strpos($senhaArmazenada, '$2') === 0) {
            $senhaValida = password_verify($senha, $senhaArmazenada);
        } else {
            $salt = $user['salt'] ?? '';
            $hash = $salt ? sha1($salt . sha1($salt . sha1($senha))) : sha1($senha);
            $senhaValida = ($hash === $senhaArmazenada);
            if ($senhaValida) $needsUpgrade = true;
        }

        if (!$senhaValida) {
            response(false, null, "Senha incorreta", 401);
        }

        if ($needsUpgrade) {
            $newHash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE $tabela SET $senha_campo = ?, salt = '' WHERE $id_campo = ?")
               ->execute([$newHash, $user[$id_campo]]);
        }

        $token = gerarToken($user[$id_campo], $tipo);
        response(true, [
            "user_id" => $user[$id_campo],
            "tipo" => $tipo,
            "nome" => $user["nome"] ?? $user["name"] ?? '',
            "token" => $token
        ], "Login realizado!");

    } catch (Exception $e) {
        error_log("[login] " . $e->getMessage());
        response(false, null, "Erro interno. Tente novamente.", 500);
    }
} else {
    // SuperBora customer login (default - no tipo specified)
    // Delegate to customer-login.php which handles oc_customer table
    require __DIR__ . '/customer-login.php';
}
