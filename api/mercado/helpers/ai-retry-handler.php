<?php
/**
 * AI Retry Handler with Circuit Breaker
 *
 * Robust retry/fallback chain for Claude API calls.
 * Implements progressive prompt simplification and a circuit breaker
 * to protect against cascading failures.
 *
 * Used by: webhooks/twilio-voice-ai.php, whatsapp-ai.php
 * Depends on: config/database.php (getDB), helpers/claude-client.php
 *
 * Design principles:
 *   - Progressive degradation: full prompt -> simplified -> minimal -> static fallback
 *   - Circuit breaker prevents hammering a failing API
 *   - Every attempt is logged for observability
 *   - Never throw exceptions — always return a usable response
 *   - error_log() with [ai-retry] prefix
 */

require_once __DIR__ . '/../helpers/claude-client.php';


// =============================================================================
// 1. Main Retry Function
// =============================================================================

/**
 * Call Claude API with progressive retry and fallback chain.
 *
 * Flow:
 *   1. Full prompt, normal call
 *   2. Simplified prompt (last 4 messages, reduced max_tokens)
 *   3. Minimal prompt ("Responda em 1 frase" + last message only)
 *   4. Static step-aware fallback (no Claude call)
 *
 * @param string $systemPrompt  Full system prompt
 * @param array  $messages      Conversation messages [{role, content}, ...]
 * @param array  $options       Optional config:
 *   - max_retries (int)         Max retry attempts (default 3)
 *   - timeout_ms (int)          Timeout in milliseconds (default 15000)
 *   - conversation_type (string) 'voice' or 'whatsapp'
 *   - conversation_id (int)     Conversation ID for logging
 *   - step (string)             Current conversation step
 *   - language (string)         Detected language: 'pt', 'en', 'es'
 * @return array{success: bool, text: string, attempt: int, fallback_used: string, response_time_ms: int}
 */
function callClaudeWithRetry(string $systemPrompt, array $messages, array $options = []): array {
    $maxRetries = (int)($options['max_retries'] ?? 3);
    $convType = $options['conversation_type'] ?? 'unknown';
    $convId = (int)($options['conversation_id'] ?? 0);
    $step = $options['step'] ?? 'unknown';
    $language = $options['language'] ?? 'pt';

    // Check circuit breaker first
    $circuitStatus = checkCircuitBreaker();
    if ($circuitStatus['open']) {
        error_log("[ai-retry] Circuit breaker OPEN (error_rate={$circuitStatus['error_rate']}%) — using fallback directly");
        $fallbackText = _retryGetStepFallback($step, $language);
        _retryLogAttempt($convType, $convId, 0, 'circuit_breaker_open', 'Circuit breaker open', 'fallback_message', false, 0);
        return [
            'success' => true,
            'text' => $fallbackText,
            'attempt' => 0,
            'fallback_used' => 'circuit_breaker_fallback',
            'response_time_ms' => 0,
            'circuit_breaker' => true,
        ];
    }

    $claude = new ClaudeClient('claude-sonnet-4-20250514', 30, 0); // No internal retries — we handle them

    // Attempt 1: Full prompt
    if ($maxRetries >= 1) {
        $startMs = _retryTimeMs();
        $result = $claude->send($systemPrompt, $messages, 1024);
        $elapsed = _retryTimeMs() - $startMs;

        if ($result['success'] && !empty($result['text'])) {
            _retryLogAttempt($convType, $convId, 1, null, null, 'none', true, $elapsed);
            return [
                'success' => true,
                'text' => $result['text'],
                'attempt' => 1,
                'fallback_used' => 'none',
                'response_time_ms' => $elapsed,
            ];
        }

        $errorType = _retryClassifyError($result['error'] ?? 'unknown');
        $errorMsg = $result['error'] ?? 'Unknown error';
        _retryLogAttempt($convType, $convId, 1, $errorType, $errorMsg, 'retry', false, $elapsed);
        error_log("[ai-retry] Attempt 1 failed: {$errorMsg} ({$elapsed}ms) conv={$convType}:{$convId}");
    }

    // Attempt 2: Simplified prompt (trim history, reduce tokens)
    if ($maxRetries >= 2) {
        $simplifiedMessages = _retrySimplifyMessages($messages, 4);
        $simplifiedPrompt = _retrySimplifyPrompt($systemPrompt);

        $startMs = _retryTimeMs();
        $result = $claude->send($simplifiedPrompt, $simplifiedMessages, 300);
        $elapsed = _retryTimeMs() - $startMs;

        if ($result['success'] && !empty($result['text'])) {
            _retryLogAttempt($convType, $convId, 2, null, null, 'simplified_prompt', true, $elapsed);
            return [
                'success' => true,
                'text' => $result['text'],
                'attempt' => 2,
                'fallback_used' => 'simplified_prompt',
                'response_time_ms' => $elapsed,
            ];
        }

        $errorType = _retryClassifyError($result['error'] ?? 'unknown');
        $errorMsg = $result['error'] ?? 'Unknown error';
        _retryLogAttempt($convType, $convId, 2, $errorType, $errorMsg, 'retry', false, $elapsed);
        error_log("[ai-retry] Attempt 2 failed: {$errorMsg} ({$elapsed}ms) conv={$convType}:{$convId}");
    }

    // Attempt 3: Minimal prompt (1 sentence response, last message only)
    if ($maxRetries >= 3) {
        $lastMessage = _retryGetLastUserMessage($messages);
        $minimalPrompt = _retryGetMinimalPrompt($language);
        $minimalMessages = [
            ['role' => 'user', 'content' => $lastMessage],
        ];

        $startMs = _retryTimeMs();
        $result = $claude->send($minimalPrompt, $minimalMessages, 150);
        $elapsed = _retryTimeMs() - $startMs;

        if ($result['success'] && !empty($result['text'])) {
            _retryLogAttempt($convType, $convId, 3, null, null, 'minimal_prompt', true, $elapsed);
            return [
                'success' => true,
                'text' => $result['text'],
                'attempt' => 3,
                'fallback_used' => 'minimal_prompt',
                'response_time_ms' => $elapsed,
            ];
        }

        $errorType = _retryClassifyError($result['error'] ?? 'unknown');
        $errorMsg = $result['error'] ?? 'Unknown error';
        _retryLogAttempt($convType, $convId, 3, $errorType, $errorMsg, 'fallback_message', false, $elapsed);
        error_log("[ai-retry] Attempt 3 failed: {$errorMsg} ({$elapsed}ms) conv={$convType}:{$convId}");
    }

    // Final fallback: Static step-aware message (no Claude call)
    error_log("[ai-retry] All attempts exhausted — using static fallback conv={$convType}:{$convId} step={$step}");
    $fallbackText = _retryGetStepFallback($step, $language);
    _retryLogAttempt($convType, $convId, $maxRetries + 1, 'all_failed', 'All retry attempts exhausted', 'fallback_message', false, 0);

    return [
        'success' => true, // Still "success" because we return a usable message
        'text' => $fallbackText,
        'attempt' => $maxRetries + 1,
        'fallback_used' => 'static_fallback',
        'response_time_ms' => 0,
    ];
}


// =============================================================================
// 2. Circuit Breaker
// =============================================================================

/**
 * Check if the circuit breaker is open (too many recent failures).
 *
 * If error rate > 15% in the last 5 minutes, the circuit opens and
 * all Claude calls are skipped in favor of static fallbacks.
 *
 * @return array{open: bool, error_rate: float, total_recent: int, failed_recent: int}
 */
function checkCircuitBreaker(): array {
    try {
        $db = getDB();

        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) as failed
            FROM om_ai_retry_log
            WHERE created_at >= NOW() - INTERVAL '5 minutes'
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total = (int)($row['total'] ?? 0);
        $failed = (int)($row['failed'] ?? 0);

        // Need at least 10 requests to evaluate
        if ($total < 10) {
            return [
                'open' => false,
                'error_rate' => 0.0,
                'total_recent' => $total,
                'failed_recent' => $failed,
            ];
        }

        $errorRate = round(($failed / $total) * 100, 1);
        $isOpen = $errorRate > 15.0;

        if ($isOpen) {
            error_log("[ai-retry] Circuit breaker check: OPEN error_rate={$errorRate}% ({$failed}/{$total})");
        }

        return [
            'open' => $isOpen,
            'error_rate' => $errorRate,
            'total_recent' => $total,
            'failed_recent' => $failed,
        ];

    } catch (Exception $e) {
        error_log("[ai-retry] Circuit breaker check failed: " . $e->getMessage());
        // On DB error, keep circuit closed to not block all calls
        return [
            'open' => false,
            'error_rate' => 0.0,
            'total_recent' => 0,
            'failed_recent' => 0,
        ];
    }
}


// =============================================================================
// 3. Retry Dashboard
// =============================================================================

/**
 * Get retry/fallback metrics dashboard.
 *
 * @param PDO    $db     Database connection
 * @param string $period '1h', '24h', '7d', '30d'
 * @return array Dashboard data
 */
function getRetryDashboard(PDO $db, string $period = '24h'): array {
    try {
        $intervalMap = [
            '1h' => '1 hour',
            '24h' => '24 hours',
            '7d' => '7 days',
            '30d' => '30 days',
        ];
        $interval = $intervalMap[$period] ?? '24 hours';

        // Total attempts & success rate
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total_attempts,
                SUM(CASE WHEN success = TRUE THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) as failed,
                ROUND(AVG(response_time_ms), 0) as avg_response_time_ms,
                MAX(response_time_ms) as max_response_time_ms
            FROM om_ai_retry_log
            WHERE created_at >= NOW() - INTERVAL '{$interval}'
        ");
        $stmt->execute();
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalAttempts = (int)($summary['total_attempts'] ?? 0);
        $successful = (int)($summary['successful'] ?? 0);
        $successRate = $totalAttempts > 0 ? round(($successful / $totalAttempts) * 100, 1) : 0;

        // Errors by type
        $stmt = $db->prepare("
            SELECT
                error_type,
                COUNT(*) as count,
                ROUND(AVG(response_time_ms), 0) as avg_response_time_ms
            FROM om_ai_retry_log
            WHERE created_at >= NOW() - INTERVAL '{$interval}'
            AND error_type IS NOT NULL
            GROUP BY error_type
            ORDER BY count DESC
        ");
        $stmt->execute();
        $errorsByType = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback usage
        $stmt = $db->prepare("
            SELECT
                fallback_used,
                COUNT(*) as count
            FROM om_ai_retry_log
            WHERE created_at >= NOW() - INTERVAL '{$interval}'
            AND fallback_used IS NOT NULL
            GROUP BY fallback_used
            ORDER BY count DESC
        ");
        $stmt->execute();
        $fallbackUsage = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Attempt distribution
        $stmt = $db->prepare("
            SELECT
                attempt_number,
                COUNT(*) as count,
                SUM(CASE WHEN success = TRUE THEN 1 ELSE 0 END) as successes
            FROM om_ai_retry_log
            WHERE created_at >= NOW() - INTERVAL '{$interval}'
            GROUP BY attempt_number
            ORDER BY attempt_number ASC
        ");
        $stmt->execute();
        $attemptDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hourly trend (last 24h or matching period)
        $stmt = $db->prepare("
            SELECT
                DATE_TRUNC('hour', created_at) as hour,
                COUNT(*) as total,
                SUM(CASE WHEN success = TRUE THEN 1 ELSE 0 END) as successes,
                SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) as failures
            FROM om_ai_retry_log
            WHERE created_at >= NOW() - INTERVAL '{$interval}'
            GROUP BY DATE_TRUNC('hour', created_at)
            ORDER BY hour ASC
        ");
        $stmt->execute();
        $hourlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Current circuit breaker status
        $circuitStatus = checkCircuitBreaker();

        return [
            'period' => $period,
            'total_attempts' => $totalAttempts,
            'successful' => $successful,
            'failed' => (int)($summary['failed'] ?? 0),
            'success_rate' => $successRate,
            'avg_response_time_ms' => (int)($summary['avg_response_time_ms'] ?? 0),
            'max_response_time_ms' => (int)($summary['max_response_time_ms'] ?? 0),
            'errors_by_type' => $errorsByType,
            'fallback_usage' => $fallbackUsage,
            'attempt_distribution' => $attemptDistribution,
            'hourly_trend' => $hourlyTrend,
            'circuit_breaker' => $circuitStatus,
        ];

    } catch (Exception $e) {
        error_log("[ai-retry] getRetryDashboard error: " . $e->getMessage());
        return [
            'period' => $period,
            'total_attempts' => 0,
            'success_rate' => 0,
            'error' => 'Failed to load dashboard',
            'circuit_breaker' => ['open' => false, 'error_rate' => 0],
        ];
    }
}


// =============================================================================
// 4. Internal Helpers
// =============================================================================

/**
 * Simplify messages: keep only the last N messages.
 * @internal
 */
function _retrySimplifyMessages(array $messages, int $keepLast = 4): array {
    if (count($messages) <= $keepLast) {
        return $messages;
    }
    return array_slice($messages, -$keepLast);
}

/**
 * Simplify system prompt by trimming to essential instructions.
 * @internal
 */
function _retrySimplifyPrompt(string $prompt): string {
    // If prompt is short enough, return as-is
    if (mb_strlen($prompt, 'UTF-8') <= 500) {
        return $prompt;
    }

    // Take first 500 chars (usually the core instruction) + add simplified directive
    $trimmed = mb_substr($prompt, 0, 500, 'UTF-8');
    // Cut at last complete sentence
    $lastPeriod = mb_strrpos($trimmed, '.', 0, 'UTF-8');
    if ($lastPeriod !== false && $lastPeriod > 200) {
        $trimmed = mb_substr($trimmed, 0, $lastPeriod + 1, 'UTF-8');
    }

    return $trimmed . "\n\nIMPORTANTE: Responda de forma curta e direta. Maximo 2 frases.";
}

/**
 * Get the last user message from the conversation.
 * @internal
 */
function _retryGetLastUserMessage(array $messages): string {
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (($messages[$i]['role'] ?? '') === 'user') {
            return $messages[$i]['content'] ?? '';
        }
    }
    return '';
}

/**
 * Get minimal prompt for last-resort Claude call.
 * @internal
 */
function _retryGetMinimalPrompt(string $language): string {
    $prompts = [
        'pt' => 'Voce e um atendente do SuperBora (app de delivery de supermercado). Responda em 1 frase curta e amigavel ao cliente. Se nao souber o que responder, pergunte como pode ajudar.',
        'en' => 'You are a SuperBora assistant (grocery delivery app). Reply in 1 short, friendly sentence. If unsure, ask how you can help.',
        'es' => 'Eres un asistente de SuperBora (app de delivery de supermercado). Responde en 1 frase corta y amigable. Si no sabes que responder, pregunta como puedes ayudar.',
    ];
    return $prompts[$language] ?? $prompts['pt'];
}

/**
 * Get step-aware static fallback message (no Claude call).
 * @internal
 */
function _retryGetStepFallback(string $step, string $language): string {
    $fallbacks = [
        'pt' => [
            'greeting' => 'Ola! Bem-vindo ao SuperBora. Como posso ajudar voce hoje?',
            'identify_store' => 'De qual loja voce gostaria de pedir? Temos varias opcoes disponiveis.',
            'take_order' => 'O que voce gostaria de pedir? Pode me dizer os itens e as quantidades.',
            'review_order' => 'Vamos revisar seu pedido. Deseja adicionar mais alguma coisa ou podemos confirmar?',
            'address' => 'Para qual endereco devemos entregar seu pedido?',
            'payment' => 'Como voce gostaria de pagar? Aceitamos PIX, cartao de credito e debito.',
            'confirm_order' => 'Posso confirmar seu pedido? Tudo certo para finalizar?',
            'submit_order' => 'Seu pedido esta sendo processado. Obrigado pela preferencia!',
            'support' => 'Entendo. Vou transferir voce para nosso suporte para ajudar melhor.',
            'order_status' => 'Deixe-me verificar o status do seu pedido. Um momento, por favor.',
            'transfer_agent' => 'Vou transferir voce para um atendente. Um momento, por favor.',
            'end' => 'Obrigado por usar o SuperBora! Tenha um otimo dia.',
        ],
        'en' => [
            'greeting' => 'Hello! Welcome to SuperBora. How can I help you today?',
            'identify_store' => 'Which store would you like to order from? We have several options available.',
            'take_order' => 'What would you like to order? You can tell me the items and quantities.',
            'review_order' => 'Let\'s review your order. Would you like to add anything else or shall we confirm?',
            'address' => 'What is the delivery address for your order?',
            'payment' => 'How would you like to pay? We accept PIX, credit and debit cards.',
            'confirm_order' => 'Can I confirm your order? Everything ready to finalize?',
            'submit_order' => 'Your order is being processed. Thank you!',
            'support' => 'I understand. Let me transfer you to our support team for better assistance.',
            'order_status' => 'Let me check your order status. One moment please.',
            'transfer_agent' => 'I\'ll transfer you to an agent. One moment please.',
            'end' => 'Thank you for using SuperBora! Have a great day.',
        ],
        'es' => [
            'greeting' => 'Hola! Bienvenido a SuperBora. Como puedo ayudarte hoy?',
            'identify_store' => 'De cual tienda te gustaria pedir? Tenemos varias opciones disponibles.',
            'take_order' => 'Que te gustaria pedir? Puedes decirme los items y las cantidades.',
            'review_order' => 'Vamos a revisar tu pedido. Quieres agregar algo mas o confirmamos?',
            'address' => 'A cual direccion debemos entregar tu pedido?',
            'payment' => 'Como te gustaria pagar? Aceptamos PIX, tarjeta de credito y debito.',
            'confirm_order' => 'Puedo confirmar tu pedido? Todo listo para finalizar?',
            'submit_order' => 'Tu pedido esta siendo procesado. Gracias!',
            'support' => 'Entiendo. Voy a transferirte a nuestro soporte para ayudarte mejor.',
            'order_status' => 'Dejame verificar el estado de tu pedido. Un momento por favor.',
            'transfer_agent' => 'Voy a transferirte a un agente. Un momento por favor.',
            'end' => 'Gracias por usar SuperBora! Que tengas un excelente dia.',
        ],
    ];

    $langFallbacks = $fallbacks[$language] ?? $fallbacks['pt'];
    return $langFallbacks[$step] ?? $langFallbacks['greeting'];
}

/**
 * Classify error type from error message string.
 * @internal
 */
function _retryClassifyError(string $error): string {
    $lower = mb_strtolower($error, 'UTF-8');

    if (strpos($lower, 'timeout') !== false || strpos($lower, 'timed out') !== false) {
        return 'timeout';
    }
    if (strpos($lower, 'overloaded') !== false || strpos($lower, '529') !== false || strpos($lower, '503') !== false) {
        return 'api_overloaded';
    }
    if (strpos($lower, 'rate limit') !== false || strpos($lower, '429') !== false) {
        return 'rate_limited';
    }
    if (strpos($lower, 'content') !== false && strpos($lower, 'filter') !== false) {
        return 'content_filter';
    }
    if (strpos($lower, 'invalid') !== false && strpos($lower, 'response') !== false) {
        return 'invalid_response';
    }
    if (strpos($lower, 'api_key') !== false || strpos($lower, 'authentication') !== false || strpos($lower, '401') !== false) {
        return 'auth_error';
    }
    if (strpos($lower, 'curl') !== false || strpos($lower, 'connection') !== false) {
        return 'network_error';
    }
    if (strpos($lower, '500') !== false || strpos($lower, 'internal') !== false) {
        return 'api_internal_error';
    }

    return 'unknown';
}

/**
 * Log a retry attempt to the om_ai_retry_log table.
 * @internal
 */
function _retryLogAttempt(
    string $convType,
    int $convId,
    int $attemptNumber,
    ?string $errorType,
    ?string $errorMessage,
    ?string $fallbackUsed,
    bool $success,
    int $responseTimeMs
): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO om_ai_retry_log (
                conversation_type, conversation_id, attempt_number,
                error_type, error_message, fallback_used,
                success, response_time_ms, created_at
            ) VALUES (
                :conv_type, :conv_id, :attempt,
                :error_type, :error_msg, :fallback,
                :success, :response_time, NOW()
            )
        ");
        $stmt->execute([
            ':conv_type' => $convType,
            ':conv_id' => $convId,
            ':attempt' => $attemptNumber,
            ':error_type' => $errorType,
            ':error_msg' => $errorMessage !== null ? mb_substr($errorMessage, 0, 500, 'UTF-8') : null,
            ':fallback' => $fallbackUsed,
            ':success' => $success ? 'true' : 'false',
            ':response_time' => $responseTimeMs,
        ]);
    } catch (Exception $e) {
        // Never fail the main flow for logging
        error_log("[ai-retry] Failed to log attempt: " . $e->getMessage());
    }
}

/**
 * Get current time in milliseconds.
 * @internal
 */
function _retryTimeMs(): int {
    return (int)(microtime(true) * 1000);
}
