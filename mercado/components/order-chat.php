<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ══════════════════════════════════════════════════════════════
 * 💬 ONEMUNDO - CHAT DO PEDIDO V2
 * Widget flutuante moderno
 * ══════════════════════════════════════════════════════════════
 */

if (!isset($_SESSION['customer_id'])) return;

$customer_id = $_SESSION['customer_id'];

try {
    $db_host = '147.93.12.236';
    $db_name = 'love1';
    $db_user = 'root';
    // $db_pass loaded from central config
    
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buscar pedido ativo com chat
    $stmt = $pdo->prepare("
        SELECT o.order_id, o.status, s.name as shopper_name, s.avatar as shopper_avatar, a.chat_expires_at
        FROM om_market_orders o
        LEFT JOIN om_order_assignments a ON o.order_id = a.order_id
        LEFT JOIN om_order_shoppers s ON a.shopper_id = s.shopper_id
        WHERE o.customer_id = :customer_id
        AND (
            o.status NOT IN ('cancelled')
            AND (o.status != 'delivered' OR a.chat_expires_at > NOW())
        )
        ORDER BY o.order_id DESC
        LIMIT 1
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pedido || !$pedido['shopper_name']) return;
    
    // Buscar mensagens
    $stmt = $pdo->prepare("SELECT * FROM om_order_chat WHERE order_id = ? ORDER BY created_at ASC");
    $stmt->execute([$pedido['order_id']]);
    $mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Contar não lidas
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_order_chat WHERE order_id = ? AND sender_type != 'customer' AND is_read = 0");
    $stmt->execute([$pedido['order_id']]);
    $unread = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    return;
}
?>

<!-- Botão Flutuante -->
<button class="om-chat-float" id="chatFloat" onclick="toggleChat()">
    <span class="om-chat-float-icon">💬</span>
    <?php if ($unread > 0): ?>
    <span class="om-chat-float-badge" id="chatBadge"><?= $unread ?></span>
    <?php endif; ?>
</button>

<!-- Container do Chat -->
<div class="om-chat-container" id="chatContainer">
    <!-- Header -->
    <div class="om-chat-header">
        <div class="om-chat-header-info">
            <div class="om-chat-avatar"><?= $pedido['shopper_avatar'] ?? '👩‍🦰' ?></div>
            <div class="om-chat-user">
                <span class="om-chat-name"><?= htmlspecialchars($pedido['shopper_name']) ?></span>
                <span class="om-chat-status">
                    <span class="om-chat-status-dot"></span>
                    Online
                </span>
            </div>
        </div>
        <button class="om-chat-close" onclick="toggleChat()">✕</button>
    </div>
    
    <!-- Mensagens -->
    <div class="om-chat-messages" id="chatMessages">
        <?php foreach ($mensagens as $msg): 
            $is_customer = ($msg['sender_type'] === 'customer');
        ?>
        <div class="om-chat-msg <?= $is_customer ? 'sent' : 'received' ?>">
            <?php if (!$is_customer): ?>
            <div class="om-msg-avatar"><?= $pedido['shopper_avatar'] ?? '👩‍🦰' ?></div>
            <?php endif; ?>
            <div class="om-msg-content">
                <div class="om-msg-bubble"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                <div class="om-msg-time"><?= date('H:i', strtotime($msg['created_at'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Input -->
    <div class="om-chat-input-area">
        <input type="text" 
               class="om-chat-input" 
               id="chatInput" 
               placeholder="Digite sua mensagem..."
               onkeypress="if(event.key==='Enter')sendMessage()">
        <button class="om-chat-send" onclick="sendMessage()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="22" y1="2" x2="11" y2="13"></line>
                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
            </svg>
        </button>
    </div>
</div>

<style>
/* ══════════════════════════════════════════════════════════════
   CHAT V2
   ══════════════════════════════════════════════════════════════ */

/* Botão Flutuante */
.om-chat-float {
    position: fixed;
    bottom: 90px;
    right: 20px;
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #00d47e, #00a35f);
    border: none;
    border-radius: 50%;
    cursor: pointer;
    z-index: 9997;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 20px rgba(0,212,126,0.4);
    transition: transform 0.3s, box-shadow 0.3s;
}

.om-chat-float:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 30px rgba(0,212,126,0.5);
}

.om-chat-float-icon {
    font-size: 26px;
}

.om-chat-float-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    width: 22px;
    height: 22px;
    background: #ef4444;
    border-radius: 50%;
    font-size: 12px;
    font-weight: 700;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #030303;
}

/* Container do Chat */
.om-chat-container {
    position: fixed;
    bottom: 90px;
    right: 20px;
    width: 360px;
    height: 500px;
    max-height: calc(100vh - 120px);
    background: #0a0a0a;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 20px;
    z-index: 9999;
    display: none;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
    animation: chatIn 0.3s ease;
}

.om-chat-container.show {
    display: flex;
}

@keyframes chatIn {
    from { opacity: 0; transform: translateY(20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* Mobile */
@media (max-width: 480px) {
    .om-chat-container {
        width: 100%;
        height: 100%;
        max-height: 100%;
        bottom: 0;
        right: 0;
        border-radius: 0;
    }
    
    .om-chat-float {
        bottom: 100px;
    }
}

/* Header */
.om-chat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    background: linear-gradient(135deg, #111, #1a1a1a);
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.om-chat-header-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.om-chat-avatar {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, #00d47e, #00a35f);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}

.om-chat-user {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.om-chat-name {
    font-size: 15px;
    font-weight: 600;
    color: #fff;
}

.om-chat-status {
    font-size: 12px;
    color: #00d47e;
    display: flex;
    align-items: center;
    gap: 6px;
}

.om-chat-status-dot {
    width: 8px;
    height: 8px;
    background: #00d47e;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

.om-chat-close {
    width: 32px;
    height: 32px;
    background: rgba(255,255,255,0.1);
    border: none;
    border-radius: 50%;
    color: rgba(255,255,255,0.6);
    font-size: 16px;
    cursor: pointer;
    transition: all 0.2s;
}

.om-chat-close:hover {
    background: rgba(255,255,255,0.2);
    color: #fff;
}

/* Mensagens */
.om-chat-messages {
    flex: 1;
    padding: 16px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: #050505;
}

.om-chat-messages::-webkit-scrollbar {
    width: 4px;
}

.om-chat-messages::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.1);
    border-radius: 2px;
}

.om-chat-msg {
    display: flex;
    gap: 8px;
    max-width: 85%;
    animation: msgIn 0.3s ease;
}

@keyframes msgIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.om-chat-msg.sent {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.om-chat-msg.received {
    align-self: flex-start;
}

.om-msg-avatar {
    width: 28px;
    height: 28px;
    background: linear-gradient(135deg, #00d47e, #00a35f);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}

.om-msg-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.om-chat-msg.sent .om-msg-content {
    align-items: flex-end;
}

.om-msg-bubble {
    padding: 12px 16px;
    border-radius: 18px;
    font-size: 14px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
}

.om-chat-msg.received .om-msg-bubble {
    background: rgba(255,255,255,0.08);
    color: #fff;
    border-bottom-left-radius: 4px;
}

.om-chat-msg.sent .om-msg-bubble {
    background: linear-gradient(135deg, #00d47e, #00a35f);
    color: #fff;
    border-bottom-right-radius: 4px;
}

.om-msg-time {
    font-size: 10px;
    color: rgba(255,255,255,0.4);
    padding: 0 4px;
}

/* Input */
.om-chat-input-area {
    display: flex;
    gap: 10px;
    padding: 14px 16px;
    background: #0a0a0a;
    border-top: 1px solid rgba(255,255,255,0.1);
}

.om-chat-input {
    flex: 1;
    padding: 12px 18px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 50px;
    color: #fff;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    transition: border-color 0.2s;
}

.om-chat-input::placeholder {
    color: rgba(255,255,255,0.4);
}

.om-chat-input:focus {
    border-color: #00d47e;
}

.om-chat-send {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #00d47e, #00a35f);
    border: none;
    border-radius: 50%;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s;
}

.om-chat-send:hover {
    transform: scale(1.1);
}
</style>

<script>
const ORDER_ID = <?= $pedido['order_id'] ?>;
let lastMsgId = <?= end($mensagens)['message_id'] ?? 0 ?>;
let chatOpen = false;

// Toggle Chat
function toggleChat() {
    const container = document.getElementById('chatContainer');
    const float = document.getElementById('chatFloat');
    
    chatOpen = !chatOpen;
    
    if (chatOpen) {
        container.classList.add('show');
        float.style.display = 'none';
        markAsRead();
        scrollToBottom();
    } else {
        container.classList.remove('show');
        float.style.display = 'flex';
    }
}

// Enviar Mensagem
async function sendMessage() {
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    
    if (!msg) return;
    
    // Adicionar na UI imediatamente
    addMessageToUI(msg, true);
    input.value = '';
    
    // Enviar para API
    try {
        await fetch('/mercado/api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'send',
                order_id: ORDER_ID,
                message: msg,
                sender_type: 'customer'
            })
        });
    } catch (e) {
        console.error('Erro ao enviar:', e);
    }
}

// Adicionar mensagem na UI
function addMessageToUI(message, isSent) {
    const container = document.getElementById('chatMessages');
    const time = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    
    const html = `
        <div class="om-chat-msg ${isSent ? 'sent' : 'received'}">
            ${!isSent ? '<div class="om-msg-avatar">👩‍🦰</div>' : ''}
            <div class="om-msg-content">
                <div class="om-msg-bubble">${message.replace(/\n/g, '<br>')}</div>
                <div class="om-msg-time">${time}</div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', html);
    scrollToBottom();
}

// Scroll para baixo
function scrollToBottom() {
    const container = document.getElementById('chatMessages');
    container.scrollTop = container.scrollHeight;
}

// Marcar como lidas
async function markAsRead() {
    try {
        await fetch('/mercado/api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'mark_read',
                order_id: ORDER_ID
            })
        });
        
        // Remover badge
        const badge = document.getElementById('chatBadge');
        if (badge) badge.remove();
    } catch (e) {}
}

// Polling para novas mensagens
setInterval(async () => {
    try {
        const response = await fetch(`/mercado/api/chat.php?action=poll&order_id=${ORDER_ID}&last_id=${lastMsgId}`);
        const data = await response.json();
        
        if (data.success && data.messages && data.messages.length > 0) {
            data.messages.forEach(msg => {
                if (msg.sender_type !== 'customer') {
                    addMessageToUI(msg.message, false);
                    
                    // Atualizar badge se chat fechado
                    if (!chatOpen) {
                        updateBadge();
                    }
                }
                lastMsgId = Math.max(lastMsgId, msg.message_id);
            });
            
            if (chatOpen) markAsRead();
        }
    } catch (e) {}
}, 5000);

// Atualizar badge
function updateBadge() {
    let badge = document.getElementById('chatBadge');
    if (!badge) {
        badge = document.createElement('span');
        badge.id = 'chatBadge';
        badge.className = 'om-chat-float-badge';
        document.getElementById('chatFloat').appendChild(badge);
    }
    badge.textContent = parseInt(badge.textContent || 0) + 1;
}

// Scroll inicial
scrollToBottom();
</script>
