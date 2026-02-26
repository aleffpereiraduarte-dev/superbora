<?php
/**
 * CHECKOUT UI COMPONENTS - ONEMUNDO MERCADO
 * Gerado em 2025-12-23 05:24:25
 */
$_cart = $_SESSION["market_cart"] ?? [];
$_cartCount = 0; $_cartTotal = 0;
foreach ($_cart as $_item) {
    $_cartCount += $_item["qty"];
    $_price = $_item["price_promo"] > 0 ? $_item["price_promo"] : $_item["price"];
    $_cartTotal += $_price * $_item["qty"];
}
$_frete_minimo = $membership_frete_minimo ?? 80;
$_frete_gratis = $_cartTotal >= $_frete_minimo;
$_falta_frete = max(0, $_frete_minimo - $_cartTotal);
$_frete_percent = $_frete_minimo > 0 ? min(100, ($_cartTotal / $_frete_minimo) * 100) : 100;
$_frete_valor = 9.90;
?>
<style>
.om-sticky-bar{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #e5e7eb;padding:12px 16px;padding-bottom:calc(12px + env(safe-area-inset-bottom));z-index:9999;box-shadow:0 -4px 20px rgba(0,0,0,0.12);transform:translateY(100%);transition:all .35s cubic-bezier(0.4,0,0.2,1)}
.om-sticky-bar.visible{transform:translateY(0)}
.om-sticky-bar-inner{max-width:500px;margin:0 auto}
.om-frete-progress{margin-bottom:12px}
.om-frete-info{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;font-size:12px}
.om-frete-label{color:#6b7280}.om-frete-label b{color:#10b981}
.om-frete-value{font-weight:600;color:#10b981}.om-frete-value.complete{color:#22c55e;font-weight:700}
.om-frete-bar{height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden}
.om-frete-fill{height:100%;background:linear-gradient(90deg,#10b981,#22c55e);border-radius:3px;transition:width .5s ease}
.om-sticky-cart{display:flex;align-items:center;gap:12px}
.om-cart-summary{flex:1;display:flex;align-items:center;gap:12px;background:#f3f4f6;padding:10px 14px;border-radius:14px;cursor:pointer;transition:all .2s ease}
.om-cart-summary:active{transform:scale(0.98);background:#e5e7eb}
.om-cart-icon-wrap{position:relative;width:46px;height:46px;background:#fff;border-radius:13px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 10px rgba(0,0,0,0.08)}
.om-cart-icon-wrap svg{width:24px;height:24px;color:#10b981}
.om-cart-badge{position:absolute;top:-5px;right:-5px;min-width:22px;height:22px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;font-size:11px;font-weight:700;border-radius:11px;display:flex;align-items:center;justify-content:center;padding:0 6px;box-shadow:0 2px 6px rgba(239,68,68,0.4);animation:pulse-badge 2s infinite}
@keyframes pulse-badge{0%,100%{transform:scale(1)}50%{transform:scale(1.1)}}
.om-cart-info{flex:1}.om-cart-items-text{font-size:13px;color:#6b7280;margin-bottom:2px}
.om-cart-total{font-size:20px;font-weight:800;color:#111827;letter-spacing:-0.5px}
.om-btn-checkout{display:flex;align-items:center;justify-content:center;gap:8px;padding:16px 28px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-size:15px;font-weight:700;border:none;border-radius:14px;cursor:pointer;transition:all .2s ease;white-space:nowrap;box-shadow:0 4px 15px rgba(16,185,129,0.4);text-transform:uppercase;letter-spacing:0.5px}
.om-btn-checkout:active{transform:scale(0.96)}.om-btn-checkout:disabled{background:#d1d5db;box-shadow:none;cursor:not-allowed}
.om-btn-checkout svg{width:20px;height:20px}
.om-btn-checkout.pulse{animation:pulse-btn 2s infinite}
@keyframes pulse-btn{0%,100%{box-shadow:0 4px 15px rgba(16,185,129,0.4)}50%{box-shadow:0 6px 25px rgba(16,185,129,0.6)}}
.om-cart-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10000;opacity:0;visibility:hidden;transition:all .3s ease}
.om-cart-overlay.open{opacity:1;visibility:visible}
.om-cart-drawer{position:fixed;top:0;right:0;width:100%;max-width:420px;height:100dvh;background:#fff;z-index:10001;transform:translateX(100%);transition:transform .4s cubic-bezier(0.4,0,0.2,1);display:flex;flex-direction:column;box-shadow:-10px 0 40px rgba(0,0,0,0.15)}
.om-cart-drawer.open{transform:translateX(0)}
.om-drawer-header{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid #e5e7eb;background:linear-gradient(135deg,#f9fafb,#f3f4f6)}
.om-drawer-title{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:800;color:#111827}
.om-drawer-title svg{width:24px;height:24px;color:#10b981}
.om-drawer-count{background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:3px 10px;border-radius:12px;font-size:13px;font-weight:700}
.om-drawer-close{width:42px;height:42px;display:flex;align-items:center;justify-content:center;background:#fff;border:none;border-radius:12px;cursor:pointer}
.om-drawer-close:active{background:#fee2e2;color:#ef4444}
.om-drawer-frete{padding:16px 20px;background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-bottom:1px solid #6ee7b7}
.om-drawer-frete.complete{background:linear-gradient(135deg,#dcfce7,#bbf7d0)}
.om-drawer-frete-row{display:flex;align-items:center;gap:10px}
.om-drawer-frete-icon{font-size:24px}
.om-drawer-frete-text{flex:1;font-size:14px;font-weight:600;color:#065f46}
.om-drawer-frete-bar{height:8px;background:rgba(255,255,255,0.7);border-radius:4px;overflow:hidden;margin-top:12px}
.om-drawer-frete-fill{height:100%;background:linear-gradient(90deg,#10b981,#22c55e);border-radius:4px;transition:width .5s ease}
.om-drawer-items{flex:1;overflow-y:auto;padding:16px 20px}
.om-drawer-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;text-align:center;padding:40px}
.om-drawer-empty-icon{width:90px;height:90px;background:#f3f4f6;border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:24px}
.om-drawer-empty-icon svg{width:45px;height:45px;color:#9ca3af}
.om-drawer-empty h3{font-size:20px;font-weight:700;color:#111827;margin-bottom:8px}
.om-drawer-empty p{font-size:15px;color:#6b7280}
.om-cart-item{display:flex;gap:14px;padding:16px 0;border-bottom:1px solid #f3f4f6;animation:slideIn .3s ease}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
.om-cart-item-img{width:75px;height:75px;background:#f9fafb;border-radius:14px;overflow:hidden;flex-shrink:0;border:1px solid #e5e7eb}
.om-cart-item-img img{width:100%;height:100%;object-fit:contain;padding:6px}
.om-cart-item-info{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:space-between}
.om-cart-item-name{font-size:14px;font-weight:600;color:#111827;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.om-cart-item-brand{font-size:12px;color:#9ca3af;margin-top:2px}
.om-cart-item-bottom{display:flex;align-items:center;justify-content:space-between;margin-top:8px}
.om-cart-item-price{font-size:17px;font-weight:800;color:#10b981}
.om-cart-item-price small{font-size:12px;color:#9ca3af;font-weight:500;text-decoration:line-through;display:block}
.om-qty-controls{display:flex;align-items:center;background:#f3f4f6;border-radius:12px;overflow:hidden}
.om-qty-btn{width:36px;height:36px;display:flex;align-items:center;justify-content:center;background:transparent;border:none;cursor:pointer;color:#374151;font-size:18px;font-weight:700}
.om-qty-btn:active{background:#e5e7eb}
.om-qty-btn.minus:active{background:#fee2e2;color:#ef4444}
.om-qty-btn.plus:active{background:#d1fae5;color:#10b981}
.om-qty-value{width:36px;text-align:center;font-size:15px;font-weight:700;color:#111827}
.om-drawer-footer{padding:18px 20px;padding-bottom:calc(18px + env(safe-area-inset-bottom));border-top:1px solid #e5e7eb;background:#fff}
.om-coupon-box{display:flex;gap:8px;margin-bottom:18px}
.om-coupon-box input{flex:1;padding:14px 16px;border:2px solid #e5e7eb;border-radius:12px;font-size:14px}
.om-coupon-box input:focus{outline:none;border-color:#10b981}
.om-coupon-box button{padding:14px 22px;background:#111827;color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer}
.om-drawer-totals{margin-bottom:18px}
.om-drawer-total-row{display:flex;justify-content:space-between;padding:10px 0;font-size:14px;color:#6b7280}
.om-drawer-total-row.shipping span:last-child.free{color:#22c55e;font-weight:700}
.om-drawer-total-row.total{font-size:20px;font-weight:800;color:#111827;padding-top:14px;border-top:2px dashed #e5e7eb;margin-top:8px}
.om-drawer-checkout{width:100%;padding:18px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-size:17px;font-weight:800;border:none;border-radius:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;box-shadow:0 6px 20px rgba(16,185,129,0.35)}
.om-drawer-checkout:active{transform:scale(0.98)}
.om-drawer-checkout:disabled{background:#d1d5db;box-shadow:none}
.om-toast{position:fixed;bottom:120px;left:50%;transform:translateX(-50%) translateY(100px);background:#111827;color:#fff;padding:14px 26px;border-radius:50px;font-size:14px;font-weight:600;display:flex;align-items:center;gap:10px;z-index:11000;opacity:0;transition:all .4s ease;box-shadow:0 10px 40px rgba(0,0,0,0.3)}
.om-toast.show{transform:translateX(-50%) translateY(0);opacity:1}
.om-toast-icon{width:26px;height:26px;background:#22c55e;border-radius:50%;display:flex;align-items:center;justify-content:center}
.om-toast-icon svg{width:14px;height:14px;color:#fff}
.om-cart-shake{animation:cartShake .5s ease}
@keyframes cartShake{0%,100%{transform:rotate(0)}20%{transform:rotate(-8deg)}40%{transform:rotate(8deg)}60%{transform:rotate(-5deg)}80%{transform:rotate(5deg)}}
body{padding-bottom:110px!important}
</style>
<div class="om-cart-overlay" id="omCartOverlay" onclick="omCloseCart()"></div>
<div class="om-cart-drawer" id="omCartDrawer">
<div class="om-drawer-header">
<div class="om-drawer-title"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>Meu Carrinho<span class="om-drawer-count" id="omDrawerCount"><?= $_cartCount ?></span></div>
<button class="om-drawer-close" onclick="omCloseCart()"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
</div>
<div class="om-drawer-frete<?= $_frete_gratis ? " complete" : "" ?>" id="omDrawerFrete">
<div class="om-drawer-frete-row"><span class="om-drawer-frete-icon"><?= $_frete_gratis ? "✅" : "🚚" ?></span><span class="om-drawer-frete-text" id="omDrawerFreteText"><?= $_frete_gratis ? "<b>Frete GRÁTIS</b> garantido!" : "Falta <b>R$ " . number_format($_falta_frete, 2, ",", ".") . "</b> para frete grátis" ?></span></div>
<div class="om-drawer-frete-bar"><div class="om-drawer-frete-fill" id="omDrawerFreteFill" style="width:<?= $_frete_percent ?>%"></div></div>
</div>
<div class="om-drawer-items" id="omDrawerItems"></div>
<div class="om-drawer-footer">
<div class="om-coupon-box"><input type="text" placeholder="Código do cupom" id="omCouponInput"><button onclick="omApplyCoupon()">Aplicar</button></div>
<div class="om-drawer-totals">
<div class="om-drawer-total-row"><span>Subtotal</span><span id="omDrawerSubtotal">R$ <?= number_format($_cartTotal, 2, ",", ".") ?></span></div>
<div class="om-drawer-total-row shipping"><span>Entrega</span><span id="omDrawerDelivery" class="<?= $_frete_gratis ? "free" : "" ?>"><?= $_frete_gratis ? "GRÁTIS" : "R$ " . number_format($_frete_valor, 2, ",", ".") ?></span></div>
<div class="om-drawer-total-row total"><span>Total</span><span id="omDrawerTotal">R$ <?= number_format($_cartTotal + ($_frete_gratis ? 0 : $_frete_valor), 2, ",", ".") ?></span></div>
</div>
<button class="om-drawer-checkout" onclick="omGoCheckout()" <?= $_cartCount == 0 ? "disabled" : "" ?> id="omDrawerCheckoutBtn"><svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>Finalizar Compra</button>
</div>
</div>
<div class="om-sticky-bar<?= $_cartCount > 0 ? " visible" : "" ?>" id="omStickyBar">
<div class="om-sticky-bar-inner">
<div class="om-frete-progress">
<div class="om-frete-info"><span class="om-frete-label" id="omFreteLabel"><?= $_frete_gratis ? "✅ <b>FRETE GRÁTIS</b>" : "🚚 Falta <b>R$ " . number_format($_falta_frete, 2, ",", ".") . "</b> para frete grátis" ?></span><span class="om-frete-value<?= $_frete_gratis ? " complete" : "" ?>" id="omFreteValue"><?= $_frete_gratis ? "GRÁTIS" : "R$ " . number_format($_cartTotal, 2, ",", ".") . " / R$ " . number_format($_frete_minimo, 2, ",", ".") ?></span></div>
<div class="om-frete-bar"><div class="om-frete-fill" id="omFreteFill" style="width:<?= $_frete_percent ?>%"></div></div>
</div>
<div class="om-sticky-cart">
<div class="om-cart-summary" onclick="omOpenCart()">
<div class="om-cart-icon-wrap" id="omCartIconWrap"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg><span class="om-cart-badge" id="omCartBadge"><?= $_cartCount ?></span></div>
<div class="om-cart-info"><div class="om-cart-items-text" id="omCartItemsText"><?= $_cartCount ?> <?= $_cartCount == 1 ? "item" : "itens" ?></div><div class="om-cart-total" id="omCartTotalDisplay">R$ <?= number_format($_cartTotal, 2, ",", ".") ?></div></div>
</div>
<button class="om-btn-checkout pulse" onclick="omGoCheckout()" <?= $_cartCount == 0 ? "disabled" : "" ?> id="omStickyCheckoutBtn"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>Finalizar</button>
</div>
</div>
</div>
<div class="om-toast" id="omToast"><div class="om-toast-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg></div><span id="omToastText">Adicionado!</span></div>
<script>
(function(){
const FRETE_MIN=<?= $_frete_minimo ?>,FRETE_VALOR=<?= $_frete_valor ?>;
let omCart=<?= json_encode($_cart) ?>;
function omFmt(v){return"R$ "+v.toFixed(2).replace(".",",")}
function omGetTotal(){let t=0;for(let i in omCart){t+=(omCart[i].price_promo>0?omCart[i].price_promo:omCart[i].price)*omCart[i].qty}return t}
function omGetCount(){let c=0;for(let i in omCart)c+=omCart[i].qty;return c}
function omUpdateUI(){
const total=omGetTotal(),count=omGetCount(),falta=Math.max(0,FRETE_MIN-total),pct=Math.min(100,FRETE_MIN>0?(total/FRETE_MIN)*100:100),gratis=total>=FRETE_MIN;
document.getElementById("omStickyBar").classList.toggle("visible",count>0);
document.getElementById("omCartBadge").textContent=count;
document.getElementById("omCartItemsText").textContent=count+(count===1?" item":" itens");
document.getElementById("omCartTotalDisplay").textContent=omFmt(total);
document.getElementById("omFreteFill").style.width=pct+"%";
document.getElementById("omDrawerFreteFill").style.width=pct+"%";
if(gratis){document.getElementById("omFreteLabel").innerHTML="✅ <b>FRETE GRÁTIS</b>";document.getElementById("omFreteValue").textContent="GRÁTIS";document.getElementById("omFreteValue").classList.add("complete");document.getElementById("omDrawerFreteText").innerHTML="<b>Frete GRÁTIS</b> garantido!";document.getElementById("omDrawerFrete").classList.add("complete");document.getElementById("omDrawerDelivery").textContent="GRÁTIS";document.getElementById("omDrawerDelivery").classList.add("free")}
else{document.getElementById("omFreteLabel").innerHTML="🚚 Falta <b>"+omFmt(falta)+"</b> para frete grátis";document.getElementById("omFreteValue").textContent=omFmt(total)+" / "+omFmt(FRETE_MIN);document.getElementById("omFreteValue").classList.remove("complete");document.getElementById("omDrawerFreteText").innerHTML="Falta <b>"+omFmt(falta)+"</b> para frete grátis";document.getElementById("omDrawerFrete").classList.remove("complete");document.getElementById("omDrawerDelivery").textContent=omFmt(FRETE_VALOR);document.getElementById("omDrawerDelivery").classList.remove("free")}
document.getElementById("omDrawerCount").textContent=count;
document.getElementById("omDrawerSubtotal").textContent=omFmt(total);
document.getElementById("omDrawerTotal").textContent=omFmt(total+(gratis?0:FRETE_VALOR));
document.getElementById("omDrawerCheckoutBtn").disabled=count===0;
document.getElementById("omStickyCheckoutBtn").disabled=count===0;
omRenderItems()}
function omRenderItems(){
const c=document.getElementById("omDrawerItems"),count=omGetCount();
if(count===0){c.innerHTML='<div class="om-drawer-empty"><div class="om-drawer-empty-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg></div><h3>Carrinho vazio</h3><p>Adicione produtos</p></div>';return}
let h="";for(let id in omCart){const i=omCart[id],p=i.price_promo>0?i.price_promo:i.price,promo=i.price_promo>0;h+='<div class="om-cart-item"><div class="om-cart-item-img"><img src="'+(i.image||"/image/placeholder.png")+'" onerror="this.src=\'/image/placeholder.png\'"></div><div class="om-cart-item-info"><div class="om-cart-item-name">'+i.name+'</div>'+(i.brand?'<div class="om-cart-item-brand">'+i.brand+'</div>':"")+'<div class="om-cart-item-bottom"><div class="om-cart-item-price">'+omFmt(p*i.qty)+(promo?"<small>"+omFmt(i.price*i.qty)+"</small>":"")+'</div><div class="om-qty-controls"><button class="om-qty-btn minus" onclick="omUpdateQty('+id+',-1)">−</button><span class="om-qty-value">'+i.qty+'</span><button class="om-qty-btn plus" onclick="omUpdateQty('+id+',1)">+</button></div></div></div></div>'}c.innerHTML=h}
window.omAddToCart=function(p){const id=p.id;if(omCart[id])omCart[id].qty++;else omCart[id]={id:p.id,name:p.name,brand:p.brand||"",image:p.image,price:parseFloat(p.price),price_promo:parseFloat(p.price_promo)||0,qty:1};document.getElementById("omCartIconWrap").classList.add("om-cart-shake");setTimeout(()=>document.getElementById("omCartIconWrap").classList.remove("om-cart-shake"),500);omShowToast("Adicionado ao carrinho!");omSaveCart();omUpdateUI()};
window.omUpdateQty=function(id,d){if(!omCart[id])return;omCart[id].qty+=d;if(omCart[id].qty<=0)delete omCart[id];omSaveCart();omUpdateUI()};
function omSaveCart(){fetch("/mercado/api/cart.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"save",cart:omCart})}).catch(()=>{})}
window.omOpenCart=function(){document.getElementById("omCartOverlay").classList.add("open");document.getElementById("omCartDrawer").classList.add("open");document.body.style.overflow="hidden"};
window.omCloseCart=function(){document.getElementById("omCartOverlay").classList.remove("open");document.getElementById("omCartDrawer").classList.remove("open");document.body.style.overflow=""};
window.omGoCheckout=function(){if(omGetCount()===0){omShowToast("Adicione produtos");return}window.location.href="/mercado/checkout.php"};
window.omApplyCoupon=function(){const c=document.getElementById("omCouponInput").value.trim();if(!c){omShowToast("Digite um código");return}omShowToast("Cupom aplicado!")};
function omShowToast(m){const t=document.getElementById("omToast");document.getElementById("omToastText").textContent=m;t.classList.add("show");setTimeout(()=>t.classList.remove("show"),2500)}
window.omShowToast=omShowToast;window.addToCart=window.omAddToCart;
document.addEventListener("DOMContentLoaded",omUpdateUI);if(document.readyState!=="loading")omUpdateUI();
})();
</script>