<?php
/**
 * AI Conversation Safeguards
 *
 * Safety guardrails for the AI call center system (voice + WhatsApp).
 * Validates Claude responses, monitors conversation health, prevents abuse,
 * handles graceful degradation, and optimizes Claude API usage.
 *
 * Used by: webhooks/twilio-voice-ai.php, whatsapp-ai.php
 * Depends on: config/database.php (getDB), helpers/claude-client.php
 *
 * Design principles:
 *   - All functions < 5ms (real-time phone calls)
 *   - Never throw exceptions -- always return structured results
 *   - Conservative validation (false positives hurt customer experience)
 *   - error_log() with [ai-safeguards] prefix
 *   - No shared state between calls
 *   - No DB queries in hot-path validation (except abuse check for rate limiting)
 */


// =============================================================================
// 1. Response Validation
// =============================================================================

/**
 * Validate a Claude AI response for safety, accuracy, and step appropriateness.
 *
 * @param string $response  Claude's raw text response
 * @param string $step      Current conversation step
 * @param array  $context   AI context:
 *   - channel (string) 'voice' or 'whatsapp'
 *   - menu_products (array) products with name, price, special_price
 *   - items (array) draft order items with name, price, quantity
 *   - store_names (array) known store name strings
 *   - store_name (string) currently selected store
 * @return array{valid: bool, issues: string[], sanitized: string}
 */
function aiSafeguardValidateResponse(string $response, string $step, array $context): array {
    $issues = [];
    $sanitized = trim($response);
    $channel = $context['channel'] ?? 'voice';
    $maxLen = $channel === 'voice' ? 500 : 1000;

    // -- Empty / too short --
    if (mb_strlen($sanitized, 'UTF-8') < 5) {
        $issues[] = 'response_too_short';
        error_log("[ai-safeguards] Response too short (" . mb_strlen($sanitized, 'UTF-8') . " chars) step={$step}");
        return ['valid' => false, 'issues' => $issues, 'sanitized' => ''];
    }

    // -- Too long -- truncate at sentence boundary --
    if (mb_strlen($sanitized, 'UTF-8') > $maxLen) {
        $issues[] = 'response_too_long';
        $truncated = mb_substr($sanitized, 0, $maxLen, 'UTF-8');
        $lastPeriod = mb_strrpos($truncated, '.', 0, 'UTF-8');
        $lastExcl = mb_strrpos($truncated, '!', 0, 'UTF-8');
        $lastQuestion = mb_strrpos($truncated, '?', 0, 'UTF-8');
        $cutAt = max($lastPeriod ?: 0, $lastExcl ?: 0, $lastQuestion ?: 0);
        if ($cutAt > (int)($maxLen * 0.4)) {
            $sanitized = mb_substr($truncated, 0, $cutAt + 1, 'UTF-8');
        } else {
            $sanitized = $truncated;
        }
    }

    // -- Strip internal markers that should never reach the user --
    $internalPatterns = [
        '/\[STORE:(?:ID:)?\d+:[^\]]*\]/',
        '/\[ITEM:\d+:\d+:[\d.]+:[^\]]*\](?:\[OPT:[\d,]+\])?/',
        '/\[NEXT_STEP\]/',
        '/\[CONFIRMED\]/',
        '/\[PAYMENT:\w+(?::\d+)?\]/',
        '/\[ADDRESS:\d+\]/',
        '/\[ADDRESS_TEXT:[^\]]*\]/',
        '/\[CEP:\d+\]/',
        '/\[REMOVE_ITEM:\d+\]/',
        '/\[UPDATE_QTY:\d+:\d+\]/',
        '/\[CANCEL_ORDER:[^\]]*\]/',
        '/\[ORDER_STATUS:[^\]]*\]/',
        '/\[SWITCH_TO_ORDER\]/',
        '/\[SCHEDULE:[^\]]*\]/',
        '/\[DEBUG:[^\]]*\]/i',
        '/\[INTERNAL:[^\]]*\]/i',
        '/\[SYSTEM:[^\]]*\]/i',
    ];
    foreach ($internalPatterns as $pat) {
        if (preg_match($pat, $sanitized)) {
            $issues[] = 'internal_marker_leaked';
        }
        $sanitized = preg_replace($pat, '', $sanitized);
    }

    // -- Profanity in AI response (should never happen, but safety net) --
    $profanityResult = aiSafeguardProfanityCheck($sanitized);
    if ($profanityResult['has_profanity'] && $profanityResult['severity'] !== 'mild') {
        $issues[] = 'response_profanity';
        $sanitized = $profanityResult['cleaned'];
        error_log("[ai-safeguards] Profanity in AI response step={$step} severity={$profanityResult['severity']}");
    }

    // -- Competitor names / URLs --
    $competitors = ['ifood', 'i food', 'rappi', 'uber eats', 'ubereats', 'james delivery',
                    'zdelivery', 'ze delivery', 'aiqfome', 'daki', 'grubhub', 'doordash'];
    $lowerResponse = mb_strtolower($sanitized, 'UTF-8');
    foreach ($competitors as $comp) {
        if (mb_strpos($lowerResponse, $comp) !== false) {
            $issues[] = 'competitor_mention';
            $sanitized = preg_replace('/' . preg_quote($comp, '/') . '/iu', 'outro app', $sanitized);
            error_log("[ai-safeguards] Competitor mention '{$comp}' in response step={$step}");
            break;
        }
    }

    // -- URLs in response --
    if (preg_match('#https?://[^\s]+#i', $sanitized)) {
        $issues[] = 'contains_url';
        $sanitized = preg_replace('#https?://[^\s]+#i', '[link removido]', $sanitized);
    }

    // -- Phone numbers that shouldn't be shared --
    if (preg_match('/\(?\d{2}\)?\s*\d{4,5}[-.\s]?\d{4}/', $sanitized)) {
        $issues[] = 'contains_phone_number';
        error_log("[ai-safeguards] Phone number in AI response step={$step}");
    }

    // -- CPF/CNPJ detection --
    if (preg_match('/\d{3}\.\d{3}\.\d{3}-\d{2}/', $sanitized) ||
        preg_match('/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/', $sanitized)) {
        $issues[] = 'contains_personal_data';
        error_log("[ai-safeguards] Personal document in AI response step={$step}");
    }

    // -- AI self-reference check --
    $aiSelfRef = [
        '/\bcomo (uma |a )?intelig.ncia artificial\b/iu',
        '/\bsou (um |uma )?(rob.|robo|bot|programa)\b/iu',
        '/\blanguage model\b/i',
        '/\bClaude\b/',
        '/\bAnthropic\b/i',
        '/\bOpenAI\b/i',
        '/\bGPT\b/',
    ];
    foreach ($aiSelfRef as $pat) {
        if (preg_match($pat, $sanitized)) {
            $issues[] = 'ai_self_reference';
            $sanitized = preg_replace($pat, '', $sanitized);
        }
    }

    // -- English contamination --
    $englishPatterns = [
        '/\b(sorry|please|thank you|hello|hi there|how can I help|unfortunately|certainly|absolutely|wonderful|great choice|excellent|I understand|let me|would you like|I\'d be happy|I apologize)\b/i',
        '/\b(delivery fee|subtotal|checkout|shopping cart|add to cart|your order|order placed|payment method|credit card|cash on delivery)\b/i',
    ];
    foreach ($englishPatterns as $pat) {
        if (preg_match($pat, $sanitized, $engMatch)) {
            $issues[] = 'english_detected';
            $sanitized = preg_replace($pat, '', $sanitized);
        }
    }

    // -- Price validation against menu data --
    if (!empty($context['menu_products'])) {
        $menuPrices = [];
        foreach ($context['menu_products'] as $product) {
            $pPrice = (float)($product['special_price'] ?: ($product['price'] ?? 0));
            if ($pPrice > 0) {
                $menuPrices[] = $pPrice;
            }
        }
        // Also add item subtotals as valid prices
        $items = $context['items'] ?? [];
        $subtotal = 0.0;
        foreach ($items as $item) {
            $itemPrice = (float)($item['price'] ?? 0);
            $itemQty = (int)($item['quantity'] ?? 1);
            $menuPrices[] = $itemPrice;
            $menuPrices[] = $itemPrice * $itemQty;
            $subtotal += $itemPrice * $itemQty;
        }
        if ($subtotal > 0) {
            $menuPrices[] = $subtotal;
            // Common totals with delivery fees
            for ($fee = 0; $fee <= 15; $fee++) {
                $menuPrices[] = $subtotal + $fee;
            }
        }

        if (preg_match_all('/R\$\s*([\d.,]+)/', $sanitized, $priceMatches) && !empty($menuPrices)) {
            foreach ($priceMatches[1] as $priceStr) {
                $cleaned_price = str_replace('.', '', $priceStr); // Remove thousand sep
                $cleaned_price = str_replace(',', '.', $cleaned_price); // Decimal
                $claimedPrice = (float)$cleaned_price;
                if ($claimedPrice <= 0) continue;

                $priceFound = false;
                foreach ($menuPrices as $vp) {
                    if ($vp <= 0) continue;
                    $diff = abs($claimedPrice - $vp);
                    if ($diff < 0.02 || ($vp > 0 && ($diff / $vp) < 0.01)) {
                        $priceFound = true;
                        break;
                    }
                }
                if (!$priceFound && $claimedPrice > 0.50) {
                    $issues[] = 'unverified_price';
                    error_log("[ai-safeguards] Unverified price R\${$claimedPrice} step={$step}");
                }
            }
        }
    }

    // -- Hallucinated store name --
    if (!empty($context['store_names']) && ($step === 'identify_store' || $step === 'greeting')) {
        $knownStores = array_map(function ($s) {
            $clean = preg_replace('/\s*\(ID:\d+\).*$/', '', $s);
            return mb_strtolower(trim($clean), 'UTF-8');
        }, $context['store_names']);

        if (preg_match('/(?:loja|mercado|restaurante|padaria)\s+["\']?([^"\'.,!?]+)/iu', $sanitized, $storeMatch)) {
            $mentioned = mb_strtolower(trim($storeMatch[1]), 'UTF-8');
            $found = false;
            foreach ($knownStores as $known) {
                if (mb_strpos($known, $mentioned) !== false || mb_strpos($mentioned, $known) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found && mb_strlen($mentioned, 'UTF-8') > 3) {
                $issues[] = 'possible_hallucinated_store';
                error_log("[ai-safeguards] Possible hallucinated store '{$mentioned}' step={$step}");
            }
        }
    }

    // -- Hallucinated products --
    if (!empty($context['menu_products']) && $step === 'take_order') {
        $knownProducts = [];
        foreach ($context['menu_products'] as $p) {
            $pName = mb_strtolower(trim($p['name'] ?? ''), 'UTF-8');
            if ($pName !== '') {
                $knownProducts[] = $pName;
            }
        }
        // Check if AI mentions adding a specific product not in the menu
        if (preg_match('/(?:adicionei|anotei|inclui|pedido)[:\s]+["\']?([^"\'.,!?]{4,40})/iu', $sanitized, $prodMatch)) {
            $mentionedProd = mb_strtolower(trim($prodMatch[1]), 'UTF-8');
            $prodFound = false;
            foreach ($knownProducts as $kp) {
                if (mb_strpos($kp, $mentionedProd) !== false || mb_strpos($mentionedProd, $kp) !== false) {
                    $prodFound = true;
                    break;
                }
            }
            if (!$prodFound) {
                $issues[] = 'possible_hallucinated_product';
                error_log("[ai-safeguards] Possible hallucinated product '{$mentionedProd}' step={$step}");
            }
        }
    }

    // -- Wrong step behavior --
    $lowerSan = mb_strtolower($sanitized, 'UTF-8');

    // Confirming order when still identifying store
    if ($step === 'identify_store' || $step === 'greeting') {
        $confirmPhrases = ['pedido confirmado', 'pedido foi enviado', 'seu pedido numero', 'pedido #', 'total do pedido'];
        foreach ($confirmPhrases as $cp) {
            if (mb_strpos($lowerSan, $cp) !== false) {
                $issues[] = 'premature_order_confirm';
                error_log("[ai-safeguards] Premature order confirmation at step={$step}");
                break;
            }
        }
    }

    // Asking for address when no items yet
    if (($step === 'identify_store' || $step === 'greeting') && empty($context['items'])) {
        $addressPhrases = ['qual seu endere', 'qual o endere', 'pra onde entrego', 'endere.o de entrega'];
        foreach ($addressPhrases as $ap) {
            if (preg_match('/' . $ap . '/iu', $lowerSan)) {
                $issues[] = 'premature_address_ask';
                break;
            }
        }
    }

    // Asking for payment when no items
    if (empty($context['items']) && in_array($step, ['identify_store', 'greeting', 'take_order'])) {
        $paymentPhrases = ['forma de pagamento', 'como quer pagar', 'pix ou cart', 'dinheiro ou cart'];
        foreach ($paymentPhrases as $pp) {
            if (mb_strpos($lowerSan, $pp) !== false) {
                $issues[] = 'premature_payment_ask';
                break;
            }
        }
    }

    // -- Strip markdown formatting (bad for voice) --
    $sanitized = preg_replace('/\*\*(.+?)\*\*/', '$1', $sanitized);
    $sanitized = preg_replace('/\*(.+?)\*/', '$1', $sanitized);
    $sanitized = preg_replace('/^#{1,6}\s+/m', '', $sanitized);
    $sanitized = preg_replace('/```[\s\S]*?```/', '', $sanitized);
    $sanitized = preg_replace('/`([^`]+)`/', '$1', $sanitized);
    if ($channel === 'voice') {
        $sanitized = preg_replace('/\n{2,}/', ' ', $sanitized);
        $sanitized = str_replace("\n", ' ', $sanitized);
    }

    // -- Final cleanup --
    $sanitized = preg_replace('/\s+/', ' ', trim($sanitized));
    $sanitized = preg_replace('/^\s*[,;:]\s*/', '', $sanitized);
    $sanitized = preg_replace('/([.!?]){2,}/', '$1', $sanitized);

    // Determine validity -- only hard-fail on critical issues
    $criticalIssues = ['response_too_short', 'response_profanity'];
    $hasCritical = !empty(array_intersect($issues, $criticalIssues));

    if (!empty($issues)) {
        error_log("[ai-safeguards] Validation issues: " . implode(', ', $issues) . " step={$step}");
    }

    return [
        'valid' => !$hasCritical,
        'issues' => $issues,
        'sanitized' => $sanitized,
    ];
}


// =============================================================================
// 2. Conversation Health Monitor
// =============================================================================

/**
 * Check the health of an ongoing AI conversation.
 *
 * @param array $context AI context:
 *   - turn_count (int)
 *   - silence_count (int)
 *   - error_count (int)
 *   - step (string)
 *   - history (array) [{role, content}, ...]
 *   - started_at (string|int) conversation start timestamp
 *   - step_visits (array) {step_name: visit_count}
 *   - items (array) draft order items
 * @return array{healthy: bool, warnings: string[], action: string}
 *   action: 'continue', 'simplify', 'transfer_agent', 'end_call'
 */
function aiSafeguardCheckHealth(array $context): array {
    $warnings = [];
    $action = 'continue';

    $turnCount = (int)($context['turn_count'] ?? 0);
    $silenceCount = (int)($context['silence_count'] ?? 0);
    $errorCount = (int)($context['error_count'] ?? 0);
    $step = $context['step'] ?? 'identify_store';
    $history = $context['history'] ?? [];

    // -- Turn count: stuck conversations --
    if ($turnCount > 30) {
        $warnings[] = 'extremely_long_conversation';
        $action = 'transfer_agent';
    } elseif ($turnCount > 20 && empty($context['items'])) {
        $warnings[] = 'too_many_turns_no_order';
        $action = 'simplify';
    } elseif ($turnCount > 15) {
        $warnings[] = 'long_conversation';
    }

    // -- Silence count --
    if ($silenceCount > 5) {
        $warnings[] = 'customer_may_have_left';
        $action = 'end_call';
    } elseif ($silenceCount > 3) {
        $warnings[] = 'multiple_silences';
    }

    // -- Error count (Claude failures) --
    if ($errorCount > 5) {
        $warnings[] = 'too_many_errors';
        if ($action === 'continue') {
            $action = 'transfer_agent';
        }
    } elseif ($errorCount > 3) {
        $warnings[] = 'multiple_errors';
        if ($action === 'continue') {
            $action = 'simplify';
        }
    }

    // -- Repeated questions by AI (asking same thing 3+ times) --
    if (count($history) >= 6) {
        $aiMessages = [];
        foreach ($history as $msg) {
            if (($msg['role'] ?? '') === 'assistant') {
                $aiMessages[] = mb_strtolower(trim($msg['content'] ?? ''), 'UTF-8');
            }
        }
        if (count($aiMessages) >= 3) {
            $lastThree = array_slice($aiMessages, -3);
            if (_aiSafeguardTextSimilarity($lastThree[0], $lastThree[1]) > 0.6 &&
                _aiSafeguardTextSimilarity($lastThree[1], $lastThree[2]) > 0.6) {
                $warnings[] = 'ai_repeating_questions';
                if ($action === 'continue') {
                    $action = 'simplify';
                }
            }
        }
    }

    // -- Customer frustration indicators --
    $frustrationCount = 0;
    $frustrationWords = ['nao', 'pare', 'chega', 'para', 'basta', 'cansei', 'que saco',
                         'nao entende', 'ja falei', 'repeti', 'de novo', 'desisto',
                         'dificil', 'complicado', 'irritado', 'irritada', 'aff'];
    $recentUserMessages = [];
    foreach (array_slice($history, -10) as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $recentUserMessages[] = mb_strtolower(trim($msg['content'] ?? ''), 'UTF-8');
        }
    }
    foreach ($recentUserMessages as $userMsg) {
        foreach ($frustrationWords as $fw) {
            if (mb_strpos($userMsg, $fw) !== false) {
                $frustrationCount++;
                break; // One match per message
            }
        }
    }
    if ($frustrationCount >= 4) {
        $warnings[] = 'customer_frustrated';
        if ($action === 'continue' || $action === 'simplify') {
            $action = 'transfer_agent';
        }
    } elseif ($frustrationCount >= 2) {
        $warnings[] = 'possible_frustration';
    }

    // -- Total conversation duration --
    $startedAt = $context['started_at'] ?? null;
    if ($startedAt) {
        $elapsed = time() - (is_numeric($startedAt) ? (int)$startedAt : strtotime($startedAt));
        if ($elapsed > 900) { // 15 minutes
            $warnings[] = 'conversation_too_long';
            if ($action === 'continue') {
                $action = 'simplify';
            }
        }
    }

    // -- Loop detection (same step visited > 4 times) --
    $stepVisits = $context['step_visits'] ?? [];
    $currentStepVisits = (int)($stepVisits[$step] ?? 0);
    if ($currentStepVisits > 4) {
        $warnings[] = 'step_loop_detected';
        if ($action === 'continue') {
            $action = 'simplify';
        }
        error_log("[ai-safeguards] Step loop: step={$step} visits={$currentStepVisits}");
    }

    $healthy = $action === 'continue' && empty($warnings);

    return [
        'healthy' => $healthy,
        'warnings' => $warnings,
        'action' => $action,
    ];
}

/**
 * Simple text similarity (Jaccard index on word sets).
 * @internal
 */
function _aiSafeguardTextSimilarity(string $a, string $b): float {
    if ($a === '' || $b === '') return 0.0;
    $wordsA = array_unique(preg_split('/\s+/', $a));
    $wordsB = array_unique(preg_split('/\s+/', $b));
    $intersection = count(array_intersect($wordsA, $wordsB));
    $union = count(array_unique(array_merge($wordsA, $wordsB)));
    return $union > 0 ? $intersection / $union : 0.0;
}


// =============================================================================
// 3. Anti-Abuse Protection
// =============================================================================

/**
 * Check if a request from a phone number should be allowed.
 *
 * @param string $phone  Caller phone number (E.164 or raw)
 * @param string $input  User's speech/text input
 * @param PDO    $db     Database connection (used for rate limiting)
 * @return array{allowed: bool, reason: string, action: string}
 *   action: 'allow', 'warn', 'throttle', 'block'
 */
function aiSafeguardCheckAbuse(string $phone, string $input, PDO $db): array {
    $phoneClean = preg_replace('/\D/', '', $phone);
    if (empty($phoneClean)) {
        return ['allowed' => true, 'reason' => '', 'action' => 'allow'];
    }

    // -- Known blocked numbers (in-memory blocklist) --
    $blockedNumbers = [
        // Add known spam/prank numbers here as needed
        // '5500000000000',
    ];
    if (in_array($phoneClean, $blockedNumbers, true)) {
        error_log("[ai-safeguards] Blocked number: {$phoneClean}");
        return ['allowed' => false, 'reason' => 'blocked_number', 'action' => 'block'];
    }

    // -- Input length check --
    $inputLen = mb_strlen($input, 'UTF-8');
    $isVoice = $inputLen <= 500;
    $maxInputLen = $isVoice ? 500 : 2000;
    if ($inputLen > $maxInputLen) {
        error_log("[ai-safeguards] Input too long: {$inputLen} chars from {$phoneClean}");
        return ['allowed' => true, 'reason' => 'input_truncated', 'action' => 'warn'];
    }

    // -- Profanity / harassment --
    if (!empty($input)) {
        $profanity = aiSafeguardProfanityCheck($input);
        if ($profanity['has_profanity'] && $profanity['severity'] === 'severe') {
            error_log("[ai-safeguards] Severe profanity from {$phoneClean}: " . mb_substr($input, 0, 50, 'UTF-8'));
            return ['allowed' => true, 'reason' => 'profanity_severe', 'action' => 'warn'];
        }
    }

    // -- SQL injection / code injection attempts --
    $injectionPatterns = [
        '/(\bunion\b.*\bselect\b|\bselect\b.*\bfrom\b.*\bwhere\b)/i',
        '/(\bdrop\b\s+\btable\b|\bdelete\b\s+\bfrom\b|\binsert\b\s+\binto\b)/i',
        '/<script[\s>]/i',
        '/\{\{.*\}\}/',
        '/\$\{.*\}/',
        '/(__import__|eval\s*\(|exec\s*\(|os\.system)/i',
    ];
    foreach ($injectionPatterns as $pattern) {
        if (preg_match($pattern, $input)) {
            error_log("[ai-safeguards] Injection attempt from {$phoneClean}: " . mb_substr($input, 0, 80, 'UTF-8'));
            return ['allowed' => true, 'reason' => 'injection_attempt', 'action' => 'warn'];
        }
    }

    // -- Prank / gibberish detection --
    if ($inputLen > 10) {
        $digitsOnly = preg_replace('/\D/', '', $input);
        if (strlen($digitsOnly) > 15 && strlen($digitsOnly) >= $inputLen - 2) {
            return ['allowed' => true, 'reason' => 'gibberish_numbers', 'action' => 'warn'];
        }
        $stripped = preg_replace('/\s/', '', mb_strtolower($input, 'UTF-8'));
        $uniqueChars = count(array_unique(mb_str_split($stripped)));
        if ($uniqueChars <= 2 && mb_strlen($stripped, 'UTF-8') > 10) {
            return ['allowed' => true, 'reason' => 'gibberish_repeated', 'action' => 'warn'];
        }
    }

    // -- Rate limiting (using om_callcenter_rate_limit table) --
    try {
        $msgKey = 'ai_msg_' . $phoneClean;

        // Upsert rate counter
        $stmt = $db->prepare("
            INSERT INTO om_callcenter_rate_limit (phone, action_type, window_start, count)
            VALUES (?, 'message', NOW(), 1)
            ON CONFLICT (phone, action_type) DO UPDATE
            SET count = CASE
                WHEN om_callcenter_rate_limit.window_start < NOW() - INTERVAL '1 hour'
                THEN 1
                ELSE om_callcenter_rate_limit.count + 1
            END,
            window_start = CASE
                WHEN om_callcenter_rate_limit.window_start < NOW() - INTERVAL '1 hour'
                THEN NOW()
                ELSE om_callcenter_rate_limit.window_start
            END
            RETURNING count
        ");
        $stmt->execute([$phoneClean]);
        $msgCount = (int)$stmt->fetchColumn();

        if ($msgCount >= 60) {
            error_log("[ai-safeguards] Rate limit (messages): {$phoneClean} has {$msgCount}/60 in last hour");
            return ['allowed' => false, 'reason' => 'rate_limit_messages', 'action' => 'throttle'];
        }
    } catch (\Exception $e) {
        // Fail open -- don't block legitimate users if rate limiting breaks
        error_log("[ai-safeguards] Rate limit check failed: " . $e->getMessage());
    }

    return ['allowed' => true, 'reason' => '', 'action' => 'allow'];
}


// =============================================================================
// 4. Graceful Degradation
// =============================================================================

/**
 * Return a pre-written fallback response when Claude is unavailable.
 *
 * @param string $step        Current conversation step
 * @param int    $errorCount  Number of Claude errors so far in this session
 * @param string $channel     'voice' or 'whatsapp'
 * @return string Fallback message text
 */
function aiSafeguardFallback(string $step, int $errorCount, string $channel = 'voice'): string {

    // After 5 errors: offer agent transfer
    if ($errorCount >= 5) {
        if ($channel === 'voice') {
            return 'Desculpe, estou com dificuldade tecnica. Vou te transferir para um atendente. Um momento.';
        }
        return "Desculpe, estou com dificuldade tecnica no momento.\n\nDigite *0* para falar com um atendente, ou tente novamente mais tarde.";
    }

    // After 3 errors: offer simpler interaction
    if ($errorCount >= 3) {
        if ($channel === 'voice') {
            switch ($step) {
                case 'greeting':
                    return 'Desculpe o problema. De qual loja voce quer pedir?';
                case 'identify_store':
                    return 'Me desculpe a dificuldade. Pode me dizer o nome da loja de novo?';
                case 'take_order':
                    return 'Me desculpe a dificuldade. Pode me dizer o numero do item que voce quer, que eu adiciono.';
                case 'get_address':
                    return 'Desculpe o problema. Pode me falar o CEP do seu endereco?';
                case 'get_payment':
                    return 'Desculpe o problema. Vai ser PIX, cartao ou dinheiro?';
                case 'confirm_order':
                    return 'Desculpe o problema. Voce confirma o pedido? Fala sim ou nao.';
                default:
                    return 'Desculpe o problema. Pode repetir de forma mais simples?';
            }
        }
        // WhatsApp -- numbered options
        switch ($step) {
            case 'greeting':
                return "Desculpe a dificuldade. De qual loja voce gostaria de pedir?";
            case 'identify_store':
                return "Desculpe a dificuldade. Envie o *nome da loja* para continuar.\n\nOu digite *0* para falar com um atendente.";
            case 'take_order':
                return "Desculpe a dificuldade. Para continuar, envie o *numero* do item que deseja:\n\nOu digite *0* para falar com um atendente.";
            case 'get_address':
                return "Desculpe o problema. Envie seu *CEP* (8 digitos) para continuar.\n\nOu digite *0* para falar com um atendente.";
            case 'get_payment':
                return "Desculpe o problema. Escolha a forma de pagamento:\n\n*1* - PIX\n*2* - Cartao na entrega\n*3* - Dinheiro\n\nOu digite *0* para falar com um atendente.";
            case 'confirm_order':
                return "Desculpe o problema. Voce confirma o pedido?\n\nResponda *sim* ou *nao*.";
            default:
                return "Desculpe o problema. Tente novamente de forma mais simples.\n\nOu digite *0* para falar com um atendente.";
        }
    }

    // Normal fallback (1-2 errors) -- step-specific
    if ($channel === 'voice') {
        switch ($step) {
            case 'greeting':
                return 'Oi! Bem-vindo ao SuperBora. De qual loja voce gostaria de pedir?';
            case 'identify_store':
                return 'Desculpa, nao consegui entender. De qual loja voce quer pedir?';
            case 'take_order':
                return 'Desculpa, tive um problema. O que mais voce gostaria de pedir?';
            case 'get_address':
                return 'Desculpa, tive uma falha. Qual o endereco de entrega?';
            case 'get_payment':
                return 'Desculpa, tive um problema. Como voce quer pagar? PIX, cartao ou dinheiro?';
            case 'confirm_order':
                return 'Desculpa, tive uma falha. Voce confirma o pedido? Diz sim ou nao.';
            default:
                return 'Desculpa, tive uma dificuldade. Pode repetir?';
        }
    }

    // WhatsApp normal fallback
    switch ($step) {
        case 'greeting':
            return "Ola! Bem-vindo ao *SuperBora*.\n\nDe qual loja voce gostaria de pedir?";
        case 'identify_store':
            return "Desculpe, nao consegui entender. De qual loja voce quer pedir?";
        case 'take_order':
            return "Desculpe, tive um problema. O que voce gostaria de pedir?\n\nEnvie o nome do item ou o numero do cardapio.";
        case 'get_address':
            return "Desculpe, tive uma falha. Qual o endereco de entrega?\n\nVoce pode enviar o CEP ou o endereco completo.";
        case 'get_payment':
            return "Desculpe, tive um problema. Como voce quer pagar?\n\n*1* - PIX\n*2* - Cartao na entrega\n*3* - Dinheiro";
        case 'confirm_order':
            return "Desculpe, tive uma falha. Voce confirma o pedido?\n\nResponda *sim* ou *nao*.";
        default:
            return "Desculpe, tive uma dificuldade. Pode repetir?";
    }
}


// =============================================================================
// 5. Claude API Optimization
// =============================================================================

/**
 * Optimize Claude API prompt and message history to reduce token usage.
 *
 * @param string $systemPrompt  Full system prompt
 * @param array  $messages      Conversation messages [{role, content}, ...]
 * @param string $step          Current step
 * @return array{system: string, messages: array, estimated_tokens: int}
 */
function aiSafeguardOptimizePrompt(string $systemPrompt, array $messages, string $step): array {
    $optimizedSystem = $systemPrompt;
    $optimizedMessages = $messages;

    // -- Remove irrelevant system prompt sections based on step --
    $systemLen = mb_strlen($optimizedSystem, 'UTF-8');

    if ($systemLen > 6000) {
        // Remove store list if not identifying
        if ($step !== 'identify_store' && $step !== 'greeting') {
            $optimizedSystem = preg_replace(
                '/(?:## LOJAS DISPON.VEIS|--- LOJAS DISPONIVEIS ---)[\s\S]*?(?:## |--- FIM LOJAS ---)/u',
                '[lojas omitidas]',
                $optimizedSystem
            );
        }

        // Truncate menu if not taking order
        if ($step !== 'take_order') {
            $optimizedSystem = preg_replace_callback(
                '/(?:## CARD.PIO|--- CARDAPIO ---)(.{500,}?)(?:## |--- FIM CARDAPIO ---)/su',
                function ($m) {
                    return '--- CARDAPIO ---' . mb_substr($m[1], 0, 500, 'UTF-8') . "\n[cardapio truncado]--- FIM CARDAPIO ---";
                },
                $optimizedSystem
            );
        }

        // For late steps, remove personality/style extras
        if (in_array($step, ['get_payment', 'confirm_order'])) {
            $optimizedSystem = preg_replace('/### Leitura emocional[\s\S]*?(?=### |## )/u', '', $optimizedSystem);
            $optimizedSystem = preg_replace('/### Humor e personalidade[\s\S]*?(?=### |## )/u', '', $optimizedSystem);
        }
    }

    // -- Trim conversation history --
    $msgCount = count($optimizedMessages);

    if ($msgCount > 20) {
        // Keep first 2 messages (initial context) + last 16
        $first = array_slice($optimizedMessages, 0, 2);
        $recent = array_slice($optimizedMessages, -16);
        $skipped = $msgCount - 18;
        $summary = [
            'role' => 'user',
            'content' => "[{$skipped} mensagens anteriores omitidas para brevidade]",
        ];
        $optimizedMessages = array_merge($first, [$summary], $recent);
    }

    // -- Estimate token count (rough: chars / 4 for mixed PT/EN) --
    $systemTokens = (int)(mb_strlen($optimizedSystem, 'UTF-8') / 4);
    $msgTokens = 0;
    foreach ($optimizedMessages as $msg) {
        $msgTokens += (int)(mb_strlen($msg['content'] ?? '', 'UTF-8') / 4) + 4;
    }
    $estimatedTokens = $systemTokens + $msgTokens;

    // -- Aggressive compression if over 8000 tokens --
    if ($estimatedTokens > 8000) {
        $keepCount = 6; // Last 3 exchanges
        if (count($optimizedMessages) > $keepCount) {
            $old = array_slice($optimizedMessages, 0, -$keepCount);
            $recent = array_slice($optimizedMessages, -$keepCount);

            // Compact summary of older messages
            $summaryParts = [];
            foreach ($old as $msg) {
                $role = ($msg['role'] ?? 'user') === 'user' ? 'Cliente' : 'AI';
                $text = mb_substr($msg['content'] ?? '', 0, 80, 'UTF-8');
                if (mb_strlen($msg['content'] ?? '', 'UTF-8') > 80) {
                    $text .= '...';
                }
                $summaryParts[] = $role . ': ' . $text;
            }
            $summaryText = "Resumo da conversa anterior:\n" . implode("\n", $summaryParts);

            $optimizedMessages = array_merge(
                [['role' => 'user', 'content' => $summaryText]],
                [['role' => 'assistant', 'content' => 'Entendido, continuando daqui.']],
                $recent
            );

            // Recalculate
            $msgTokens = 0;
            foreach ($optimizedMessages as $msg) {
                $msgTokens += (int)(mb_strlen($msg['content'] ?? '', 'UTF-8') / 4) + 4;
            }
            $estimatedTokens = $systemTokens + $msgTokens;
        }

        // If still over, truncate system prompt
        if ($estimatedTokens > 8000) {
            $targetSystemLen = max(2000, (8000 - $msgTokens) * 4);
            if (mb_strlen($optimizedSystem, 'UTF-8') > $targetSystemLen) {
                $optimizedSystem = mb_substr($optimizedSystem, 0, $targetSystemLen, 'UTF-8')
                    . "\n[prompt truncado por limite de tokens]";
                $systemTokens = (int)($targetSystemLen / 4);
                $estimatedTokens = $systemTokens + $msgTokens;
            }
        }
    }

    // -- Ensure messages alternate roles (Claude API requirement) --
    $optimizedMessages = _aiSafeguardEnsureAlternating($optimizedMessages);

    return [
        'system' => $optimizedSystem,
        'messages' => $optimizedMessages,
        'estimated_tokens' => $estimatedTokens,
    ];
}

/**
 * Ensure messages alternate between user and assistant roles.
 * Merges consecutive same-role messages.
 * @internal
 */
function _aiSafeguardEnsureAlternating(array $messages): array {
    if (empty($messages)) return [];

    $cleaned = [];
    $lastRole = null;

    foreach ($messages as $msg) {
        $role = $msg['role'] ?? 'user';
        $content = trim($msg['content'] ?? '');
        if ($content === '') continue;

        if ($role === $lastRole && !empty($cleaned)) {
            $idx = count($cleaned) - 1;
            $cleaned[$idx]['content'] .= "\n" . $content;
        } else {
            $cleaned[] = ['role' => $role, 'content' => $content];
            $lastRole = $role;
        }
    }

    // Must start with 'user'
    if (!empty($cleaned) && $cleaned[0]['role'] !== 'user') {
        array_unshift($cleaned, ['role' => 'user', 'content' => '[inicio da conversa]']);
    }

    return $cleaned;
}


// =============================================================================
// 6. Price Validation
// =============================================================================

/**
 * Validate a claimed price against the actual price.
 * Allow 1% tolerance for rounding, minimum R$0.50 tolerance.
 *
 * @param float  $claimed  Price stated by AI or displayed
 * @param float  $actual   Known correct price from database
 * @param string $context  Description for logging (e.g. product name)
 * @return bool True if price is acceptable, false if mismatch
 */
function aiSafeguardValidatePrice(float $claimed, float $actual, string $context = ''): bool {
    if ($actual <= 0) {
        // Can't validate against zero/negative actual price
        return true;
    }

    $diff = abs($claimed - $actual);
    $tolerance = max(0.50, $actual * 0.01);

    if ($diff > $tolerance) {
        error_log("[ai-safeguards] Price mismatch: claimed=R\${$claimed} actual=R\${$actual} diff=R\${$diff} context={$context}");
        return false;
    }

    return true;
}


// =============================================================================
// 7. Profanity Filter (PT-BR)
// =============================================================================

/**
 * Check text for profanity in Brazilian Portuguese.
 * Normalizes text before checking (remove accents, lowercase).
 * Avoids false positives on food words containing profanity substrings.
 *
 * @param string $text Input text
 * @return array{has_profanity: bool, severity: string, cleaned: string}
 *   severity: 'mild', 'moderate', 'severe'
 */
function aiSafeguardProfanityCheck(string $text): array {
    if ($text === '') {
        return ['has_profanity' => false, 'severity' => 'none', 'cleaned' => ''];
    }

    $normalized = mb_strtolower($text, 'UTF-8');
    $normalized = _aiSafeguardRemoveAccents($normalized);

    $cleaned = $text;
    $maxSeverity = 'none';
    $found = false;

    // -- Severe: threats, slurs, extreme abuse --
    $severe = [
        'vai se foder', 'vai tomar no cu', 'filho da puta', 'filha da puta',
        'foda-se', 'fodase', 'foda se', 'puta que pariu',
        'vou te matar', 'vou matar', 'estupro', 'estuprar',
        'pqp', 'vsf', 'vtnc', 'fdp', 'tnc',
    ];

    // -- Moderate: common profanity --
    $moderate = [
        'porra', 'caralho', 'merda', 'bosta', 'cacete',
        'arrombado', 'arrombada', 'cuzao', 'cuzao',
        'babaca', 'otario', 'otaria', 'imbecil',
        'puta', 'viado', 'viada', 'desgraca',
        'buceta', 'cagada', 'cagado',
    ];

    // -- Mild: very common, almost acceptable --
    $mild = [
        'droga', 'inferno', 'caramba', 'maldito', 'maldita',
        'danado', 'danada', 'porcaria', 'diacho', 'diabos', 'raios',
    ];

    // Check severe words (word boundary matching)
    foreach ($severe as $word) {
        $pattern = '/\b' . preg_quote($word, '/') . '\b/u';
        if (preg_match($pattern, $normalized)) {
            $found = true;
            $maxSeverity = 'severe';
            $cleaned = preg_replace($pattern . 'i', '***', _aiSafeguardRemoveAccents(mb_strtolower($cleaned, 'UTF-8')));
            $cleaned = $text; // Keep original but mark as found
            $cleaned = preg_replace('/' . preg_quote($word, '/') . '/iu', '***', $cleaned);
        }
    }

    // Check moderate (only if not already severe)
    if ($maxSeverity !== 'severe') {
        foreach ($moderate as $word) {
            $pattern = '/\b' . preg_quote($word, '/') . '\b/u';
            if (preg_match($pattern, $normalized)) {
                // Food-safe exceptions: "pao de cacete" is a real bread, "computador/disputa/reputacao" contain "puta"
                if ($word === 'puta' && preg_match('/(?:com|dis|re)puta/u', $normalized)) {
                    continue;
                }
                if ($word === 'cacete' && preg_match('/pao\s+de\s+cacete/u', $normalized)) {
                    continue;
                }
                $found = true;
                $maxSeverity = 'moderate';
                $cleaned = preg_replace('/\b' . preg_quote($word, '/') . '\b/iu', '***', $cleaned);
            }
        }
    }

    // Check mild (only if nothing worse found)
    if (!$found) {
        foreach ($mild as $word) {
            $pattern = '/\b' . preg_quote($word, '/') . '\b/u';
            if (preg_match($pattern, $normalized)) {
                $found = true;
                $maxSeverity = 'mild';
                // Don't clean mild profanity -- it's mostly acceptable
                break;
            }
        }
    }

    return [
        'has_profanity' => $found,
        'severity' => $maxSeverity,
        'cleaned' => $cleaned,
    ];
}

/**
 * Remove accents from a UTF-8 string for normalization.
 * @internal
 */
function _aiSafeguardRemoveAccents(string $str): string {
    $map = [
        'a' => '[aàáâãäå]', 'e' => '[eèéêë]', 'i' => '[iìíîï]',
        'o' => '[oòóôõö]', 'u' => '[uùúûü]', 'c' => '[cç]',
        'n' => '[nñ]',
    ];
    foreach ($map as $replacement => $pattern) {
        $str = preg_replace('/' . $pattern . '/u', $replacement, $str);
    }
    return $str;
}


// =============================================================================
// 8. Session Token Tracker
// =============================================================================

/**
 * Track Claude API token usage per call session.
 * Stores in om_callcenter_calls.notes JSON under key 'token_usage'.
 * Alerts via error_log if a single call exceeds 50,000 tokens total.
 *
 * @param string $callSid       Twilio CallSid or WhatsApp session ID
 * @param int    $inputTokens   Tokens used for input (prompt)
 * @param int    $outputTokens  Tokens used for output (response)
 * @param PDO    $db            Database connection
 */
function aiSafeguardTrackTokens(string $callSid, int $inputTokens, int $outputTokens, PDO $db): void {
    try {
        $stmt = $db->prepare("SELECT id, notes FROM om_callcenter_calls WHERE twilio_call_sid = ? LIMIT 1");
        $stmt->execute([$callSid]);
        $row = $stmt->fetch();

        if (!$row) {
            error_log("[ai-safeguards] Token tracking: no call record for sid={$callSid}");
            return;
        }

        $callId = (int)$row['id'];
        $notes = $row['notes'] ?? '';
        $notesData = [];
        if (!empty($notes) && $notes[0] === '{') {
            $notesData = json_decode($notes, true) ?: [];
        }

        // Preserve _ai_context if it exists (used by loadAiContext/saveAiContext)
        $tokenUsage = $notesData['token_usage'] ?? [
            'total_input' => 0,
            'total_output' => 0,
            'total' => 0,
            'calls' => 0,
        ];

        $tokenUsage['total_input'] += $inputTokens;
        $tokenUsage['total_output'] += $outputTokens;
        $tokenUsage['total'] = $tokenUsage['total_input'] + $tokenUsage['total_output'];
        $tokenUsage['calls'] += 1;
        $tokenUsage['last_call_at'] = date('c');

        $notesData['token_usage'] = $tokenUsage;

        // Alert if exceeding 50k tokens
        if ($tokenUsage['total'] > 50000) {
            error_log("[ai-safeguards] HIGH TOKEN USAGE: call_sid={$callSid} total={$tokenUsage['total']} api_calls={$tokenUsage['calls']}");
        }

        // Save back -- careful not to clobber _ai_context
        $json = json_encode($notesData, JSON_UNESCAPED_UNICODE);
        $db->prepare("UPDATE om_callcenter_calls SET notes = ? WHERE id = ?")
           ->execute([$json, $callId]);

    } catch (\Exception $e) {
        error_log("[ai-safeguards] Token tracking error: " . $e->getMessage());
    }
}


// =============================================================================
// 9. Convenience Wrappers (used by twilio-voice-ai.php and whatsapp-ai.php)
// =============================================================================

/**
 * Run all safeguard checks in one call: abuse check, context sanitization, health monitoring.
 *
 * @param PDO    $db         Database connection
 * @param string $phone      Caller/sender phone
 * @param string $callSid    Twilio CallSid or session identifier
 * @param string $userInput  The user's latest message/speech
 * @param array  $aiContext  Current AI context (step, history, items, etc.)
 * @return array{allowed: bool, context: array, health: array, abuse: array, twiml: string|null}
 */
function runSafeguards(PDO $db, string $phone, string $callSid, string $userInput, array $aiContext): array
{
    // 1. Abuse check (rate limiting, blacklist, spam, profanity)
    $abuse = aiSafeguardCheckAbuse($phone, $userInput, $db);
    if (!$abuse['allowed'] && $abuse['action'] === 'block') {
        return [
            'allowed' => false,
            'context' => $aiContext,
            'health'  => ['healthy' => true, 'issues' => [], 'recommended_action' => 'none'],
            'abuse'   => $abuse,
            'twiml'   => buildTwilioSay('Desculpe, nao foi possivel processar sua ligacao. Tente novamente mais tarde.'),
        ];
    }

    // 2. Sanitize context (fill defaults, trim history, validate structure)
    $sanitizedContext = _safeguardSanitizeContext($aiContext);

    // 3. Health check (loop detection, frustration, excessive turns)
    $health = aiSafeguardCheckHealth($sanitizedContext);

    return [
        'allowed' => true,
        'context' => $sanitizedContext,
        'health'  => [
            'healthy'            => $health['healthy'],
            'issues'             => $health['warnings'] ?? [],
            'recommended_action' => $health['action'] ?? 'continue',
        ],
        'abuse'   => $abuse,
        'twiml'   => null,
    ];
}

/**
 * Sanitize and normalize the AI context structure.
 * Fills missing keys, trims history, validates step names.
 * @internal
 */
function _safeguardSanitizeContext(array $ctx): array
{
    // Ensure required keys exist
    $defaults = [
        'step'           => 'identify_store',
        'history'        => [],
        'items'          => [],
        'partner_id'     => null,
        'partner_name'   => null,
        'customer_id'    => null,
        'customer_name'  => null,
        'address'        => null,
        'payment_method' => null,
        'turn_count'     => 0,
        'error_count'    => 0,
        'silence_count'  => 0,
        'started_at'     => date('c'),
    ];

    foreach ($defaults as $key => $default) {
        if (!isset($ctx[$key])) {
            $ctx[$key] = $default;
        }
    }

    // Validate step name
    $validSteps = [
        'greeting', 'identify_store', 'take_order', 'get_address',
        'get_payment', 'confirm_order', 'submit_order', 'support', 'question',
    ];
    if (!in_array($ctx['step'], $validSteps, true)) {
        $ctx['step'] = 'identify_store';
    }

    // Trim history to max 20 messages
    if (count($ctx['history']) > 20) {
        $ctx['history'] = array_slice($ctx['history'], -20);
    }

    // Cap individual message length
    foreach ($ctx['history'] as &$msg) {
        if (isset($msg['content']) && mb_strlen($msg['content'], 'UTF-8') > 1000) {
            $msg['content'] = mb_substr($msg['content'], 0, 1000, 'UTF-8') . '...';
        }
    }
    unset($msg);

    // Validate items array
    if (!is_array($ctx['items'])) {
        $ctx['items'] = [];
    }
    foreach ($ctx['items'] as &$item) {
        if (isset($item['quantity'])) {
            $item['quantity'] = min(99, max(1, (int)$item['quantity']));
        }
    }
    unset($item);

    // Increment turn count
    $ctx['turn_count'] = (int)$ctx['turn_count'] + 1;

    return $ctx;
}

/**
 * Build a TwiML <Say> element with Polly.Camila voice.
 * Fallback for when ElevenLabs TTS is unavailable.
 *
 * @param string $text  Text to speak
 * @param string $voice Polly voice name (default: Polly.Camila)
 * @return string TwiML <Say> XML string
 */
function buildTwilioSay(string $text, string $voice = 'Polly.Camila'): string
{
    $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<Say language="pt-BR" voice="' . $voice . '">' . $escaped . '</Say>';
}

/**
 * Log per-turn call metrics for analytics.
 * Stores metrics in om_callcenter_calls record as JSON.
 *
 * @param PDO   $db      Database connection
 * @param int   $callId  Call record ID
 * @param array $metrics Metrics to log:
 *   - turn_number (int)
 *   - step (string)
 *   - claude_latency_ms (int)
 *   - tts_latency_ms (int)
 *   - step_transition (string) "from->to"
 *   - error_type (string)
 *   - error_message (string)
 *   - input_tokens (int)
 *   - output_tokens (int)
 *   - speech_confidence (float)
 *   - response_length (int)
 */
function logCallMetrics(PDO $db, int $callId, array $metrics): void
{
    try {
        $stmt = $db->prepare("SELECT notes FROM om_callcenter_calls WHERE id = ? LIMIT 1");
        $stmt->execute([$callId]);
        $notes = $stmt->fetchColumn() ?: '';

        $notesData = [];
        if (!empty($notes) && $notes[0] === '{') {
            $notesData = json_decode($notes, true) ?: [];
        }

        // Append to metrics log (keep last 50 entries)
        $metricsLog = $notesData['_metrics_log'] ?? [];
        $metrics['timestamp'] = date('c');
        $metricsLog[] = $metrics;
        if (count($metricsLog) > 50) {
            $metricsLog = array_slice($metricsLog, -50);
        }
        $notesData['_metrics_log'] = $metricsLog;

        // Update aggregates
        $agg = $notesData['_metrics_summary'] ?? [
            'total_turns'         => 0,
            'total_errors'        => 0,
            'total_input_tokens'  => 0,
            'total_output_tokens' => 0,
            'avg_claude_latency'  => 0,
            'step_transitions'    => [],
        ];
        $agg['total_turns']++;
        if (!empty($metrics['error_type'])) {
            $agg['total_errors']++;
        }
        if (isset($metrics['input_tokens'])) {
            $agg['total_input_tokens'] += (int)$metrics['input_tokens'];
        }
        if (isset($metrics['output_tokens'])) {
            $agg['total_output_tokens'] += (int)$metrics['output_tokens'];
        }
        if (isset($metrics['claude_latency_ms'])) {
            $n = $agg['total_turns'];
            $agg['avg_claude_latency'] = (int)(
                (($agg['avg_claude_latency'] * ($n - 1)) + $metrics['claude_latency_ms']) / $n
            );
        }
        if (!empty($metrics['step_transition'])) {
            $agg['step_transitions'][] = $metrics['step_transition'];
        }
        $notesData['_metrics_summary'] = $agg;

        $json = json_encode($notesData, JSON_UNESCAPED_UNICODE);
        $db->prepare("UPDATE om_callcenter_calls SET notes = ? WHERE id = ?")
           ->execute([$json, $callId]);

    } catch (\Exception $e) {
        error_log("[ai-safeguards] logCallMetrics error: " . $e->getMessage());
    }
}

/**
 * Mask PII (personally identifiable information) for safe logging.
 *
 * @param string $text Text that may contain PII
 * @return string Text with PII masked
 */
function maskPiiForLog(string $text): string
{
    // Phone numbers: keep last 4 digits
    $text = preg_replace('/(\+?\d{2,3}[\s\-]?)?\(?\d{2}\)?[\s\-]?\d{4,5}[\s\-]?\d{4}/', '***-****-$4', $text);

    // CPF: XXX.XXX.XXX-XX -> ***.***.***-XX
    $text = preg_replace('/\d{3}\.\d{3}\.\d{3}-(\d{2})/', '***.***.***-$1', $text);

    // Email
    $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '***@***.***', $text);

    return $text;
}

/**
 * Optimize a Claude API request: trim prompt/history, set step-appropriate max_tokens.
 * Wrapper around aiSafeguardOptimizePrompt.
 *
 * @param string $systemPrompt  Full system prompt
 * @param array  $history       Conversation history messages
 * @param array  $aiContext     AI context with step, items, etc.
 * @return array{prompt: string, history: array, max_tokens: int, estimated_tokens: int}
 */
function optimizeClaudeRequest(string $systemPrompt, array $history, array $aiContext): array
{
    $step = $aiContext['step'] ?? 'identify_store';
    $optimized = aiSafeguardOptimizePrompt($systemPrompt, $history, $step);

    // Step-based max_tokens (phone = shorter responses)
    $maxTokensByStep = [
        'greeting'       => 200,
        'identify_store' => 250,
        'take_order'     => 350,
        'get_address'    => 200,
        'get_payment'    => 200,
        'confirm_order'  => 400,
        'submit_order'   => 200,
        'support'        => 350,
        'question'       => 350,
    ];

    return [
        'prompt'           => $optimized['system'],
        'history'          => $optimized['messages'],
        'max_tokens'       => $maxTokensByStep[$step] ?? 300,
        'estimated_tokens' => $optimized['estimated_tokens'],
    ];
}

/**
 * Validate an AI response for safety, language, and content issues.
 * Wrapper around aiSafeguardValidateResponse.
 *
 * @param string $response   Claude's raw text response
 * @param array  $context    Context with step, items, menu_prices, channel, etc.
 * @return array{valid: bool, issues: string[], cleaned: string}
 */
function validateAiResponse(string $response, array $context): array
{
    $step = $context['step'] ?? 'identify_store';
    return aiSafeguardValidateResponse($response, $step, $context);
}

/**
 * Get a smart fallback message appropriate to the current step and error context.
 * Wrapper around aiSafeguardFallback.
 *
 * @param string $step       Current conversation step
 * @param array  $aiContext  AI context
 * @param string $errorType  Type of error that triggered fallback
 * @return string Fallback message text
 */
function getSmartFallback(string $step, array $aiContext, string $errorType = ''): string
{
    $errorCount = ($aiContext['error_count'] ?? 0) + 1;
    $channel = $aiContext['channel'] ?? 'voice';
    return aiSafeguardFallback($step, $errorCount, $channel);
}

/**
 * Handle degraded mode when Claude or other services are down.
 * Returns a TwiML-wrapped fallback response using keyword-based matching.
 *
 * @param string $component  Failed component: 'claude', 'tts', 'db', 'network'
 * @param string $error      Error message
 * @param array  $context    Context with step, last_input, items, store_name, nearby_stores
 * @return string TwiML response string (e.g., <Say> or <Play> tag)
 */
function handleDegradedMode(string $component, string $error, array $context): string
{
    $step = $context['step'] ?? 'identify_store';
    $channel = $context['channel'] ?? 'voice';

    // If Claude is down, use keyword-based response
    if ($component === 'claude') {
        $input = mb_strtolower($context['last_input'] ?? '', 'UTF-8');

        // Try to detect common intents from keywords
        $nearbyStores = $context['nearby_stores'] ?? [];
        $storeName = $context['store_name'] ?? '';

        // Simple intent matching
        if (mb_strpos($input, 'atendente') !== false || mb_strpos($input, 'pessoa') !== false) {
            $msg = 'Vou te transferir pra um atendente agora. Um momento!';
        } elseif (mb_strpos($input, 'status') !== false || mb_strpos($input, 'pedido') !== false) {
            $msg = 'Pra verificar seu pedido, vou te passar pro atendente. Um momento!';
        } elseif ($step === 'identify_store' && !empty($nearbyStores)) {
            $names = array_map(fn($s) => $s['name'] ?? '', array_slice($nearbyStores, 0, 3));
            $msg = 'Desculpa o probleminha tecnico! Temos ' . implode(', ', $names) . '. Qual voce quer?';
        } elseif ($step === 'take_order' && $storeName) {
            $msg = "Desculpa, tive um probleminha. Pode repetir o que voce quer pedir da {$storeName}?";
        } elseif ($step === 'confirm_order') {
            $msg = 'Tive um probleminha tecnico. Pode confirmar se quer finalizar o pedido?';
        } else {
            $msg = 'Desculpa, tive um probleminha aqui. Pode repetir o que voce precisa?';
        }

        // For voice, wrap in TTS
        if ($channel === 'voice') {
            if (function_exists('ttsSayOrPlay')) {
                return ttsSayOrPlay($msg);
            }
            return buildTwilioSay($msg);
        }
        return $msg;
    }

    // Database down
    if ($component === 'db') {
        $msg = 'Estamos com um probleminha tecnico no momento. Por favor, tente novamente em alguns minutos.';
        return ($channel === 'voice') ? buildTwilioSay($msg) : $msg;
    }

    // Network/other
    $msg = 'Desculpa, estou com dificuldade tecnica. Pode repetir?';
    return ($channel === 'voice') ? buildTwilioSay($msg) : $msg;
}
