// ══════════════════════════════════════════════════════════════════════════════
// 🔔 ONEMUNDO ALERT SYSTEM - NOTIFICAÇÕES COM SOM TIPO UBER
// ══════════════════════════════════════════════════════════════════════════════

class OneMundoAlerts {
    constructor(config = {}) {
        this.userType = config.userType || "delivery";
        this.userId = config.userId || 0;
        this.pollInterval = config.pollInterval || 3000;
        this.lastNotificationId = 0;
        this.isPolling = false;
        this.audioContext = null;
        this.soundEnabled = true;
        this.currentOffer = null;
        this.offerTimeout = null;
        
        this.init();
    }
    
    init() {
        this.createStyles();
        this.createModal();
        this.initAudio();
        this.registerServiceWorker();
        this.startPolling();
        
        // Verificar ofertas ao iniciar
        setTimeout(() => this.checkOffers(), 1000);
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // CRIAR ESTILOS CSS
    // ══════════════════════════════════════════════════════════════════════════
    createStyles() {
        if (document.getElementById("om-alert-styles")) return;
        
        const style = document.createElement("style");
        style.id = "om-alert-styles";
        style.textContent = `
            @keyframes om-pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
            @keyframes om-shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }
            @keyframes om-glow { 0%, 100% { box-shadow: 0 0 20px rgba(245,158,11,0.5); } 50% { box-shadow: 0 0 40px rgba(245,158,11,0.8); } }
            @keyframes om-countdown { from { width: 100%; } to { width: 0%; } }
            @keyframes om-fadeIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
            
            .om-alert-overlay {
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.9);
                z-index: 99999;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 20px;
                backdrop-filter: blur(10px);
            }
            .om-alert-overlay.show { display: flex; }
            
            .om-alert-modal {
                background: linear-gradient(145deg, #1a1a1a 0%, #0d0d0d 100%);
                border-radius: 24px;
                width: 100%;
                max-width: 400px;
                overflow: hidden;
                animation: om-fadeIn 0.3s ease, om-glow 1.5s ease infinite;
                border: 2px solid #f59e0b;
            }
            
            .om-alert-header {
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                padding: 20px;
                text-align: center;
            }
            .om-alert-header-icon {
                font-size: 48px;
                animation: om-pulse 1s ease infinite;
            }
            .om-alert-header-title {
                color: #000;
                font-size: 24px;
                font-weight: 800;
                margin-top: 8px;
            }
            .om-alert-header-subtitle {
                color: rgba(0,0,0,0.7);
                font-size: 14px;
                margin-top: 4px;
            }
            
            .om-alert-timer {
                height: 6px;
                background: rgba(0,0,0,0.3);
            }
            .om-alert-timer-bar {
                height: 100%;
                background: #000;
                animation: om-countdown linear forwards;
            }
            
            .om-alert-body {
                padding: 24px;
            }
            
            .om-alert-info {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px;
                background: rgba(255,255,255,0.05);
                border-radius: 12px;
                margin-bottom: 12px;
            }
            .om-alert-info-icon {
                font-size: 24px;
                width: 44px;
                height: 44px;
                background: rgba(245,158,11,0.2);
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .om-alert-info-text {
                flex: 1;
            }
            .om-alert-info-label {
                color: rgba(255,255,255,0.5);
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .om-alert-info-value {
                color: #fff;
                font-size: 16px;
                font-weight: 700;
            }
            
            .om-alert-earning {
                text-align: center;
                padding: 20px;
                background: rgba(245,158,11,0.1);
                border-radius: 16px;
                margin: 16px 0;
                border: 1px dashed rgba(245,158,11,0.3);
            }
            .om-alert-earning-label {
                color: rgba(255,255,255,0.6);
                font-size: 12px;
                text-transform: uppercase;
            }
            .om-alert-earning-value {
                color: #f59e0b;
                font-size: 42px;
                font-weight: 900;
                margin-top: 4px;
            }
            
            .om-alert-actions {
                display: flex;
                gap: 12px;
                margin-top: 20px;
            }
            
            .om-alert-btn {
                flex: 1;
                padding: 16px;
                border: none;
                border-radius: 14px;
                font-size: 16px;
                font-weight: 700;
                cursor: pointer;
                transition: all 0.2s;
            }
            .om-alert-btn:active { transform: scale(0.98); }
            
            .om-alert-btn-reject {
                background: rgba(255,255,255,0.1);
                color: rgba(255,255,255,0.7);
            }
            .om-alert-btn-reject:hover {
                background: rgba(255,255,255,0.15);
            }
            
            .om-alert-btn-accept {
                background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
                color: #fff;
                animation: om-pulse 1s ease infinite;
            }
            .om-alert-btn-accept:hover {
                background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            }
            
            /* Toast notification */
            .om-toast {
                position: fixed;
                bottom: 100px;
                left: 50%;
                transform: translateX(-50%) translateY(100px);
                background: #1a1a1a;
                color: #fff;
                padding: 14px 24px;
                border-radius: 50px;
                font-size: 14px;
                font-weight: 600;
                z-index: 99998;
                opacity: 0;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                gap: 8px;
                border: 1px solid rgba(255,255,255,0.1);
            }
            .om-toast.show {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
            .om-toast.success { border-color: #22c55e; }
            .om-toast.error { border-color: #ef4444; }
        `;
        document.head.appendChild(style);
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // CRIAR MODAL HTML
    // ══════════════════════════════════════════════════════════════════════════
    createModal() {
        if (document.getElementById("om-alert-overlay")) return;
        
        const modal = document.createElement("div");
        modal.id = "om-alert-overlay";
        modal.className = "om-alert-overlay";
        modal.innerHTML = `
            <div class="om-alert-modal">
                <div class="om-alert-header">
                    <div class="om-alert-header-icon" id="omAlertIcon">🚴</div>
                    <div class="om-alert-header-title" id="omAlertTitle">Nova Entrega!</div>
                    <div class="om-alert-header-subtitle" id="omAlertSubtitle">Aceite rápido!</div>
                </div>
                <div class="om-alert-timer">
                    <div class="om-alert-timer-bar" id="omAlertTimer"></div>
                </div>
                <div class="om-alert-body">
                    <div class="om-alert-info">
                        <div class="om-alert-info-icon">📍</div>
                        <div class="om-alert-info-text">
                            <div class="om-alert-info-label">Local</div>
                            <div class="om-alert-info-value" id="omAlertLocation">-</div>
                        </div>
                    </div>
                    <div class="om-alert-info">
                        <div class="om-alert-info-icon" id="omAlertVehicleIcon">🏍️</div>
                        <div class="om-alert-info-text">
                            <div class="om-alert-info-label">Veículo</div>
                            <div class="om-alert-info-value" id="omAlertVehicle">Moto</div>
                        </div>
                    </div>
                    <div class="om-alert-info" id="omAlertDistanceContainer">
                        <div class="om-alert-info-icon">📏</div>
                        <div class="om-alert-info-text">
                            <div class="om-alert-info-label">Distância</div>
                            <div class="om-alert-info-value" id="omAlertDistance">-</div>
                        </div>
                    </div>
                    <div class="om-alert-earning">
                        <div class="om-alert-earning-label">Você vai ganhar</div>
                        <div class="om-alert-earning-value" id="omAlertEarning">R$ 0,00</div>
                    </div>
                    <div class="om-alert-actions">
                        <button class="om-alert-btn om-alert-btn-reject" onclick="omAlerts.rejectOffer()">Recusar</button>
                        <button class="om-alert-btn om-alert-btn-accept" onclick="omAlerts.acceptOffer()">✓ Aceitar</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Toast
        const toast = document.createElement("div");
        toast.id = "om-toast";
        toast.className = "om-toast";
        document.body.appendChild(toast);
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // INICIALIZAR ÁUDIO
    // ══════════════════════════════════════════════════════════════════════════
    initAudio() {
        // Criar contexto de áudio na primeira interação
        document.addEventListener("click", () => {
            if (!this.audioContext) {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }
        }, { once: true });
    }
    
    // Tocar som de alerta
    playAlertSound() {
        if (!this.soundEnabled) return;
        
        try {
            if (!this.audioContext) {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }
            
            const ctx = this.audioContext;
            const now = ctx.currentTime;
            
            // Som tipo "ding dong" urgente
            const playTone = (freq, start, duration) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                
                osc.connect(gain);
                gain.connect(ctx.destination);
                
                osc.frequency.value = freq;
                osc.type = "sine";
                
                gain.gain.setValueAtTime(0, now + start);
                gain.gain.linearRampToValueAtTime(0.5, now + start + 0.05);
                gain.gain.linearRampToValueAtTime(0, now + start + duration);
                
                osc.start(now + start);
                osc.stop(now + start + duration);
            };
            
            // Sequência de tons urgentes
            playTone(880, 0, 0.15);
            playTone(1100, 0.15, 0.15);
            playTone(880, 0.3, 0.15);
            playTone(1100, 0.45, 0.15);
            playTone(1320, 0.6, 0.3);
            
        } catch (e) {
            console.warn("Erro ao tocar som:", e);
        }
    }
    
    // Som contínuo enquanto modal aberto
    startAlertLoop() {
        this.playAlertSound();
        this.soundLoop = setInterval(() => {
            if (document.getElementById("om-alert-overlay").classList.contains("show")) {
                this.playAlertSound();
            } else {
                this.stopAlertLoop();
            }
        }, 3000);
    }
    
    stopAlertLoop() {
        if (this.soundLoop) {
            clearInterval(this.soundLoop);
            this.soundLoop = null;
        }
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // SERVICE WORKER
    // ══════════════════════════════════════════════════════════════════════════
    async registerServiceWorker() {
        if ("serviceWorker" in navigator) {
            try {
                const registration = await navigator.serviceWorker.register("/mercado/sw.js");
                console.log("🔔 Service Worker registrado:", registration.scope);
                
                // Solicitar permissão de notificação
                if (Notification.permission === "default") {
                    const permission = await Notification.requestPermission();
                    console.log("🔔 Permissão de notificação:", permission);
                }
            } catch (e) {
                console.warn("Service Worker erro:", e);
            }
        }
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // POLLING DE NOTIFICAÇÕES
    // ══════════════════════════════════════════════════════════════════════════
    startPolling() {
        if (this.isPolling) return;
        this.isPolling = true;
        
        this.pollNotifications();
        this.pollTimer = setInterval(() => this.pollNotifications(), this.pollInterval);
    }
    
    stopPolling() {
        this.isPolling = false;
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }
    
    async pollNotifications() {
        try {
            const response = await fetch(`/mercado/api/push.php?action=poll&user_type=${this.userType}&user_id=${this.userId}&last_id=${this.lastNotificationId}`);
            const data = await response.json();
            
            if (data.success && data.notifications && data.notifications.length > 0) {
                for (const notif of data.notifications) {
                    this.lastNotificationId = Math.max(this.lastNotificationId, notif.queue_id);
                    this.handleNotification(notif);
                }
            }
        } catch (e) {
            console.warn("Erro no polling:", e);
        }
    }
    
    handleNotification(notif) {
        // Se for oferta urgente, mostrar modal
        if (notif.priority === "urgent" && notif.data) {
            this.showOfferModal(notif.data);
        } else {
            // Notificação normal
            this.showToast(notif.icon + " " + notif.title, notif.body ? "info" : "success");
            
            // Push do navegador
            if (Notification.permission === "granted") {
                new Notification(notif.title, {
                    body: notif.body,
                    icon: "/mercado/assets/icon-192.png",
                    vibrate: [200, 100, 200]
                });
            }
        }
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // VERIFICAR OFERTAS PENDENTES
    // ══════════════════════════════════════════════════════════════════════════
    async checkOffers() {
        if (this.userType === "delivery") {
            await this.checkDeliveryOffers();
        } else if (this.userType === "shopper") {
            await this.checkShopperOrders();
        }
    }
    
    async checkDeliveryOffers() {
        try {
            const response = await fetch(`/mercado/api/push.php?action=get_pending_offers&delivery_id=${this.userId}`);
            const data = await response.json();
            
            if (data.success && data.offers && data.offers.length > 0) {
                // Mostrar a primeira oferta
                this.showOfferModal(data.offers[0]);
            }
        } catch (e) {
            console.warn("Erro ao buscar ofertas:", e);
        }
    }
    
    async checkShopperOrders() {
        try {
            const response = await fetch(`/mercado/api/push.php?action=get_pending_orders&shopper_id=${this.userId}`);
            const data = await response.json();
            
            if (data.success && data.orders && data.orders.length > 0) {
                // Mostrar o primeiro pedido
                this.showOrderModal(data.orders[0]);
            }
        } catch (e) {
            console.warn("Erro ao buscar pedidos:", e);
        }
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // MOSTRAR MODAL DE OFERTA (DELIVERY)
    // ══════════════════════════════════════════════════════════════════════════
    showOfferModal(offer) {
        this.currentOffer = offer;
        
        const overlay = document.getElementById("om-alert-overlay");
        
        // Atualizar conteúdo
        document.getElementById("omAlertIcon").textContent = "🚴";
        document.getElementById("omAlertTitle").textContent = "Nova Entrega!";
        document.getElementById("omAlertSubtitle").textContent = "Aceite antes que expire!";
        document.getElementById("omAlertLocation").textContent = offer.market_name || "Mercado";
        
        const vehicle = offer.vehicle_required === "carro" ? "Carro" : "Moto";
        const vehicleIcon = offer.vehicle_required === "carro" ? "🚗" : "🏍️";
        document.getElementById("omAlertVehicle").textContent = vehicle;
        document.getElementById("omAlertVehicleIcon").textContent = vehicleIcon;
        
        if (offer.distance_km) {
            document.getElementById("omAlertDistance").textContent = offer.distance_km + " km";
            document.getElementById("omAlertDistanceContainer").style.display = "flex";
        } else {
            document.getElementById("omAlertDistanceContainer").style.display = "none";
        }
        
        const earning = parseFloat(offer.delivery_earning || 0);
        document.getElementById("omAlertEarning").textContent = "R$ " + earning.toFixed(2).replace(".", ",");
        
        // Timer
        const seconds = parseInt(offer.seconds_remaining || 120);
        const timerBar = document.getElementById("omAlertTimer");
        timerBar.style.animation = "none";
        timerBar.offsetHeight; // Trigger reflow
        timerBar.style.animation = `om-countdown ${seconds}s linear forwards`;
        
        // Timeout para fechar
        if (this.offerTimeout) clearTimeout(this.offerTimeout);
        this.offerTimeout = setTimeout(() => {
            this.hideModal();
            this.showToast("⏰ Oferta expirada", "error");
        }, seconds * 1000);
        
        // Mostrar e tocar som
        overlay.classList.add("show");
        this.startAlertLoop();
        
        // Vibrar
        if (navigator.vibrate) {
            navigator.vibrate([200, 100, 200, 100, 200]);
        }
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // MOSTRAR MODAL DE PEDIDO (SHOPPER)
    // ══════════════════════════════════════════════════════════════════════════
    showOrderModal(order) {
        this.currentOffer = order;
        
        const overlay = document.getElementById("om-alert-overlay");
        
        // Atualizar conteúdo para shopper
        document.getElementById("omAlertIcon").textContent = "🛒";
        document.getElementById("omAlertTitle").textContent = "Novo Pedido!";
        document.getElementById("omAlertSubtitle").textContent = order.total_items + " itens para separar";
        document.getElementById("omAlertLocation").textContent = order.market_name || "Mercado";
        
        document.getElementById("omAlertVehicle").textContent = order.total_items + " itens";
        document.getElementById("omAlertVehicleIcon").textContent = "📦";
        document.getElementById("omAlertDistanceContainer").style.display = "none";
        
        const earning = parseFloat(order.total || 0) * 0.05; // 5% do pedido
        document.getElementById("omAlertEarning").textContent = "R$ " + Math.max(5, earning).toFixed(2).replace(".", ",");
        
        // Timer de 2 minutos
        const timerBar = document.getElementById("omAlertTimer");
        timerBar.style.animation = "none";
        timerBar.offsetHeight;
        timerBar.style.animation = "om-countdown 120s linear forwards";
        
        if (this.offerTimeout) clearTimeout(this.offerTimeout);
        this.offerTimeout = setTimeout(() => {
            this.hideModal();
        }, 120000);
        
        overlay.classList.add("show");
        this.startAlertLoop();
        
        if (navigator.vibrate) {
            navigator.vibrate([200, 100, 200, 100, 200]);
        }
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // ACEITAR OFERTA
    // ══════════════════════════════════════════════════════════════════════════
    async acceptOffer() {
        if (!this.currentOffer) return;
        
        this.hideModal();
        this.showToast("⏳ Processando...", "info");
        
        try {
            let url, body;
            
            if (this.userType === "delivery") {
                url = "/mercado/api/delivery_offers.php";
                body = JSON.stringify({
                    action: "accept_offer",
                    offer_id: this.currentOffer.offer_id,
                    delivery_id: this.userId
                });
            } else {
                url = "/mercado/shopper/api/shopper.php";
                body = JSON.stringify({
                    action: "accept_order",
                    order_id: this.currentOffer.order_id,
                    shopper_id: this.userId
                });
            }
            
            const response = await fetch(url, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: body
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showToast("✅ Aceito com sucesso!", "success");
                setTimeout(() => location.reload(), 1500);
            } else {
                this.showToast("❌ " + (data.error || "Erro ao aceitar"), "error");
                // Verificar novas ofertas
                setTimeout(() => this.checkOffers(), 2000);
            }
        } catch (e) {
            this.showToast("❌ Erro de conexão", "error");
        }
        
        this.currentOffer = null;
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // RECUSAR OFERTA
    // ══════════════════════════════════════════════════════════════════════════
    rejectOffer() {
        this.hideModal();
        this.currentOffer = null;
        this.showToast("Oferta recusada", "info");
        
        // Verificar próxima oferta
        setTimeout(() => this.checkOffers(), 1000);
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════════
    hideModal() {
        document.getElementById("om-alert-overlay").classList.remove("show");
        this.stopAlertLoop();
        if (this.offerTimeout) {
            clearTimeout(this.offerTimeout);
            this.offerTimeout = null;
        }
    }
    
    showToast(message, type = "info") {
        const toast = document.getElementById("om-toast");
        toast.textContent = message;
        toast.className = "om-toast " + type + " show";
        
        setTimeout(() => {
            toast.classList.remove("show");
        }, 3000);
    }
}

// Variável global para acesso
let omAlerts = null;
