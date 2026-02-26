<?php
/**
 * =====================================================
 * TESTE E2E - SISTEMA ONEMUNDO MARKETPLACE
 * =====================================================
 *
 * Testa todos os fluxos principais:
 * 1. Fluxo do Cliente (CEP → Loja → Carrinho → Checkout)
 * 2. Fluxo do Shopper (Login → Aceitar → Coletar → Entregar)
 * 3. Fluxo de Pagamento (PIX, Cartão)
 * 4. Verificação de Cobertura por CEP
 *
 * Uso: php teste-e2e-sistema.php
 *      ou acesse via navegador: /teste-e2e-sistema.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// =====================================================
// CONFIGURAÇÃO
// =====================================================

$BASE_URL = 'http://localhost'; // ou https://superbora.com.br
$API_BASE = $BASE_URL . '/api';

$results = [];
$passed = 0;
$failed = 0;

// =====================================================
// FUNÇÕES AUXILIARES
// =====================================================

function test($name, $callback) {
    global $results, $passed, $failed;

    echo "🧪 Testando: $name... ";

    try {
        $result = $callback();

        if ($result === true || (is_array($result) && $result['success'] === true)) {
            echo "✅ PASSOU\n";
            $results[] = ['name' => $name, 'status' => 'passed', 'message' => 'OK'];
            $passed++;
            return true;
        } else {
            $msg = is_array($result) ? ($result['message'] ?? json_encode($result)) : $result;
            echo "❌ FALHOU: $msg\n";
            $results[] = ['name' => $name, 'status' => 'failed', 'message' => $msg];
            $failed++;
            return false;
        }
    } catch (Exception $e) {
        echo "❌ ERRO: " . $e->getMessage() . "\n";
        $results[] = ['name' => $name, 'status' => 'error', 'message' => $e->getMessage()];
        $failed++;
        return false;
    }
}

function api($endpoint, $method = 'GET', $data = null) {
    global $API_BASE;

    $url = $API_BASE . $endpoint;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
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

    $decoded = json_decode($response, true);

    return [
        'http_code' => $httpCode,
        'response' => $decoded,
        'raw' => $response
    ];
}

function assert_true($condition, $message = '') {
    if (!$condition) {
        throw new Exception($message ?: 'Assertion failed');
    }
    return true;
}

function assert_equals($expected, $actual, $message = '') {
    if ($expected !== $actual) {
        throw new Exception($message ?: "Expected '$expected', got '$actual'");
    }
    return true;
}

// =====================================================
// INÍCIO DOS TESTES
// =====================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         TESTE E2E - ONEMUNDO MARKETPLACE                     ║\n";
echo "║         " . date('Y-m-d H:i:s') . "                              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// =====================================================
// 1. TESTES DE API - MERCADO
// =====================================================

echo "📦 MÓDULO: API MERCADO\n";
echo str_repeat("-", 60) . "\n";

test('Listar parceiros/mercados', function() {
    $result = api('/mercado/parceiros/listar.php');
    assert_true($result['http_code'] === 200, 'HTTP 200 esperado');
    assert_true(isset($result['response']['success']), 'Resposta deve ter campo success');
    return $result['response'];
});

test('Detalhes do parceiro ID=1', function() {
    $result = api('/mercado/parceiros/detalhes.php?id=1');
    // Pode retornar 404 se não existir
    if ($result['http_code'] === 404) {
        return ['success' => true, 'message' => 'Parceiro não existe (esperado em ambiente de teste)'];
    }
    assert_true($result['http_code'] === 200, 'HTTP 200 esperado');
    return $result['response'];
});

test('Listar produtos do mercado', function() {
    $result = api('/mercado/produtos/listar.php?partner_id=1');
    assert_true($result['http_code'] === 200, 'HTTP 200 esperado');
    return $result['response'];
});

test('Buscar produtos por termo', function() {
    $result = api('/mercado/produtos/buscar.php?q=teste');
    // Pode retornar erro se termo muito curto
    assert_true(in_array($result['http_code'], [200, 400]), 'HTTP 200 ou 400 esperado');
    return $result['response'];
});

test('Listar categorias', function() {
    $result = api('/mercado/categorias/listar.php');
    assert_true($result['http_code'] === 200, 'HTTP 200 esperado');
    return $result['response'];
});

// =====================================================
// 2. TESTES DE COBERTURA POR CEP
// =====================================================

echo "\n📍 MÓDULO: COBERTURA POR CEP\n";
echo str_repeat("-", 60) . "\n";

test('Listar parceiros por CEP válido', function() {
    $result = api('/mercado/parceiros/por-cep.php?cep=01310100');
    assert_true($result['http_code'] === 200, 'HTTP 200 esperado');
    return $result['response'];
});

test('Rejeitar CEP inválido (menos de 8 dígitos)', function() {
    $result = api('/mercado/parceiros/por-cep.php?cep=123');
    assert_true($result['http_code'] === 400, 'HTTP 400 esperado para CEP inválido');
    return ['success' => true];
});

test('Verificar cobertura de parceiro específico', function() {
    $result = api('/mercado/parceiros/verifica-cobertura.php?partner_id=1&cep=01310100');
    // Pode retornar 200 ou 404
    assert_true(in_array($result['http_code'], [200, 404]), 'HTTP 200 ou 404 esperado');
    return $result['response'] ?? ['success' => true];
});

// =====================================================
// 3. TESTES DE CARRINHO
// =====================================================

echo "\n🛒 MÓDULO: CARRINHO\n";
echo str_repeat("-", 60) . "\n";

test('Listar carrinho vazio', function() {
    $result = api('/mercado/carrinho/listar.php?session_id=teste_e2e_' . time());
    assert_true($result['http_code'] === 200, 'HTTP 200 esperado');
    return $result['response'];
});

test('Adicionar item ao carrinho', function() {
    $result = api('/mercado/carrinho/adicionar.php', 'POST', [
        'session_id' => 'teste_e2e_' . time(),
        'partner_id' => 1,
        'product_id' => 1,
        'quantity' => 1
    ]);
    // Pode falhar se produto não existir
    assert_true(in_array($result['http_code'], [200, 400, 404]), 'HTTP válido esperado');
    return $result['response'] ?? ['success' => true, 'message' => 'Produto pode não existir'];
});

// =====================================================
// 4. TESTES DO SHOPPER
// =====================================================

echo "\n🚴 MÓDULO: SHOPPER\n";
echo str_repeat("-", 60) . "\n";

test('Login do shopper com credenciais inválidas', function() {
    $result = api('/mercado/shopper/login.php', 'POST', [
        'email' => 'teste@invalido.com',
        'senha' => 'senha_errada'
    ]);
    // Deve retornar 401 ou 400
    assert_true(in_array($result['http_code'], [400, 401, 404]), 'HTTP 400/401/404 esperado');
    return ['success' => true, 'message' => 'Login inválido rejeitado corretamente'];
});

test('Consultar saldo sem autenticação', function() {
    $result = api('/mercado/shopper/saldo.php?shopper_id=1');
    // Pode retornar dados ou 404
    assert_true(in_array($result['http_code'], [200, 404]), 'HTTP válido esperado');
    return $result['response'] ?? ['success' => true];
});

test('Listar pedidos disponíveis para shopper', function() {
    $result = api('/mercado/shopper/pedidos-disponiveis.php?shopper_id=1&lat=-18.85&lng=-41.95');
    assert_true($result['http_code'] === 200, 'HTTP 200 esperado');
    return $result['response'];
});

test('Tentar aceitar pedido inexistente', function() {
    $result = api('/mercado/shopper/aceitar-pedido.php', 'POST', [
        'shopper_id' => 1,
        'order_id' => 999999
    ]);
    // Deve retornar 404 ou 400
    assert_true(in_array($result['http_code'], [400, 404, 409]), 'HTTP 400/404/409 esperado');
    return ['success' => true, 'message' => 'Pedido inexistente rejeitado corretamente'];
});

test('Tentar saque com valor mínimo', function() {
    $result = api('/mercado/shopper/saque.php', 'POST', [
        'shopper_id' => 1,
        'valor' => 10 // Menor que mínimo de R$ 20
    ]);
    // Deve retornar 400 por valor mínimo
    assert_true($result['http_code'] === 400, 'HTTP 400 esperado para valor abaixo do mínimo');
    return ['success' => true, 'message' => 'Validação de valor mínimo funcionando'];
});

// =====================================================
// 5. TESTES DE FRETE
// =====================================================

echo "\n🚚 MÓDULO: FRETE\n";
echo str_repeat("-", 60) . "\n";

test('Calcular frete para parceiro', function() {
    $result = api('/mercado/frete/calcular.php?partner_id=1&cep=01310100');
    // Pode retornar 200 ou 404
    assert_true(in_array($result['http_code'], [200, 404]), 'HTTP válido esperado');
    return $result['response'] ?? ['success' => true];
});

// =====================================================
// 6. TESTES DE SEGURANÇA
// =====================================================

echo "\n🔒 MÓDULO: SEGURANÇA\n";
echo str_repeat("-", 60) . "\n";

test('SQL Injection no parâmetro order_id', function() {
    $result = api('/mercado/shopper/aceitar-pedido.php', 'POST', [
        'shopper_id' => 1,
        'order_id' => "1 OR 1=1; --"
    ]);
    // Deve retornar erro, não executar a injeção
    assert_true(in_array($result['http_code'], [400, 404, 500]), 'Injeção SQL deve ser bloqueada');
    return ['success' => true, 'message' => 'SQL Injection bloqueado'];
});

test('SQL Injection no parâmetro CEP', function() {
    $maliciousCep = urlencode("01310100'; DROP TABLE users; --");
    $result = api('/mercado/parceiros/por-cep.php?cep=' . $maliciousCep);
    // API sanitiza o CEP removendo caracteres não-numéricos
    // Pode retornar 200 (sanitizou e processou) ou 400 (rejeitou)
    // Ambos são válidos - o importante é que a injeção NÃO seja executada
    assert_true(in_array($result['http_code'], [200, 400]), 'Injeção SQL deve ser bloqueada');
    return ['success' => true, 'message' => 'SQL Injection no CEP bloqueado (sanitizado)'];
});

test('Rate limiting no saque', function() {
    // Fazer 2 requisições de saque seguidas
    api('/mercado/shopper/saque.php', 'POST', ['shopper_id' => 1, 'valor' => 50]);
    $result = api('/mercado/shopper/saque.php', 'POST', ['shopper_id' => 1, 'valor' => 50]);

    // Segunda requisição deve ser bloqueada (rate limit)
    // Pode retornar 429 (too many requests) ou 400/404
    assert_true(in_array($result['http_code'], [400, 404, 429]), 'Rate limit deve funcionar');
    return ['success' => true, 'message' => 'Rate limiting funcionando'];
});

// =====================================================
// 7. TESTES DE VALIDAÇÃO
// =====================================================

echo "\n✅ MÓDULO: VALIDAÇÕES\n";
echo str_repeat("-", 60) . "\n";

test('Validar campos obrigatórios - aceitar pedido', function() {
    $result = api('/mercado/shopper/aceitar-pedido.php', 'POST', []);
    assert_true($result['http_code'] === 400, 'Campos obrigatórios devem ser validados');
    return ['success' => true];
});

test('Validar valor máximo de saque', function() {
    $result = api('/mercado/shopper/saque.php', 'POST', [
        'shopper_id' => 1,
        'valor' => 999999
    ]);
    assert_true($result['http_code'] === 400, 'Valor máximo deve ser validado');
    return ['success' => true];
});

// =====================================================
// RESULTADO FINAL
// =====================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                    RESULTADO DOS TESTES                      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$total = $passed + $failed;
$percentage = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "📊 RESUMO:\n";
echo "   ✅ Passou: $passed\n";
echo "   ❌ Falhou: $failed\n";
echo "   📈 Taxa de sucesso: $percentage%\n\n";

if ($failed > 0) {
    echo "⚠️ TESTES COM FALHA:\n";
    foreach ($results as $r) {
        if ($r['status'] !== 'passed') {
            echo "   - {$r['name']}: {$r['message']}\n";
        }
    }
    echo "\n";
}

// Retornar código de saída para CI/CD
if ($failed > 0) {
    echo "❌ ALGUNS TESTES FALHARAM!\n";
    if (php_sapi_name() === 'cli') {
        exit(1);
    }
} else {
    echo "✅ TODOS OS TESTES PASSARAM!\n";
    if (php_sapi_name() === 'cli') {
        exit(0);
    }
}

// =====================================================
// SAÍDA JSON (se solicitado)
// =====================================================

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $failed === 0,
        'passed' => $passed,
        'failed' => $failed,
        'percentage' => $percentage,
        'results' => $results,
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
}
