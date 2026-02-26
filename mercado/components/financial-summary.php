<?php
/**
 * 💵 COMPONENTE DE RESUMO FINANCEIRO DO PEDIDO
 */
if (!isset($order_id)) return;
?>
<div id="financial-summary" style="display:none;margin:16px 0;background:rgba(255,255,255,0.03);border-radius:16px;padding:16px;">
    <div style="font-weight:600;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
        💵 Resumo Financeiro
        <span id="has-adjustments" style="display:none;background:#f59e0b;color:#000;padding:2px 8px;border-radius:10px;font-size:10px;">AJUSTADO</span>
    </div>
    
    <div id="summary-content">
        <div class="summary-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.05);">
            <span style="color:#888;">Subtotal original</span>
            <span id="original-total">R$ 0,00</span>
        </div>
        <div id="adjustments-list"></div>
        <div class="summary-row" style="display:flex;justify-content:space-between;padding:12px 0;font-size:18px;font-weight:700;">
            <span>Total Final</span>
            <span id="final-total" style="color:#22c55e;">R$ 0,00</span>
        </div>
    </div>
    
    <div id="pending-charges" style="display:none;margin-top:12px;padding:12px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:10px;">
        <div style="font-size:13px;color:#f59e0b;margin-bottom:8px;">⚠️ Cobrança pendente</div>
        <div style="font-size:20px;font-weight:700;color:#f59e0b;" id="pending-amount">R$ 0,00</div>
    </div>
</div>

<script>
(function() {
    const orderId = <?php echo intval($order_id); ?>;
    
    async function loadSummary() {
        try {
            // Buscar resumo
            const res = await fetch(`/mercado/api/adjustments.php?action=order_summary&order_id=${orderId}`);
            const data = await res.json();
            
            if (!data.success) return;
            
            const s = data.summary;
            
            // Só mostrar se tiver ajustes
            if (parseFloat(s.total_refunded) > 0 || parseFloat(s.total_charged) > 0) {
                document.getElementById("financial-summary").style.display = "block";
                document.getElementById("has-adjustments").style.display = "inline";
            } else {
                return;
            }
            
            document.getElementById("original-total").textContent = "R$ " + parseFloat(s.original_total || 0).toFixed(2).replace(".", ",");
            document.getElementById("final-total").textContent = "R$ " + parseFloat(s.final_total || s.original_total || 0).toFixed(2).replace(".", ",");
            
            // Buscar ajustes
            const adjRes = await fetch(`/mercado/api/adjustments.php?action=list&order_id=${orderId}`);
            const adjData = await adjRes.json();
            
            if (adjData.success && adjData.adjustments.length > 0) {
                let html = "";
                adjData.adjustments.forEach(adj => {
                    const isRefund = adj.direction === "refund";
                    const color = isRefund ? "#22c55e" : "#f59e0b";
                    const sign = isRefund ? "-" : "+";
                    const icon = isRefund ? "↩️" : "💳";
                    
                    html += `
                        <div class="summary-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px;">
                            <span style="color:#888;">${icon} ${adj.product_name || adj.reason}</span>
                            <span style="color:${color};">${sign}R$ ${parseFloat(adj.amount).toFixed(2).replace(".", ",")}</span>
                        </div>
                    `;
                });
                document.getElementById("adjustments-list").innerHTML = html;
            }
            
            // Mostrar cobranças pendentes
            if (parseFloat(s.pending_charge) > 0) {
                document.getElementById("pending-charges").style.display = "block";
                document.getElementById("pending-amount").textContent = "R$ " + parseFloat(s.pending_charge).toFixed(2).replace(".", ",");
            }
            
        } catch (e) {
            console.error("Erro:", e);
        }
    }
    
    loadSummary();
})();
</script>
