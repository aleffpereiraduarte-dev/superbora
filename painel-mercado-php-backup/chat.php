<?php
/**
 * PAINEL DO MERCADO - Chat com Suporte
 * Comunicacao direta com a equipe OneMundo
 * Real-time via Pusher + AJAX fallback polling
 */

session_start();

if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/includes/classes/PusherService.php';
$db = getDB();

$mercado_id = (int)$_SESSION['mercado_id'];
$mercado_nome = $_SESSION['mercado_nome'];

// ═══════════════════════════════════════════════════════════════
// AJAX: Fetch new messages (GET ?action=fetch_messages&after_id=X)
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'fetch_messages') {
    header('Content-Type: application/json; charset=utf-8');
    $after_id = (int)($_GET['after_id'] ?? 0);

    // Mark admin messages as read
    $stmt = $db->prepare("
        UPDATE om_partner_messages SET read_at = NOW()
        WHERE partner_id = ? AND sender_type = 'admin' AND read_at IS NULL
    ");
    $stmt->execute([$mercado_id]);

    // Fetch new messages
    $stmt = $db->prepare("
        SELECT id, sender_type, sender_name, message, created_at, read_at
        FROM om_partner_messages
        WHERE partner_id = ? AND id > ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$mercado_id, $after_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format for frontend
    $formatted = [];
    foreach ($messages as $m) {
        $formatted[] = [
            'id' => (int)$m['id'],
            'sender_type' => $m['sender_type'],
            'sender_name' => $m['sender_name'] ?: 'Suporte',
            'message' => $m['message'],
            'time' => date('H:i', strtotime($m['created_at'])),
            'date' => date('Y-m-d', strtotime($m['created_at'])),
            'read_at' => $m['read_at'],
        ];
    }

    echo json_encode(['success' => true, 'messages' => $formatted]);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// AJAX: Check read status of partner messages
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'check_read') {
    header('Content-Type: application/json; charset=utf-8');
    $stmt = $db->prepare("
        SELECT id FROM om_partner_messages
        WHERE partner_id = ? AND sender_type = 'partner' AND read_at IS NOT NULL
        ORDER BY id DESC LIMIT 50
    ");
    $stmt->execute([$mercado_id]);
    $readIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

    echo json_encode(['success' => true, 'read_ids' => array_map('intval', $readIds)]);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// AJAX POST: Send message without page reload
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        $message = trim($_POST['message'] ?? '');
        if ($message) {
            $stmt = $db->prepare("
                INSERT INTO om_partner_messages
                (partner_id, sender_type, sender_name, message)
                VALUES (?, 'partner', ?, ?)
            ");
            $stmt->execute([$mercado_id, $mercado_nome, $message]);
            $newId = (int)$db->lastInsertId();

            // Trigger Pusher event for real-time delivery
            try {
                PusherService::trigger("chat-partner-{$mercado_id}", 'new-message', [
                    'id' => $newId,
                    'sender_type' => 'partner',
                    'sender_name' => $mercado_nome,
                    'message' => $message,
                    'time' => date('H:i'),
                    'date' => date('Y-m-d'),
                    'read_at' => null,
                ]);
            } catch (Exception $e) {
                error_log("[Chat] Pusher trigger error: " . $e->getMessage());
            }

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message_id' => $newId,
                    'time' => date('H:i'),
                    'date' => date('Y-m-d'),
                ]);
                exit;
            }
        }
        // Fallback: redirect for non-AJAX
        header('Location: chat.php');
        exit;
    }

    if ($action === 'typing') {
        // Broadcast typing indicator via Pusher
        try {
            PusherService::trigger("chat-partner-{$mercado_id}", 'typing', [
                'sender_type' => 'partner',
                'sender_name' => $mercado_nome,
            ]);
        } catch (Exception $e) {
            // Silent fail - typing is non-critical
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true]);
            exit;
        }
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════
// Normal page load: mark admin messages as read + fetch all
// ═══════════════════════════════════════════════════════════════
$stmt = $db->prepare("
    UPDATE om_partner_messages SET read_at = NOW()
    WHERE partner_id = ? AND sender_type = 'admin' AND read_at IS NULL
");
$stmt->execute([$mercado_id]);

$stmt = $db->prepare("
    SELECT * FROM om_partner_messages
    WHERE partner_id = ?
    ORDER BY created_at ASC
");
$stmt->execute([$mercado_id]);
$mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Track last message ID for polling
$lastMessageId = 0;
if (!empty($mensagens)) {
    $lastMessageId = (int)$mensagens[count($mensagens) - 1]['id'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - Painel do Mercado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
    <script src="https://js.pusher.com/8.0/pusher.min.js"></script>
</head>
<body class="om-app-layout">
    <!-- Sidebar -->
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>OneMundo</span>'">
        </div>

        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link"><i class="lucide-layout-dashboard"></i><span>Dashboard</span></a>
            <a href="pedidos.php" class="om-sidebar-link"><i class="lucide-shopping-bag"></i><span>Pedidos</span></a>
            <a href="produtos.php" class="om-sidebar-link"><i class="lucide-package"></i><span>Produtos</span></a>
                <a href="cardapio-ia.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path><path d="M12 18v4"></path><path d="M8 22h8"></path></svg>
                    <span class="om-sidebar-link-text">Cardapio IA</span>
                    <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;">NOVO</span>
                </a>
            <a href="promocoes.php" class="om-sidebar-link"><i class="lucide-percent"></i><span>Promocoes</span></a>
            <a href="categorias.php" class="om-sidebar-link"><i class="lucide-tags"></i><span>Categorias</span></a>
            <a href="faturamento.php" class="om-sidebar-link"><i class="lucide-bar-chart-3"></i><span>Faturamento</span></a>
            <a href="chat.php" class="om-sidebar-link active"><i class="lucide-message-circle"></i><span>Mensagens</span></a>
            <a href="avaliacoes.php" class="om-sidebar-link"><i class="lucide-star"></i><span>Avaliacoes</span></a>
            <a href="horarios.php" class="om-sidebar-link"><i class="lucide-clock"></i><span>Horarios</span></a>
            <a href="perfil.php" class="om-sidebar-link"><i class="lucide-settings"></i><span>Configuracoes</span></a>
        </nav>

        <div class="om-sidebar-footer">
            <a href="logout.php" class="om-sidebar-link"><i class="lucide-log-out"></i><span>Sair</span></a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="om-main-content">
        <header class="om-topbar">
            <button class="om-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="lucide-menu"></i>
            </button>
            <h1 class="om-topbar-title">
                <i class="lucide-message-circle"></i> Chat com Suporte
            </h1>
            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome, ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="om-avatar om-avatar-sm"><?= htmlspecialchars(strtoupper(substr($mercado_nome, 0, 2)), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </header>

        <div class="om-page-content om-chat-container">
            <div class="om-chat-wrapper">
                <!-- Chat Header -->
                <div class="om-chat-header">
                    <div class="om-flex om-items-center om-gap-3">
                        <div class="om-avatar om-avatar-md om-bg-primary">
                            <i class="lucide-headphones"></i>
                        </div>
                        <div>
                            <h3 class="om-font-semibold">Suporte OneMundo</h3>
                            <p class="om-text-sm" id="chatStatus">
                                <span class="om-status-dot online"></span>
                                <span id="statusText">Online</span>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- New message badge (shown when scrolled up) -->
                <div class="om-chat-new-badge" id="newMessageBadge" style="display:none;" onclick="scrollToBottom(true)">
                    <i class="lucide-chevron-down"></i> Nova mensagem
                </div>

                <!-- Mensagens -->
                <div class="om-chat-messages" id="chatMessages">
                    <?php if (empty($mensagens)): ?>
                    <div class="om-chat-welcome" id="chatWelcome">
                        <div class="om-chat-welcome-icon">
                            <i class="lucide-message-circle"></i>
                        </div>
                        <h3>Bem-vindo ao Chat!</h3>
                        <p>Tire suas duvidas, solicite ajuda ou envie sugestoes.<br>Nossa equipe responde rapidamente!</p>
                    </div>
                    <?php else: ?>
                    <?php
                    $ultima_data = '';
                    foreach ($mensagens as $msg):
                        $data_msg = date('Y-m-d', strtotime($msg['created_at']));
                        if ($data_msg != $ultima_data):
                            $ultima_data = $data_msg;
                            $hoje = date('Y-m-d');
                            $ontem = date('Y-m-d', strtotime('-1 day'));
                            if ($data_msg == $hoje) {
                                $data_label = 'Hoje';
                            } elseif ($data_msg == $ontem) {
                                $data_label = 'Ontem';
                            } else {
                                $data_label = date('d/m/Y', strtotime($data_msg));
                            }
                    ?>
                    <div class="om-chat-date-divider">
                        <span><?= $data_label ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="om-chat-message <?= $msg['sender_type'] === 'partner' ? 'sent' : 'received' ?>" data-msg-id="<?= (int)$msg['id'] ?>">
                        <div class="om-chat-bubble">
                            <?php if ($msg['sender_type'] === 'admin'): ?>
                            <div class="om-chat-sender"><?= htmlspecialchars($msg['sender_name'] ?: 'Suporte', ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <div class="om-chat-text"><?= nl2br(htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8')) ?></div>
                            <div class="om-chat-time">
                                <?= date('H:i', strtotime($msg['created_at'])) ?>
                                <?php if ($msg['sender_type'] === 'partner'): ?>
                                <i class="lucide-check<?= $msg['read_at'] ? '-check read' : '' ?>" data-check-id="<?= (int)$msg['id'] ?>"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Typing indicator -->
                    <div class="om-chat-message received om-typing-indicator" id="typingIndicator" style="display:none;">
                        <div class="om-chat-bubble">
                            <div class="om-typing-dots">
                                <span></span><span></span><span></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Input -->
                <form id="chatForm" class="om-chat-input-form" onsubmit="return false;">
                    <div class="om-chat-input-wrapper">
                        <textarea
                            name="message"
                            id="messageInput"
                            class="om-chat-input"
                            placeholder="Digite sua mensagem..."
                            rows="1"
                            required
                        ></textarea>
                        <button type="button" class="om-chat-send-btn" id="sendBtn" onclick="sendMessage()">
                            <i class="lucide-send"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Dicas Rapidas -->
            <div class="om-chat-sidebar">
                <div class="om-card">
                    <div class="om-card-header">
                        <h4 class="om-card-title"><i class="lucide-lightbulb"></i> Duvidas Frequentes</h4>
                    </div>
                    <div class="om-card-body om-p-0">
                        <div class="om-faq-list">
                            <button class="om-faq-item" onclick="enviarPergunta('Como alterar meus horarios de funcionamento?')">
                                <i class="lucide-clock"></i>
                                Horarios de funcionamento
                            </button>
                            <button class="om-faq-item" onclick="enviarPergunta('Como cadastrar uma promocao?')">
                                <i class="lucide-percent"></i>
                                Criar promocoes
                            </button>
                            <button class="om-faq-item" onclick="enviarPergunta('Quando recebo meus repasses?')">
                                <i class="lucide-wallet"></i>
                                Pagamentos e repasses
                            </button>
                            <button class="om-faq-item" onclick="enviarPergunta('Como funciona a taxa de entrega?')">
                                <i class="lucide-truck"></i>
                                Taxa de entrega
                            </button>
                            <button class="om-faq-item" onclick="enviarPergunta('Preciso de ajuda com um pedido')">
                                <i class="lucide-shopping-bag"></i>
                                Ajuda com pedido
                            </button>
                        </div>
                    </div>
                </div>

                <div class="om-card om-mt-4">
                    <div class="om-card-body om-text-center">
                        <i class="lucide-phone om-text-2xl om-text-primary"></i>
                        <p class="om-font-semibold om-mt-2">Urgente?</p>
                        <p class="om-text-sm om-text-muted">Ligue para (11) 99999-9999</p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <style>
    .om-chat-container {
        display: grid;
        grid-template-columns: 1fr 280px;
        gap: var(--om-space-4);
        height: calc(100vh - 80px);
        padding: var(--om-space-4);
    }
    .om-chat-wrapper {
        display: flex;
        flex-direction: column;
        background: white;
        border-radius: var(--om-radius-lg);
        border: 1px solid var(--om-gray-200);
        overflow: hidden;
        position: relative;
    }
    .om-chat-header {
        padding: var(--om-space-4);
        border-bottom: 1px solid var(--om-gray-200);
        background: var(--om-gray-50);
    }
    .om-chat-messages {
        flex: 1;
        overflow-y: auto;
        padding: var(--om-space-4);
        background: #f0f2f5;
    }
    .om-chat-welcome {
        text-align: center;
        padding: var(--om-space-8);
        color: var(--om-gray-600);
    }
    .om-chat-welcome-icon {
        width: 80px;
        height: 80px;
        margin: 0 auto var(--om-space-4);
        background: var(--om-primary-light);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .om-chat-welcome-icon i {
        font-size: 2.5rem;
        color: var(--om-primary);
    }
    .om-chat-date-divider {
        text-align: center;
        margin: var(--om-space-4) 0;
    }
    .om-chat-date-divider span {
        background: rgba(0,0,0,0.1);
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 0.75rem;
        color: var(--om-gray-600);
    }
    .om-chat-message {
        display: flex;
        margin-bottom: var(--om-space-3);
    }
    .om-chat-message.sent {
        justify-content: flex-end;
    }
    .om-chat-bubble {
        max-width: 70%;
        padding: var(--om-space-3);
        border-radius: var(--om-radius-lg);
        background: white;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }
    .om-chat-message.sent .om-chat-bubble {
        background: var(--om-primary);
        color: white;
        border-bottom-right-radius: 4px;
    }
    .om-chat-message.received .om-chat-bubble {
        border-bottom-left-radius: 4px;
    }
    .om-chat-sender {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--om-primary);
        margin-bottom: 4px;
    }
    .om-chat-text {
        line-height: 1.5;
        word-wrap: break-word;
    }
    .om-chat-time {
        font-size: 0.7rem;
        opacity: 0.7;
        text-align: right;
        margin-top: 4px;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 4px;
    }
    .om-chat-time i {
        font-size: 14px;
    }
    .om-chat-time i.read {
        color: #53bdeb;
    }
    .om-chat-input-form {
        padding: var(--om-space-3);
        border-top: 1px solid var(--om-gray-200);
        background: white;
    }
    .om-chat-input-wrapper {
        display: flex;
        gap: var(--om-space-2);
        align-items: flex-end;
    }
    .om-chat-input {
        flex: 1;
        border: 1px solid var(--om-gray-300);
        border-radius: var(--om-radius-lg);
        padding: var(--om-space-3);
        resize: none;
        max-height: 120px;
        font-family: inherit;
        font-size: 1rem;
    }
    .om-chat-input:focus {
        outline: none;
        border-color: var(--om-primary);
    }
    .om-chat-send-btn {
        width: 44px;
        height: 44px;
        border: none;
        border-radius: 50%;
        background: var(--om-primary);
        color: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    .om-chat-send-btn:hover {
        background: var(--om-primary-dark);
        transform: scale(1.05);
    }
    .om-chat-send-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }
    .om-chat-sidebar {
        display: flex;
        flex-direction: column;
    }
    .om-faq-list {
        display: flex;
        flex-direction: column;
    }
    .om-faq-item {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        padding: var(--om-space-3);
        border: none;
        background: none;
        text-align: left;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 0.875rem;
        color: var(--om-gray-700);
    }
    .om-faq-item:hover {
        background: var(--om-gray-50);
        color: var(--om-primary);
    }
    .om-faq-item i {
        color: var(--om-primary);
        font-size: 18px;
    }
    .om-status-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-right: 4px;
    }
    .om-status-dot.online {
        background: var(--om-success);
        box-shadow: 0 0 0 2px rgba(54, 179, 126, 0.3);
    }
    .om-avatar.om-bg-primary {
        background: var(--om-primary);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* New message badge */
    .om-chat-new-badge {
        position: absolute;
        bottom: 80px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--om-primary);
        color: white;
        padding: 8px 20px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
        cursor: pointer;
        z-index: 10;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        display: flex;
        align-items: center;
        gap: 6px;
        animation: badgeBounce 0.3s ease;
    }
    .om-chat-new-badge:hover {
        background: var(--om-primary-dark);
    }
    .om-chat-new-badge i {
        font-size: 16px;
    }

    @keyframes badgeBounce {
        0% { transform: translateX(-50%) translateY(10px); opacity: 0; }
        100% { transform: translateX(-50%) translateY(0); opacity: 1; }
    }

    /* Typing indicator */
    .om-typing-dots {
        display: flex;
        gap: 4px;
        padding: 4px 0;
        align-items: center;
    }
    .om-typing-dots span {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--om-gray-400);
        animation: typingDot 1.4s infinite;
    }
    .om-typing-dots span:nth-child(2) { animation-delay: 0.2s; }
    .om-typing-dots span:nth-child(3) { animation-delay: 0.4s; }

    @keyframes typingDot {
        0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
        30% { transform: translateY(-6px); opacity: 1; }
    }

    /* Message sent animation */
    .om-chat-message.msg-new {
        animation: msgSlideIn 0.25s ease;
    }
    @keyframes msgSlideIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 768px) {
        .om-chat-container {
            grid-template-columns: 1fr;
        }
        .om-chat-sidebar {
            display: none;
        }
    }
    </style>

    <script>
    (function() {
        'use strict';

        // ═══════════════════════════════════════════════════
        // State
        // ═══════════════════════════════════════════════════
        const MERCADO_ID = <?= (int)$mercado_id ?>;
        let lastMessageId = <?= (int)$lastMessageId ?>;
        let lastDateLabel = '<?= !empty($mensagens) ? date('Y-m-d', strtotime(end($mensagens)['created_at'])) : '' ?>';
        let isSending = false;
        let isUserScrolledUp = false;
        let typingTimeout = null;
        let lastTypingSent = 0;
        const TYPING_THROTTLE_MS = 3000;
        const POLL_INTERVAL_MS = 60000;

        // ═══════════════════════════════════════════════════
        // DOM refs
        // ═══════════════════════════════════════════════════
        const chatMessages = document.getElementById('chatMessages');
        const textarea = document.getElementById('messageInput');
        const sendBtn = document.getElementById('sendBtn');
        const typingIndicator = document.getElementById('typingIndicator');
        const newMessageBadge = document.getElementById('newMessageBadge');
        const statusText = document.getElementById('statusText');

        // ═══════════════════════════════════════════════════
        // Audio notification (inline base64 to avoid external dependency)
        // ═══════════════════════════════════════════════════
        let notifSound = null;
        try {
            // Create a simple notification beep using AudioContext
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            notifSound = {
                play: function() {
                    try {
                        const osc = audioCtx.createOscillator();
                        const gain = audioCtx.createGain();
                        osc.connect(gain);
                        gain.connect(audioCtx.destination);
                        osc.frequency.value = 880;
                        osc.type = 'sine';
                        gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.3);
                        osc.start(audioCtx.currentTime);
                        osc.stop(audioCtx.currentTime + 0.3);
                    } catch(e) { /* silent fail */ }
                }
            };
        } catch(e) {
            notifSound = { play: function(){} };
        }

        function playNotificationSound() {
            if (notifSound) notifSound.play();
        }

        // ═══════════════════════════════════════════════════
        // Scroll management
        // ═══════════════════════════════════════════════════
        function scrollToBottom(force) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
            isUserScrolledUp = false;
            newMessageBadge.style.display = 'none';
        }

        function checkIfScrolledUp() {
            const threshold = 100;
            isUserScrolledUp = (chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight) > threshold;
            if (!isUserScrolledUp) {
                newMessageBadge.style.display = 'none';
            }
        }

        chatMessages.addEventListener('scroll', checkIfScrolledUp);

        // Initial scroll
        scrollToBottom();

        // ═══════════════════════════════════════════════════
        // Textarea auto-resize + Enter to send
        // ═══════════════════════════════════════════════════
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';

            // Send typing indicator (throttled)
            const now = Date.now();
            if (now - lastTypingSent > TYPING_THROTTLE_MS) {
                lastTypingSent = now;
                sendTypingIndicator();
            }
        });

        textarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (textarea.value.trim()) {
                    sendMessage();
                }
            }
        });

        // ═══════════════════════════════════════════════════
        // Send message via AJAX
        // ═══════════════════════════════════════════════════
        window.sendMessage = function() {
            const message = textarea.value.trim();
            if (!message || isSending) return;

            isSending = true;
            sendBtn.disabled = true;

            // Optimistic UI: add message immediately
            const tempId = 'temp-' + Date.now();
            appendMessage({
                id: tempId,
                sender_type: 'partner',
                sender_name: '',
                message: message,
                time: new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }),
                date: new Date().toISOString().split('T')[0],
                read_at: null,
            }, true);

            // Remove welcome message if present
            const welcome = document.getElementById('chatWelcome');
            if (welcome) welcome.remove();

            textarea.value = '';
            textarea.style.height = 'auto';
            scrollToBottom();

            const formData = new FormData();
            formData.append('action', 'send');
            formData.append('message', message);

            fetch('chat.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.message_id) {
                    // Update temp message with real ID
                    const tempEl = document.querySelector('[data-msg-id="' + tempId + '"]');
                    if (tempEl) {
                        tempEl.setAttribute('data-msg-id', data.message_id);
                        // Update check icon with real id
                        const checkIcon = tempEl.querySelector('[data-check-id]');
                        if (checkIcon) checkIcon.setAttribute('data-check-id', data.message_id);
                    }
                    lastMessageId = Math.max(lastMessageId, data.message_id);
                }
            })
            .catch(err => {
                console.error('[Chat] Send error:', err);
            })
            .finally(() => {
                isSending = false;
                sendBtn.disabled = false;
                textarea.focus();
            });
        };

        // ═══════════════════════════════════════════════════
        // Typing indicator
        // ═══════════════════════════════════════════════════
        function sendTypingIndicator() {
            const formData = new FormData();
            formData.append('action', 'typing');
            fetch('chat.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            }).catch(() => {});
        }

        function showTypingIndicator() {
            typingIndicator.style.display = 'flex';
            scrollToBottom();
            clearTimeout(typingTimeout);
            typingTimeout = setTimeout(() => {
                typingIndicator.style.display = 'none';
            }, 4000);
        }

        function hideTypingIndicator() {
            typingIndicator.style.display = 'none';
            clearTimeout(typingTimeout);
        }

        // ═══════════════════════════════════════════════════
        // Append message to chat (used by Pusher + AJAX poll)
        // ═══════════════════════════════════════════════════
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function getDateLabel(dateStr) {
            const today = new Date().toISOString().split('T')[0];
            const yesterday = new Date(Date.now() - 86400000).toISOString().split('T')[0];
            if (dateStr === today) return 'Hoje';
            if (dateStr === yesterday) return 'Ontem';
            // Format as dd/mm/yyyy
            const parts = dateStr.split('-');
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }

        function appendMessage(data, isOptimistic) {
            // Check for duplicate
            if (!isOptimistic && document.querySelector('[data-msg-id="' + data.id + '"]')) {
                return;
            }

            // Hide typing indicator if we receive a real message
            if (data.sender_type === 'admin') {
                hideTypingIndicator();
            }

            // Date divider if new day
            if (data.date && data.date !== lastDateLabel) {
                lastDateLabel = data.date;
                const divider = document.createElement('div');
                divider.className = 'om-chat-date-divider';
                divider.innerHTML = '<span>' + getDateLabel(data.date) + '</span>';
                chatMessages.insertBefore(divider, typingIndicator);
            }

            const isSent = data.sender_type === 'partner';
            const msgDiv = document.createElement('div');
            msgDiv.className = 'om-chat-message ' + (isSent ? 'sent' : 'received') + ' msg-new';
            msgDiv.setAttribute('data-msg-id', data.id);

            let checkHtml = '';
            if (isSent) {
                const isRead = data.read_at ? '-check read' : '';
                checkHtml = '<i class="lucide-check' + isRead + '" data-check-id="' + data.id + '"></i>';
            }

            let senderHtml = '';
            if (!isSent && data.sender_name) {
                senderHtml = '<div class="om-chat-sender">' + escapeHtml(data.sender_name) + '</div>';
            }

            msgDiv.innerHTML = '<div class="om-chat-bubble">'
                + senderHtml
                + '<div class="om-chat-text">' + escapeHtml(data.message).replace(/\n/g, '<br>') + '</div>'
                + '<div class="om-chat-time">' + escapeHtml(data.time) + ' ' + checkHtml + '</div>'
                + '</div>';

            chatMessages.insertBefore(msgDiv, typingIndicator);

            if (!isOptimistic && typeof data.id === 'number') {
                lastMessageId = Math.max(lastMessageId, data.id);
            }
        }

        // ═══════════════════════════════════════════════════
        // Pusher real-time subscription
        // ═══════════════════════════════════════════════════
        let pusherConnected = false;
        try {
            const pusher = new Pusher('1cd7a205ab19e56edcfe', {
                cluster: 'sa1',
                forceTLS: true
            });

            const chatChannel = pusher.subscribe('chat-partner-' + MERCADO_ID);

            chatChannel.bind('new-message', function(data) {
                // Skip our own messages (already shown optimistically)
                if (data.sender_type === 'partner') {
                    // But update the temp message ID if we find it
                    return;
                }

                appendMessage(data);

                if (isUserScrolledUp) {
                    newMessageBadge.style.display = 'flex';
                } else {
                    scrollToBottom();
                }

                playNotificationSound();
            });

            chatChannel.bind('typing', function(data) {
                // Only show typing from admin side
                if (data.sender_type !== 'partner') {
                    showTypingIndicator();
                }
            });

            chatChannel.bind('message-read', function(data) {
                // Update check marks for read messages
                if (data.message_ids && Array.isArray(data.message_ids)) {
                    data.message_ids.forEach(function(id) {
                        updateCheckmark(id);
                    });
                }
            });

            pusher.connection.bind('connected', function() {
                pusherConnected = true;
                statusText.innerHTML = 'Online';
            });

            pusher.connection.bind('disconnected', function() {
                pusherConnected = false;
                statusText.innerHTML = 'Reconectando...';
            });

            pusher.connection.bind('error', function() {
                pusherConnected = false;
            });

        } catch(e) {
            console.warn('[Chat] Pusher init failed:', e);
        }

        // ═══════════════════════════════════════════════════
        // Read receipt checkmark update
        // ═══════════════════════════════════════════════════
        function updateCheckmark(msgId) {
            const icon = document.querySelector('[data-check-id="' + msgId + '"]');
            if (icon && !icon.classList.contains('read')) {
                icon.className = 'lucide-check-check read';
            }
        }

        // ═══════════════════════════════════════════════════
        // Fallback AJAX polling (every 60s)
        // ═══════════════════════════════════════════════════
        function fetchNewMessages() {
            fetch('chat.php?action=fetch_messages&after_id=' + lastMessageId)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.messages && data.messages.length > 0) {
                        let hasNew = false;
                        data.messages.forEach(function(msg) {
                            // Skip if already shown (Pusher delivered it)
                            if (document.querySelector('[data-msg-id="' + msg.id + '"]')) return;

                            appendMessage(msg);
                            hasNew = true;
                        });

                        if (hasNew) {
                            if (isUserScrolledUp) {
                                newMessageBadge.style.display = 'flex';
                            } else {
                                scrollToBottom();
                            }
                            playNotificationSound();
                        }
                    }
                })
                .catch(err => console.warn('[Chat] Poll error:', err));
        }

        function checkReadStatus() {
            fetch('chat.php?action=check_read')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.read_ids) {
                        data.read_ids.forEach(function(id) {
                            updateCheckmark(id);
                        });
                    }
                })
                .catch(() => {});
        }

        // Poll every 60 seconds for new messages + read status
        setInterval(fetchNewMessages, POLL_INTERVAL_MS);
        setInterval(checkReadStatus, POLL_INTERVAL_MS);

        // ═══════════════════════════════════════════════════
        // FAQ quick questions
        // ═══════════════════════════════════════════════════
        window.enviarPergunta = function(pergunta) {
            textarea.value = pergunta;
            textarea.focus();
        };

    })();
    </script>
</body>
</html>
