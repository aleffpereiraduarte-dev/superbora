<?php
/**
 * POST /api/mercado/customer/voice-chat.php
 * Multipart: audio (file) + history (json optional)
 *
 * Real-time voice → text → response pipeline:
 *   1. Whisper transcribes audio (Groq Whisper Large V3 Turbo)
 *   2. Llama responds in pt-BR considering history
 *   3. Returns transcript + reply in one round-trip
 *
 * Latency target: <2s end-to-end (Whisper ~500ms + Llama ~600ms + transport)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        response(false, null, 'audio obrigatorio', 400);
    }
    if ($_FILES['audio']['size'] > 25 * 1024 * 1024) {
        response(false, null, 'audio muito grande', 413);
    }

    $apiKey = $_ENV['GROQ_API_KEY'] ?? '';
    if (!$apiKey) response(false, null, 'GROQ_API_KEY not configured', 503);

    // Step 1: Whisper (Groq)
    $cfile = new CURLFile($_FILES['audio']['tmp_name'], $_FILES['audio']['type'] ?: 'audio/mpeg', $_FILES['audio']['name'] ?: 'audio.mp3');
    $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => [
            'file' => $cfile,
            'model' => 'whisper-large-v3-turbo',
            'language' => 'pt',
            'response_format' => 'json',
            'temperature' => '0',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) response(false, null, 'falha transcrever', 502);

    $data = json_decode($resp, true);
    $transcript = trim($data['text'] ?? '');
    if ($transcript === '') response(false, null, 'audio vazio', 422);

    // Step 2: Llama responds
    $history = json_decode($_POST['history'] ?? '[]', true);
    if (!is_array($history)) $history = [];
    $history[] = ['role' => 'user', 'content' => $transcript];
    $history = array_slice($history, -10); // last 5 turns

    $client = new ClaudeClient();
    $aiResp = $client->send(
        'Voce eh ONE, o assistente de voz do SuperBora delivery. Respostas curtas (max 200 chars), em pt-BR, naturais para falar.',
        $history,
        300
    );
    if (!$aiResp['success']) response(false, null, 'falha resposta IA', 502);

    response(true, [
        'transcript' => $transcript,
        'reply' => $aiResp['text'],
        'history' => array_merge($history, [['role' => 'assistant', 'content' => $aiResp['text']]]),
    ]);
} catch (Exception $e) {
    error_log('[voice-chat] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
