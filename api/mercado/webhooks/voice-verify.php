<?php
/**
 * POST /api/mercado/webhooks/voice-verify.php
 * Internal endpoint — sends/checks verification codes
 *
 * Provider controlled by SMS_PROVIDER env var:
 *   - "telnyx": Self-managed OTP via Telnyx SMS + DB storage
 *   - "twilio": Twilio Verify API (managed OTP)
 *
 * Protected by X-Internal-Key header
 *
 * Actions:
 *   action=send   — Send verification code
 *   action=check  — Verify the code the customer provided
 */

if (file_exists(__DIR__ . '/../../../.env')) {
    $envFile = file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim(trim($value), '"\'');
        }
    }
}

header('Content-Type: application/json');

if (($_SERVER['HTTP_X_INTERNAL_KEY'] ?? '') !== 'superbora-voice-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$smsProvider = strtolower($_ENV['SMS_PROVIDER'] ?? getenv('SMS_PROVIDER') ?? 'twilio');

$twilioSid = $_ENV['TWILIO_SID'] ?? '';
$twilioToken = $_ENV['TWILIO_TOKEN'] ?? $_ENV['TWILIO_AUTH_TOKEN'] ?? '';
$verifySid = $_ENV['TWILIO_VERIFY_SID'] ?? 'VA34083528deea28a6963d3bee14a72ceb';

// Parse input
$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;

$action = $input['action'] ?? '';
$phone = preg_replace('/[^0-9+]/', '', $input['phone'] ?? '');

if (empty($phone)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing phone']);
    exit;
}

// Format phone to E.164 (+XX...)
$cleanPhone = preg_replace('/\D/', '', $phone);
// Only add country code 55 for Brazilian local numbers (10-11 digits, not starting with country code)
// Numbers starting with 1 (US/Canada), 44 (UK), etc should NOT get 55 prepended
if (strlen($cleanPhone) >= 10 && strlen($cleanPhone) <= 11 && !preg_match('/^(55|1|44|34|49|33|61|81)/', $cleanPhone)) {
    $cleanPhone = '55' . $cleanPhone;
}
$formattedPhone = '+' . $cleanPhone;

// ── Telnyx provider: self-managed OTP via SMS ───────────────────────
if ($smsProvider === 'telnyx') {
    require_once __DIR__ . '/../helpers/telnyx-client.php';
    $telnyx = getTelnyxClient();

    if ($action === 'send') {
        // Generate a 4-digit code, store in DB, send via Telnyx SMS
        $code = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

        // Store in a simple way — use the DB so check can verify later
        require_once __DIR__ . '/../config/database.php';
        $db = getDB();
        // Clean up old codes for this phone
        $db->prepare("DELETE FROM om_market_otp_codes WHERE phone = ? AND created_at < NOW() - INTERVAL '10 minutes'")->execute([$formattedPhone]);
        $db->prepare(
            "INSERT INTO om_market_otp_codes (phone, code, expires_at, attempts, used, created_at)
             VALUES (?, ?, NOW() + INTERVAL '5 minutes', 0, 0, NOW())"
        )->execute([$formattedPhone, password_hash($code, PASSWORD_DEFAULT)]);

        $smsResult = $telnyx->sendSMS($formattedPhone, "SuperBora: Seu codigo de verificacao e {$code}. Valido por 5 minutos.");

        if ($smsResult['success']) {
            error_log("[voice-verify] Telnyx code sent via SMS to {$formattedPhone}");
            echo json_encode(['success' => true, 'sent_via' => 'sms']);
        } else {
            error_log("[voice-verify] Telnyx SMS failed: {$smsResult['message']}");
            http_response_code(502);
            echo json_encode(['success' => false, 'error' => $smsResult['message']]);
        }
        exit;

    } elseif ($action === 'check') {
        $code = preg_replace('/\D/', '', $input['code'] ?? '');
        if (empty($code)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing code']);
            exit;
        }

        require_once __DIR__ . '/../config/database.php';
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT id, code FROM om_market_otp_codes
             WHERE phone = ? AND used = 0 AND attempts < 5 AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$formattedPhone]);
        $row = $stmt->fetch();

        if ($row && password_verify($code, $row['code'])) {
            $db->prepare("UPDATE om_market_otp_codes SET used = 1 WHERE id = ?")->execute([$row['id']]);
            error_log("[voice-verify] Telnyx code APPROVED for {$formattedPhone}");
            echo json_encode(['success' => true, 'verified' => true]);
        } else {
            // Increment attempts
            if ($row) {
                $db->prepare("UPDATE om_market_otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
            }
            error_log("[voice-verify] Telnyx code REJECTED for {$formattedPhone}");
            echo json_encode(['success' => false, 'verified' => false, 'status' => 'pending']);
        }
        exit;

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use: send or check']);
        exit;
    }
}

// ── Twilio provider (default) ───────────────────────────────────────

if ($action === 'send') {
    // ── SEND verification code via Twilio Verify (SMS) ──
    // Twilio Verify handles code generation, rate limiting, and anti-fraud
    if (empty($twilioSid) || empty($twilioToken)) {
        http_response_code(503);
        echo json_encode(['error' => 'Twilio not configured']);
        exit;
    }

    $result = twilioVerifyRequest(
        $twilioSid, $twilioToken, $verifySid,
        'Verifications',
        ['To' => $formattedPhone, 'Channel' => 'sms']
    );

    if ($result['success']) {
        error_log("[voice-verify] Code sent via SMS to {$formattedPhone}");
        echo json_encode(['success' => true, 'sent_via' => 'sms']);
    } else {
        error_log("[voice-verify] Failed to send code: {$result['message']}");
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => $result['message']]);
    }

} elseif ($action === 'check') {
    // ── CHECK verification code via Twilio Verify ──
    $code = preg_replace('/\D/', '', $input['code'] ?? '');
    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing code']);
        exit;
    }

    if (empty($twilioSid) || empty($twilioToken)) {
        http_response_code(503);
        echo json_encode(['error' => 'Twilio not configured']);
        exit;
    }

    $result = twilioVerifyRequest(
        $twilioSid, $twilioToken, $verifySid,
        'VerificationCheck',
        ['To' => $formattedPhone, 'Code' => $code]
    );

    if ($result['success'] && ($result['data']['status'] ?? '') === 'approved') {
        error_log("[voice-verify] Code APPROVED for {$formattedPhone}");
        echo json_encode(['success' => true, 'verified' => true]);
    } else {
        $status = $result['data']['status'] ?? 'failed';
        error_log("[voice-verify] Code REJECTED for {$formattedPhone}: status={$status}");
        echo json_encode(['success' => false, 'verified' => false, 'status' => $status]);
    }

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action. Use: send or check']);
}

/**
 * Make a request to Twilio Verify API
 */
function twilioVerifyRequest(string $sid, string $token, string $verifySid, string $endpoint, array $data): array {
    $url = "https://verify.twilio.com/v2/Services/{$verifySid}/{$endpoint}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_USERPWD => "{$sid}:{$token}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message' => "cURL: {$curlError}", 'data' => []];
    }

    $responseData = json_decode($result, true) ?? [];

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => 'OK', 'data' => $responseData];
    }

    return [
        'success' => false,
        'message' => $responseData['message'] ?? "HTTP {$httpCode}",
        'data' => $responseData
    ];
}
