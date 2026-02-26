<?php
/**
 * 🔧 FIX TIMING - Executa DEPOIS de tudo
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$onePath = __DIR__ . '/one.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix Timing</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,sans-serif;background:#0a0a0a;color:#e0e0e0;padding:30px}
.box{background:#111;border:1px solid #333;border-radius:12px;padding:24px;margin-bottom:20px}
.ok{color:#10b981}.err{color:#ef4444}
h1{color:#fff;margin-bottom:20px}
.btn{background:#10a37f;color:#fff;padding:14px 28px;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:600;text-decoration:none;display:inline-block;margin:5px}
pre{background:#000;padding:15px;border-radius:8px;font-size:12px;margin:15px 0}
</style>
</head><body>";

echo "<h1>🔧 Fix Timing - Voz</h1>";

if (!file_exists($onePath)) {
    die("<div class='box err'>one.php não encontrado!</div>");
}

$content = file_get_contents($onePath);

$action = $_GET['action'] ?? '';

if ($action === 'aplicar') {
    // Backup
    copy($onePath, $onePath . '.bak_' . date('YmdHis'));
    
    // Remove fixes anteriores
    $content = preg_replace('/<!-- ONE VOICE.*?<\/script>/s', '', $content);
    $content = preg_replace('/<!-- FIX VOICE.*?<\/script>/s', '', $content);
    
    // Novo fix - executa com delays crescentes
    $fix = '
<!-- FIX VOICE TIMING -->
<script>
(function(){
    function forceVoiceRedirect() {
        var btn = document.getElementById("voiceBtn");
        if (btn) {
            // Clona pra remover listeners antigos
            var novo = btn.cloneNode(true);
            novo.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                window.location.href = "one_voice.php";
                return false;
            };
            if (btn.parentNode) {
                btn.parentNode.replaceChild(novo, btn);
            }
        }
    }
    
    // Aplica em vários momentos pra garantir
    setTimeout(forceVoiceRedirect, 100);
    setTimeout(forceVoiceRedirect, 500);
    setTimeout(forceVoiceRedirect, 1000);
    setTimeout(forceVoiceRedirect, 2000);
    setTimeout(forceVoiceRedirect, 3000);
    setTimeout(forceVoiceRedirect, 5000);
    
    // Também aplica quando clicar em qualquer lugar (caso recriem o botão)
    document.addEventListener("click", function(e) {
        var target = e.target.closest("#voiceBtn, .voice-btn");
        if (target) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            window.location.href = "one_voice.php";
            return false;
        }
    }, true);
})();
</script>
';
    
    // Coloca DEPOIS do </body> pra ser o último
    if (strpos($content, '</body>') !== false) {
        $content = str_replace('</body>', '</body>' . $fix, $content);
    } elseif (strpos($content, '</html>') !== false) {
        $content = str_replace('</html>', '</html>' . $fix, $content);
    } else {
        $content .= $fix;
    }
    
    file_put_contents($onePath, $content);
    
    echo "<div class='box'>";
    echo "<h2 class='ok'>✅ APLICADO!</h2>";
    echo "<p>O fix:</p>";
    echo "<ul style='margin:15px 20px;line-height:2'>";
    echo "<li>Clona o botão (remove todos os listeners antigos)</li>";
    echo "<li>Aplica em 100ms, 500ms, 1s, 2s, 3s, 5s</li>";
    echo "<li>Intercepta cliques com event delegation (capture)</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<p style='margin-bottom:15px'>⚠️ <strong>IMPORTANTE:</strong> Limpa o cache do navegador!</p>";
    echo "<p style='margin-bottom:20px'>Ctrl+Shift+R ou Cmd+Shift+R</p>";
    echo "<a href='one.php' class='btn' style='font-size:20px;padding:20px 40px'>🎤 TESTAR</a>";
    echo "</div>";
    
} else {
    echo "<div class='box' style='text-align:center'>";
    echo "<p style='margin-bottom:20px'>Este fix aplica o redirecionamento com delays crescentes (100ms até 5s) para garantir que seja o último a executar.</p>";
    echo "<a href='?action=aplicar' class='btn' style='font-size:18px'>🚀 APLICAR FIX</a>";
    echo "</div>";
}

echo "</body></html>";
