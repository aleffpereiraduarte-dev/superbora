<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre style='background:#0f172a;color:#fff;padding:30px;font-family:monospace;'>";
echo "🔍 VERIFICANDO theme.php\n\n";

$path = __DIR__ . '/includes/theme.php';

// Tamanho do arquivo
echo "📁 Tamanho: " . filesize($path) . " bytes\n";
echo "📅 Modificado: " . date('d/m/Y H:i:s', filemtime($path)) . "\n\n";

// Verificar sintaxe
exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $return);
echo "🔧 Sintaxe: " . ($return === 0 ? "✅ OK" : "❌ ERRO") . "\n";
if ($return !== 0) {
    echo implode("\n", $output) . "\n";
}

// Mostrar linhas 764-785 (onde estava o problema)
echo "\n📄 Linhas 764-785:\n";
echo "─────────────────────────────────────────\n";
$lines = file($path);
for ($i = 763; $i < min(785, count($lines)); $i++) {
    $num = $i + 1;
    echo sprintf("%4d: %s", $num, $lines[$i]);
}

echo "</pre>";
