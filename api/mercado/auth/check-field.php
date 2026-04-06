<?php
/**
 * POST /api/mercado/auth/check-field.php
 * Check if a field (email, cpf, or telefone) is already registered.
 * No auth required (pre-registration check).
 *
 * Body: { "field": "email|cpf|telefone", "value": "..." }
 * Returns: { "available": true } or { "available": false, "message": "..." }
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
if (!RateLimiter::check(30, 60)) {
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

try {
    $input = getInput();

    $field = trim($input['field'] ?? '');
    $value = trim($input['value'] ?? '');

    if (empty($field) || empty($value)) {
        response(false, null, "Campos 'field' e 'value' sao obrigatorios", 400);
    }

    if (!in_array($field, ['email', 'cpf', 'telefone'], true)) {
        response(false, null, "Campo invalido. Use: email, cpf ou telefone", 400);
    }

    $db = getDB();

    // ── Email ──
    if ($field === 'email') {
        $email = filter_var($value, FILTER_SANITIZE_EMAIL);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            response(false, null, "Email invalido", 400);
        }

        $stmt = $db->prepare("SELECT customer_id FROM om_customers WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            response(true, ["available" => false, "message" => "Este email ja esta cadastrado"]);
        }

        response(true, ["available" => true]);
    }

    // ── CPF ──
    if ($field === 'cpf') {
        $cpf = preg_replace('/[^0-9]/', '', $value);

        if (strlen($cpf) !== 11) {
            response(false, null, "CPF deve ter 11 digitos", 400);
        }

        if (!validarCPF($cpf)) {
            response(false, null, "CPF invalido", 400);
        }

        $stmt = $db->prepare("
            SELECT customer_id FROM om_customers
            WHERE REGEXP_REPLACE(cpf, '[^0-9]', '', 'g') = ?
            AND cpf IS NOT NULL AND cpf != ''
        ");
        $stmt->execute([$cpf]);

        if ($stmt->fetch()) {
            response(true, ["available" => false, "message" => "Este CPF ja esta cadastrado"]);
        }

        response(true, ["available" => true]);
    }

    // ── Telefone ──
    if ($field === 'telefone') {
        $phoneDigits = preg_replace('/[^0-9]/', '', $value);

        if (strlen($phoneDigits) < 7) {
            response(false, null, "Telefone invalido", 400);
        }

        // Normalize: add country code 55 for 10-digit BR landlines
        $phoneNormalized = $phoneDigits;
        if (strlen($phoneDigits) === 10) {
            $phoneNormalized = '55' . $phoneDigits;
        }

        // Build suffix without country code for matching old records
        $phoneSuffix = $phoneNormalized;
        if (substr($phoneNormalized, 0, 2) === '55' && strlen($phoneNormalized) >= 12) {
            $phoneSuffix = substr($phoneNormalized, 2);
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
            response(true, ["available" => false, "message" => "Este telefone ja esta cadastrado"]);
        }

        response(true, ["available" => true]);
    }

} catch (Exception $e) {
    error_log("[API Auth CheckField] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar campo", 500);
}
