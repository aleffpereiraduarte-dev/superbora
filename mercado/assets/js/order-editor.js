/**
 * 🔄 OneMundo Mercado - Edição de Pedido em Tempo Real
 */

class OrderEditor {
    constructor(orderId, customerId) {
        this.orderId = orderId;
        this.customerId = customerId;
        this.canEdit = true;
        this.checkInterval = null;
        this.apiBase = "/mercado/api/order_edit.php";
    }
    
    async checkCanEdit() {
        try {
            const res = await fetch(`${this.apiBase}?action=can_edit&order_id=${this.orderId}`);
            const data = await res.json();
            
            this.canEdit = data.can;
            
            if (!data.can) {
                this.showLockMessage(data.reason);
                this.disableEditButtons();
            }
            
            return data;
        } catch (e) {
            console.error("Erro ao verificar edição:", e);
            return { can: false, reason: "Erro de conexão" };
        }
    }
    
    async addItem(productId, quantity = 1) {
        if (!this.canEdit) {
            this.showToast("❌ Não é possível editar este pedido", "error");
            return false;
        }
        
        try {
            const res = await fetch(this.apiBase, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    action: "add_item",
                    order_id: this.orderId,
                    customer_id: this.customerId,
                    product_id: productId,
                    quantity: quantity
                })
            });
            
            const data = await res.json();
            
            if (data.success) {
                this.showToast(`✅ ${data.item.name} adicionado!`);
                this.updateTotalsUI(data.totals);
                this.refreshOrderItems();
            } else {
                this.showToast(`❌ ${data.message}`, "error");
            }
            
            return data;
        } catch (e) {
            this.showToast("❌ Erro ao adicionar item", "error");
            return { success: false };
        }
    }
    
    async removeItem(productId) {
        if (!this.canEdit) {
            this.showToast("❌ Não é possível editar este pedido", "error");
            return false;
        }
        
        if (!confirm("Remover este item do pedido?")) return false;
        
        try {
            const res = await fetch(this.apiBase, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    action: "remove_item",
                    order_id: this.orderId,
                    customer_id: this.customerId,
                    product_id: productId
                })
            });
            
            const data = await res.json();
            
            if (data.success) {
                this.showToast("🗑️ Item removido");
                this.updateTotalsUI(data.totals);
                this.refreshOrderItems();
            } else {
                this.showToast(`❌ ${data.message}`, "error");
            }
            
            return data;
        } catch (e) {
            this.showToast("❌ Erro ao remover item", "error");
            return { success: false };
        }
    }
    
    async updateQuantity(productId, newQty) {
        if (!this.canEdit) {
            this.showToast("❌ Não é possível editar este pedido", "error");
            return false;
        }
        
        try {
            const res = await fetch(this.apiBase, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    action: "update_qty",
                    order_id: this.orderId,
                    customer_id: this.customerId,
                    product_id: productId,
                    quantity: newQty
                })
            });
            
            const data = await res.json();
            
            if (data.success) {
                this.showToast("✅ Quantidade atualizada");
                this.updateTotalsUI(data.totals);
            } else {
                this.showToast(`❌ ${data.message}`, "error");
            }
            
            return data;
        } catch (e) {
            this.showToast("❌ Erro ao atualizar", "error");
            return { success: false };
        }
    }
    
    updateTotalsUI(totals) {
        const subtotalEl = document.getElementById("order-subtotal");
        const totalEl = document.getElementById("order-total");
        
        if (subtotalEl) subtotalEl.textContent = `R$ ${totals.subtotal.toFixed(2).replace(".", ",")}`;
        if (totalEl) totalEl.textContent = `R$ ${totals.total.toFixed(2).replace(".", ",")}`;
        
        // Disparar evento para outros componentes
        window.dispatchEvent(new CustomEvent("orderTotalsUpdated", { detail: totals }));
    }
    
    refreshOrderItems() {
        // Recarregar lista de itens - implementar conforme UI
        window.dispatchEvent(new CustomEvent("orderItemsChanged", { detail: { orderId: this.orderId } }));
    }
    
    showLockMessage(reason) {
        const banner = document.createElement("div");
        banner.className = "order-edit-locked-banner";
        banner.innerHTML = `
            <div style="background: linear-gradient(135deg, #fef3c7, #fde68a); padding: 12px 16px; border-radius: 12px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 24px;">🔒</span>
                <div>
                    <strong style="color: #92400e;">Pedido bloqueado para edição</strong>
                    <p style="font-size: 13px; color: #a16207; margin: 0;">${reason}</p>
                </div>
            </div>
        `;
        
        const container = document.querySelector(".order-items-container") || document.body;
        container.prepend(banner);
    }
    
    disableEditButtons() {
        document.querySelectorAll(".order-item-edit-btn, .order-add-item-btn").forEach(btn => {
            btn.disabled = true;
            btn.style.opacity = "0.5";
            btn.style.cursor = "not-allowed";
        });
    }
    
    showToast(message, type = "success") {
        const toast = document.createElement("div");
        toast.style.cssText = `
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: ${type === "error" ? "#ef4444" : "#10b981"};
            color: white;
            padding: 14px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            z-index: 9999;
            opacity: 0;
            transition: all 0.3s ease;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        `;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = "1";
            toast.style.transform = "translateX(-50%) translateY(0)";
        }, 10);
        
        setTimeout(() => {
            toast.style.opacity = "0";
            toast.style.transform = "translateX(-50%) translateY(100px)";
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    startPolling(intervalMs = 10000) {
        this.checkInterval = setInterval(() => this.checkCanEdit(), intervalMs);
    }
    
    stopPolling() {
        if (this.checkInterval) {
            clearInterval(this.checkInterval);
            this.checkInterval = null;
        }
    }
}

// Exportar para uso global
window.OrderEditor = OrderEditor;

// Inicializar automaticamente se dados estiverem disponíveis
document.addEventListener("DOMContentLoaded", () => {
    const orderContainer = document.querySelector("[data-order-id]");
    if (orderContainer) {
        const orderId = orderContainer.dataset.orderId;
        const customerId = orderContainer.dataset.customerId || 0;
        
        window.currentOrderEditor = new OrderEditor(orderId, customerId);
        window.currentOrderEditor.checkCanEdit();
        window.currentOrderEditor.startPolling();
    }
});
