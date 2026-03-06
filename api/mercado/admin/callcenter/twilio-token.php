<?php
/**
 * GET /api/mercado/admin/callcenter/twilio-token.php
 * Generate Twilio Access Token for browser softphone (WebRTC)
 *
 * Returns a JWT token with VoiceGrant for Twilio Client SDK.
 * Pure PHP JWT — no Twilio PHP SDK dependency.
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

    // ── Read Twilio credentials from environment ────────────────────────
    $accountSid = $_ENV['TWILIO_SID'] ?? getenv('TWILIO_SID') ?: '';
    $apiKeySid = $_ENV['TWILIO_API_KEY_SID'] ?? getenv('TWILIO_API_KEY_SID') ?: '';
    $apiKeySecret = $_ENV['TWILIO_API_KEY_SECRET'] ?? getenv('TWILIO_API_KEY_SECRET') ?: '';
    $twimlAppSid = $_ENV['TWILIO_TWIML_APP_SID'] ?? getenv('TWILIO_TWIML_APP_SID') ?: '';

    if (empty($accountSid) || empty($apiKeySid) || empty($apiKeySecret) || empty($twimlAppSid)) {
        error_log("[twilio-token] Missing Twilio credentials for admin_id={$adminId}");
        response(false, null, 'Twilio not configured', 503);
    }

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

    error_log("[twilio-token] Token generated for admin_id={$adminId} identity={$identity}");

    response(true, [
        'token' => $jwt,
        'identity' => $identity,
        'expires_in' => $ttl,
        'agent_name' => $agent['display_name'] ?? $adminName,
    ]);

} catch (Exception $e) {
    error_log("[twilio-token] Error: " . $e->getMessage());
    response(false, null, 'Erro ao gerar token', 500);
}
