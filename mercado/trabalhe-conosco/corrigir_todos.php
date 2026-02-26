<?php
/**
 * 🔧 CORRIGIR TODOS OS PROBLEMAS
 * - theme.php (erro de sintaxe)
 * - config.php (items_count)
 * - api/db.php (session isolada)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Corrigir Tudo</title>";
echo "<style>
body { font-family: -apple-system, sans-serif; background: #0f172a; color: #fff; padding: 30px; }
h1 { color: #10b981; }
.result { background: #1e293b; padding: 15px; margin: 10px 0; border-radius: 10px; }
.ok { color: #10b981; border-left: 4px solid #10b981; padding-left: 15px; }
.error { color: #ef4444; border-left: 4px solid #ef4444; padding-left: 15px; }
.warn { color: #f59e0b; border-left: 4px solid #f59e0b; padding-left: 15px; }
.btn { display: inline-block; padding: 15px 30px; background: #10b981; color: #000; text-decoration: none; border-radius: 10px; font-weight: bold; margin: 10px 5px; }
pre { background: #0f172a; padding: 10px; border-radius: 5px; font-size: 12px; overflow-x: auto; }
</style></head><body>";

echo "<h1>🔧 Corrigindo Todos os Problemas</h1>";

$results = [];
$baseDir = __DIR__;

// ═══════════════════════════════════════════════════════════════════════════════
// 1. CORRIGIR theme.php
// ═══════════════════════════════════════════════════════════════════════════════
echo "<h2>1. Corrigindo includes/theme.php</h2>";

$themePath = $baseDir . '/includes/theme.php';
if (file_exists($themePath)) {
    $content = file_get_contents($themePath);
    $modified = false;
    
    // Problema: linha com querySelector e aspas dentro de string PHP
    // const header = document.querySelector('.header, .site-header, [class*="header-main"]');
    
    // Encontrar e substituir a linha problemática
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        // Procurar a linha com querySelector e [class*=
        if (strpos($line, 'document.querySelector') !== false && strpos($line, '[class*=') !== false) {
            // Substituir por versão simples sem o seletor problemático
            $lines[$i] = "    const header = document.querySelector('.header, .site-header, .header-main');";
            $modified = true;
            echo "<div class='result ok'>✅ Linha " . ($i + 1) . " corrigida</div>";
            break;
        }
    }
    
    if ($modified) {
        $content = implode("\n", $lines);
        file_put_contents($themePath, $content);
        
        // Verificar sintaxe
        exec("php -l " . escapeshellarg($themePath) . " 2>&1", $output, $return);
        if ($return === 0) {
            echo "<div class='result ok'>✅ theme.php - Sintaxe OK após correção</div>";
        } else {
            echo "<div class='result error'>❌ theme.php ainda com erro: " . implode("<br>", $output) . "</div>";
        }
    } else {
        echo "<div class='result warn'>⚠️ Padrão não encontrado - tentando método alternativo...</div>";
        
        // Método alternativo: substituir toda a função pageEnd
        $oldPattern = "const header = document.querySelector('.header, .site-header, [class*=\"header-main\"]');";
        $newPattern = "const header = document.querySelector('.header, .site-header, .header-main');";
        
        if (strpos($content, $oldPattern) !== false) {
            $content = str_replace($oldPattern, $newPattern, $content);
            file_put_contents($themePath, $content);
            echo "<div class='result ok'>✅ theme.php corrigido (método 2)</div>";
        } else {
            // Método 3: Usar regex
            $content = file_get_contents($themePath);
            $pattern = "/const header = document\.querySelector\(['\"]\.header.*?\[class\*=.*?\].*?['\"]\);/s";
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "const header = document.querySelector('.header, .site-header, .header-main');", $content);
                file_put_contents($themePath, $content);
                echo "<div class='result ok'>✅ theme.php corrigido (regex)</div>";
            } else {
                echo "<div class='result error'>❌ Não foi possível corrigir automaticamente</div>";
            }
        }
        
        // Verificar novamente
        exec("php -l " . escapeshellarg($themePath) . " 2>&1", $output2, $return2);
        if ($return2 === 0) {
            echo "<div class='result ok'>✅ Sintaxe final: OK</div>";
        } else {
            echo "<div class='result error'>❌ Sintaxe final: " . implode("<br>", $output2) . "</div>";
        }
    }
} else {
    echo "<div class='result error'>❌ theme.php não encontrado</div>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2. CORRIGIR config.php - remover items_count
// ═══════════════════════════════════════════════════════════════════════════════
echo "<h2>2. Corrigindo config.php</h2>";

$configPath = $baseDir . '/config.php';
if (file_exists($configPath)) {
    $content = file_get_contents($configPath);
    $modified = false;
    
    // Remover items_count das queries
    $patterns = [
        'o.items_count, ' => '',
        ', o.items_count' => '',
        'o.items_count,' => '',
        ',o.items_count' => '',
    ];
    
    foreach ($patterns as $old => $new) {
        if (strpos($content, $old) !== false) {
            $content = str_replace($old, $new, $content);
            $modified = true;
        }
    }
    
    if ($modified) {
        file_put_contents($configPath, $content);
        echo "<div class='result ok'>✅ config.php - Removido items_count</div>";
    } else {
        echo "<div class='result ok'>✓ config.php já estava sem items_count</div>";
    }
    
    // Verificar sintaxe
    exec("php -l " . escapeshellarg($configPath) . " 2>&1", $output, $return);
    if ($return === 0) {
        echo "<div class='result ok'>✅ config.php - Sintaxe OK</div>";
    } else {
        echo "<div class='result error'>❌ " . implode("<br>", $output) . "</div>";
    }
} else {
    echo "<div class='result error'>❌ config.php não encontrado</div>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// 3. CORRIGIR api/db.php - sessão isolada para workers
// ═══════════════════════════════════════════════════════════════════════════════
echo "<h2>3. Corrigindo api/db.php (sessão isolada)</h2>";

$dbPath = $baseDir . '/api/db.php';
if (file_exists($dbPath)) {
    $content = file_get_contents($dbPath);
    
    // Verificar se já tem session_name
    if (strpos($content, "session_name('WORKER_SESSID')") !== false) {
        echo "<div class='result ok'>✓ api/db.php já tem sessão isolada</div>";
    } else {
        // Substituir session_start() por versão com session_name
        $oldAuth = "function requireAuth() {\n    session_start();";
        $newAuth = "function requireAuth() {\n    if (session_status() === PHP_SESSION_NONE) {\n        session_name('WORKER_SESSID');\n        session_start();\n    }";
        
        if (strpos($content, $oldAuth) !== false) {
            $content = str_replace($oldAuth, $newAuth, $content);
            file_put_contents($dbPath, $content);
            echo "<div class='result ok'>✅ api/db.php - Sessão isolada adicionada</div>";
        } else {
            // Tentar outro padrão
            $content = preg_replace(
                '/function requireAuth\(\)\s*\{\s*session_start\(\);/',
                "function requireAuth() {\n    if (session_status() === PHP_SESSION_NONE) {\n        session_name('WORKER_SESSID');\n        session_start();\n    }",
                $content
            );
            file_put_contents($dbPath, $content);
            echo "<div class='result ok'>✅ api/db.php - Sessão isolada (regex)</div>";
        }
    }
    
    // Verificar sintaxe
    exec("php -l " . escapeshellarg($dbPath) . " 2>&1", $output, $return);
    if ($return === 0) {
        echo "<div class='result ok'>✅ api/db.php - Sintaxe OK</div>";
    } else {
        echo "<div class='result error'>❌ " . implode("<br>", $output) . "</div>";
    }
} else {
    echo "<div class='result error'>❌ api/db.php não encontrado</div>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// 4. VERIFICAR ARQUIVOS PRINCIPAIS
// ═══════════════════════════════════════════════════════════════════════════════
echo "<h2>4. Verificação Final</h2>";

$mainFiles = ['login.php', 'app.php', 'config.php', 'includes/theme.php', 'api/db.php'];
$allOk = true;

foreach ($mainFiles as $file) {
    $path = $baseDir . '/' . $file;
    if (file_exists($path)) {
        exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $return);
        if ($return === 0) {
            echo "<div class='result ok'>✅ $file - OK</div>";
        } else {
            echo "<div class='result error'>❌ $file - ERRO</div>";
            $allOk = false;
        }
    } else {
        echo "<div class='result warn'>⚠️ $file - não encontrado</div>";
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// RESULTADO FINAL
// ═══════════════════════════════════════════════════════════════════════════════
echo "<h2>🎯 Resultado Final</h2>";

if ($allOk) {
    echo "<div class='result ok' style='font-size:18px;padding:20px;'>
        ✅ TODOS OS ARQUIVOS PRINCIPAIS CORRIGIDOS!<br><br>
        Agora você pode testar o login.
    </div>";
} else {
    echo "<div class='result error' style='font-size:18px;padding:20px;'>
        ⚠️ Alguns arquivos ainda têm problemas. Verifique os erros acima.
    </div>";
}

echo "<div style='margin-top:30px;'>";
echo "<a href='login.php' class='btn'>🔐 Testar Login</a>";
echo "<a href='app.php' class='btn' style='background:#3b82f6;'>📱 Testar App</a>";
echo "<a href='analisar_todos.php' class='btn' style='background:#8b5cf6;'>🔍 Analisar Novamente</a>";
echo "</div>";

echo "</body></html>";
