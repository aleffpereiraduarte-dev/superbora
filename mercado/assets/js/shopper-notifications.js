/**
 * 🔔 OneMundo Mercado - Notificações do Shopper
 * 
 * Recebe notificações em tempo real quando cliente edita o pedido
 */

class ShopperNotifications {
    constructor(orderId, shopperId) {
        this.orderId = orderId;
        this.shopperId = shopperId;
        this.pollInterval = null;
        this.apiBase = "/mercado/api/order_edit.php";
        this.audioContext = null;
    }
    
    async checkNotifications() {
        try {
            const res = await fetch(`${this.apiBase}?action=pending_notifications&order_id=${this.orderId}&shopper_id=${this.shopperId}`);
            const data = await res.json();
            
            if (data.success && data.edits && data.edits.length > 0) {
                data.edits.forEach(edit => this.showNotification(edit));
                this.updateFeeDisplay(data.shopper_fee, data.total_items);
            }
            
            return data;
        } catch (e) {
            console.error("Erro ao verificar notificações:", e);
            return { success: false };
        }
    }
    
    showNotification(edit) {
        const messages = {
            "add": { icon: "🛒", title: "Item Adicionado!", color: "#10b981" },
            "remove": { icon: "❌", title: "Item Removido", color: "#f59e0b" },
            "update_qty": { icon: "🔄", title: "Quantidade Alterada", color: "#3b82f6" }
        };
        
        const config = messages[edit.action] || messages["add"];
        
        // Criar notificação
        const notif = document.createElement("div");
        notif.className = "shopper-notification";
        notif.innerHTML = `
            <div class="notif-icon" style="background: ${config.color}20; color: ${config.color};">${config.icon}</div>
            <div class="notif-content">
                <strong>${config.title}</strong>
                <p>${edit.product_name} ${edit.quantity > 1 ? `(x${edit.quantity})` : ""}</p>
                <small>Novo total: R$ ${parseFloat(edit.new_total).toFixed(2).replace(".", ",")}</small>
            </div>
        `;
        
        notif.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: 16px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            z-index: 9999;
            animation: slideInRight 0.3s ease;
            max-width: 320px;
        `;
        
        document.body.appendChild(notif);
        
        // Som de notificação
        this.playNotificationSound();
        
        // Vibrar se suportado
        if (navigator.vibrate) {
            navigator.vibrate([200, 100, 200]);
        }
        
        // Remover após 5 segundos
        setTimeout(() => {
            notif.style.animation = "slideOutRight 0.3s ease forwards";
            setTimeout(() => notif.remove(), 300);
        }, 5000);
    }
    
    updateFeeDisplay(fee, totalItems) {
        const feeEl = document.getElementById("shopper-fee-display");
        if (!feeEl) return;
        
        const oldTotal = parseFloat(feeEl.dataset.currentFee || 0);
        const newTotal = fee.total;
        
        feeEl.innerHTML = `
            <div style="text-align: center; padding: 16px; background: linear-gradient(135deg, #d1fae5, #a7f3d0); border-radius: 12px;">
                <div style="font-size: 13px; color: #065f46;">Seu ganho neste pedido</div>
                <div style="font-size: 28px; font-weight: 800; color: #047857;">
                    R$ ${newTotal.toFixed(2).replace(".", ",")}
                </div>
                <div style="font-size: 12px; color: #059669;">
                    Base: R$ ${fee.base.toFixed(2)} ${fee.bonus > 0 ? `+ Bônus: R$ ${fee.bonus.toFixed(2)}` : ""}
                </div>
                <div style="font-size: 11px; color: #10b981; margin-top: 4px;">
                    ${totalItems} itens no pedido
                </div>
            </div>
        `;
        
        feeEl.dataset.currentFee = newTotal;
        
        // Animar se aumentou
        if (newTotal > oldTotal) {
            feeEl.style.animation = "none";
            setTimeout(() => {
                feeEl.style.animation = "pulse 0.5s ease 3";
            }, 10);
            
            this.showToast(`🎉 Seu ganho aumentou para R$ ${newTotal.toFixed(2)}!`);
        }
    }
    
    playNotificationSound() {
        try {
            if (!this.audioContext) {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }
            
            const oscillator = this.audioContext.createOscillator();
            const gainNode = this.audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(this.audioContext.destination);
            
            oscillator.frequency.value = 800;
            oscillator.type = "sine";
            
            gainNode.gain.setValueAtTime(0.3, this.audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, this.audioContext.currentTime + 0.3);
            
            oscillator.start(this.audioContext.currentTime);
            oscillator.stop(this.audioContext.currentTime + 0.3);
        } catch (e) {}
    }
    
    showToast(message) {
        const toast = document.createElement("div");
        toast.style.cssText = `
            position: fixed;
            bottom: 80px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 16px 24px;
            border-radius: 12px;
            font-weight: 600;
            z-index: 9999;
            animation: bounceIn 0.5s ease;
        `;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = "fadeOut 0.3s ease forwards";
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
    
    startPolling(intervalMs = 5000) {
        this.checkNotifications();
        this.pollInterval = setInterval(() => this.checkNotifications(), intervalMs);
    }
    
    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    }
}

// CSS para animações
const style = document.createElement("style");
style.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
    @keyframes bounceIn {
        0% { transform: translateX(-50%) scale(0.5); opacity: 0; }
        70% { transform: translateX(-50%) scale(1.1); }
        100% { transform: translateX(-50%) scale(1); opacity: 1; }
    }
    @keyframes fadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
    }
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }
    .notif-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }
    .notif-content strong {
        display: block;
        font-size: 14px;
        color: #1f2937;
    }
    .notif-content p {
        font-size: 13px;
        color: #6b7280;
        margin: 2px 0;
    }
    .notif-content small {
        font-size: 12px;
        color: #10b981;
        font-weight: 600;
    }
`;
document.head.appendChild(style);

window.ShopperNotifications = ShopperNotifications;
