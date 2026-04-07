<?php
/**
 * POST /api/mercado/intelligence/review-moderation.php
 * Body: { "text":"...", "rating":1-5, "review_id": optional }
 * Returns: { "action":"approve|hold|reject", "reasons":[], "score": 0-100, "is_fake": bool }
 *
 * Used by avaliacoes/salvar-completa.php BEFORE saving the review.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $text = trim($input['text'] ?? '');
    $rating = (int)($input['rating'] ?? 0);

    if ($text === '') {
        response(true, ['action' => 'approve', 'reasons' => [], 'score' => 0, 'is_fake' => false]);
    }

    // Quick rule-based pre-check
    $hardBlocks = ['fdp', 'puta', 'caralho ', 'merda ', 'http://', 'https://', 'whatsapp.com', 't.me/', 'bit.ly'];
    foreach ($hardBlocks as $b) {
        if (stripos($text, $b) !== false) {
            response(true, [
                'action' => 'hold',
                'reasons' => ['contém palavra ou link bloqueado'],
                'score' => 90,
                'is_fake' => false,
            ]);
        }
    }

    $prompt = "Avaliacao de cliente para uma loja/restaurante:\n" .
              "Nota: {$rating}/5\n" .
              "Texto: \"{$text}\"\n\n" .
              "Modere essa avaliacao. Detecte: ofensa, spam, conteudo falso/comprado, " .
              "informacao pessoal exposta, ameaca, conteudo fora do escopo. " .
              "Responda APENAS JSON: " .
              '{"action":"approve|hold|reject","reasons":["motivo1"],"score":0-100,"is_fake":true_ou_false,' .
              '"sentiment":"positive|neutral|negative","topics":["entrega","sabor","preco"]}';

    $reply = ClaudeClient::text(
        $prompt,
        'Voce eh moderador de conteudo. Politicas: nada de ofensa, links externos, info pessoal, spam.',
        400
    );
    $parsed = ClaudeClient::parseJson($reply ?: '') ?: [];

    $out = [
        'action' => in_array($parsed['action'] ?? '', ['approve', 'hold', 'reject'], true) ? $parsed['action'] : 'approve',
        'reasons' => is_array($parsed['reasons'] ?? null) ? $parsed['reasons'] : [],
        'score' => isset($parsed['score']) ? (int)$parsed['score'] : 0,
        'is_fake' => (bool)($parsed['is_fake'] ?? false),
        'sentiment' => $parsed['sentiment'] ?? 'neutral',
        'topics' => is_array($parsed['topics'] ?? null) ? $parsed['topics'] : [],
    ];

    response(true, $out);
} catch (Exception $e) {
    error_log('[review-moderation] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
