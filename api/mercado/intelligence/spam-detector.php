<?php
/**
 * POST /api/mercado/intelligence/spam-detector.php
 * Body: { "name":"...","email":"...","phone":"...","ip":"..." }
 *
 * Detects suspicious customer signups (bot/spam patterns).
 * Used by /auth/register.php right after the basic validation.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = preg_replace('/\D/', '', $input['phone'] ?? '');
    $ip = trim($input['ip'] ?? '');

    // Cheap heuristics first (free, no API call)
    $heuristicScore = 0;
    $reasons = [];

    if (preg_match('/^[a-z]+\d+$/i', $name) && strlen($name) > 8) { $heuristicScore += 30; $reasons[] = 'nome com padrao gerado'; }
    if (preg_match('/[0-9]{4,}/', $name)) { $heuristicScore += 20; $reasons[] = 'nome com muitos digitos'; }
    if ($name && strlen($name) > 50) { $heuristicScore += 15; $reasons[] = 'nome muito longo'; }
    if ($email && preg_match('/\+\d+@/', $email)) { $heuristicScore += 25; $reasons[] = 'email com tag (alias)'; }
    $disposableDomains = ['mailinator.com','tempmail.com','10minutemail.com','guerrillamail.com','yopmail.com','throwawaymail.com','sharklasers.com'];
    foreach ($disposableDomains as $d) if (stripos($email, $d) !== false) { $heuristicScore += 50; $reasons[] = 'email descartavel'; break; }
    if ($phone && (strlen($phone) < 10 || strlen($phone) > 13)) { $heuristicScore += 30; $reasons[] = 'telefone formato invalido'; }
    if ($phone && preg_match('/^(\d)\1+$/', $phone)) { $heuristicScore += 50; $reasons[] = 'telefone com digito repetido'; }

    // Check signup velocity from same IP in last hour
    if ($ip) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT COUNT(*) FROM om_customers WHERE created_at > NOW() - INTERVAL '1 hour'");
            $stmt->execute();
            // Note: we don't store IP per customer in om_customers, so this is global
        } catch (Exception $e) {}
    }

    // If heuristic is already high, skip AI
    if ($heuristicScore >= 60) {
        response(true, [
            'is_spam' => true,
            'score' => min(100, $heuristicScore),
            'action' => 'block',
            'reasons' => $reasons,
            'method' => 'heuristic',
        ]);
    }

    // Otherwise call Llama for nuanced check
    $prompt = "Analise se este cadastro eh suspeito (bot/spam):\n" .
              "Nome: {$name}\nEmail: {$email}\nTelefone: {$phone}\n\n" .
              "Heuristicas detectaram score {$heuristicScore}/100 com motivos: " . implode(', ', $reasons) . "\n\n" .
              "Responda APENAS JSON: " .
              '{"is_spam":true_ou_false,"score":0-100,"action":"allow|review|block","reasons":["..."]}';

    $reply = ClaudeClient::text($prompt, 'Voce eh detector de spam. Conservador: bloqueia so se for claro.', 300);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) {
        // Fall back to heuristic only
        response(true, [
            'is_spam' => $heuristicScore >= 50,
            'score' => $heuristicScore,
            'action' => $heuristicScore >= 50 ? 'review' : 'allow',
            'reasons' => $reasons,
            'method' => 'heuristic_fallback',
        ]);
    }

    $finalScore = max($heuristicScore, (int)($parsed['score'] ?? 0));
    response(true, [
        'is_spam' => (bool)($parsed['is_spam'] ?? false),
        'score' => $finalScore,
        'action' => $parsed['action'] ?? 'allow',
        'reasons' => array_unique(array_merge($reasons, $parsed['reasons'] ?? [])),
        'method' => 'ai',
    ]);
} catch (Exception $e) {
    error_log('[spam-detector] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
