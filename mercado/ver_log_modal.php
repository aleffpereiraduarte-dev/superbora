<?php
/**
 * VER LOG DO MODAL
 */

header('Content-Type: text/html; charset=utf-8');

echo "<html><head><meta charset='UTF-8'><title>Log Modal</title>";
echo "<style>
body { font-family: monospace; background: #1a1a2e; color: #0f0; padding: 20px; }
h1 { color: #00d4aa; }
pre { background: #0a0a15; padding: 15px; border-radius: 8px; max-height: 500px; overflow: auto; }
</style></head><body>";

echo "<h1>📋 Log do Modal</h1>";

$logPath = __DIR__ . '/debug_modal.log';

if (file_exists($logPath)) {
    $content = file_get_contents($logPath);
    $lines = explode("\n", $content);
    
    // Últimas 30 linhas
    $ultimas = array_slice($lines, -30);
    
    echo "<h2>Últimas 30 entradas:</h2>";
    echo "<pre>";
    foreach ($ultimas as $linha) {
        if (empty(trim($linha))) continue;
        
        // Destacar se tem mercado ou não
        if (strpos($linha, '"100"') !== false || strpos($linha, ':100') !== false) {
            echo "<span style='color:#00d4aa'>$linha</span>\n";
        } elseif (strpos($linha, 'NÃO DEFINIDO') !== false || strpos($linha, 'null') !== false) {
            echo "<span style='color:#ff6b6b'>$linha</span>\n";
        } else {
            echo "$linha\n";
        }
    }
    echo "</pre>";
    
    echo "<p><a href='?limpar=1' style='color:#ff6b6b'>🗑️ Limpar log</a></p>";
    
    if (isset($_GET['limpar'])) {
        file_put_contents($logPath, '');
        echo "<p style='color:#00d4aa'>✅ Log limpo!</p>";
    }
} else {
    echo "<p style='color:#ffc107'>⚠️ Log não existe ainda. Acesse /mercado/ primeiro.</p>";
}

echo "<hr style='border-color:#333'>";
echo "<h2>🔍 Agora teste:</h2>";
echo "<ol>";
echo "<li>Limpe o log (clique acima)</li>";
echo "<li>Abra <a href='/mercado/' target='_blank' style='color:#00d4aa'>/mercado/</a> em nova aba</li>";
echo "<li>Volte aqui e recarregue pra ver o log</li>";
echo "</ol>";

echo "</body></html>";
