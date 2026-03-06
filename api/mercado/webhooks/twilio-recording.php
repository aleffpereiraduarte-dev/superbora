<?php
/**
 * POST /api/mercado/webhooks/twilio-recording.php
 * Twilio Recording Ready Callback — stores recording URL on call record
 *
 * Twilio sends: RecordingSid, RecordingUrl, RecordingStatus, RecordingDuration, CallSid
 */

// Load env manually (webhooks bypass rate limiting)
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

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/xml; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// ── Validate Twilio Signature ───────────────────────────────────────────
$authToken = $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';
$twilioSignature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

if (!empty($authToken) && empty($twilioSignature)) {
    error_log("[twilio-recording] Rejected: missing signature header");
    http_response_code(403);
    exit;
}
if (!empty($authToken) && !empty($twilioSignature)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $fullUrl = $scheme . '://' . $host . strtok($uri, '?');

    $params = $_POST;
    ksort($params);
    $dataString = $fullUrl;
    foreach ($params as $key => $value) {
        $dataString .= $key . $value;
    }

    $expectedSignature = base64_encode(hash_hmac('sha1', $dataString, $authToken, true));
    if (!hash_equals($expectedSignature, $twilioSignature)) {
        error_log("[twilio-recording] Rejected: invalid signature");
        http_response_code(403);
        exit;
    }
}

// ── Parse Recording Data ────────────────────────────────────────────────
$callSid = $_POST['CallSid'] ?? '';
$recordingSid = $_POST['RecordingSid'] ?? '';
$recordingUrl = $_POST['RecordingUrl'] ?? '';
$recordingStatus = $_POST['RecordingStatus'] ?? '';
$recordingDuration = (int)($_POST['RecordingDuration'] ?? 0);

if (empty($callSid) || empty($recordingUrl)) {
    error_log("[twilio-recording] Missing required fields: CallSid={$callSid} RecordingUrl={$recordingUrl}");
    http_response_code(400);
    exit;
}

error_log("[twilio-recording] CallSid={$callSid} RecordingSid={$recordingSid} Status={$recordingStatus} Duration={$recordingDuration}s");

// Only process completed recordings
if ($recordingStatus !== 'completed') {
    error_log("[twilio-recording] Ignoring non-completed recording: status={$recordingStatus}");
    echo '<?xml version="1.0" encoding="UTF-8"?><Response/>';
    exit;
}

try {
    $db = getDB();

    // Append .mp3 for direct playback URL
    $mp3Url = rtrim($recordingUrl, '/') . '.mp3';

    $stmt = $db->prepare("
        UPDATE om_callcenter_calls
        SET recording_url = ?,
            recording_duration = ?
        WHERE twilio_call_sid = ?
        RETURNING id
    ");
    $stmt->execute([$mp3Url, $recordingDuration, $callSid]);
    $row = $stmt->fetch();

    if ($row) {
        error_log("[twilio-recording] Stored recording for call_id={$row['id']}: {$mp3Url} ({$recordingDuration}s)");
    } else {
        error_log("[twilio-recording] No call found for CallSid={$callSid} — recording orphaned");
    }

} catch (Exception $e) {
    error_log("[twilio-recording] Error: " . $e->getMessage());
}

// Always return 200 to Twilio
echo '<?xml version="1.0" encoding="UTF-8"?><Response/>';
