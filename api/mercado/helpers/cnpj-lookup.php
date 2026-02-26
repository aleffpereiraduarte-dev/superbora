<?php
/**
 * Helper: Consulta CNPJ via BrasilAPI + Validacao CNAE
 *
 * consultarCNPJ($cnpj)  - Busca dados na BrasilAPI
 * validarCNAE($cnaePrincipal, $cnaesSecundarios) - Valida ramo alimenticio
 */

/**
 * Consulta CNPJ na BrasilAPI
 * @param string $cnpj CNPJ apenas numeros (14 digitos)
 * @return array ['success' => bool, 'data' => [...], 'error' => string]
 */
function consultarCNPJ(string $cnpj): array {
    $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
    if (strlen($cnpj) !== 14) {
        return ['success' => false, 'data' => null, 'error' => 'CNPJ deve ter 14 digitos'];
    }

    $url = "https://brasilapi.com.br/api/cnpj/v1/{$cnpj}";

    // SECURITY: Use cURL for better error handling and logging
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'BrasilAPI-Client/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $result === false) {
        error_log("[cnpj-lookup] cURL error querying BrasilAPI: " . ($curlError ?: 'empty response'));
        return ['success' => false, 'data' => null, 'error' => 'Erro ao consultar BrasilAPI. Tente novamente.'];
    }

    if ($httpCode !== 200) {
        error_log("[cnpj-lookup] BrasilAPI returned HTTP $httpCode for CNPJ lookup");
        return ['success' => false, 'data' => null, 'error' => 'Erro ao consultar BrasilAPI. Tente novamente.'];
    }

    $data = json_decode($result, true);

    if (!$data || isset($data['type']) && $data['type'] === 'not_found') {
        return ['success' => false, 'data' => null, 'error' => 'CNPJ nao encontrado na Receita Federal.'];
    }

    // Extrair campos relevantes
    $cnaesSecundarios = [];
    if (!empty($data['cnaes_secundarios']) && is_array($data['cnaes_secundarios'])) {
        foreach ($data['cnaes_secundarios'] as $cs) {
            $cnaesSecundarios[] = [
                'codigo' => (string)($cs['codigo'] ?? ''),
                'descricao' => $cs['descricao'] ?? '',
            ];
        }
    }

    return [
        'success' => true,
        'data' => [
            'razao_social' => $data['razao_social'] ?? '',
            'nome_fantasia' => $data['nome_fantasia'] ?? '',
            'cnae_fiscal' => (string)($data['cnae_fiscal'] ?? ''),
            'cnae_fiscal_descricao' => $data['cnae_fiscal_descricao'] ?? '',
            'descricao_situacao_cadastral' => $data['descricao_situacao_cadastral'] ?? '',
            'situacao_cadastral' => (int)($data['situacao_cadastral'] ?? 0),
            'cnaes_secundarios' => $cnaesSecundarios,
            'uf' => $data['uf'] ?? '',
            'municipio' => $data['municipio'] ?? '',
            'logradouro' => $data['logradouro'] ?? '',
            'numero' => $data['numero'] ?? '',
            'bairro' => $data['bairro'] ?? '',
            'cep' => $data['cep'] ?? '',
        ],
        'error' => null,
    ];
}

/**
 * Valida se o CNAE e do ramo alimenticio/mercado
 * @param string $cnaePrincipal Codigo CNAE principal (ex: "5611201")
 * @param array $cnaesSecundarios Array de ['codigo' => '...', 'descricao' => '...']
 * @return array ['valid' => bool, 'matched_cnae' => string, 'matched_descricao' => string]
 */
function validarCNAE(string $cnaePrincipal, array $cnaesSecundarios = []): array {
    // Prefixos aceitos (4 primeiros digitos do CNAE)
    $prefixosAceitos = [
        // Restaurantes e alimentacao
        '5611' => 'Restaurantes e similares',
        '5612' => 'Servicos ambulantes de alimentacao',
        '5620' => 'Catering, bufes e outros servicos de comida preparada',
        // Supermercados e mercados
        '4711' => 'Comercio varejista - hipermercados e supermercados',
        '4712' => 'Comercio varejista - minimercados e mercearias',
        // Alimentos especializados
        '4721' => 'Comercio varejista - produtos de padaria, laticinio, doces',
        '4722' => 'Comercio varejista - carnes e pescados (acougue)',
        '4723' => 'Comercio varejista - bebidas',
        '4724' => 'Comercio varejista - hortifrutigranjeiros',
        '4729' => 'Comercio varejista - produtos alimenticios em geral',
        // Industria alimenticia
        '1091' => 'Fabricacao de produtos de panificacao',
        '1092' => 'Fabricacao de biscoitos e bolachas',
        '1093' => 'Fabricacao de produtos alimenticios nao especificados',
        '1094' => 'Fabricacao de massas alimenticias',
        '1095' => 'Fabricacao de especiarias, molhos e condimentos',
        '1096' => 'Fabricacao de alimentos e pratos prontos',
        // Sorvetes e acai
        '1099' => 'Fabricacao de produtos alimenticios nao especificados',
        '4781' => 'Comercio varejista - artigos do vestuario e acessorios', // Nao - removido
        // Farmacias (aceitas no app)
        '4771' => 'Comercio varejista - produtos farmaceuticos',
        // Pet shops (aceitos no app)
        '4789' => 'Comercio varejista - outros produtos novos',
    ];

    // Remover entrada errada
    unset($prefixosAceitos['4781']);

    // Verificar CNAE principal
    if (!empty($cnaePrincipal)) {
        $prefixo = substr($cnaePrincipal, 0, 4);
        if (isset($prefixosAceitos[$prefixo])) {
            return [
                'valid' => true,
                'matched_cnae' => $cnaePrincipal,
                'matched_descricao' => $prefixosAceitos[$prefixo],
            ];
        }
    }

    // Verificar CNAEs secundarios
    foreach ($cnaesSecundarios as $cnae) {
        $codigo = $cnae['codigo'] ?? '';
        if (empty($codigo)) continue;
        $prefixo = substr($codigo, 0, 4);
        if (isset($prefixosAceitos[$prefixo])) {
            return [
                'valid' => true,
                'matched_cnae' => $codigo,
                'matched_descricao' => $prefixosAceitos[$prefixo],
            ];
        }
    }

    return [
        'valid' => false,
        'matched_cnae' => '',
        'matched_descricao' => '',
    ];
}
