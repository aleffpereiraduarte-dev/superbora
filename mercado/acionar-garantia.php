<?php
/**
 * ONEMUNDO - ACIONAR GARANTIA
 * Formulario para acionar uma garantia ativa
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

$garantia_id = intval($_GET['id'] ?? 0);
$garantia = null;
$erro = '';
$sucesso = '';

// Buscar garantia
if ($garantia_id) {
    $stmt = $pdo->prepare("
        SELECT g.*, pd.name as produto_nome, p.image as produto_imagem,
               v.nome_loja as vendedor_nome
        FROM om_garantias g
        LEFT JOIN oc_product p ON g.product_id = p.product_id
        LEFT JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 2
        LEFT JOIN om_vendedores v ON g.seller_id = v.vendedor_id
        WHERE g.id = ? AND g.customer_id = ? AND g.status = 'ativa'
    ");
    $stmt->execute([$garantia_id, $customer_id]);
    $garantia = $stmt->fetch();
}

if (!$garantia) {
    header('Location: /mercado/minhas-garantias.php');
    exit;
}

// Processar acionamento
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_problema = $_POST['tipo_problema'] ?? '';
    $descricao = trim($_POST['descricao'] ?? '');

    if (empty($tipo_problema) || empty($descricao)) {
        $erro = 'Preencha todos os campos';
    } else {
        try {
            // Criar disputa de garantia
            $stmt = $pdo->prepare("
                INSERT INTO om_disputes (order_id, customer_id, seller_id, tipo, motivo, status, data_abertura)
                VALUES (?, ?, ?, 'defeito', ?, 'aberta', NOW())
            ");
            $motivo = "ACIONAMENTO DE GARANTIA - " . strtoupper($garantia['tipo']) . "\n\n";
            $motivo .= "Produto: " . ($garantia['produto_nome'] ?? 'N/A') . "\n";
            $motivo .= "Tipo do problema: " . $tipo_problema . "\n\n";
            $motivo .= "Descricao: " . $descricao;

            $stmt->execute([$garantia['order_id'], $customer_id, $garantia['seller_id'], $motivo]);
            $dispute_id = $pdo->lastInsertId();

            // Atualizar status da garantia
            $pdo->prepare("UPDATE om_garantias SET status = 'utilizada' WHERE id = ?")->execute([$garantia_id]);

            $sucesso = 'Garantia acionada com sucesso! Acompanhe o processo na area de disputas.';

            // Atualizar garantia localmente
            $garantia['status'] = 'utilizada';

        } catch (Exception $e) {
            $erro = 'Erro ao acionar garantia. Tente novamente.';
        }
    }
}

function getImgUrl($img) {
    if (empty($img)) return '/image/placeholder.png';
    if (strpos($img, 'http') === 0) return $img;
    return '/image/' . $img;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acionar Garantia - OneMundo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #f1f5f9; min-height: 100vh; }

        .header {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
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

        .header h1 { font-size: 1.2rem; }

        .container {
            max-width: 600px;
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

        .produto-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .produto-img {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            object-fit: contain;
            background: #f8fafc;
        }

        .produto-info { flex: 1; }
        .produto-nome { font-weight: 600; color: #1e293b; margin-bottom: 4px; }

        .produto-garantia {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: #dbeafe;
            color: #1d4ed8;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .produto-validade {
            font-size: 13px;
            color: #64748b;
            margin-top: 6px;
        }

        .form-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
        }

        .form-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            font-family: inherit;
        }

        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .btn-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 8px;
        }

        .btn-submit:disabled {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
        }

        .info-box {
            background: #fef3c7;
            border-radius: 12px;
            padding: 16px;
            margin-top: 20px;
            font-size: 13px;
            color: #92400e;
        }

        .info-box i { margin-right: 8px; }
    </style>
</head>
<body>

<header class="header">
    <a href="/mercado/minhas-garantias.php" class="header-back">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h1><i class="fas fa-tools"></i> Acionar Garantia</h1>
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
    <div class="form-card" style="text-align: center;">
        <i class="fas fa-check-circle" style="font-size: 64px; color: #10b981; margin-bottom: 20px;"></i>
        <h2 style="margin-bottom: 12px;">Garantia Acionada!</h2>
        <p style="color: #64748b; margin-bottom: 24px;">Sua solicitacao foi registrada. Acompanhe o andamento na area de disputas.</p>
        <a href="/mercado/minhas-disputas.php" class="btn-submit" style="text-decoration: none; display: inline-flex; width: auto; padding: 14px 32px;">
            <i class="fas fa-gavel"></i> Ver Minhas Disputas
        </a>
    </div>
    <?php else: ?>

    <div class="produto-card">
        <img src="<?= getImgUrl($garantia['produto_imagem']) ?>" alt="" class="produto-img">
        <div class="produto-info">
            <div class="produto-nome"><?= htmlspecialchars($garantia['produto_nome'] ?? 'Produto') ?></div>
            <div class="produto-garantia">
                <i class="fas fa-shield-alt"></i>
                <?= match($garantia['tipo']) {
                    'garantia_loja' => 'Garantia da Loja',
                    'garantia_extendida' => 'Garantia Extendida',
                    'seguro_roubo' => 'Seguro Roubo',
                    'seguro_dano' => 'Seguro contra Danos',
                    'seguro_quebra_acidental' => 'Seguro Quebra Acidental',
                    default => 'Garantia'
                } ?>
            </div>
            <div class="produto-validade">
                Valido ate: <?= date('d/m/Y', strtotime($garantia['vigencia_fim'])) ?>
            </div>
        </div>
    </div>

    <form method="POST" class="form-card">
        <h3 class="form-title"><i class="fas fa-edit"></i> Descreva o Problema</h3>

        <div class="form-group">
            <label>Tipo do Problema</label>
            <select name="tipo_problema" required>
                <option value="">-- Selecione --</option>
                <option value="defeito_fabricacao">Defeito de fabricacao</option>
                <option value="parou_funcionar">Parou de funcionar</option>
                <option value="dano_acidental">Dano acidental</option>
                <option value="roubo_furto">Roubo ou furto</option>
                <option value="outro">Outro</option>
            </select>
        </div>

        <div class="form-group">
            <label>Descreva detalhadamente o problema</label>
            <textarea name="descricao" rows="5" required
                      placeholder="Explique o que aconteceu com o produto, quando ocorreu e como percebeu o problema..."></textarea>
        </div>

        <button type="submit" class="btn-submit">
            <i class="fas fa-paper-plane"></i> Acionar Garantia
        </button>

        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            Ao acionar a garantia, uma disputa sera aberta automaticamente e voce podera acompanhar todo o processo.
            O vendedor sera notificado e tera prazo para responder.
        </div>
    </form>

    <?php endif; ?>

</div>

</body>
</html>
