<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO - MINHAS DISPUTAS E DEVOLUCOES
 * Area do cliente para abrir e acompanhar disputas
 * ══════════════════════════════════════════════════════════════════════════════
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

// Filtro
$filtro = $_GET['filtro'] ?? 'todas';

// Buscar disputas do cliente
$disputas = [];
try {
    $sql = "
        SELECT d.*, v.nome_loja as vendedor_nome,
               o.total as pedido_total
        FROM om_disputes d
        LEFT JOIN om_vendedores v ON d.seller_id = v.vendedor_id
        LEFT JOIN oc_order o ON d.order_id = o.order_id
        WHERE d.customer_id = ?
    ";

    if ($filtro === 'abertas') {
        $sql .= " AND d.status IN ('aberta', 'aguardando_vendedor', 'aguardando_cliente', 'em_analise', 'mediacao')";
    } elseif ($filtro === 'resolvidas') {
        $sql .= " AND d.status IN ('resolvida_cliente', 'resolvida_vendedor', 'encerrada')";
    }

    $sql .= " ORDER BY d.data_abertura DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$customer_id]);
    $disputas = $stmt->fetchAll();
} catch (Exception $e) {
    // Tabela pode nao existir ainda
}

// Buscar pedidos recentes para abrir disputa
$pedidos_recentes = [];
try {
    $stmt = $pdo->prepare("
        SELECT o.order_id, o.total, o.date_added,
               os.name as status_nome
        FROM oc_order o
        LEFT JOIN oc_order_status os ON o.order_status_id = os.order_status_id AND os.language_id = 2
        WHERE o.customer_id = ? AND o.order_status_id > 0
        ORDER BY o.date_added DESC
        LIMIT 10
    ");
    $stmt->execute([$customer_id]);
    $pedidos_recentes = $stmt->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disputas e Devolucoes - OneMundo</title>
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

        .header h1 { font-size: 1.3rem; }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .btn-nova-disputa {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 20px;
            text-decoration: none;
        }

        .filtros {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            overflow-x: auto;
        }

        .filtro {
            padding: 10px 18px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            white-space: nowrap;
        }

        .filtro:hover { border-color: #f59e0b; color: #f59e0b; }
        .filtro.active { background: #f59e0b; color: white; border-color: #f59e0b; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
        }

        .empty-state i {
            font-size: 64px;
            color: #cbd5e1;
            margin-bottom: 20px;
        }

        .empty-state h2 {
            font-size: 1.3rem;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .empty-state p { color: #64748b; }

        .disputa-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }

        .disputa-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .disputa-id {
            font-size: 12px;
            color: #64748b;
        }

        .disputa-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-aberta { background: #fef3c7; color: #92400e; }
        .status-aguardando_vendedor { background: #dbeafe; color: #1d4ed8; }
        .status-aguardando_cliente { background: #fee2e2; color: #991b1b; }
        .status-em_analise { background: #e0e7ff; color: #3730a3; }
        .status-mediacao { background: #fce7f3; color: #9d174d; }
        .status-resolvida_cliente, .status-resolvida_vendedor { background: #d1fae5; color: #065f46; }
        .status-encerrada { background: #f3f4f6; color: #6b7280; }

        .disputa-tipo {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .disputa-motivo {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 12px;
        }

        .disputa-info {
            display: flex;
            gap: 20px;
            font-size: 13px;
            color: #64748b;
        }

        .disputa-info span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .disputa-actions {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #f1f5f9;
        }

        .btn-ver {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #f1f5f9;
            color: #475569;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }

        .btn-ver:hover { background: #e2e8f0; }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active { display: flex; }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 24px;
            width: 100%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            font-size: 1.2rem;
        }

        .modal-close {
            width: 36px;
            height: 36px;
            background: #f1f5f9;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 18px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
        }

        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #f59e0b;
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
    </style>
</head>
<body>

<header class="header">
    <a href="/mercado/conta.php" class="header-back">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h1><i class="fas fa-gavel"></i> Disputas e Devolucoes</h1>
</header>

<div class="container">

    <button class="btn-nova-disputa" onclick="abrirModal()">
        <i class="fas fa-plus"></i> Abrir Nova Disputa
    </button>

    <div class="filtros">
        <a href="?filtro=todas" class="filtro <?= $filtro === 'todas' ? 'active' : '' ?>">Todas</a>
        <a href="?filtro=abertas" class="filtro <?= $filtro === 'abertas' ? 'active' : '' ?>">Em Andamento</a>
        <a href="?filtro=resolvidas" class="filtro <?= $filtro === 'resolvidas' ? 'active' : '' ?>">Resolvidas</a>
    </div>

    <?php if (empty($disputas)): ?>
    <div class="empty-state">
        <i class="fas fa-gavel"></i>
        <h2>Nenhuma disputa encontrada</h2>
        <p>Voce nao possui disputas ou devolucoes abertas.</p>
    </div>
    <?php else: ?>

    <?php foreach ($disputas as $d): ?>
    <div class="disputa-card">
        <div class="disputa-header">
            <span class="disputa-id">#<?= $d['id'] ?></span>
            <span class="disputa-status status-<?= $d['status'] ?>">
                <?= match($d['status']) {
                    'aberta' => '<i class="fas fa-clock"></i> Aberta',
                    'aguardando_vendedor' => '<i class="fas fa-store"></i> Aguardando Vendedor',
                    'aguardando_cliente' => '<i class="fas fa-user"></i> Aguardando Voce',
                    'em_analise' => '<i class="fas fa-search"></i> Em Analise',
                    'mediacao' => '<i class="fas fa-balance-scale"></i> Em Mediacao',
                    'resolvida_cliente' => '<i class="fas fa-check"></i> Resolvida (seu favor)',
                    'resolvida_vendedor' => '<i class="fas fa-check"></i> Resolvida',
                    'encerrada' => '<i class="fas fa-times"></i> Encerrada',
                    default => $d['status']
                } ?>
            </span>
        </div>

        <div class="disputa-tipo">
            <?= match($d['tipo']) {
                'nao_recebido' => 'Produto nao recebido',
                'diferente_anunciado' => 'Produto diferente do anunciado',
                'defeito' => 'Produto com defeito',
                'arrependimento' => 'Arrependimento da compra',
                'devolucao' => 'Devolucao',
                default => 'Outro motivo'
            } ?>
        </div>

        <div class="disputa-motivo">
            <?= htmlspecialchars(substr($d['motivo'], 0, 100)) ?>...
        </div>

        <div class="disputa-info">
            <span><i class="fas fa-shopping-bag"></i> Pedido #<?= $d['order_id'] ?></span>
            <span><i class="fas fa-store"></i> <?= htmlspecialchars($d['vendedor_nome'] ?? 'Vendedor') ?></span>
            <span><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($d['data_abertura'])) ?></span>
        </div>

        <div class="disputa-actions">
            <a href="/mercado/disputa.php?id=<?= $d['id'] ?>" class="btn-ver">
                <i class="fas fa-eye"></i> Ver Detalhes
            </a>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

</div>

<!-- Modal Nova Disputa -->
<div class="modal" id="modalDisputa">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Abrir Nova Disputa</h2>
            <button class="modal-close" onclick="fecharModal()">&times;</button>
        </div>

        <form method="POST" action="/mercado/api/disputa.php">
            <input type="hidden" name="action" value="criar">

            <div class="form-group">
                <label>Selecione o Pedido</label>
                <select name="order_id" required>
                    <option value="">-- Escolha um pedido --</option>
                    <?php foreach ($pedidos_recentes as $p): ?>
                    <option value="<?= $p['order_id'] ?>">
                        Pedido #<?= $p['order_id'] ?> - R$ <?= number_format($p['total'], 2, ',', '.') ?>
                        (<?= date('d/m/Y', strtotime($p['date_added'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Tipo do Problema</label>
                <select name="tipo" required>
                    <option value="">-- Escolha --</option>
                    <option value="nao_recebido">Nao recebi o produto</option>
                    <option value="diferente_anunciado">Produto diferente do anunciado</option>
                    <option value="defeito">Produto com defeito</option>
                    <option value="arrependimento">Quero devolver (arrependimento)</option>
                    <option value="outro">Outro motivo</option>
                </select>
            </div>

            <div class="form-group">
                <label>Descreva o problema</label>
                <textarea name="motivo" rows="4" required
                          placeholder="Explique detalhadamente o que aconteceu..."></textarea>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i> Enviar Disputa
            </button>
        </form>
    </div>
</div>

<script>
function abrirModal() {
    document.getElementById('modalDisputa').classList.add('active');
}

function fecharModal() {
    document.getElementById('modalDisputa').classList.remove('active');
}

document.getElementById('modalDisputa').addEventListener('click', function(e) {
    if (e.target === this) fecharModal();
});
</script>

</body>
</html>
