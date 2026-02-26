<?php
/**
 * 🔧 FIX DEFINITIVO BASEADO NA ANÁLISE CLAUDE AI
 * Implementa namespace protegido + Object.freeze + addEventListener
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$onePath = __DIR__ . '/one.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix Definitivo</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,sans-serif;background:#0a0a0a;color:#e0e0e0;padding:30px}
.box{background:#111;border:1px solid #333;border-radius:12px;padding:24px;margin-bottom:20px}
.ok{color:#10b981}.err{color:#ef4444}.warn{color:#f59e0b}
h1{color:#fff;margin-bottom:20px}
pre{background:#000;padding:15px;border-radius:8px;overflow-x:auto;font-size:12px;margin:15px 0}
.btn{background:#10a37f;color:#fff;padding:14px 28px;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:600;text-decoration:none;display:inline-block;margin:5px}
.btn:hover{background:#0d8a6a}
code{background:#222;padding:2px 6px;border-radius:4px;font-size:13px}
</style>
</head><body>";

echo "<h1>🔧 Fix Definitivo - Baseado na Análise Claude AI</h1>";

if (!file_exists($onePath)) {
    die("<div class='box'><p class='err'>❌ one.php não encontrado!</p></div>");
}

$content = file_get_contents($onePath);

// O código fix baseado na recomendação da Claude
$fixCode = '
<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- 🔧 ONE VOICE FIX DEFINITIVO - BASEADO NA ANÁLISE CLAUDE AI     -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<script>
(function() {
    "use strict";
    
    // 1. Criar namespace protegido
    window.ONE = window.ONE || {};
    
    // 2. Definir função no namespace
    window.ONE.voiceBtnClick = function() {
        console.log("🎤 ONE Voice: Redirecionando...");
        window.location.href = "one_voice.php";
        return false;
    };
    
    // 3. Criar função global que aponta para o namespace
    window.voiceBtnClick = function() {
        return window.ONE.voiceBtnClick();
    };
    
    // 4. Tentar proteger com Object.defineProperty
    try {
        Object.defineProperty(window, "voiceBtnClick", {
            value: function() {
                window.location.href = "one_voice.php";
                return false;
            },
            writable: false,
            configurable: false
        });
    } catch(e) {
        // Se falhar (já definido), usa método alternativo
        console.log("ONE Voice: Usando método alternativo");
    }
    
    // 5. Aplicar no DOM quando carregar
    function applyVoiceFix() {
        // Por ID
        var voiceBtn = document.getElementById("voiceBtn");
        if (voiceBtn) {
            voiceBtn.onclick = null; // Remove onclick antigo
            voiceBtn.removeAttribute("onclick");
            voiceBtn.addEventListener("click", function(e) {
                e.preventDefault();
                e.stopPropagation();
                window.location.href = "one_voice.php";
            }, true); // useCapture = true para ter prioridade
            console.log("✅ ONE Voice Fix aplicado no #voiceBtn");
        }
        
        // Por classe
        var voiceBtns = document.querySelectorAll(".voice-btn");
        voiceBtns.forEach(function(btn) {
            btn.onclick = null;
            btn.removeAttribute("onclick");
            btn.addEventListener("click", function(e) {
                e.preventDefault();
                e.stopPropagation();
                window.location.href = "one_voice.php";
            }, true);
        });
        
        // Por atributo onclick que contém voiceBtnClick
        var allBtns = document.querySelectorAll("[onclick*=\'voiceBtnClick\']");
        allBtns.forEach(function(btn) {
            btn.onclick = null;
            btn.removeAttribute("onclick");
            btn.addEventListener("click", function(e) {
                e.preventDefault();
                e.stopPropagation();
                window.location.href = "one_voice.php";
            }, true);
            console.log("✅ ONE Voice Fix aplicado em botão com onclick");
        });
    }
    
    // 6. Aplicar imediatamente se DOM já carregou
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", applyVoiceFix);
    } else {
        applyVoiceFix();
    }
    
    // 7. Reaplicar após 1 segundo (caso algo sobrescreva)
    setTimeout(applyVoiceFix, 1000);
    
    // 8. Reaplicar após 3 segundos (garantia extra)
    setTimeout(applyVoiceFix, 3000);
    
    // 9. Monitorar mudanças no DOM
    if (typeof MutationObserver !== "undefined") {
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === "childList" || mutation.type === "attributes") {
                    var voiceBtn = document.getElementById("voiceBtn");
                    if (voiceBtn && voiceBtn.getAttribute("onclick")) {
                        applyVoiceFix();
                    }
                }
            });
        });
        
        // Observar o body para mudanças
        setTimeout(function() {
            if (document.body) {
                observer.observe(document.body, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ["onclick"]
                });
            }
        }, 100);
    }
    
    console.log("🎤 ONE Voice Fix Definitivo carregado!");
})();
</script>
<!-- ═══════════════════════════════════════════════════════════════ -->
';

$action = $_GET['action'] ?? '';

if ($action === 'aplicar') {
    // Backup
    $backup = $onePath . '.backup_' . date('YmdHis');
    copy($onePath, $backup);
    echo "<p class='ok'>📦 Backup criado: " . basename($backup) . "</p>";
    
    // Remover fix anterior se existir
    $content = preg_replace('/<!-- ═+\s*-->[\s\S]*?ONE VOICE FIX DEFINITIVO[\s\S]*?<!-- ═+\s*-->/s', '', $content);
    $content = preg_replace('/<!-- ONE VOICE FIX[\s\S]*?<\/script>\s*-->/s', '', $content);
    $content = preg_replace('/<!-- VOICE BTN FIX[\s\S]*?<\/script>/s', '', $content);
    
    // Injetar antes do </body>
    if (strpos($content, '</body>') !== false) {
        $content = str_replace('</body>', $fixCode . "\n</body>", $content);
    } elseif (strpos($content, '</html>') !== false) {
        $content = str_replace('</html>', $fixCode . "\n</html>", $content);
    } else {
        $content .= $fixCode;
    }
    
    file_put_contents($onePath, $content);
    
    echo "<div class='box'>";
    echo "<h2 class='ok'>✅ FIX APLICADO COM SUCESSO!</h2>";
    echo "<p style='margin:15px 0'>O fix implementa:</p>";
    echo "<ul style='margin-left:20px;line-height:2'>";
    echo "<li>✅ <strong>Namespace protegido</strong> (window.ONE.voiceBtnClick)</li>";
    echo "<li>✅ <strong>Object.defineProperty</strong> para impedir sobrescrita</li>";
    echo "<li>✅ <strong>addEventListener com capture</strong> para ter prioridade máxima</li>";
    echo "<li>✅ <strong>Remove onclick antigo</strong> do botão</li>";
    echo "<li>✅ <strong>Reaplicação automática</strong> após 1s e 3s</li>";
    echo "<li>✅ <strong>MutationObserver</strong> para detectar mudanças no DOM</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<a href='one.php' class='btn' style='font-size:20px;padding:20px 40px'>🎤 TESTAR AGORA!</a>";
    echo "<br><br>";
    echo "<a href='one_voice.php' class='btn' style='background:#6366f1'>🎙️ Testar Voice direto</a>";
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h3>🧪 Teste no Console (F12)</h3>";
    echo "<p>Após abrir o one.php, digite no console:</p>";
    echo "<pre>console.log(voiceBtnClick.toString())</pre>";
    echo "<p>Se mostrar <code>window.location.href = \"one_voice.php\"</code> está funcionando!</p>";
    echo "</div>";
    
} else {
    echo "<div class='box'>";
    echo "<h2>🎯 O que este fix faz?</h2>";
    echo "<p style='margin:15px 0'>Baseado na análise da Claude AI, este fix:</p>";
    echo "<ol style='margin-left:20px;line-height:2'>";
    echo "<li>Cria um <strong>namespace protegido</strong> <code>window.ONE</code></li>";
    echo "<li>Define a função com <strong>Object.defineProperty</strong> (não pode ser sobrescrita)</li>";
    echo "<li>Usa <strong>addEventListener com capture=true</strong> (prioridade máxima)</li>";
    echo "<li><strong>Remove o onclick antigo</strong> do botão</li>";
    echo "<li>Usa <strong>MutationObserver</strong> para detectar se algo muda o botão</li>";
    echo "<li><strong>Reaplica automaticamente</strong> após 1s e 3s</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h3>📝 Código que será injetado:</h3>";
    echo "<pre>" . htmlspecialchars($fixCode) . "</pre>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<a href='?action=aplicar' class='btn' style='font-size:18px;padding:16px 32px'>🚀 APLICAR FIX DEFINITIVO</a>";
    echo "</div>";
}

echo "</body></html>";
