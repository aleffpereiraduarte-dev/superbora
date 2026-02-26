<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO - SEJA UM AFILIADO
 * Programa de afiliados para ganhar comissoes
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_name('OCSESSID');
session_start();

$_oc_root = dirname(__DIR__);
if (file_exists($_oc_root . '/config.php')) {
    require_once $_oc_root . '/config.php';
}

$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$customer_id = $_SESSION['customer_id'] ?? 0;
$customer = null;
$afiliado = null;
$erro = '';
$sucesso = '';

// Buscar dados do cliente
if ($customer_id) {
    $stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();

    // Verificar se ja e afiliado
    try {
        $stmt = $pdo->prepare("SELECT * FROM om_affiliates WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $afiliado = $stmt->fetch();
    } catch (Exception $e) {}
}

// Processar cadastro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $customer_id && !$afiliado) {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $pix_tipo = $_POST['pix_tipo'] ?? '';
    $pix_chave = trim($_POST['pix_chave'] ?? '');

    if (empty($nome) || empty($email)) {
        $erro = 'Nome e email sao obrigatorios';
    } elseif (empty($pix_tipo) || empty($pix_chave)) {
        $erro = 'Chave PIX e obrigatoria para receber suas comissoes';
    } else {
        // Gerar codigo unico
        $codigo = 'AF' . strtoupper(substr(md5($customer_id . time()), 0, 6));

        try {
            $stmt = $pdo->prepare("
                INSERT INTO om_affiliates (customer_id, code, pix_type, pix_key, status, created_at)
                VALUES (?, ?, ?, ?, 'active', NOW())
            ");
            $stmt->execute([$customer_id, $codigo, $pix_tipo, $pix_chave]);

            $sucesso = 'Cadastro realizado! Seu codigo de afiliado e: ' . $codigo;

            // Recarregar
            $stmt = $pdo->prepare("SELECT * FROM om_affiliates WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $afiliado = $stmt->fetch();
        } catch (Exception $e) {
            $erro = 'Erro ao processar cadastro.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seja um Afiliado - OneMundo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #f8fafc; min-height: 100vh; }

        .af-header {
            background: linear-gradient(135deg, #7c3aed 0%, #a855f7 50%, #c084fc 100%);
            padding: 60px 20px;
            text-align: center;
            color: white;
            position: relative;
        }

        .af-header-back {
            position: absolute;
            top: 20px;
            left: 20px;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .af-header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .af-header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .af-benefits {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            max-width: 900px;
            margin: -40px auto 40px;
            padding: 0 20px;
            position: relative;
            z-index: 10;
        }

        .af-benefit {
            background: white;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .af-benefit-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #f3e8ff, #e9d5ff);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 24px;
            color: #7c3aed;
        }

        .af-benefit h3 { font-size: 16px; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        .af-benefit p { font-size: 13px; color: #64748b; }

        .af-container {
            max-width: 700px;
            margin: 0 auto;
            padding: 0 20px 60px;
        }

        .af-dashboard {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }

        .af-codigo {
            background: linear-gradient(135deg, #7c3aed, #a855f7);
            color: white;
            padding: 20px;
            border-radius: 16px;
            text-align: center;
            margin-bottom: 24px;
        }

        .af-codigo-label { font-size: 13px; opacity: 0.9; margin-bottom: 8px; }
        .af-codigo-value { font-size: 28px; font-weight: 800; letter-spacing: 2px; }

        .af-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .af-stat {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }

        .af-stat-value { font-size: 24px; font-weight: 800; color: #7c3aed; }
        .af-stat-label { font-size: 12px; color: #64748b; margin-top: 4px; }

        .af-link-box {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .af-link-box input {
            flex: 1;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }

        .af-link-box button {
            padding: 12px 20px;
            background: #7c3aed;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .af-form {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }

        .af-form-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .af-form-group {
            margin-bottom: 16px;
        }

        .af-form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
        }

        .af-form-group input,
        .af-form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
        }

        .af-form-group input:focus,
        .af-form-group select:focus {
            outline: none;
            border-color: #7c3aed;
        }

        .af-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #7c3aed, #a855f7);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 8px;
        }

        .af-alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .af-alert.error { background: #fee2e2; color: #991b1b; }
        .af-alert.success { background: #d1fae5; color: #065f46; }

        .af-login-cta {
            background: white;
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }

        .af-login-cta h2 { font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 12px; }
        .af-login-cta p { color: #64748b; margin-bottom: 24px; }

        .af-login-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #7c3aed, #a855f7);
            color: white;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
        }

        @media (max-width: 600px) {
            .af-header h1 { font-size: 1.8rem; }
            .af-benefits { grid-template-columns: 1fr 1fr; }
            .af-stats { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<header class="af-header">
    <a href="/mercado/conta.php" class="af-header-back">
        <i class="fas fa-arrow-left"></i> Voltar
    </a>
    <h1><i class="fas fa-handshake"></i> Seja um Afiliado</h1>
    <p>Ganhe comissao indicando produtos da OneMundo</p>
</header>

<div class="af-benefits">
    <div class="af-benefit">
        <div class="af-benefit-icon"><i class="fas fa-percentage"></i></div>
        <h3>Ate 5% de Comissao</h3>
        <p>Em cada venda realizada</p>
    </div>
    <div class="af-benefit">
        <div class="af-benefit-icon"><i class="fas fa-link"></i></div>
        <h3>Link Personalizado</h3>
        <p>Compartilhe facilmente</p>
    </div>
    <div class="af-benefit">
        <div class="af-benefit-icon"><i class="fas fa-money-bill-wave"></i></div>
        <h3>Pagamento via PIX</h3>
        <p>Receba rapidamente</p>
    </div>
    <div class="af-benefit">
        <div class="af-benefit-icon"><i class="fas fa-chart-bar"></i></div>
        <h3>Dashboard Completo</h3>
        <p>Acompanhe seus ganhos</p>
    </div>
</div>

<div class="af-container">

<?php if (!$customer_id): ?>
    <div class="af-login-cta">
        <h2>Faca login para continuar</h2>
        <p>Voce precisa ter uma conta OneMundo para se tornar afiliado</p>
        <a href="/mercado/mercado-login.php?redirect=<?= urlencode('/mercado/seja-afiliado.php') ?>" class="af-login-btn">
            <i class="fas fa-sign-in-alt"></i> Entrar na minha conta
        </a>
    </div>

<?php elseif ($afiliado): ?>
    <!-- Dashboard do Afiliado -->
    <div class="af-dashboard">
        <div class="af-codigo">
            <div class="af-codigo-label">Seu codigo de afiliado</div>
            <div class="af-codigo-value"><?= htmlspecialchars($afiliado['code']) ?></div>
        </div>

        <div class="af-stats">
            <div class="af-stat">
                <div class="af-stat-value"><?= number_format($afiliado['total_clicks'] ?? 0) ?></div>
                <div class="af-stat-label">Cliques</div>
            </div>
            <div class="af-stat">
                <div class="af-stat-value"><?= number_format($afiliado['total_conversions'] ?? 0) ?></div>
                <div class="af-stat-label">Vendas</div>
            </div>
            <div class="af-stat">
                <div class="af-stat-value">R$ <?= number_format($afiliado['total_earnings'] ?? 0, 2, ',', '.') ?></div>
                <div class="af-stat-label">Comissoes</div>
            </div>
        </div>

        <h4 style="margin-bottom: 12px; color: #475569;">Seu link de afiliado:</h4>
        <div class="af-link-box">
            <input type="text" readonly id="linkAfiliado"
                   value="https://onemundo.com.br/?ref=<?= htmlspecialchars($afiliado['code']) ?>">
            <button onclick="copiarLink()">
                <i class="fas fa-copy"></i> Copiar
            </button>
        </div>
    </div>

<?php else: ?>
    <!-- Formulario de Cadastro -->

    <?php if ($erro): ?>
        <div class="af-alert error">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <?php if ($sucesso): ?>
        <div class="af-alert success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($sucesso) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="af-form">
        <h3 class="af-form-title"><i class="fas fa-user-plus"></i> Cadastre-se como Afiliado</h3>

        <div class="af-form-group">
            <label>Nome Completo</label>
            <input type="text" name="nome" required
                   value="<?= htmlspecialchars(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? '')) ?>">
        </div>

        <div class="af-form-group">
            <label>E-mail</label>
            <input type="email" name="email" required
                   value="<?= htmlspecialchars($customer['email'] ?? '') ?>">
        </div>

        <div class="af-form-group">
            <label>Telefone</label>
            <input type="text" name="telefone"
                   value="<?= htmlspecialchars($customer['telephone'] ?? '') ?>">
        </div>

        <div class="af-form-group">
            <label>Tipo de Chave PIX</label>
            <select name="pix_tipo" required>
                <option value="">Selecione</option>
                <option value="cpf">CPF</option>
                <option value="email">E-mail</option>
                <option value="telefone">Telefone</option>
                <option value="aleatoria">Chave Aleatoria</option>
            </select>
        </div>

        <div class="af-form-group">
            <label>Chave PIX</label>
            <input type="text" name="pix_chave" required placeholder="Sua chave PIX para receber">
        </div>

        <button type="submit" class="af-submit">
            <i class="fas fa-rocket"></i> Comecar a Ganhar
        </button>
    </form>

<?php endif; ?>

</div>

<script>
function copiarLink() {
    const input = document.getElementById('linkAfiliado');
    input.select();
    document.execCommand('copy');
    alert('Link copiado!');
}
</script>

</body>
</html>
