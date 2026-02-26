<?php
$path = __DIR__ . '/includes/theme.php';
$lines = file($path);

echo "<pre style='background:#000;color:#0f0;padding:20px;'>";

// Linha 202
$line = $lines[201];
echo "LINHA 202:\n";
echo "Comprimento: " . strlen($line) . " caracteres\n";
echo "Conteúdo bruto: [" . $line . "]\n";
echo "Trim: [" . trim($line) . "]\n\n";

echo "Cada caractere em HEX:\n";
for ($i = 0; $i < strlen($line); $i++) {
    $char = $line[$i];
    $hex = sprintf("%02X", ord($char));
    $display = ($char === "\n" || $char === "\r") ? "\\n" : $char;
    echo "  [$i] = '$display' (0x$hex)\n";
}

// Verificar se começa com espaço
if ($line[0] === ' ' || $line[0] === "\t") {
    echo "\n❌ PROBLEMA ENCONTRADO!\n";
    echo "A linha 202 começa com espaço/tab!\n";
    echo "Heredoc não pode ter espaços antes do fechamento.\n";
} else {
    echo "\n✅ Linha 202 começa corretamente (sem espaços)\n";
}

// Verificar linha 87 também
echo "\n\nLINHA 87 (início heredoc):\n";
$line87 = $lines[86];
echo "Conteúdo: [" . trim($line87) . "]\n";

// Ver se PHP versão suporta heredoc indentado
echo "\n\nVersão PHP: " . phpversion() . "\n";
echo "PHP 7.3+ suporta heredoc indentado\n";

echo "</pre>";
