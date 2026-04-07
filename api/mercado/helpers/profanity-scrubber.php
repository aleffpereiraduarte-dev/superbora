<?php
/**
 * Profanity scrubber helper.
 *
 * Provides scrubProfanity($text) which masks ofensive content.
 * Uses fast wordlist match + falls back to Llama for nuance.
 */

if (!function_exists('scrubProfanity')) {

    function scrubProfanity(string $text, bool $useAI = false): array {
        $original = $text;

        // Brazilian Portuguese profanity wordlist (basic + common variants)
        $words = [
            'porra','caralho','foda','foder','fdp','filho da puta','filhadaputa','vsf','vai se fuder','vai se foder',
            'cu','cu de','viado','viadinho','arrombado','arrombada','desgracado','desgraçado','desgraçada',
            'puta','puto','putinha','putao','rapariga','vagabunda','vagabundo','safado','safada',
            'merda','bosta','escroto','idiota','imbecil','retardado','mongol','mongoloide',
            'preto safado','negao seu','suas raca','negro de merda','macaco safado',
        ];

        $foundWords = [];
        $masked = $text;
        foreach ($words as $w) {
            $pattern = '/\b' . preg_quote($w, '/') . '\b/iu';
            if (preg_match($pattern, $masked)) {
                $foundWords[] = $w;
                $masked = preg_replace($pattern, str_repeat('*', mb_strlen($w)), $masked);
            }
        }

        $result = [
            'has_profanity' => !empty($foundWords),
            'found' => $foundWords,
            'cleaned' => $masked,
            'method' => 'wordlist',
        ];

        // Optional AI second pass for nuance (slang, indirect insults)
        if ($useAI && empty($foundWords)) {
            require_once __DIR__ . '/claude-client.php';
            $reply = ClaudeClient::text(
                "Texto: \"{$original}\"\n\nContem ofensa, insulto, ou linguagem inapropriada? Considere giria e indiretas. Responda APENAS JSON: " .
                '{"has_profanity":true_ou_false,"severity":"low|medium|high","reason":"breve"}',
                'Voce eh moderador de conteudo pt-BR. Conservador.',
                200
            );
            if ($reply) {
                $parsed = ClaudeClient::parseJson($reply);
                if ($parsed && !empty($parsed['has_profanity'])) {
                    $result['has_profanity'] = true;
                    $result['ai_severity'] = $parsed['severity'] ?? 'medium';
                    $result['ai_reason'] = $parsed['reason'] ?? '';
                    $result['method'] = 'ai';
                }
            }
        }

        return $result;
    }
}
