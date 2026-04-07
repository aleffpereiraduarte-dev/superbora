<?php
/**
 * OpenAI Vision Client (drop-in replacement for ClaudeClient::sendWithVision).
 *
 * Uses GPT-4o-mini by default — ~20x cheaper than Claude Sonnet 4 for vision tasks.
 * Same response shape as ClaudeClient::send():
 *   ['success' => bool, 'text' => string, 'input_tokens' => int,
 *    'output_tokens' => int, 'total_tokens' => int, 'model' => string, 'error' => string]
 */

class OpenAIVisionClient {
    private string $apiKey;
    private string $model;
    private int $timeout;

    const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    const DEFAULT_MODEL = 'gpt-4o-mini';
    const PREMIUM_MODEL = 'gpt-4o';
    const DEFAULT_TIMEOUT = 120;

    public function __construct(string $model = self::DEFAULT_MODEL, int $timeout = self::DEFAULT_TIMEOUT) {
        $this->apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
        $this->model = $model;
        $this->timeout = $timeout;
    }

    /**
     * Match ClaudeClient::sendWithVision signature exactly.
     * @param array $images Array of ['data' => base64_string, 'mime' => 'image/jpeg']
     */
    public function sendWithVision(string $systemPrompt, array $images, string $textPrompt, int $maxTokens = 8192): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'OPENAI_API_KEY not configured'];
        }
        if (empty($images)) {
            return ['success' => false, 'error' => 'no images provided'];
        }

        // Build OpenAI vision content array
        $userContent = [];
        $userContent[] = ['type' => 'text', 'text' => $textPrompt];
        foreach ($images as $img) {
            $mime = $img['mime'] ?? 'image/jpeg';
            $b64 = $img['data'] ?? '';
            if ($b64 === '') continue;
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:{$mime};base64,{$b64}",
                    // 'high' = better quality but ~3x cost. Default 'auto' picks based on image size.
                    'detail' => 'auto',
                ],
            ];
        }

        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $userContent];

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => 0.3,
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL: ' . $curlError];
        }
        if ($httpCode !== 200) {
            $errorBody = json_decode($response, true);
            $msg = $errorBody['error']['message'] ?? "HTTP {$httpCode}";
            return ['success' => false, 'error' => "OpenAI vision: {$msg}"];
        }

        $result = json_decode($response, true);
        $text = $result['choices'][0]['message']['content'] ?? '';
        if ($text === '') {
            return ['success' => false, 'error' => 'Empty response from OpenAI vision'];
        }

        return [
            'success' => true,
            'text' => $text,
            'input_tokens' => $result['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $result['usage']['completion_tokens'] ?? 0,
            'total_tokens' => $result['usage']['total_tokens'] ?? 0,
            'model' => $result['model'] ?? $this->model,
            'provider' => 'openai',
        ];
    }
}
