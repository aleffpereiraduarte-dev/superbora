<?php
/**
 * 🔍 ANÁLISE COMPLETA DE TODOS OS ARQUIVOS PHP
 * Verifica sintaxe, dependências e problemas
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(120);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Análise Completa</title>";
echo "<style>
body { font-family: -apple-system, sans-serif; background: #0f172a; color: #fff; padding: 20px; margin: 0; }
h1 { color: #10b981; }
h2 { color: #3b82f6; border-bottom: 1px solid #334155; padding-bottom: 10px; margin-top: 30px; }
.ok { color: #10b981; }
.error { color: #ef4444; }
.warn { color: #f59e0b; }
.file { background: #1e293b; padding: 15px; margin: 10px 0; border-radius: 10px; border-left: 4px solid #334155; }
.file.has-error { border-left-color: #ef4444; }
.file.has-warn { border-left-color: #f59e0b; }
.file.is-ok { border-left-color: #10b981; }
.filename { font-weight: bold; font-size: 16px; margin-bottom: 8px; }
.detail { font-size: 13px; color: #94a3b8; margin: 4px 0; padding-left: 20px; }
.summary { background: #1e293b; padding: 20px; border-radius: 10px; margin: 20px 0; }
.btn { display: inline-block; padding: 12px 24px; background: #10b981; color: #000; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 5px; }
.btn.fix { background: #f59e0b; }
pre { background: #0f172a; padding: 10px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
</style></head><body>";

echo "<h1>🔍 Análise Completa - Trabalhe Conosco</h1>";

$baseDir = __DIR__;
$allFiles = [];
$errors = [];
$warnings = [];
$okFiles = [];

// Coletar todos os arquivos PHP
function scanPhpFiles($dir, &$files, $base = '') {
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        $relative = $base ? $base . '/' . $item : $item;
        
        if (is_dir($path)) {
            // Ignorar algumas pastas
            if (!in_array($item, ['vendor', 'node_modules', 'uploads', 'logs', '.git'])) {
                scanPhpFiles($path, $files, $relative);
            }
        } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'php') {
            $files[$relative] = $path;
        }
    }
}

scanPhpFiles($baseDir, $allFiles);

echo "<div class='summary'>";
echo "<strong>📁 Total de arquivos PHP encontrados:</strong> " . count($allFiles);
echo "</div>";

// Analisar cada arquivo
$fileResults = [];

foreach ($allFiles as $relative => $fullPath) {
    $result = [
        'file' => $relative,
        'path' => $fullPath,
        'syntax_ok' => true,
        'syntax_error' => '',
        'issues' => [],
        'warnings' => []
    ];
    
    // 1. Verificar sintaxe
    $output = [];
    $return = 0;
    exec("php -l " . escapeshellarg($fullPath) . " 2>&1", $output, $return);
    
    if ($return !== 0) {
        $result['syntax_ok'] = false;
        $result['syntax_error'] = implode("\n", $output);
        $errors[] = $relative;
    }
    
    // 2. Analisar conteúdo
    $content = file_get_contents($fullPath);
    
    // Verificar problemas comuns
    
    // 2.1 Session antes de output
    if (preg_match('/^<\?php\s*\n.*?session_start/s', $content) && 
        preg_match('/echo|print|html|<!DOCTYPE/i', substr($content, 0, strpos($content, 'session_start')))) {
        $result['warnings'][] = "session_start() pode ter output antes";
    }
    
    // 2.2 Require/include de arquivos que podem não existir
    preg_match_all('/(?:require|include)(?:_once)?\s*[\(\'"]([^\'"]+)[\'"\)]/i', $content, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $inc) {
            if (strpos($inc, '$') === false) { // Ignorar variáveis
                $incPath = dirname($fullPath) . '/' . $inc;
                if (!file_exists($incPath) && !file_exists($baseDir . '/' . $inc)) {
                    $result['warnings'][] = "Include não encontrado: $inc";
                }
            }
        }
    }
    
    // 2.3 Funções que podem não existir (do config.php)
    $configFunctions = ['getPDO', 'getMySQLi', 'getDB', 'requireWorkerLogin', 'getWorker', 
                        'syncWorkerToShopper', 'getAvailableOffers', 'getActiveOrder', 'getTodayStats',
                        'formatMoney', 'formatDate', 'jsonResponse'];
    
    foreach ($configFunctions as $func) {
        if (preg_match('/\b' . $func . '\s*\(/', $content)) {
            // Verifica se inclui config.php
            if (strpos($content, 'config.php') === false && $relative !== 'config.php') {
                $result['warnings'][] = "Usa $func() mas pode não incluir config.php";
                break;
            }
        }
    }
    
    // 2.4 Aspas problemáticas em strings (comum em JS dentro de PHP)
    if (preg_match('/echo\s*[\'"].*?\[class\*=["\'].*?["\']\].*?[\'"];/s', $content)) {
        $result['issues'][] = "Possível problema de aspas em seletor CSS/JS";
    }
    
    // 2.5 Verificar se usa tabelas/colunas que podem não existir
    $problematicColumns = ['items_count', 'o.items_count'];
    foreach ($problematicColumns as $col) {
        if (strpos($content, $col) !== false) {
            $result['warnings'][] = "Usa coluna '$col' que pode não existir";
        }
    }
    
    // 2.6 Verificar constantes de banco
    if (preg_match('/\b(DB_HOST|DB_NAME|DB_USER|DB_PASS|DB_HOSTNAME|DB_DATABASE|DB_USERNAME|DB_PASSWORD)\b/', $content)) {
        if (strpos($content, 'config.php') === false && strpos($content, 'define(') === false && $relative !== 'config.php') {
            $result['warnings'][] = "Usa constantes DB_* mas pode não definir/incluir";
        }
    }
    
    if (!empty($result['warnings'])) {
        $warnings[] = $relative;
    }
    
    if ($result['syntax_ok'] && empty($result['issues']) && empty($result['warnings'])) {
        $okFiles[] = $relative;
    }
    
    $fileResults[] = $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXIBIR RESULTADOS
// ═══════════════════════════════════════════════════════════════════════════════

// Resumo
echo "<div class='summary'>";
echo "<h2>📊 Resumo</h2>";
echo "<p class='ok'>✅ Arquivos OK: " . count($okFiles) . "</p>";
echo "<p class='warn'>⚠️ Arquivos com avisos: " . count($warnings) . "</p>";
echo "<p class='error'>❌ Arquivos com ERRO de sintaxe: " . count($errors) . "</p>";
echo "</div>";

// Erros de sintaxe (CRÍTICOS)
if (!empty($errors)) {
    echo "<h2 class='error'>❌ ERROS DE SINTAXE (CRÍTICOS)</h2>";
    foreach ($fileResults as $r) {
        if (!$r['syntax_ok']) {
            echo "<div class='file has-error'>";
            echo "<div class='filename'>{$r['file']}</div>";
            echo "<pre class='error'>" . htmlspecialchars($r['syntax_error']) . "</pre>";
            echo "</div>";
        }
    }
}

// Arquivos com issues/warnings
$hasIssues = array_filter($fileResults, function($r) {
    return !empty($r['issues']) || !empty($r['warnings']);
});

if (!empty($hasIssues)) {
    echo "<h2 class='warn'>⚠️ Arquivos com Avisos</h2>";
    foreach ($hasIssues as $r) {
        if ($r['syntax_ok'] && (!empty($r['issues']) || !empty($r['warnings']))) {
            echo "<div class='file has-warn'>";
            echo "<div class='filename'>{$r['file']}</div>";
            foreach ($r['issues'] as $issue) {
                echo "<div class='detail error'>🔴 $issue</div>";
            }
            foreach ($r['warnings'] as $warn) {
                echo "<div class='detail warn'>🟡 $warn</div>";
            }
            echo "</div>";
        }
    }
}

// Lista de OK
echo "<h2 class='ok'>✅ Arquivos OK (" . count($okFiles) . ")</h2>";
echo "<div class='file is-ok'>";
foreach ($okFiles as $f) {
    echo "<div class='detail ok'>✓ $f</div>";
}
echo "</div>";

// Botão para corrigir
echo "<div style='margin-top:30px;'>";
echo "<a href='corrigir_todos.php' class='btn fix'>🔧 CORRIGIR TODOS OS ERROS</a>";
echo "<a href='login.php' class='btn'>🔐 Tentar Login</a>";
echo "</div>";

echo "</body></html>";
