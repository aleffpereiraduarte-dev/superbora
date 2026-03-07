<?php
/**
 * Cron: Call Center Recording Cleanup
 * Deletes Twilio recordings older than 90 days
 *
 * Schedule: daily at 3 AM
 * crontab: 0 3 * * * php /var/www/html/api/mercado/cron/recording-cleanup.php
 */

require_once __DIR__ . '/../config/database.php';

$db = getDB();
$RETENTION_DAYS = 90;

// Load Twilio credentials
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

$twilioSid = $_ENV['TWILIO_SID'] ?? getenv('TWILIO_SID') ?: '';
$twilioToken = $_ENV['TWILIO_AUTH_TOKEN'] ?? $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';

if (empty($twilioSid) || empty($twilioToken)) {
    echo "[recording-cleanup] ERROR: Twilio credentials not configured\n";
    exit(1);
}

// Find recordings older than retention period
$stmt = $db->prepare("
    SELECT id, twilio_call_sid, recording_sid, recording_url, ended_at
    FROM om_callcenter_calls
    WHERE recording_sid IS NOT NULL
      AND ended_at < NOW() - INTERVAL '{$RETENTION_DAYS} days'
    ORDER BY ended_at ASC
    LIMIT 100
");
$stmt->execute();
$calls = $stmt->fetchAll();

$deleted = 0;
$failed = 0;

foreach ($calls as $call) {
    $recordingSid = $call['recording_sid'];

    // Delete from Twilio via REST API
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilioSid}/Recordings/{$recordingSid}.json";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => "{$twilioSid}:{$twilioToken}",
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 204 || $httpCode === 404) {
        // 204 = deleted, 404 = already gone
        $db->prepare("
            UPDATE om_callcenter_calls
            SET recording_url = NULL, recording_sid = NULL, recording_duration = NULL
            WHERE id = ?
        ")->execute([$call['id']]);
        $deleted++;
        echo "[recording-cleanup] Deleted: {$recordingSid} (call {$call['twilio_call_sid']}, ended {$call['ended_at']})\n";
    } else {
        $failed++;
        echo "[recording-cleanup] FAILED ({$httpCode}): {$recordingSid} — {$response}\n";
    }

    // Small delay to not hit Twilio rate limits
    usleep(200000); // 200ms
}

echo "[recording-cleanup] Done: {$deleted} deleted, {$failed} failed, " . count($calls) . " total processed\n";
