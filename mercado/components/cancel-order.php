<?php
/**
 * ❌ COMPONENTE DE CANCELAMENTO - BOTÃO + MODAL
 * 
 * Uso: <?php $order_id = 123; include "components/cancel-order.php"; ?>
 */
if (!isset($order_id)) return;
?>
<div id="cancel-btn-container" style="display:none;margin-top:16px;">
    <button onclick="openCancelModal()" style="width:100%;padding:14px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:12px;color:#ef4444;font-size:14px;font-weight:600;cursor:pointer;">
        ❌ Cancelar Pedido
    </button>
</div>

<div id="cancel-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:99999;padding:20px;overflow-y:auto;">
    <div style="max-width:450px;margin:0 auto;background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);border-radius:24px;padding:24px;">
        
        <div style="text-align:center;margin-bottom:24px;">
            <div style="font-size:48px;margin-bottom:8px;">❌</div>
            <h2 style="font-size:20px;margin-bottom:4px;">Cancelar Pedido</h2>
            <p id="cancel-message" style="color:#888;font-size:14px;">Tem certeza que deseja cancelar?</p>
        </div>
        
        <div id="cancel-reasons" style="margin-bottom:20px;">
            <!-- Motivos serão carregados aqui -->
        </div>
        
        <div style="margin-bottom:20px;">
            <textarea id="cancel-details" placeholder="Detalhes adicionais (opcional)" style="width:100%;height:70px;background:rgba(0,0,0,0.2);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:12px;color:#fff;font-size:14px;resize:none;"></textarea>
        </div>
        
        <div id="refund-info" style="display:none;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:12px;padding:16px;margin-bottom:20px;text-align:center;">
            <div style="font-size:12px;color:#22c55e;margin-bottom:4px;">REEMBOLSO ESTIMADO</div>
            <div id="refund-amount" style="font-size:24px;font-weight:700;color:#22c55e;">R$ 0,00</div>
        </div>
        
        <div style="display:flex;gap:12px;">
            <button onclick="closeCancelModal()" style="flex:1;padding:14px;background:rgba(255,255,255,0.1);border:none;border-radius:12px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;">Voltar</button>
            <button id="confirm-cancel-btn" onclick="confirmCancel()" style="flex:1;padding:14px;background:#ef4444;border:none;border-radius:12px;color:#fff;font-size:14px;font-weight:700;cursor:pointer;" disabled>Confirmar Cancelamento</button>
        </div>
    </div>
</div>

<script>
(function() {
    const orderId = <?php echo intval($order_id); ?>;
    let canCancel = false;
    let selectedReason = null;
    let orderTotal = 0;
    let refundPercent = 100;
    
    // Verificar se pode cancelar
    async function checkCancel() {
        try {
            const res = await fetch(`/mercado/api/cancel.php?action=check&order_id=${orderId}`);
            const data = await res.json();
            
            if (data.can_cancel) {
                canCancel = true;
                document.getElementById("cancel-btn-container").style.display = "block";
                document.getElementById("cancel-message").textContent = data.message;
                
                if (data.partial_refund) {
                    refundPercent = 90;
                }
                
                // Carregar motivos
                loadReasons(data.reasons);
            }
        } catch (e) {
            console.error("Erro:", e);
        }
    }
    
    function loadReasons(reasons) {
        const container = document.getElementById("cancel-reasons");
        let html = "";
        
        for (const [key, label] of Object.entries(reasons)) {
            html += `
                <label style="display:flex;align-items:center;gap:12px;padding:12px;background:rgba(0,0,0,0.2);border-radius:10px;margin-bottom:8px;cursor:pointer;border:2px solid transparent;" data-reason="${key}">
                    <input type="radio" name="cancel_reason" value="${key}" style="display:none;">
                    <div style="width:20px;height:20px;border:2px solid #444;border-radius:50%;flex-shrink:0;"></div>
                    <span style="font-size:14px;">${label}</span>
                </label>
            `;
        }
        
        container.innerHTML = html;
        
        // Event listeners
        container.querySelectorAll("label").forEach(label => {
            label.addEventListener("click", () => {
                container.querySelectorAll("label").forEach(l => {
                    l.style.borderColor = "transparent";
                    l.querySelector("div").style.borderColor = "#444";
                    l.querySelector("div").style.background = "transparent";
                });
                
                label.style.borderColor = "#ef4444";
                label.querySelector("div").style.borderColor = "#ef4444";
                label.querySelector("div").style.background = "#ef4444";
                
                selectedReason = label.dataset.reason;
                document.getElementById("confirm-cancel-btn").disabled = false;
            });
        });
    }
    
    window.openCancelModal = function() {
        document.getElementById("cancel-modal").style.display = "block";
        
        // Mostrar info de reembolso
        document.getElementById("refund-info").style.display = "block";
        // Buscar total do pedido da página (se disponível)
        const totalEl = document.querySelector("[data-order-total]");
        if (totalEl) {
            orderTotal = parseFloat(totalEl.dataset.orderTotal) || 0;
            const refund = orderTotal * (refundPercent / 100);
            document.getElementById("refund-amount").textContent = "R$ " + refund.toFixed(2).replace(".", ",");
        }
    };
    
    window.closeCancelModal = function() {
        document.getElementById("cancel-modal").style.display = "none";
    };
    
    window.confirmCancel = async function() {
        if (!selectedReason) {
            alert("Selecione um motivo");
            return;
        }
        
        const btn = document.getElementById("confirm-cancel-btn");
        btn.disabled = true;
        btn.textContent = "Cancelando...";
        
        try {
            const res = await fetch("/mercado/api/cancel.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    action: "cancel",
                    order_id: orderId,
                    reason: selectedReason,
                    reason_details: document.getElementById("cancel-details").value
                })
            });
            const data = await res.json();
            
            if (data.success) {
                document.getElementById("cancel-modal").innerHTML = `
                    <div style="max-width:400px;margin:50px auto;background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);border-radius:24px;padding:40px;text-align:center;">
                        <div style="font-size:64px;margin-bottom:16px;">✅</div>
                        <h2 style="margin-bottom:8px;">Pedido Cancelado</h2>
                        <p style="color:#888;margin-bottom:16px;">Seu pedido foi cancelado com sucesso</p>
                        ${data.refund_amount > 0 ? `<p style="color:#22c55e;font-size:18px;font-weight:700;">Reembolso: R$ ${data.refund_amount.toFixed(2).replace(".", ",")}</p>` : ""}
                        <button onclick="location.reload()" style="margin-top:20px;padding:14px 32px;background:rgba(255,255,255,0.1);border:none;border-radius:12px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;">Fechar</button>
                    </div>
                `;
            } else {
                alert(data.error || "Erro ao cancelar");
                btn.disabled = false;
                btn.textContent = "Confirmar Cancelamento";
            }
        } catch (e) {
            alert("Erro de conexão");
            btn.disabled = false;
            btn.textContent = "Confirmar Cancelamento";
        }
    };
    
    // Iniciar
    checkCancel();
})();
</script>
