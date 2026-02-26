<?php
/**
 * 🔄 COMPONENTE DE SUBSTITUIÇÃO - CLIENTE
 * 
 * Mostra substituições pendentes para o cliente aprovar/rejeitar
 */
if (!isset($order_id)) return;
?>
<div id="substitutions-container"></div>

<style>
.sub-card {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border: 2px solid #f59e0b;
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 12px;
    animation: pulse-border 2s infinite;
}
@keyframes pulse-border {
    0%, 100% { border-color: #f59e0b; box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
    50% { border-color: #fbbf24; box-shadow: 0 0 20px 0 rgba(245, 158, 11, 0.2); }
}
.sub-header { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.sub-badge { background: #f59e0b; color: #000; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.sub-timer { margin-left: auto; font-size: 13px; color: #f59e0b; font-weight: 600; }
.sub-products { display: grid; grid-template-columns: 1fr auto 1fr; gap: 12px; align-items: center; margin-bottom: 16px; }
.sub-product { text-align: center; }
.sub-product-name { font-size: 13px; margin-bottom: 4px; }
.sub-product-price { font-size: 15px; font-weight: 700; }
.sub-arrow { font-size: 20px; color: #22c55e; }
.sub-diff { text-align: center; padding: 8px; background: rgba(0,0,0,0.2); border-radius: 8px; margin-bottom: 12px; font-size: 13px; }
.sub-diff.positive { color: #ef4444; }
.sub-diff.negative { color: #22c55e; }
.sub-diff.neutral { color: #888; }
.sub-note { font-size: 12px; color: #888; margin-bottom: 12px; padding: 8px; background: rgba(0,0,0,0.2); border-radius: 8px; }
.sub-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.sub-btn { padding: 12px; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; }
.sub-btn-approve { background: #22c55e; color: #fff; }
.sub-btn-reject { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
</style>

<script>
(function() {
    const orderId = <?php echo intval($order_id); ?>;
    let checkInterval = null;
    
    async function checkSubstitutions() {
        try {
            const res = await fetch(`/mercado/api/substitution.php?action=pending&order_id=${orderId}`);
            const data = await res.json();
            
            if (data.success && data.substitutions.length > 0) {
                renderSubstitutions(data.substitutions);
            } else {
                document.getElementById("substitutions-container").innerHTML = "";
            }
        } catch (e) {}
    }
    
    function renderSubstitutions(subs) {
        const container = document.getElementById("substitutions-container");
        let html = "";
        
        subs.forEach(sub => {
            const expires = new Date(sub.expires_at).getTime();
            const diff = parseFloat(sub.price_difference);
            const diffClass = diff > 0 ? "positive" : (diff < 0 ? "negative" : "neutral");
            const diffText = diff > 0 ? `+R$ ${diff.toFixed(2).replace(".", ",")}` : 
                            (diff < 0 ? `-R$ ${Math.abs(diff).toFixed(2).replace(".", ",")}` : "Mesmo preço");
            
            html += `
                <div class="sub-card" data-sub-id="${sub.substitution_id}" data-expires="${expires}">
                    <div class="sub-header">
                        <span class="sub-badge">🔄 SUBSTITUIÇÃO</span>
                        <span class="sub-timer" data-timer="${expires}">--:--</span>
                    </div>
                    
                    <div class="sub-products">
                        <div class="sub-product">
                            <div class="sub-product-name" style="color:#ef4444;text-decoration:line-through;">${sub.original_name}</div>
                            <div class="sub-product-price" style="color:#888;">R$ ${parseFloat(sub.original_price).toFixed(2).replace(".", ",")}</div>
                        </div>
                        <div class="sub-arrow">→</div>
                        <div class="sub-product">
                            <div class="sub-product-name" style="color:#22c55e;">${sub.suggested_name}</div>
                            <div class="sub-product-price" style="color:#22c55e;">R$ ${parseFloat(sub.suggested_price).toFixed(2).replace(".", ",")}</div>
                        </div>
                    </div>
                    
                    <div class="sub-diff ${diffClass}">${diffText}</div>
                    
                    ${sub.shopper_note ? `<div class="sub-note">💬 ${sub.shopper_note}</div>` : ""}
                    
                    <div class="sub-actions">
                        <button class="sub-btn sub-btn-reject" onclick="respondSub(${sub.substitution_id}, 'rejected')">❌ Recusar</button>
                        <button class="sub-btn sub-btn-approve" onclick="respondSub(${sub.substitution_id}, 'approved')">✅ Aprovar</button>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
        startTimers();
    }
    
    function startTimers() {
        document.querySelectorAll("[data-timer]").forEach(el => {
            const expires = parseInt(el.dataset.timer);
            updateTimer(el, expires);
        });
    }
    
    function updateTimer(el, expires) {
        const update = () => {
            const now = Date.now();
            const remaining = Math.max(0, Math.floor((expires - now) / 1000));
            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            el.textContent = `${mins}:${secs.toString().padStart(2, "0")}`;
            
            if (remaining <= 0) {
                el.textContent = "Expirado";
                el.closest(".sub-card").style.opacity = "0.5";
            }
        };
        update();
        setInterval(update, 1000);
    }
    
    window.respondSub = async function(subId, response) {
        const card = document.querySelector(`[data-sub-id="${subId}"]`);
        card.style.opacity = "0.5";
        card.querySelectorAll("button").forEach(b => b.disabled = true);
        
        try {
            const res = await fetch("/mercado/api/substitution.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ action: "respond", substitution_id: subId, response })
            });
            const data = await res.json();
            
            if (data.success) {
                card.innerHTML = `
                    <div style="text-align:center;padding:20px;">
                        ${response === "approved" ? "✅ Substituição aprovada!" : "❌ Substituição recusada"}
                    </div>
                `;
                setTimeout(() => card.remove(), 2000);
            } else {
                alert(data.error || "Erro");
                card.style.opacity = "1";
                card.querySelectorAll("button").forEach(b => b.disabled = false);
            }
        } catch (e) {
            alert("Erro de conexão");
            card.style.opacity = "1";
            card.querySelectorAll("button").forEach(b => b.disabled = false);
        }
    };
    
    // Verificar a cada 5 segundos
    checkSubstitutions();
    checkInterval = setInterval(checkSubstitutions, 5000);
})();
</script>
