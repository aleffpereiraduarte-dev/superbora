<?php
/**
 * POST /api/mercado/helpers/transcribe.php
 * Receives audio (base64 or file) and transcribes using OpenAI Whisper API
 * Returns: {"text": "transcribed text"}
 */
require_once __DIR__ . '/../config/database.php';

setCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Method not allowed', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $audioBase64 = $input['audio'] ?? '';
    $mimeType = $input['mime_type'] ?? 'audio/webm';

    if (empty($audioBase64)) {
        response(false, null, 'Audio data required', 400);
    }

    // Decode base64
    $audioData = base64_decode($audioBase64);
    if ($audioData === false || strlen($audioData) < 100) {
        response(false, null, 'Invalid audio data', 400);
    }

    // Max 10MB
    if (strlen($audioData) > 10 * 1024 * 1024) {
        response(false, null, 'Audio too large (max 10MB)', 400);
    }

    // Determine file extension from mime type
    $extMap = [
        'audio/webm' => 'webm',
        'audio/mp4' => 'm4a',
        'audio/m4a' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/x-caf' => 'caf',
    ];
    $ext = $extMap[$mimeType] ?? 'webm';

    // Write to temp file
    $tmpFile = tempnam(sys_get_temp_dir(), 'audio_') . '.' . $ext;
    file_put_contents($tmpFile, $audioData);

    // Try OpenAI Whisper API first
    $openaiKey = $_ENV['OPENAI_API_KEY'] ?? '';

    if ($openaiKey) {
        $text = transcribeWithWhisper($tmpFile, $ext, $openaiKey);
    } else {
        // Fallback: use Claude to transcribe by describing audio intent
        // This won't work for actual audio, so return a helpful error
        @unlink($tmpFile);
        response(false, null, 'Transcription service not configured', 503);
    }

    @unlink($tmpFile);

    if ($text === null || $text === '') {
        response(false, null, 'Could not transcribe audio', 422);
    }

    response(true, [
        'text' => trim($text),
    ]);

} catch (Exception $e) {
    error_log("[Transcribe] Error: " . $e->getMessage());
    response(false, null, 'Transcription failed', 500);
}

/**
 * Transcribe audio using OpenAI Whisper API
 */
function transcribeWithWhisper(string $filePath, string $ext, string $apiKey): ?string {
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');

    $cfile = new CURLFile($filePath, 'audio/' . $ext, 'audio.' . $ext);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => $cfile,
            'model' => 'whisper-1',
            'language' => 'pt',
            'response_format' => 'text',
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("[Transcribe] Whisper API error (HTTP {$httpCode}): " . $result);
        throw new Exception("Whisper API returned HTTP {$httpCode}");
    }

    return $result;
}
