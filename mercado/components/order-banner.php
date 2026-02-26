<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ══════════════════════════════════════════════════════════════
 * 🛒 ONEMUNDO - BANNER DE PEDIDO V3
 * Com botão para gerar QR Code de entrega
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
    
    $stmt = $pdo->prepare("
        SELECT 
            o.order_id, o.status, o.delivery_code, o.total, o.customer_name,
            o.stop_order, o.estimated_delivery_time, o.route_id,
            s.name as shopper_name, s.avatar as shopper_avatar,
            a.chat_expires_at,
            r.total_stops, r.completed_stops, r.status as route_status,
            d.name as delivery_name
        FROM om_market_orders o
        LEFT JOIN om_order_assignments a ON o.order_id = a.order_id
        LEFT JOIN om_order_shoppers s ON a.shopper_id = s.shopper_id
        LEFT JOIN om_delivery_routes r ON o.route_id = r.route_id
        LEFT JOIN om_market_deliveries d ON r.delivery_id = d.delivery_id
        WHERE o.customer_id = :customer_id
        AND (
            o.status NOT IN ('cancelled', 'delivered')
            OR (o.status = 'delivered' AND a.chat_expires_at > NOW())
        )
        ORDER BY o.order_id DESC
        LIMIT 1
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pedido) return;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_order_chat WHERE order_id = ? AND sender_type != 'customer' AND is_read = 0");
    $stmt->execute([$pedido['order_id']]);
    $unread = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    return;
}

$status_config = [
    'pending' => ['icon' => '⏳', 'label' => 'Aguardando', 'color' => '#f59e0b', 'step' => 1],
    'confirmed' => ['icon' => '✅', 'label' => 'Confirmado', 'color' => '#3b82f6', 'step' => 2],
    'preparing' => ['icon' => '🛒', 'label' => 'Separando', 'color' => '#8b5cf6', 'step' => 3],
    'shopping' => ['icon' => '🛒', 'label' => 'Comprando', 'color' => '#8b5cf6', 'step' => 3],
    'ready' => ['icon' => '📦', 'label' => 'Pronto', 'color' => '#06b6d4', 'step' => 4],
    'delivering' => ['icon' => '🚚', 'label' => 'A caminho', 'color' => '#10b981', 'step' => 5],
    'delivered' => ['icon' => '🎉', 'label' => 'Entregue', 'color' => '#22c55e', 'step' => 6],
];

$status_atual = $pedido['status'];
$config = $status_config[$status_atual] ?? $status_config['pending'];

// Posição na fila
$posicao_fila = null;
$tempo_estimado = null;
$is_delivering = ($status_atual === 'delivering');

if ($is_delivering && $pedido['stop_order']) {
    $posicao_fila = $pedido['stop_order'] - ($pedido['completed_stops'] ?? 0);
    if ($posicao_fila < 1) $posicao_fila = 1;
    $tempo_estimado = $pedido['estimated_delivery_time'];
}

// Mostrar código quando pronto ou entregando
$show_code = in_array($status_atual, ['ready', 'delivering']);

// Tempo restante chat
$chat_minutes_left = null;
if ($status_atual === 'delivered' && $pedido['chat_expires_at']) {
    $expires = strtotime($pedido['chat_expires_at']);
    $chat_minutes_left = max(0, ceil(($expires - time()) / 60));
}
?>

<!-- Banner de Pedido V3 -->
<div class="om-order-banner" id="orderBanner" data-order-id="<?= $pedido['order_id'] ?>">
    <div class="om-banner-container">
        
        <?php if ($status_atual === 'delivered'): ?>
        <!-- Banner Entregue -->
        <div class="om-banner-delivered">
            <div class="om-banner-delivered-content">
                <div class="om-banner-delivered-icon">🎉</div>
                <div class="om-banner-delivered-text">
                    <h3>Entrega realizada!</h3>
                    <p>Obrigado, <?= htmlspecialchars(explode(' ', $pedido['customer_name'])[0]) ?>! 💚</p>
                </div>
            </div>
            <?php if ($chat_minutes_left > 0): ?>
            <div class="om-banner-chat-timer">
                💬 Chat disponível por mais <strong id="chatTimer"><?= $chat_minutes_left ?></strong> min
            </div>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        <!-- Banner Normal -->
        <div class="om-banner-header">
            <div class="om-banner-title">
                <span class="om-banner-greeting">Olá, <?= htmlspecialchars(explode(' ', $pedido['customer_name'])[0]) ?>!</span>
                <span class="om-banner-status-text"><?= $config['label'] ?></span>
            </div>
            <div class="om-banner-order-id">#<?= $pedido['order_id'] ?></div>
        </div>

        <!-- Timeline -->
        <div class="om-banner-timeline">
            <?php 
            $steps = [
                ['icon' => '⏳', 'label' => 'Aguardando'],
                ['icon' => '✅', 'label' => 'Confirmado'],
                ['icon' => '🛒', 'label' => 'Separando'],
                ['icon' => '📦', 'label' => 'Pronto'],
                ['icon' => '🚚', 'label' => 'A caminho'],
            ];
            foreach ($steps as $idx => $step):
                $step_num = $idx + 1;
                $is_active = $config['step'] >= $step_num;
                $is_current = $config['step'] === $step_num;
            ?>
            <div class="om-timeline-step <?= $is_active ? 'active' : '' ?> <?= $is_current ? 'current' : '' ?>">
                <div class="om-timeline-dot"><?= $step['icon'] ?></div>
            </div>
            <?php if ($idx < count($steps) - 1): ?>
            <div class="om-timeline-line <?= $config['step'] > $step_num ? 'active' : '' ?>"></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- Info Entrega -->
        <?php if ($is_delivering && $posicao_fila): ?>
        <div class="om-banner-delivery-info">
            <div class="om-delivery-position">
                <span class="om-delivery-position-number"><?= $posicao_fila ?>º</span>
                <span class="om-delivery-position-text">na fila</span>
            </div>
            <div class="om-delivery-divider"></div>
            <div class="om-delivery-time">
                <span class="om-delivery-time-number">~<?= $tempo_estimado ?? 20 ?></span>
                <span class="om-delivery-time-text">minutos</span>
            </div>
            <?php if ($pedido['delivery_name']): ?>
            <div class="om-delivery-divider"></div>
            <div class="om-delivery-driver">
                <span class="om-delivery-driver-icon">🚴</span>
                <span class="om-delivery-driver-name"><?= htmlspecialchars($pedido['delivery_name']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Código de Entrega com QR -->
        <?php if ($show_code && $pedido['delivery_code']): ?>
        <div class="om-banner-code-section">
            <div class="om-code-header">
                <span>🔑 Código de confirmação</span>
            </div>
            
            <div class="om-code-options">
                <!-- Opção 1: Código texto -->
                <div class="om-code-text" onclick="copyCode('<?= $pedido['delivery_code'] ?>')">
                    <span class="om-code-value"><?= $pedido['delivery_code'] ?></span>
                    <span class="om-code-copy">📋 Copiar</span>
                </div>
                
                <!-- Opção 2: Gerar QR -->
                <button class="om-code-qr-btn" onclick="showDeliveryQR()">
                    <span class="om-qr-icon">📱</span>
                    <span>Gerar QR</span>
                </button>
            </div>
            
            <p class="om-code-hint">Fale o código OU mostre o QR pro entregador</p>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="om-banner-footer">
            <?php if ($pedido['shopper_name']): ?>
            <div class="om-shopper-info">
                <div class="om-shopper-avatar"><?= $pedido['shopper_avatar'] ?? '👩‍🦰' ?></div>
                <div class="om-shopper-details">
                    <span class="om-shopper-name"><?= htmlspecialchars($pedido['shopper_name']) ?></span>
                    <span class="om-shopper-role">Shopper</span>
                </div>
            </div>
            <?php else: ?>
            <div></div>
            <?php endif; ?>
            
            <button class="om-chat-btn" onclick="toggleChat()">
                💬 Chat
                <?php if ($unread > 0): ?>
                <span class="om-chat-badge"><?= $unread ?></span>
                <?php endif; ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal QR Code -->
<div class="om-qr-modal" id="qrModal">
    <div class="om-qr-modal-content">
        <button class="om-qr-close" onclick="closeQRModal()">✕</button>
        <h3>📱 QR Code de Entrega</h3>
        <p>Mostre esse QR para o entregador</p>
        
        <div class="om-qr-display">
            <img id="qrImage" src="" alt="QR Code">
        </div>
        
        <div class="om-qr-code-text">
            <span id="qrCodeText"><?= $pedido['delivery_code'] ?? '' ?></span>
        </div>
        
        <p class="om-qr-hint">O entregador vai escanear para confirmar a entrega</p>
    </div>
</div>

<style>
/* ══════════════════════════════════════════════════════════════
   BANNER V3 - Com QR Code
   ══════════════════════════════════════════════════════════════ */

.om-order-banner {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 9998;
    background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 100%);
    border-bottom: 1px solid rgba(255,255,255,0.1);
    padding: max(env(safe-area-inset-top, 0px), 8px) 16px 12px;
    font-family: 'Inter', -apple-system, sans-serif;
    animation: slideDown 0.4s ease;
}

@keyframes slideDown {
    from { transform: translateY(-100%); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.om-banner-container {
    max-width: 600px;
    margin: 0 auto;
}

/* Header */
.om-banner-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.om-banner-greeting {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #fff;
}

.om-banner-status-text {
    font-size: 12px;
    color: #00d47e;
    font-weight: 600;
}

.om-banner-order-id {
    font-size: 11px;
    color: rgba(255,255,255,0.4);
    font-weight: 600;
}

/* Timeline */
.om-banner-timeline {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 14px;
}

.om-timeline-step { position: relative; }

.om-timeline-dot {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    opacity: 0.4;
    transition: all 0.3s;
}

.om-timeline-step.active .om-timeline-dot {
    background: rgba(0,212,126,0.2);
    opacity: 1;
}

.om-timeline-step.current .om-timeline-dot {
    background: linear-gradient(135deg, #00d47e, #00a35f);
    box-shadow: 0 0 20px rgba(0,212,126,0.4);
    transform: scale(1.1);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(0,212,126,0.4); }
    50% { box-shadow: 0 0 0 10px rgba(0,212,126,0); }
}

.om-timeline-line {
    width: 24px;
    height: 3px;
    background: rgba(255,255,255,0.1);
    margin: 0 4px;
    border-radius: 2px;
}

.om-timeline-line.active {
    background: linear-gradient(90deg, #00d47e, #00a35f);
}

/* Delivery Info */
.om-banner-delivery-info {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    background: rgba(0,212,126,0.1);
    border: 1px solid rgba(0,212,126,0.2);
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 12px;
}

.om-delivery-position,
.om-delivery-time {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.om-delivery-position-number,
.om-delivery-time-number {
    font-size: 20px;
    font-weight: 800;
    color: #00d47e;
}

.om-delivery-position-text,
.om-delivery-time-text {
    font-size: 10px;
    color: rgba(255,255,255,0.5);
    text-transform: uppercase;
}

.om-delivery-divider {
    width: 1px;
    height: 30px;
    background: rgba(255,255,255,0.1);
}

.om-delivery-driver {
    display: flex;
    align-items: center;
    gap: 6px;
}

.om-delivery-driver-icon { font-size: 18px; }
.om-delivery-driver-name { font-size: 13px; font-weight: 600; }

/* ═══ CÓDIGO COM QR ═══ */
.om-banner-code-section {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 12px;
}

.om-code-header {
    font-size: 11px;
    color: rgba(255,255,255,0.5);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 10px;
    text-align: center;
}

.om-code-options {
    display: flex;
    gap: 10px;
    align-items: stretch;
}

.om-code-text {
    flex: 1;
    background: rgba(0,212,126,0.1);
    border: 2px dashed rgba(0,212,126,0.3);
    border-radius: 10px;
    padding: 12px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}

.om-code-text:hover {
    background: rgba(0,212,126,0.15);
}

.om-code-value {
    display: block;
    font-size: 18px;
    font-weight: 900;
    color: #fff;
    letter-spacing: 2px;
    font-family: 'Courier New', monospace;
}

.om-code-copy {
    display: block;
    font-size: 10px;
    color: #00d47e;
    margin-top: 4px;
}

.om-code-qr-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 12px 20px;
    background: linear-gradient(135deg, #00d47e, #00a35f);
    border: none;
    border-radius: 10px;
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.om-code-qr-btn:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 20px rgba(0,212,126,0.3);
}

.om-qr-icon {
    font-size: 20px;
}

.om-code-hint {
    font-size: 10px;
    color: rgba(255,255,255,0.4);
    text-align: center;
    margin-top: 8px;
}

/* Footer */
.om-banner-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.om-shopper-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.om-shopper-avatar {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, #00d47e, #00a35f);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.om-shopper-name {
    display: block;
    font-size: 13px;
    font-weight: 600;
}

.om-shopper-role {
    font-size: 10px;
    color: rgba(255,255,255,0.4);
}

.om-chat-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    background: linear-gradient(135deg, #00d47e, #00a35f);
    border: none;
    border-radius: 50px;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
}

.om-chat-btn:hover {
    transform: scale(1.05);
}

.om-chat-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 20px;
    height: 20px;
    background: #ef4444;
    border-radius: 50%;
    font-size: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Delivered */
.om-banner-delivered {
    text-align: center;
}

.om-banner-delivered-content {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin-bottom: 10px;
}

.om-banner-delivered-icon { font-size: 32px; }
.om-banner-delivered-text h3 { font-size: 16px; color: #00d47e; }
.om-banner-delivered-text p { font-size: 13px; color: rgba(255,255,255,0.7); }

.om-banner-chat-timer {
    background: rgba(255,255,255,0.05);
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 12px;
    color: rgba(255,255,255,0.6);
}

.om-banner-chat-timer strong { color: #00d47e; }

/* ═══ MODAL QR ═══ */
.om-qr-modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.9);
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.om-qr-modal.show {
    display: flex;
}

.om-qr-modal-content {
    background: #0a0a0a;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 24px;
    padding: 32px 24px;
    max-width: 360px;
    width: 100%;
    text-align: center;
    position: relative;
    animation: modalIn 0.3s ease;
}

@keyframes modalIn {
    from { opacity: 0; transform: scale(0.9); }
    to { opacity: 1; transform: scale(1); }
}

.om-qr-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 32px;
    height: 32px;
    background: rgba(255,255,255,0.1);
    border: none;
    border-radius: 50%;
    color: rgba(255,255,255,0.6);
    font-size: 16px;
    cursor: pointer;
}

.om-qr-modal-content h3 {
    font-size: 20px;
    margin-bottom: 8px;
}

.om-qr-modal-content > p {
    font-size: 14px;
    color: rgba(255,255,255,0.6);
    margin-bottom: 24px;
}

.om-qr-display {
    background: #fff;
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 16px;
}

.om-qr-display img {
    width: 200px;
    height: 200px;
}

.om-qr-code-text {
    font-size: 24px;
    font-weight: 900;
    letter-spacing: 4px;
    font-family: 'Courier New', monospace;
    color: #00d47e;
    margin-bottom: 16px;
}

.om-qr-hint {
    font-size: 12px;
    color: rgba(255,255,255,0.4);
}

/* Responsivo */
@media (max-width: 400px) {
    .om-code-options { flex-direction: column; }
    .om-delivery-info { flex-wrap: wrap; gap: 12px; }
    .om-delivery-divider { display: none; }
}
</style>

<script>
const ORDER_ID = <?= $pedido['order_id'] ?>;
const DELIVERY_CODE = '<?= $pedido['delivery_code'] ?? '' ?>';

// Copiar código
function copyCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        const el = document.querySelector('.om-code-copy');
        if (el) {
            el.textContent = '✅ Copiado!';
            setTimeout(() => el.textContent = '📋 Copiar', 2000);
        }
    });
}

// Mostrar QR de entrega
async function showDeliveryQR() {
    const modal = document.getElementById('qrModal');
    const img = document.getElementById('qrImage');
    
    // Gerar URL do QR
    const qrData = 'DELIVERY:' + DELIVERY_CODE;
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(qrData);
    
    img.src = qrUrl;
    document.getElementById('qrCodeText').textContent = DELIVERY_CODE;
    
    modal.classList.add('show');
}

// Fechar modal
function closeQRModal() {
    document.getElementById('qrModal').classList.remove('show');
}

// Fechar com ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeQRModal();
});

// Fechar clicando fora
document.getElementById('qrModal')?.addEventListener('click', (e) => {
    if (e.target.classList.contains('om-qr-modal')) closeQRModal();
});

// Polling para atualizar status
setInterval(() => {
    fetch('/mercado/api/delivery.php?action=customer_status&order_id=' + ORDER_ID)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.order) {
                console.log('Status:', data.order.status);
            }
        })
        .catch(() => {});
}, 30000);
</script>
