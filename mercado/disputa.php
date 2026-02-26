<?php
/**
 * ONEMUNDO - DETALHES DA DISPUTA
 * Visualizacao e interacao com uma disputa especifica
 */

require_once __DIR__ . '/auth-guard.php';

$_oc_root = dirname(__DIR__);
if (file_exists($_oc_root . '/config.php')) {
    require_once $_oc_root . '/config.php';
}

$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$customer_id = $_SESSION['customer_id'] ?? 0;

if (!$customer_id) {
    header('Location: /mercado/mercado-login.php');
    exit;
}

$dispute_id = intval($_GET['id'] ?? 0);
$disputa = null;
$mensagens = [];
$produtos = [];
$erro = '';
$sucesso = '';

// Buscar disputa
if ($dispute_id) {
    $stmt = $pdo->prepare("
        SELECT d.*, v.nome_loja as vendedor_nome, v.logo as vendedor_logo,
               o.total as pedido_total, o.date_added as pedido_data
        FROM om_disputes d
        LEFT JOIN om_vendedores v ON d.seller_id = v.vendedor_id
        LEFT JOIN oc_order o ON d.order_id = o.order_id
        WHERE d.id = ? AND d.customer_id = ?
    ");
    $stmt->execute([$dispute_id, $customer_id]);
    $disputa = $stmt->fetch();

    if ($disputa) {
        // Buscar mensagens
        $stmt = $pdo->prepare("
            SELECT * FROM om_dispute_messages
            WHERE dispute_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$dispute_id]);
        $mensagens = $stmt->fetchAll();

        // Buscar produtos do pedido
        $stmt = $pdo->prepare("
            SELECT op.*, pd.name as produto_nome, p.image as produto_imagem
            FROM oc_order_product op
            LEFT JOIN oc_product p ON op.product_id = p.product_id
            LEFT JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 2
            WHERE op.order_id = ?
        ");
        $stmt->execute([$disputa['order_id']]);
        $produtos = $stmt->fetchAll();
    }
}

// Processar envio de mensagem
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $disputa) {
    $mensagem = trim($_POST['mensagem'] ?? '');

    if (empty($mensagem)) {
        $erro = 'Digite uma mensagem';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO om_dispute_messages (dispute_id, sender_type, sender_id, mensagem)
                VALUES (?, 'customer', ?, ?)
            ");
            $stmt->execute([$dispute_id, $customer_id, $mensagem]);

            // Atualizar status
            $pdo->prepare("UPDATE om_disputes SET status = 'aguardando_vendedor' WHERE id = ?")->execute([$dispute_id]);

            $sucesso = 'Mensagem enviada com sucesso';

            // Recarregar mensagens
            $stmt = $pdo->prepare("SELECT * FROM om_dispute_messages WHERE dispute_id = ? ORDER BY created_at ASC");
            $stmt->execute([$dispute_id]);
            $mensagens = $stmt->fetchAll();

        } catch (Exception $e) {
            $erro = 'Erro ao enviar mensagem';
        }
    }
}

// Helper para imagem
function getImgUrl($img) {
    if (empty($img)) return '/image/placeholder.png';
    if (strpos($img, 'http') === 0) return $img;
    return '/image/' . $img;
}

if (!$disputa) {
    header('Location: /mercado/minhas-disputas.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disputa #<?= $dispute_id ?> - OneMundo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #f1f5f9; min-height: 100vh; }

        .header {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-back {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
        }

        .header h1 { font-size: 1.1rem; }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert.error { background: #fee2e2; color: #991b1b; }
        .alert.success { background: #d1fae5; color: #065f46; }

        .disputa-info {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }

        .disputa-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .disputa-titulo {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1e293b;
        }

        .disputa-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-aberta { background: #fef3c7; color: #92400e; }
        .status-aguardando_vendedor { background: #dbeafe; color: #1d4ed8; }
        .status-aguardando_cliente { background: #fee2e2; color: #991b1b; }
        .status-em_analise { background: #e0e7ff; color: #3730a3; }
        .status-mediacao { background: #fce7f3; color: #9d174d; }
        .status-resolvida_cliente, .status-resolvida_vendedor { background: #d1fae5; color: #065f46; }
        .status-encerrada { background: #f3f4f6; color: #6b7280; }

        .disputa-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .meta-label {
            font-size: 12px;
            color: #64748b;
        }

        .meta-value {
            font-weight: 600;
            color: #1e293b;
        }

        .disputa-motivo {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
        }

        .disputa-motivo-label {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 8px;
        }

        .disputa-motivo-text {
            color: #1e293b;
            line-height: 1.6;
        }

        .produtos-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .produtos-title {
            font-size: 14px;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 16px;
        }

        .produto-item {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .produto-item:last-child { border-bottom: none; }

        .produto-img {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: contain;
            background: #f8fafc;
        }

        .produto-info { flex: 1; }
        .produto-nome { font-weight: 600; color: #1e293b; font-size: 14px; }
        .produto-qtd { font-size: 13px; color: #64748b; }
        .produto-preco { font-size: 14px; font-weight: 600; color: #10b981; }

        .chat-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }

        .chat-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .chat-header h3 {
            font-size: 14px;
            color: #475569;
        }

        .chat-messages {
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .message {
            display: flex;
            flex-direction: column;
            max-width: 80%;
        }

        .message.customer { align-self: flex-end; }
        .message.seller, .message.admin { align-self: flex-start; }

        .message-bubble {
            padding: 12px 16px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.5;
        }

        .message.customer .message-bubble {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message.seller .message-bubble {
            background: #f1f5f9;
            color: #1e293b;
            border-bottom-left-radius: 4px;
        }

        .message.admin .message-bubble {
            background: #dbeafe;
            color: #1e40af;
            border-bottom-left-radius: 4px;
        }

        .message-meta {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
            padding: 0 4px;
        }

        .message.customer .message-meta { text-align: right; }

        .chat-empty {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }

        .chat-input {
            padding: 16px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 12px;
        }

        .chat-input textarea {
            flex: 1;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            resize: none;
            font-size: 14px;
            font-family: inherit;
        }

        .chat-input textarea:focus {
            outline: none;
            border-color: #f59e0b;
        }

        .chat-input button {
            padding: 12px 24px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chat-input button:disabled {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
        }

        @media (max-width: 600px) {
            .disputa-header { flex-direction: column; }
            .message { max-width: 90%; }
        }
    </style>
</head>
<body>

<header class="header">
    <a href="/mercado/minhas-disputas.php" class="header-back">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h1><i class="fas fa-gavel"></i> Disputa #<?= $dispute_id ?></h1>
</header>

<div class="container">

    <?php if ($erro): ?>
    <div class="alert error">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($erro) ?>
    </div>
    <?php endif; ?>

    <?php if ($sucesso): ?>
    <div class="alert success">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($sucesso) ?>
    </div>
    <?php endif; ?>

    <div class="disputa-info">
        <div class="disputa-header">
            <div class="disputa-titulo">
                <?= match($disputa['tipo']) {
                    'nao_recebido' => 'Produto nao recebido',
                    'diferente_anunciado' => 'Produto diferente do anunciado',
                    'defeito' => 'Produto com defeito',
                    'arrependimento' => 'Arrependimento da compra',
                    'devolucao' => 'Devolucao',
                    default => 'Outro motivo'
                } ?>
            </div>
            <span class="disputa-status status-<?= $disputa['status'] ?>">
                <?= match($disputa['status']) {
                    'aberta' => '<i class="fas fa-clock"></i> Aberta',
                    'aguardando_vendedor' => '<i class="fas fa-store"></i> Aguardando Vendedor',
                    'aguardando_cliente' => '<i class="fas fa-user"></i> Aguardando Voce',
                    'em_analise' => '<i class="fas fa-search"></i> Em Analise',
                    'mediacao' => '<i class="fas fa-balance-scale"></i> Em Mediacao',
                    'resolvida_cliente' => '<i class="fas fa-check"></i> Resolvida a seu favor',
                    'resolvida_vendedor' => '<i class="fas fa-check"></i> Resolvida',
                    'encerrada' => '<i class="fas fa-times"></i> Encerrada',
                    default => $disputa['status']
                } ?>
            </span>
        </div>

        <div class="disputa-meta">
            <div class="meta-item">
                <span class="meta-label">Pedido</span>
                <span class="meta-value">#<?= $disputa['order_id'] ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Vendedor</span>
                <span class="meta-value"><?= htmlspecialchars($disputa['vendedor_nome'] ?? 'N/A') ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Valor do Pedido</span>
                <span class="meta-value">R$ <?= number_format($disputa['pedido_total'] ?? 0, 2, ',', '.') ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Aberta em</span>
                <span class="meta-value"><?= date('d/m/Y H:i', strtotime($disputa['data_abertura'])) ?></span>
            </div>
        </div>

        <div class="disputa-motivo">
            <div class="disputa-motivo-label">Motivo da disputa</div>
            <div class="disputa-motivo-text"><?= nl2br(htmlspecialchars($disputa['motivo'])) ?></div>
        </div>
    </div>

    <?php if (!empty($produtos)): ?>
    <div class="produtos-card">
        <div class="produtos-title"><i class="fas fa-shopping-bag"></i> Produtos do Pedido</div>
        <?php foreach ($produtos as $p): ?>
        <div class="produto-item">
            <img src="<?= getImgUrl($p['produto_imagem']) ?>" alt="" class="produto-img">
            <div class="produto-info">
                <div class="produto-nome"><?= htmlspecialchars($p['produto_nome'] ?? $p['name']) ?></div>
                <div class="produto-qtd">Qtd: <?= $p['quantity'] ?></div>
            </div>
            <div class="produto-preco">R$ <?= number_format($p['total'], 2, ',', '.') ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="chat-card">
        <div class="chat-header">
            <h3><i class="fas fa-comments"></i> Conversa</h3>
        </div>

        <div class="chat-messages" id="chatMessages">
            <?php if (empty($mensagens)): ?>
            <div class="chat-empty">
                <i class="fas fa-comments" style="font-size: 48px; color: #e2e8f0; margin-bottom: 12px;"></i>
                <p>Nenhuma mensagem ainda.<br>Inicie a conversa abaixo.</p>
            </div>
            <?php else: ?>
                <?php foreach ($mensagens as $m): ?>
                <div class="message <?= $m['sender_type'] ?>">
                    <div class="message-bubble"><?= nl2br(htmlspecialchars($m['mensagem'])) ?></div>
                    <div class="message-meta">
                        <?= $m['sender_type'] === 'customer' ? 'Voce' : ($m['sender_type'] === 'seller' ? 'Vendedor' : 'Suporte') ?>
                        - <?= date('d/m H:i', strtotime($m['created_at'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (!in_array($disputa['status'], ['encerrada', 'resolvida_cliente', 'resolvida_vendedor'])): ?>
        <form method="POST" class="chat-input">
            <textarea name="mensagem" rows="2" placeholder="Digite sua mensagem..." required></textarea>
            <button type="submit">
                <i class="fas fa-paper-plane"></i> Enviar
            </button>
        </form>
        <?php endif; ?>
    </div>

</div>

<script>
// Scroll to bottom of chat
const chatMessages = document.getElementById('chatMessages');
if (chatMessages) {
    chatMessages.scrollTop = chatMessages.scrollHeight;
}
</script>

</body>
</html>
