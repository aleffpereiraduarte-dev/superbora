<?php
/**
 * 🔧 RECONSTRUIR theme.php
 * Lê o arquivo atual e recria sem caracteres problemáticos
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$path = __DIR__ . '/includes/theme.php';

echo "<pre style='background:#000;color:#0f0;padding:20px;'>";
echo "🔧 RECONSTRUINDO theme.php\n\n";

// Ler arquivo atual
$content = file_get_contents($path);
echo "Tamanho original: " . strlen($content) . " bytes\n";

// Remover BOM se existir
if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
    $content = substr($content, 3);
    echo "✅ BOM removido\n";
}

// Normalizar quebras de linha para \n
$content = str_replace("\r\n", "\n", $content);
$content = str_replace("\r", "\n", $content);
echo "✅ Quebras de linha normalizadas\n";

// Remover caracteres nulos
$content = str_replace("\x00", "", $content);

// Garantir que começa com <?php
if (substr(trim($content), 0, 5) !== '<?php') {
    $pos = strpos($content, '<?php');
    if ($pos !== false) {
        $content = substr($content, $pos);
        echo "✅ Removidos caracteres antes do <?php\n";
    }
}

// Salvar arquivo limpo
file_put_contents($path, $content);
echo "Tamanho final: " . strlen($content) . " bytes\n\n";

// Testar sintaxe
echo "Testando sintaxe...\n";
exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $return);

if ($return === 0) {
    echo "✅ SINTAXE OK!\n";
} else {
    echo "❌ Ainda tem erro:\n";
    echo implode("\n", $output) . "\n\n";
    
    // Tentar identificar o problema - procurar o emoji que pode estar causando problema
    echo "Verificando emojis...\n";
    
    // O emoji 🎨 no comentário pode ser problema em algumas configs
    // Vamos substituir por texto
    $content = preg_replace('/[\x{1F300}-\x{1F9FF}]/u', '', $content);
    file_put_contents($path, $content);
    
    echo "✅ Emojis removidos\n";
    
    // Testar novamente
    exec("php -l " . escapeshellarg($path) . " 2>&1", $output2, $return2);
    if ($return2 === 0) {
        echo "✅ SINTAXE OK após remover emojis!\n";
    } else {
        echo "❌ Ainda com erro: " . implode("\n", $output2) . "\n";
    }
}

echo "\n</pre>";

echo "<div style='padding:20px;'>";
echo "<a href='diagnostico_paginas.php' style='padding:15px 30px;background:#10b981;color:#000;border-radius:10px;text-decoration:none;font-weight:bold;'>🔍 Testar Páginas</a> ";
echo "<a href='login.php' style='padding:15px 30px;background:#3b82f6;color:#fff;border-radius:10px;text-decoration:none;font-weight:bold;'>🔐 Login</a>";
echo "</div>";
