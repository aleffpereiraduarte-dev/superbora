<?php
/**
 * Audio proxy — generates and serves TTS audio on demand
 * Any server can handle this — text is encoded in URL params
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

require_once __DIR__ . '/../helpers/voice-tts.php';

$hash = $_GET['h'] ?? '';
$encoded = $_GET['t'] ?? '';
$emotion = $_GET['e'] ?? 'neutral';

if (empty($hash) || empty($encoded)) {
    http_response_code(400);
    exit;
}

if (!ttsServeAudio($hash, $encoded, $emotion)) {
    // Fallback: return silence (1 second)
    http_response_code(500);
    error_log("[audio.php] Failed to generate audio for hash={$hash} emotion={$emotion}");
}
