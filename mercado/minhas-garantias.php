<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO - MINHAS GARANTIAS
 * Area do cliente para ver produtos protegidos
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

// Buscar garantias do cliente
$garantias = [];
try {
    $stmt = $pdo->prepare("
        SELECT g.*, pd.name as produto_nome, p.image as produto_imagem,
               v.nome_loja as vendedor_nome
        FROM om_garantias g
        LEFT JOIN oc_product p ON g.product_id = p.product_id
        LEFT JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 2
        LEFT JOIN om_vendedores v ON g.seller_id = v.vendedor_id
        WHERE g.customer_id = ?
        ORDER BY g.vigencia_fim DESC
    ");
    $stmt->execute([$customer_id]);
    $garantias = $stmt->fetchAll();
} catch (Exception $e) {
    // Tabela pode nao existir ainda
}

// Helper para imagem
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
    <title>Minhas Garantias - OneMundo</title>
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

        .header h1 { font-size: 1.3rem; }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
            margin-top: 20px;
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

        .empty-state p {
            color: #64748b;
        }

        .garantia-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }

        .garantia-header {
            display: flex;
            gap: 16px;
            align-items: flex-start;
        }

        .garantia-img {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            object-fit: contain;
            background: #f8fafc;
        }

        .garantia-info { flex: 1; }

        .garantia-produto {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .garantia-tipo {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: #dbeafe;
            color: #1d4ed8;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .garantia-validade {
            font-size: 13px;
            color: #64748b;
        }

        .garantia-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-ativa { background: #d1fae5; color: #065f46; }
        .status-expirada { background: #f3f4f6; color: #6b7280; }
        .status-utilizada { background: #fef3c7; color: #92400e; }

        .garantia-actions {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #f1f5f9;
        }

        .btn-acionar {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-acionar:disabled {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
        }
    </style>
</head>
<body>

<header class="header">
    <a href="/mercado/conta.php" class="header-back">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h1><i class="fas fa-shield-alt"></i> Minhas Garantias</h1>
</header>

<div class="container">

<?php if (empty($garantias)): ?>
    <div class="empty-state">
        <i class="fas fa-shield-alt"></i>
        <h2>Nenhuma garantia ativa</h2>
        <p>Quando voce comprar produtos com garantia, eles aparecerao aqui.</p>
    </div>
<?php else: ?>

    <?php foreach ($garantias as $g): ?>
    <div class="garantia-card">
        <div class="garantia-header">
            <img src="<?= getImgUrl($g['produto_imagem']) ?>" alt="" class="garantia-img">
            <div class="garantia-info">
                <div class="garantia-produto"><?= htmlspecialchars($g['produto_nome'] ?? 'Produto') ?></div>
                <div class="garantia-tipo">
                    <i class="fas fa-shield-alt"></i>
                    <?= match($g['tipo']) {
                        'garantia_loja' => 'Garantia da Loja',
                        'garantia_extendida' => 'Garantia Extendida',
                        'seguro_roubo' => 'Seguro Roubo',
                        'seguro_dano' => 'Seguro contra Danos',
                        'seguro_quebra_acidental' => 'Seguro Quebra Acidental',
                        default => 'Garantia'
                    } ?>
                </div>
                <div class="garantia-validade">
                    Valido ate: <?= date('d/m/Y', strtotime($g['vigencia_fim'])) ?>
                </div>
            </div>
            <span class="garantia-status status-<?= $g['status'] ?>">
                <?= match($g['status']) {
                    'ativa' => '<i class="fas fa-check-circle"></i> Ativa',
                    'expirada' => '<i class="fas fa-clock"></i> Expirada',
                    'utilizada' => '<i class="fas fa-exclamation-circle"></i> Utilizada',
                    default => $g['status']
                } ?>
            </span>
        </div>

        <?php if ($g['status'] === 'ativa'): ?>
        <div class="garantia-actions">
            <a href="/mercado/acionar-garantia.php?id=<?= $g['id'] ?>" class="btn-acionar">
                <i class="fas fa-tools"></i> Acionar Garantia
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

<?php endif; ?>

</div>

</body>
</html>
