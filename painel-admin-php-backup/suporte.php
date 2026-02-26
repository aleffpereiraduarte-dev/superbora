<?php
/**
 * Painel Admin - Suporte Unificado
 * Chat e atendimento para: Clientes, Mercados, Shoppers
 * Nível DoorDash/Instacart
 */

session_start();
require_once dirname(__DIR__, 2) . '/database.php';

// Verificar autenticação admin
if (!isset($_SESSION['admin_id'])) {
    header('Location: /painel/admin/login.php');
    exit;
}

$db = getDB();
$admin_id = $_SESSION['admin_id'];
$admin_nome = $_SESSION['admin_nome'] ?? 'Admin';

// Processar ações AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'listar_tickets':
                $filtro_status = $_POST['status'] ?? 'aberto';
                $filtro_tipo = $_POST['tipo'] ?? 'todos';
                $busca = $_POST['busca'] ?? '';

                $sql = "SELECT t.*,
                        (SELECT COUNT(*) FROM om_support_messages m WHERE m.ticket_id = t.id AND m.lida = 0 AND m.remetente_tipo = 'entidade') as nao_lidas
                        FROM om_support_tickets t WHERE 1=1";
                $params = [];

                if ($filtro_status !== 'todos') {
                    $sql .= " AND t.status = ?";
                    $params[] = $filtro_status;
                }
                if ($filtro_tipo !== 'todos') {
                    $sql .= " AND t.entidade_tipo = ?";
                    $params[] = $filtro_tipo;
                }
                if ($busca) {
                    $sql .= " AND (t.ticket_number LIKE ? OR t.entidade_nome LIKE ? OR t.assunto LIKE ?)";
                    $params[] = "%$busca%";
                    $params[] = "%$busca%";
                    $params[] = "%$busca%";
                }

                $sql .= " ORDER BY
                    CASE t.prioridade WHEN 'urgente' THEN 1 WHEN 'alta' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END,
                    t.ultima_mensagem_at DESC, t.created_at DESC LIMIT 100";

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['success' => true, 'tickets' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                exit;

            case 'carregar_ticket':
                $ticket_id = intval($_POST['ticket_id']);

                // Buscar ticket
                $stmt = $db->prepare("SELECT * FROM om_support_tickets WHERE id = ?");
                $stmt->execute([$ticket_id]);
                $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$ticket) {
                    echo json_encode(['success' => false, 'error' => 'Ticket não encontrado']);
                    exit;
                }

                // Buscar mensagens
                $stmt = $db->prepare("SELECT * FROM om_support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
                $stmt->execute([$ticket_id]);
                $mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Marcar como lidas
                $db->prepare("UPDATE om_support_messages SET lida = 1, lida_em = NOW() WHERE ticket_id = ? AND remetente_tipo = 'entidade' AND lida = 0")->execute([$ticket_id]);

                // Buscar contexto da entidade
                $contexto = [];
                if ($ticket['entidade_tipo'] === 'mercado') {
                    $stmt = $db->prepare("SELECT partner_id, name, email, phone, saldo_disponivel, total_vendas FROM om_market_partners WHERE partner_id = ?");
                    $stmt->execute([$ticket['entidade_id']]);
                    $contexto = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                } elseif ($ticket['entidade_tipo'] === 'shopper') {
                    $stmt = $db->prepare("SELECT shopper_id, name, phone, rating, saldo, total_entregas, nivel_nome FROM om_market_shoppers WHERE shopper_id = ?");
                    $stmt->execute([$ticket['entidade_id']]);
                    $contexto = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                } elseif ($ticket['entidade_tipo'] === 'cliente') {
                    $stmt = $db->prepare("SELECT customer_id, firstname, lastname, email, telephone FROM oc_customer WHERE customer_id = ?");
                    $stmt->execute([$ticket['entidade_id']]);
                    $contexto = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                }

                // Assumir ticket se não tiver atendente
                if (!$ticket['atendente_id']) {
                    $db->prepare("UPDATE om_support_tickets SET atendente_id = ?, atendente_nome = ?, status = 'em_atendimento' WHERE id = ?")->execute([$admin_id, $admin_nome, $ticket_id]);
                    $ticket['atendente_id'] = $admin_id;
                    $ticket['atendente_nome'] = $admin_nome;
                    $ticket['status'] = 'em_atendimento';
                }

                echo json_encode(['success' => true, 'ticket' => $ticket, 'mensagens' => $mensagens, 'contexto' => $contexto]);
                exit;

            case 'enviar_mensagem':
                $ticket_id = intval($_POST['ticket_id']);
                $mensagem = trim($_POST['mensagem'] ?? '');

                if (!$mensagem) {
                    echo json_encode(['success' => false, 'error' => 'Mensagem vazia']);
                    exit;
                }

                $stmt = $db->prepare("INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_id, remetente_nome, mensagem) VALUES (?, 'admin', ?, ?, ?)");
                $stmt->execute([$ticket_id, $admin_id, $admin_nome, $mensagem]);

                // Atualizar ticket
                $db->prepare("UPDATE om_support_tickets SET ultima_mensagem_at = NOW(), status = 'aguardando_resposta' WHERE id = ?")->execute([$ticket_id]);

                echo json_encode(['success' => true, 'message_id' => $db->lastInsertId()]);
                exit;

            case 'resolver_ticket':
                $ticket_id = intval($_POST['ticket_id']);
                $db->prepare("UPDATE om_support_tickets SET status = 'resolvido', resolvido_em = NOW() WHERE id = ?")->execute([$ticket_id]);
                echo json_encode(['success' => true]);
                exit;

            case 'fechar_ticket':
                $ticket_id = intval($_POST['ticket_id']);
                $db->prepare("UPDATE om_support_tickets SET status = 'fechado' WHERE id = ?")->execute([$ticket_id]);
                echo json_encode(['success' => true]);
                exit;

            case 'alterar_prioridade':
                $ticket_id = intval($_POST['ticket_id']);
                $prioridade = $_POST['prioridade'];
                $db->prepare("UPDATE om_support_tickets SET prioridade = ? WHERE id = ?")->execute([$prioridade, $ticket_id]);
                echo json_encode(['success' => true]);
                exit;

            case 'criar_ticket':
                $tipo = $_POST['entidade_tipo'];
                $id = intval($_POST['entidade_id']);
                $assunto = trim($_POST['assunto']);
                $categoria = $_POST['categoria'] ?? 'outro';
                $mensagem_inicial = trim($_POST['mensagem'] ?? '');

                // Buscar nome da entidade
                $nome = 'Desconhecido';
                if ($tipo === 'mercado') {
                    $stmt = $db->prepare("SELECT name FROM om_market_partners WHERE partner_id = ?");
                    $stmt->execute([$id]);
                    $nome = $stmt->fetchColumn() ?: $nome;
                } elseif ($tipo === 'shopper') {
                    $stmt = $db->prepare("SELECT name FROM om_market_shoppers WHERE shopper_id = ?");
                    $stmt->execute([$id]);
                    $nome = $stmt->fetchColumn() ?: $nome;
                } elseif ($tipo === 'cliente') {
                    $stmt = $db->prepare("SELECT CONCAT(firstname, ' ', lastname) FROM oc_customer WHERE customer_id = ?");
                    $stmt->execute([$id]);
                    $nome = $stmt->fetchColumn() ?: $nome;
                }

                // Gerar número do ticket
                $ticket_number = 'TKT' . date('ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

                $stmt = $db->prepare("INSERT INTO om_support_tickets (ticket_number, entidade_tipo, entidade_id, entidade_nome, assunto, categoria, atendente_id, atendente_nome, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'em_atendimento')");
                $stmt->execute([$ticket_number, $tipo, $id, $nome, $assunto, $categoria, $admin_id, $admin_nome]);
                $ticket_id = $db->lastInsertId();

                if ($mensagem_inicial) {
                    $db->prepare("INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_id, remetente_nome, mensagem) VALUES (?, 'admin', ?, ?, ?)")->execute([$ticket_id, $admin_id, $admin_nome, $mensagem_inicial]);
                    $db->prepare("UPDATE om_support_tickets SET ultima_mensagem_at = NOW() WHERE id = ?")->execute([$ticket_id]);
                }

                echo json_encode(['success' => true, 'ticket_id' => $ticket_id, 'ticket_number' => $ticket_number]);
                exit;

            case 'buscar_entidade':
                $tipo = $_POST['tipo'];
                $termo = $_POST['termo'];

                $resultados = [];
                if ($tipo === 'mercado') {
                    $stmt = $db->prepare("SELECT partner_id as id, name as nome, email, phone as telefone FROM om_market_partners WHERE name LIKE ? OR email LIKE ? LIMIT 10");
                    $stmt->execute(["%$termo%", "%$termo%"]);
                    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } elseif ($tipo === 'shopper') {
                    $stmt = $db->prepare("SELECT shopper_id as id, name as nome, email, phone as telefone FROM om_market_shoppers WHERE name LIKE ? OR email LIKE ? OR phone LIKE ? LIMIT 10");
                    $stmt->execute(["%$termo%", "%$termo%", "%$termo%"]);
                    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } elseif ($tipo === 'cliente') {
                    $stmt = $db->prepare("SELECT customer_id as id, CONCAT(firstname, ' ', lastname) as nome, email, telephone as telefone FROM oc_customer WHERE firstname LIKE ? OR lastname LIKE ? OR email LIKE ? LIMIT 10");
                    $stmt->execute(["%$termo%", "%$termo%", "%$termo%"]);
                    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode(['success' => true, 'resultados' => $resultados]);
                exit;

            case 'estatisticas':
                // Estatísticas gerais
                $stats = [];

                $stmt = $db->query("SELECT COUNT(*) FROM om_support_tickets WHERE status IN ('aberto', 'em_atendimento')");
                $stats['abertos'] = $stmt->fetchColumn();

                $stmt = $db->query("SELECT COUNT(*) FROM om_support_tickets WHERE status = 'aguardando_resposta'");
                $stats['aguardando'] = $stmt->fetchColumn();

                $stmt = $db->query("SELECT COUNT(*) FROM om_support_tickets WHERE status = 'resolvido' AND DATE(resolvido_em) = CURDATE()");
                $stats['resolvidos_hoje'] = $stmt->fetchColumn();

                $stmt = $db->query("SELECT entidade_tipo, COUNT(*) as total FROM om_support_tickets WHERE status IN ('aberto', 'em_atendimento', 'aguardando_resposta') GROUP BY entidade_tipo");
                $stats['por_tipo'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                echo json_encode(['success' => true, 'stats' => $stats]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suporte Unificado - Admin OneMundo</title>
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --sidebar-width: 320px;
            --header-height: 60px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f6fa;
            height: 100vh;
            overflow: hidden;
        }

        .app-container {
            display: flex;
            height: 100vh;
        }

        /* Sidebar de tickets */
        .tickets-sidebar {
            width: var(--sidebar-width);
            background: #fff;
            border-right: 1px solid #e0e0e0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 16px;
            border-bottom: 1px solid #eee;
        }

        .sidebar-header h2 {
            font-size: 18px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 10px 36px 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .search-box i {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .filter-tabs {
            display: flex;
            gap: 4px;
            padding: 12px 16px;
            border-bottom: 1px solid #eee;
            overflow-x: auto;
        }

        .filter-tab {
            padding: 6px 12px;
            border: none;
            background: #f0f0f0;
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }

        .filter-tab.active {
            background: var(--primary-color, #4a6cf7);
            color: white;
        }

        .filter-tab .badge {
            background: rgba(255,255,255,0.3);
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 4px;
            font-size: 10px;
        }

        .tickets-list {
            flex: 1;
            overflow-y: auto;
        }

        .ticket-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.2s;
        }

        .ticket-item:hover {
            background: #f8f9fe;
        }

        .ticket-item.active {
            background: #e8ecff;
            border-left: 3px solid var(--primary-color, #4a6cf7);
        }

        .ticket-item.unread {
            background: #fff8e1;
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 6px;
        }

        .ticket-number {
            font-size: 11px;
            color: #888;
            font-family: monospace;
        }

        .ticket-time {
            font-size: 11px;
            color: #999;
        }

        .ticket-subject {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 4px;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .ticket-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #666;
        }

        .entity-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .entity-badge.cliente { background: #e3f2fd; color: #1976d2; }
        .entity-badge.mercado { background: #e8f5e9; color: #388e3c; }
        .entity-badge.shopper { background: #fff3e0; color: #f57c00; }
        .entity-badge.motorista { background: #fce4ec; color: #c2185b; }

        .priority-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .priority-dot.urgente { background: #e53935; }
        .priority-dot.alta { background: #ff9800; }
        .priority-dot.normal { background: #4caf50; }
        .priority-dot.baixa { background: #9e9e9e; }

        /* Chat area */
        .chat-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #fff;
        }

        .chat-header {
            padding: 16px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-header-info h3 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .chat-header-meta {
            font-size: 12px;
            color: #666;
            display: flex;
            gap: 16px;
        }

        .chat-actions {
            display: flex;
            gap: 8px;
        }

        .chat-actions button {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .chat-actions button:hover {
            background: #f5f5f5;
        }

        .chat-actions button.primary {
            background: #4caf50;
            color: white;
            border-color: #4caf50;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8f9fa;
        }

        .message {
            max-width: 70%;
            margin-bottom: 16px;
            display: flex;
            flex-direction: column;
        }

        .message.admin {
            margin-left: auto;
        }

        .message.entidade {
            margin-right: auto;
        }

        .message-bubble {
            padding: 12px 16px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.5;
        }

        .message.admin .message-bubble {
            background: var(--primary-color, #4a6cf7);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message.entidade .message-bubble {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-bottom-left-radius: 4px;
        }

        .message.sistema .message-bubble {
            background: #f0f0f0;
            color: #666;
            font-size: 12px;
            text-align: center;
            margin: 0 auto;
        }

        .message-info {
            font-size: 11px;
            color: #999;
            margin-top: 4px;
            display: flex;
            gap: 8px;
        }

        .message.admin .message-info {
            justify-content: flex-end;
        }

        .chat-input-container {
            padding: 16px 20px;
            border-top: 1px solid #eee;
            background: #fff;
        }

        .chat-input-wrapper {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        .chat-input-wrapper textarea {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 24px;
            font-size: 14px;
            resize: none;
            max-height: 120px;
            font-family: inherit;
        }

        .send-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: none;
            background: var(--primary-color, #4a6cf7);
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: transform 0.2s;
        }

        .send-btn:hover {
            transform: scale(1.1);
        }

        /* Contexto sidebar */
        .context-sidebar {
            width: 280px;
            background: #fff;
            border-left: 1px solid #e0e0e0;
            overflow-y: auto;
        }

        .context-section {
            padding: 16px;
            border-bottom: 1px solid #eee;
        }

        .context-section h4 {
            font-size: 12px;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
        }

        .context-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .context-item label {
            color: #666;
        }

        .context-item span {
            font-weight: 500;
        }

        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .quick-action-btn {
            padding: 10px 12px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 8px;
            text-align: left;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .quick-action-btn:hover {
            background: #f5f5f5;
            border-color: var(--primary-color);
        }

        /* Empty state */
        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #999;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        /* Stats bar */
        .stats-bar {
            display: flex;
            gap: 16px;
            padding: 12px 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
        }

        .stat-label {
            font-size: 11px;
            opacity: 0.8;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: #fff;
            border-radius: 12px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 18px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .search-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-top: 4px;
        }

        .search-result-item {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
        }

        .search-result-item:hover {
            background: #f5f5f5;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .btn-primary {
            background: var(--primary-color, #4a6cf7);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            width: 100%;
        }

        .new-ticket-btn {
            margin: 12px 16px;
            padding: 10px;
            background: var(--primary-color, #4a6cf7);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar de Tickets -->
        <div class="tickets-sidebar">
            <div class="stats-bar">
                <div class="stat-item">
                    <div class="stat-value" id="stat-abertos">0</div>
                    <div class="stat-label">Abertos</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="stat-aguardando">0</div>
                    <div class="stat-label">Aguardando</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="stat-resolvidos">0</div>
                    <div class="stat-label">Hoje</div>
                </div>
            </div>

            <div class="sidebar-header">
                <h2><i class="fas fa-headset"></i> Suporte</h2>
                <div class="search-box">
                    <input type="text" id="search-tickets" placeholder="Buscar tickets...">
                    <i class="fas fa-search"></i>
                </div>
            </div>

            <div class="filter-tabs">
                <button class="filter-tab active" data-status="aberto">Abertos</button>
                <button class="filter-tab" data-status="em_atendimento">Atendendo</button>
                <button class="filter-tab" data-status="aguardando_resposta">Aguardando</button>
                <button class="filter-tab" data-status="todos">Todos</button>
            </div>

            <div class="filter-tabs" style="padding-top: 0;">
                <button class="filter-tab active" data-tipo="todos">Todos</button>
                <button class="filter-tab" data-tipo="cliente">Clientes</button>
                <button class="filter-tab" data-tipo="mercado">Mercados</button>
                <button class="filter-tab" data-tipo="shopper">Shoppers</button>
            </div>

            <button class="new-ticket-btn" onclick="openNewTicketModal()">
                <i class="fas fa-plus"></i> Novo Ticket
            </button>

            <div class="tickets-list" id="tickets-list">
                <!-- Tickets serão carregados aqui -->
            </div>
        </div>

        <!-- Área do Chat -->
        <div class="chat-container" id="chat-container">
            <div class="empty-state" id="empty-state">
                <i class="fas fa-comments"></i>
                <h3>Selecione um ticket</h3>
                <p>Escolha um ticket na lista para iniciar o atendimento</p>
            </div>

            <div id="chat-area" style="display: none; flex: 1; display: flex; flex-direction: column;">
                <div class="chat-header">
                    <div class="chat-header-info">
                        <h3 id="chat-subject"></h3>
                        <div class="chat-header-meta">
                            <span id="chat-ticket-number"></span>
                            <span id="chat-entity-info"></span>
                        </div>
                    </div>
                    <div class="chat-actions">
                        <button onclick="alterarPrioridade()"><i class="fas fa-flag"></i> Prioridade</button>
                        <button class="primary" onclick="resolverTicket()"><i class="fas fa-check"></i> Resolver</button>
                    </div>
                </div>

                <div class="chat-messages" id="chat-messages">
                    <!-- Mensagens serão carregadas aqui -->
                </div>

                <div class="chat-input-container">
                    <div class="chat-input-wrapper">
                        <textarea id="message-input" placeholder="Digite sua mensagem..." rows="1"></textarea>
                        <button class="send-btn" onclick="enviarMensagem()">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar de Contexto -->
        <div class="context-sidebar" id="context-sidebar" style="display: none;">
            <div class="context-section">
                <h4>Informações</h4>
                <div id="context-info"></div>
            </div>

            <div class="context-section">
                <h4>Ações Rápidas</h4>
                <div class="quick-actions">
                    <button class="quick-action-btn" onclick="abrirPerfil()">
                        <i class="fas fa-user"></i> Ver Perfil Completo
                    </button>
                    <button class="quick-action-btn" onclick="verPedidos()">
                        <i class="fas fa-shopping-bag"></i> Ver Pedidos
                    </button>
                    <button class="quick-action-btn" onclick="verFinanceiro()">
                        <i class="fas fa-wallet"></i> Ver Financeiro
                    </button>
                </div>
            </div>

            <div class="context-section">
                <h4>Respostas Rápidas</h4>
                <div class="quick-actions" id="quick-responses">
                    <button class="quick-action-btn" onclick="inserirResposta('Olá! Como posso ajudar você hoje?')">
                        <i class="fas fa-comment"></i> Saudação
                    </button>
                    <button class="quick-action-btn" onclick="inserirResposta('Vou verificar isso para você. Um momento, por favor.')">
                        <i class="fas fa-search"></i> Verificando
                    </button>
                    <button class="quick-action-btn" onclick="inserirResposta('Problema resolvido! Há algo mais em que posso ajudar?')">
                        <i class="fas fa-check-circle"></i> Resolvido
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Novo Ticket -->
    <div class="modal-overlay" id="modal-new-ticket">
        <div class="modal">
            <div class="modal-header">
                <h3>Novo Ticket de Suporte</h3>
                <button class="modal-close" onclick="closeModal('modal-new-ticket')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Tipo de Entidade</label>
                    <select id="new-ticket-tipo" onchange="limparBuscaEntidade()">
                        <option value="cliente">Cliente</option>
                        <option value="mercado">Mercado</option>
                        <option value="shopper">Shopper</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Buscar</label>
                    <input type="text" id="new-ticket-busca" placeholder="Nome, email ou telefone..." oninput="buscarEntidade()">
                    <div class="search-results" id="search-results" style="display: none;"></div>
                    <input type="hidden" id="new-ticket-entidade-id">
                    <div id="selected-entity" style="margin-top: 8px; font-weight: 500;"></div>
                </div>

                <div class="form-group">
                    <label>Categoria</label>
                    <select id="new-ticket-categoria">
                        <option value="pedido">Pedido</option>
                        <option value="pagamento">Pagamento</option>
                        <option value="entrega">Entrega</option>
                        <option value="produto">Produto</option>
                        <option value="conta">Conta</option>
                        <option value="reclamacao">Reclamação</option>
                        <option value="sugestao">Sugestão</option>
                        <option value="outro">Outro</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Assunto</label>
                    <input type="text" id="new-ticket-assunto" placeholder="Resumo do problema...">
                </div>

                <div class="form-group">
                    <label>Mensagem Inicial (opcional)</label>
                    <textarea id="new-ticket-mensagem" rows="3" placeholder="Descreva o problema..."></textarea>
                </div>

                <button class="btn-primary" onclick="criarTicket()">Criar Ticket</button>
            </div>
        </div>
    </div>

    <script>
        let ticketAtual = null;
        let filtroStatus = 'aberto';
        let filtroTipo = 'todos';
        let refreshInterval;

        // Inicialização
        document.addEventListener('DOMContentLoaded', () => {
            carregarEstatisticas();
            carregarTickets();

            // Auto-refresh a cada 30s
            refreshInterval = setInterval(() => {
                carregarTickets();
                if (ticketAtual) {
                    carregarMensagens(ticketAtual);
                }
            }, 30000);

            // Filtros de status
            document.querySelectorAll('.filter-tab[data-status]').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.filter-tab[data-status]').forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    filtroStatus = tab.dataset.status;
                    carregarTickets();
                });
            });

            // Filtros de tipo
            document.querySelectorAll('.filter-tab[data-tipo]').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.filter-tab[data-tipo]').forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    filtroTipo = tab.dataset.tipo;
                    carregarTickets();
                });
            });

            // Busca
            document.getElementById('search-tickets').addEventListener('input', debounce(carregarTickets, 300));

            // Enter para enviar mensagem
            document.getElementById('message-input').addEventListener('keypress', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    enviarMensagem();
                }
            });

            // Auto-resize textarea
            document.getElementById('message-input').addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });
        });

        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }

        async function carregarEstatisticas() {
            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=estatisticas'
            });
            const data = await response.json();
            if (data.success) {
                document.getElementById('stat-abertos').textContent = data.stats.abertos;
                document.getElementById('stat-aguardando').textContent = data.stats.aguardando;
                document.getElementById('stat-resolvidos').textContent = data.stats.resolvidos_hoje;
            }
        }

        async function carregarTickets() {
            const busca = document.getElementById('search-tickets').value;
            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=listar_tickets&status=${filtroStatus}&tipo=${filtroTipo}&busca=${encodeURIComponent(busca)}`
            });
            const data = await response.json();

            if (data.success) {
                renderizarTickets(data.tickets);
            }
        }

        function renderizarTickets(tickets) {
            const container = document.getElementById('tickets-list');

            if (tickets.length === 0) {
                container.innerHTML = '<div style="padding: 40px; text-align: center; color: #999;">Nenhum ticket encontrado</div>';
                return;
            }

            container.innerHTML = tickets.map(t => `
                <div class="ticket-item ${t.id == ticketAtual ? 'active' : ''} ${t.nao_lidas > 0 ? 'unread' : ''}" onclick="abrirTicket(${t.id})">
                    <div class="ticket-header">
                        <span class="ticket-number">${t.ticket_number}</span>
                        <span class="ticket-time">${formatarTempo(t.ultima_mensagem_at || t.created_at)}</span>
                    </div>
                    <div class="ticket-subject">${escapeHtml(t.assunto)}</div>
                    <div class="ticket-meta">
                        <span class="entity-badge ${t.entidade_tipo}">${t.entidade_tipo}</span>
                        <span>${escapeHtml(t.entidade_nome || 'Sem nome')}</span>
                        <span class="priority-dot ${t.prioridade}" title="${t.prioridade}"></span>
                        ${t.nao_lidas > 0 ? `<span style="color: #e53935; font-weight: 600;">${t.nao_lidas} nova(s)</span>` : ''}
                    </div>
                </div>
            `).join('');
        }

        async function abrirTicket(id) {
            ticketAtual = id;

            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=carregar_ticket&ticket_id=${id}`
            });
            const data = await response.json();

            if (data.success) {
                document.getElementById('empty-state').style.display = 'none';
                document.getElementById('chat-area').style.display = 'flex';
                document.getElementById('context-sidebar').style.display = 'block';

                // Atualizar header
                document.getElementById('chat-subject').textContent = data.ticket.assunto;
                document.getElementById('chat-ticket-number').textContent = data.ticket.ticket_number;
                document.getElementById('chat-entity-info').textContent = `${data.ticket.entidade_tipo}: ${data.ticket.entidade_nome}`;

                // Renderizar mensagens
                renderizarMensagens(data.mensagens);

                // Atualizar contexto
                renderizarContexto(data.ticket, data.contexto);

                // Marcar como ativo na lista
                document.querySelectorAll('.ticket-item').forEach(item => {
                    item.classList.remove('active');
                    if (item.onclick.toString().includes(id)) {
                        item.classList.add('active');
                    }
                });

                carregarTickets();
            }
        }

        function renderizarMensagens(mensagens) {
            const container = document.getElementById('chat-messages');

            container.innerHTML = mensagens.map(m => `
                <div class="message ${m.remetente_tipo}">
                    <div class="message-bubble">${escapeHtml(m.mensagem).replace(/\n/g, '<br>')}</div>
                    <div class="message-info">
                        <span>${m.remetente_nome || m.remetente_tipo}</span>
                        <span>${formatarDataHora(m.created_at)}</span>
                    </div>
                </div>
            `).join('');

            // Scroll para baixo
            container.scrollTop = container.scrollHeight;
        }

        function renderizarContexto(ticket, contexto) {
            let html = '';

            if (ticket.entidade_tipo === 'mercado' && contexto.partner_id) {
                html = `
                    <div class="context-item"><label>ID:</label><span>${contexto.partner_id}</span></div>
                    <div class="context-item"><label>Nome:</label><span>${escapeHtml(contexto.name)}</span></div>
                    <div class="context-item"><label>Email:</label><span>${escapeHtml(contexto.email || '-')}</span></div>
                    <div class="context-item"><label>Telefone:</label><span>${escapeHtml(contexto.phone || '-')}</span></div>
                    <div class="context-item"><label>Saldo:</label><span>R$ ${parseFloat(contexto.saldo_disponivel || 0).toFixed(2)}</span></div>
                    <div class="context-item"><label>Total Vendas:</label><span>R$ ${parseFloat(contexto.total_vendas || 0).toFixed(2)}</span></div>
                `;
            } else if (ticket.entidade_tipo === 'shopper' && contexto.shopper_id) {
                html = `
                    <div class="context-item"><label>ID:</label><span>${contexto.shopper_id}</span></div>
                    <div class="context-item"><label>Nome:</label><span>${escapeHtml(contexto.name)}</span></div>
                    <div class="context-item"><label>Telefone:</label><span>${escapeHtml(contexto.phone || '-')}</span></div>
                    <div class="context-item"><label>Rating:</label><span>⭐ ${parseFloat(contexto.rating || 5).toFixed(1)}</span></div>
                    <div class="context-item"><label>Nível:</label><span>${contexto.nivel_nome || 'Iniciante'}</span></div>
                    <div class="context-item"><label>Entregas:</label><span>${contexto.total_entregas || 0}</span></div>
                    <div class="context-item"><label>Saldo:</label><span>R$ ${parseFloat(contexto.saldo || 0).toFixed(2)}</span></div>
                `;
            } else if (ticket.entidade_tipo === 'cliente' && contexto.customer_id) {
                html = `
                    <div class="context-item"><label>ID:</label><span>${contexto.customer_id}</span></div>
                    <div class="context-item"><label>Nome:</label><span>${escapeHtml(contexto.firstname + ' ' + contexto.lastname)}</span></div>
                    <div class="context-item"><label>Email:</label><span>${escapeHtml(contexto.email || '-')}</span></div>
                    <div class="context-item"><label>Telefone:</label><span>${escapeHtml(contexto.telephone || '-')}</span></div>
                `;
            } else {
                html = '<div class="context-item"><label>Sem dados disponíveis</label></div>';
            }

            document.getElementById('context-info').innerHTML = html;
        }

        async function enviarMensagem() {
            const input = document.getElementById('message-input');
            const mensagem = input.value.trim();

            if (!mensagem || !ticketAtual) return;

            input.value = '';
            input.style.height = 'auto';

            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=enviar_mensagem&ticket_id=${ticketAtual}&mensagem=${encodeURIComponent(mensagem)}`
            });

            const data = await response.json();
            if (data.success) {
                abrirTicket(ticketAtual);
            }
        }

        async function resolverTicket() {
            if (!ticketAtual) return;

            if (confirm('Marcar este ticket como resolvido?')) {
                await fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=resolver_ticket&ticket_id=${ticketAtual}`
                });

                ticketAtual = null;
                document.getElementById('empty-state').style.display = 'flex';
                document.getElementById('chat-area').style.display = 'none';
                document.getElementById('context-sidebar').style.display = 'none';
                carregarTickets();
                carregarEstatisticas();
            }
        }

        function alterarPrioridade() {
            if (!ticketAtual) return;

            const prioridade = prompt('Nova prioridade (baixa, normal, alta, urgente):');
            if (prioridade && ['baixa', 'normal', 'alta', 'urgente'].includes(prioridade)) {
                fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=alterar_prioridade&ticket_id=${ticketAtual}&prioridade=${prioridade}`
                }).then(() => carregarTickets());
            }
        }

        function inserirResposta(texto) {
            const input = document.getElementById('message-input');
            input.value = texto;
            input.focus();
        }

        function openNewTicketModal() {
            document.getElementById('modal-new-ticket').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function limparBuscaEntidade() {
            document.getElementById('new-ticket-busca').value = '';
            document.getElementById('new-ticket-entidade-id').value = '';
            document.getElementById('selected-entity').textContent = '';
            document.getElementById('search-results').style.display = 'none';
        }

        async function buscarEntidade() {
            const tipo = document.getElementById('new-ticket-tipo').value;
            const termo = document.getElementById('new-ticket-busca').value;

            if (termo.length < 2) {
                document.getElementById('search-results').style.display = 'none';
                return;
            }

            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=buscar_entidade&tipo=${tipo}&termo=${encodeURIComponent(termo)}`
            });

            const data = await response.json();

            if (data.success && data.resultados.length > 0) {
                const container = document.getElementById('search-results');
                container.innerHTML = data.resultados.map(r => `
                    <div class="search-result-item" onclick="selecionarEntidade(${r.id}, '${escapeHtml(r.nome)}')">
                        <strong>${escapeHtml(r.nome)}</strong><br>
                        <small>${escapeHtml(r.email || r.telefone || '')}</small>
                    </div>
                `).join('');
                container.style.display = 'block';
            } else {
                document.getElementById('search-results').style.display = 'none';
            }
        }

        function selecionarEntidade(id, nome) {
            document.getElementById('new-ticket-entidade-id').value = id;
            document.getElementById('selected-entity').textContent = `✓ ${nome}`;
            document.getElementById('search-results').style.display = 'none';
        }

        async function criarTicket() {
            const tipo = document.getElementById('new-ticket-tipo').value;
            const id = document.getElementById('new-ticket-entidade-id').value;
            const categoria = document.getElementById('new-ticket-categoria').value;
            const assunto = document.getElementById('new-ticket-assunto').value;
            const mensagem = document.getElementById('new-ticket-mensagem').value;

            if (!id) {
                alert('Selecione uma entidade');
                return;
            }

            if (!assunto) {
                alert('Digite o assunto');
                return;
            }

            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=criar_ticket&entidade_tipo=${tipo}&entidade_id=${id}&categoria=${categoria}&assunto=${encodeURIComponent(assunto)}&mensagem=${encodeURIComponent(mensagem)}`
            });

            const data = await response.json();

            if (data.success) {
                closeModal('modal-new-ticket');
                carregarTickets();
                abrirTicket(data.ticket_id);
            } else {
                alert(data.error || 'Erro ao criar ticket');
            }
        }

        function formatarTempo(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const now = new Date();
            const diff = (now - date) / 1000;

            if (diff < 60) return 'agora';
            if (diff < 3600) return Math.floor(diff / 60) + 'min';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h';
            return Math.floor(diff / 86400) + 'd';
        }

        function formatarDataHora(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleString('pt-BR', {day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'});
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function abrirPerfil() {
            // Implementar navegação para perfil completo
            alert('Abrindo perfil...');
        }

        function verPedidos() {
            // Implementar navegação para pedidos
            alert('Abrindo pedidos...');
        }

        function verFinanceiro() {
            // Implementar navegação para financeiro
            alert('Abrindo financeiro...');
        }
    </script>
</body>
</html>
