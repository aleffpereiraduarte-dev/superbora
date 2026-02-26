<?php
/**
 * GET /api/mercado/parceiro/consultar-cnpj.php?cnpj=12345678000190
 *
 * Consulta CNPJ na BrasilAPI para auto-preencher campos no cadastro.
 * Retorna razao social, nome fantasia, situacao, CNAE e validacao do ramo.
 * Publico (sem autenticacao), mas com rate limit.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once __DIR__ . "/../helpers/cnpj-lookup.php";

// Rate limit: 10 consultas por minuto por IP
if (!RateLimiter::check(10, 60)) {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Use GET com ?cnpj=XXXXX", 405);
}

$cnpj = preg_replace('/[^0-9]/', '', $_GET['cnpj'] ?? '');

if (strlen($cnpj) !== 14) {
    response(false, null, "CNPJ invalido. Informe 14 digitos.", 400);
}

// Validar checksum MOD-11
if (!validarCNPJChecksum($cnpj)) {
    response(false, null, "CNPJ invalido (digitos verificadores incorretos).", 400);
}

// Consultar BrasilAPI
$resultado = consultarCNPJ($cnpj);

if (!$resultado['success']) {
    response(false, null, $resultado['error'], 502);
}

$dados = $resultado['data'];

// Validar CNAE
$cnaeResult = validarCNAE($dados['cnae_fiscal'], $dados['cnaes_secundarios']);

// Verificar situacao cadastral (2 = ATIVA)
$situacaoAtiva = ($dados['situacao_cadastral'] === 2);

response(true, [
    'cnpj' => $cnpj,
    'razao_social' => $dados['razao_social'],
    'nome_fantasia' => $dados['nome_fantasia'],
    'cnae_principal' => $dados['cnae_fiscal'],
    'cnae_descricao' => $dados['cnae_fiscal_descricao'],
    'situacao' => $dados['descricao_situacao_cadastral'],
    'situacao_ativa' => $situacaoAtiva,
    'cnae_valido' => $cnaeResult['valid'],
    'cnae_matched' => $cnaeResult['matched_cnae'],
    'cnae_matched_descricao' => $cnaeResult['matched_descricao'],
    'endereco' => [
        'logradouro' => $dados['logradouro'],
        'numero' => $dados['numero'],
        'bairro' => $dados['bairro'],
        'cidade' => $dados['municipio'],
        'estado' => $dados['uf'],
        'cep' => $dados['cep'],
    ],
], $cnaeResult['valid']
    ? "CNPJ valido para cadastro."
    : "CNPJ encontrado, mas o ramo de atividade nao e compativel com a plataforma.");

/**
 * Validacao MOD-11 de CNPJ (duplicada aqui para evitar dependencia do cadastro.php)
 */
function validarCNPJChecksum(string $cnpj): bool {
    $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
    if (strlen($cnpj) !== 14) return false;
    if (preg_match('/^(\d)\1{13}$/', $cnpj)) return false;

    $tamanho = strlen($cnpj) - 2;
    $numeros = substr($cnpj, 0, $tamanho);
    $digitos = substr($cnpj, $tamanho);

    $soma = 0;
    $pos = $tamanho - 7;
    for ($i = $tamanho; $i >= 1; $i--) {
        $soma += $numeros[$tamanho - $i] * $pos--;
        if ($pos < 2) $pos = 9;
    }
    $resultado = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
    if ($resultado != $digitos[0]) return false;

    $tamanho++;
    $numeros = substr($cnpj, 0, $tamanho);
    $soma = 0;
    $pos = $tamanho - 7;
    for ($i = $tamanho; $i >= 1; $i--) {
        $soma += $numeros[$tamanho - $i] * $pos--;
        if ($pos < 2) $pos = 9;
    }
    $resultado = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
    if ($resultado != $digitos[1]) return false;

    return true;
}
