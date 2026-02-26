<?php
/**
 * FIX URGENTE - SESSÃO E CÓDIGO CORROMPIDO
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

echo "<html><head><meta charset='UTF-8'><title>Fix Urgente</title>";
echo "<style>
body { font-family: Arial, sans-serif; background: #1a1a2e; color: #fff; padding: 20px; max-width: 1000px; margin: 0 auto; }
h1, h2 { color: #00d4aa; }
.box { background: #16213e; padding: 20px; border-radius: 10px; margin: 15px 0; }
.ok { color: #00d4aa; }
.erro { color: #ff6b6b; }
.aviso { color: #ffc107; }
pre { background: #0a0a15; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 11px; max-height: 300px; overflow-y: auto; }
.btn { display: inline-block; padding: 15px 30px; background: #00d4aa; color: #000; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 10px 5px; }
</style></head><body>";

echo "<h1>🚨 Fix Urgente - Sessão e Código</h1>";

$baseDir = __DIR__;

// ═══════════════════════════════════════════════════════════════════════════
// 1. DIAGNÓSTICO
// ═══════════════════════════════════════════════════════════════════════════
echo "<div class='box'>";
echo "<h2>1️⃣ Problemas Identificados</h2>";
echo "<ul>";
echo "<li class='erro'>❌ Modal usando sessão PHPSESSID (deveria ser OCSESSID)</li>";
echo "<li class='erro'>❌ Código corrompido no index.php: <code>\" . intval( ?: 0) . \"</code></li>";
echo "</ul>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════
// 2. VERIFICAR INDEX.PHP
// ═══════════════════════════════════════════════════════════════════════════
echo "<div class='box'>";
echo "<h2>2️⃣ Verificar Código Corrompido no index.php</h2>";

$indexPath = $baseDir . '/index.php';
$indexContent = file_get_contents($indexPath);

// Procurar código corrompido
$corrupcoes = [];
if (strpos($indexContent, '" . intval(') !== false) {
    preg_match_all('/" \. intval\([^)]*\) \. "/', $indexContent, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as $m) {
        $linha = substr_count(substr($indexContent, 0, $m[1]), "\n") + 1;
        $corrupcoes[] = ['linha' => $linha, 'codigo' => $m[0]];
    }
}

if (count($corrupcoes) > 0) {
    echo "<p class='erro'>❌ Encontradas " . count($corrupcoes) . " corruções:</p>";
    echo "<ul>";
    foreach ($corrupcoes as $c) {
        echo "<li>Linha {$c['linha']}: <code>" . htmlspecialchars($c['codigo']) . "</code></li>";
    }
    echo "</ul>";
} else {
    echo "<p class='ok'>✅ Nenhuma corrupção óbvia encontrada</p>";
}

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════
// 3. VERIFICAR BACKUPS
// ═══════════════════════════════════════════════════════════════════════════
echo "<div class='box'>";
echo "<h2>3️⃣ Backups Disponíveis</h2>";

$backups = glob($baseDir . '/index.php.backup*');
rsort($backups); // mais recente primeiro

if (count($backups) > 0) {
    echo "<table style='width:100%;border-collapse:collapse'>";
    echo "<tr style='background:#0a0a15'><th style='padding:10px;text-align:left'>Arquivo</th><th>Tamanho</th><th>Data</th><th>Ação</th></tr>";
    
    foreach (array_slice($backups, 0, 10) as $backup) {
        $nome = basename($backup);
        $tamanho = number_format(filesize($backup) / 1024, 1) . ' KB';
        $data = date('d/m/Y H:i', filemtime($backup));
        
        // Verificar se tem corrupção
        $backupContent = file_get_contents($backup);
        $temCorrupcao = strpos($backupContent, '" . intval(') !== false;
        $status = $temCorrupcao ? "<span class='erro'>Corrompido</span>" : "<span class='ok'>OK</span>";
        
        echo "<tr style='border-bottom:1px solid #333'>";
        echo "<td style='padding:8px'>$nome</td>";
        echo "<td style='padding:8px'>$tamanho</td>";
        echo "<td style='padding:8px'>$data</td>";
        echo "<td style='padding:8px'>$status";
        if (!$temCorrupcao) {
            echo " <a href='?restaurar=" . urlencode($nome) . "' style='color:#00d4aa'>[Restaurar]</a>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='aviso'>⚠️ Nenhum backup encontrado</p>";
}

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════
// 4. RESTAURAR BACKUP
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['restaurar'])) {
    echo "<div class='box' style='border:2px solid #00d4aa'>";
    echo "<h2>🔄 Restaurando Backup</h2>";
    
    $backupNome = $_GET['restaurar'];
    $backupPath = $baseDir . '/' . $backupNome;
    
    if (file_exists($backupPath) && strpos($backupNome, 'index.php.backup') === 0) {
        // Fazer backup do atual antes de restaurar
        $novoBackup = $indexPath . '.pre_restauracao_' . date('YmdHis');
        copy($indexPath, $novoBackup);
        echo "<p class='ok'>✅ Backup do atual: " . basename($novoBackup) . "</p>";
        
        // Restaurar
        copy($backupPath, $indexPath);
        echo "<p class='ok'>✅ Restaurado: $backupNome</p>";
        
        echo "<p><strong>Agora precisa re-adicionar o include do modal após &lt;body&gt;</strong></p>";
        echo "<p><a href='?adicionar_modal=1' class='btn'>Adicionar Include do Modal</a></p>";
    } else {
        echo "<p class='erro'>❌ Backup inválido</p>";
    }
    
    echo "</div>";
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. ADICIONAR INCLUDE DO MODAL
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['adicionar_modal'])) {
    echo "<div class='box' style='border:2px solid #00d4aa'>";
    echo "<h2>➕ Adicionando Include do Modal</h2>";
    
    $indexContent = file_get_contents($indexPath);
    
    // Verificar se já tem
    if (strpos($indexContent, 'modal_verificar_cep') !== false) {
        echo "<p class='aviso'>⚠️ Include do modal já existe</p>";
    } else {
        // Adicionar após <body>
        $indexContent = preg_replace(
            '/(<body[^>]*>)/i',
            "$1\n<?php include __DIR__ . '/components/modal_verificar_cep.php'; ?>",
            $indexContent,
            1,
            $count
        );
        
        if ($count > 0) {
            file_put_contents($indexPath, $indexContent);
            echo "<p class='ok'>✅ Include adicionado após &lt;body&gt;</p>";
        } else {
            echo "<p class='erro'>❌ Não encontrou tag &lt;body&gt;</p>";
        }
    }
    
    echo "</div>";
}

// ═══════════════════════════════════════════════════════════════════════════
// 6. CRIAR MODAL LIMPO
// ═══════════════════════════════════════════════════════════════════════════
echo "<div class='box'>";
echo "<h2>4️⃣ Criar Modal Limpo</h2>";

if (isset($_GET['criar_modal'])) {
    $modalPath = $baseDir . '/components/modal_verificar_cep.php';
    
    $modalCode = '<?php
/**
 * MODAL DE VERIFICAÇÃO DE CEP - LIMPO
 */

// IMPORTANTE: Usar sessão OCSESSID do OpenCart
if (session_status() === PHP_SESSION_NONE) {
    session_name(\'OCSESSID\');
    session_start();
}

// Verificar se já tem mercado
$partner_id = $_SESSION[\'market_partner_id\'] ?? null;
$mercado_id = $_SESSION[\'mercado_proximo\'][\'partner_id\'] ?? null;

$tem_mercado = (intval($partner_id) > 0 || intval($mercado_id) > 0);

// Se tem mercado, não mostra modal
if ($tem_mercado) {
    return;
}
?>
<style>
.om-modal-bg{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.85);z-index:99999;display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:.3s}
.om-modal-bg.show{opacity:1;visibility:visible}
.om-modal{background:#fff;border-radius:16px;max-width:380px;width:90%;overflow:hidden}
.om-modal-head{background:linear-gradient(135deg,#00d4aa,#00b894);padding:25px;text-align:center}
.om-modal-head h2{color:#fff;margin:0 0 5px;font-size:18px}
.om-modal-head p{color:rgba(255,255,255,.9);margin:0;font-size:13px}
.om-modal-body{padding:20px}
.om-modal input{width:100%;padding:12px;font-size:18px;border:2px solid #ddd;border-radius:8px;text-align:center;margin-bottom:10px;box-sizing:border-box}
.om-modal input:focus{border-color:#00d4aa;outline:none}
.om-modal button{width:100%;padding:12px;background:#00d4aa;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:bold;cursor:pointer}
.om-modal button:disabled{background:#ccc}
.om-modal-erro{color:#c00;text-align:center;margin-bottom:10px;font-size:13px;display:none}
.om-modal-erro.show{display:block}
</style>
<div class="om-modal-bg" id="omModalBg">
<div class="om-modal">
<div class="om-modal-head">
<h2>📍 Verificar disponibilidade</h2>
<p>Digite seu CEP</p>
</div>
<div class="om-modal-body">
<input type="text" id="omCepInput" placeholder="00000-000" maxlength="9">
<div class="om-modal-erro" id="omErro"></div>
<button id="omBtn" onclick="omVerificar()">Verificar</button>
</div>
</div>
</div>
<script>
document.getElementById("omCepInput").addEventListener("input",function(e){var v=e.target.value.replace(/\D/g,"");if(v.length>5)v=v.substr(0,5)+"-"+v.substr(5,3);e.target.value=v});
function omAbrir(){document.getElementById("omModalBg").classList.add("show");document.getElementById("omCepInput").focus()}
async function omVerificar(){
var cep=document.getElementById("omCepInput").value.replace(/\D/g,"");
if(cep.length!==8){document.getElementById("omErro").textContent="CEP inválido";document.getElementById("omErro").classList.add("show");return}
document.getElementById("omBtn").disabled=true;document.getElementById("omBtn").textContent="Aguarde...";
try{var r=await fetch("/mercado/api/localizacao.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"verificar_cep",cep:cep})});var d=await r.json();if(d.success&&d.disponivel){location.reload()}else{document.getElementById("omErro").textContent="Região não atendida";document.getElementById("omErro").classList.add("show");document.getElementById("omBtn").disabled=false;document.getElementById("omBtn").textContent="Verificar"}}catch(e){document.getElementById("omBtn").disabled=false;document.getElementById("omBtn").textContent="Verificar"}
}
setTimeout(omAbrir,500);
</script>';

    file_put_contents($modalPath, $modalCode);
    echo "<p class='ok'>✅ Modal limpo criado!</p>";
} else {
    echo "<p><a href='?criar_modal=1' class='btn'>🔧 Criar Modal Limpo</a></p>";
}

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════
// LINKS
// ═══════════════════════════════════════════════════════════════════════════
echo "<div class='box' style='text-align:center'>";
echo "<p><a href='/mercado/' target='_blank' class='btn'>🛒 Testar Mercado</a></p>";
echo "<p><a href='diagnostico_pos_fix.php' class='btn'>📋 Diagnóstico</a></p>";
echo "</div>";

echo "</body></html>";
