/**
 * 🎯 Smart Banners JS
 */
class SmartBanners {
    constructor() { this.api = '/mercado/api/promos.php'; }
    
    async loadStatus() {
        try {
            const res = await fetch(`${this.api}?action=status`);
            const data = await res.json();
            if (data.success) this.updateUI(data);
            return data;
        } catch (e) { return null; }
    }
    
    updateUI(data) {
        // Streak
        const streakTitle = document.querySelector('.feat-streak-text h3');
        if (streakTitle) streakTitle.textContent = `Sequência de ${data.streak} dias!`;
        
        document.querySelectorAll('.feat-streak-day').forEach((el, i) => {
            if (i < data.streak) { el.classList.add('active'); el.innerHTML = '🔥'; }
        });
        
        // Missões badge
        const badge = document.querySelector('.feat-missoes-badge');
        if (badge) badge.textContent = `${data.missions_completed}/${data.missions_total} completas`;
        
        // Cupom primeira compra
        const cupom = document.querySelector('.cupom-banner');
        if (cupom) cupom.style.display = data.is_first_order ? 'flex' : 'none';
    }
    
    async applyCoupon(code, total) {
        try {
            const res = await fetch(this.api, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'apply_coupon', code, total })
            });
            const data = await res.json();
            if (data.success) {
                this.showToast(`✅ Cupom aplicado! -R$ ${data.discount.toFixed(2)}`);
                window.dispatchEvent(new CustomEvent('couponApplied', { detail: data }));
            } else {
                this.showToast(`❌ ${data.message}`, 'error');
            }
            return data;
        } catch (e) {
            this.showToast('❌ Erro ao aplicar cupom', 'error');
            return { success: false };
        }
    }
    
    copyCoupon(code) {
        navigator.clipboard.writeText(code).then(() => this.showToast(`📋 Cupom ${code} copiado!`));
    }
    
    showToast(msg, type = 'success') {
        const toast = document.createElement('div');
        toast.style.cssText = `position:fixed;bottom:100px;left:50%;transform:translateX(-50%) translateY(100px);padding:16px 24px;border-radius:12px;font-weight:600;z-index:9999;background:${type==='error'?'#ef4444':'#10b981'};color:white;box-shadow:0 8px 24px rgba(0,0,0,0.2);opacity:0;transition:all 0.3s;`;
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '1'; toast.style.transform = 'translateX(-50%) translateY(0)'; }, 10);
        setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
    }
}

const smartBanners = new SmartBanners();
document.addEventListener('DOMContentLoaded', () => smartBanners.loadStatus());

function copyCupom(code) { smartBanners.copyCoupon(code); }
function applyCupom(code) { return smartBanners.applyCoupon(code, window.cartTotal || 0); }