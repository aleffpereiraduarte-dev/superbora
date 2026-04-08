<?php
/**
 * Groq API Client (drop-in replacement for ClaudeClient text-only calls).
 *
 * Uses the OpenAI-compatible Chat Completions endpoint at api.groq.com.
 * Default model: llama-3.3-70b-versatile (~10x faster, ~5x cheaper than Claude Sonnet).
 *
 * Returns the SAME response shape as ClaudeClient::send():
 *   ['success' => bool, 'text' => string, 'input_tokens' => int,
 *    'output_tokens' => int, 'total_tokens' => int, 'model' => string, 'error' => string]
 *
 * Groq does NOT support vision yet. Vision calls must continue to use ClaudeClient::sendWithVision().
 */

class GroqClient {
    private string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxRetries;

    const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
    // ALL tiers use Kimi K2 Instruct 0905 — Sep 2025, 262K context, best PT-BR + reasoning.
    const DEFAULT_MODEL = 'moonshotai/kimi-k2-instruct-0905';
    const FAST_MODEL = 'moonshotai/kimi-k2-instruct-0905';
    const REASONING_MODEL = 'moonshotai/kimi-k2-instruct-0905';
    // Fallback if Kimi fails: Llama 3.3 70B before bubbling up to Claude
    const FALLBACK_MODEL = 'llama-3.3-70b-versatile';
    const DEFAULT_TIMEOUT = 60;
    const DEFAULT_MAX_RETRIES = 1;

    public function __construct(
        string $model = self::DEFAULT_MODEL,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES
    ) {
        $this->apiKey = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?: '';
        $this->model = $model;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    /**
     * Same signature as ClaudeClient::send() with one extra optional flag.
     * $messages format: [['role'=>'user','content'=>'...'], ...]  (Anthropic style — converted internally)
     *
     * @param bool $jsonMode  When true, forces the model to return a JSON object via
     *                        OpenAI-compatible response_format. Required when callers
     *                        rely on parseJson() — without it Kimi K2 sometimes ignores
     *                        the prompt and returns plain text greetings.
     */
    public function send(string $systemPrompt, array $messages, int $maxTokens = 4096, bool $jsonMode = false): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'GROQ_API_KEY not configured'];
        }

        // Convert Anthropic-style messages to OpenAI-style: prepend system message if non-empty
        $openaiMessages = [];
        if ($systemPrompt !== '') {
            $openaiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($messages as $m) {
            // Anthropic content can be a string OR an array of parts. For text-only we keep strings.
            $content = $m['content'] ?? '';
            if (is_array($content)) {
                // Strip non-text parts (Groq has no vision)
                $textOnly = '';
                foreach ($content as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'text') {
                        $textOnly .= ($part['text'] ?? '') . "\n";
                    } elseif (is_string($part)) {
                        $textOnly .= $part . "\n";
                    }
                }
                $content = trim($textOnly);
            }
            $openaiMessages[] = ['role' => $m['role'] ?? 'user', 'content' => (string)$content];
        }

        $data = [
            'model' => $this->model,
            'messages' => $openaiMessages,
            'max_tokens' => $maxTokens,
            'temperature' => 0.7,
        ];

        if ($jsonMode) {
            // OpenAI/Groq spec: response_format requires the prompt to mention "json"
            // somewhere, otherwise the API rejects with 400. Our AI prompts already
            // do, but we guard anyway by appending a hint.
            $data['response_format'] = ['type' => 'json_object'];
        }

        $lastError = '';
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                sleep(pow(2, $attempt));
            }

            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $lastError = 'cURL error: ' . $curlError;
                continue;
            }

            // Retry on overload / rate limit / server error
            if (in_array($httpCode, [429, 500, 502, 503, 529], true)) {
                $lastError = "API overloaded (HTTP {$httpCode})";
                continue;
            }

            if ($httpCode !== 200) {
                $errorBody = json_decode($response, true);
                $errorMsg = $errorBody['error']['message'] ?? "HTTP {$httpCode}";
                return ['success' => false, 'error' => "Groq API error: {$errorMsg}"];
            }

            $result = json_decode($response, true);
            $text = $result['choices'][0]['message']['content'] ?? '';
            if ($text === '') {
                return ['success' => false, 'error' => 'Empty response from Groq'];
            }

            return [
                'success' => true,
                'text' => $text,
                'input_tokens' => $result['usage']['prompt_tokens'] ?? 0,
                'output_tokens' => $result['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $result['usage']['total_tokens'] ?? 0,
                'model' => $result['model'] ?? $this->model,
                'provider' => 'groq',
            ];
        }

        // ─── Model fallback: if the primary model failed all retries, try the
        // FALLBACK_MODEL (Llama 3.3 70B) once before bubbling the error up. This
        // covers the case where Kimi K2 is temporarily unavailable on Groq.
        if ($this->model !== self::FALLBACK_MODEL) {
            error_log("[groq-client] {$this->model} failed ({$lastError}), trying FALLBACK_MODEL " . self::FALLBACK_MODEL);
            $data['model'] = self::FALLBACK_MODEL;
            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode === 200) {
                $result = json_decode($response, true);
                $text = $result['choices'][0]['message']['content'] ?? '';
                if ($text !== '') {
                    return [
                        'success' => true,
                        'text' => $text,
                        'input_tokens' => $result['usage']['prompt_tokens'] ?? 0,
                        'output_tokens' => $result['usage']['completion_tokens'] ?? 0,
                        'total_tokens' => $result['usage']['total_tokens'] ?? 0,
                        'model' => $result['model'] ?? self::FALLBACK_MODEL,
                        'provider' => 'groq-fallback',
                    ];
                }
            }
        }

        return ['success' => false, 'error' => $lastError ?: 'Max retries exceeded'];
    }

    /**
     * Vision is NOT supported by Groq. Returns an error so callers fall back to Claude.
     */
    public function sendWithVision(string $systemPrompt, array $images, string $textPrompt, int $maxTokens = 8192): array {
        return [
            'success' => false,
            'error' => 'Groq does not support vision yet. Use ClaudeClient::sendWithVision().',
        ];
    }

    /**
     * Same JSON parser as ClaudeClient — handles markdown wrapping etc.
     * Static so callers can do GroqClient::parseJson(...) the same way.
     */
    public static function parseJson(string $raw): ?array {
        $text = trim($raw);

        if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?```$/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        $parsed = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return $parsed;
        }

        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                return $parsed;
            }
        }

        if (preg_match('/\[[\s\S]*\]/', $text, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Transcribe audio via Groq Whisper Large V3 Turbo.
     * Same shape as the OpenAI Whisper response: ['text' => '...']
     */
    public static function transcribe(string $audioPath, string $language = 'pt'): array {
        $apiKey = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?: '';
        if (!$apiKey) {
            return ['success' => false, 'error' => 'GROQ_API_KEY not configured'];
        }

        if (!is_readable($audioPath)) {
            return ['success' => false, 'error' => 'audio file not readable'];
        }

        $cfile = new CURLFile($audioPath, mime_content_type($audioPath) ?: 'audio/mpeg', basename($audioPath));
        $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS => [
                'file' => $cfile,
                'model' => 'whisper-large-v3-turbo',
                'language' => $language,
                'response_format' => 'json',
                'temperature' => '0',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$resp) {
            return ['success' => false, 'error' => "Groq Whisper HTTP {$code}: " . substr($resp ?: '', 0, 200)];
        }
        $parsed = json_decode($resp, true);
        return [
            'success' => true,
            'text' => trim($parsed['text'] ?? ''),
        ];
    }
}
