<?php
/**
 * POST /api/mercado/auth/register.php
 * Registro de novo cliente
 * Campos obrigatorios: nome, email, senha, telefone, cpf
 * Campo opcional: otp_code (se fornecido, verifica OTP e marca phone_verified=1)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

function validarCPF(string $cpf): bool {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) !== 11) return false;
    // Rejeitar sequencias repetidas (111..., 222..., etc)
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;

    // Primeiro digito verificador
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$cpf[$i] * (10 - $i);
    }
    $remainder = $sum % 11;
    $digit1 = ($remainder < 2) ? 0 : 11 - $remainder;
    if ((int)$cpf[9] !== $digit1) return false;

    // Segundo digito verificador
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += (int)$cpf[$i] * (11 - $i);
    }
    $remainder = $sum % 11;
    $digit2 = ($remainder < 2) ? 0 : 11 - $remainder;
    if ((int)$cpf[10] !== $digit2) return false;

    return true;
}

try {
    $input = getInput();
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $nome = strip_tags(trim(substr($input['nome'] ?? '', 0, 100)));
    $sobrenome = strip_tags(trim(substr($input['sobrenome'] ?? '', 0, 100)));
    $email = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $telefone = preg_replace('/[^0-9+]/', '', $input['telefone'] ?? '');
    $cpf = preg_replace('/[^0-9]/', '', $input['cpf'] ?? '');
    $senha = $input['senha'] ?? '';
    $otpCode = trim($input['otp_code'] ?? '');
    $genero = trim($input['genero'] ?? '');
    $dataNascimento = trim($input['data_nascimento'] ?? '');

    // Validar genero (opcional)
    $validGenders = ['masculino', 'feminino', 'outro', 'prefiro_nao_dizer', ''];
    if (!in_array($genero, $validGenders)) {
        $genero = '';
    }

    // Validar data de nascimento (opcional, formato YYYY-MM-DD)
    $birthDate = null;
    if (!empty($dataNascimento)) {
        $d = DateTime::createFromFormat('Y-m-d', $dataNascimento);
        if ($d && $d->format('Y-m-d') === $dataNascimento) {
            $birthDate = $dataNascimento;
        }
    }

    // Validacoes
    if (empty($nome)) {
        response(false, null, "Nome obrigatorio", 400);
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        response(false, null, "Email invalido", 400);
    }
    if (strlen($senha) < 8) {
        response(false, null, "Senha deve ter no minimo 8 caracteres", 400);
    }
    // SECURITY: Require at least one letter and one number
    if (!preg_match('/[a-zA-Z]/', $senha) || !preg_match('/[0-9]/', $senha)) {
        response(false, null, "Senha deve conter pelo menos uma letra e um numero", 400);
    }

    // Telefone obrigatorio
    if (empty($telefone) || strlen($telefone) < 10 || strlen($telefone) > 15) {
        response(false, null, "Telefone obrigatorio e deve ter DDD + numero", 400);
    }

    // CPF obrigatorio com validacao mod11
    if (empty($cpf)) {
        response(false, null, "CPF obrigatorio", 400);
    }
    if (!validarCPF($cpf)) {
        response(false, null, "CPF invalido", 400);
    }

    // Verificar email unico
    $stmt = $db->prepare("SELECT customer_id FROM om_customers WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        response(false, null, "Dados ja cadastrados. Tente fazer login ou recuperar sua conta.", 409);
    }

    // Verificar CPF unico
    $stmt = $db->prepare("SELECT customer_id FROM om_customers WHERE cpf = ?");
    $stmt->execute([$cpf]);
    if ($stmt->fetch()) {
        response(false, null, "Dados ja cadastrados. Tente fazer login ou recuperar sua conta.", 409);
    }

    // Verificar telefone unico
    $phoneClean = preg_replace('/[^0-9]/', '', $telefone);
    $stmt = $db->prepare("SELECT customer_id FROM om_customers WHERE REPLACE(REPLACE(phone, '+', ''), '-', '') = ?");
    $stmt->execute([$phoneClean]);
    if ($stmt->fetch()) {
        response(false, null, "Dados ja cadastrados. Tente fazer login ou recuperar sua conta.", 409);
    }

    // Verificar OTP se fornecido
    $phoneVerified = 0;
    if (!empty($otpCode)) {
        if (strlen($otpCode) !== 6) {
            response(false, null, "Codigo de verificacao deve ter 6 digitos", 400);
        }

        // Buscar codigo mais recente nao expirado (atomic with FOR UPDATE)
        $db->beginTransaction();
        $stmt = $db->prepare("
            SELECT id, code, attempts, expires_at
            FROM om_market_otp_codes
            WHERE phone = ? AND expires_at > NOW() AND (used = 0 OR used IS NULL)
            ORDER BY created_at DESC LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$phoneClean]);
        $otp = $stmt->fetch();

        if (!$otp) {
            $db->rollBack();
            response(false, null, "Codigo expirado ou nao encontrado. Solicite um novo.", 400);
        }

        // Max 3 tentativas por codigo
        if ((int)$otp['attempts'] >= 3) {
            $db->prepare("UPDATE om_market_otp_codes SET used = 1 WHERE id = ?")->execute([$otp['id']]);
            $db->commit();
            response(false, null, "Muitas tentativas erradas. Solicite um novo codigo.", 400);
        }

        // Verificar codigo
        if (!password_verify($otpCode, $otp['code'])) {
            $db->prepare("UPDATE om_market_otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$otp['id']]);
            $db->commit();
            $remaining = 3 - (int)$otp['attempts'] - 1;
            response(false, null, "Codigo incorreto. $remaining tentativa(s) restante(s).", 400);
        }

        // Marcar OTP como usado
        $db->prepare("UPDATE om_market_otp_codes SET used = 1, verified_at = NOW() WHERE id = ?")->execute([$otp['id']]);
        $db->commit();
        $phoneVerified = 1;
    }

    // Hash da senha
    $passwordHash = om_auth()->hashPassword($senha);
    $fullName = trim($nome . ' ' . $sobrenome);

    // Criar cliente
    $stmt = $db->prepare("
        INSERT INTO om_customers (name, email, password_hash, phone, cpf, phone_verified, is_active, is_verified, gender, birth_date, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, ?, NOW(), NOW())
        RETURNING customer_id
    ");
    $stmt->execute([$fullName, $email, $passwordHash, $telefone, $cpf ?: null, $phoneVerified, $genero ?: null, $birthDate]);
    $customerId = (int)$stmt->fetch()['customer_id'];

    // Auto-login: gerar token
    $token = om_auth()->generateToken('customer', $customerId, [
        'name' => $fullName,
        'email' => $email
    ]);

    response(true, [
        "token" => $token,
        "customer" => [
            "id" => $customerId,
            "nome" => $fullName,
            "email" => $email,
            "telefone" => $telefone,
            "cpf" => $cpf,
            "genero" => $genero ?: null,
            "data_nascimento" => $birthDate,
        ]
    ], "Conta criada com sucesso!", 201);

} catch (Exception $e) {
    error_log("[API Auth Register] Erro: " . $e->getMessage());
    response(false, null, "Erro ao criar conta", 500);
}
