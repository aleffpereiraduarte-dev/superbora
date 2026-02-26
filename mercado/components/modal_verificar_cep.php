<?php
/**
 * MODAL DE VERIFICAÇÃO DE CEP - LIMPO
 */

// IMPORTANTE: Usar sessão OCSESSID do OpenCart
if (session_status() === PHP_SESSION_NONE) {
    session_name('OCSESSID');
    session_start();
}

// Verificar se já tem mercado
$partner_id = $_SESSION['market_partner_id'] ?? null;
$mercado_id = $_SESSION['mercado_proximo']['partner_id'] ?? null;

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
<input type="text" id="omCepInput" placeholder="00000-000" maxlength="9" inputmode="numeric">
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
</script>