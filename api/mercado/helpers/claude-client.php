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
     *
     * @param bool $jsonMode  When true, forces the model to return strict JSON.
     *                        For Groq this enables OpenAI-compatible response_format.
     *                        For Claude this is a no-op since Claude follows JSON-in-prompt
     *                        instructions reliably already.
     * @return array{success: bool, text?: string, input_tokens?: int, output_tokens?: int, model?: string, error?: string}
     */
    public function send(string $systemPrompt, array $messages, int $maxTokens = 4096, bool $jsonMode = false): array {
        // ============================================================
        // PROVIDER ROUTING: when AI_PROVIDER=groq, route text-only calls
        // to GroqClient (Kimi K2 / Llama 3.3 70B fallback). Vision calls
        // (sendWithVision) continue to use Claude.
        // ============================================================
        $provider = $_ENV['AI_PROVIDER'] ?? getenv('AI_PROVIDER') ?: 'claude';
        if ($provider === 'groq') {
            $groqPath = __DIR__ . '/groq-client.php';
            if (file_exists($groqPath)) {
                require_once $groqPath;
                if (class_exists('GroqClient')) {
                    static $groq = null;
                    if ($groq === null) $groq = new GroqClient();
                    $r = $groq->send($systemPrompt, $messages, $maxTokens, $jsonMode);
                    if ($r['success']) {
                        return $r;
                    }
                    // On Groq failure, fall through to Claude as backup
                    error_log('[claude-client] Groq failed, falling back to Claude: ' . ($r['error'] ?? 'unknown'));
                }
            }
        }

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
                sleep(pow(2, $attempt)); // 2s, 4s exponential backoff
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
     * Send with vision (images).
     *
     * VISION ROUTING (cost-optimized cascade):
     *   VISION_PROVIDER=openai   -> OpenAI GPT-4o-mini only (cheapest, ~20x less than Claude Sonnet)
     *   VISION_PROVIDER=claude   -> Claude only (best quality, most expensive)
     *   VISION_PROVIDER=cascade  -> GPT-4o-mini first; if it fails or returns garbage, fall back to Claude (DEFAULT)
     *
     * @param array $images Array of ['data' => base64, 'mime' => 'image/jpeg']
     */
    public function sendWithVision(string $systemPrompt, array $images, string $textPrompt, int $maxTokens = 8192): array {
        $vp = $_ENV['VISION_PROVIDER'] ?? getenv('VISION_PROVIDER') ?: 'cascade';

        // Try OpenAI GPT-4o-mini first when allowed
        if ($vp === 'openai' || $vp === 'cascade') {
            $openaiPath = __DIR__ . '/openai-vision-client.php';
            if (file_exists($openaiPath)) {
                require_once $openaiPath;
                if (class_exists('OpenAIVisionClient')) {
                    static $oai = null;
                    if ($oai === null) $oai = new OpenAIVisionClient();
                    $r = $oai->sendWithVision($systemPrompt, $images, $textPrompt, $maxTokens);
                    if ($r['success']) {
                        return $r;
                    }
                    // openai-only mode: don't fall back
                    if ($vp === 'openai') {
                        return $r;
                    }
                    error_log('[claude-client] OpenAI vision failed, falling back to Claude: ' . ($r['error'] ?? 'unknown'));
                }
            }
        }

        // Claude vision (original path)
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
        // Force the call to go directly to Anthropic (bypass the Groq router which has no vision)
        return $this->sendDirectClaude($systemPrompt, $messages, $maxTokens);
    }

    /**
     * Force a direct Claude API call, bypassing the Groq router.
     * Used internally by sendWithVision() since Groq has no vision.
     */
    private function sendDirectClaude(string $systemPrompt, array $messages, int $maxTokens = 4096): array {
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
                sleep(pow(2, $attempt));
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
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) { $lastError = 'cURL: ' . $curlError; continue; }
            if ($httpCode === 529 || $httpCode === 503) { $lastError = "API overloaded ({$httpCode})"; continue; }
            if ($httpCode !== 200) {
                $err = json_decode($response, true);
                return ['success' => false, 'error' => 'Claude vision: ' . ($err['error']['message'] ?? "HTTP {$httpCode}")];
            }
            $result = json_decode($response, true);
            if (!isset($result['content'][0]['text'])) {
                return ['success' => false, 'error' => 'Invalid Claude response'];
            }
            return [
                'success' => true,
                'text' => $result['content'][0]['text'],
                'input_tokens' => $result['usage']['input_tokens'] ?? 0,
                'output_tokens' => $result['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0),
                'model' => $result['model'] ?? $this->model,
                'provider' => 'claude',
            ];
        }
        return ['success' => false, 'error' => $lastError ?: 'Max retries exceeded'];
    }

    /**
     * Convenience static helper: get raw text from a single user prompt.
     * Routes through provider router (Groq when AI_PROVIDER=groq).
     * Returns null on failure. Use this for one-shot text generation.
     *
     * Usage:
     *   $reply = ClaudeClient::text("Diga ola", "", 200);
     */
    public static function text(string $userPrompt, string $systemPrompt = '', int $maxTokens = 1024): ?string {
        static $instance = null;
        if ($instance === null) $instance = new self();
        $r = $instance->send($systemPrompt, [['role' => 'user', 'content' => $userPrompt]], $maxTokens);
        return $r['success'] ? ($r['text'] ?? null) : null;
    }

    /**
     * Fast tier: routes to Groq Llama 3.1 8B Instant when AI_PROVIDER=groq.
     * Use for short-form, high-volume tasks: classification, spam detection,
     * push copy, FAQ generation, search suggestions, birthday greetings.
     * ~3x faster than 70B and far cheaper. Falls back to 70B then Claude on failure.
     *
     * Usage:
     *   $reply = ClaudeClient::sendFast("classifique como spam: ...", "", 100);
     */
    public static function sendFast(string $userPrompt, string $systemPrompt = '', int $maxTokens = 512): ?string {
        $provider = $_ENV['AI_PROVIDER'] ?? getenv('AI_PROVIDER') ?: 'claude';
        if ($provider === 'groq') {
            $groqPath = __DIR__ . '/groq-client.php';
            if (file_exists($groqPath)) {
                require_once $groqPath;
                if (class_exists('GroqClient')) {
                    static $fastGroq = null;
                    if ($fastGroq === null) $fastGroq = new GroqClient(GroqClient::FAST_MODEL);
                    $r = $fastGroq->send($systemPrompt, [['role' => 'user', 'content' => $userPrompt]], $maxTokens);
                    if ($r['success']) {
                        return $r['text'] ?? null;
                    }
                    error_log('[claude-client] Groq fast tier failed, falling back to 70B: ' . ($r['error'] ?? 'unknown'));
                }
            }
        }
        // Fall back to default tier (70B Groq or Claude)
        return self::text($userPrompt, $systemPrompt, $maxTokens);
    }

    /**
     * sendFast variant that GUARANTEES valid JSON output by retrying with the
     * 70B model if the 8B response fails to parse. Use for any callsite that
     * expects structured JSON (FAQ, spam, push variants, search suggestions).
     *
     * Returns the parsed array, or null if both tiers fail.
     */
    public static function sendFastJson(string $userPrompt, string $systemPrompt = '', int $maxTokens = 1024): ?array {
        $reply = self::sendFast($userPrompt, $systemPrompt, $maxTokens);
        if ($reply) {
            $parsed = self::parseJson($reply);
            if (is_array($parsed) && !empty($parsed)) {
                return $parsed;
            }
            error_log('[claude-client] sendFastJson: 8B returned invalid/truncated JSON, retrying with 70B');
        }
        // Fallback to 70B tier (or Claude if Groq down)
        $reply2 = self::text($userPrompt, $systemPrompt, $maxTokens);
        if (!$reply2) return null;
        $parsed2 = self::parseJson($reply2);
        return is_array($parsed2) ? $parsed2 : null;
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
