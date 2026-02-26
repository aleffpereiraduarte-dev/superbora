<?php
/**
 * 🔧 FIX TEXTO EXATO - Busca e substitui a função antiga
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');

$onePath = __DIR__ . '/one.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix Texto Exato</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,sans-serif;background:#0a0a0a;color:#e0e0e0;padding:30px}
.box{background:#111;border:1px solid #333;border-radius:12px;padding:24px;margin-bottom:20px}
.ok{color:#10b981}.err{color:#ef4444}.warn{color:#f59e0b}
h1{color:#fff;margin-bottom:20px}
.btn{background:#10a37f;color:#fff;padding:14px 28px;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:600;text-decoration:none;display:inline-block;margin:5px}
pre{background:#000;padding:15px;border-radius:8px;font-size:11px;margin:15px 0;overflow-x:auto}
</style>
</head><body>";

echo "<h1>🔧 Fix Texto Exato</h1>";

if (!file_exists($onePath)) {
    die("<div class='box err'>one.php não encontrado!</div>");
}

$content = file_get_contents($onePath);

// Texto EXATO da função antiga (como aparece no console)
$funcaoAntiga = 'function voiceBtnClick() {
        // Desbloqueia áudio no primeiro clique
        prepareAudio();
        
        if (voiceState === VoiceState.IDLE) {
            // Ativa e já começa a ouvir wake word
            toggleVoice();
        } else if (voiceState === VoiceState.LISTENING_WAKE) {
            // Ativa direto sem precisar de wake word (atalho)
            activateVoice();
        } else if (voiceState === VoiceState.SPEAKING) {
            // Interrompe
            interruptSpeaking();
        } else if (voiceState === VoiceState.RECORDING) {
            // Para gravação e processa
            stopRecording();
        } else {
            // Desativa
            voiceEnabled = false;
            stopAll();
        }
    }';

$funcaoNova = 'function voiceBtnClick() {
        // Redireciona para ONE Voice
        window.location.href = "one_voice.php";
    }';

$action = $_GET['action'] ?? '';

if ($action === 'aplicar') {
    // Backup
    $backup = $onePath . '.backup_' . date('YmdHis');
    copy($onePath, $backup);
    echo "<p class='ok'>📦 Backup: " . basename($backup) . "</p>";
    
    // Verifica se encontra
    if (strpos($content, $funcaoAntiga) !== false) {
        $content = str_replace($funcaoAntiga, $funcaoNova, $content);
        file_put_contents($onePath, $content);
        
        echo "<div class='box'>";
        echo "<h2 class='ok'>✅ FUNÇÃO SUBSTITUÍDA!</h2>";
        echo "<p>A função antiga foi encontrada e substituída.</p>";
        echo "</div>";
    } else {
        echo "<div class='box'>";
        echo "<p class='warn'>⚠️ Texto exato não encontrado. Tentando variações...</p>";
        
        // Tenta com diferentes espaçamentos
        $variacoes = [
            // Com tabs
            str_replace('        ', "\t\t", $funcaoAntiga),
            str_replace('        ', "\t", $funcaoAntiga),
            // Com 4 espaços
            str_replace('        ', '    ', $funcaoAntiga),
            // Sem espaços extras
            preg_replace('/\s+/', ' ', $funcaoAntiga),
        ];
        
        $encontrou = false;
        foreach ($variacoes as $var) {
            if (strpos($content, $var) !== false) {
                $content = str_replace($var, $funcaoNova, $content);
                file_put_contents($onePath, $content);
                echo "<p class='ok'>✅ Encontrado com variação de espaçamento!</p>";
                $encontrou = true;
                break;
            }
        }
        
        if (!$encontrou) {
            // Busca por regex mais flexível
            $pattern = '/function\s+voiceBtnClick\s*\(\s*\)\s*\{[^}]*prepareAudio\(\);[^}]*toggleVoice\(\);[^}]*activateVoice\(\);[^}]*interruptSpeaking\(\);[^}]*stopRecording\(\);[^}]*stopAll\(\);[^}]*\}/s';
            
            if (preg_match($pattern, $content, $match)) {
                $content = str_replace($match[0], $funcaoNova, $content);
                file_put_contents($onePath, $content);
                echo "<p class='ok'>✅ Encontrado por regex!</p>";
                echo "<pre>Substituído:\n" . htmlspecialchars(substr($match[0], 0, 200)) . "...</pre>";
                $encontrou = true;
            }
        }
        
        if (!$encontrou) {
            echo "<p class='err'>❌ Não encontrou a função. Mostrando contexto...</p>";
            
            // Mostra onde tem voiceBtnClick
            $pos = strpos($content, 'function voiceBtnClick');
            if ($pos !== false) {
                $contexto = substr($content, $pos, 800);
                echo "<pre>" . htmlspecialchars($contexto) . "</pre>";
                echo "<p>Posição: $pos</p>";
            }
        }
        echo "</div>";
    }
    
    echo "<div class='box' style='text-align:center'>";
    echo "<p style='margin-bottom:15px'>⚠️ <strong>LIMPA O CACHE!</strong> Ctrl+Shift+R</p>";
    echo "<a href='one.php' class='btn' style='font-size:20px;padding:20px 40px'>🎤 TESTAR</a>";
    echo "</div>";
    
} else {
    // Mostra status
    echo "<div class='box'>";
    echo "<h2>🔍 Status</h2>";
    
    $encontrou = strpos($content, $funcaoAntiga) !== false;
    $posicao = strpos($content, 'function voiceBtnClick');
    
    if ($encontrou) {
        echo "<p class='ok'>✅ Função antiga encontrada (texto exato)</p>";
    } else {
        echo "<p class='warn'>⚠️ Texto exato não encontrado, mas tentará variações</p>";
    }
    
    if ($posicao !== false) {
        $linha = substr_count(substr($content, 0, $posicao), "\n") + 1;
        echo "<p>voiceBtnClick está na linha: <strong>$linha</strong></p>";
        
        // Mostra o contexto
        $contexto = substr($content, $posicao, 600);
        echo "<p style='margin-top:15px'>Contexto atual:</p>";
        echo "<pre>" . htmlspecialchars($contexto) . "</pre>";
    }
    
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<a href='?action=aplicar' class='btn' style='font-size:18px'>🚀 SUBSTITUIR FUNÇÃO</a>";
    echo "</div>";
}

echo "</body></html>";
