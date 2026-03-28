<?php
/**
 * GET /api/mercado/admin/callcenter/twilio-token.php
 * Generate voice token for browser softphone (WebRTC)
 *
 * Supports two providers via VOICE_PROVIDER env var:
 *   - "telnyx" (default): Telnyx WebRTC credential + SIP token
 *   - "twilio": Twilio Access Token with VoiceGrant (pure PHP JWT)
 */

require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Method not allowed', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];
    $adminName = $payload['data']['name'] ?? 'Agent';

    // ── Get or create agent identity ────────────────────────────────────
    $agentStmt = $db->prepare("
        SELECT id, display_name FROM om_callcenter_agents WHERE admin_id = ? LIMIT 1
    ");
    $agentStmt->execute([$adminId]);
    $agent = $agentStmt->fetch();

    $identity = 'agent_' . $adminId;
    if ($agent) {
        $identity = 'agent_' . $agent['id'];
    }

    // ── Choose provider ─────────────────────────────────────────────────
    $voiceProvider = strtolower($_ENV['VOICE_PROVIDER'] ?? getenv('VOICE_PROVIDER') ?: 'twilio');

    if ($voiceProvider === 'telnyx') {
        // ── Telnyx WebRTC Token ─────────────────────────────────────────
        require_once __DIR__ . '/../../helpers/telnyx-client.php';
        $telnyx = getTelnyxClient();

        if (!$telnyx->isConfigured()) {
            error_log("[twilio-token] Telnyx not configured for admin_id={$adminId}");
            response(false, null, 'Telnyx not configured', 503);
        }

        // Create a telephony credential for this agent
        $credential = $telnyx->generateWebRTCToken($identity);
        if (!$credential) {
            error_log("[twilio-token] Telnyx credential creation failed for admin_id={$adminId}");
            response(false, null, 'Failed to generate Telnyx credential', 502);
        }

        // Generate a short-lived SIP token (JWT) for the WebRTC SDK
        $credentialId = $credential['id'] ?? '';
        $sipToken = null;
        if ($credentialId) {
            $sipToken = $telnyx->createSipToken($credentialId);
        }

        $ttl = 3600;

        // Update agent status to online
        if ($agent) {
            $db->prepare("UPDATE om_callcenter_agents SET status = 'online', updated_at = NOW() WHERE id = ?")
               ->execute([$agent['id']]);
        }

        error_log("[twilio-token] Telnyx token generated for admin_id={$adminId} identity={$identity} cred_id={$credentialId}");

        response(true, [
            'token'          => $sipToken ?? '',
            'identity'       => $identity,
            'expires_in'     => $ttl,
            'agent_name'     => $agent['display_name'] ?? $adminName,
            'provider'       => 'telnyx',
            'credential_id'  => $credentialId,
            'sip_username'   => $credential['sip_username'] ?? '',
            'sip_password'   => $credential['sip_password'] ?? '',
            'connection_id'  => $_ENV['TELNYX_CONNECTION_ID'] ?? getenv('TELNYX_CONNECTION_ID') ?: '',
            'websocket_url'  => $_ENV['TELNYX_WEBSOCKET'] ?? getenv('TELNYX_WEBSOCKET') ?: 'wss://rtc.telnyx.com',
        ]);
    }

    // ── Twilio Access Token (default / fallback) ────────────────────────
    $accountSid = $_ENV['TWILIO_SID'] ?? getenv('TWILIO_SID') ?: '';
    $apiKeySid = $_ENV['TWILIO_API_KEY_SID'] ?? getenv('TWILIO_API_KEY_SID') ?: '';
    $apiKeySecret = $_ENV['TWILIO_API_KEY_SECRET'] ?? getenv('TWILIO_API_KEY_SECRET') ?: '';
    $twimlAppSid = $_ENV['TWILIO_TWIML_APP_SID'] ?? getenv('TWILIO_TWIML_APP_SID') ?: '';

    if (empty($accountSid) || empty($apiKeySid) || empty($apiKeySecret) || empty($twimlAppSid)) {
        error_log("[twilio-token] Missing Twilio credentials for admin_id={$adminId}");
        response(false, null, 'Twilio not configured', 503);
    }

    // ── Build Twilio Access Token (pure PHP JWT) ────────────────────────
    $ttl = 3600; // 1 hour
    $now = time();

    $header = [
        'typ' => 'JWT',
        'alg' => 'HS256',
        'cty' => 'twilio-fpa;v=1',
    ];

    // Voice grant
    $grants = [
        'voice' => [
            'incoming' => ['allow' => true],
            'outgoing' => [
                'application_sid' => $twimlAppSid,
            ],
        ],
        'identity' => $identity,
    ];

    $tokenPayload = [
        'jti' => $apiKeySid . '-' . $now,
        'iss' => $apiKeySid,
        'sub' => $accountSid,
        'iat' => $now,
        'exp' => $now + $ttl,
        'grants' => $grants,
    ];

    // Encode JWT
    $headerEncoded = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $payloadEncoded = rtrim(strtr(base64_encode(json_encode($tokenPayload)), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $apiKeySecret, true)
    ), '+/', '-_'), '=');

    $jwt = $headerEncoded . '.' . $payloadEncoded . '.' . $signature;

    // Update agent status to online
    if ($agent) {
        $db->prepare("UPDATE om_callcenter_agents SET status = 'online', updated_at = NOW() WHERE id = ?")
           ->execute([$agent['id']]);
    }

    error_log("[twilio-token] Twilio token generated for admin_id={$adminId} identity={$identity}");

    response(true, [
        'token' => $jwt,
        'identity' => $identity,
        'expires_in' => $ttl,
        'agent_name' => $agent['display_name'] ?? $adminName,
        'provider' => 'twilio',
    ]);

} catch (Exception $e) {
    error_log("[twilio-token] Error: " . $e->getMessage());
    response(false, null, 'Erro ao gerar token', 500);
}
