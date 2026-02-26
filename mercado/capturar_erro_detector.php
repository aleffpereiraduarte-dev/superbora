<?php
/**
 * 🔍 Capturar erro 500
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<h1>🔍 Capturar Erro</h1>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;} pre{background:#1e293b;padding:16px;border-radius:8px;overflow-x:auto;font-size:12px;} .error{color:#ef4444;}</style>";

$onePath = __DIR__ . '/one.php';
$conteudo = file_get_contents($onePath);

// Verificar sintaxe
echo "<h2>1️⃣ Verificar Sintaxe PHP</h2>";
$output = shell_exec("php -l $onePath 2>&1");
echo "<pre>$output</pre>";

// Procurar o código inserido
echo "<h2>2️⃣ Código do Detector Inserido</h2>";
if (strpos($conteudo, '// 🎯 ONE UNIVERSAL - DETECTOR DE INTENÇÃO') !== false) {
    // Extrair o trecho
    $inicio = strpos($conteudo, '// 🎯 ONE UNIVERSAL - DETECTOR DE INTENÇÃO');
    $fim = strpos($conteudo, '// FIM DETECTOR - Continua fluxo normal');
    if ($inicio && $fim) {
        $trecho = substr($conteudo, $inicio, $fim - $inicio + 100);
        echo "<pre>" . htmlspecialchars($trecho) . "</pre>";
    }
} else {
    echo "<p class='error'>❌ Código do detector não encontrado</p>";
}

// Verificar se funções existem
echo "<h2>3️⃣ Funções Necessárias</h2>";
$funcoes = [
    'detectarIntencao',
    'carregarClienteCompleto', 
    'salvarContexto',
    'getCarrinho',
    'getTotal'
];

foreach ($funcoes as $f) {
    $existe = strpos($conteudo, "function $f") !== false;
    $status = $existe ? "✅" : "❌";
    echo "<p>$status $f()</p>";
}

// Tentar executar com try-catch
echo "<h2>4️⃣ Tentar Executar</h2>";
echo "<pre>";

try {
    // Simular requisição
    $_GET['action'] = 'send';
    $_GET['message'] = 'quero ir pra miami';
    
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

// Mostrar linhas ao redor do código inserido
echo "<h2>5️⃣ Contexto (linhas 8295-8320)</h2>";
$linhas = explode("\n", $conteudo);
echo "<pre>";
for ($i = 8294; $i < 8350 && $i < count($linhas); $i++) {
    $num = $i + 1;
    $linha = htmlspecialchars($linhas[$i]);
    echo "$num: $linha\n";
}
echo "</pre>";
