<?php
/**
 * GET  /api/mercado/auth/totp-setup.php — Check if 2FA is enabled
 * POST /api/mercado/auth/totp-setup.php — Generate TOTP secret + QR URI
 *
 * Requires customer authentication.
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

$customerId = requireCustomerAuth();
$db = getDB();

// ─── Base32 encoder ──────────────────────────────────────────────────────────
function base32_encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $encoded = '';
    $chunks = str_split($binary, 5);
    foreach ($chunks as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $encoded .= $alphabet[bindec($chunk)];
    }
    return $encoded;
}

try {
    // ─── GET: Check 2FA status ───────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare("
            SELECT totp_enabled, totp_enabled_at
            FROM om_customers
            WHERE customer_id = ?
        ");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();

        if (!$row) {
            response(false, null, "Cliente nao encontrado", 404);
        }

        response(true, [
            'totp_enabled' => (bool)($row['totp_enabled'] ?? false),
            'totp_enabled_at' => $row['totp_enabled_at'] ?? null,
        ], "Status 2FA");
    }

    // ─── POST: Generate new TOTP secret ──────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    // Check if already enabled
    $stmt = $db->prepare("SELECT totp_enabled, email, name FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();

    if (!$customer) {
        response(false, null, "Cliente nao encontrado", 404);
    }

    if ($customer['totp_enabled']) {
        response(false, null, "2FA ja esta ativado. Desative primeiro para gerar novo segredo.", 409);
    }

    // Generate 20-byte (160-bit) cryptographically secure secret
    $secretBytes = random_bytes(20);
    $secret = base32_encode($secretBytes);

    // Store secret (not yet enabled — user must verify first)
    $stmt = $db->prepare("
        UPDATE om_customers
        SET totp_secret = ?, totp_enabled = FALSE
        WHERE customer_id = ?
    ");
    $stmt->execute([$secret, $customerId]);

    // Build otpauth:// URI for QR code
    $issuer = 'SuperBora';
    $accountName = $customer['email'] ?? "customer_{$customerId}";
    $otpauthUri = sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        rawurlencode($issuer),
        rawurlencode($accountName),
        $secret,
        rawurlencode($issuer)
    );

    response(true, [
        'secret' => $secret,
        'otpauth_uri' => $otpauthUri,
        'qr_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($otpauthUri),
    ], "Escaneie o QR code no seu aplicativo autenticador e depois verifique o codigo.");

} catch (Exception $e) {
    error_log("[TOTP Setup] Erro: " . $e->getMessage());
    response(false, null, "Erro ao configurar 2FA", 500);
}
