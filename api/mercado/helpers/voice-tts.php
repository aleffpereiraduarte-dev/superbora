<?php
/**
 * Voice TTS Helper — Ultra-natural speech synthesis
 *
 * Priority:
 *   1. ElevenLabs (best quality, native Brazilian voice)
 *   2. OpenAI TTS (excellent quality, multilingual)
 *   3. Google Neural2 via Twilio <Say> (fallback)
 *
 * Usage:
 *   require_once __DIR__ . '/voice-tts.php';
 *   echo ttsSayOrPlay("Oi, tudo bem?");
 */

// Default ElevenLabs voice ID — "Valentina" Brazilian Portuguese
// Can be overridden in .env with ELEVENLABS_VOICE_ID
define('TTS_ELEVENLABS_DEFAULT_VOICE', 'cgSgspJ2msm6clMCkdW9'); // Rachel - good multilingual

/**
 * Generate TwiML: <Play> with high-quality TTS, or <Say> fallback
 */
function ttsSayOrPlay(string $text): string {
    $clean = preg_replace('/<[^>]+>/', ' ', $text);
    $clean = preg_replace('/\s+/', ' ', trim($clean));

    if (empty($clean)) {
        return '';
    }

    $hash = md5($clean . '_v3');
    $encoded = rtrim(base64_encode($clean), '=');
    $scheme = 'https';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'superbora.com.br';
    $url = $scheme . '://' . $host . '/api/mercado/webhooks/audio.php?h=' . $hash . '&t=' . urlencode($encoded);

    return '<Play>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Play>';
}

/**
 * Called by audio.php to generate/serve audio on demand
 */
function ttsServeAudio(string $hash, string $encodedText): bool {
    $text = base64_decode($encodedText);
    if (empty($text)) return false;

    $expectedHash = md5($text . '_v3');
    if ($hash !== $expectedHash) return false;

    $audioDir = __DIR__ . '/../webhooks/audio';
    if (!is_dir($audioDir)) @mkdir($audioDir, 0755, true);

    $filePath = $audioDir . '/tts_' . $hash . '.mp3';

    // Check cache (valid 4 hours)
    if (file_exists($filePath) && (time() - filemtime($filePath)) < 14400 && filesize($filePath) > 100) {
        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: public, max-age=7200');
        readfile($filePath);
        return true;
    }

    // Try ElevenLabs first
    $audio = ttsElevenLabs($text);

    // Fallback to OpenAI
    if (!$audio) {
        $audio = ttsOpenAI($text);
    }

    if (!$audio || strlen($audio) < 100) {
        return false;
    }

    @file_put_contents($filePath, $audio);
    ttsCleanupOldFiles($audioDir);

    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . strlen($audio));
    header('Cache-Control: public, max-age=7200');
    echo $audio;
    return true;
}

/**
 * ElevenLabs TTS — Most natural voice, native Brazilian accent
 */
function ttsElevenLabs(string $text): ?string {
    $apiKey = $_ENV['ELEVENLABS_API_KEY'] ?? getenv('ELEVENLABS_API_KEY') ?: '';
    if (empty($apiKey)) return null;

    $voiceId = $_ENV['ELEVENLABS_VOICE_ID'] ?? getenv('ELEVENLABS_VOICE_ID') ?: TTS_ELEVENLABS_DEFAULT_VOICE;

    $payload = json_encode([
        'text' => $text,
        'model_id' => 'eleven_multilingual_v2',
        'voice_settings' => [
            'stability' => 0.4,
            'similarity_boost' => 0.8,
            'style' => 0.3,
            'use_speaker_boost' => true,
        ],
    ]);

    $ch = curl_init("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'xi-api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: audio/mpeg',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response) || strlen($response) < 100) {
        error_log("[voice-tts] ElevenLabs failed: HTTP {$httpCode} | {$error}");
        return null;
    }

    error_log("[voice-tts] ElevenLabs OK: " . strlen($response) . " bytes");
    return $response;
}

/**
 * OpenAI TTS — Excellent quality multilingual voice
 */
function ttsOpenAI(string $text): ?string {
    $apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
    if (empty($apiKey)) return null;

    $payload = json_encode([
        'model' => 'tts-1-hd',
        'input' => $text,
        'voice' => 'nova',
        'response_format' => 'mp3',
        'speed' => 1.0,
    ]);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response) || strlen($response) < 100) {
        error_log("[voice-tts] OpenAI TTS failed: HTTP {$httpCode} | {$error}");
        return null;
    }

    error_log("[voice-tts] OpenAI TTS OK: " . strlen($response) . " bytes");
    return $response;
}

/**
 * Remove old cached audio files
 */
function ttsCleanupOldFiles(string $dir): void {
    $files = @glob($dir . '/tts_*.mp3');
    if (!$files || count($files) <= 300) return;

    usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
    $toDelete = count($files) - 200;
    foreach ($files as $file) {
        if ($toDelete-- <= 0) break;
        @unlink($file);
    }
}
