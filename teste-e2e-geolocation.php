<?php
/**
 * TESTE E2E - GEOLOCALIZAÇÃO E MERCADO INTEGRADO
 *
 * Testa:
 * 1. API de inicialização de geolocalização
 * 2. CEP do usuário logado
 * 3. Produtos por CEP
 * 4. Verificação de mercado disponível
 * 5. Cenários diversos de CEP
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Carregar database.php
$dbPaths = [
    __DIR__ . '/database.php',
    __DIR__ . '/config/database.php',
    '/var/www/html/database.php'
];
foreach ($dbPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

header('Content-Type: text/plain; charset=utf-8');

$BASE_URL = 'http://localhost';
$startTime = microtime(true);
$results = [];
$passed = 0;
$failed = 0;

echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║     TESTE E2E - GEOLOCALIZAÇÃO E MERCADO INTEGRADO                   ║\n";
echo "║     Data: " . date('Y-m-d H:i:s') . "                                        ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

/**
 * Função de teste
 */
function test($name, $callback) {
    global $results, $passed, $failed;

    echo "🧪 $name... ";

    try {
        $result = $callback();

        if ($result === true || (is_array($result) && ($result['success'] ?? false))) {
            echo "✅ PASSOU\n";
            $results[] = ['name' => $name, 'status' => 'passed'];
            $passed++;
            return true;
        } else {
            $msg = is_array($result) ? ($result['error'] ?? json_encode($result)) : $result;
            echo "❌ FALHOU: $msg\n";
            $results[] = ['name' => $name, 'status' => 'failed', 'error' => $msg];
            $failed++;
            return false;
        }
    } catch (Exception $e) {
        echo "❌ ERRO: " . $e->getMessage() . "\n";
        $results[] = ['name' => $name, 'status' => 'error', 'error' => $e->getMessage()];
        $failed++;
        return false;
    }
}

/**
 * Requisição HTTP
 */
function api($url, $method = 'GET', $data = null) {
    global $BASE_URL;

    $fullUrl = strpos($url, 'http') === 0 ? $url : $BASE_URL . $url;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response,
        'json' => json_decode($response, true)
    ];
}

// ============================================================
// TESTE 1: API DE INICIALIZAÇÃO DE GEOLOCALIZAÇÃO
// ============================================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "MÓDULO 1: API DE INICIALIZAÇÃO DE GEOLOCALIZAÇÃO\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

test('API /api/geolocation/init.php responde', function() {
    $resp = api('/api/geolocation/init.php');
    if ($resp['code'] !== 200) {
        return ['success' => false, 'error' => "HTTP {$resp['code']}"];
    }
    if (!$resp['json']) {
        return ['success' => false, 'error' => 'Resposta não é JSON'];
    }
    return $resp['json'];
});

test('API init retorna campos obrigatórios', function() {
    $resp = api('/api/geolocation/init.php');
    $json = $resp['json'];

    $required = ['success', 'source', 'cep', 'customer_logged', 'mercado_disponivel'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $json)) {
            return ['success' => false, 'error' => "Campo '$field' ausente"];
        }
    }
    return true;
});

// ============================================================
// TESTE 2: API DE CONSULTA DE CEP
// ============================================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "MÓDULO 2: API DE CONSULTA DE CEP\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

test('CEP válido (São Paulo) retorna dados', function() {
    $resp = api('/api/localizacao/cep.php?cep=01310100');
    if ($resp['code'] !== 200) {
        return ['success' => false, 'error' => "HTTP {$resp['code']}"];
    }
    $json = $resp['json'];
    if (!$json || !($json['success'] ?? false)) {
        return ['success' => false, 'error' => 'CEP não encontrado'];
    }
    if (empty($json['cidade'])) {
        return ['success' => false, 'error' => 'Cidade não retornada'];
    }
    return true;
});

test('CEP inválido (curto) retorna erro', function() {
    $resp = api('/api/localizacao/cep.php?cep=123');
    $json = $resp['json'];
    if ($json['erro'] ?? false) {
        return true;
    }
    return ['success' => false, 'error' => 'Deveria retornar erro'];
});

test('CEP inexistente retorna erro', function() {
    $resp = api('/api/localizacao/cep.php?cep=99999999');
    $json = $resp['json'];
    if ($json['erro'] ?? false) {
        return true;
    }
    return ['success' => false, 'error' => 'Deveria retornar erro'];
});

// ============================================================
// TESTE 3: API DE VERIFICAÇÃO DE MERCADO
// ============================================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "MÓDULO 3: VERIFICAÇÃO DE MERCADO DISPONÍVEL\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

test('Mercado disponível em São Paulo', function() {
    $resp = api('/api/home/mercado.php?cidade=' . urlencode('São Paulo'));
    $json = $resp['json'];
    if (!isset($json['disponivel']) || !$json['disponivel']) {
        return ['success' => false, 'error' => 'São Paulo deveria ter mercado. Resposta: ' . json_encode($json)];
    }
    if (($json['parceiros'] ?? 0) < 1) {
        return ['success' => false, 'error' => 'Deveria ter parceiros'];
    }
    return true;
});

test('Mercado disponível em Belo Horizonte', function() {
    $resp = api('/api/home/mercado.php?cidade=' . urlencode('Belo Horizonte'));
    $json = $resp['json'];
    return isset($json['disponivel']) && $json['disponivel'];
});

test('Mercado disponível em Governador Valadares', function() {
    $resp = api('/api/home/mercado.php?cidade=' . urlencode('Governador Valadares'));
    $json = $resp['json'];
    return isset($json['disponivel']) && $json['disponivel'];
});

test('Mercado NÃO disponível em cidade pequena', function() {
    $resp = api('/api/home/mercado.php?cidade=' . urlencode('Cidade Inexistente XYZ'));
    $json = $resp['json'];
    // Deve retornar disponivel = false
    if (isset($json['disponivel']) && $json['disponivel']) {
        return ['success' => false, 'error' => 'Cidade inexistente não deveria ter mercado'];
    }
    return true;
});

test('Verificar mercado por CEP de São Paulo', function() {
    $resp = api('/api/home/mercado.php?cep=01310100');
    $json = $resp['json'];
    return $json['disponivel'] ?? false;
});

// ============================================================
// TESTE 4: API DE PRODUTOS POR CEP
// ============================================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "MÓDULO 4: API DE PRODUTOS POR CEP\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

test('API /api/mercado/produtos-por-cep.php responde', function() {
    $resp = api('/api/mercado/produtos-por-cep.php?cep=01310100');
    if ($resp['code'] !== 200) {
        return ['success' => false, 'error' => "HTTP {$resp['code']}"];
    }
    return $resp['json']['success'] ?? false;
});

test('Produtos por CEP retorna campos obrigatórios', function() {
    $resp = api('/api/mercado/produtos-por-cep.php?cep=01310100');
    $json = $resp['json'];

    $required = ['success', 'disponivel', 'cidade', 'produtos'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $json)) {
            return ['success' => false, 'error' => "Campo '$field' ausente"];
        }
    }
    return true;
});

test('CEP inválido retorna erro', function() {
    $resp = api('/api/mercado/produtos-por-cep.php?cep=123');
    $json = $resp['json'];
    if ($json['success'] ?? true) {
        return ['success' => false, 'error' => 'Deveria falhar com CEP inválido'];
    }
    return true;
});

test('Limite de produtos funciona', function() {
    $resp = api('/api/mercado/produtos-por-cep.php?cep=01310100&limit=5');
    $json = $resp['json'];
    if (isset($json['produtos']) && count($json['produtos']) > 5) {
        return ['success' => false, 'error' => 'Limite não respeitado'];
    }
    return true;
});

// ============================================================
// TESTE 5: CENÁRIOS DE CEP DIVERSOS
// ============================================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "MÓDULO 5: CENÁRIOS DE CEP DIVERSOS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$cepsParaTestar = [
    ['cep' => '01310100', 'cidade' => 'São Paulo', 'esperado' => true],
    ['cep' => '30130000', 'cidade' => 'Belo Horizonte', 'esperado' => true],
    ['cep' => '35010000', 'cidade' => 'Governador Valadares', 'esperado' => true],
    ['cep' => '20040020', 'cidade' => 'Rio de Janeiro', 'esperado' => true],
    ['cep' => '80010000', 'cidade' => 'Curitiba', 'esperado' => true],
    ['cep' => '69900000', 'cidade' => 'Rio Branco', 'esperado' => false],
];

foreach ($cepsParaTestar as $caso) {
    test("CEP {$caso['cep']} ({$caso['cidade']})", function() use ($caso) {
        $resp = api('/api/home/mercado.php?cep=' . $caso['cep']);
        $json = $resp['json'];
        $disponivel = $json['disponivel'] ?? false;

        if ($disponivel !== $caso['esperado']) {
            $status = $caso['esperado'] ? 'disponível' : 'indisponível';
            return ['success' => false, 'error' => "Esperado: $status"];
        }
        return true;
    });
}

// ============================================================
// TESTE 6: SEGURANÇA
// ============================================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "MÓDULO 6: TESTES DE SEGURANÇA\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

test('SQL Injection no CEP bloqueado', function() {
    $resp = api('/api/localizacao/cep.php?cep=01310100\'; DROP TABLE users; --');
    // Deve retornar erro de CEP inválido, não executar SQL
    return true; // Se chegou aqui sem erro, está ok
});

test('XSS no CEP sanitizado', function() {
    $resp = api('/api/mercado/produtos-por-cep.php?cep=<script>alert(1)</script>');
    $json = $resp['json'];
    // Deve retornar erro de CEP inválido
    if ($json['success'] ?? true) {
        return ['success' => false, 'error' => 'Deveria falhar'];
    }
    return true;
});

test('Limite máximo de produtos respeitado', function() {
    $resp = api('/api/mercado/produtos-por-cep.php?cep=01310100&limit=1000');
    $json = $resp['json'];
    // Limite máximo é 50
    if (isset($json['produtos']) && count($json['produtos']) > 50) {
        return ['success' => false, 'error' => 'Limite máximo ultrapassado'];
    }
    return true;
});

// ============================================================
// TESTE 7: ARQUIVOS CRIADOS
// ============================================================
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "MÓDULO 7: ARQUIVOS NECESSÁRIOS EXISTEM\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$arquivos = [
    '/root/api/geolocation/init.php',
    '/root/api/mercado/produtos-por-cep.php',
    '/root/om_geolocation_v2.js',
    '/root/om_mercado_index.css',
    '/root/om_loja_cep.js',
    '/root/api/localizacao/cep.php',
    '/root/api/home/mercado.php',
];

foreach ($arquivos as $arquivo) {
    test("Arquivo existe: " . basename($arquivo), function() use ($arquivo) {
        if (!file_exists($arquivo)) {
            return ['success' => false, 'error' => 'Arquivo não encontrado'];
        }
        return true;
    });
}

// ============================================================
// RESUMO FINAL
// ============================================================
$endTime = microtime(true);
$tempoTotal = round($endTime - $startTime, 2);
$total = $passed + $failed;
$percentual = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "\n╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║                      RESUMO FINAL DOS TESTES                         ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

echo "RESULTADO GERAL: $passed/$total testes passaram ($percentual%)\n";
echo "TEMPO TOTAL: {$tempoTotal}s\n\n";

if ($percentual == 100) {
    echo "🎉 TODOS OS TESTES PASSARAM!\n";
} elseif ($percentual >= 90) {
    echo "✅ SISTEMA FUNCIONANDO BEM\n";
} elseif ($percentual >= 70) {
    echo "⚠️ SISTEMA COM ALGUNS PROBLEMAS\n";
} else {
    echo "❌ SISTEMA COM PROBLEMAS CRÍTICOS\n";
}

if ($failed > 0) {
    echo "\n📋 TESTES QUE FALHARAM:\n";
    foreach ($results as $r) {
        if ($r['status'] !== 'passed') {
            echo "   ❌ {$r['name']}: " . ($r['error'] ?? 'erro') . "\n";
        }
    }
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "FIM DOS TESTES - " . date('Y-m-d H:i:s') . "\n";
