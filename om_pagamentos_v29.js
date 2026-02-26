/**
 * OneMundo Pagamentos v29
 * - Stripe para Cartão + Apple Pay + Google Pay
 * - Pagar.me para PIX
 * - Tokenização de cartão
 * - Suporte a cobranças adicionais
 */
(function(){
"use strict";

if(window._OMv29_){return}
window._OMv29_=true;

// APIs
var API_PIX="/api_pagarme.php";
var API_STRIPE="/api_stripe.php";
var API_COBRANCA="/api/pagamento/cobranca_adicional.php";

var TIMEOUT_MINUTES=5;
var STORAGE_KEY="om_payment_state_v29";

// Estado
var S={
    paid:false,
    method:null,
    chargeId:null,
    paymentIntentId:null,
    paidAt:null,
    intervals:{},
    split:{},
    savedCards:[],
    total:0,
    customerId:null,
    stripeCustomerId:null,
    paymentMethodId:null,
    walletAvailable:{applePay:false,googlePay:false}
};

// Stripe
var stripe=null;
var elements=null;
var cardElement=null;

var $=function(s){return document.querySelector(s)};
var $$=function(s){return document.querySelectorAll(s)};

// ══════════════════════════════════════════════════════════════════
// INICIALIZAÇÃO STRIPE
// ══════════════════════════════════════════════════════════════════
async function initStripe(){
    try{
        var res=await fetch(API_STRIPE+"?action=get_config");
        var cfg=await res.json();
        if(cfg.success&&cfg.publishable_key){
            stripe=Stripe(cfg.publishable_key);
            elements=stripe.elements({locale:'pt-BR'});
            console.log("Stripe inicializado");

            // Verificar Apple Pay / Google Pay
            checkWalletAvailability();
        }
    }catch(e){
        console.error("Erro ao inicializar Stripe:",e);
    }
}

async function checkWalletAvailability(){
    if(!stripe)return;
    try{
        var paymentRequest=stripe.paymentRequest({
            country:'BR',currency:'brl',
            total:{label:'Total',amount:1000},
            requestPayerName:true,requestPayerEmail:true
        });
        var result=await paymentRequest.canMakePayment();
        S.walletAvailable={
            applePay:result?.applePay||false,
            googlePay:result?.googlePay||false
        };
        console.log("Wallets:",S.walletAvailable);
    }catch(e){}
}

// ══════════════════════════════════════════════════════════════════
// PERSISTÊNCIA
// ══════════════════════════════════════════════════════════════════
function saveState(){
    try{sessionStorage.setItem(STORAGE_KEY,JSON.stringify({
        paid:S.paid,method:S.method,chargeId:S.chargeId,paymentIntentId:S.paymentIntentId,
        paidAt:S.paidAt,total:S.total,stripeCustomerId:S.stripeCustomerId,paymentMethodId:S.paymentMethodId
    }))}catch(e){}
}

function loadState(){
    try{
        var saved=sessionStorage.getItem(STORAGE_KEY);
        if(saved){
            var data=JSON.parse(saved);
            if(data.paid&&data.paidAt){
                var elapsed=(Date.now()-data.paidAt)/1000;
                if(elapsed<TIMEOUT_MINUTES*60){
                    Object.assign(S,data);
                    return true;
                }else{
                    clearState();
                    return "expired";
                }
            }
        }
    }catch(e){}
    return false;
}

function clearState(){
    S.paid=false;S.method=null;S.chargeId=null;S.paymentIntentId=null;S.paidAt=null;
    try{sessionStorage.removeItem(STORAGE_KEY)}catch(e){}
}

// ══════════════════════════════════════════════════════════════════
// CSS
// ══════════════════════════════════════════════════════════════════
function addCSS(){
    if($("#om-v29-css"))return;
    var s=document.createElement("style");
    s.id="om-v29-css";
    s.textContent=`
@keyframes omFade{from{opacity:0}to{opacity:1}}
@keyframes omSlide{from{opacity:0;transform:translateY(30px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes omSpin{to{transform:rotate(360deg)}}
@keyframes omPulse{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.4)}50%{box-shadow:0 0 0 15px rgba(16,185,129,0)}}
@keyframes omBlink{0%,100%{opacity:1}50%{opacity:.5}}

.om-bg{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.85);z-index:9999999;display:flex;align-items:center;justify-content:center;padding:16px;animation:omFade .2s}
.om-modal{background:#fff;border-radius:20px;width:100%;max-width:440px;max-height:90vh;overflow:hidden;animation:omSlide .3s;box-shadow:0 25px 80px rgba(0,0,0,.5)}
.om-hd{padding:20px 24px;display:flex;justify-content:space-between;align-items:center;color:#fff}
.om-hd h3{margin:0;font-size:18px;font-weight:600;display:flex;align-items:center;gap:10px}
.om-x{background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:18px;transition:all .2s;display:flex;align-items:center;justify-content:center}
.om-x:hover{background:rgba(255,255,255,.3);transform:scale(1.1)}
.om-bd{padding:24px;max-height:calc(90vh - 80px);overflow-y:auto}

.om-amt{text-align:center;padding:20px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-radius:16px;margin-bottom:20px}
.om-amt b{font-size:36px;color:#1e293b;display:block;font-weight:700}
.om-amt small{color:#64748b;font-size:13px;margin-top:6px;display:block}

.om-btn{width:100%;padding:16px;border:none;border-radius:12px;font-size:15px;font-weight:600;cursor:pointer;margin:8px 0;display:flex;align-items:center;justify-content:center;gap:10px;transition:all .2s}
.om-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 8px 25px rgba(0,0,0,.15)}
.om-btn:disabled{opacity:.5;cursor:not-allowed}

.om-pix{background:linear-gradient(135deg,#00D4AA,#00B894);color:#fff}
.om-crd{background:linear-gradient(135deg,#635BFF,#5046e5);color:#fff}
.om-apple{background:#000;color:#fff}
.om-google{background:#fff;color:#1f2937;border:2px solid #e2e8f0}
.om-ok{background:linear-gradient(135deg,#10B981,#059669);color:#fff}
.om-gry{background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0}
.om-danger{background:linear-gradient(135deg,#EF4444,#DC2626);color:#fff}

.om-inp{width:100%;padding:14px 16px;background:#f8fafc;border:2px solid #e2e8f0;border-radius:12px;color:#1e293b;font-size:15px;margin-bottom:12px;box-sizing:border-box;transition:all .2s}
.om-inp:focus{outline:none;border-color:#635BFF;background:#fff}
.om-inp::placeholder{color:#94a3b8}
.om-row{display:flex;gap:12px}.om-row>*{flex:1}

.om-stripe-card{padding:14px 16px;background:#f8fafc;border:2px solid #e2e8f0;border-radius:12px;margin-bottom:12px;transition:all .2s}
.om-stripe-card.StripeElement--focus{border-color:#635BFF;background:#fff}
.om-stripe-card.StripeElement--invalid{border-color:#EF4444}
.om-card-error{color:#EF4444;font-size:13px;margin:8px 0}

.om-qr{background:#f8fafc;padding:24px;border-radius:16px;text-align:center;margin-bottom:16px;border:2px dashed #e2e8f0}
.om-qr img{max-width:180px;display:block;margin:0 auto;border-radius:8px}

.om-code{background:#ecfdf5;padding:16px;border-radius:12px;font-family:monospace;font-size:11px;color:#059669;word-break:break-all;margin:16px 0;border:2px solid #d1fae5}

.om-timer{text-align:center;font-size:42px;font-weight:700;color:#1e293b;padding:20px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-radius:16px;margin:16px 0;font-family:monospace;letter-spacing:4px}
.om-timer.urg{color:#EF4444;background:linear-gradient(135deg,#fef2f2,#fee2e2);animation:omBlink 1s infinite}

.om-spin{width:40px;height:40px;border:4px solid #e2e8f0;border-top-color:#635BFF;border-radius:50%;animation:omSpin .6s linear infinite;margin:30px auto}

.om-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);padding:14px 28px;border-radius:50px;color:#fff;font-weight:600;z-index:99999999;animation:omSlide .25s}
.om-toast.ok{background:linear-gradient(135deg,#10B981,#059669)}
.om-toast.err{background:linear-gradient(135deg,#EF4444,#DC2626)}

.om-sec{margin-bottom:24px}
.om-sec h4{color:#1e293b;font-size:14px;margin:0 0 12px;font-weight:600}

.om-wallet-btns{display:flex;gap:12px;margin-bottom:16px}
.om-wallet-btns .om-btn{flex:1}

.om-card-item{background:#f8fafc;border:2px solid #e2e8f0;border-radius:14px;padding:16px;margin:10px 0;cursor:pointer;display:flex;align-items:center;gap:16px;transition:all .2s}
.om-card-item:hover{border-color:#c4b5fd}
.om-card-item.sel{border-color:#635BFF;background:#f5f3ff}
.om-card-item .ico{font-size:28px;width:45px;text-align:center}
.om-card-item .info{flex:1}
.om-card-item .num{color:#1e293b;font-weight:600;font-size:15px;font-family:monospace}
.om-card-item .brand{color:#64748b;font-size:12px;text-transform:uppercase}

.om-chk{display:flex;align-items:center;gap:12px;padding:14px;background:#f8fafc;border-radius:12px;cursor:pointer;margin:12px 0}
.om-chk input{width:20px;height:20px;accent-color:#635BFF}
.om-chk span{color:#475569;font-size:14px}

.om-success{text-align:center;padding:40px 20px}
.om-success .icon{font-size:80px;margin-bottom:16px}
.om-success h3{color:#059669;margin:0;font-size:22px}
.om-success p{color:#64748b;margin:8px 0 0;font-size:14px}

.om-expired{text-align:center;padding:40px 20px}
.om-expired .icon{font-size:60px;margin-bottom:16px}
.om-expired h3{color:#EF4444;margin:0 0 8px;font-size:20px}
.om-expired p{color:#64748b;margin:0 0 20px;font-size:14px}

.om-loading{text-align:center;padding:40px}
.om-loading p{color:#64748b;margin-top:16px}

/* Powered by Stripe badge */
.om-stripe-badge{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:16px;font-size:12px;color:#64748b}
.om-stripe-badge svg{height:20px}

body.om-checkout-bloqueado .product-quantity,
body.om-checkout-bloqueado .input-group,
body.om-checkout-bloqueado [class*="quantity"],
body.om-checkout-bloqueado button[data-update],
body.om-checkout-bloqueado .btn-minus,.om-checkout-bloqueado .btn-plus{
    pointer-events:none!important;opacity:.3!important;cursor:not-allowed!important;
}
`;
    document.head.appendChild(s);
}

// ══════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════
function toast(m,t){
    var o=$(".om-toast");if(o)o.remove();
    var d=document.createElement("div");
    d.className="om-toast "+(t||"ok");
    d.textContent=m;
    document.body.appendChild(d);
    setTimeout(function(){d.remove()},4000);
}

function modal(id,title,emoji,gradient,html){
    fechar(id);
    var bg=document.createElement("div");
    bg.id="om-"+id;
    bg.className="om-bg";
    bg.innerHTML='<div class="om-modal"><div class="om-hd" style="background:'+gradient+'"><h3>'+emoji+' '+title+'</h3><button class="om-x" onclick="OM29.fechar(\''+id+'\')">✕</button></div><div class="om-bd" id="om-c-'+id+'">'+html+'</div></div>';
    bg.onclick=function(e){if(e.target===bg&&!S.paid)fechar(id)};
    document.body.appendChild(bg);
}

function fechar(id){
    var m=$("#om-"+id);if(m)m.remove();
    if(S.intervals[id])clearInterval(S.intervals[id]);
    if(S.intervals[id+"c"])clearInterval(S.intervals[id+"c"]);
}

function fmt(v){return"R$ "+parseFloat(v).toFixed(2).replace(".",",")}

function getTotal(){
    try{
        var vue=$(".om-checkout-forms");
        if(vue&&vue.__vue__&&vue.__vue__.order_data){
            return parseFloat(vue.__vue__.order_data.total)||0;
        }
    }catch(e){}
    return 0;
}

function getCustomerData(){
    try{
        // Tentar pegar do Vue (checkout antigo)
        var vue=$(".om-checkout-forms");
        if(vue&&vue.__vue__){
            var o=vue.__vue__.order_data||{};
            return{
                name:((o.firstname||"")+" "+(o.lastname||"")).trim()||"Cliente",
                email:o.email||"",
                phone:(o.telephone||"").replace(/\D/g,""),
                cpf:(o.cpf||o.custom_field?.account?.cpf||"").replace(/\D/g,"")
            };
        }

        // Tentar pegar de inputs do checkout_novo.php
        var nome=($("[name='firstname']")||{}).value||"";
        var sobrenome=($("[name='lastname']")||{}).value||"";
        var email=($("[name='email']")||$("#customer-email")||{}).value||"";
        var phone=($("[name='telephone']")||$("#customer-phone")||{}).value||"";
        var cpf=($("[name='cpf']")||$("#customer-cpf")||{}).value||"";

        if(nome||email){
            return{
                name:(nome+" "+sobrenome).trim()||"Cliente",
                email:email,
                phone:phone.replace(/\D/g,""),
                cpf:cpf.replace(/\D/g,"")
            };
        }

        // Tentar pegar do sessionStorage (dados salvos anteriormente)
        var saved=sessionStorage.getItem("om_customer_data");
        if(saved){
            return JSON.parse(saved);
        }
    }catch(e){}
    return{name:"Cliente",email:"",phone:"",cpf:""};
}

// Salvar dados do cliente
function setCustomerData(data){
    try{
        sessionStorage.setItem("om_customer_data",JSON.stringify(data));
    }catch(e){}
}

// ══════════════════════════════════════════════════════════════════
// PIX (via Pagar.me)
// ══════════════════════════════════════════════════════════════════
async function abrirPix(amt){
    if(S.paid){toast("Pagamento já realizado!","ok");return}
    var total=amt||getTotal();
    if(total<=0){toast("Valor inválido","err");return}

    S.total=total;
    modal("pix","PIX","💠","linear-gradient(135deg,#00D4AA,#00B894)",'<div class="om-loading"><div class="om-spin"></div><p>Gerando QR Code...</p></div>');

    var cust=getCustomerData();

    try{
        // Usar CPF real ou gerar um placeholder válido
        var cpf=cust.cpf||"";
        if(cpf.length<11){
            // Gerar CPF válido de teste (11111111111 é aceito pela Pagar.me em sandbox)
            cpf="11111111111";
        }

        var res=await fetch(API_PIX+"?action=create_pix",{
            method:"POST",
            headers:{"Content-Type":"application/json"},
            body:JSON.stringify({
                amount:Math.round(total*100),
                customer_name:cust.name,
                customer_email:cust.email,
                customer_document:cpf,
                customer_phone:cust.phone||"11999999999"
            })
        });
        var j=await res.json();
        var box=$("#om-c-pix");if(!box)return;

        if(j.success&&(j.qr_code_url||j.qr_code)){
            var chg=j.charge_id||j.order_id,time=300;

            var html='<div class="om-amt"><b>'+fmt(total)+'</b><small>Pague via PIX</small></div>';
            if(j.qr_code_url)html+='<div class="om-qr"><img src="'+j.qr_code_url+'"></div>';
            html+='<div class="om-timer" id="t-pix">05:00</div>';
            if(j.qr_code){
                html+='<div class="om-code">'+j.qr_code+'</div>';
                html+='<button class="om-btn om-pix" onclick="navigator.clipboard.writeText(\''+j.qr_code+'\');OM29.toast(\'Código copiado!\')">📋 Copiar Código PIX</button>';
            }
            html+='<p style="text-align:center;color:#64748b;font-size:13px;margin-top:16px">⏳ Aguardando pagamento...</p>';
            box.innerHTML=html;

            // Timer
            S.intervals.pix=setInterval(function(){
                time--;
                var m=Math.floor(time/60);
                var s=time%60;
                var el=$("#t-pix");
                if(el){
                    el.textContent=String(m).padStart(2,"0")+":"+String(s).padStart(2,"0");
                    if(time<60)el.classList.add("urg");
                }
                if(time<=0){
                    clearInterval(S.intervals.pix);
                    clearInterval(S.intervals.pixc);
                    box.innerHTML='<div class="om-expired"><div class="icon">⏰</div><h3>PIX Expirado</h3><p>O tempo para pagamento acabou</p><button class="om-btn om-pix" onclick="OM29.fechar(\'pix\');OM29.abrirPix()">Gerar Novo PIX</button></div>';
                }
            },1000);

            // Check status
            S.intervals.pixc=setInterval(async function(){
                try{
                    var r=await fetch(API_PIX+"?action=check_pix&charge_id="+chg);
                    var x=await r.json();
                    if(x.paid){
                        clearInterval(S.intervals.pix);
                        clearInterval(S.intervals.pixc);
                        S.chargeId=chg;
                        pago("pix");
                        box.innerHTML='<div class="om-success"><div class="icon">✅</div><h3>PIX Confirmado!</h3><p>Clique em FINALIZAR PEDIDO</p></div>';
                        setTimeout(function(){fechar("pix")},2000);
                    }
                }catch(e){}
            },3000);
        }else{
            box.innerHTML='<div class="om-expired"><div class="icon">❌</div><h3>Erro</h3><p>'+(j.error||"Erro ao gerar PIX")+'</p></div>';
        }
    }catch(e){
        $("#om-c-pix").innerHTML='<div class="om-expired"><div class="icon">❌</div><h3>Erro</h3><p>'+e.message+'</p></div>';
    }
}

// ══════════════════════════════════════════════════════════════════
// CARTÃO (via Stripe)
// ══════════════════════════════════════════════════════════════════
async function abrirCartao(amt){
    if(S.paid){toast("Pagamento já realizado!","ok");return}
    var total=amt||getTotal();
    if(total<=0){toast("Valor inválido","err");return}

    if(!stripe){
        toast("Carregando Stripe...","err");
        await initStripe();
        if(!stripe){toast("Stripe não disponível","err");return}
    }

    S.total=total;

    // Buscar cartões salvos
    var cust=getCustomerData();
    var savedHtml="";

    // Verificar Apple Pay / Google Pay
    var walletHtml="";
    if(S.walletAvailable.applePay){
        walletHtml+='<button class="om-btn om-apple" onclick="OM29.payWallet(\'apple\')"> Apple Pay</button>';
    }
    if(S.walletAvailable.googlePay){
        walletHtml+='<button class="om-btn om-google" onclick="OM29.payWallet(\'google\')"><svg viewBox="0 0 24 24" width="20" height="20"><path fill="#4285F4" d="M12.24 10.285V14.4h6.806c-.275 1.765-2.056 5.174-6.806 5.174-4.095 0-7.439-3.389-7.439-7.574s3.345-7.574 7.439-7.574c2.33 0 3.891.989 4.785 1.849l3.254-3.138C18.189 1.186 15.479 0 12.24 0c-6.635 0-12 5.365-12 12s5.365 12 12 12c6.926 0 11.52-4.869 11.52-11.726 0-.788-.085-1.39-.189-1.989H12.24z"/></svg> Google Pay</button>';
    }
    if(walletHtml){
        walletHtml='<div class="om-wallet-btns">'+walletHtml+'</div><div style="text-align:center;color:#64748b;font-size:13px;margin:16px 0">ou pague com cartão</div>';
    }

    // Opções de parcelas
    var opts="";
    for(var i=1;i<=12;i++){
        opts+='<option value="'+i+'">'+i+'x de '+fmt(total/i)+(i===1?" (à vista)":"")+'</option>';
    }

    var html='<div class="om-amt"><b>'+fmt(total)+'</b></div>';
    html+=walletHtml;
    html+='<div class="om-sec">';
    html+='<h4>💳 Dados do Cartão</h4>';
    html+='<div id="stripe-card-element" class="om-stripe-card"></div>';
    html+='<div id="stripe-card-errors" class="om-card-error"></div>';
    html+='<select class="om-inp" id="stripe-installments">'+opts+'</select>';
    html+='<label class="om-chk"><input type="checkbox" id="stripe-save" checked><span>Salvar cartão para próximas compras</span></label>';
    html+='</div>';
    html+='<button class="om-btn om-crd" id="stripe-pay-btn" onclick="OM29.payCard()">💳 Pagar '+fmt(total)+'</button>';
    html+='<button class="om-btn om-gry" onclick="OM29.fechar(\'cartao\')">Cancelar</button>';
    html+='<div class="om-stripe-badge"><span>Powered by</span><svg viewBox="0 0 60 25" xmlns="http://www.w3.org/2000/svg" width="60" height="25"><path fill="#635BFF" d="M5.17 10.11c0-.59.48-.82 1.28-.82.73 0 1.65.22 2.38.61V7.68c-.8-.32-1.59-.44-2.38-.44-1.95 0-3.25 1.02-3.25 2.72 0 2.65 3.65 2.23 3.65 3.37 0 .7-.61.93-1.46.93-.84 0-1.93-.35-2.79-.82v2.26c.95.41 1.91.59 2.79.59 2 0 3.37-.99 3.37-2.71-.01-2.86-3.67-2.35-3.67-3.47zm6.1-2.94-.04 11.02h2.22V10.11l3.11 5.47h.03l3.1-5.48v8.09h2.25V7.17h-2.31l-2.97 5.39L13.7 7.17h-2.44zm12.2 0v11.02h2.22V7.17h-2.22zm3.98 0v11.02h2.22v-7.65l3.2 7.65h1.96V7.17h-2.22v7.54l-3.16-7.54h-2zm8.78 0v11.02h6.35v-2.02h-4.13v-2.49h4.09v-2.02h-4.09V9.19h4.13V7.17h-6.35z"/></svg></div>';

    modal("cartao","Cartão de Crédito","💳","linear-gradient(135deg,#635BFF,#5046e5)",html);

    // Montar Stripe Elements
    setTimeout(function(){
        if(!elements)return;
        cardElement=elements.create("card",{
            style:{
                base:{color:"#1e293b",fontFamily:"Inter, sans-serif",fontSize:"16px","::placeholder":{color:"#94a3b8"}},
                invalid:{color:"#ef4444"}
            },
            hidePostalCode:true
        });
        cardElement.mount("#stripe-card-element");
        cardElement.on("change",function(e){
            var err=$("#stripe-card-errors");
            if(err)err.textContent=e.error?e.error.message:"";
        });
    },100);
}

async function payCard(){
    if(!stripe||!cardElement){toast("Stripe não pronto","err");return}

    var btn=$("#stripe-pay-btn");
    btn.disabled=true;
    btn.innerHTML='<div class="om-spin" style="width:20px;height:20px;margin:0;border-width:2px"></div>';

    var cust=getCustomerData();
    var saveCard=$("#stripe-save")?.checked;

    try{
        // 1. Criar Payment Intent
        var res=await fetch(API_STRIPE,{
            method:"POST",
            headers:{"Content-Type":"application/json"},
            body:JSON.stringify({
                action:"create_payment_intent",
                amount:S.total,
                customer_email:cust.email,
                customer_name:cust.name
            })
        });
        var intent=await res.json();

        if(!intent.success){
            toast(intent.error||"Erro","err");
            btn.disabled=false;
            btn.innerHTML="💳 Pagar "+fmt(S.total);
            return;
        }

        // 2. Confirmar com cartão
        var result=await stripe.confirmCardPayment(intent.client_secret,{
            payment_method:{
                card:cardElement,
                billing_details:{name:cust.name,email:cust.email}
            },
            setup_future_usage:saveCard?"off_session":undefined
        });

        if(result.error){
            toast(result.error.message,"err");
            btn.disabled=false;
            btn.innerHTML="💳 Pagar "+fmt(S.total);
            return;
        }

        if(result.paymentIntent.status==="succeeded"){
            S.paymentIntentId=result.paymentIntent.id;

            // Se salvou o cartão, guardar referência
            if(saveCard&&result.paymentIntent.payment_method){
                S.paymentMethodId=result.paymentIntent.payment_method;
            }

            pago("cartao");
            $("#om-c-cartao").innerHTML='<div class="om-success"><div class="icon">✅</div><h3>Pagamento Aprovado!</h3><p>Clique em FINALIZAR PEDIDO</p></div>';
            setTimeout(function(){fechar("cartao")},2000);
        }else{
            toast("Status: "+result.paymentIntent.status,"err");
            btn.disabled=false;
            btn.innerHTML="💳 Pagar "+fmt(S.total);
        }

    }catch(e){
        toast(e.message,"err");
        btn.disabled=false;
        btn.innerHTML="💳 Pagar "+fmt(S.total);
    }
}

async function payWallet(type){
    if(!stripe){
        toast("Stripe não inicializado","err");
        return;
    }

    var total=S.total||getTotal();
    if(total<=0){
        toast("Valor inválido","err");
        return;
    }

    var cust=getCustomerData();

    // Criar Payment Request
    var paymentRequest=stripe.paymentRequest({
        country:'BR',
        currency:'brl',
        total:{
            label:'OneMundo',
            amount:Math.round(total*100)
        },
        requestPayerName:true,
        requestPayerEmail:true
    });

    // Verificar disponibilidade
    var canPay=await paymentRequest.canMakePayment();
    if(!canPay){
        toast(type+" Pay não disponível neste dispositivo","err");
        return;
    }

    // Mostrar loading
    modal("wallet",type+" Pay","💳","linear-gradient(135deg,#000,#333)",'<div class="om-loading"><div class="om-spin"></div><p>Preparando '+type+' Pay...</p></div>');

    try{
        // 1. Criar Payment Intent no backend
        var res=await fetch(API_STRIPE,{
            method:"POST",
            headers:{"Content-Type":"application/json"},
            body:JSON.stringify({
                action:"create_payment_intent",
                amount:total,
                customer_email:cust.email,
                customer_name:cust.name
            })
        });
        var intent=await res.json();

        if(!intent.success){
            fechar("wallet");
            toast(intent.error||"Erro ao criar pagamento","err");
            return;
        }

        S.clientSecret=intent.client_secret;

        // 2. Listener para quando o usuário autorizar o pagamento
        paymentRequest.on('paymentmethod',async function(ev){
            $("#om-c-wallet").innerHTML='<div class="om-loading"><div class="om-spin"></div><p>Processando pagamento...</p></div>';

            // Confirmar o pagamento com Stripe
            var confirmResult=await stripe.confirmCardPayment(
                S.clientSecret,
                {payment_method:ev.paymentMethod.id},
                {handleActions:false}
            );

            if(confirmResult.error){
                ev.complete('fail');
                fechar("wallet");
                toast(confirmResult.error.message,"err");
                return;
            }

            ev.complete('success');

            // Verificar se precisa de ação adicional (3D Secure)
            if(confirmResult.paymentIntent.status==='requires_action'){
                var actionResult=await stripe.confirmCardPayment(S.clientSecret);
                if(actionResult.error){
                    fechar("wallet");
                    toast(actionResult.error.message,"err");
                    return;
                }
            }

            // Sucesso!
            S.paymentIntentId=confirmResult.paymentIntent.id;
            pago("wallet_"+type.toLowerCase());

            $("#om-c-wallet").innerHTML='<div class="om-success"><div class="icon">✅</div><h3>Pagamento Aprovado!</h3><p>'+type+' Pay confirmado</p></div>';
            setTimeout(function(){fechar("wallet")},2000);
        });

        // 3. Listener para cancelamento
        paymentRequest.on('cancel',function(){
            fechar("wallet");
            toast("Pagamento cancelado","err");
        });

        // 4. Mostrar o modal do Apple Pay / Google Pay
        fechar("wallet");
        paymentRequest.show();

    }catch(e){
        fechar("wallet");
        toast("Erro: "+e.message,"err");
    }
}

// ══════════════════════════════════════════════════════════════════
// FINALIZAÇÃO
// ══════════════════════════════════════════════════════════════════
var _pagamentoLock=false;

function pago(m){
    if(_pagamentoLock||S.paid)return;
    _pagamentoLock=true;
    S.paid=true;
    S.method=m;
    S.paidAt=Date.now();
    saveState();
    bloquearInterface();
    toast("✓ Pagamento confirmado! Finalize o pedido.","ok");
}

function bloquearInterface(){
    document.body.classList.add("om-checkout-bloqueado");
    var btn=$(".om-summary-cta button");
    if(btn){
        btn.disabled=false;
        btn.style.cssText="background:linear-gradient(135deg,#10B981,#059669)!important;color:#fff!important";
        btn.innerHTML="✓ FINALIZAR PEDIDO";
    }
}

// ══════════════════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════════════════
function init(){
    addCSS();
    initStripe();

    var estado=loadState();
    if(estado===true){
        bloquearInterface();
        toast("Pagamento pendente de finalização","ok");
    }

    // Interceptar cliques em métodos de pagamento
    document.addEventListener("click",function(e){
        if($(".om-bg"))return;
        var el=e.target.closest(".payment-option,[data-method]");
        if(!el)return;

        var method=el.dataset.method||(el.textContent||"").toLowerCase();
        if(method.indexOf("pix")>-1){
            e.preventDefault();e.stopPropagation();
            abrirPix();
            return false;
        }
        if(method.indexOf("card")>-1||method.indexOf("cartao")>-1||method.indexOf("crédito")>-1){
            e.preventDefault();e.stopPropagation();
            abrirCartao();
            return false;
        }
    },true);
}

if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init)}else{init()}

window.OM29={
    // Pagamentos
    abrirPix:abrirPix,
    abrirCartao:abrirCartao,
    payCard:payCard,
    payWallet:payWallet,

    // UI
    fechar:fechar,
    toast:toast,

    // Dados
    getTotal:getTotal,
    getCustomerData:getCustomerData,
    setCustomerData:setCustomerData,

    // Estado
    isPaid:function(){return S.paid},
    getState:function(){return{...S}},
    clearState:clearState,

    // Stripe
    initStripe:initStripe,
    isWalletAvailable:function(){return S.walletAvailable}
};

})();
