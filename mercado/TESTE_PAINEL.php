<?php
/**
 * TESTE DIRETO DO PAINEL - Executa e mostra erro
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🧪 TESTE DIRETO DO PAINEL</h1>";
echo "<style>body{font-family:Arial;background:#111;color:#eee;padding:20px}.erro{color:#f55}.ok{color:#5f5}pre{background:#222;padding:15px;border-radius:8px}</style>";

// Limpar cache de arquivos
clearstatcache();

// Verificar data de modificação dos arquivos
echo "<h2>1. DATA DOS ARQUIVOS</h2><pre>";
$files = [
    __DIR__ . '/painel/config.php',
    __DIR__ . '/painel/index.php'
];
foreach ($files as $f) {
    if (file_exists($f)) {
        echo basename($f) . ": " . date('d/m/Y H:i:s', filemtime($f)) . " (" . filesize($f) . " bytes)\n";
    }
}
echo "</pre>";

// Tentar incluir o painel
echo "<h2>2. EXECUTANDO PAINEL</h2><pre>";

// Capturar qualquer output/erro
ob_start();

try {
    // Simular request
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    
    // Incluir o arquivo
    $painelPath = __DIR__ . '/painel/index.php';
    
    echo "Incluindo: $painelPath\n";
    echo "Tamanho: " . filesize($painelPath) . " bytes\n\n";
    
    // Executar
    include $painelPath;
    
    echo "\n<span class='ok'>✅ Executou sem erro fatal!</span>\n";
    
} catch (Throwable $e) {
    echo "<span class='erro'>❌ ERRO: " . $e->getMessage() . "</span>\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
    echo "\nStack:\n" . $e->getTraceAsString() . "\n";
}

$output = ob_get_clean();

// Mostrar apenas início do output (para não poluir)
if (strlen($output) > 500) {
    echo htmlspecialchars(substr($output, 0, 500)) . "\n\n... (truncado, total: " . strlen($output) . " bytes)";
} else {
    echo htmlspecialchars($output);
}

echo "</pre>";

// Ver error_log ATUALIZADO
echo "<h2>3. ERROR_LOG ATUALIZADO</h2><pre>";
$log = __DIR__ . '/painel/error_log';
if (file_exists($log)) {
    // Pegar só erros dos últimos 5 minutos
    $lines = file($log);
    $now = time();
    
    echo "Erros recentes (últimos 5 min):\n\n";
    
    $found = false;
    foreach (array_reverse($lines) as $line) {
        // Extrair timestamp
        if (preg_match('/\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})/', $line, $m)) {
            $logTime = strtotime($m[1]);
            if ($now - $logTime < 300) { // 5 minutos
                echo "<span class='erro'>" . htmlspecialchars(trim($line)) . "</span>\n";
                $found = true;
            }
        }
    }
    
    if (!$found) {
        echo "<span class='ok'>✅ Nenhum erro nos últimos 5 minutos!</span>\n";
    }
} else {
    echo "Arquivo error_log não existe\n";
}
echo "</pre>";

// Sugestões
echo "<h2>4. AÇÕES</h2>";
echo "<ul>";
echo "<li><a href='/mercado/painel/' style='color:#5af' target='_blank'>🔗 Abrir Painel em nova aba</a></li>";
echo "<li><a href='/mercado/painel/?nocache=" . time() . "' style='color:#5af' target='_blank'>🔗 Abrir Painel (sem cache)</a></li>";
echo "<li><a href='/mercado/admin/' style='color:#5af' target='_blank'>🔗 Abrir Admin</a></li>";
echo "</ul>";

echo "<p>Se ainda der erro 500, limpe o cache do navegador (Ctrl+Shift+R)</p>";

echo "<hr><p>Teste em: " . date('d/m/Y H:i:s') . "</p>";
