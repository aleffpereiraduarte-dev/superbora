<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Corrigindo Widget...</h1>";

$widgetPath = __DIR__ . '/components/mercado-banner-widget-js.php';

// Novo conteudo do widget (sem PHP, so HTML/CSS/JS)
$novoConteudo = '<!-- Widget OneMundo Mercado - Inteligente v2 -->
<style>
#om-mercado-banner{position:fixed;bottom:20px;right:20px;z-index:99999;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;background:linear-gradient(135deg,#00AA5B 0%,#008547 100%);color:#fff;padding:16px 20px;border-radius:16px;box-shadow:0 4px 20px rgba(0,170,91,0.4);max-width:300px;display:none;animation:omSlide 0.5s ease}
@keyframes omSlide{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
#om-mercado-banner.show{display:block}
.om-content{display:flex;align-items:center;gap:12px}
.om-icon{font-size:36px;line-height:1}
.om-text{flex:1}
.om-title{font-size:15px;font-weight:700;margin-bottom:2px}
.om-tempo{font-size:22px;font-weight:800;color:#FFB800}
.om-cidade{font-size:12px;opacity:0.9;margin-top:2px}
.om-close{position:absolute;top:8px;right:10px;background:none;border:none;color:#fff;font-size:18px;cursor:pointer;opacity:0.7;padding:0}
.om-close:hover{opacity:1}
.om-btn{display:block;width:100%;margin-top:12px;padding:12px;background:#FFB800;color:#333;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px;text-align:center}
.om-btn:hover{background:#ffc933;color:#333}
@media(max-width:480px){#om-mercado-banner{left:15px;right:15px;bottom:15px;max-width:100%}}
</style>

<div id="om-mercado-banner">
    <button class="om-close" onclick="this.parentElement.style.display=\'none\';localStorage.setItem(\'om_widget_closed\',new Date().toDateString())">x</button>
    <div class="om-content">
        <span class="om-icon">🛒</span>
        <div class="om-text">
            <div class="om-title">Mercado em</div>
            <div class="om-tempo" id="om-tempo">-- min</div>
            <div class="om-cidade" id="om-cidade"></div>
        </div>
    </div>
    <a href="/mercado/" class="om-btn">🛒 Ir para o Mercado</a>
</div>

<script>
(function(){
    var banner = document.getElementById("om-mercado-banner");
    if(!banner) return;
    
    if(localStorage.getItem("om_widget_closed") === new Date().toDateString()) return;
    
    function detectarCEP(){
        var cep = localStorage.getItem("om_cep");
        if(cep && cep.length >= 8) return cep.replace(/\\D/g,"");
        
        var html = document.body.innerText || "";
        var match = html.match(/(\\d{5})-(\\d{3})/);
        if(match) return match[1] + match[2];
        
        return null;
    }
    
    function verificar(cep){
        var xhr = new XMLHttpRequest();
        xhr.open("GET", "/mercado/api/localizacao.php?action=verificar_cep&cep=" + cep);
        xhr.onload = function(){
            try {
                var data = JSON.parse(xhr.responseText);
                if(data && data.disponivel){
                    document.getElementById("om-tempo").textContent = data.mercado.tempo_estimado + " min";
                    document.getElementById("om-cidade").textContent = "📍 " + data.localizacao.cidade;
                    banner.classList.add("show");
                    localStorage.setItem("om_cep", cep);
                }
            } catch(e){ console.log("Widget error:", e); }
        };
        xhr.send();
    }
    
    function init(){
        var cep = detectarCEP();
        if(cep) verificar(cep);
    }
    
    if(document.readyState === "loading"){
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
    
    setTimeout(init, 2000);
})();
</script>';

// Criar backup
if (file_exists($widgetPath)) {
    copy($widgetPath, $widgetPath . '.bak');
    echo "<p>✅ Backup criado</p>";
}

// Salvar novo conteudo
if (file_put_contents($widgetPath, $novoConteudo)) {
    echo "<p style='color:green;font-size:20px;'>✅ Widget corrigido com sucesso!</p>";
    echo "<p>Tamanho: " . strlen($novoConteudo) . " bytes</p>";
} else {
    echo "<p style='color:red;'>❌ Erro ao salvar</p>";
}

echo "<h2>Teste do Widget:</h2>";
echo "<p><a href='/mercado/components/mercado-banner-widget-js.php' target='_blank'>Clique aqui para testar o widget</a></p>";

echo "<h2>Próximos passos:</h2>";
echo "<ol>";
echo "<li>Limpe o cache do Journal3</li>";
echo "<li>Limpe o cache do navegador (Ctrl+Shift+R)</li>";
echo "<li>Acesse a página principal</li>";
echo "</ol>";
