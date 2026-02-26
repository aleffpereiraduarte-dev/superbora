<?php
/**
 * 🔧 FIX DEFINITIVO voiceBtnClick
 * Injeta a função correta no FINAL do arquivo para sobrescrever qualquer outra
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$onePath = __DIR__ . '/one.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix voiceBtnClick</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:monospace;background:#0a0a0a;color:#0f0;padding:30px;font-size:14px}
.box{background:#111;border:1px solid #333;border-radius:8px;padding:20px;margin-bottom:20px}
.ok{color:#10b981}.err{color:#ef4444}.warn{color:#f59e0b}
h1{color:#fff;margin-bottom:20px}
pre{background:#000;padding:15px;border-radius:6px;overflow-x:auto;margin:10px 0}
.btn{background:#10a37f;color:#fff;padding:12px 24px;border:none;border-radius:6px;cursor:pointer;font-size:14px;margin:10px 5px 10px 0;text-decoration:none;display:inline-block}
.btn:hover{background:#0d8a6a}
.btn-danger{background:#ef4444}
</style>
</head><body>";

echo "<h1>🔧 FIX DEFINITIVO: voiceBtnClick</h1>";

if (!file_exists($onePath)) {
    die("<p class='err'>❌ one.php não encontrado!</p>");
}

$content = file_get_contents($onePath);

// O código que vamos injetar no final
$injectCode = '
<!-- ONE VOICE FIX - INJETADO NO FINAL -->
<script>
// Sobrescreve qualquer definição anterior de voiceBtnClick
window.voiceBtnClick = function() {
    window.location.href = "one_voice.php";
};

// Garante que o botão use nossa função
document.addEventListener("DOMContentLoaded", function() {
    var voiceBtn = document.getElementById("voiceBtn");
    if (voiceBtn) {
        voiceBtn.onclick = function() {
            window.location.href = "one_voice.php";
        };
        console.log("✅ ONE Voice Fix aplicado!");
    }
});
</script>
<!-- FIM ONE VOICE FIX -->
';

$action = $_GET['action'] ?? '';

if ($action === 'aplicar') {
    // Verifica se já foi aplicado
    if (strpos($content, 'ONE VOICE FIX - INJETADO') !== false) {
        echo "<div class='box'>";
        echo "<p class='warn'>⚠️ Fix já foi aplicado anteriormente!</p>";
        echo "<p>Quer reaplicar mesmo assim?</p>";
        echo "<a href='?action=forcar' class='btn btn-danger'>🔄 Forçar reaplicação</a>";
        echo "<a href='one.php' class='btn'>🎤 Testar ONE</a>";
        echo "</div>";
    } else {
        // Faz backup
        $backup = $onePath . '.backup_' . date('YmdHis');
        copy($onePath, $backup);
        
        // Injeta antes do </body> ou </html> ou no final
        if (strpos($content, '</body>') !== false) {
            $content = str_replace('</body>', $injectCode . '</body>', $content);
        } elseif (strpos($content, '</html>') !== false) {
            $content = str_replace('</html>', $injectCode . '</html>', $content);
        } else {
            $content .= $injectCode;
        }
        
        file_put_contents($onePath, $content);
        
        echo "<div class='box'>";
        echo "<p class='ok'>✅ FIX APLICADO COM SUCESSO!</p>";
        echo "<p>Backup salvo em: " . basename($backup) . "</p>";
        echo "<p style='margin-top:15px'>O código foi injetado no final do arquivo para garantir que sobrescreva qualquer outra definição.</p>";
        echo "<pre>" . htmlspecialchars($injectCode) . "</pre>";
        echo "<a href='one.php' class='btn'>🎤 TESTAR AGORA</a>";
        echo "<a href='one_voice.php' class='btn' style='background:#6366f1'>🎙️ Testar Voice direto</a>";
        echo "</div>";
    }
    
} elseif ($action === 'forcar') {
    // Remove fix anterior
    $content = preg_replace('/<!-- ONE VOICE FIX - INJETADO NO FINAL -->.*?<!-- FIM ONE VOICE FIX -->/s', '', $content);
    
    // Backup
    $backup = $onePath . '.backup_' . date('YmdHis');
    copy($onePath, $backup);
    
    // Injeta novamente
    if (strpos($content, '</body>') !== false) {
        $content = str_replace('</body>', $injectCode . '</body>', $content);
    } elseif (strpos($content, '</html>') !== false) {
        $content = str_replace('</html>', $injectCode . '</html>', $content);
    } else {
        $content .= $injectCode;
    }
    
    file_put_contents($onePath, $content);
    
    echo "<div class='box'>";
    echo "<p class='ok'>✅ FIX REAPLICADO!</p>";
    echo "<a href='one.php' class='btn'>🎤 TESTAR AGORA</a>";
    echo "</div>";
    
} elseif ($action === 'remover') {
    // Remove o fix
    $content = preg_replace('/<!-- ONE VOICE FIX - INJETADO NO FINAL -->.*?<!-- FIM ONE VOICE FIX -->/s', '', $content);
    file_put_contents($onePath, $content);
    
    echo "<div class='box'>";
    echo "<p class='ok'>✅ Fix removido!</p>";
    echo "</div>";
    
} else {
    // Mostra status atual
    echo "<div class='box'>";
    echo "<h2>📊 Status Atual</h2>";
    
    $temFix = strpos($content, 'ONE VOICE FIX - INJETADO') !== false;
    
    if ($temFix) {
        echo "<p class='ok'>✅ Fix já está aplicado no arquivo</p>";
    } else {
        echo "<p class='warn'>⚠️ Fix ainda não aplicado</p>";
    }
    
    // Verifica a função atual
    preg_match('/function\s+voiceBtnClick\s*\(\)\s*\{[^}]+\}/', $content, $match);
    if (!empty($match)) {
        echo "<p style='margin-top:15px'>Função atual no código fonte:</p>";
        echo "<pre>" . htmlspecialchars($match[0]) . "</pre>";
    }
    
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h2>🔧 O que este fix faz?</h2>";
    echo "<p>1. Injeta um script no <strong>final</strong> do HTML</p>";
    echo "<p>2. Define <code>window.voiceBtnClick</code> globalmente (sobrescreve qualquer outra)</p>";
    echo "<p>3. Adiciona um <code>onclick</code> direto no botão após o DOM carregar</p>";
    echo "<p>4. Isso garante que mesmo se algum outro código redefinir a função, o botão ainda vai funcionar</p>";
    
    echo "<p style='margin-top:20px'>Código que será injetado:</p>";
    echo "<pre>" . htmlspecialchars($injectCode) . "</pre>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<a href='?action=aplicar' class='btn'>🚀 APLICAR FIX</a>";
    if ($temFix) {
        echo "<a href='?action=remover' class='btn btn-danger'>🗑️ Remover Fix</a>";
    }
    echo "<a href='one.php' class='btn' style='background:#6366f1'>🎤 Testar ONE</a>";
    echo "</div>";
}

echo "</body></html>";
