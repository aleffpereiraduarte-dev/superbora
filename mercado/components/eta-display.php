<?php
/**
 * ⏱️ COMPONENTE DE ETA - TEMPO ESTIMADO
 */
if (!isset($order_id)) return;
?>
<div id="eta-container" style="margin:16px 0;display:none;">
    <div style="background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);border-radius:16px;padding:20px;">
        
        <!-- Tempo Principal -->
        <div style="text-align:center;margin-bottom:20px;">
            <div style="font-size:11px;color:#888;text-transform:uppercase;margin-bottom:4px;">Tempo Estimado</div>
            <div id="eta-time" style="font-size:48px;font-weight:800;color:#22c55e;">--</div>
            <div id="eta-label" style="font-size:13px;color:#888;">Calculando...</div>
        </div>
        
        <!-- Timeline -->
        <div id="eta-timeline" style="position:relative;padding-left:24px;">
            <!-- Linha vertical -->
            <div style="position:absolute;left:7px;top:8px;bottom:8px;width:2px;background:rgba(255,255,255,0.1);"></div>
        </div>
        
        <!-- Horário previsto -->
        <div id="eta-arrival" style="text-align:center;margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.1);">
            <span style="color:#888;font-size:13px;">Previsão de chegada: </span>
            <span id="eta-arrival-time" style="font-weight:700;color:#f59e0b;">--:--</span>
        </div>
    </div>
</div>

<style>
.eta-step {
    position: relative;
    padding: 8px 0 8px 20px;
}
.eta-dot {
    position: absolute;
    left: -20px;
    top: 12px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    border: 2px solid rgba(255,255,255,0.2);
}
.eta-dot.completed {
    background: #22c55e;
    border-color: #22c55e;
}
.eta-dot.current {
    background: #f59e0b;
    border-color: #f59e0b;
    animation: pulse-dot 1.5s infinite;
}
@keyframes pulse-dot {
    0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
    50% { box-shadow: 0 0 0 8px rgba(245, 158, 11, 0); }
}
.eta-step-label {
    font-size: 13px;
    font-weight: 500;
}
.eta-step-time {
    font-size: 11px;
    color: #888;
}
.eta-step.completed .eta-step-label { color: #888; }
.eta-step.current .eta-step-label { color: #f59e0b; font-weight: 700; }
</style>

<script>
(function() {
    const orderId = <?php echo intval($order_id); ?>;
    let refreshInterval = null;
    
    async function loadETA() {
        try {
            // Calcular novo ETA
            await fetch(`/mercado/api/eta.php?action=calculate&order_id=${orderId}`);
            
            // Buscar dados
            const res = await fetch(`/mercado/api/eta.php?action=get&order_id=${orderId}`);
            const data = await res.json();
            
            if (data.success) {
                renderETA(data);
            }
        } catch (e) {
            console.error("Erro ETA:", e);
        }
    }
    
    function renderETA(data) {
        const container = document.getElementById("eta-container");
        container.style.display = "block";
        
        // Tempo principal
        const remaining = data.remaining_minutes;
        if (remaining !== null && remaining > 0) {
            if (remaining >= 60) {
                const hours = Math.floor(remaining / 60);
                const mins = remaining % 60;
                document.getElementById("eta-time").textContent = `${hours}h ${mins}min`;
            } else {
                document.getElementById("eta-time").textContent = `${remaining} min`;
            }
            document.getElementById("eta-time").style.color = remaining <= 10 ? "#22c55e" : (remaining <= 30 ? "#f59e0b" : "#3b82f6");
        } else if (data.status === "delivered" || data.status === "completed") {
            document.getElementById("eta-time").textContent = "✓";
            document.getElementById("eta-time").style.color = "#22c55e";
        }
        
        // Label do status
        const statusLabels = {
            pending: "Aguardando confirmação",
            confirmed: "Shopper aceitou",
            preparing: "Separando produtos",
            ready: "Pronto! Aguardando entregador",
            delivering: "A caminho",
            delivered: "Entregue!",
            completed: "Pedido concluído"
        };
        document.getElementById("eta-label").textContent = statusLabels[data.status] || data.status;
        
        // Horário de chegada
        if (data.estimated_delivery_at) {
            const arrival = new Date(data.estimated_delivery_at);
            document.getElementById("eta-arrival-time").textContent = 
                arrival.toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit" });
        }
        
        // Timeline
        renderTimeline(data.timeline, data.status);
    }
    
    function renderTimeline(timeline, currentStatus) {
        const container = document.getElementById("eta-timeline");
        let html = "";
        
        const statusOrder = ["created", "shopper_accepted", "shopping", "ready", "delivery_picked", "delivering", "delivered"];
        const currentIndex = statusOrder.indexOf(getTimelineStatus(currentStatus));
        
        timeline.forEach((step, index) => {
            const stepIndex = statusOrder.indexOf(step.step);
            let dotClass = "";
            let stepClass = "";
            
            if (step.completed) {
                dotClass = "completed";
                stepClass = "completed";
            } else if (stepIndex === currentIndex || stepIndex === currentIndex + 1) {
                dotClass = "current";
                stepClass = "current";
            }
            
            const time = step.time ? new Date(step.time).toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit" }) : "";
            
            html += `
                <div class="eta-step ${stepClass}">
                    <div class="eta-dot ${dotClass}"></div>
                    <div class="eta-step-label">${step.label}</div>
                    ${time ? `<div class="eta-step-time">${time}</div>` : ""}
                </div>
            `;
        });
        
        container.innerHTML = `<div style="position:absolute;left:7px;top:8px;bottom:8px;width:2px;background:rgba(255,255,255,0.1);"></div>` + html;
    }
    
    function getTimelineStatus(status) {
        const map = {
            pending: "created",
            confirmed: "shopper_accepted",
            preparing: "shopping",
            ready: "ready",
            delivering: "delivering",
            delivered: "delivered",
            completed: "delivered"
        };
        return map[status] || "created";
    }
    
    // Carregar inicial
    loadETA();
    
    // Atualizar a cada 30 segundos
    refreshInterval = setInterval(loadETA, 30000);
})();
</script>
