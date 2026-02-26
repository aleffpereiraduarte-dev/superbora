<?php
/**
 * 🔍 Capturar erro 500 - Instalador C
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<h1>🔍 Capturar Erro - Instalador C</h1>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;} pre{background:#1e293b;padding:16px;border-radius:8px;overflow-x:auto;font-size:12px;} .error{color:#ef4444;} .success{color:#10b981;}</style>";

$onePath = __DIR__ . '/one.php';
$conteudo = file_get_contents($onePath);

// Verificar sintaxe
echo "<h2>1️⃣ Verificar Sintaxe PHP</h2>";
$output = shell_exec("php -l $onePath 2>&1");
echo "<pre>$output</pre>";

// Verificar funções
echo "<h2>2️⃣ Funções Necessárias</h2>";
$funcoes = [
    'buscarProdutoCatalogo',
    'buscarProdutoMercado',
    'detectarIntencaoUniversal',
    'carregarClienteCompleto',
    'salvarContexto',
    'carregarContexto',
    'converterDataRelativa'
];

foreach ($funcoes as $f) {
    $existe = strpos($conteudo, "function $f") !== false;
    $status = $existe ? "<span class='success'>✅</span>" : "<span class='error'>❌</span>";
    echo "<p>$status $f()</p>";
}

// Tentar executar
echo "<h2>3️⃣ Tentar Executar</h2>";
echo "<pre>";

try {
    $_GET['action'] = 'send';
    $_GET['message'] = 'quero comprar um notebook';
    
    ob_start();
    include($onePath);
    $output = ob_get_clean();
    
    echo "Saída: " . htmlspecialchars(substr($output, 0, 2000));
    
} catch (Throwable $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString();
}

echo "</pre>";

// Mostrar código do ecommerce
echo "<h2>4️⃣ Código do ECOMMERCE</h2>";
if (preg_match('/\/\/ ECOMMERCE.*?return \[.success.*?\];/s', $conteudo, $match)) {
    echo "<pre>" . htmlspecialchars(substr($match[0], 0, 2000)) . "</pre>";
} else {
    echo "<p class='error'>Bloco ECOMMERCE não encontrado</p>";
}
