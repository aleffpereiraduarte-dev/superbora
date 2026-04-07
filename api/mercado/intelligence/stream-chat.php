<?php
/**
 * POST /api/mercado/intelligence/stream-chat.php
 *
 * Streaming text generation via Groq (Server-Sent Events).
 * Used by ONE assistant, partner coach chat, and any UI that wants
 * letter-by-letter rendering instead of buffered.
 *
 * Body: { "system":"...","messages":[{"role":"user","content":"..."}],"max_tokens":1024 }
 *
 * Output: SSE stream
 *   data: {"text":"Olá"}
 *   data: {"text":" "}
 *   data: {"text":"como"}
 *   ...
 *   data: [DONE]
 */
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // disable nginx buffering
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$systemPrompt = trim($input['system'] ?? '');
$messages = $input['messages'] ?? [];
$maxTokens = max(50, min(4096, (int)($input['max_tokens'] ?? 1024)));

if (empty($messages)) {
    echo "data: " . json_encode(['error' => 'messages required']) . "\n\n";
    exit;
}

$apiKey = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?: '';
if (!$apiKey) {
    echo "data: " . json_encode(['error' => 'GROQ_API_KEY not configured']) . "\n\n";
    exit;
}

// Build OpenAI-style payload
$openaiMessages = [];
if ($systemPrompt !== '') {
    $openaiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
}
foreach ($messages as $m) {
    $content = $m['content'] ?? '';
    if (is_array($content)) $content = implode("\n", array_filter(array_map(fn($p) => is_array($p) ? ($p['text'] ?? '') : (string)$p, $content)));
    $openaiMessages[] = ['role' => $m['role'] ?? 'user', 'content' => (string)$content];
}

$payload = json_encode([
    'model' => 'llama-3.3-70b-versatile',
    'messages' => $openaiMessages,
    'max_tokens' => $maxTokens,
    'temperature' => 0.7,
    'stream' => true,
]);

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_TIMEOUT => 60,
    // Stream callback: parse Groq SSE -> our SSE
    CURLOPT_WRITEFUNCTION => function ($ch, $data) {
        static $buffer = '';
        $buffer .= $data;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);
            if (strpos($line, 'data: ') !== 0) continue;
            $payload = substr($line, 6);
            if ($payload === '[DONE]') {
                echo "data: [DONE]\n\n";
                @ob_flush(); @flush();
                continue;
            }
            $obj = json_decode($payload, true);
            $delta = $obj['choices'][0]['delta']['content'] ?? '';
            if ($delta !== '') {
                echo "data: " . json_encode(['text' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n";
                @ob_flush(); @flush();
            }
        }
        return strlen($data);
    },
]);

curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo "data: " . json_encode(['error' => $err]) . "\n\n";
}
