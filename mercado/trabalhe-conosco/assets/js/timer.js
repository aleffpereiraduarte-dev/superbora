/**
 * Timer Component - 60 Minutos para Compras
 * Uso: const timer = new ShoppingTimer(orderId, containerElement);
 */

class ShoppingTimer {
    constructor(orderId, container, options = {}) {
        this.orderId = orderId;
        this.container = typeof container === "string" ? document.querySelector(container) : container;
        this.options = {
            apiUrl: "/mercado/trabalhe-conosco/api/timer.php",
            refreshInterval: 1000,
            warningThreshold: 600, // 10 minutos
            criticalThreshold: 300, // 5 minutos
            onExpire: null,
            onWarning: null,
            ...options
        };
        
        this.timeRemaining = 0;
        this.status = "not_started";
        this.intervalId = null;
        
        this.init();
    }
    
    async init() {
        await this.fetchStatus();
        this.render();
        this.startCountdown();
    }
    
    async fetchStatus() {
        try {
            const response = await fetch(`${this.options.apiUrl}?order_id=${this.orderId}`);
            const data = await response.json();
            
            if (data.success) {
                this.timeRemaining = data.time_remaining;
                this.status = data.status;
                this.isPaused = data.is_paused;
                this.percentage = data.percentage;
            }
        } catch (error) {
            console.error("Erro ao buscar status do timer:", error);
        }
    }
    
    startCountdown() {
        if (this.intervalId) clearInterval(this.intervalId);
        
        this.intervalId = setInterval(() => {
            if (this.status === "running" && !this.isPaused) {
                this.timeRemaining = Math.max(0, this.timeRemaining - 1);
                this.updateDisplay();
                
                // Verificar alertas
                if (this.timeRemaining === this.options.warningThreshold && this.options.onWarning) {
                    this.options.onWarning(this.timeRemaining);
                }
                
                if (this.timeRemaining <= 0) {
                    this.status = "expired";
                    if (this.options.onExpire) {
                        this.options.onExpire();
                    }
                }
            }
        }, this.options.refreshInterval);
    }
    
    formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins.toString().padStart(2, "0")}:${secs.toString().padStart(2, "0")}`;
    }
    
    getColorClass() {
        if (this.timeRemaining <= this.options.criticalThreshold) return "critical";
        if (this.timeRemaining <= this.options.warningThreshold) return "warning";
        return "normal";
    }
    
    render() {
        if (!this.container) return;
        
        this.container.innerHTML = `
            <div class="shopping-timer ${this.getColorClass()}" id="timer-${this.orderId}">
                <div class="timer-circle">
                    <svg viewBox="0 0 100 100">
                        <circle class="timer-bg" cx="50" cy="50" r="45"/>
                        <circle class="timer-progress" cx="50" cy="50" r="45" 
                            stroke-dasharray="283" 
                            stroke-dashoffset="${283 * (1 - this.percentage / 100)}"/>
                    </svg>
                    <div class="timer-display">
                        <span class="timer-time">${this.formatTime(this.timeRemaining)}</span>
                        <span class="timer-label">${this.getStatusLabel()}</span>
                    </div>
                </div>
                <div class="timer-controls">
                    ${this.status === "running" && !this.isPaused ? `
                        <button class="timer-btn pause" onclick="timer.pause()">⏸️ Pausar</button>
                    ` : ""}
                    ${this.isPaused ? `
                        <button class="timer-btn resume" onclick="timer.resume()">▶️ Continuar</button>
                    ` : ""}
                    <button class="timer-btn extend" onclick="timer.requestExtension()">⏱️ +15min</button>
                </div>
            </div>
        `;
        
        this.addStyles();
    }
    
    updateDisplay() {
        const timeEl = this.container?.querySelector(".timer-time");
        const timerEl = this.container?.querySelector(".shopping-timer");
        const progressEl = this.container?.querySelector(".timer-progress");
        
        if (timeEl) {
            timeEl.textContent = this.formatTime(this.timeRemaining);
        }
        
        if (timerEl) {
            timerEl.className = `shopping-timer ${this.getColorClass()}`;
        }
        
        if (progressEl) {
            const percentage = (3600 - this.timeRemaining) / 3600 * 100;
            progressEl.style.strokeDashoffset = 283 * (1 - percentage / 100);
        }
    }
    
    getStatusLabel() {
        switch (this.status) {
            case "running": return this.isPaused ? "PAUSADO" : "COMPRANDO";
            case "completed": return "FINALIZADO";
            case "expired": return "EXPIRADO";
            default: return "AGUARDANDO";
        }
    }
    
    async pause() {
        try {
            const response = await fetch(this.options.apiUrl, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ order_id: this.orderId, action: "pause" })
            });
            const data = await response.json();
            if (data.success) {
                this.isPaused = true;
                this.render();
            }
        } catch (error) {
            console.error("Erro ao pausar:", error);
        }
    }
    
    async resume() {
        try {
            const response = await fetch(this.options.apiUrl, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ order_id: this.orderId, action: "resume" })
            });
            const data = await response.json();
            if (data.success) {
                this.isPaused = false;
                await this.fetchStatus();
                this.render();
            }
        } catch (error) {
            console.error("Erro ao retomar:", error);
        }
    }
    
    async requestExtension() {
        const reason = prompt("Motivo do tempo extra:");
        if (!reason) return;
        
        try {
            const response = await fetch(this.options.apiUrl, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ 
                    order_id: this.orderId, 
                    action: "extend",
                    extra_minutes: 15,
                    reason: reason
                })
            });
            const data = await response.json();
            if (data.success) {
                this.timeRemaining += 15 * 60;
                alert("✅ +15 minutos adicionados!");
                this.updateDisplay();
            }
        } catch (error) {
            console.error("Erro ao estender:", error);
        }
    }
    
    async complete() {
        try {
            const response = await fetch(this.options.apiUrl, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ order_id: this.orderId, action: "complete" })
            });
            const data = await response.json();
            if (data.success) {
                this.status = "completed";
                clearInterval(this.intervalId);
                this.render();
            }
            return data;
        } catch (error) {
            console.error("Erro ao completar:", error);
            return { success: false };
        }
    }
    
    addStyles() {
        if (document.getElementById("timer-styles")) return;
        
        const style = document.createElement("style");
        style.id = "timer-styles";
        style.textContent = `
            .shopping-timer {
                text-align: center;
                padding: 15px;
            }
            .timer-circle {
                position: relative;
                width: 150px;
                height: 150px;
                margin: 0 auto 15px;
            }
            .timer-circle svg {
                width: 100%;
                height: 100%;
                transform: rotate(-90deg);
            }
            .timer-bg {
                fill: none;
                stroke: #e0e0e0;
                stroke-width: 8;
            }
            .timer-progress {
                fill: none;
                stroke: #00a650;
                stroke-width: 8;
                stroke-linecap: round;
                transition: stroke-dashoffset 0.5s, stroke 0.3s;
            }
            .shopping-timer.warning .timer-progress { stroke: #ffa502; }
            .shopping-timer.critical .timer-progress { stroke: #ff4757; }
            .timer-display {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                text-align: center;
            }
            .timer-time {
                display: block;
                font-size: 2em;
                font-weight: 700;
                color: #333;
            }
            .shopping-timer.warning .timer-time { color: #ffa502; }
            .shopping-timer.critical .timer-time { color: #ff4757; }
            .timer-label {
                font-size: 0.8em;
                color: #666;
            }
            .timer-controls {
                display: flex;
                gap: 10px;
                justify-content: center;
                flex-wrap: wrap;
            }
            .timer-btn {
                padding: 8px 16px;
                border: none;
                border-radius: 20px;
                font-size: 0.9em;
                cursor: pointer;
                transition: transform 0.2s;
            }
            .timer-btn:hover { transform: scale(1.05); }
            .timer-btn.pause { background: #ffa502; color: #fff; }
            .timer-btn.resume { background: #00a650; color: #fff; }
            .timer-btn.extend { background: #667eea; color: #fff; }
        `;
        document.head.appendChild(style);
    }
    
    destroy() {
        if (this.intervalId) clearInterval(this.intervalId);
        if (this.container) this.container.innerHTML = "";
    }
}

// Exportar para uso global
window.ShoppingTimer = ShoppingTimer;
