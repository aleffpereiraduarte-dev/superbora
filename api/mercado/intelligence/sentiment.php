<?php
/**
 * GET /api/mercado/intelligence/sentiment.php?partner_id=X
 *
 * Keyword-based sentiment analysis on recent reviews (last 30 days).
 * Returns overall sentiment, aspect scores, trending keywords, and weekly breakdown.
 *
 * Cached in Redis for 5 minutes when available.
 */
require_once __DIR__ . '/../config/database.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

$partnerId = (int)($_GET['partner_id'] ?? 0);
if (!$partnerId) {
    response(false, null, 'partner_id obrigatorio', 400);
}

// --- Redis cache check ---
$cacheKey = "sentiment:{$partnerId}";
$redis = null;
try {
    $redis = new Redis();
    $redis->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', (int)($_ENV['REDIS_PORT'] ?? 6379));
    $redisPassword = $_ENV['REDIS_PASSWORD'] ?? '';
    if (!empty($redisPassword)) {
        $redis->auth($redisPassword);
    }
    $cached = $redis->get($cacheKey);
    if ($cached) {
        $data = json_decode($cached, true);
        if ($data) {
            response(true, array_merge($data, ['source' => 'cache']));
        }
    }
} catch (Exception $e) {
    $redis = null; // Redis unavailable, proceed without cache
}

try {
    $db = getDB();

    // Verify partner exists
    $stmt = $db->prepare("SELECT partner_id, COALESCE(trade_name, name) AS business_name FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$partner) {
        response(false, null, 'Parceiro nao encontrado', 404);
    }

    // Fetch reviews from the last 30 days
    $stmt = $db->prepare("
        SELECT id, rating, comment, created_at
        FROM om_market_reviews
        WHERE partner_id = ?
          AND created_at >= NOW() - INTERVAL '30 days'
        ORDER BY created_at DESC
    ");
    $stmt->execute([$partnerId]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalReviews = count($reviews);

    if ($totalReviews === 0) {
        $emptyResult = [
            'partner_id' => $partnerId,
            'business_name' => $partner['business_name'],
            'overall_sentiment' => 'neutral',
            'total_reviews' => 0,
            'avg_rating' => 0,
            'aspect_scores' => [
                'delivery' => null,
                'food' => null,
                'service' => null,
                'price' => null,
                'packaging' => null,
            ],
            'trending_keywords' => [],
            'sentiment_over_time' => [],
        ];
        response(true, $emptyResult);
    }

    // --- Sentiment word lists ---
    $positiveWords = [
        'otimo', 'excelente', 'rapido', 'delicioso', 'perfeito', 'adorei',
        'maravilhoso', 'recomendo', 'top', 'incrivel', 'gostoso', 'bom',
        'fresco', 'caprichado', 'nota 10', 'parabens', 'surpreendeu',
        'amei', 'sensacional', 'qualidade', 'saboroso', 'melhor',
    ];

    $negativeWords = [
        'ruim', 'frio', 'demora', 'errado', 'horrivel', 'pessimo',
        'nao recomendo', 'nojo', 'atrasado', 'demorou', 'decepcionante',
        'terrivel', 'nojento', 'lixo', 'reclamar', 'absurdo', 'pior',
        'mofado', 'estragado', 'vencido', 'nunca mais', 'horrivel',
    ];

    // --- Aspect keyword mappings ---
    $aspects = [
        'delivery' => [
            'keywords' => ['entrega', 'entreg', 'motoboy', 'motorista', 'rapido', 'demorou', 'demora', 'atrasado', 'atraso', 'tempo', 'chegou', 'veloz', 'rapida', 'pontual'],
            'positiveHints' => ['rapido', 'rapida', 'pontual', 'veloz', 'antes do tempo', 'chegou cedo'],
            'negativeHints' => ['demorou', 'demora', 'atrasado', 'atraso', 'lento', 'demorado'],
        ],
        'food' => [
            'keywords' => ['comida', 'sabor', 'gostoso', 'saboroso', 'delicioso', 'fresco', 'frio', 'quente', 'tempero', 'cozido', 'cru', 'qualidade', 'gosto', 'ingrediente', 'porcao', 'quantidade'],
            'positiveHints' => ['gostoso', 'saboroso', 'delicioso', 'fresco', 'quente', 'tempero bom', 'qualidade'],
            'negativeHints' => ['frio', 'sem sabor', 'cru', 'estragado', 'mofado', 'vencido', 'sem tempero', 'pouco'],
        ],
        'service' => [
            'keywords' => ['atendimento', 'educado', 'gentil', 'grosso', 'atencao', 'atencioso', 'ignorou', 'prestativo', 'simpatico', 'mal educado'],
            'positiveHints' => ['educado', 'gentil', 'atencioso', 'prestativo', 'simpatico', 'otimo atendimento'],
            'negativeHints' => ['grosso', 'ignorou', 'mal educado', 'rude', 'falta de atencao'],
        ],
        'price' => [
            'keywords' => ['preco', 'valor', 'caro', 'barato', 'custo', 'conta', 'paguei', 'cobra', 'acessivel', 'justo', 'absurdo'],
            'positiveHints' => ['barato', 'acessivel', 'justo', 'bom preco', 'vale a pena', 'bom custo'],
            'negativeHints' => ['caro', 'absurdo', 'muito caro', 'nao vale', 'cobraram'],
        ],
        'packaging' => [
            'keywords' => ['embalagem', 'embalar', 'pacote', 'caixa', 'lacre', 'vazou', 'derramou', 'bem embalado', 'protegido', 'amassado'],
            'positiveHints' => ['bem embalado', 'protegido', 'lacre', 'organizado', 'caprichado'],
            'negativeHints' => ['vazou', 'derramou', 'amassado', 'mal embalado', 'aberto', 'rasgado'],
        ],
    ];

    // --- Process reviews ---
    $ratingSum = 0;
    $positiveCount = 0;
    $negativeCount = 0;
    $wordFrequency = [];
    $aspectMentions = []; // aspect => [positive => int, negative => int, total => int]
    $weeklyData = [];     // week_label => [sum_rating, count, positive, negative]

    foreach (array_keys($aspects) as $asp) {
        $aspectMentions[$asp] = ['positive' => 0, 'negative' => 0, 'total' => 0];
    }

    foreach ($reviews as $review) {
        $rating = (int)$review['rating'];
        $ratingSum += $rating;
        $comment = mb_strtolower(trim($review['comment'] ?? ''), 'UTF-8');

        // Remove accents for matching
        $normalized = removeAccents($comment);

        // Overall sentiment counting
        $hasPositive = false;
        $hasNegative = false;
        foreach ($positiveWords as $pw) {
            if (mb_strpos($normalized, $pw) !== false) {
                $hasPositive = true;
                break;
            }
        }
        foreach ($negativeWords as $nw) {
            if (mb_strpos($normalized, $nw) !== false) {
                $hasNegative = true;
                break;
            }
        }
        if ($rating >= 4 || $hasPositive) $positiveCount++;
        if ($rating <= 2 || $hasNegative) $negativeCount++;

        // Word frequency for trending keywords (skip short/common words)
        if (!empty($comment)) {
            $words = preg_split('/[\s,.!?;:()\-]+/u', $normalized);
            $stopWords = ['de', 'da', 'do', 'e', 'o', 'a', 'os', 'as', 'um', 'uma', 'em', 'no', 'na', 'com', 'que', 'para', 'por', 'se', 'ao', 'eu', 'foi', 'nao', 'mas', 'muito', 'mais', 'ja', 'tem', 'so', 'ate', 'tudo', 'bem', 'meu', 'minha', 'essa', 'esse', 'esta', 'este', 'eles', 'voce', 'nos', 'como', 'vai', 'vou', 'pra', 'pro', 'das', 'dos', 'uns', 'pelo'];
            foreach ($words as $w) {
                $w = trim($w);
                if (mb_strlen($w) < 3) continue;
                if (in_array($w, $stopWords)) continue;
                $wordFrequency[$w] = ($wordFrequency[$w] ?? 0) + 1;
            }
        }

        // Aspect scoring
        foreach ($aspects as $aspectKey => $aspectDef) {
            $mentioned = false;
            foreach ($aspectDef['keywords'] as $kw) {
                if (mb_strpos($normalized, $kw) !== false) {
                    $mentioned = true;
                    break;
                }
            }
            if ($mentioned) {
                $aspectMentions[$aspectKey]['total']++;

                // Determine positive or negative for this aspect
                $aspectPositive = false;
                $aspectNegative = false;
                foreach ($aspectDef['positiveHints'] as $ph) {
                    if (mb_strpos($normalized, $ph) !== false) {
                        $aspectPositive = true;
                        break;
                    }
                }
                foreach ($aspectDef['negativeHints'] as $nh) {
                    if (mb_strpos($normalized, $nh) !== false) {
                        $aspectNegative = true;
                        break;
                    }
                }

                // Fall back to overall rating if no keyword hint found
                if (!$aspectPositive && !$aspectNegative) {
                    if ($rating >= 4) $aspectPositive = true;
                    elseif ($rating <= 2) $aspectNegative = true;
                }

                if ($aspectPositive) $aspectMentions[$aspectKey]['positive']++;
                if ($aspectNegative) $aspectMentions[$aspectKey]['negative']++;
            }
        }

        // Weekly bucketing
        $createdAt = strtotime($review['created_at']);
        $weekStart = date('Y-m-d', strtotime('monday this week', $createdAt));
        if (!isset($weeklyData[$weekStart])) {
            $weeklyData[$weekStart] = ['sum_rating' => 0, 'count' => 0, 'positive' => 0, 'negative' => 0, 'neutral' => 0];
        }
        $weeklyData[$weekStart]['sum_rating'] += $rating;
        $weeklyData[$weekStart]['count']++;
        if ($rating >= 4) $weeklyData[$weekStart]['positive']++;
        elseif ($rating <= 2) $weeklyData[$weekStart]['negative']++;
        else $weeklyData[$weekStart]['neutral']++;
    }

    // --- Calculate results ---
    $avgRating = round($ratingSum / $totalReviews, 2);

    // Overall sentiment
    if ($avgRating >= 3.8) {
        $overallSentiment = 'positive';
    } elseif ($avgRating <= 2.5) {
        $overallSentiment = 'negative';
    } else {
        $overallSentiment = 'neutral';
    }

    // Aspect scores (0.0 to 1.0, null if no mentions)
    $aspectScores = [];
    foreach ($aspectMentions as $asp => $data) {
        if ($data['total'] === 0) {
            $aspectScores[$asp] = null;
        } else {
            // Score = positive ratio, with a slight boost from overall rating context
            $posRatio = $data['positive'] / $data['total'];
            $negRatio = $data['negative'] / $data['total'];
            // Score from 0 to 1: more positive mentions = higher score
            $score = max(0, min(1, 0.5 + ($posRatio - $negRatio) * 0.5));
            $aspectScores[$asp] = round($score, 2);
        }
    }

    // Trending keywords (top 10 by frequency, min 2 mentions)
    arsort($wordFrequency);
    $trendingKeywords = [];
    $count = 0;
    foreach ($wordFrequency as $word => $freq) {
        if ($freq < 2) break;
        if ($count >= 10) break;
        $trendingKeywords[] = ['word' => $word, 'count' => $freq];
        $count++;
    }

    // Sentiment over time (last 4 weeks, sorted chronologically)
    ksort($weeklyData);
    $sentimentOverTime = [];
    $weekSlice = array_slice($weeklyData, -4, 4, true);
    foreach ($weekSlice as $weekStart => $data) {
        $weekAvg = $data['count'] > 0 ? round($data['sum_rating'] / $data['count'], 2) : 0;
        $sentimentOverTime[] = [
            'week_start' => $weekStart,
            'avg_rating' => $weekAvg,
            'total' => $data['count'],
            'positive' => $data['positive'],
            'negative' => $data['negative'],
            'neutral' => $data['neutral'],
        ];
    }

    $result = [
        'partner_id' => $partnerId,
        'business_name' => $partner['business_name'],
        'overall_sentiment' => $overallSentiment,
        'total_reviews' => $totalReviews,
        'avg_rating' => $avgRating,
        'positive_count' => $positiveCount,
        'negative_count' => $negativeCount,
        'aspect_scores' => $aspectScores,
        'trending_keywords' => $trendingKeywords,
        'sentiment_over_time' => $sentimentOverTime,
    ];

    // Cache in Redis (5 min TTL)
    if ($redis) {
        try {
            $redis->setex($cacheKey, 300, json_encode($result));
        } catch (Exception $e) {
            // Redis write failed, no problem
        }
    }

    response(true, $result);

} catch (Exception $e) {
    error_log("[intelligence/sentiment] Erro: " . $e->getMessage());
    response(false, null, "Erro ao analisar sentimento", 500);
}

/**
 * Remove common Portuguese accents for keyword matching.
 */
function removeAccents(string $str): string {
    // Use transliterator if available (most reliable)
    if (function_exists('transliterator_transliterate')) {
        return transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $str);
    }

    // Fallback: manual replacement of common PT-BR accented chars
    $map = [
        "\xC3\xA1" => 'a', "\xC3\xA0" => 'a', "\xC3\xA2" => 'a', "\xC3\xA3" => 'a',
        "\xC3\x81" => 'a', "\xC3\x80" => 'a', "\xC3\x82" => 'a', "\xC3\x83" => 'a',
        "\xC3\xA9" => 'e', "\xC3\xA8" => 'e', "\xC3\xAA" => 'e',
        "\xC3\x89" => 'e', "\xC3\x88" => 'e', "\xC3\x8A" => 'e',
        "\xC3\xAD" => 'i', "\xC3\xAC" => 'i', "\xC3\x8D" => 'i', "\xC3\x8C" => 'i',
        "\xC3\xB3" => 'o', "\xC3\xB2" => 'o', "\xC3\xB4" => 'o', "\xC3\xB5" => 'o',
        "\xC3\x93" => 'o', "\xC3\x92" => 'o', "\xC3\x94" => 'o', "\xC3\x95" => 'o',
        "\xC3\xBA" => 'u', "\xC3\xB9" => 'u', "\xC3\x9A" => 'u', "\xC3\x99" => 'u',
        "\xC3\xA7" => 'c', "\xC3\x87" => 'c',
        "\xC3\xB1" => 'n', "\xC3\x91" => 'n',
    ];

    return strtr($str, $map);
}
