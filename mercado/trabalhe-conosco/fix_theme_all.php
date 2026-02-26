<?php
/**
 * 🔧 CORRIGIR THEME.PHP - TODAS AS OCORRÊNCIAS
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre style='background:#0f172a;color:#10b981;padding:30px;font-family:monospace;font-size:14px;'>";
echo "🔧 CORRIGINDO THEME.PHP - TODAS AS OCORRÊNCIAS\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$themePath = __DIR__ . '/includes/theme.php';

if (!file_exists($themePath)) {
    echo "❌ Arquivo não encontrado!\n";
    exit;
}

$content = file_get_contents($themePath);
$original = $content;
$fixes = 0;

// 1. Encontrar TODAS as linhas com querySelector e [class*=
$lines = explode("\n", $content);
$modifiedLines = [];

foreach ($lines as $i => $line) {
    $lineNum = $i + 1;
    $originalLine = $line;
    
    // Procurar querySelector com [class*=
    if (strpos($line, 'querySelector') !== false && strpos($line, '[class*=') !== false) {
        echo "🔍 Linha $lineNum encontrada:\n";
        echo "   ANTES: " . trim($line) . "\n";
        
        // Substituir por versão simples
        // Pode ter vários formatos, vamos cobrir todos
        
        // Formato 1: const header = document.querySelector('.header, .site-header, [class*="header-main"]');
        // Formato 2: const header = document.querySelector(".header, .site-header, [class*='header-main']");
        
        // Substituir qualquer querySelector com [class*=...] por versão simplificada
        $line = preg_replace(
            '/document\.querySelector\s*\(\s*[\'"]([^"\']*)\[class\*=["\'][^"\']*["\']\]([^"\']*)[\'"]/',
            'document.querySelector(\'$1.header-main$2\'',
            $line
        );
        
        // Se ainda tiver problema, substituir mais agressivamente
        if (strpos($line, '[class*=') !== false) {
            // Extrair o que vem antes do [class*=
            if (preg_match('/document\.querySelector\s*\(\s*[\'"](.+?),\s*\[class\*=/', $originalLine, $matches)) {
                $selectors = $matches[1];
                $line = preg_replace(
                    '/document\.querySelector\s*\(\s*[\'"][^"\']+[\'"]/',
                    "document.querySelector('" . trim($selectors) . ", .header-main'",
                    $originalLine
                );
            } else {
                // Última tentativa - substituir tudo por seletor simples
                $line = "    const header = document.querySelector('.header, .site-header, .header-main');";
            }
        }
        
        echo "   DEPOIS: " . trim($line) . "\n\n";
        $fixes++;
    }
    
    $modifiedLines[] = $line;
}

if ($fixes > 0) {
    $content = implode("\n", $modifiedLines);
    file_put_contents($themePath, $content);
    echo "✅ $fixes ocorrência(s) corrigida(s)\n\n";
} else {
    echo "⚠️ Nenhuma ocorrência de [class*= encontrada\n\n";
}

// 2. Verificar sintaxe após correção
echo "═══════════════════════════════════════════════════════════════\n";
echo "🔍 VERIFICANDO SINTAXE:\n";
echo "───────────────────────────────────────────────────────────────\n";

$output = [];
exec("php -l " . escapeshellarg($themePath) . " 2>&1", $output, $return);

if ($return === 0) {
    echo "✅ SINTAXE OK! Arquivo corrigido com sucesso.\n";
} else {
    echo "❌ Ainda há erros:\n";
    foreach ($output as $line) {
        echo "   $line\n";
    }
    
    // Tentar identificar a linha do erro
    preg_match('/line (\d+)/', implode(' ', $output), $matches);
    if (!empty($matches[1])) {
        $errorLine = (int)$matches[1];
        echo "\n📍 Contexto da linha $errorLine:\n";
        
        $lines = file($themePath);
        $start = max(0, $errorLine - 3);
        $end = min(count($lines), $errorLine + 3);
        
        for ($i = $start; $i < $end; $i++) {
            $num = $i + 1;
            $marker = ($num == $errorLine) ? ">>>" : "   ";
            echo "$marker $num: " . rtrim($lines[$i]) . "\n";
        }
    }
}

echo "\n</pre>";

echo "<div style='padding:20px;font-family:sans-serif;'>";
if ($return === 0) {
    echo "<p style='color:#10b981;font-size:18px;'>✅ <strong>theme.php corrigido!</strong></p>";
    echo "<a href='login.php' style='padding:15px 30px;background:#10b981;color:#000;border-radius:10px;text-decoration:none;font-weight:bold;margin:5px;display:inline-block;'>🔐 Testar Login</a>";
    echo "<a href='app.php' style='padding:15px 30px;background:#3b82f6;color:#fff;border-radius:10px;text-decoration:none;font-weight:bold;margin:5px;display:inline-block;'>📱 Testar App</a>";
} else {
    echo "<p style='color:#f59e0b;'>⚠️ Ainda há problemas. Envie o conteúdo do erro para análise.</p>";
    echo "<a href='debug_theme.php' style='padding:15px 30px;background:#f59e0b;color:#000;border-radius:10px;text-decoration:none;font-weight:bold;margin:5px;display:inline-block;'>🔍 Diagnóstico Detalhado</a>";
}
echo "</div>";
