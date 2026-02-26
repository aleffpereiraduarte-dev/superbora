<?php
/**
 * 🔧 CORRIGIR ERROS - theme.php e config.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre style='background:#0f172a;color:#fff;padding:30px;font-family:monospace;'>";
echo "🔧 CORRIGINDO ERROS\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$results = [];

// ═══════════════════════════════════════════════════════════════════════════════
// 1. CORRIGIR theme.php - Linha 729
// ═══════════════════════════════════════════════════════════════════════════════
$themePath = __DIR__ . '/includes/theme.php';

if (file_exists($themePath)) {
    $content = file_get_contents($themePath);
    $original = $content;
    
    // O problema: aspas duplas dentro de echo com aspas simples
    // const header = document.querySelector('.header, .site-header, [class*="header-main"]');
    // Precisa escapar ou trocar
    
    // Solução: trocar a linha problemática
    $oldLine = "const header = document.querySelector('.header, .site-header, [class*=\"header-main\"]');";
    $newLine = "const header = document.querySelector('.header, .site-header');";
    
    if (strpos($content, $oldLine) !== false) {
        $content = str_replace($oldLine, $newLine, $content);
        file_put_contents($themePath, $content);
        $results[] = "✅ theme.php corrigido (linha querySelector)";
    } else {
        // Tentar outra variação
        $patterns = [
            '/const header = document\.querySelector\([\'"][^"\']*\[class\*=["\'][^"\']*["\']\][^"\']*["\']\);/',
            '/const header = document\.querySelector\(.*header-main.*\);/s'
        ];
        
        $fixed = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "const header = document.querySelector('.header, .site-header');", $content);
                file_put_contents($themePath, $content);
                $results[] = "✅ theme.php corrigido (padrão regex)";
                $fixed = true;
                break;
            }
        }
        
        if (!$fixed) {
            // Método mais direto - procurar a função pageEnd e corrigir
            $search = "const header = document.querySelector";
            if (strpos($content, $search) !== false) {
                // Encontrar a linha completa e substituir
                $lines = explode("\n", $content);
                foreach ($lines as $i => $line) {
                    if (strpos($line, "const header = document.querySelector") !== false) {
                        $lines[$i] = "    const header = document.querySelector('.header, .site-header');";
                        $content = implode("\n", $lines);
                        file_put_contents($themePath, $content);
                        $results[] = "✅ theme.php corrigido (linha " . ($i+1) . ")";
                        $fixed = true;
                        break;
                    }
                }
            }
            
            if (!$fixed) {
                $results[] = "⚠️ theme.php: padrão não encontrado para correção automática";
            }
        }
    }
    
    // Verificar sintaxe após correção
    exec("php -l " . escapeshellarg($themePath) . " 2>&1", $output, $return);
    if ($return === 0) {
        $results[] = "✅ theme.php: sintaxe OK após correção";
    } else {
        $results[] = "❌ theme.php: ainda tem erro - " . implode(" ", $output);
    }
    
} else {
    $results[] = "❌ theme.php não encontrado";
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2. CORRIGIR config.php - Função getAvailableOffers (items_count não existe)
// ═══════════════════════════════════════════════════════════════════════════════
$configPath = __DIR__ . '/config.php';

if (file_exists($configPath)) {
    $content = file_get_contents($configPath);
    
    // Remover o.items_count da query
    $oldQuery = "o.order_number, o.total, o.items_count, o.shipping_address,";
    $newQuery = "o.order_number, o.total, o.shipping_address,";
    
    if (strpos($content, $oldQuery) !== false) {
        $content = str_replace($oldQuery, $newQuery, $content);
        file_put_contents($configPath, $content);
        $results[] = "✅ config.php corrigido (removido items_count)";
    } else {
        // Verificar se já está sem items_count
        if (strpos($content, 'items_count') === false) {
            $results[] = "✓ config.php já estava sem items_count";
        } else {
            $results[] = "⚠️ config.php: items_count encontrado mas em formato diferente";
        }
    }
    
    // Verificar sintaxe
    exec("php -l " . escapeshellarg($configPath) . " 2>&1", $output2, $return2);
    if ($return2 === 0) {
        $results[] = "✅ config.php: sintaxe OK";
    } else {
        $results[] = "❌ config.php: erro - " . implode(" ", $output2);
    }
    
} else {
    $results[] = "❌ config.php não encontrado";
}

// ═══════════════════════════════════════════════════════════════════════════════
// MOSTRAR RESULTADOS
// ═══════════════════════════════════════════════════════════════════════════════
echo "📋 RESULTADOS:\n";
echo "───────────────────────────────────────────────────────────────\n";
foreach ($results as $r) {
    echo "$r\n";
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "🧪 TESTANDO APP.PHP:\n";
echo "═══════════════════════════════════════════════════════════════\n";

// Verificar sintaxe do app.php
exec("php -l " . escapeshellarg(__DIR__ . '/app.php') . " 2>&1", $appOutput, $appReturn);
if ($appReturn === 0) {
    echo "✅ app.php: sintaxe OK\n";
} else {
    echo "❌ app.php: " . implode(" ", $appOutput) . "\n";
}

echo "\n</pre>";

echo "<div style='padding:20px;font-family:sans-serif;'>";
echo "<a href='login.php' style='padding:15px 30px;background:#10b981;color:#fff;border-radius:10px;text-decoration:none;margin:5px;display:inline-block;font-weight:bold;'>🔐 Ir para Login</a>";
echo "<a href='app.php' style='padding:15px 30px;background:#3b82f6;color:#fff;border-radius:10px;text-decoration:none;margin:5px;display:inline-block;font-weight:bold;'>📱 Ir para App</a>";
echo "</div>";
