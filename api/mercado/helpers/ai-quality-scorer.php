<?php
/**
 * AI Conversation Quality Scorer
 *
 * Auto-scores every AI conversation on 7 dimensions (0-100 each).
 * Pure heuristic scoring (no Claude API call) for speed.
 *
 * Used by: webhooks/twilio-voice-ai.php, whatsapp-ai.php (post-conversation)
 * Depends on: config/database.php (getDB)
 *
 * Design principles:
 *   - No Claude API calls — all heuristic for < 5ms scoring
 *   - Never throw exceptions — always return structured results
 *   - error_log() with [ai-quality] prefix
 *   - Weighted overall_score: understanding 25%, accuracy 20%, resolution 20%,
 *     tone 15%, efficiency 10%, upsell 5%, greeting 5%
 */


// =============================================================================
// 1. Main Scoring Function
// =============================================================================

/**
 * Score an AI conversation on 7 quality dimensions.
 *
 * @param PDO    $db               Database connection
 * @param string $type             Conversation type: 'voice' or 'whatsapp'
 * @param int    $conversationId   FK to calls or whatsapp conversation table
 * @param array  $conversationData Keys:
 *   - history (array)            [{role, content}, ...] full conversation
 *   - items (array)              Cart items at end of conversation
 *   - step (string)              Final conversation step
 *   - order_id (int|null)        Order ID if placed
 *   - customer_sentiment (string) 'positive', 'neutral', 'negative'
 *   - turns_count (int)          Total turns
 *   - duration_seconds (int)     Total conversation duration
 * @return array Scores + metadata
 */
function scoreAiConversation(PDO $db, string $type, int $conversationId, array $conversationData): array {
    try {
        $history = $conversationData['history'] ?? [];
        $items = $conversationData['items'] ?? [];
        $step = $conversationData['step'] ?? '';
        $orderId = $conversationData['order_id'] ?? null;
        $sentiment = $conversationData['customer_sentiment'] ?? 'neutral';
        $turnsCount = (int)($conversationData['turns_count'] ?? count($history));
        $durationSeconds = (int)($conversationData['duration_seconds'] ?? 0);

        // Split messages by role
        $aiMessages = [];
        $userMessages = [];
        foreach ($history as $msg) {
            $role = $msg['role'] ?? '';
            $content = trim($msg['content'] ?? '');
            if ($role === 'assistant' && $content !== '') {
                $aiMessages[] = $content;
            } elseif ($role === 'user' && $content !== '') {
                $userMessages[] = $content;
            }
        }

        // Score each dimension
        $greetingScore = _qualityScoreGreeting($aiMessages, $conversationData);
        $understandingScore = _qualityScoreUnderstanding($aiMessages, $userMessages);
        $accuracyScore = _qualityScoreAccuracy($aiMessages, $items);
        $upsellScore = _qualityScoreUpsell($aiMessages, $items);
        $toneScore = _qualityScoreTone($aiMessages, $userMessages, $sentiment);
        $resolutionScore = _qualityScoreResolution($step, $orderId);
        $efficiencyScore = _qualityScoreEfficiency($turnsCount);

        // Weighted overall
        $overallScore = (int)round(
            $understandingScore * 0.25 +
            $accuracyScore * 0.20 +
            $resolutionScore * 0.20 +
            $toneScore * 0.15 +
            $efficiencyScore * 0.10 +
            $upsellScore * 0.05 +
            $greetingScore * 0.05
        );
        $overallScore = max(0, min(100, $overallScore));

        // Detect missed opportunities
        $missedOpportunities = _qualityDetectMissedOpportunities($aiMessages, $items);

        // Detect issues
        $issuesDetected = _qualityDetectIssues($aiMessages, $userMessages, $turnsCount, $sentiment);

        // Determine conversion result
        $conversionResult = _qualityDetermineConversion($step, $orderId);

        // Compute order value
        $orderValue = 0.0;
        foreach ($items as $item) {
            $orderValue += ((float)($item['price'] ?? 0)) * ((int)($item['quantity'] ?? 1));
        }

        // Build sentiment flow
        $sentimentFlow = _qualityBuildSentimentFlow($userMessages);

        // Flag for review
        $hasHighSeverity = false;
        foreach ($issuesDetected as $issue) {
            if (($issue['severity'] ?? '') === 'high') {
                $hasHighSeverity = true;
                break;
            }
        }
        $flaggedForReview = $overallScore < 50 || $hasHighSeverity;

        // Detect language (basic)
        $allUserText = implode(' ', $userMessages);
        $languageDetected = _qualityDetectLanguageSimple($allUserText);

        // Save to database
        $scoreId = _qualitySaveScore($db, [
            'conversation_type' => $type,
            'conversation_id' => $conversationId,
            'overall_score' => $overallScore,
            'greeting_score' => $greetingScore,
            'understanding_score' => $understandingScore,
            'accuracy_score' => $accuracyScore,
            'upsell_score' => $upsellScore,
            'tone_score' => $toneScore,
            'resolution_score' => $resolutionScore,
            'efficiency_score' => $efficiencyScore,
            'missed_opportunities' => $missedOpportunities,
            'issues_detected' => $issuesDetected,
            'conversion_result' => $conversionResult,
            'order_value' => $orderValue,
            'turns_count' => $turnsCount,
            'duration_seconds' => $durationSeconds,
            'language_detected' => $languageDetected,
            'sentiment_flow' => $sentimentFlow,
            'flagged_for_review' => $flaggedForReview,
        ]);

        $result = [
            'score_id' => $scoreId,
            'overall_score' => $overallScore,
            'greeting_score' => $greetingScore,
            'understanding_score' => $understandingScore,
            'accuracy_score' => $accuracyScore,
            'upsell_score' => $upsellScore,
            'tone_score' => $toneScore,
            'resolution_score' => $resolutionScore,
            'efficiency_score' => $efficiencyScore,
            'missed_opportunities' => $missedOpportunities,
            'issues_detected' => $issuesDetected,
            'conversion_result' => $conversionResult,
            'order_value' => $orderValue,
            'flagged_for_review' => $flaggedForReview,
            'language_detected' => $languageDetected,
            'sentiment_flow' => $sentimentFlow,
        ];

        if ($flaggedForReview) {
            error_log("[ai-quality] Flagged for review: type={$type} conv={$conversationId} score={$overallScore}");
        }

        return $result;

    } catch (Exception $e) {
        error_log("[ai-quality] scoreAiConversation error: " . $e->getMessage());
        return [
            'score_id' => 0,
            'overall_score' => 0,
            'error' => $e->getMessage(),
            'flagged_for_review' => true,
        ];
    }
}


// =============================================================================
// 2. Individual Dimension Scorers
// =============================================================================

/**
 * Greeting score: Did AI greet? Did it use customer name?
 * @internal
 */
function _qualityScoreGreeting(array $aiMessages, array $conversationData): int {
    if (empty($aiMessages)) {
        return 0;
    }

    $firstAiMsg = mb_strtolower($aiMessages[0], 'UTF-8');
    $score = 0;

    // Check for greeting words
    $greetings = ['ola', 'oi', 'bom dia', 'boa tarde', 'boa noite', 'bem-vindo', 'bemvindo', 'bem vindo', 'hello', 'hi'];
    foreach ($greetings as $g) {
        if (mb_strpos($firstAiMsg, $g) !== false) {
            $score += 50;
            break;
        }
    }

    // Check if customer name was used
    $customerName = $conversationData['customer_name'] ?? '';
    if ($customerName !== '') {
        $nameLower = mb_strtolower($customerName, 'UTF-8');
        // Check first name
        $firstName = explode(' ', trim($nameLower))[0] ?? '';
        if ($firstName !== '' && mb_strpos($firstAiMsg, $firstName) !== false) {
            $score += 30;
        }
    } else {
        // No name available, give partial credit if greeting is warm
        $warmPhrases = ['como posso ajudar', 'em que posso', 'tudo bem', 'como vai', 'prazer'];
        foreach ($warmPhrases as $wp) {
            if (mb_strpos($firstAiMsg, $wp) !== false) {
                $score += 20;
                break;
            }
        }
    }

    // Polite opener bonus
    $politePatterns = ['superbora', 'obrigad', 'prazer', 'seja bem'];
    foreach ($politePatterns as $pp) {
        if (mb_strpos($firstAiMsg, $pp) !== false) {
            $score += 20;
            break;
        }
    }

    return max(0, min(100, $score));
}

/**
 * Understanding score: How many times did AI say it didn't understand?
 * @internal
 */
function _qualityScoreUnderstanding(array $aiMessages, array $userMessages): int {
    if (empty($aiMessages)) {
        return 50; // neutral
    }

    $confusionPhrases = [
        'nao entendi', 'nao compreendi', 'pode repetir', 'poderia repetir',
        'nao consegui entender', 'desculpe, nao', 'desculpa, nao',
        'nao ficou claro', 'pode falar novamente', 'pode dizer novamente',
        'nao captei', 'pode explicar melhor', 'sorry', 'i didn\'t understand',
        'no entendi', 'no comprendi',
    ];

    $confusionCount = 0;
    foreach ($aiMessages as $msg) {
        $lower = mb_strtolower($msg, 'UTF-8');
        foreach ($confusionPhrases as $phrase) {
            if (mb_strpos($lower, $phrase) !== false) {
                $confusionCount++;
                break; // one per message
            }
        }
    }

    // Also check if user had to repeat themselves
    $userRepeatCount = 0;
    if (count($userMessages) >= 2) {
        for ($i = 1; $i < count($userMessages); $i++) {
            $similarity = _qualityTextSimilarity(
                mb_strtolower($userMessages[$i], 'UTF-8'),
                mb_strtolower($userMessages[$i - 1], 'UTF-8')
            );
            if ($similarity > 0.7) {
                $userRepeatCount++;
            }
        }
    }

    $totalIssues = $confusionCount + $userRepeatCount;

    if ($totalIssues === 0) return 100;
    if ($totalIssues === 1) return 80;
    if ($totalIssues === 2) return 60;
    if ($totalIssues === 3) return 40;
    if ($totalIssues === 4) return 20;
    return 10;
}

/**
 * Accuracy score: Were items added/removed multiple times? Price corrections?
 * @internal
 */
function _qualityScoreAccuracy(array $aiMessages, array $items): int {
    if (empty($aiMessages)) {
        return 50;
    }

    $score = 100;

    // Count corrections / changes in AI messages
    $correctionPhrases = [
        'corrigi', 'correcao', 'na verdade', 'desculpe, o preco',
        'preco correto', 'removi', 'tirei do pedido', 'alterei',
        'valor correto', 'me enganei', 'errei', 'estava errado',
        'preco errado', 'corrected', 'actually', 'my mistake',
    ];

    $correctionCount = 0;
    foreach ($aiMessages as $msg) {
        $lower = mb_strtolower($msg, 'UTF-8');
        foreach ($correctionPhrases as $phrase) {
            if (mb_strpos($lower, $phrase) !== false) {
                $correctionCount++;
                break;
            }
        }
    }

    // Count add/remove oscillation
    $addCount = 0;
    $removeCount = 0;
    foreach ($aiMessages as $msg) {
        $lower = mb_strtolower($msg, 'UTF-8');
        if (preg_match('/(?:adicionei|anotei|inclui|acrescentei|adicionado)/', $lower)) {
            $addCount++;
        }
        if (preg_match('/(?:removi|tirei|retirei|removido|cancelei esse)/', $lower)) {
            $removeCount++;
        }
    }
    $oscillation = min($addCount, $removeCount);

    $score -= $correctionCount * 15;
    $score -= $oscillation * 10;

    return max(0, min(100, $score));
}

/**
 * Upsell score: Did AI suggest complementary items?
 * @internal
 */
function _qualityScoreUpsell(array $aiMessages, array $items): int {
    if (empty($aiMessages)) {
        return 50;
    }

    $upsellPhrases = [
        'quer tambem', 'gostaria tambem', 'que tal', 'acompanha',
        'quer adicionar', 'sugestao', 'combina com', 'vai bem com',
        'complementar', 'aproveitar', 'oferta especial', 'promocao',
        'levar tambem', 'quer incluir', 'posso sugerir',
        'would you also', 'how about', 'quiere tambien',
    ];

    $upsellAttempts = 0;
    foreach ($aiMessages as $msg) {
        $lower = mb_strtolower($msg, 'UTF-8');
        foreach ($upsellPhrases as $phrase) {
            if (mb_strpos($lower, $phrase) !== false) {
                $upsellAttempts++;
                break;
            }
        }
    }

    // At least 1 upsell attempt = good. 2+ = great. 0 = missed opportunity but not terrible.
    if ($upsellAttempts === 0) return 30;
    if ($upsellAttempts === 1) return 70;
    if ($upsellAttempts === 2) return 90;
    return 100; // 3+ attempts
}

/**
 * Tone score: Match customer sentiment, apologize when needed.
 * @internal
 */
function _qualityScoreTone(array $aiMessages, array $userMessages, string $sentiment): int {
    if (empty($aiMessages)) {
        return 50;
    }

    $score = 70; // baseline

    // Check for empathetic phrases in AI messages
    $empatheticPhrases = [
        'entendo', 'compreendo', 'sem problema', 'claro', 'com certeza',
        'fique tranquilo', 'fique tranquila', 'sem preocupacao', 'certo',
        'perfeito', 'otimo', 'maravilha', 'excelente',
    ];

    $apologyPhrases = [
        'desculpe', 'desculpa', 'sinto muito', 'lamento', 'perdao',
        'me desculpe', 'peco desculpas',
    ];

    $empatheticCount = 0;
    $apologyCount = 0;
    foreach ($aiMessages as $msg) {
        $lower = mb_strtolower($msg, 'UTF-8');
        foreach ($empatheticPhrases as $ep) {
            if (mb_strpos($lower, $ep) !== false) {
                $empatheticCount++;
                break;
            }
        }
        foreach ($apologyPhrases as $ap) {
            if (mb_strpos($lower, $ap) !== false) {
                $apologyCount++;
                break;
            }
        }
    }

    // Customer was frustrated — did AI apologize/empathize?
    if ($sentiment === 'negative') {
        if ($apologyCount > 0 || $empatheticCount >= 2) {
            $score += 20; // Good recovery
        } else {
            $score -= 30; // Failed to match frustrated customer
        }
    } elseif ($sentiment === 'positive') {
        if ($empatheticCount > 0) {
            $score += 20; // Matched positive energy
        }
    }

    // Check for robotic/cold responses (very short, no emotion words)
    $shortRobotic = 0;
    foreach ($aiMessages as $msg) {
        if (mb_strlen($msg, 'UTF-8') < 15) {
            $shortRobotic++;
        }
    }
    if ($shortRobotic > count($aiMessages) * 0.5) {
        $score -= 15;
    }

    // Check for user frustration words not addressed
    $frustrationWords = ['cansei', 'desisto', 'chega', 'irritado', 'irritada', 'absurdo', 'ridiculo'];
    $customerFrustrated = false;
    foreach ($userMessages as $msg) {
        $lower = mb_strtolower($msg, 'UTF-8');
        foreach ($frustrationWords as $fw) {
            if (mb_strpos($lower, $fw) !== false) {
                $customerFrustrated = true;
                break 2;
            }
        }
    }
    if ($customerFrustrated && $apologyCount === 0) {
        $score -= 20;
    }

    return max(0, min(100, $score));
}

/**
 * Resolution score: Did conversation reach order submission?
 * @internal
 */
function _qualityScoreResolution(string $step, $orderId): int {
    if ($orderId && (int)$orderId > 0) {
        return 100; // Order placed
    }

    // Map final steps to resolution scores
    $stepScores = [
        'submit_order' => 100,
        'confirm_order' => 90,
        'payment' => 70,
        'address' => 60,
        'review_order' => 55,
        'take_order' => 40,
        'identify_store' => 20,
        'greeting' => 10,
        'transfer_agent' => 30,
        'support' => 50,
        'order_status' => 60,
        'end' => 50,
    ];

    return $stepScores[$step] ?? 30;
}

/**
 * Efficiency score: Fewer turns = higher score.
 * @internal
 */
function _qualityScoreEfficiency(int $turnsCount): int {
    if ($turnsCount <= 0) return 50;
    if ($turnsCount >= 3 && $turnsCount <= 8) return 100;
    if ($turnsCount >= 9 && $turnsCount <= 12) return 80;
    if ($turnsCount >= 13 && $turnsCount <= 20) return 60;
    if ($turnsCount > 20) return 40;
    // 1-2 turns (very short — possibly abandoned)
    return 60;
}


// =============================================================================
// 3. Opportunity & Issue Detection
// =============================================================================

/**
 * Detect missed upsell and engagement opportunities.
 * @internal
 */
function _qualityDetectMissedOpportunities(array $aiMessages, array $items): array {
    $missed = [];

    if (empty($items)) {
        return $missed;
    }

    $allAiText = mb_strtolower(implode(' ', $aiMessages), 'UTF-8');

    // Check for complementary item suggestions
    $itemNames = [];
    foreach ($items as $item) {
        $itemNames[] = mb_strtolower($item['name'] ?? '', 'UTF-8');
    }
    $allItemText = implode(' ', $itemNames);

    // Pizza without drink suggestion
    if (preg_match('/pizza/', $allItemText)) {
        $drinkSuggested = preg_match('/(?:bebida|refrigerante|suco|agua|cerveja|drink)/', $allAiText);
        if (!$drinkSuggested) {
            $missed[] = [
                'type' => 'upsell_missed',
                'description' => 'Cart had pizza but no drink suggestion',
            ];
        }
    }

    // Hamburger without side suggestion
    if (preg_match('/(?:hambur|burger|lanche|sanduich)/', $allItemText)) {
        $sideSuggested = preg_match('/(?:batata|fritas|onion|acompanhamento|porcao)/', $allAiText);
        if (!$sideSuggested) {
            $missed[] = [
                'type' => 'upsell_missed',
                'description' => 'Cart had burger/sandwich but no side dish suggestion',
            ];
        }
    }

    // Breakfast items without coffee suggestion
    if (preg_match('/(?:pao|croissant|bolo|torrada|tapioca)/', $allItemText)) {
        $coffeeSuggested = preg_match('/(?:cafe|cappuccino|chocolate quente|cha)/', $allAiText);
        if (!$coffeeSuggested) {
            $missed[] = [
                'type' => 'upsell_missed',
                'description' => 'Cart had breakfast items but no coffee/drink suggestion',
            ];
        }
    }

    // Single item cart — no combo suggestion
    if (count($items) === 1) {
        $comboSuggested = preg_match('/(?:combo|promocao|oferta|acompanha|junto)/', $allAiText);
        if (!$comboSuggested) {
            $missed[] = [
                'type' => 'combo_missed',
                'description' => 'Single-item cart without combo/promotion suggestion',
            ];
        }
    }

    // No loyalty/cashback mention
    $loyaltyMentioned = preg_match('/(?:cashback|fidelidade|pontos|desconto)/', $allAiText);
    if (!$loyaltyMentioned && count($items) > 0) {
        $missed[] = [
            'type' => 'loyalty_missed',
            'description' => 'No mention of cashback/loyalty program',
        ];
    }

    return $missed;
}

/**
 * Detect conversation quality issues.
 * @internal
 */
function _qualityDetectIssues(array $aiMessages, array $userMessages, int $turnsCount, string $sentiment): array {
    $issues = [];

    // Check for customer repetition (had to repeat 3+ times)
    $repeatCount = 0;
    if (count($userMessages) >= 3) {
        for ($i = 1; $i < count($userMessages); $i++) {
            $sim = _qualityTextSimilarity(
                mb_strtolower($userMessages[$i], 'UTF-8'),
                mb_strtolower($userMessages[$i - 1], 'UTF-8')
            );
            if ($sim > 0.65) {
                $repeatCount++;
            }
        }
    }
    if ($repeatCount >= 3) {
        $issues[] = [
            'type' => 'confusion',
            'severity' => 'high',
            'description' => "Customer had to repeat {$repeatCount} times",
        ];
    } elseif ($repeatCount >= 2) {
        $issues[] = [
            'type' => 'confusion',
            'severity' => 'medium',
            'description' => "Customer had to repeat {$repeatCount} times",
        ];
    }

    // AI repeating itself
    $aiRepeatCount = 0;
    if (count($aiMessages) >= 3) {
        for ($i = 1; $i < count($aiMessages); $i++) {
            $sim = _qualityTextSimilarity(
                mb_strtolower($aiMessages[$i], 'UTF-8'),
                mb_strtolower($aiMessages[$i - 1], 'UTF-8')
            );
            if ($sim > 0.6) {
                $aiRepeatCount++;
            }
        }
    }
    if ($aiRepeatCount >= 2) {
        $issues[] = [
            'type' => 'ai_loop',
            'severity' => $aiRepeatCount >= 3 ? 'high' : 'medium',
            'description' => "AI repeated similar responses {$aiRepeatCount} times",
        ];
    }

    // Very long conversation without resolution
    if ($turnsCount > 20) {
        $issues[] = [
            'type' => 'lengthy_conversation',
            'severity' => 'medium',
            'description' => "Conversation lasted {$turnsCount} turns",
        ];
    }

    // Negative sentiment at end
    if ($sentiment === 'negative') {
        $issues[] = [
            'type' => 'negative_outcome',
            'severity' => 'medium',
            'description' => 'Customer ended conversation with negative sentiment',
        ];
    }

    // Check for language mixing in AI responses
    $languageMixCount = 0;
    foreach ($aiMessages as $msg) {
        $lower = mb_strtolower($msg, 'UTF-8');
        // English phrases in Portuguese conversation
        if (preg_match('/\b(unfortunately|certainly|wonderful|excellent|I understand|let me|I apologize)\b/i', $lower)) {
            $languageMixCount++;
        }
    }
    if ($languageMixCount >= 2) {
        $issues[] = [
            'type' => 'language_mixing',
            'severity' => 'low',
            'description' => "AI mixed languages in {$languageMixCount} messages",
        ];
    }

    // Empty AI responses (possible API failures)
    $emptyCount = 0;
    foreach ($aiMessages as $msg) {
        if (mb_strlen(trim($msg), 'UTF-8') < 5) {
            $emptyCount++;
        }
    }
    if ($emptyCount > 0) {
        $issues[] = [
            'type' => 'empty_responses',
            'severity' => $emptyCount >= 3 ? 'high' : 'medium',
            'description' => "{$emptyCount} near-empty AI responses detected",
        ];
    }

    return $issues;
}

/**
 * Determine conversion result from final step and order ID.
 * @internal
 */
function _qualityDetermineConversion(string $step, $orderId): string {
    if ($orderId && (int)$orderId > 0) {
        return 'order_placed';
    }
    if ($step === 'transfer_agent') {
        return 'transferred';
    }
    if (in_array($step, ['support', 'order_status', 'cancel_order'])) {
        return 'support_only';
    }
    return 'abandoned';
}

/**
 * Build sentiment flow from user messages.
 * @internal
 */
function _qualityBuildSentimentFlow(array $userMessages): array {
    $flow = [];

    $positiveWords = ['obrigad', 'otimo', 'perfeito', 'maravilh', 'legal', 'bom', 'boa', 'top', 'valeu', 'show', 'excelente'];
    $negativeWords = ['nao', 'ruim', 'pessimo', 'horrivel', 'chega', 'cansei', 'desisto', 'irritad', 'absurd', 'ridicul'];

    foreach ($userMessages as $idx => $msg) {
        $lower = mb_strtolower($msg, 'UTF-8');
        $posCount = 0;
        $negCount = 0;

        foreach ($positiveWords as $pw) {
            if (mb_strpos($lower, $pw) !== false) {
                $posCount++;
            }
        }
        foreach ($negativeWords as $nw) {
            if (mb_strpos($lower, $nw) !== false) {
                $negCount++;
            }
        }

        if ($posCount > $negCount) {
            $sentimentLabel = 'positive';
            $sentimentScore = min(100, 50 + $posCount * 20);
        } elseif ($negCount > $posCount) {
            $sentimentLabel = 'negative';
            $sentimentScore = max(0, 50 - $negCount * 20);
        } else {
            $sentimentLabel = 'neutral';
            $sentimentScore = 50;
        }

        $flow[] = [
            'turn' => $idx + 1,
            'sentiment' => $sentimentLabel,
            'score' => $sentimentScore,
        ];
    }

    return $flow;
}


// =============================================================================
// 4. Helper Functions
// =============================================================================

/**
 * Simple text similarity (Jaccard index on word sets).
 * @internal
 */
function _qualityTextSimilarity(string $a, string $b): float {
    if ($a === '' || $b === '') return 0.0;
    $wordsA = array_unique(preg_split('/\s+/', $a));
    $wordsB = array_unique(preg_split('/\s+/', $b));
    $intersection = count(array_intersect($wordsA, $wordsB));
    $union = count(array_unique(array_merge($wordsA, $wordsB)));
    return $union > 0 ? $intersection / $union : 0.0;
}

/**
 * Basic language detection from user text.
 * @internal
 */
function _qualityDetectLanguageSimple(string $text): string {
    $lower = mb_strtolower(trim($text), 'UTF-8');
    if ($lower === '') return 'pt';

    $words = preg_split('/\s+/', $lower);
    $enWords = ['the', 'is', 'are', 'want', 'please', 'hello', 'order', 'would', 'like', 'need', 'thank', 'delivery'];
    $esWords = ['quiero', 'por favor', 'hola', 'necesito', 'gracias', 'puedo', 'tengo'];

    $enCount = 0;
    $esCount = 0;
    foreach ($words as $w) {
        if (in_array($w, $enWords)) $enCount++;
        if (in_array($w, $esWords)) $esCount++;
    }

    $totalWords = max(1, count($words));
    if ($enCount / $totalWords > 0.15) return 'en';
    if ($esCount / $totalWords > 0.10) return 'es';
    return 'pt';
}

/**
 * Save score to om_ai_quality_scores table.
 * @internal
 * @return int Score ID
 */
function _qualitySaveScore(PDO $db, array $data): int {
    try {
        $stmt = $db->prepare("
            INSERT INTO om_ai_quality_scores (
                conversation_type, conversation_id, overall_score,
                greeting_score, understanding_score, accuracy_score,
                upsell_score, tone_score, resolution_score, efficiency_score,
                missed_opportunities, issues_detected, conversion_result,
                order_value, turns_count, duration_seconds,
                language_detected, sentiment_flow, flagged_for_review,
                scored_at, created_at
            ) VALUES (
                :conversation_type, :conversation_id, :overall_score,
                :greeting_score, :understanding_score, :accuracy_score,
                :upsell_score, :tone_score, :resolution_score, :efficiency_score,
                :missed_opportunities, :issues_detected, :conversion_result,
                :order_value, :turns_count, :duration_seconds,
                :language_detected, :sentiment_flow, :flagged_for_review,
                NOW(), NOW()
            )
            RETURNING id
        ");

        $stmt->execute([
            ':conversation_type' => $data['conversation_type'],
            ':conversation_id' => $data['conversation_id'],
            ':overall_score' => $data['overall_score'],
            ':greeting_score' => $data['greeting_score'],
            ':understanding_score' => $data['understanding_score'],
            ':accuracy_score' => $data['accuracy_score'],
            ':upsell_score' => $data['upsell_score'],
            ':tone_score' => $data['tone_score'],
            ':resolution_score' => $data['resolution_score'],
            ':efficiency_score' => $data['efficiency_score'],
            ':missed_opportunities' => json_encode($data['missed_opportunities']),
            ':issues_detected' => json_encode($data['issues_detected']),
            ':conversion_result' => $data['conversion_result'],
            ':order_value' => $data['order_value'],
            ':turns_count' => $data['turns_count'],
            ':duration_seconds' => $data['duration_seconds'],
            ':language_detected' => $data['language_detected'],
            ':sentiment_flow' => json_encode($data['sentiment_flow']),
            ':flagged_for_review' => $data['flagged_for_review'] ? 'true' : 'false',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['id'] ?? 0);

    } catch (Exception $e) {
        error_log("[ai-quality] Failed to save score: " . $e->getMessage());
        return 0;
    }
}


// =============================================================================
// 5. Dashboard & Reporting
// =============================================================================

/**
 * Get quality dashboard metrics for a period.
 *
 * @param PDO         $db      Database connection
 * @param string      $period  '24h', '7d', '30d', '90d'
 * @param string|null $channel Filter by channel: 'voice', 'whatsapp', null (all)
 * @return array Dashboard data
 */
function getQualityDashboard(PDO $db, string $period = '7d', ?string $channel = null): array {
    try {
        // Build interval
        $intervalMap = [
            '24h' => '24 hours',
            '7d' => '7 days',
            '30d' => '30 days',
            '90d' => '90 days',
        ];
        $interval = $intervalMap[$period] ?? '7 days';

        $channelFilter = '';
        $params = [];
        if ($channel !== null) {
            $channelFilter = 'AND conversation_type = :channel';
            $params[':channel'] = $channel;
        }

        // Average scores by dimension
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total_conversations,
                ROUND(AVG(overall_score), 1) as avg_overall,
                ROUND(AVG(greeting_score), 1) as avg_greeting,
                ROUND(AVG(understanding_score), 1) as avg_understanding,
                ROUND(AVG(accuracy_score), 1) as avg_accuracy,
                ROUND(AVG(upsell_score), 1) as avg_upsell,
                ROUND(AVG(tone_score), 1) as avg_tone,
                ROUND(AVG(resolution_score), 1) as avg_resolution,
                ROUND(AVG(efficiency_score), 1) as avg_efficiency,
                ROUND(AVG(turns_count), 1) as avg_turns,
                ROUND(AVG(duration_seconds), 0) as avg_duration
            FROM om_ai_quality_scores
            WHERE created_at >= NOW() - INTERVAL '{$interval}'
            {$channelFilter}
        ");
        $stmt->execute($params);
        $avgScores = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Score distribution
        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN overall_score BETWEEN 0 AND 30 THEN 1 ELSE 0 END) as bad,
                SUM(CASE WHEN overall_score BETWEEN 31 AND 60 THEN 1 ELSE 0 END) as ok,
                SUM(CASE WHEN overall_score BETWEEN 61 AND 80 THEN 1 ELSE 0 END) as good,
                SUM(CASE WHEN overall_score BETWEEN 81 AND 100 THEN 1 ELSE 0 END) as great
            FROM om_ai_quality_scores
            WHERE created_at >= NOW() - INTERVAL '{$interval}'
            {$channelFilter}
        ");
        $stmt->execute($params);
        $distribution = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Conversion rates
        $stmt = $db->prepare("
            SELECT
                conversion_result,
                COUNT(*) as count,
                ROUND(AVG(order_value), 2) as avg_order_value
            FROM om_ai_quality_scores
            WHERE created_at >= NOW() - INTERVAL '{$interval}'
            {$channelFilter}
            GROUP BY conversion_result
            ORDER BY count DESC
        ");
        $stmt->execute($params);
        $conversions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Top issues (aggregate from JSONB)
        $stmt = $db->prepare("
            SELECT
                issue->>'type' as issue_type,
                issue->>'severity' as severity,
                COUNT(*) as occurrence_count
            FROM om_ai_quality_scores,
                 jsonb_array_elements(issues_detected) as issue
            WHERE created_at >= NOW() - INTERVAL '{$interval}'
            {$channelFilter}
            GROUP BY issue->>'type', issue->>'severity'
            ORDER BY occurrence_count DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $topIssues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Flagged conversations count
        $stmt = $db->prepare("
            SELECT COUNT(*) as flagged_count
            FROM om_ai_quality_scores
            WHERE created_at >= NOW() - INTERVAL '{$interval}'
            AND flagged_for_review = TRUE
            {$channelFilter}
        ");
        $stmt->execute($params);
        $flaggedRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $flaggedCount = (int)($flaggedRow['flagged_count'] ?? 0);

        // Score trend (daily averages)
        $stmt = $db->prepare("
            SELECT
                DATE(created_at) as date,
                ROUND(AVG(overall_score), 1) as avg_score,
                COUNT(*) as conversations
            FROM om_ai_quality_scores
            WHERE created_at >= NOW() - INTERVAL '{$interval}'
            {$channelFilter}
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute($params);
        $trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'period' => $period,
            'channel' => $channel,
            'avg_scores' => $avgScores,
            'distribution' => [
                'bad' => (int)($distribution['bad'] ?? 0),
                'ok' => (int)($distribution['ok'] ?? 0),
                'good' => (int)($distribution['good'] ?? 0),
                'great' => (int)($distribution['great'] ?? 0),
            ],
            'conversions' => $conversions,
            'top_issues' => $topIssues,
            'flagged_count' => $flaggedCount,
            'trend' => $trend,
        ];

    } catch (Exception $e) {
        error_log("[ai-quality] getQualityDashboard error: " . $e->getMessage());
        return [
            'period' => $period,
            'channel' => $channel,
            'error' => 'Failed to load dashboard',
            'avg_scores' => [],
            'distribution' => ['bad' => 0, 'ok' => 0, 'good' => 0, 'great' => 0],
            'conversions' => [],
            'top_issues' => [],
            'flagged_count' => 0,
            'trend' => [],
        ];
    }
}

/**
 * Get full quality score details for a specific score ID.
 *
 * @param PDO $db      Database connection
 * @param int $scoreId Score ID to retrieve
 * @return array Score details + conversation data
 */
function getConversationQualityDetails(PDO $db, int $scoreId): array {
    try {
        $stmt = $db->prepare("
            SELECT
                id, conversation_type, conversation_id, overall_score,
                greeting_score, understanding_score, accuracy_score,
                upsell_score, tone_score, resolution_score, efficiency_score,
                missed_opportunities, issues_detected, conversion_result,
                order_value, turns_count, duration_seconds,
                language_detected, sentiment_flow, flagged_for_review,
                reviewed_by, review_notes, scored_at, created_at
            FROM om_ai_quality_scores
            WHERE id = :id
        ");
        $stmt->execute([':id' => $scoreId]);
        $score = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$score) {
            return ['error' => 'Score not found', 'score_id' => $scoreId];
        }

        // Parse JSONB fields
        $score['missed_opportunities'] = json_decode($score['missed_opportunities'] ?? '[]', true) ?: [];
        $score['issues_detected'] = json_decode($score['issues_detected'] ?? '[]', true) ?: [];
        $score['sentiment_flow'] = json_decode($score['sentiment_flow'] ?? '[]', true) ?: [];

        // Score breakdown with labels
        $score['score_breakdown'] = [
            ['dimension' => 'Understanding', 'score' => (int)$score['understanding_score'], 'weight' => '25%'],
            ['dimension' => 'Accuracy', 'score' => (int)$score['accuracy_score'], 'weight' => '20%'],
            ['dimension' => 'Resolution', 'score' => (int)$score['resolution_score'], 'weight' => '20%'],
            ['dimension' => 'Tone', 'score' => (int)$score['tone_score'], 'weight' => '15%'],
            ['dimension' => 'Efficiency', 'score' => (int)$score['efficiency_score'], 'weight' => '10%'],
            ['dimension' => 'Upsell', 'score' => (int)$score['upsell_score'], 'weight' => '5%'],
            ['dimension' => 'Greeting', 'score' => (int)$score['greeting_score'], 'weight' => '5%'],
        ];

        // Quality label
        $overall = (int)$score['overall_score'];
        if ($overall >= 81) {
            $score['quality_label'] = 'great';
        } elseif ($overall >= 61) {
            $score['quality_label'] = 'good';
        } elseif ($overall >= 31) {
            $score['quality_label'] = 'ok';
        } else {
            $score['quality_label'] = 'bad';
        }

        return $score;

    } catch (Exception $e) {
        error_log("[ai-quality] getConversationQualityDetails error: " . $e->getMessage());
        return ['error' => $e->getMessage(), 'score_id' => $scoreId];
    }
}
