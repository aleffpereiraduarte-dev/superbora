<?php
$path = __DIR__ . '/includes/theme.php';
$content = file_get_contents($path);
$lines = explode("\n", $content);

echo "<pre style='background:#000;color:#0f0;padding:20px;font-size:12px;'>";

// Ver linha 202 em hex para verificar caracteres ocultos
echo "LINHA 202 (fim do heredoc) em HEX:\n";
echo "================\n";
$line202 = $lines[201];
echo "Texto: [" . $line202 . "]\n";
echo "Hex: ";
for ($i = 0; $i < strlen($line202); $i++) {
    echo sprintf("%02X ", ord($line202[$i]));
}
echo "\n\n";

// Verificar se CSS; está correto
echo "Verificando fechamento heredoc:\n";
if (trim($line202) === 'CSS;') {
    echo "✅ Linha 202 é 'CSS;' corretamente\n";
} else {
    echo "❌ Linha 202 NÃO é 'CSS;' - é: [" . trim($line202) . "]\n";
}

// Ver linhas 85-90
echo "\nLINHAS 85-92:\n";
echo "================\n";
for ($i = 84; $i < 92; $i++) {
    $hex = '';
    for ($j = 0; $j < min(20, strlen($lines[$i])); $j++) {
        $hex .= sprintf("%02X ", ord($lines[$i][$j]));
    }
    echo ($i+1) . ": " . htmlspecialchars(substr($lines[$i], 0, 60)) . "\n";
    echo "    HEX: $hex\n";
}

// Testar se o problema é o heredoc
echo "\n\nTESTANDO CADA FUNÇÃO:\n";
echo "================\n";

// Extrair e testar getDB
preg_match('/function getDB\(\)\s*\{[^}]+\}/s', $content, $m);
$test = "<?php\n" . ($m[0] ?? '');
$tmp = tempnam(sys_get_temp_dir(), 'php');
file_put_contents($tmp, $test);
exec("php -l $tmp 2>&1", $o, $r);
echo "getDB(): " . ($r === 0 ? "✅" : "❌") . "\n";
unlink($tmp);

// Extrair e testar icon
preg_match('/function icon\(\$n\)\s*\{[\s\S]+?\n\}/m', $content, $m);
if ($m) {
    $test = "<?php\n" . $m[0];
    $tmp = tempnam(sys_get_temp_dir(), 'php');
    file_put_contents($tmp, $test);
    exec("php -l $tmp 2>&1", $o, $r);
    echo "icon(): " . ($r === 0 ? "✅" : "❌ " . implode(" ", $o)) . "\n";
    unlink($tmp);
}

echo "</pre>";
