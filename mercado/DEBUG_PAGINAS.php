<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔍 DEBUG - Testa páginas que estão dando timeout
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 10);

echo "<h1>🔍 Debug de Páginas</h1>";
echo "<style>body{font-family:Arial;background:#0f172a;color:#fff;padding:20px} .ok{color:#10b981} .err{color:#ef4444} pre{background:#1e293b;padding:15px;border-radius:8px;overflow:auto}</style>";

// ============================================================================
// 1. TESTAR CARRINHO
// ============================================================================
echo "<h2>1. carrinho.php</h2>";
try {
    ob_start();
    include __DIR__ . '/carrinho.php';
    $output = ob_get_clean();
    echo "<p class='ok'>✅ Carregou (" . strlen($output) . " bytes)</p>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "<p class='err'>❌ Erro: " . $e->getMessage() . "</p>";
    echo "<pre>Linha: " . $e->getLine() . "\nArquivo: " . $e->getFile() . "</pre>";
}

// ============================================================================
// 2. TESTAR CHECKOUT
// ============================================================================
echo "<h2>2. checkout.php</h2>";
try {
    // Simular sessão
    session_name('OCSESSID');
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['customer_id'] = $_SESSION['customer_id'] ?? 2;
    $_SESSION['market_cart'] = $_SESSION['market_cart'] ?? ['1' => ['product_id' => 1, 'name' => 'Teste', 'price' => 10, 'qty' => 1]];
    
    ob_start();
    include __DIR__ . '/checkout.php';
    $output = ob_get_clean();
    echo "<p class='ok'>✅ Carregou (" . strlen($output) . " bytes)</p>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "<p class='err'>❌ Erro: " . $e->getMessage() . "</p>";
    echo "<pre>Linha: " . $e->getLine() . "\nArquivo: " . $e->getFile() . "</pre>";
}

// ============================================================================
// 3. TESTAR API BUSCA
// ============================================================================
echo "<h2>3. api/busca.php</h2>";
$_GET['q'] = 'arroz';
try {
    ob_start();
    include __DIR__ . '/api/busca.php';
    $output = ob_get_clean();
    echo "<p class='ok'>✅ Resposta:</p>";
    echo "<pre>" . htmlspecialchars($output) . "</pre>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "<p class='err'>❌ Erro: " . $e->getMessage() . "</p>";
    echo "<pre>Linha: " . $e->getLine() . "\nArquivo: " . $e->getFile() . "</pre>";
}

// ============================================================================
// 4. TESTAR OFERTAS
// ============================================================================
echo "<h2>4. ofertas/index.php</h2>";
try {
    ob_start();
    include __DIR__ . '/ofertas/index.php';
    $output = ob_get_clean();
    echo "<p class='ok'>✅ Carregou (" . strlen($output) . " bytes)</p>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "<p class='err'>❌ Erro: " . $e->getMessage() . "</p>";
    echo "<pre>Linha: " . $e->getLine() . "\nArquivo: " . $e->getFile() . "</pre>";
}

// ============================================================================
// 5. TESTAR CATEGORIAS
// ============================================================================
echo "<h2>5. categorias/index.php</h2>";
try {
    ob_start();
    include __DIR__ . '/categorias/index.php';
    $output = ob_get_clean();
    echo "<p class='ok'>✅ Carregou (" . strlen($output) . " bytes)</p>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "<p class='err'>❌ Erro: " . $e->getMessage() . "</p>";
    echo "<pre>Linha: " . $e->getLine() . "\nArquivo: " . $e->getFile() . "</pre>";
}

// ============================================================================
// 6. TESTAR DELIVERY
// ============================================================================
echo "<h2>6. delivery/index.php</h2>";
$_SESSION['delivery_id'] = 1; // Simular login
try {
    ob_start();
    include __DIR__ . '/delivery/index.php';
    $output = ob_get_clean();
    echo "<p class='ok'>✅ Carregou (" . strlen($output) . " bytes)</p>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "<p class='err'>❌ Erro: " . $e->getMessage() . "</p>";
    echo "<pre>Linha: " . $e->getLine() . "\nArquivo: " . $e->getFile() . "</pre>";
}

// ============================================================================
// 7. TESTAR API PAGARME
// ============================================================================
echo "<h2>7. api/pagarme.php</h2>";
$_GET['action'] = 'test';
try {
    ob_start();
    include __DIR__ . '/api/pagarme.php';
    $output = ob_get_clean();
    echo "<p class='ok'>✅ Resposta:</p>";
    echo "<pre>" . htmlspecialchars($output) . "</pre>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "<p class='err'>❌ Erro: " . $e->getMessage() . "</p>";
    echo "<pre>Linha: " . $e->getLine() . "\nArquivo: " . $e->getFile() . "</pre>";
}

// ============================================================================
// 8. TESTAR PEDIDO CONFIRMADO
// ============================================================================
echo "<h2>8. pedido-confirmado.php</h2>";
$_GET['id'] = 1;
try {
    ob_start();
    include __DIR__ . '/pedido-confirmado.php';
    $output = ob_get_clean();
    echo "<p class='ok'>✅ Carregou (" . strlen($output) . " bytes)</p>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "<p class='err'>❌ Erro: " . $e->getMessage() . "</p>";
    echo "<pre>Linha: " . $e->getLine() . "\nArquivo: " . $e->getFile() . "</pre>";
}

// ============================================================================
// 9. VERIFICAR TABELAS NO BANCO
// ============================================================================
echo "<h2>9. Verificar Tabelas</h2>";
try {
    $pdo = getPDO();
    
    $tabelas = ['om_orders', 'om_order_items', 'om_market_partners', 'om_market_shoppers', 'om_market_categories'];
    
    foreach ($tabelas as $t) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            echo "<p class='ok'>✅ $t - $count registros</p>";
        } catch (Exception $e) {
            echo "<p class='err'>❌ $t - " . $e->getMessage() . "</p>";
        }
    }
} catch (Exception $e) {
    echo "<p class='err'>❌ Erro DB: " . $e->getMessage() . "</p>";
}

echo "<br><a href='/mercado/' style='color:#10b981'>← Voltar</a>";
