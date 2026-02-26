<?php
/**
 * Validacao de CPF via cpfcnpj.com.br
 * POST { cpf: "12345678901" }
 *
 * Retorna: { success: true, data: { valid: true, nome: "FULANO DE TAL" } }
 * Ou:      { success: false, message: "CPF invalido" }
 */

require_once __DIR__ . '/../config/database.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

$input = getInput();
$cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');

if (strlen($cpf) !== 11) {
    response(false, null, "CPF deve ter 11 digitos", 400);
}

// Validacao MOD-11 local (rejeita CPFs invalidos antes de consultar API)
if (!validarCpfMod11($cpf)) {
    response(false, ['valid' => false], "CPF invalido (digito verificador incorreto)", 200);
}

// Rate limit por IP: max 5 consultas/minuto
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$db = getDB();
if (!checkRateLimit("cpf_validate:$ip", 5, 60)) {
    response(false, null, "Muitas consultas. Aguarde 1 minuto.", 429);
}

// Verificar cache (30 dias)
$stmt = $db->prepare("SELECT nome, status, api_response, consulted_at FROM om_cpf_cache WHERE cpf = ? AND consulted_at > NOW() - INTERVAL '30 days'");
$stmt->execute([$cpf]);
$cached = $stmt->fetch();

if ($cached) {
    if ($cached['status'] === 'valid') {
        response(true, [
            'valid' => true,
            'nome' => $cached['nome'],
            'cached' => true,
        ]);
    } else {
        response(true, [
            'valid' => false,
            'cached' => true,
        ], "CPF nao encontrado na Receita Federal");
    }
}

// Consultar API cpfcnpj.com.br
$token = $_ENV['CPFCNPJ_API_TOKEN'] ?? '';
if (empty($token)) {
    // Sem token configurado - usar apenas validacao local MOD-11
    response(true, [
        'valid' => true,
        'nome' => null,
        'local_only' => true,
    ], "Validacao local apenas (token API nao configurado)");
}

$pacote = 1; // Pacote 1 = nome apenas (mais barato)
$url = "https://api.cpfcnpj.com.br/{$token}/{$pacote}/{$cpf}";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_SSL_VERIFYPEER => true,
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200) {
    error_log("[validate-cpf] API error: HTTP {$httpCode}, curl: {$curlError}");
    // Fail-closed: do not assume valid on API failure
    response(false, [
        'valid' => null,
        'unavailable' => true,
    ], "API de validacao indisponivel. Tente novamente.", 503);
}

$apiData = json_decode($result, true);

if (!$apiData || isset($apiData['erro']) || isset($apiData['error'])) {
    // CPF nao encontrado ou erro na API
    $errorMsg = $apiData['mensagem'] ?? $apiData['message'] ?? 'CPF nao encontrado';

    // Cachear resultado negativo
    $stmt = $db->prepare("INSERT INTO om_cpf_cache (cpf, nome, status, api_response, consulted_at) VALUES (?, NULL, 'invalid', ?, NOW()) ON CONFLICT (cpf) DO UPDATE SET status = 'invalid', api_response = ?, consulted_at = NOW()");
    $jsonResponse = json_encode($apiData);
    $stmt->execute([$cpf, $jsonResponse, $jsonResponse]);

    response(true, ['valid' => false], "CPF nao encontrado na Receita Federal");
}

// Sucesso - extrair nome
$nome = $apiData['nome'] ?? $apiData['name'] ?? '';

// Cachear resultado positivo
$stmt = $db->prepare("INSERT INTO om_cpf_cache (cpf, nome, status, api_response, consulted_at) VALUES (?, ?, 'valid', ?, NOW()) ON CONFLICT (cpf) DO UPDATE SET nome = ?, status = 'valid', api_response = ?, consulted_at = NOW()");
$jsonResponse = json_encode($apiData);
$stmt->execute([$cpf, $nome, $jsonResponse, $nome, $jsonResponse]);

response(true, [
    'valid' => true,
    'nome' => $nome,
]);

// ───────────────────────────────
// Funcao MOD-11
// ───────────────────────────────
function validarCpfMod11(string $cpf): bool {
    // Rejeitar sequencias repetidas (00000000000, 11111111111, etc)
    if (preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    // Primeiro digito verificador
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$cpf[$i] * (10 - $i);
    }
    $remainder = $sum % 11;
    $digit1 = ($remainder < 2) ? 0 : (11 - $remainder);

    if ((int)$cpf[9] !== $digit1) {
        return false;
    }

    // Segundo digito verificador
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += (int)$cpf[$i] * (11 - $i);
    }
    $remainder = $sum % 11;
    $digit2 = ($remainder < 2) ? 0 : (11 - $remainder);

    return (int)$cpf[10] === $digit2;
}
