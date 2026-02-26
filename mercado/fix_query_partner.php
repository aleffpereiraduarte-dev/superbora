<?php
/**
 * FIX COMPLETO - QUERY PARTNER_ID NULL
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

echo "<html><head><meta charset='UTF-8'><title>Fix Query Partner</title>";
echo "<style>
body { font-family: Arial, sans-serif; background: #1a1a2e; color: #fff; padding: 20px; max-width: 1000px; margin: 0 auto; }
h1, h2 { color: #00d4aa; }
.box { background: #16213e; padding: 20px; border-radius: 10px; margin: 15px 0; }
.ok { color: #00d4aa; }
.erro { color: #ff6b6b; }
pre { background: #0f0f23; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 11px; max-height: 400px; overflow-y: auto; }
.btn { display: inline-block; padding: 15px 30px; background: #00d4aa; color: #000; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 10px 5px; }
</style></head><body>";

echo "<h1>🔧 Fix Query Partner ID</h1>";

$baseDir = __DIR__;
$indexPath = $baseDir . '/index.php';
$content = file_get_contents($indexPath);
$lines = explode("\n", $content);

$acao = $_GET['acao'] ?? 'analisar';

if ($acao == 'analisar') {
    
    echo "<div class='box'>";
    echo "<h2>🔍 Procurando onde \$where é construído...</h2>";
    
    // Procurar linhas com $where
    $whereLines = [];
    foreach ($lines as $num => $line) {
        if (strpos($line, '$where') !== false && strpos($line, 'partner_id') !== false) {
            $whereLines[$num + 1] = $line;
        }
    }
    
    echo "<p>Linhas com \$where e partner_id:</p>";
    echo "<pre>";
    foreach ($whereLines as $num => $line) {
        echo "$num: " . htmlspecialchars(trim($line)) . "\n";
    }
    echo "</pre>";
    echo "</div>";
    
    // Mostrar contexto de onde partner_id é definido
    echo "<div class='box'>";
    echo "<h2>🔍 Onde \$partner_id é definido:</h2>";
    
    echo "<pre>";
    foreach ($lines as $num => $line) {
        if (preg_match('/\$partner_id\s*=/', $line)) {
            // Mostrar contexto (5 linhas antes e depois)
            for ($i = max(0, $num - 3); $i <= min(count($lines) - 1, $num + 3); $i++) {
                $destaque = ($i == $num) ? ' style="background:#00d4aa;color:#000"' : '';
                echo "<span$destaque>" . ($i + 1) . ": " . htmlspecialchars($lines[$i]) . "</span>\n";
            }
            echo "\n---\n\n";
        }
    }
    echo "</pre>";
    echo "</div>";
    
    // Mostrar linhas 500-560 para ver query completa
    echo "<div class='box'>";
    echo "<h2>📋 Query de produtos (linhas 500-560):</h2>";
    echo "<pre>";
    for ($i = 499; $i <= 559 && $i < count($lines); $i++) {
        echo ($i + 1) . ": " . htmlspecialchars($lines[$i]) . "\n";
    }
    echo "</pre>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<p><a href='?acao=corrigir' class='btn'>🔧 APLICAR FIX AUTOMÁTICO</a></p>";
    echo "</div>";
    
} elseif ($acao == 'corrigir') {
    
    echo "<div class='box'>";
    echo "<h2>🔧 Aplicando correção...</h2>";
    
    // Backup
    $backupPath = $indexPath . '.backup_query_' . date('YmdHis');
    copy($indexPath, $backupPath);
    echo "<p class='ok'>✅ Backup: " . basename($backupPath) . "</p>";
    
    $alteracoes = 0;
    
    // FIX 1: Garantir que $partner_id nunca seja null na query
    // Procurar padrões como: $where = "WHERE pp.partner_id = $partner_id"
    // E substituir por versão segura
    
    // Padrão 1: WHERE pp.partner_id = $partner_id
    $content = preg_replace(
        '/(\$where\s*=\s*["\'])WHERE\s+pp\.partner_id\s*=\s*\$partner_id/',
        '$1WHERE pp.partner_id = " . intval($partner_id) . "',
        $content,
        -1,
        $count
    );
    if ($count > 0) {
        echo "<p class='ok'>✅ Corrigido padrão 1: $count ocorrências</p>";
        $alteracoes += $count;
    }
    
    // Padrão 2: pp.partner_id = $partner_id (em concatenação)
    $content = preg_replace(
        '/pp\.partner_id\s*=\s*\$partner_id(?!\s*\?)/',
        'pp.partner_id = " . intval($partner_id ?: 0) . "',
        $content,
        -1,
        $count
    );
    if ($count > 0) {
        echo "<p class='ok'>✅ Corrigido padrão 2: $count ocorrências</p>";
        $alteracoes += $count;
    }
    
    // Padrão 3: partner_id = {$partner_id}
    $content = preg_replace(
        '/partner_id\s*=\s*\{\$partner_id\}/',
        'partner_id = " . intval($partner_id ?: 0) . "',
        $content,
        -1,
        $count
    );
    if ($count > 0) {
        echo "<p class='ok'>✅ Corrigido padrão 3: $count ocorrências</p>";
        $alteracoes += $count;
    }
    
    // FIX 2: No início, garantir $partner_id = 0 se não existir
    // Procurar onde $partner_id é definido da sessão
    if (preg_match('/\$partner_id\s*=\s*\$_SESSION\[.market_partner_id.\]\s*\?\?\s*null\s*;/', $content)) {
        $content = preg_replace(
            '/\$partner_id\s*=\s*\$_SESSION\[.market_partner_id.\]\s*\?\?\s*null\s*;/',
            '$partner_id = $_SESSION[\'market_partner_id\'] ?? 0;',
            $content
        );
        echo "<p class='ok'>✅ Alterado: ?? null → ?? 0</p>";
        $alteracoes++;
    }
    
    // FIX 3: Se tem "?? null" em qualquer lugar relacionado a partner_id
    $content = preg_replace(
        '/\$partner_id\s*=.*\?\?\s*null/',
        '$partner_id = $_SESSION[\'market_partner_id\'] ?? 0',
        $content,
        -1,
        $count
    );
    if ($count > 0) {
        echo "<p class='ok'>✅ Corrigido ?? null → ?? 0: $count ocorrências</p>";
        $alteracoes += $count;
    }
    
    // FIX 4: Adicionar intval() em queries existentes que usam $partner_id diretamente
    // Procurar: WHERE ... = $partner_id (sem intval)
    $patterns = [
        '/= \$partner_id(?![_a-zA-Z0-9])/' => '= " . intval($partner_id ?: 0) . "',
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        $antes = $content;
        $content = preg_replace($pattern, $replacement, $content, -1, $count);
        if ($content !== $antes && $count > 0) {
            echo "<p class='ok'>✅ Protegido com intval(): $count ocorrências</p>";
            $alteracoes += $count;
        }
    }
    
    // Salvar
    file_put_contents($indexPath, $content);
    
    echo "<p><strong>Total de alterações: $alteracoes</strong></p>";
    echo "</div>";
    
    // Verificar resultado
    echo "<div class='box'>";
    echo "<h2>📋 Verificando resultado (linhas 500-560):</h2>";
    
    $newContent = file_get_contents($indexPath);
    $newLines = explode("\n", $newContent);
    
    echo "<pre>";
    for ($i = 499; $i <= 559 && $i < count($newLines); $i++) {
        echo ($i + 1) . ": " . htmlspecialchars($newLines[$i]) . "\n";
    }
    echo "</pre>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<p><a href='/mercado/' target='_blank' class='btn'>🛒 Testar Mercado</a></p>";
    echo "<p><a href='/mercado/diagnostico_erro500.php' class='btn'>🔍 Diagnóstico</a></p>";
    echo "</div>";
}

echo "</body></html>";
