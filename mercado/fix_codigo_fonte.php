<?php
/**
 * 🔧 FIX CÓDIGO FONTE - Substitui a função voiceBtnClick antiga
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');

$onePath = __DIR__ . '/one.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix Código Fonte</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,sans-serif;background:#0a0a0a;color:#e0e0e0;padding:30px}
.box{background:#111;border:1px solid #333;border-radius:12px;padding:24px;margin-bottom:20px}
.ok{color:#10b981}.err{color:#ef4444}.warn{color:#f59e0b}
h1{color:#fff;margin-bottom:20px}
.btn{background:#10a37f;color:#fff;padding:14px 28px;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:600;text-decoration:none;display:inline-block;margin:5px}
pre{background:#000;padding:15px;border-radius:8px;font-size:11px;margin:15px 0;overflow-x:auto;white-space:pre-wrap}
</style>
</head><body>";

echo "<h1>🔧 Fix Código Fonte - voiceBtnClick</h1>";

if (!file_exists($onePath)) {
    die("<div class='box err'>one.php não encontrado!</div>");
}

$content = file_get_contents($onePath);
$originalSize = strlen($content);

$action = $_GET['action'] ?? '';

if ($action === 'analisar') {
    echo "<div class='box'>";
    echo "<h2>🔍 Análise da função voiceBtnClick</h2>";
    
    // Encontra TODAS as ocorrências da função
    $pattern = '/function\s+voiceBtnClick\s*\(\s*\)\s*\{/';
    preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
    
    echo "<p>Encontradas <strong>" . count($matches[0]) . "</strong> definições de voiceBtnClick:</p>";
    
    foreach ($matches[0] as $idx => $match) {
        $pos = $match[1];
        $line = substr_count(substr($content, 0, $pos), "\n") + 1;
        
        // Extrai a função completa
        $nivel = 0;
        $inicio = $pos;
        $fim = $pos;
        for ($i = $pos; $i < min($pos + 3000, strlen($content)); $i++) {
            if ($content[$i] === '{') $nivel++;
            if ($content[$i] === '}') {
                $nivel--;
                if ($nivel === 0) {
                    $fim = $i + 1;
                    break;
                }
            }
        }
        
        $funcao = substr($content, $inicio, $fim - $inicio);
        $temOneVoice = strpos($funcao, 'one_voice.php') !== false;
        $temPrepareAudio = strpos($funcao, 'prepareAudio') !== false;
        
        $status = $temOneVoice ? "<span class='ok'>✅ CORRETA</span>" : "<span class='err'>❌ ANTIGA</span>";
        
        echo "<div style='margin:15px 0;padding:15px;background:#1a1a1a;border-radius:8px'>";
        echo "<p><strong>Definição " . ($idx + 1) . "</strong> - Linha $line - $status</p>";
        echo "<pre>" . htmlspecialchars(substr($funcao, 0, 500)) . (strlen($funcao) > 500 ? '...' : '') . "</pre>";
        echo "</div>";
    }
    
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<a href='?action=aplicar' class='btn'>🚀 SUBSTITUIR FUNÇÃO ANTIGA</a>";
    echo "</div>";
    
} elseif ($action === 'aplicar') {
    // Backup
    $backup = $onePath . '.backup_' . date('YmdHis');
    copy($onePath, $backup);
    echo "<p class='ok'>📦 Backup: " . basename($backup) . "</p>";
    
    // Encontra a função antiga (que tem prepareAudio)
    $patternAntiga = '/function\s+voiceBtnClick\s*\(\s*\)\s*\{[^}]*prepareAudio[^}]*(?:\{[^}]*\}[^}]*)*\}/s';
    
    // Tenta padrão mais abrangente
    $found = preg_match_all('/function\s+voiceBtnClick\s*\(\s*\)\s*\{/', $content, $matches, PREG_OFFSET_CAPTURE);
    
    $substituicoes = 0;
    
    foreach ($matches[0] as $match) {
        $pos = $match[1];
        
        // Extrai a função completa
        $nivel = 0;
        $inicio = $pos;
        $fim = $pos;
        for ($i = $pos; $i < strlen($content); $i++) {
            if ($content[$i] === '{') $nivel++;
            if ($content[$i] === '}') {
                $nivel--;
                if ($nivel === 0) {
                    $fim = $i + 1;
                    break;
                }
            }
        }
        
        $funcaoAntiga = substr($content, $inicio, $fim - $inicio);
        
        // Só substitui se for a antiga (tem prepareAudio ou toggleVoice)
        if (strpos($funcaoAntiga, 'prepareAudio') !== false || strpos($funcaoAntiga, 'toggleVoice') !== false) {
            $funcaoNova = "function voiceBtnClick() {\n        // Redireciona para ONE Voice\n        window.location.href = 'one_voice.php';\n    }";
            
            $content = substr($content, 0, $inicio) . $funcaoNova . substr($content, $fim);
            $substituicoes++;
            
            echo "<div class='box'>";
            echo "<p class='ok'>✅ Função antiga substituída (linha " . (substr_count(substr($content, 0, $inicio), "\n") + 1) . ")</p>";
            echo "<p><strong>De:</strong></p>";
            echo "<pre>" . htmlspecialchars(substr($funcaoAntiga, 0, 300)) . "...</pre>";
            echo "<p><strong>Para:</strong></p>";
            echo "<pre>" . htmlspecialchars($funcaoNova) . "</pre>";
            echo "</div>";
            
            break; // Só precisa substituir uma vez
        }
    }
    
    if ($substituicoes === 0) {
        echo "<div class='box warn'>";
        echo "<p>⚠️ Nenhuma função antiga encontrada para substituir.</p>";
        echo "<p>Pode ser que já tenha sido substituída anteriormente.</p>";
        echo "</div>";
    }
    
    // Remove fixes antigos (scripts injetados)
    $content = preg_replace('/<!-- ONE VOICE.*?<\/script>\s*/s', '', $content);
    $content = preg_replace('/<!-- FIX VOICE.*?<\/script>\s*/s', '', $content);
    $content = preg_replace('/<!-- VOICE BTN FIX.*?<\/script>\s*/s', '', $content);
    
    file_put_contents($onePath, $content);
    
    $newSize = strlen($content);
    echo "<p>Tamanho: " . number_format($originalSize) . " → " . number_format($newSize) . " bytes</p>";
    
    echo "<div class='box' style='text-align:center;margin-top:20px'>";
    echo "<p style='margin-bottom:15px'>⚠️ <strong>LIMPA O CACHE!</strong> Ctrl+Shift+R</p>";
    echo "<a href='one.php' class='btn' style='font-size:20px;padding:20px 40px'>🎤 TESTAR AGORA</a>";
    echo "</div>";
    
} else {
    // Tela inicial
    echo "<div class='box'>";
    echo "<h2>🎯 O Problema</h2>";
    echo "<p>A função <code>voiceBtnClick()</code> no código fonte ainda é a <strong>antiga</strong>:</p>";
    echo "<pre>function voiceBtnClick() {
    prepareAudio();
    if (voiceState === VoiceState.IDLE) {
        toggleVoice();
    } ...
}</pre>";
    echo "<p style='margin-top:15px'>Precisamos substituir por:</p>";
    echo "<pre>function voiceBtnClick() {
    window.location.href = 'one_voice.php';
}</pre>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<a href='?action=analisar' class='btn' style='background:#6366f1'>🔍 ANALISAR PRIMEIRO</a>";
    echo "<a href='?action=aplicar' class='btn'>🚀 SUBSTITUIR DIRETO</a>";
    echo "</div>";
}

echo "</body></html>";
