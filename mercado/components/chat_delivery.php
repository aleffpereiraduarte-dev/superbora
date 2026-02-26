<?php
/**
 * 💬 COMPONENTE DE CHAT SHOPPER ↔ DELIVERY
 * Incluir em páginas com: include "components/chat_delivery.php";
 */

function renderDeliveryChatModal($order_id, $user_type, $user_id) {
    ?>
    <div id="deliveryChatModal" class="dc-modal" style="display:none;">
        <div class="dc-modal-content">
            <div class="dc-header">
                <div class="dc-header-info">
                    <span class="dc-avatar">💬</span>
                    <div>
                        <h3 id="dcChatTitle">Chat</h3>
                        <small id="dcChatSubtitle">Pedido #<?= $order_id ?></small>
                    </div>
                </div>
                <button class="dc-close" onclick="closeDeliveryChat()">✕</button>
            </div>
            
            <div class="dc-messages" id="dcMessages">
                <div class="dc-empty">Nenhuma mensagem ainda</div>
            </div>
            
            <div class="dc-input-area">
                <input type="text" id="dcMessageInput" placeholder="Digite sua mensagem..." onkeypress="if(event.key==='Enter')sendDeliveryMsg()">
                <button class="dc-send" onclick="sendDeliveryMsg()">➤</button>
            </div>
        </div>
    </div>
    
    <style>
    .dc-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; display: flex; align-items: flex-end; justify-content: center; }
    .dc-modal-content { background: #111; width: 100%; max-width: 500px; height: 70vh; border-radius: 20px 20px 0 0; display: flex; flex-direction: column; }
    .dc-header { padding: 16px 20px; border-bottom: 1px solid #222; display: flex; justify-content: space-between; align-items: center; }
    .dc-header-info { display: flex; align-items: center; gap: 12px; }
    .dc-avatar { font-size: 28px; }
    .dc-header h3 { margin: 0; font-size: 16px; color: #fff; }
    .dc-header small { color: #888; }
    .dc-close { background: none; border: none; color: #888; font-size: 20px; cursor: pointer; }
    .dc-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 10px; }
    .dc-empty { text-align: center; color: #666; padding: 40px; }
    .dc-msg { max-width: 80%; padding: 12px 16px; border-radius: 16px; font-size: 14px; line-height: 1.4; }
    .dc-msg.sent { background: #00d47e; color: #000; align-self: flex-end; border-bottom-right-radius: 4px; }
    .dc-msg.received { background: #222; color: #fff; align-self: flex-start; border-bottom-left-radius: 4px; }
    .dc-msg.system { background: rgba(245,158,11,0.2); color: #f59e0b; align-self: center; font-size: 12px; text-align: center; }
    .dc-msg-time { font-size: 10px; opacity: 0.7; margin-top: 4px; }
    .dc-input-area { padding: 16px; border-top: 1px solid #222; display: flex; gap: 10px; }
    .dc-input-area input { flex: 1; padding: 12px 16px; background: #0a0a0a; border: 1px solid #333; border-radius: 25px; color: #fff; font-size: 14px; }
    .dc-input-area input:focus { outline: none; border-color: #00d47e; }
    .dc-send { width: 44px; height: 44px; background: #00d47e; border: none; border-radius: 50%; color: #000; font-size: 18px; cursor: pointer; }
    </style>
    
    <script>
    const dcOrderId = <?= $order_id ?>;
    const dcUserType = "<?= $user_type ?>";
    const dcUserId = <?= $user_id ?>;
    let dcLastId = 0;
    let dcInterval = null;
    
    function openDeliveryChat(title) {
        document.getElementById("deliveryChatModal").style.display = "flex";
        if (title) document.getElementById("dcChatTitle").textContent = title;
        loadDeliveryMessages();
        dcInterval = setInterval(loadDeliveryMessages, 3000);
    }
    
    function closeDeliveryChat() {
        document.getElementById("deliveryChatModal").style.display = "none";
        if (dcInterval) clearInterval(dcInterval);
    }
    
    async function loadDeliveryMessages() {
        try {
            const r = await fetch(`/mercado/api/chat_delivery.php?action=get&order_id=${dcOrderId}&last_id=${dcLastId}`);
            const d = await r.json();
            if (d.success && d.messages.length > 0) {
                const container = document.getElementById("dcMessages");
                const empty = container.querySelector(".dc-empty");
                if (empty) empty.remove();
                
                d.messages.forEach(m => {
                    dcLastId = Math.max(dcLastId, m.chat_id);
                    const div = document.createElement("div");
                    
                    if (m.sender_type === "system") {
                        div.className = "dc-msg system";
                    } else if (m.sender_type === dcUserType) {
                        div.className = "dc-msg sent";
                    } else {
                        div.className = "dc-msg received";
                    }
                    
                    div.innerHTML = m.message + '<div class="dc-msg-time">' + new Date(m.created_at).toLocaleTimeString("pt-BR", {hour:"2-digit", minute:"2-digit"}) + '</div>';
                    container.appendChild(div);
                });
                
                container.scrollTop = container.scrollHeight;
            }
        } catch (e) {
            console.error("Erro ao carregar mensagens:", e);
        }
    }
    
    async function sendDeliveryMsg() {
        const input = document.getElementById("dcMessageInput");
        const msg = input.value.trim();
        if (!msg) return;
        
        input.value = "";
        
        try {
            await fetch("/mercado/api/chat_delivery.php", {
                method: "POST",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify({
                    action: "send",
                    order_id: dcOrderId,
                    message: msg,
                    sender_type: dcUserType,
                    sender_id: dcUserId
                })
            });
            loadDeliveryMessages();
        } catch (e) {
            console.error("Erro ao enviar:", e);
        }
    }
    </script>
    <?php
}
