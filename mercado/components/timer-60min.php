<?php
/**
 * Timer 60 Minutos - Chat pós-entrega
 * Include em qualquer página para mostrar o timer quando aplicável
 */

$timer_order_id = $order_id ?? $_GET["order_id"] ?? 0;
$chat_expires_at = null;
$remaining_minutes = 0;

if ($timer_order_id && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT chat_expires_at, delivered_at, status FROM om_market_orders WHERE id = ?");
        $stmt->execute([$timer_order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order && $order["status"] === "delivered" && $order["chat_expires_at"]) {
            $expires = strtotime($order["chat_expires_at"]);
            $now = time();
            if ($expires > $now) {
                $remaining_minutes = ceil(($expires - $now) / 60);
                $chat_expires_at = $order["chat_expires_at"];
            }
        }
    } catch (Exception $e) {}
}
?>

<?php if ($remaining_minutes > 0): ?>
<div id="timer-60min" class="timer-60min-container">
    <div class="timer-60min-content">
        <div class="timer-icon">⏱️</div>
        <div class="timer-info">
            <span class="timer-label">Chat disponível por</span>
            <span class="timer-value" id="timer-countdown"><?= $remaining_minutes ?> min</span>
        </div>
        <button class="timer-chat-btn" onclick="openChat()">
            <i class="fas fa-comments"></i> Abrir Chat
        </button>
    </div>
</div>

<style>
.timer-60min-container {
    position: fixed;
    bottom: 80px;
    left: 50%;
    transform: translateX(-50%);
    background: linear-gradient(135deg, #1f2937, #374151);
    color: white;
    padding: 12px 20px;
    border-radius: 50px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    z-index: 999;
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from { transform: translateX(-50%) translateY(100px); opacity: 0; }
    to { transform: translateX(-50%) translateY(0); opacity: 1; }
}

.timer-60min-content {
    display: flex;
    align-items: center;
    gap: 12px;
}

.timer-icon {
    font-size: 1.5rem;
}

.timer-info {
    display: flex;
    flex-direction: column;
}

.timer-label {
    font-size: 0.7rem;
    opacity: 0.8;
}

.timer-value {
    font-size: 1rem;
    font-weight: 700;
    color: #fbbf24;
}

.timer-chat-btn {
    background: #10b981;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
}

.timer-chat-btn:hover {
    background: #059669;
}
</style>

<script>
(function() {
    const expiresAt = new Date("<?= $chat_expires_at ?>").getTime();
    const timerEl = document.getElementById("timer-countdown");
    const containerEl = document.getElementById("timer-60min");
    
    function updateTimer() {
        const now = Date.now();
        const remaining = expiresAt - now;
        
        if (remaining <= 0) {
            containerEl.style.display = "none";
            return;
        }
        
        const minutes = Math.floor(remaining / 60000);
        const seconds = Math.floor((remaining % 60000) / 1000);
        
        if (minutes > 0) {
            timerEl.textContent = `${minutes} min`;
        } else {
            timerEl.textContent = `${seconds} seg`;
            timerEl.style.color = "#ef4444";
        }
    }
    
    updateTimer();
    setInterval(updateTimer, 1000);
})();

function openChat() {
    // Trigger chat widget or redirect
    if (typeof toggleChat === "function") {
        toggleChat();
    } else {
        window.location.href = "pedido.php?id=<?= $timer_order_id ?>&chat=1";
    }
}
</script>
<?php endif; ?>