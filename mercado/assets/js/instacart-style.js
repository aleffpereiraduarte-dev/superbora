window.icCart=JSON.parse(localStorage.getItem("icCart")||"[]");

window.icOpenProductModal=async function(id){
try{
var r=await fetch("/mercado/api/product-modal.php?id="+id);
var d=await r.json();
if(!d.success)return alert(d.error);
window.icCurrentProduct=d.product;
icRenderModal(d.product,d.related||[]);
document.getElementById("icModalOverlay").classList.add("open");
document.getElementById("icProductModal").classList.add("open");
document.body.style.overflow="hidden";
}catch(e){console.error(e)}
};

window.icCloseProductModal=function(){
document.getElementById("icModalOverlay").classList.remove("open");
document.getElementById("icProductModal").classList.remove("open");
document.body.style.overflow="";
};

window.icOpenCart=function(){
document.getElementById("icCartOverlay").classList.add("open");
document.getElementById("icCartSidebar").classList.add("open");
document.body.style.overflow="hidden";
icRenderCart();
};

window.icCloseCart=function(){
document.getElementById("icCartOverlay").classList.remove("open");
document.getElementById("icCartSidebar").classList.remove("open");
document.body.style.overflow="";
};

window.icAddToCart=function(id,name,price,image){
var item=icCart.find(function(i){return i.product_id==id});
if(item){item.qty++}
else{icCart.push({product_id:id,name:name,price:parseFloat(price),image:image,qty:1})}
icSaveCart();
icUpdateBadge();
icShowToast(name+" adicionado!");

// Sincronizar com sessao PHP
fetch('/mercado/api/carrinho.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'add', product_id: id, quantity: 1, name: name, price: price, image: image})
}).catch(function(e){console.error('Erro ao sincronizar carrinho:', e);});
};

window.icChangeQty=function(id,delta){
var item=icCart.find(function(i){return i.product_id==id});
if(!item)return;
item.qty+=delta;
var newQty = item.qty;
if(item.qty<=0){
    icCart=icCart.filter(function(i){return i.product_id!=id});
    newQty = 0;
}
icSaveCart();
icUpdateBadge();
icRenderCart();

// Sincronizar com sessao PHP
if(newQty <= 0){
    fetch('/mercado/api/carrinho.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'remove', product_id: id})
    }).catch(function(e){console.error('Erro ao sincronizar carrinho:', e);});
} else {
    fetch('/mercado/api/carrinho.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'update', product_id: id, quantity: newQty})
    }).catch(function(e){console.error('Erro ao sincronizar carrinho:', e);});
}
};

function icRenderModal(p,related){
var modal=document.getElementById("icProductModal");
var inCart=icCart.find(function(i){return i.product_id==p.product_id});
var qty=inCart?inCart.qty:0;
var price=p.price_promo>0?p.price_promo:p.price;

var relHtml="";
if(related.length>0){
relHtml="<h3 style=\"margin-top:20px;font-size:16px\">Clientes tambem consideraram</h3><div class=\"ic-related-grid\">";
for(var i=0;i<related.length;i++){
var r=related[i];
var rp=r.price_promo>0?r.price_promo:r.price;
relHtml+="<div class=\"ic-related-card\" onclick=\"icOpenProductModal("+r.product_id+")\">";
relHtml+="<img src=\""+(r.image||"")+"\" class=\"ic-related-img\">";
relHtml+="<div class=\"ic-related-name\">"+r.name+"</div>";
relHtml+="<div class=\"ic-related-price\">R$ "+parseFloat(rp).toFixed(2).replace(".",",")+"</div></div>";
}
relHtml+="</div>";
}

var btnHtml;
if(qty>0){
btnHtml="<div class=\"ic-qty-controls\">";
btnHtml+="<button class=\"ic-qty-btn\" onclick=\"icChangeQty("+p.product_id+",-1)\">-</button>";
btnHtml+="<span class=\"ic-qty-value\">"+qty+" no carrinho</span>";
btnHtml+="<button class=\"ic-qty-btn\" onclick=\"icChangeQty("+p.product_id+",1)\">+</button></div>";
}else{
btnHtml="<button class=\"ic-add-btn\" onclick=\"icAddToCart("+p.product_id+",'"+p.name.replace(/\x27/g,"")+"',"+price+",'"+(p.image||"")+"')\">Adicionar ao carrinho</button>";
}

var html="<div class=\"ic-modal-header\"><span></span><button class=\"ic-modal-close\" onclick=\"icCloseProductModal()\">X</button></div>";
html+="<div class=\"ic-modal-content\">";
html+="<div class=\"ic-modal-left\">";
html+="<img src=\""+(p.image||"")+"\" class=\"ic-product-image\">";
html+=relHtml;
html+="</div>";
html+="<div class=\"ic-modal-right\">";
html+="<h1 class=\"ic-product-name\">"+p.name+"</h1>";
html+="<div class=\"ic-product-price\">R$ "+parseFloat(price).toFixed(2).replace(".",",")+"</div>";
html+=btnHtml;
html+="<div class=\"ic-instruction\"><span>Se nao tiver, substituir pelo mais parecido</span><span>></span></div>";
html+="<div class=\"ic-instruction\"><span>Adicionar nota para o shopper</span><span>></span></div>";
html+="</div></div>";

modal.innerHTML=html;
}

function icRenderCart(){
var c=document.getElementById("icCartItems");
var t=document.getElementById("icCartTotal");
if(icCart.length===0){
c.innerHTML="<div class=\"ic-cart-empty\"><div style=\"font-size:64px\">🛒</div><h3>Carrinho vazio</h3></div>";
if(t)t.textContent="R$ 0,00";
return;
}
var html="";
var total=0;
for(var i=0;i<icCart.length;i++){
var item=icCart[i];
var itemTotal=item.price*item.qty;
total+=itemTotal;
html+="<div class=\"ic-cart-item\">";
html+="<img src=\""+(item.image||"")+"\" class=\"ic-cart-item-img\">";
html+="<div class=\"ic-cart-item-info\">";
html+="<div class=\"ic-cart-item-name\">"+item.name+"</div>";
html+="<div class=\"ic-cart-item-price\">R$ "+itemTotal.toFixed(2).replace(".",",")+"</div>";
html+="</div>";
html+="<div style=\"display:flex;align-items:center;gap:4px;background:#f6f7f8;border-radius:8px;padding:4px\">";
html+="<button onclick=\"icChangeQty("+item.product_id+",-1)\" style=\"width:32px;height:32px;border:none;background:transparent;cursor:pointer\">-</button>";
html+="<span>"+item.qty+"</span>";
html+="<button onclick=\"icChangeQty("+item.product_id+",1)\" style=\"width:32px;height:32px;border:none;background:transparent;cursor:pointer\">+</button>";
html+="</div></div>";
}
c.innerHTML=html;
if(t)t.textContent="R$ "+total.toFixed(2).replace(".",",");
}

function icSaveCart(){
localStorage.setItem("icCart",JSON.stringify(icCart));
}

function icUpdateBadge(){
var count=0;
for(var i=0;i<icCart.length;i++){count+=icCart[i].qty}
var badges=document.querySelectorAll(".ic-float-badge,.cart-badge");
for(var i=0;i<badges.length;i++){if(badges[i])badges[i].textContent=count}
}

function icShowToast(msg){
var t=document.createElement("div");
t.textContent=msg;
t.style.cssText="position:fixed;bottom:100px;left:50%;transform:translateX(-50%);background:#fff;padding:12px 24px;border-radius:50px;box-shadow:0 4px 20px rgba(0,0,0,.15);z-index:99999";
document.body.appendChild(t);
setTimeout(function(){t.remove()},2500);
}

document.addEventListener("DOMContentLoaded",function(){icUpdateBadge()});