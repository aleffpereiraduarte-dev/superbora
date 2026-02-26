<?php
/**
 * 📊 ANALISADOR COMPLETO ONE.PHP
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(120);

$onePath = __DIR__ . '/one.php';

if (!file_exists($onePath)) {
    die("❌ one.php não encontrado");
}

$content = file_get_contents($onePath);
$totalBytes = strlen($content);
$totalLines = substr_count($content, "\n") + 1;

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Análise ONE.php</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:monospace;background:#0a0a0a;color:#e0e0e0;padding:20px;font-size:12px;line-height:1.6}
h1{color:#fff;margin-bottom:20px}
h2{color:#10b981;margin:20px 0 10px;padding-top:15px;border-top:1px solid #333}
.box{background:#111;border:1px solid #333;border-radius:8px;padding:16px;margin-bottom:16px}
.ok{color:#10b981}.err{color:#ef4444}.warn{color:#f59e0b}.info{color:#3b82f6}
table{width:100%;border-collapse:collapse;margin:10px 0}
th,td{text-align:left;padding:6px 10px;border-bottom:1px solid #222}
th{color:#888}
.num{color:#f59e0b}
pre{background:#000;padding:10px;border-radius:4px;overflow-x:auto;font-size:11px;max-height:200px;overflow-y:auto}
.critical{background:#2a0a0a;border-color:#ef4444}
.warning{background:#2a2a0a;border-color:#f59e0b}
</style>
</head><body>";

echo "<h1>📊 ANÁLISE COMPLETA: one.php</h1>";

// ═══════════════════════════════════════════════════════════════════════════════
// 1. INFORMAÇÕES GERAIS
// ═══════════════════════════════════════════════════════════════════════════════
echo "<div class='box'>";
echo "<h2>📁 1. INFORMAÇÕES GERAIS</h2>";
echo "<table>";
echo "<tr><td>Tamanho total:</td><td class='num'>" . number_format($totalBytes) . " bytes (" . round($totalBytes/1024, 1) . " KB)</td></tr>";
echo "<tr><td>Total de linhas:</td><td class='num'>" . number_format($totalLines) . "</td></tr>";
echo "</table>";

// Breakdown por tipo
preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $content, $styles);
preg_match_all('/<script[^>]*>(.*?)<\/script>/s', $content, $scripts);

$cssSize = array_sum(array_map('strlen', $styles[0]));
$jsSize = array_sum(array_map('strlen', $scripts[0]));

echo "<table>";
echo "<tr><th>Tipo</th><th>Tamanho</th><th>%</th></tr>";
echo "<tr><td>CSS (style tags)</td><td class='num'>" . round($cssSize/1024, 1) . " KB</td><td>" . round($cssSize/$totalBytes*100, 1) . "%</td></tr>";
echo "<tr><td>JavaScript (script tags)</td><td class='num'>" . round($jsSize/1024, 1) . " KB</td><td>" . round($jsSize/$totalBytes*100, 1) . "%</td></tr>";
echo "<tr><td>Blocos style:</td><td class='num'>" . count($styles[0]) . "</td><td></td></tr>";
echo "<tr><td>Blocos script:</td><td class='num'>" . count($scripts[0]) . "</td><td></td></tr>";
echo "</table>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 2. FUNÇÕES DUPLICADAS (CRÍTICO!)
// ═══════════════════════════════════════════════════════════════════════════════
echo "<div class='box critical'>";
echo "<h2>🚨 2. FUNÇÕES JAVASCRIPT DUPLICADAS</h2>";

preg_match_all('/function\s+(\w+)\s*\([^)]*\)\s*\{/', $content, $funcMatches, PREG_OFFSET_CAPTURE);

$funcCount = [];
foreach ($funcMatches[1] as $match) {
    $funcName = $match[0];
    $pos = $match[1];
    $line = substr_count(substr($content, 0, $pos), "\n") + 1;
    
    if (!isset($funcCount[$funcName])) {
        $funcCount[$funcName] = [];
    }
    $funcCount[$funcName][] = $line;
}

$duplicates = array_filter($funcCount, function($lines) { return count($lines) > 1; });
uasort($duplicates, function($a, $b) { return count($b) - count($a); });

if (empty($duplicates)) {
    echo "<p class='ok'>✅ Nenhuma função duplicada encontrada</p>";
} else {
    echo "<p class='err'>❌ " . count($duplicates) . " funções definidas múltiplas vezes!</p>";
    echo "<table>";
    echo "<tr><th>Função</th><th>Vezes</th><th>Linhas</th></tr>";
    foreach (array_slice($duplicates, 0, 30, true) as $name => $lines) {
        $class = count($lines) > 2 ? 'err' : 'warn';
        echo "<tr><td class='$class'>$name()</td><td class='num'>" . count($lines) . "x</td><td>" . implode(', ', $lines) . "</td></tr>";
    }
    echo "</table>";
}
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 3. FUNÇÃO voiceBtnClick ESPECÍFICA
// ═══════════════════════════════════════════════════════════════════════════════
echo "<div class='box critical'>";
echo "<h2>🎤 3. ANÁLISE: voiceBtnClick()</h2>";

if (isset($funcCount['voiceBtnClick'])) {
    $lines = $funcCount['voiceBtnClick'];
    echo "<p class='err'>⚠️ Encontrada " . count($lines) . " vez(es) nas linhas: " . implode(', ', $lines) . "</p>";
    
    // Extrai cada versão
    foreach ($lines as $idx => $lineNum) {
        preg_match_all('/function\s+voiceBtnClick\s*\([^)]*\)\s*\{/', $content, $m, PREG_OFFSET_CAPTURE);
        if (isset($m[0][$idx])) {
            $pos = $m[0][$idx][1];
            $nivel = 0;
            $fim = $pos;
            for ($i = $pos; $i < min($pos + 3000, strlen($content)); $i++) {
                if ($content[$i] === '{') $nivel++;
                if ($content[$i] === '}') {
                    $nivel--;
                    if ($nivel === 0) { $fim = $i + 1; break; }
                }
            }
            $funcBody = substr($content, $pos, $fim - $pos);
            
            $isCorrect = strpos($funcBody, "one_voice.php") !== false;
            $boxClass = $isCorrect ? 'ok' : 'err';
            
            echo "<p style='margin-top:15px'><strong>Versão " . ($idx+1) . " (linha $lineNum):</strong> ";
            echo $isCorrect ? "<span class='ok'>✅ CORRETA</span>" : "<span class='err'>❌ ANTIGA</span>";
            echo " - " . strlen($funcBody) . " bytes</p>";
            echo "<pre>" . htmlspecialchars(substr($funcBody, 0, 500)) . (strlen($funcBody) > 500 ? '...' : '') . "</pre>";
        }
    }
} else {
    echo "<p class='err'>❌ Função voiceBtnClick não encontrada!</p>";
}
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 4. IDs DUPLICADOS
// ═══════════════════════════════════════════════════════════════════════════════
echo "<div class='box warning'>";
echo "<h2>🏷️ 4. IDs HTML DUPLICADOS</h2>";

preg_match_all('/id\s*=\s*["\']([^"\']+)["\']/', $content, $idMatches);
$idCount = array_count_values($idMatches[1]);
$duplicateIds = array_filter($idCount, function($c) { return $c > 1; });
arsort($duplicateIds);

if (empty($duplicateIds)) {
    echo "<p class='ok'>✅ Nenhum ID duplicado</p>";
} else {
    echo "<p class='warn'>⚠️ " . count($duplicateIds) . " IDs duplicados encontrados</p>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Vezes</th></tr>";
    foreach (array_slice($duplicateIds, 0, 20, true) as $id => $count) {
        echo "<tr><td>#$id</td><td class='num'>{$count}x</td></tr>";
    }
    echo "</table>";
}
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 5. PADRÕES DE CÓDIGO
// ═══════════════════════════════════════════════════════════════════════════════
echo "<div class='box'>";
echo "<h2>📈 5. PADRÕES DETECTADOS</h2>";
echo "<table>";
echo "<tr><th>Padrão</th><th>Quantidade</th><th>Status</th></tr>";

$patterns = [
    ['DOMContentLoaded', substr_count($content, 'DOMContentLoaded'), 5],
    ['addEventListener', substr_count($content, 'addEventListener'), 50],
    ['setInterval', substr_count($content, 'setInterval'), 10],
    ['setTimeout', substr_count($content, 'setTimeout'), 30],
    ['fetch(', substr_count($content, 'fetch('), 20],
    ['console.log', substr_count($content, 'console.log'), 20],
    ['style="', substr_count($content, 'style="'), 200],
    ['!important', substr_count($content, '!important'), 50],
];

foreach ($patterns as $p) {
    $status = $p[1] > $p[2] ? "<span class='warn'>⚠️ Alto</span>" : "<span class='ok'>✅ OK</span>";
    echo "<tr><td>{$p[0]}</td><td class='num'>{$p[1]}</td><td>$status</td></tr>";
}
echo "</table>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 6. MAIORES FUNÇÕES
// ═══════════════════════════════════════════════════════════════════════════════
echo "<div class='box'>";
echo "<h2>📏 6. MAIORES FUNÇÕES (possível refatorar)</h2>";

$funcSizes = [];
foreach ($funcMatches[0] as $idx => $match) {
    $funcName = $funcMatches[1][$idx][0];
    $pos = $match[1];
    
    $nivel = 0;
    $fim = $pos;
    for ($i = $pos; $i < min($pos + 10000, strlen($content)); $i++) {
        if ($content[$i] === '{') $nivel++;
        if ($content[$i] === '}') {
            $nivel--;
            if ($nivel === 0) { $fim = $i + 1; break; }
        }
    }
    
    $size = $fim - $pos;
    if (!isset($funcSizes[$funcName]) || $size > $funcSizes[$funcName]) {
        $funcSizes[$funcName] = $size;
    }
}

arsort($funcSizes);
echo "<table>";
echo "<tr><th>Função</th><th>Tamanho</th></tr>";
foreach (array_slice($funcSizes, 0, 15, true) as $name => $size) {
    $class = $size > 3000 ? 'warn' : '';
    echo "<tr><td class='$class'>$name()</td><td class='num'>" . number_format($size) . " bytes</td></tr>";
}
echo "</table>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 7. RECOMENDAÇÕES
// ═══════════════════════════════════════════════════════════════════════════════
echo "<div class='box'>";
echo "<h2>💡 7. RECOMENDAÇÕES</h2>";
echo "<ol style='margin-left:20px;line-height:2'>";

if (!empty($duplicates)) {
    echo "<li class='err'><strong>CRÍTICO:</strong> Remover " . count($duplicates) . " funções duplicadas (especialmente voiceBtnClick)</li>";
}
if (!empty($duplicateIds)) {
    echo "<li class='warn'><strong>IMPORTANTE:</strong> Corrigir " . count($duplicateIds) . " IDs duplicados no HTML</li>";
}
if (count($styles[0]) > 3) {
    echo "<li class='warn'>Consolidar " . count($styles[0]) . " blocos &lt;style&gt; em um só</li>";
}
if (count($scripts[0]) > 3) {
    echo "<li class='warn'>Consolidar " . count($scripts[0]) . " blocos &lt;script&gt; em um só</li>";
}
if ($totalBytes > 500000) {
    echo "<li class='info'>Arquivo muito grande (" . round($totalBytes/1024) . "KB) - considerar separar em módulos</li>";
}

echo "</ol>";
echo "</div>";

echo "<p style='margin-top:20px;color:#666'>Análise concluída em " . date('Y-m-d H:i:s') . "</p>";
echo "</body></html>";
