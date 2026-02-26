console.log("chat-history.js v2 - Premium");

const ChatHistory = {
    isOpen: false,
    
    init() {
        this.createSidebar();
        this.setupSwipe();
    },
    
    createSidebar() {
        if (document.getElementById('historySidebar')) return;
        
        const style = document.createElement('style');
        style.textContent = `
            /* ═══ SIDEBAR PREMIUM v2 ═══ */
            #historySidebar {
                position: fixed !important;
                inset: 0 !important;
                z-index: 999999 !important;
                display: none !important;
                font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Segoe UI', sans-serif !important;
            }
            #historySidebar.open { display: flex !important; }
            
            .hs-overlay {
                position: absolute !important;
                inset: 0 !important;
                background: rgba(0,0,0,0.85) !important;
                animation: fadeIn 0.2s ease !important;
            }
            @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
            
            .hs-content {
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
                bottom: 0 !important;
                width: 300px !important;
                max-width: 85vw !important;
                background: #ffffff !important;
                display: flex !important;
                flex-direction: column !important;
                animation: slideIn 0.3s cubic-bezier(0.32, 0.72, 0, 1) !important;
                box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25) !important;
            }
            @keyframes slideIn {
                from { transform: translateX(-100%); }
                to { transform: translateX(0); }
            }
            
            /* Header */
            .hs-header {
                padding: 20px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                border-bottom: 1px solid #f0f0f0 !important;
                background: #fafafa !important;
            }
            .hs-logo {
                display: flex !important;
                align-items: center !important;
                gap: 12px !important;
            }
            .hs-logo-icon {
                width: 42px !important;
                height: 42px !important;
                background: linear-gradient(135deg, #25d366 0%, #128c7e 100%) !important;
                border-radius: 12px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                box-shadow: 0 4px 12px rgba(37,211,102,0.3) !important;
            }
            .hs-logo-text {
                color: #1a1a1a !important;
                font-size: 22px !important;
                font-weight: 700 !important;
                letter-spacing: -0.5px !important;
            }
            .hs-close {
                width: 38px !important;
                height: 38px !important;
                background: #fff !important;
                border: 1px solid #e5e5e5 !important;
                color: #666 !important;
                cursor: pointer !important;
                border-radius: 10px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                transition: all 0.2s !important;
            }
            .hs-close:hover {
                background: #f5f5f5 !important;
                color: #333 !important;
                border-color: #ddd !important;
            }
            
            /* New Chat Button */
            .hs-new {
                margin: 16px !important;
                padding: 14px 20px !important;
                background: linear-gradient(135deg, #25d366 0%, #128c7e 100%) !important;
                border: none !important;
                border-radius: 12px !important;
                color: #fff !important;
                font-size: 15px !important;
                font-weight: 600 !important;
                cursor: pointer !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 10px !important;
                box-shadow: 0 4px 12px rgba(37,211,102,0.3) !important;
                transition: all 0.2s !important;
            }
            .hs-new:hover {
                transform: translateY(-1px) !important;
                box-shadow: 0 6px 16px rgba(37,211,102,0.4) !important;
            }
            .hs-new:active {
                transform: translateY(0) !important;
            }
            
            /* Section Title */
            .hs-section-title {
                color: #999 !important;
                font-size: 11px !important;
                font-weight: 600 !important;
                text-transform: uppercase !important;
                letter-spacing: 0.5px !important;
                padding: 12px 20px 8px !important;
            }
            
            /* List */
            .hs-list {
                flex: 1 !important;
                overflow-y: auto !important;
                padding: 0 12px 20px !important;
            }
            .hs-list::-webkit-scrollbar { width: 4px !important; }
            .hs-list::-webkit-scrollbar-thumb { background: #ddd !important; border-radius: 4px !important; }
            .hs-list::-webkit-scrollbar-track { background: transparent !important; }
            
            /* Empty State */
            .hs-empty {
                color: #999 !important;
                text-align: center !important;
                padding: 40px 20px !important;
                font-size: 14px !important;
            }
            .hs-empty-icon {
                width: 48px !important;
                height: 48px !important;
                margin: 0 auto 12px !important;
                background: #f5f5f5 !important;
                border-radius: 50% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
            
            /* Conversation Item */
            .hs-item {
                padding: 14px 16px !important;
                border-radius: 12px !important;
                cursor: pointer !important;
                margin-bottom: 4px !important;
                transition: all 0.15s !important;
                border: 1px solid transparent !important;
            }
            .hs-item:hover { 
                background: #f8f8f8 !important;
                border-color: #f0f0f0 !important;
            }
            .hs-item:active { 
                transform: scale(0.98) !important;
                background: #f0f0f0 !important;
            }
            .hs-item-title {
                color: #1a1a1a !important;
                font-size: 14px !important;
                font-weight: 500 !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                line-height: 1.4 !important;
            }
            .hs-item-meta {
                display: flex !important;
                align-items: center !important;
                gap: 8px !important;
                margin-top: 6px !important;
            }
            .hs-item-time { 
                color: #999 !important; 
                font-size: 12px !important; 
            }
            .hs-item-count {
                color: #128c7e !important;
                font-size: 11px !important;
                font-weight: 600 !important;
                background: rgba(37,211,102,0.1) !important;
                padding: 2px 8px !important;
                border-radius: 10px !important;
            }
            
            /* Footer */
            .hs-footer {
                padding: 16px !important;
                border-top: 1px solid #f0f0f0 !important;
                background: #fafafa !important;
            }
            .hs-footer-text {
                color: #999 !important;
                font-size: 11px !important;
                text-align: center !important;
            }
            
            /* Menu Button - Removido, usa o do header */
            #historyBtn { display: none !important; }
            
            /* Dark Mode */
            @media (prefers-color-scheme: dark) {
                .hs-content { background: #1a1a1a !important; }
                .hs-header { background: #111 !important; border-color: #2a2a2a !important; }
                .hs-logo-text { color: #fff !important; }
                .hs-close { background: #2a2a2a !important; border-color: #333 !important; color: #999 !important; }
                .hs-close:hover { background: #333 !important; color: #fff !important; }
                .hs-section-title { color: #666 !important; }
                .hs-item:hover { background: #222 !important; border-color: #333 !important; }
                .hs-item-title { color: #f0f0f0 !important; }
                .hs-item-time { color: #666 !important; }
                .hs-item-count { background: rgba(37,211,102,0.15) !important; }
                .hs-footer { background: #111 !important; border-color: #2a2a2a !important; }
                .hs-list::-webkit-scrollbar-thumb { background: #333 !important; }
            }
        `;
        document.head.appendChild(style);
        
        const sidebar = document.createElement('div');
        sidebar.id = 'historySidebar';
        sidebar.innerHTML = `
            <div class="hs-overlay" onclick="ChatHistory.close()"></div>
            <div class="hs-content">
                <div class="hs-header">
                    <div class="hs-logo">
                        <div class="hs-logo-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="white">
                                <rect x="3" y="8" width="3" height="8" rx="1.5"/>
                                <rect x="10.5" y="4" width="3" height="16" rx="1.5"/>
                                <rect x="18" y="8" width="3" height="8" rx="1.5"/>
                            </svg>
                        </div>
                        <span class="hs-logo-text">ONE</span>
                    </div>
                    <button class="hs-close" onclick="ChatHistory.close()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                            <path d="M18 6L6 18M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                
                <button class="hs-new" onclick="ChatHistory.newChat()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                    Nova conversa
                </button>
                
                <div class="hs-section-title">Conversas recentes</div>
                
                <div class="hs-list" id="hsConversations">
                    <div class="hs-empty">
                        <div class="hs-empty-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="1.5">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                        </div>
                        Carregando...
                    </div>
                </div>
                
                <div class="hs-footer">
                    <div class="hs-footer-text">ONE • Seu assistente inteligente</div>
                </div>
            </div>
        `;
        document.body.appendChild(sidebar);
    },
    
    setupSwipe() {
        let startX = 0;
        document.addEventListener('touchstart', e => { startX = e.touches[0].clientX; }, {passive:true});
        document.addEventListener('touchend', e => {
            const diff = startX - e.changedTouches[0].clientX;
            if (diff > 80 && this.isOpen) this.close();
            if (diff < -80 && !this.isOpen && startX < 50) this.open();
        }, {passive:true});
    },
    
    toggle() { this.isOpen ? this.close() : this.open(); },
    
    open() {
        this.isOpen = true;
        document.getElementById('historySidebar').classList.add('open');
        document.body.style.overflow = 'hidden';
        this.loadConversations();
    },
    
    close() {
        this.isOpen = false;
        document.getElementById('historySidebar').classList.remove('open');
        document.body.style.overflow = '';
    },
    
    async loadConversations() {
        const list = document.getElementById('hsConversations');
        try {
            const res = await fetch('/one/api/chat.php?action=conversations');
            const data = await res.json();
            
            if (!data.success || !data.conversations?.length) {
                list.innerHTML = `
                    <div class="hs-empty">
                        <div class="hs-empty-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="1.5">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                        </div>
                        Nenhuma conversa ainda.<br>Comece uma nova!
                    </div>
                `;
                return;
            }
            
            const convs = data.conversations.filter(c => c.total > 0);
            if (!convs.length) {
                list.innerHTML = `
                    <div class="hs-empty">
                        <div class="hs-empty-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="1.5">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                        </div>
                        Nenhuma conversa ainda
                    </div>
                `;
                return;
            }
            
            list.innerHTML = convs.map(c => `
                <div class="hs-item" onclick="ChatHistory.openConversation(${c.id})">
                    <div class="hs-item-title">${this.esc(c.first_message || 'Conversa')}</div>
                    <div class="hs-item-meta">
                        <span class="hs-item-time">${this.timeAgo(c.updated_at)}</span>
                        <span class="hs-item-count">${c.total} msgs</span>
                    </div>
                </div>
            `).join('');
            
        } catch(e) {
            console.error('Erro ao carregar conversas:', e);
            list.innerHTML = `
                <div class="hs-empty">
                    <div class="hs-empty-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="1.5">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 8v4M12 16h.01"/>
                        </svg>
                    </div>
                    Erro ao carregar
                </div>
            `;
        }
    },
    
    timeAgo(d) {
        const days = Math.floor((new Date() - new Date(d)) / 86400000);
        if (days === 0) return 'Hoje';
        if (days === 1) return 'Ontem';
        if (days < 7) return days + 'd atrás';
        return new Date(d).toLocaleDateString('pt-BR', {day:'2-digit', month:'short'});
    },
    
    esc(s) { 
        const d = document.createElement('div'); 
        d.textContent = s?.substring(0, 50) || 'Conversa'; 
        return d.innerHTML; 
    },
    
    async openConversation(id) {
        this.close();
        try {
            const res = await fetch('/one/api/chat.php?action=history&conv_id=' + id);
            const data = await res.json();
            if (data.success && data.messages) {
                const container = document.getElementById('chatContainer');
                if (container) container.innerHTML = '';
                data.messages.forEach(m => {
                    if (typeof addMessage === 'function') {
                        addMessage(m.content, m.role === 'user');
                    }
                });
            }
        } catch(e) {
            console.error('Erro ao abrir conversa:', e);
        }
    },
    
    async newChat() {
        try {
            await fetch('/one/api/chat.php?action=new_conversation');
            location.reload();
        } catch(e) {
            location.reload();
        }
    }
};

// Toggle function for header button
function toggleMenu() {
    ChatHistory.toggle();
}

// Init
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => ChatHistory.init(), 300);
});
