<?php
/**
 * POST /api/mercado/auth/totp-verify.php
 * Verify a TOTP code and enable 2FA (first use) or validate login step.
 *
 * Body: { "code": "123456" }
 *
 * TOTP algorithm: HMAC-SHA1, 6 digits, 30-second period, +/-1 step drift.
 * Pure PHP implementation — no external library required.
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

$customerId = requireCustomerAuth();
$db = getDB();
$input = getInput();

$code = trim($input['code'] ?? '');

if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
    response(false, null, "Codigo deve ter 6 digitos numericos", 400);
}

// ─── Base32 decoder ──────────────────────────────────────────────────────────
function base32_decode(string $input): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper(rtrim($input, '='));
    $binary = '';
    foreach (str_split($input) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $output = '';
    $chunks = str_split($binary, 8);
    foreach ($chunks as $chunk) {
        if (strlen($chunk) < 8) break;
        $output .= chr(bindec($chunk));
    }
    return $output;
}

/**
 * Generate TOTP code for a given counter value.
 * RFC 6238 / RFC 4226: HMAC-SHA1, dynamic truncation, 6-digit output.
 */
function generateTOTP(string $secretBytes, int $counter): string {
    // Counter as 8-byte big-endian
    $counterBytes = pack('N*', 0, $counter);

    // HMAC-SHA1
    $hash = hash_hmac('sha1', $counterBytes, $secretBytes, true);

    // Dynamic truncation (RFC 4226 Section 5.4)
    $offset = ord($hash[19]) & 0x0F;
    $truncated = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
        ((ord($hash[$offset + 3]) & 0xFF))
    );

    // 6-digit code
    $otp = $truncated % 1000000;
    return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
}

try {
    // Fetch stored secret
    $stmt = $db->prepare("
        SELECT totp_secret, totp_enabled
        FROM om_customers
        WHERE customer_id = ?
    ");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();

    if (!$customer || empty($customer['totp_secret'])) {
        response(false, null, "2FA nao foi configurado. Use o endpoint de setup primeiro.", 400);
    }

    $secretBytes = base32_decode($customer['totp_secret']);
    $period = 30;
    $currentCounter = (int)floor(time() / $period);

    // Check current + previous + next counter (1-step drift tolerance)
    $valid = false;
    for ($i = -1; $i <= 1; $i++) {
        $expected = generateTOTP($secretBytes, $currentCounter + $i);
        if (hash_equals($expected, $code)) {
            $valid = true;
            break;
        }
    }

    if (!$valid) {
        response(false, null, "Codigo incorreto ou expirado", 401);
    }

    // If 2FA is not yet enabled, this verify call activates it
    if (!$customer['totp_enabled']) {
        $stmt = $db->prepare("
            UPDATE om_customers
            SET totp_enabled = TRUE, totp_enabled_at = NOW()
            WHERE customer_id = ?
        ");
        $stmt->execute([$customerId]);

        response(true, [
            'totp_enabled' => true,
            'just_activated' => true,
        ], "2FA ativado com sucesso!");
    }

    // Already enabled — this is a login verification
    response(true, [
        'totp_enabled' => true,
        'verified' => true,
    ], "Codigo verificado com sucesso");

} catch (Exception $e) {
    error_log("[TOTP Verify] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar codigo 2FA", 500);
}
