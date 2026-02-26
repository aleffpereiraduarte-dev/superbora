<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$path = __DIR__ . '/includes/theme.php';
$lines = file($path);

echo "<pre style='background:#000;color:#0f0;padding:20px;font-size:13px;'>";
echo "🔍 LINHA 729 E CONTEXTO\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "O erro diz: 'unexpected token \".\" in line 729'\n\n";

echo "LINHAS 720-740:\n";
echo "───────────────────────────────────────────────────────────────\n";

for ($i = 719; $i < min(740, count($lines)); $i++) {
    $num = $i + 1;
    $marker = ($num == 729) ? ">>> " : "    ";
    $color = ($num == 729) ? "\033[31m" : "";
    echo sprintf("%s%4d: %s", $marker, $num, $lines[$i]);
}

echo "\n\n";

// Mostrar linha 729 em detalhes
echo "LINHA 729 EM DETALHES:\n";
echo "───────────────────────────────────────────────────────────────\n";
$line729 = $lines[728];
echo "Conteúdo: " . $line729 . "\n";
echo "HEX: ";
for ($i = 0; $i < min(50, strlen($line729)); $i++) {
    echo sprintf("%02X ", ord($line729[$i]));
}
echo "\n\n";

// Verificar se o problema é o ponto
if (strpos($line729, '.') !== false) {
    echo "⚠️ A linha contém ponto(s) na posição: ";
    $pos = 0;
    while (($pos = strpos($line729, '.', $pos)) !== false) {
        echo "$pos ";
        $pos++;
    }
    echo "\n";
}

echo "</pre>";

// Agora vamos tentar corrigir
echo "<h2 style='color:#0f0;font-family:monospace;'>🔧 CORREÇÃO</h2>";

// Fazer backup
copy($path, $path . '.bak2');

$content = file_get_contents($path);

// Problema 1: Trocar o marcador heredoc de CSS para THEMESTYLE
$content = str_replace("<<<'CSS'", "<<<'THEMESTYLE'", $content);
$content = preg_replace('/^CSS;$/m', 'THEMESTYLE;', $content);

// Salvar
file_put_contents($path, $content);

// Recarregar para ver linha 729
$lines = file($path);

echo "<pre style='background:#000;color:#0f0;padding:20px;'>";
echo "Após trocar heredoc, verificando linha 729 novamente:\n\n";

for ($i = 725; $i < min(735, count($lines)); $i++) {
    $num = $i + 1;
    $marker = ($num == 729) ? ">>> " : "    ";
    echo sprintf("%s%4d: %s", $marker, $num, $lines[$i]);
}

// Testar sintaxe
echo "\n\nTestando sintaxe...\n";
exec("php -d display_errors=1 -l " . escapeshellarg($path) . " 2>&1", $output, $return);
echo implode("\n", $output) . "\n";

if ($return === 0) {
    echo "\n✅ SINTAXE OK! Problema resolvido!\n";
} else {
    echo "\n❌ Ainda com erro. Vamos analisar a linha 729...\n";
    
    // A linha 729 está dentro do echo do pageEnd()
    // O problema pode ser concatenação de string com ponto
    
    // Vamos ver o contexto maior
    echo "\nAnalisando estrutura da função pageEnd()...\n";
    
    // Encontrar início da função pageEnd
    $pageEndStart = 0;
    foreach ($lines as $i => $line) {
        if (strpos($line, 'function pageEnd()') !== false) {
            $pageEndStart = $i;
            break;
        }
    }
    
    echo "pageEnd() começa na linha " . ($pageEndStart + 1) . "\n";
    echo "\nLinhas " . ($pageEndStart + 1) . "-" . ($pageEndStart + 10) . ":\n";
    for ($i = $pageEndStart; $i < min($pageEndStart + 10, count($lines)); $i++) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}

echo "</pre>";
