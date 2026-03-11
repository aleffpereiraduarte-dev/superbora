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

require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
if (!RateLimiter::check(5, 60)) { // 5 registrations per minute per IP
    exit;
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

/**
 * Normalize phone to digits-only.
 * Frontend always sends countryCode + localDigits (e.g. 5533999999999, 15551234567).
 * For backward compat: 10-digit numbers assumed Brazilian landline (add 55).
 * 11+ digit numbers already include country code.
 */
function normalizePhone(string $phone): string {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    // Only auto-add 55 for 10-digit BR landline (DDD 2 + number 8 = 10)
    // 11+ digits already have country code (BR mobile = 55+11, US = 1+10, etc.)
    if (strlen($digits) === 10) {
        $digits = '55' . $digits;
    }
    return $digits;
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

    // Telefone obrigatorio — aceita brasileiro (10-11 digitos) e internacional (com codigo do pais)
    $phoneDigits = preg_replace('/[^0-9]/', '', $telefone);
    if (empty($phoneDigits) || strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) {
        response(false, null, "Telefone obrigatorio. Inclua DDD + numero (Brasil) ou codigo do pais + numero (internacional).", 400);
    }

    // Normalizar telefone: sempre com codigo do pais, somente digitos
    $phoneNormalized = normalizePhone($telefone);

    // CPF obrigatorio com validacao mod11
    if (empty($cpf)) {
        response(false, null, "CPF obrigatorio", 400);
    }
    if (!validarCPF($cpf)) {
        response(false, null, "CPF invalido", 400);
    }

    // ── Verificar duplicatas com mensagens especificas ──

    // Email unico (case-insensitive)
    $stmt = $db->prepare("SELECT customer_id FROM om_customers WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        response(false, ['field' => 'email'], "Este email ja esta cadastrado. Tente fazer login ou recuperar sua senha.", 409);
    }

    // CPF unico (limpar formatacao de ambos os lados)
    $stmt = $db->prepare("
        SELECT customer_id FROM om_customers
        WHERE REGEXP_REPLACE(cpf, '[^0-9]', '', 'g') = ?
        AND cpf IS NOT NULL AND cpf != ''
    ");
    $stmt->execute([$cpf]);
    if ($stmt->fetch()) {
        response(false, ['field' => 'cpf'], "Este CPF ja esta cadastrado. Tente fazer login ou entre em contato com o suporte.", 409);
    }

    // Telefone unico — normalizar ambos os lados para comparar
    // Compara tanto com quanto sem codigo do pais (pega formatos antigos)
    $phoneSuffix = $phoneNormalized;
    if (substr($phoneNormalized, 0, 2) === '55' && strlen($phoneNormalized) >= 12) {
        $phoneSuffix = substr($phoneNormalized, 2); // numero sem 55
    }
    $stmt = $db->prepare("
        SELECT customer_id FROM om_customers
        WHERE REGEXP_REPLACE(phone, '[^0-9]', '', 'g') IN (?, ?)
        OR (
            LENGTH(REGEXP_REPLACE(phone, '[^0-9]', '', 'g')) BETWEEN 10 AND 11
            AND '55' || REGEXP_REPLACE(phone, '[^0-9]', '', 'g') = ?
        )
    ");
    $stmt->execute([$phoneNormalized, $phoneSuffix, $phoneNormalized]);
    if ($stmt->fetch()) {
        response(false, ['field' => 'telefone'], "Este telefone ja esta cadastrado. Tente fazer login ou entre em contato com o suporte.", 409);
    }

    // Verificar OTP se fornecido
    $phoneVerified = 0;
    if (!empty($otpCode)) {
        if (strlen($otpCode) !== 6) {
            response(false, null, "Codigo de verificacao deve ter 6 digitos", 400);
        }

        // Buscar codigo mais recente nao expirado (atomic with FOR UPDATE)
        // Tenta com numero normalizado e tambem com variantes (com/sem 55)
        $db->beginTransaction();
        $stmt = $db->prepare("
            SELECT id, code, attempts, expires_at
            FROM om_market_otp_codes
            WHERE phone IN (?, ?) AND expires_at > NOW() AND (used = 0 OR used IS NULL)
            ORDER BY created_at DESC LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$phoneNormalized, $phoneSuffix]);
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

    // Criar cliente (catch UNIQUE violation for race condition between check + insert)
    try {
        $stmt = $db->prepare("
            INSERT INTO om_customers (name, email, password_hash, phone, cpf, phone_verified, is_active, is_verified, gender, birth_date, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, ?, NOW(), NOW())
            RETURNING customer_id
        ");
        $stmt->execute([$fullName, strtolower($email), $passwordHash, $phoneNormalized, $cpf ?: null, $phoneVerified, $genero ?: null, $birthDate]);
        $customerId = (int)$stmt->fetch()['customer_id'];
    } catch (PDOException $e) {
        if (strpos($e->getCode(), '23505') !== false || stripos($e->getMessage(), 'unique') !== false) {
            // Identify which field caused the UNIQUE violation
            $msg = $e->getMessage();
            if (stripos($msg, 'email') !== false) {
                response(false, ['field' => 'email'], "Este email ja esta cadastrado. Tente fazer login ou recuperar sua senha.", 409);
            } elseif (stripos($msg, 'phone') !== false) {
                response(false, ['field' => 'telefone'], "Este telefone ja esta cadastrado. Tente fazer login ou entre em contato com o suporte.", 409);
            } elseif (stripos($msg, 'cpf') !== false) {
                response(false, ['field' => 'cpf'], "Este CPF ja esta cadastrado. Tente fazer login ou entre em contato com o suporte.", 409);
            }
            response(false, null, "Dados ja cadastrados. Tente fazer login ou recuperar sua conta.", 409);
        }
        throw $e;
    }

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
            "telefone" => $phoneNormalized,
            "cpf" => $cpf,
            "genero" => $genero ?: null,
            "data_nascimento" => $birthDate,
        ]
    ], "Conta criada com sucesso!", 201);

} catch (Exception $e) {
    error_log("[API Auth Register] Erro: " . $e->getMessage());
    response(false, null, "Erro ao criar conta", 500);
}
