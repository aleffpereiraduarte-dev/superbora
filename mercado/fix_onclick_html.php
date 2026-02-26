<?php
/**
 * 🔧 FIX ONCLICK HTML - Muda direto no botão
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');

$onePath = __DIR__ . '/one.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix OnClick</title>
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

echo "<h1>🔧 Fix OnClick HTML</h1>";

if (!file_exists($onePath)) {
    die("<div class='box err'>one.php não encontrado!</div>");
}

$content = file_get_contents($onePath);

$action = $_GET['action'] ?? '';

if ($action === 'aplicar') {
    // Backup
    copy($onePath, $onePath . '.bak_' . date('YmdHis'));
    
    // 1. Substitui onclick="voiceBtnClick()" por redirecionamento direto
    $content = str_replace(
        'onclick="voiceBtnClick()"',
        'onclick="window.location.href=\'one_voice.php\';return false;"',
        $content
    );
    
    // 2. Também tenta com aspas simples
    $content = str_replace(
        "onclick='voiceBtnClick()'",
        "onclick=\"window.location.href='one_voice.php';return false;\"",
        $content
    );
    
    file_put_contents($onePath, $content);
    
    echo "<div class='box'>";
    echo "<h2 class='ok'>✅ APLICADO!</h2>";
    echo "<p>Substituído <code>onclick=\"voiceBtnClick()\"</code> por redirecionamento direto.</p>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<p style='margin-bottom:15px'>Ctrl+Shift+R para limpar cache</p>";
    echo "<a href='one.php' class='btn' style='font-size:20px;padding:20px 40px'>🎤 TESTAR</a>";
    echo "</div>";
    
} else {
    // Mostra status
    $count = substr_count($content, 'onclick="voiceBtnClick()"');
    
    echo "<div class='box'>";
    echo "<h2>🔍 Status</h2>";
    echo "<p>Encontrados <strong>$count</strong> botões com <code>onclick=\"voiceBtnClick()\"</code></p>";
    
    // Mostra o botão atual
    if (preg_match('/<button[^>]*id="voiceBtn"[^>]*>/', $content, $match)) {
        echo "<p style='margin-top:15px'>Botão atual:</p>";
        echo "<pre>" . htmlspecialchars($match[0]) . "</pre>";
    }
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<a href='?action=aplicar' class='btn'>🚀 APLICAR FIX</a>";
    echo "</div>";
}

echo "</body></html>";
