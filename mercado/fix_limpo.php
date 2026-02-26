<?php
/**
 * 🔧 FIX LIMPO - Remove sistema de voz antigo
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');

$onePath = __DIR__ . '/one.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix Limpo</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,sans-serif;background:#0a0a0a;color:#e0e0e0;padding:30px}
.box{background:#111;border:1px solid #333;border-radius:12px;padding:24px;margin-bottom:20px}
.ok{color:#10b981}.err{color:#ef4444}.warn{color:#f59e0b}
h1{color:#fff;margin-bottom:20px}
.btn{background:#10a37f;color:#fff;padding:14px 28px;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:600;text-decoration:none;display:inline-block;margin:5px}
pre{background:#000;padding:15px;border-radius:8px;font-size:11px;margin:15px 0;overflow-x:auto;max-height:300px}
</style>
</head><body>";

echo "<h1>🔧 Fix Limpo - Remove Sistema de Voz Antigo</h1>";

if (!file_exists($onePath)) {
    die("<div class='box err'>one.php não encontrado!</div>");
}

$content = file_get_contents($onePath);
$originalSize = strlen($content);

$action = $_GET['action'] ?? '';

if ($action === 'aplicar') {
    // Backup
    $backup = $onePath . '.backup_limpo_' . date('YmdHis');
    copy($onePath, $backup);
    echo "<p class='ok'>📦 Backup: " . basename($backup) . "</p>";
    
    $changes = [];
    
    // 1. Remover blocos de fix antigos (ONE VOICE FIX)
    $pattern1 = '/<!-- ═+[\s\S]*?ONE VOICE FIX[\s\S]*?<\/script>\s*<!-- ═+\s*-->/s';
    if (preg_match($pattern1, $content)) {
        $content = preg_replace($pattern1, '', $content);
        $changes[] = "Removido bloco ONE VOICE FIX DEFINITIVO";
    }
    
    // 2. Remover FIX VOICE TIMING
    $pattern2 = '/<!-- FIX VOICE TIMING -->[\s\S]*?<\/script>/s';
    if (preg_match($pattern2, $content)) {
        $content = preg_replace($pattern2, '', $content);
        $changes[] = "Removido FIX VOICE TIMING";
    }
    
    // 3. Remover ONE VOICE FINAL OVERRIDE
    $pattern3 = '/<!-- ONE VOICE FINAL OVERRIDE -->[\s\S]*?<\/script>/s';
    if (preg_match($pattern3, $content)) {
        $content = preg_replace($pattern3, '', $content);
        $changes[] = "Removido ONE VOICE FINAL OVERRIDE";
    }
    
    // 4. Comentar o initAlwaysListening() para não iniciar automaticamente
    $content = str_replace(
        'initAlwaysListening();',
        '// initAlwaysListening(); // DESATIVADO - Usando ONE Voice',
        $content
    );
    $changes[] = "Desativado initAlwaysListening()";
    
    // 5. Garantir que o botão tem onclick direto
    // Procura o botão e garante que tem o onclick correto
    $content = preg_replace(
        '/<button([^>]*class="voice-btn"[^>]*id="voiceBtn"[^>]*)>/',
        '<button$1 onclick="window.location.href=\'one_voice.php\'" >',
        $content
    );
    
    // Remove onclick duplicado se houver
    $content = preg_replace('/onclick="[^"]*"\s*onclick="/', 'onclick="', $content);
    
    // 6. Simplificar - substituir qualquer onclick do voiceBtn para o correto
    $content = preg_replace(
        '/(<button[^>]*id="voiceBtn"[^>]*)onclick="[^"]*"/',
        '$1onclick="window.location.href=\'one_voice.php\'"',
        $content
    );
    
    $changes[] = "Atualizado onclick do botão";
    
    file_put_contents($onePath, $content);
    
    $newSize = strlen($content);
    $saved = $originalSize - $newSize;
    
    echo "<div class='box'>";
    echo "<h2 class='ok'>✅ LIMPEZA CONCLUÍDA!</h2>";
    echo "<p>Tamanho: " . round($originalSize/1024) . " KB → " . round($newSize/1024) . " KB (economizou " . round($saved/1024) . " KB)</p>";
    echo "<p style='margin-top:15px'><strong>Mudanças:</strong></p>";
    echo "<ul style='margin:10px 20px'>";
    foreach ($changes as $c) {
        echo "<li>$c</li>";
    }
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<p style='margin-bottom:15px'>⚠️ <strong>IMPORTANTE:</strong> Ctrl+Shift+R para limpar cache!</p>";
    echo "<a href='one.php' class='btn' style='font-size:20px;padding:20px 40px'>🎤 TESTAR AGORA</a>";
    echo "</div>";
    
} else {
    // Análise
    echo "<div class='box'>";
    echo "<h2>🔍 Problemas Encontrados</h2>";
    
    $problems = [];
    
    // Verifica fixes antigos
    if (strpos($content, 'ONE VOICE FIX') !== false) {
        $problems[] = "❌ Há blocos de 'ONE VOICE FIX' que estão removendo o onclick do botão";
    }
    
    if (strpos($content, 'FIX VOICE TIMING') !== false) {
        $problems[] = "❌ Há 'FIX VOICE TIMING' interferindo";
    }
    
    // Verifica initAlwaysListening
    if (strpos($content, 'initAlwaysListening();') !== false && strpos($content, '// initAlwaysListening();') === false) {
        $problems[] = "❌ 'initAlwaysListening()' está ativo - inicia sistema de voz antigo";
    }
    
    // Verifica o botão
    if (preg_match('/<button[^>]*id="voiceBtn"[^>]*>/', $content, $btn)) {
        echo "<p>Botão encontrado:</p>";
        echo "<pre>" . htmlspecialchars($btn[0]) . "</pre>";
        
        if (strpos($btn[0], 'onclick') === false) {
            $problems[] = "❌ Botão não tem onclick!";
        } elseif (strpos($btn[0], 'one_voice.php') === false) {
            $problems[] = "❌ onclick não aponta para one_voice.php";
        }
    }
    
    if (empty($problems)) {
        echo "<p class='ok'>✅ Nenhum problema óbvio encontrado</p>";
    } else {
        echo "<ul style='margin:15px 20px;line-height:2'>";
        foreach ($problems as $p) {
            echo "<li class='err'>$p</li>";
        }
        echo "</ul>";
    }
    
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h2>🎯 O que o fix vai fazer:</h2>";
    echo "<ol style='margin:15px 20px;line-height:2'>";
    echo "<li>Remover TODOS os blocos de fix antigos (ONE VOICE FIX, FIX VOICE TIMING, etc)</li>";
    echo "<li>Desativar o <code>initAlwaysListening()</code> que inicia o sistema de voz antigo</li>";
    echo "<li>Garantir que o botão tem <code>onclick=\"window.location.href='one_voice.php'\"</code></li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<a href='?action=aplicar' class='btn' style='font-size:18px'>🚀 APLICAR FIX LIMPO</a>";
    echo "</div>";
}

echo "</body></html>";
