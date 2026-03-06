<?php
/**
 * POST /api/mercado/webhooks/twilio-status.php
 * Twilio Call Status Callback — updates call record with duration and final status
 *
 * Twilio sends: CallSid, CallStatus, CallDuration, RecordingUrl, etc.
 * Statuses: queued, ringing, in-progress, completed, busy, failed, no-answer, canceled
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
require_once __DIR__ . '/../helpers/ws-callcenter-broadcast.php';

header('Content-Type: text/xml; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// ── Validate Twilio Signature ───────────────────────────────────────────
$authToken = $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';
$twilioSignature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

if (!empty($authToken) && empty($twilioSignature)) {
    error_log("[twilio-status] Rejected: missing signature header");
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
        error_log("[twilio-status] Rejected: invalid signature");
        http_response_code(403);
        exit;
    }
}

// ── Parse Status ────────────────────────────────────────────────────────
$callSid = $_POST['CallSid'] ?? '';
$callStatus = $_POST['CallStatus'] ?? '';
$callDuration = (int)($_POST['CallDuration'] ?? 0);
$recordingUrl = $_POST['RecordingUrl'] ?? '';
$recordingSid = $_POST['RecordingSid'] ?? '';

if (empty($callSid)) {
    error_log("[twilio-status] Missing CallSid");
    http_response_code(400);
    exit;
}

error_log("[twilio-status] CallSid={$callSid} Status={$callStatus} Duration={$callDuration}s");

// ── Map Twilio status to our status ─────────────────────────────────────
$statusMap = [
    'completed' => 'completed',
    'busy' => 'missed',
    'failed' => 'missed',
    'no-answer' => 'missed',
    'canceled' => 'missed',
    'in-progress' => 'in_progress',
    'ringing' => 'ringing',
];
$mappedStatus = $statusMap[$callStatus] ?? null;

try {
    $db = getDB();

    // Build dynamic update
    $updates = [];
    $values = [];

    if ($callDuration > 0) {
        $updates[] = "duration_seconds = ?";
        $values[] = $callDuration;
    }

    if (!empty($recordingUrl)) {
        $updates[] = "recording_url = ?";
        $values[] = $recordingUrl;
    }

    // Set ended_at for terminal statuses
    $terminalStatuses = ['completed', 'busy', 'failed', 'no-answer', 'canceled'];
    if (in_array($callStatus, $terminalStatuses, true)) {
        $updates[] = "ended_at = NOW()";
    }

    // Set answered_at for in-progress
    if ($callStatus === 'in-progress') {
        $updates[] = "answered_at = COALESCE(answered_at, NOW())";
    }

    // Update status if we have a mapping
    if ($mappedStatus) {
        $updates[] = "status = ?";
        $values[] = $mappedStatus;
    }

    if (empty($updates)) {
        // Nothing to update
        echo '<?xml version="1.0" encoding="UTF-8"?><Response/>';
        exit;
    }

    $values[] = $callSid;
    $sql = "UPDATE om_callcenter_calls SET " . implode(', ', $updates) . " WHERE twilio_call_sid = ? RETURNING id, agent_id";
    $stmt = $db->prepare($sql);
    $stmt->execute($values);
    $row = $stmt->fetch();

    if ($row) {
        $callId = (int)$row['id'];
        $agentId = $row['agent_id'] ? (int)$row['agent_id'] : null;

        // Mark queue entry as abandoned if call ended without being picked
        if (in_array($callStatus, $terminalStatuses, true)) {
            $db->prepare("
                UPDATE om_callcenter_queue SET abandoned_at = NOW()
                WHERE call_id = ? AND picked_at IS NULL AND abandoned_at IS NULL
            ")->execute([$callId]);
        }

        // Broadcast status update
        ccBroadcastDashboard('call_status', [
            'call_id' => $callId,
            'status' => $mappedStatus ?? $callStatus,
            'duration' => $callDuration,
        ]);

        if ($agentId) {
            ccBroadcastAgent($agentId, 'call_status', [
                'call_id' => $callId,
                'status' => $mappedStatus ?? $callStatus,
                'duration' => $callDuration,
            ]);
        }

        error_log("[twilio-status] Updated call_id={$callId} status={$mappedStatus} duration={$callDuration}s");
    } else {
        error_log("[twilio-status] No call found for CallSid={$callSid}");
    }

} catch (Exception $e) {
    error_log("[twilio-status] Error: " . $e->getMessage());
}

// Always return 200 to Twilio
echo '<?xml version="1.0" encoding="UTF-8"?><Response/>';
