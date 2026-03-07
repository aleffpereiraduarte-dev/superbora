<?php
/**
 * Voice TTS Helper — Ultra-natural speech synthesis
 *
 * Priority:
 *   1. ElevenLabs streaming (best quality, native Brazilian voice, lowest latency)
 *   2. OpenAI TTS (excellent quality, multilingual)
 *   3. Google Neural2 via Twilio <Say> (fallback)
 *
 * Features:
 *   - Pre-cached common phrases for instant playback
 *   - Streaming ElevenLabs endpoint for minimal latency
 *   - Voice settings tuned for Brazilian Portuguese phone conversations
 *   - Emotion-aware TTS (happy, empathetic, excited, neutral)
 *   - Concatenation for instant-start playback with dynamic content
 *
 * Usage:
 *   require_once __DIR__ . '/voice-tts.php';
 *   echo ttsSayOrPlay("Oi, tudo bem?");
 *   echo ttsWithEmotion("Que ótimo! Pedido confirmado!", 'happy');
 *   echo ttsConcatPlay(["Oi", "Maria! Tudo bem?"]);
 */

// Default ElevenLabs voice ID — "Valentina" Brazilian Portuguese
// Can be overridden in .env with ELEVENLABS_VOICE_ID
define('TTS_ELEVENLABS_DEFAULT_VOICE', 'cgSgspJ2msm6clMCkdW9'); // Rachel - good multilingual

// Cache version — bump to invalidate all cached audio
define('TTS_CACHE_VERSION', 'v5');

// Voice settings tuned for Brazilian Portuguese phone conversations
define('TTS_VOICE_SETTINGS_DEFAULT', [
    'stability'         => 0.35,  // Slightly more expressive for natural PT-BR
    'similarity_boost'  => 0.85,  // High voice consistency
    'style'             => 0.25,  // Moderate expressiveness
    'use_speaker_boost' => true,  // Enhanced clarity for phone audio
]);

// Emotion presets — adjust voice settings per emotional context
define('TTS_EMOTION_PRESETS', [
    'neutral' => [
        'stability'         => 0.35,
        'similarity_boost'  => 0.85,
        'style'             => 0.25,
        'use_speaker_boost' => true,
    ],
    'happy' => [
        'stability'         => 0.25,  // More expressive variation
        'similarity_boost'  => 0.85,
        'style'             => 0.50,  // Higher style for upbeat tone
        'use_speaker_boost' => true,
    ],
    'empathetic' => [
        'stability'         => 0.50,  // Calmer, more steady voice
        'similarity_boost'  => 0.85,
        'style'             => 0.15,  // Subdued style for sensitivity
        'use_speaker_boost' => true,
    ],
    'excited' => [
        'stability'         => 0.20,  // Most dynamic variation
        'similarity_boost'  => 0.85,
        'style'             => 0.60,  // Maximum expressiveness
        'use_speaker_boost' => true,
    ],
]);

// Common phrases for pre-caching — instant playback, no API wait
define('TTS_COMMON_PHRASES', [
    'oi_tudo_bem'           => 'Oi, tudo bem?',
    'ola_bom_dia'           => 'Olá, bom dia!',
    'ola_boa_tarde'         => 'Olá, boa tarde!',
    'ola_boa_noite'         => 'Olá, boa noite!',
    'anotado'               => 'Anotado!',
    'mais_alguma_coisa'     => 'Mais alguma coisa?',
    'vou_conferir'          => 'Vou conferir.',
    'um_momento'            => 'Um momento, por favor.',
    'entendi'               => 'Entendi!',
    'certo'                 => 'Certo!',
    'pode_deixar'           => 'Pode deixar!',
    'obrigada'              => 'Obrigada!',
    'de_nada'               => 'De nada!',
    'pronto'                => 'Pronto!',
    'so_um_instante'        => 'Só um instante.',
    'pedido_confirmado'     => 'Pedido confirmado!',
    'pedido_a_caminho'      => 'Seu pedido está a caminho!',
    'pedido_entregue'       => 'Pedido entregue! Obrigada pela preferência!',
    'algo_mais'             => 'Posso ajudar com algo mais?',
    'desculpe'              => 'Desculpe pelo transtorno.',
    'vou_verificar'         => 'Vou verificar para você.',
    'aguarde'               => 'Aguarde um momento, por favor.',
    'oi'                    => 'Oi!',
    'tchau'                 => 'Tchau, tenha um ótimo dia!',
    'obrigada_preferencia'  => 'Obrigada pela preferência!',
    'com_certeza'           => 'Com certeza!',
    'infelizmente'          => 'Infelizmente não temos esse produto no momento.',
    'valor_total'           => 'O valor total do seu pedido é',
    'endereco_entrega'      => 'Qual o endereço de entrega?',
    'forma_pagamento'       => 'Qual a forma de pagamento?',
]);

/**
 * Generate TwiML: <Say> with Polly.Camila (instant, zero latency)
 *
 * Previous approach used <Play> with ElevenLabs TTS which added 1-3s latency
 * per turn (HTTP roundtrip to audio.php + ElevenLabs API call). This caused
 * choppy/"picotado" audio on phone calls.
 *
 * Polly.Camila is Amazon's Neural voice for Brazilian Portuguese, built into
 * Twilio — plays instantly with no external HTTP calls.
 */
function ttsSayOrPlay(string $text, string $emotion = 'neutral'): string {
    $clean = preg_replace('/<[^>]+>/', ' ', $text);
    $clean = preg_replace('/\s+/', ' ', trim($clean));

    if (empty($clean)) {
        return '';
    }

    $escaped = htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<Say language="pt-BR" voice="Polly.Camila">' . $escaped . '</Say>';
}

/**
 * Emotion-aware TTS — adjusts voice settings based on emotional context
 *
 * Emotions:
 *   'happy'      → Higher style, lower stability — confirmations, greetings
 *   'empathetic'  → Higher stability, lower style — complaints, problems
 *   'excited'    → Lowest stability, highest style — promos, upsells
 *   'neutral'    → Default balanced settings
 *
 * @param string $text    Text to synthesize
 * @param string $emotion One of: neutral, happy, empathetic, excited
 * @return string TwiML <Play> or <Say> tag
 */
function ttsWithEmotion(string $text, string $emotion = 'neutral'): string {
    // Validate emotion
    if (!isset(TTS_EMOTION_PRESETS[$emotion])) {
        $emotion = 'neutral';
    }

    return ttsSayOrPlay($text, $emotion);
}

/**
 * Concatenation for speed — generates <Play> tags for multiple short segments.
 * The first segment plays immediately (ideally pre-cached) while subsequent
 * segments generate in parallel. This eliminates perceived wait time.
 *
 * Example:
 *   ttsConcatPlay(["Oi", "Maria! Tudo bem?"])
 *   → Pre-cached "Oi!" plays instantly, "Maria! Tudo bem?" generates while it plays
 *
 * @param array  $parts   Array of text segments to concatenate
 * @param string $emotion Emotion preset for dynamic (non-cached) segments
 * @return string Multiple TwiML <Play> tags concatenated
 */
function ttsConcatPlay(array $parts, string $emotion = 'neutral'): string {
    $twiml = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        $twiml .= ttsSayOrPlay($part, $emotion);
    }
    return $twiml;
}

/**
 * Called by audio.php to generate/serve audio on demand
 */
function ttsServeAudio(string $hash, string $encodedText, string $emotion = 'neutral'): bool {
    $text = base64_decode($encodedText);
    if (empty($text)) return false;

    // Validate emotion
    if (!isset(TTS_EMOTION_PRESETS[$emotion])) {
        $emotion = 'neutral';
    }

    $expectedHash = md5($text . '_' . TTS_CACHE_VERSION . '_' . $emotion);
    if ($hash !== $expectedHash) return false;

    $audioDir = __DIR__ . '/../webhooks/audio';
    if (!is_dir($audioDir)) @mkdir($audioDir, 0755, true);

    $filePath = $audioDir . '/tts_' . $hash . '.mp3';

    // Check cache (valid 4 hours)
    if (file_exists($filePath) && (time() - filemtime($filePath)) < 14400 && filesize($filePath) > 100) {
        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: public, max-age=7200');
        readfile($filePath);
        return true;
    }

    // Resolve voice settings for this emotion
    $voiceSettings = TTS_EMOTION_PRESETS[$emotion] ?? TTS_VOICE_SETTINGS_DEFAULT;

    // Try ElevenLabs streaming first (lowest latency)
    $audio = ttsElevenLabs($text, $voiceSettings);

    // Fallback to OpenAI
    if (!$audio) {
        $audio = ttsOpenAI($text);
    }

    if (!$audio || strlen($audio) < 100) {
        return false;
    }

    @file_put_contents($filePath, $audio);
    ttsCleanupOldFiles($audioDir);

    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . strlen($audio));
    header('Cache-Control: public, max-age=7200');
    echo $audio;
    return true;
}

/**
 * ElevenLabs TTS — Streaming endpoint for lowest latency
 *
 * Uses /v1/text-to-speech/{voice_id}/stream with optimize_streaming_latency=3
 * for minimum first-byte time. Voice settings tuned for Brazilian Portuguese
 * phone conversations.
 *
 * @param string     $text          Text to synthesize
 * @param array|null $voiceSettings Override voice settings (stability, similarity_boost, etc.)
 * @return string|null MP3 audio data or null on failure
 */
function ttsElevenLabs(string $text, ?array $voiceSettings = null): ?string {
    $apiKey = $_ENV['ELEVENLABS_API_KEY'] ?? getenv('ELEVENLABS_API_KEY') ?: '';
    if (empty($apiKey)) return null;

    $voiceId = $_ENV['ELEVENLABS_VOICE_ID'] ?? getenv('ELEVENLABS_VOICE_ID') ?: TTS_ELEVENLABS_DEFAULT_VOICE;

    // Use provided settings or defaults tuned for PT-BR phone conversations
    $settings = $voiceSettings ?? TTS_VOICE_SETTINGS_DEFAULT;

    $payload = json_encode([
        'text' => $text,
        'model_id' => 'eleven_multilingual_v2',
        'voice_settings' => $settings,
        'optimize_streaming_latency' => 3,  // Minimum latency mode
        'speed' => 1.15,  // Slightly faster for natural phone conversations
    ]);

    // Use streaming endpoint for faster first-byte delivery
    $url = "https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}/stream";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'xi-api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: audio/mpeg',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,         // Slightly longer for streaming
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response) || strlen($response) < 100) {
        error_log("[voice-tts] ElevenLabs streaming failed: HTTP {$httpCode} | {$error}");
        return null;
    }

    error_log("[voice-tts] ElevenLabs streaming OK: " . strlen($response) . " bytes");
    return $response;
}

/**
 * OpenAI TTS — Excellent quality multilingual voice
 */
function ttsOpenAI(string $text): ?string {
    $apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
    if (empty($apiKey)) return null;

    $payload = json_encode([
        'model' => 'tts-1-hd',
        'input' => $text,
        'voice' => 'nova',
        'response_format' => 'mp3',
        'speed' => 1.15,  // Slightly faster for natural phone conversations
    ]);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response) || strlen($response) < 100) {
        error_log("[voice-tts] OpenAI TTS failed: HTTP {$httpCode} | {$error}");
        return null;
    }

    error_log("[voice-tts] OpenAI TTS OK: " . strlen($response) . " bytes");
    return $response;
}

// ─── Pre-cached Common Phrases ──────────────────────────────────────────────

/**
 * Pre-generate audio for all common phrases.
 * Call this from a cron job or deploy script to warm the cache:
 *
 *   php -r "require 'helpers/voice-tts.php'; ttsPrecacheCommon();"
 *
 * Files are stored with a 'common_' prefix and never expire via ttsCleanupOldFiles().
 * Regeneration is skipped if the file already exists and is valid.
 *
 * @param bool $force Force regeneration even if files exist
 * @return array Summary: ['generated' => int, 'skipped' => int, 'failed' => int]
 */
function ttsPrecacheCommon(bool $force = false): array {
    $audioDir = __DIR__ . '/../webhooks/audio';
    if (!is_dir($audioDir)) @mkdir($audioDir, 0755, true);

    $stats = ['generated' => 0, 'skipped' => 0, 'failed' => 0];

    foreach (TTS_COMMON_PHRASES as $key => $phrase) {
        $filePath = $audioDir . '/common_' . $key . '_' . TTS_CACHE_VERSION . '.mp3';

        // Skip if already cached and valid (unless forced)
        if (!$force && file_exists($filePath) && filesize($filePath) > 100) {
            $stats['skipped']++;
            error_log("[voice-tts] Precache skip (exists): {$key}");
            continue;
        }

        // Generate with neutral emotion and default voice settings
        $audio = ttsElevenLabs($phrase, TTS_VOICE_SETTINGS_DEFAULT);
        if (!$audio) {
            $audio = ttsOpenAI($phrase);
        }

        if ($audio && strlen($audio) > 100) {
            @file_put_contents($filePath, $audio);
            $stats['generated']++;
            error_log("[voice-tts] Precached: {$key} (" . strlen($audio) . " bytes)");
        } else {
            $stats['failed']++;
            error_log("[voice-tts] Precache FAILED: {$key}");
        }

        // Brief pause to respect API rate limits
        usleep(250000); // 250ms between requests
    }

    error_log("[voice-tts] Precache complete: " . json_encode($stats));
    return $stats;
}

/**
 * Check if a text matches a common pre-cached phrase.
 * Uses normalized comparison (lowercase, no extra whitespace, no trailing punctuation variance).
 *
 * @param string $text The input text to match
 * @return string|null The common phrase key, or null if no match
 */
function ttsMatchCommonPhrase(string $text): ?string {
    $normalized = mb_strtolower(trim($text), 'UTF-8');
    // Remove trailing punctuation for fuzzy matching
    $stripped = rtrim($normalized, '!?.,;: ');

    foreach (TTS_COMMON_PHRASES as $key => $phrase) {
        $phraseNorm = mb_strtolower(trim($phrase), 'UTF-8');
        $phraseStripped = rtrim($phraseNorm, '!?.,;: ');

        if ($normalized === $phraseNorm || $stripped === $phraseStripped) {
            return $key;
        }
    }

    return null;
}

/**
 * Get the URL for a pre-cached common phrase audio file.
 * Returns null if the file doesn't exist (not yet pre-cached).
 *
 * @param string $key The common phrase key (from TTS_COMMON_PHRASES)
 * @return string|null Full URL to the cached audio, or null
 */
function ttsCommonPhraseUrl(string $key): ?string {
    $audioDir = __DIR__ . '/../webhooks/audio';
    $filePath = $audioDir . '/common_' . $key . '_' . TTS_CACHE_VERSION . '.mp3';

    if (!file_exists($filePath) || filesize($filePath) < 100) {
        return null;
    }

    $scheme = 'https';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'superbora.com.br';

    return $scheme . '://' . $host . '/api/mercado/webhooks/audio/common_' . $key . '_' . TTS_CACHE_VERSION . '.mp3';
}

// ─── SSML Helpers for Natural PT-BR Voice ───────────────────────────────────

/**
 * Format a price for natural speech in PT-BR.
 * 35.90 → "trinta e cinco reais e noventa centavos"
 * 10.00 → "dez reais"
 * 0.50  → "cinquenta centavos"
 * 120.05 → "cento e vinte reais e cinco centavos"
 *
 * @param float $value Price in BRL
 * @return string Spoken price text
 */
function ttsFormatPrice(float $value): string {
    $value = round($value, 2);
    if ($value <= 0) return 'grátis';

    $reais = (int)floor($value);
    $centavos = (int)round(($value - $reais) * 100);

    $parts = [];
    if ($reais > 0) {
        $reaisText = ttsNumberToWords($reais);
        $parts[] = $reaisText . ($reais === 1 ? ' real' : ' reais');
    }
    if ($centavos > 0) {
        $centText = ttsNumberToWords($centavos);
        $parts[] = $centText . ($centavos === 1 ? ' centavo' : ' centavos');
    }

    if (empty($parts)) return 'grátis';
    return implode(' e ', $parts);
}

/**
 * Format an order number for clear speech.
 * "SB00123" → "S B zero zero um dois três"
 * Digits are spoken individually for clarity on phone.
 *
 * @param string $orderNumber Order number (e.g., "SB00123")
 * @return string Spoken order number
 */
function ttsFormatOrderNumber(string $orderNumber): string {
    $digitWords = [
        '0' => 'zero', '1' => 'um', '2' => 'dois', '3' => 'três',
        '4' => 'quatro', '5' => 'cinco', '6' => 'seis', '7' => 'sete',
        '8' => 'oito', '9' => 'nove',
    ];

    $parts = [];
    $chars = mb_str_split($orderNumber);
    foreach ($chars as $ch) {
        if (isset($digitWords[$ch])) {
            $parts[] = $digitWords[$ch];
        } else {
            // Letter — spell out uppercase
            $parts[] = mb_strtoupper($ch, 'UTF-8');
        }
    }

    return implode(' ', $parts);
}

/**
 * Format a phone number for speech.
 * "+5519999887766" → "dezenove, nove nove nove, oito oito, sete sete, seis seis"
 *
 * @param string $phone Phone number
 * @return string Spoken phone
 */
function ttsFormatPhone(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    // Take last 11 digits (BR format)
    $digits = substr($digits, -11);
    if (strlen($digits) < 10) {
        // Fallback: spell digit by digit
        return ttsFormatOrderNumber($digits);
    }

    $ddd = substr($digits, 0, 2);
    $rest = substr($digits, 2);

    $digitWords = [
        '0' => 'zero', '1' => 'um', '2' => 'dois', '3' => 'três',
        '4' => 'quatro', '5' => 'cinco', '6' => 'seis', '7' => 'sete',
        '8' => 'oito', '9' => 'nove',
    ];

    $parts = [];
    // DDD as number
    $parts[] = ttsNumberToWords((int)$ddd);

    // Rest in pairs
    $pairs = str_split($rest, 2);
    foreach ($pairs as $pair) {
        if (strlen($pair) === 2 && $pair[0] === $pair[1]) {
            // Same digits: "double sete" sounds better
            $parts[] = $digitWords[$pair[0]] . ' ' . $digitWords[$pair[1]];
        } elseif (strlen($pair) === 2) {
            $parts[] = $digitWords[$pair[0]] . ' ' . $digitWords[$pair[1]];
        } else {
            $parts[] = $digitWords[$pair[0]] ?? $pair[0];
        }
    }

    return implode(', ', $parts);
}

/**
 * Convert an integer (0-9999) to Portuguese words.
 *
 * @param int $n Number to convert
 * @return string Word representation
 */
function ttsNumberToWords(int $n): string {
    if ($n < 0) return 'menos ' . ttsNumberToWords(abs($n));
    if ($n === 0) return 'zero';

    $units = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove',
              'dez', 'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
    $tens = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    $hundreds = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos',
                 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];

    if ($n === 100) return 'cem';

    if ($n >= 1000) {
        $mil = (int)floor($n / 1000);
        $rest = $n % 1000;
        $milText = $mil === 1 ? 'mil' : ttsNumberToWords($mil) . ' mil';
        if ($rest === 0) return $milText;
        return $milText . ' e ' . ttsNumberToWords($rest);
    }

    if ($n >= 100) {
        $h = (int)floor($n / 100);
        $rest = $n % 100;
        if ($rest === 0) return $n === 100 ? 'cem' : $hundreds[$h];
        return $hundreds[$h] . ' e ' . ttsNumberToWords($rest);
    }

    if ($n >= 20) {
        $t = (int)floor($n / 10);
        $u = $n % 10;
        if ($u === 0) return $tens[$t];
        return $tens[$t] . ' e ' . $units[$u];
    }

    return $units[$n];
}

/**
 * Wrap text with SSML emphasis.
 *
 * @param string $text Text to emphasize
 * @param string $level One of: strong, moderate, reduced
 * @return string SSML emphasis tag
 */
function ttsEmphasis(string $text, string $level = 'moderate'): string {
    $esc = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<emphasis level="' . $level . '">' . $esc . '</emphasis>';
}

/**
 * Insert an SSML pause/break.
 *
 * @param string $duration Duration (e.g., "300ms", "1s")
 * @return string SSML break tag
 */
function ttsPause(string $duration = '300ms'): string {
    return '<break time="' . $duration . '"/>';
}

/**
 * Wrap text with SSML prosody for slower speech (useful for numbers, addresses).
 *
 * @param string $text Text to slow down
 * @param string $rate Speech rate: x-slow, slow, medium, fast, x-fast, or percentage
 * @return string SSML prosody tag
 */
function ttsSlow(string $text, string $rate = 'slow'): string {
    $esc = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<prosody rate="' . $rate . '">' . $esc . '</prosody>';
}

/**
 * Build a natural voice confirmation of an order for speech.
 * Reads back items, total, address, and payment clearly.
 *
 * @param array  $items        Order items with name, quantity, price
 * @param float  $total        Total price
 * @param string $storeName    Store name
 * @param string $address      Delivery address summary
 * @param string $payment      Payment method label
 * @param int    $eta          Estimated delivery time in minutes
 * @return string Natural spoken order confirmation
 */
function ttsBuildOrderConfirmation(array $items, float $total, string $storeName, string $address = '', string $payment = '', int $eta = 0): string {
    $parts = [];

    // Items summary (keep it concise for voice)
    $itemParts = [];
    foreach ($items as $item) {
        $qty = (int)($item['quantity'] ?? 1);
        $name = $item['name'] ?? 'item';
        if ($qty === 1) {
            $itemParts[] = $name;
        } else {
            $itemParts[] = $qty . ' ' . $name;
        }
    }

    if (count($itemParts) === 1) {
        $parts[] = 'Então fica: ' . $itemParts[0] . '.';
    } elseif (count($itemParts) <= 4) {
        $last = array_pop($itemParts);
        $parts[] = 'Então fica: ' . implode(', ', $itemParts) . ' e ' . $last . '.';
    } else {
        // Too many items — summarize
        $first3 = array_slice($itemParts, 0, 3);
        $remaining = count($itemParts) - 3;
        $parts[] = 'Então fica: ' . implode(', ', $first3) . ' e mais ' . $remaining . ' itens.';
    }

    // Total
    $parts[] = 'Total de ' . ttsFormatPrice($total) . '.';

    // Address (keep short)
    if (!empty($address)) {
        $parts[] = 'Entrega no ' . $address . '.';
    }

    // Payment
    if (!empty($payment)) {
        $payLabels = [
            'dinheiro' => 'em dinheiro', 'pix' => 'no PIX',
            'credit_card' => 'no cartão de crédito', 'debit_card' => 'no cartão de débito',
            'credito' => 'no cartão de crédito', 'debito' => 'no cartão de débito',
        ];
        $payText = $payLabels[$payment] ?? $payment;
        $parts[] = 'Pagamento ' . $payText . '.';
    }

    // ETA
    if ($eta > 0) {
        $parts[] = 'Chega em uns ' . $eta . ' minutinhos.';
    }

    $parts[] = 'Posso mandar?';

    return implode(' ', $parts);
}

// ─── Cache Maintenance ──────────────────────────────────────────────────────

/**
 * Remove old cached audio files.
 * Preserves common_* pre-cached files — only removes dynamic tts_* files.
 */
function ttsCleanupOldFiles(string $dir): void {
    // Only clean dynamic files, never pre-cached common_ files
    $files = @glob($dir . '/tts_*.mp3');
    if (!$files || count($files) <= 300) return;

    usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
    $toDelete = count($files) - 200;
    foreach ($files as $file) {
        if ($toDelete-- <= 0) break;
        @unlink($file);
    }
}

/**
 * Remove stale common phrase cache files from previous cache versions.
 * Safe to run anytime — only removes files that don't match the current TTS_CACHE_VERSION.
 *
 * @return int Number of stale files removed
 */
function ttsCleanupStaleCommon(): int {
    $audioDir = __DIR__ . '/../webhooks/audio';
    $files = @glob($audioDir . '/common_*.mp3');
    if (!$files) return 0;

    $removed = 0;
    $suffix = '_' . TTS_CACHE_VERSION . '.mp3';

    foreach ($files as $file) {
        if (!str_ends_with(basename($file), $suffix)) {
            @unlink($file);
            $removed++;
        }
    }

    if ($removed > 0) {
        error_log("[voice-tts] Cleaned {$removed} stale common cache files");
    }

    return $removed;
}
