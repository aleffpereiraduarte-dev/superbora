<?php
/**
 * Shared Claude API Client
 * Reusable across all AI endpoints (menu, tools, assistant, support, etc.)
 */

class ClaudeClient {
    private string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxRetries;

    const DEFAULT_MODEL = 'claude-sonnet-4-20250514';
    const DEFAULT_TIMEOUT = 120;
    const DEFAULT_MAX_RETRIES = 1;

    public function __construct(
        string $model = self::DEFAULT_MODEL,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES
    ) {
        $this->apiKey = $_ENV['CLAUDE_API_KEY'] ?? '';
        $this->model = $model;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    /**
     * Send a message to Claude API
     * @return array{success: bool, text?: string, input_tokens?: int, output_tokens?: int, model?: string, error?: string}
     */
    public function send(string $systemPrompt, array $messages, int $maxTokens = 4096): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'CLAUDE_API_KEY not configured'];
        }

        $data = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => $messages,
        ];

        $lastError = '';
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep(1000000 * $attempt); // 1s, 2s backoff
            }

            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: 2023-06-01',
                ],
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
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

            if ($httpCode === 529 || $httpCode === 503) {
                $lastError = "API overloaded (HTTP {$httpCode})";
                continue;
            }

            if ($httpCode !== 200) {
                $errorBody = json_decode($response, true);
                $errorMsg = $errorBody['error']['message'] ?? "HTTP {$httpCode}";
                return ['success' => false, 'error' => "API error: {$errorMsg}"];
            }

            $result = json_decode($response, true);

            if (!isset($result['content'][0]['text'])) {
                return ['success' => false, 'error' => 'Invalid API response structure'];
            }

            return [
                'success' => true,
                'text' => $result['content'][0]['text'],
                'input_tokens' => $result['usage']['input_tokens'] ?? 0,
                'output_tokens' => $result['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0),
                'model' => $result['model'] ?? $this->model,
            ];
        }

        return ['success' => false, 'error' => $lastError ?: 'Max retries exceeded'];
    }

    /**
     * Send with vision (images)
     * @param array $images Array of ['data' => base64, 'mime' => 'image/jpeg']
     */
    public function sendWithVision(string $systemPrompt, array $images, string $textPrompt, int $maxTokens = 8192): array {
        $content = [];
        foreach ($images as $idx => $img) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $img['mime'],
                    'data' => $img['data'],
                ]
            ];
            if (count($images) > 1) {
                $content[] = ['type' => 'text', 'text' => 'Pagina ' . ($idx + 1) . ' de ' . count($images)];
            }
        }
        $content[] = ['type' => 'text', 'text' => $textPrompt];

        $messages = [['role' => 'user', 'content' => $content]];
        return $this->send($systemPrompt, $messages, $maxTokens);
    }

    /**
     * Parse JSON from Claude response (handles markdown wrapping)
     */
    public static function parseJson(string $raw): ?array {
        $text = trim($raw);

        // Strip markdown code blocks
        if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?```$/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        $parsed = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return $parsed;
        }

        // Try to find JSON within text
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                return $parsed;
            }
        }

        // Try array
        if (preg_match('/\[[\s\S]*\]/', $text, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                return $parsed;
            }
        }

        return null;
    }
}
