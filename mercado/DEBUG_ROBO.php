<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<html><head><style>body{font-family:monospace;background:#1e293b;color:#e2e8f0;padding:20px;} pre{background:#0f172a;padding:15px;border-radius:8px;overflow-x:auto;}</style></head><body>";
echo "<h1>Debug ROBO_COMPLETO</h1>";

$file = __DIR__ . '/ROBO_COMPLETO.php';

if (!file_exists($file)) {
    echo "<p style='color:#ef4444;'>Arquivo nao encontrado!</p>";
    exit;
}

echo "<p>Arquivo: " . basename($file) . " (" . filesize($file) . " bytes)</p>";

// Ler conteudo e verificar problemas basicos
$content = file_get_contents($file);

// Verificar se começa com <?php
if (substr(trim($content), 0, 5) !== '<?php') {
    echo "<p style='color:#ef4444;'>ERRO: Arquivo nao comeca com &lt;?php</p>";
}

// Contar tags PHP
$opens = substr_count($content, '<?php');
$closes = substr_count($content, '?>');
echo "<p>Tags &lt;?php: {$opens} | Tags ?&gt;: {$closes}</p>";

// Verificar balanceamento de chaves
$braces_open = substr_count($content, '{');
$braces_close = substr_count($content, '}');
echo "<p>Chaves abertas: {$braces_open} | Chaves fechadas: {$braces_close}</p>";
if ($braces_open !== $braces_close) {
    echo "<p style='color:#ef4444;'>PROBLEMA: Chaves desbalanceadas!</p>";
}

// Verificar parenteses
$paren_open = substr_count($content, '(');
$paren_close = substr_count($content, ')');
echo "<p>Parenteses abertos: {$paren_open} | Parenteses fechados: {$paren_close}</p>";
if ($paren_open !== $paren_close) {
    echo "<p style='color:#ef4444;'>PROBLEMA: Parenteses desbalanceados!</p>";
}

// Tentar token_get_all para erros de sintaxe
echo "<h2>Analise de Tokens:</h2>";
try {
    $tokens = @token_get_all($content);
    $count = count($tokens);
    echo "<p style='color:#10b981;'>Tokens parseados: {$count}</p>";
    
    // Procurar por erros nos ultimos tokens
    $last_tokens = array_slice($tokens, -10);
    echo "<p>Ultimos tokens:</p><pre>";
    foreach ($last_tokens as $t) {
        if (is_array($t)) {
            echo token_name($t[0]) . ": " . htmlspecialchars(substr($t[1], 0, 50)) . "\n";
        } else {
            echo "LITERAL: " . htmlspecialchars($t) . "\n";
        }
    }
    echo "</pre>";
    
} catch (Throwable $e) {
    echo "<p style='color:#ef4444;'>Erro ao parsear: " . $e->getMessage() . "</p>";
}

// Mostrar linhas 780-790 (onde estava o problema antes)
echo "<h2>Linhas 778-795 (juncao P1/P2):</h2><pre>";
$lines = explode("\n", $content);
for ($i = 777; $i < min(795, count($lines)); $i++) {
    $ln = $i + 1;
    $line = htmlspecialchars($lines[$i]);
    echo sprintf("%4d: %s\n", $ln, $line);
}
echo "</pre>";

echo "<p><a href='ROBO_COMPLETO.php' style='color:#60a5fa;'>Tentar executar ROBO_COMPLETO.php</a></p>";
echo "</body></html>";
