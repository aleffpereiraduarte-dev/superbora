<?php
/**
 * ONEMUNDO MERCADO - SEJA UM PARCEIRO
 * Cadastro de mercados/lojas parceiras para delivery
 */

session_name('OCSESSID');
session_start();

$_oc_root = dirname(__DIR__);
if (file_exists($_oc_root . '/config.php')) {
    require_once $_oc_root . '/config.php';
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    die("Erro de conexao");
}

$customer_id = $_SESSION['customer_id'] ?? 0;
$customer = null;
$parceiro = null;

// Buscar dados do cliente logado
if ($customer_id) {
    $stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();

    // Verificar se ja e parceiro
    $stmt = $pdo->prepare("SELECT * FROM om_market_partners WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $parceiro = $stmt->fetch();
}

// Processar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'cadastrar') {
        $nome = trim($_POST['nome'] ?? '');
        $cnpj = preg_replace('/\D/', '', $_POST['cnpj'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $email = trim($_POST['email'] ?? $customer['email'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        $numero = trim($_POST['numero'] ?? '');
        $bairro = trim($_POST['bairro'] ?? '');
        $cidade = trim($_POST['cidade'] ?? '');
        $estado = strtoupper(trim($_POST['estado'] ?? ''));
        $cep = preg_replace('/\D/', '', $_POST['cep'] ?? '');
        $categoria = $_POST['categoria'] ?? 'supermercado';
        $descricao = trim($_POST['descricao'] ?? '');
        $horario_abre = $_POST['horario_abre'] ?? '08:00';
        $horario_fecha = $_POST['horario_fecha'] ?? '22:00';
        $taxa_entrega = floatval($_POST['taxa_entrega'] ?? 5.99);
        $tempo_preparo = intval($_POST['tempo_preparo'] ?? 30);

        $erros = [];
        if (empty($nome)) $erros[] = 'Nome do estabelecimento obrigatorio';
        if (strlen($cnpj) !== 14) $erros[] = 'CNPJ invalido';
        if (empty($telefone)) $erros[] = 'Telefone obrigatorio';
        if (empty($endereco)) $erros[] = 'Endereco obrigatorio';
        if (empty($cidade)) $erros[] = 'Cidade obrigatoria';

        if (empty($erros)) {
            try {
                // Verificar se CNPJ ja existe
                $stmt = $pdo->prepare("SELECT partner_id FROM om_market_partners WHERE cnpj = ?");
                $stmt->execute([$cnpj]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'CNPJ ja cadastrado']);
                    exit;
                }

                $endereco_completo = "$endereco, $numero - $bairro";

                $stmt = $pdo->prepare("
                    INSERT INTO om_market_partners
                    (customer_id, nome, name, cnpj, telefone, email, endereco, cidade, estado, cep,
                     categoria, descricao, horario_abre, horario_fecha, taxa_entrega, tempo_preparo,
                     status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente', NOW())
                ");
                $stmt->execute([
                    $customer_id ?: null, $nome, $nome, $cnpj, $telefone, $email,
                    $endereco_completo, $cidade, $estado, $cep,
                    $categoria, $descricao, $horario_abre, $horario_fecha, $taxa_entrega, $tempo_preparo
                ]);

                echo json_encode(['success' => true, 'message' => 'Cadastro enviado! Aguarde aprovacao por email.']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Erro ao cadastrar: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => implode(', ', $erros)]);
        }
        exit;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#00D26A">
    <title>Seja um Parceiro - OneMundo Mercado</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #0a0a0a;
            --card: #141414;
            --border: #252525;
            --text: #ffffff;
            --text2: #888888;
            --green: #00D26A;
            --green-dark: #00a854;
            --red: #ff4757;
            --orange: #ff9f43;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: 40px;
        }

        .header {
            background: linear-gradient(135deg, var(--green), var(--green-dark));
            color: #000;
            padding: 40px 20px;
            text-align: center;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .header p {
            font-size: 16px;
            opacity: 0.8;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .benefits {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin: 24px 0;
        }

        .benefit {
            background: var(--card);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }

        .benefit i {
            font-size: 32px;
            color: var(--green);
            margin-bottom: 12px;
        }

        .benefit h4 {
            font-size: 14px;
            margin-bottom: 4px;
        }

        .benefit p {
            font-size: 12px;
            color: var(--text2);
        }

        .form-card {
            background: var(--card);
            border-radius: 20px;
            padding: 24px;
            margin-top: 24px;
        }

        .form-card h3 {
            font-size: 18px;
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
            color: var(--text2);
            margin-bottom: 6px;
        }

        .form-group label .required {
            color: var(--red);
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            font-size: 16px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            border-color: var(--green);
        }

        .form-control::placeholder {
            color: #555;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23888' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-row {
            display: flex;
            gap: 12px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .btn {
            width: 100%;
            padding: 16px;
            background: var(--green);
            color: #000;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
        }

        .btn:hover {
            background: var(--green-dark);
        }

        .btn:disabled {
            background: var(--border);
            color: var(--text2);
            cursor: not-allowed;
        }

        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: rgba(0, 210, 106, 0.1);
            border: 1px solid var(--green);
            color: var(--green);
        }

        .alert-info {
            background: rgba(255, 159, 67, 0.1);
            border: 1px solid var(--orange);
            color: var(--orange);
        }

        .status-card {
            background: var(--card);
            border-radius: 20px;
            padding: 40px 24px;
            text-align: center;
        }

        .status-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
        }

        .status-icon.pending {
            background: rgba(255, 159, 67, 0.2);
            color: var(--orange);
        }

        .status-icon.active {
            background: rgba(0, 210, 106, 0.2);
            color: var(--green);
        }

        .status-card h3 {
            font-size: 20px;
            margin-bottom: 8px;
        }

        .status-card p {
            color: var(--text2);
            margin-bottom: 24px;
        }

        .btn-dashboard {
            display: inline-flex;
            padding: 14px 32px;
            background: var(--green);
            color: #000;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
        }

        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .loading.show { display: flex; }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border);
            border-top-color: var(--green);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .login-prompt {
            text-align: center;
            padding: 40px 20px;
        }

        .login-prompt i {
            font-size: 64px;
            color: var(--text2);
            margin-bottom: 20px;
        }

        .login-prompt h3 {
            margin-bottom: 12px;
        }

        .login-prompt p {
            color: var(--text2);
            margin-bottom: 24px;
        }

        .login-prompt a {
            display: inline-block;
            padding: 14px 32px;
            background: var(--green);
            color: #000;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
        }

        @media (max-width: 480px) {
            .benefits {
                grid-template-columns: 1fr;
            }
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <h1><i class="fas fa-store"></i> Seja um Parceiro</h1>
    <p>Cadastre seu mercado e venda pelo OneMundo</p>
</div>

<div class="container">
    <?php if ($parceiro): ?>
        <!-- Ja e parceiro -->
        <div class="status-card">
            <?php if ($parceiro['status'] === 'ativo'): ?>
                <div class="status-icon active"><i class="fas fa-check"></i></div>
                <h3>Voce ja e um Parceiro!</h3>
                <p><?= htmlspecialchars($parceiro['nome'] ?? $parceiro['name']) ?></p>
                <a href="/mercado/parceiro/dashboard.php" class="btn-dashboard">
                    <i class="fas fa-chart-line"></i> Acessar Painel
                </a>
            <?php else: ?>
                <div class="status-icon pending"><i class="fas fa-clock"></i></div>
                <h3>Cadastro em Analise</h3>
                <p>Seu cadastro esta sendo analisado. Voce recebera um email quando for aprovado.</p>
                <p style="margin-top: 16px; font-size: 14px;">
                    <strong><?= htmlspecialchars($parceiro['nome'] ?? $parceiro['name']) ?></strong><br>
                    <?= htmlspecialchars($parceiro['cidade']) ?>/<?= htmlspecialchars($parceiro['estado']) ?>
                </p>
            <?php endif; ?>
        </div>

    <?php elseif (!$customer_id): ?>
        <!-- Nao logado -->
        <div class="benefits">
            <div class="benefit">
                <i class="fas fa-users"></i>
                <h4>+1000 Clientes</h4>
                <p>Na sua regiao</p>
            </div>
            <div class="benefit">
                <i class="fas fa-motorcycle"></i>
                <h4>Entrega Rapida</h4>
                <p>Rede de shoppers</p>
            </div>
            <div class="benefit">
                <i class="fas fa-money-bill-wave"></i>
                <h4>Receba Rapido</h4>
                <p>Pagamento semanal</p>
            </div>
        </div>

        <div class="login-prompt">
            <i class="fas fa-user-circle"></i>
            <h3>Faca login para continuar</h3>
            <p>Voce precisa estar logado para cadastrar seu estabelecimento.</p>
            <a href="/index.php?route=account/login&redirect=<?= urlencode('/mercado/seja-parceiro.php') ?>">
                <i class="fas fa-sign-in-alt"></i> Fazer Login
            </a>
            <p style="margin-top: 16px;">
                Nao tem conta? <a href="/index.php?route=account/register" style="color: var(--green);">Cadastre-se</a>
            </p>
        </div>

    <?php else: ?>
        <!-- Formulario de cadastro -->
        <div class="benefits">
            <div class="benefit">
                <i class="fas fa-chart-line"></i>
                <h4>Aumente Vendas</h4>
                <p>Alcance novos clientes</p>
            </div>
            <div class="benefit">
                <i class="fas fa-headset"></i>
                <h4>Suporte 24h</h4>
                <p>Estamos sempre aqui</p>
            </div>
            <div class="benefit">
                <i class="fas fa-percent"></i>
                <h4>Taxa Justa</h4>
                <p>Apenas 12% por pedido</p>
            </div>
        </div>

        <div class="form-card">
            <h3><i class="fas fa-clipboard-list" style="color: var(--green);"></i> Dados do Estabelecimento</h3>

            <form id="formParceiro">
                <div class="form-group">
                    <label>Nome do Estabelecimento <span class="required">*</span></label>
                    <input type="text" class="form-control" name="nome" placeholder="Ex: Mercado Popular" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>CNPJ <span class="required">*</span></label>
                        <input type="text" class="form-control" name="cnpj" id="cnpj" placeholder="00.000.000/0000-00" required>
                    </div>
                    <div class="form-group">
                        <label>Categoria <span class="required">*</span></label>
                        <select class="form-control" name="categoria" required>
                            <option value="supermercado">Supermercado</option>
                            <option value="mercado">Mercado</option>
                            <option value="conveniencia">Conveniencia</option>
                            <option value="padaria">Padaria</option>
                            <option value="acougue">Acougue</option>
                            <option value="hortifruti">Hortifruti</option>
                            <option value="bebidas">Bebidas / Adega</option>
                            <option value="farmacia">Farmacia</option>
                            <option value="petshop">Pet Shop</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Telefone <span class="required">*</span></label>
                        <input type="tel" class="form-control" name="telefone" id="telefone" placeholder="(00) 0000-0000" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($customer['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex: 0.35;">
                        <label>CEP <span class="required">*</span></label>
                        <input type="text" class="form-control" name="cep" id="cep" placeholder="00000-000" required>
                    </div>
                    <div class="form-group" style="flex: 0.65;">
                        <label>Endereco <span class="required">*</span></label>
                        <input type="text" class="form-control" name="endereco" id="endereco" placeholder="Rua/Avenida" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex: 0.25;">
                        <label>Numero</label>
                        <input type="text" class="form-control" name="numero" placeholder="123">
                    </div>
                    <div class="form-group" style="flex: 0.75;">
                        <label>Bairro <span class="required">*</span></label>
                        <input type="text" class="form-control" name="bairro" id="bairro" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Cidade <span class="required">*</span></label>
                        <input type="text" class="form-control" name="cidade" id="cidade" required>
                    </div>
                    <div class="form-group" style="flex: 0.3;">
                        <label>UF <span class="required">*</span></label>
                        <input type="text" class="form-control" name="estado" id="estado" maxlength="2" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Horario Abertura</label>
                        <input type="time" class="form-control" name="horario_abre" value="08:00">
                    </div>
                    <div class="form-group">
                        <label>Horario Fechamento</label>
                        <input type="time" class="form-control" name="horario_fecha" value="22:00">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Taxa de Entrega (R$)</label>
                        <input type="number" class="form-control" name="taxa_entrega" value="5.99" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label>Tempo Preparo (min)</label>
                        <input type="number" class="form-control" name="tempo_preparo" value="30" min="5" max="120">
                    </div>
                </div>

                <div class="form-group">
                    <label>Descricao do estabelecimento</label>
                    <textarea class="form-control" name="descricao" placeholder="Conte um pouco sobre seu estabelecimento..."></textarea>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <span>Apos o cadastro, nossa equipe entrara em contato para validar as informacoes.</span>
                </div>

                <button type="submit" class="btn" id="btnSubmit">
                    <i class="fas fa-paper-plane"></i> Enviar Cadastro
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="loading" id="loading">
    <div class="spinner"></div>
</div>

<script>
// CEP
document.getElementById('cep')?.addEventListener('blur', async function() {
    const cep = this.value.replace(/\D/g, '');
    if (cep.length === 8) {
        try {
            const resp = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
            const data = await resp.json();
            if (!data.erro) {
                document.getElementById('endereco').value = data.logradouro || '';
                document.getElementById('bairro').value = data.bairro || '';
                document.getElementById('cidade').value = data.localidade || '';
                document.getElementById('estado').value = data.uf || '';
            }
        } catch(e) {}
    }
});

// Masks
document.getElementById('cnpj')?.addEventListener('input', function() {
    let v = this.value.replace(/\D/g, '');
    if (v.length > 14) v = v.slice(0, 14);
    if (v.length > 12) this.value = v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    else if (v.length > 8) this.value = v.replace(/(\d{2})(\d{3})(\d{3})(\d+)/, '$1.$2.$3/$4');
    else if (v.length > 5) this.value = v.replace(/(\d{2})(\d{3})(\d+)/, '$1.$2.$3');
    else if (v.length > 2) this.value = v.replace(/(\d{2})(\d+)/, '$1.$2');
    else this.value = v;
});

document.getElementById('telefone')?.addEventListener('input', function() {
    let v = this.value.replace(/\D/g, '');
    if (v.length > 11) v = v.slice(0, 11);
    if (v.length > 6) this.value = `(${v.slice(0,2)}) ${v.slice(2,6)}-${v.slice(6)}`;
    else if (v.length > 2) this.value = `(${v.slice(0,2)}) ${v.slice(2)}`;
    else this.value = v;
});

document.getElementById('cep')?.addEventListener('input', function() {
    let v = this.value.replace(/\D/g, '');
    if (v.length > 8) v = v.slice(0, 8);
    if (v.length > 5) this.value = `${v.slice(0,5)}-${v.slice(5)}`;
    else this.value = v;
});

// Submit
document.getElementById('formParceiro')?.addEventListener('submit', async function(e) {
    e.preventDefault();

    const btn = document.getElementById('btnSubmit');
    const loading = document.getElementById('loading');

    btn.disabled = true;
    loading.classList.add('show');

    const formData = new FormData(this);
    formData.append('action', 'cadastrar');

    try {
        const resp = await fetch('', { method: 'POST', body: formData });
        const data = await resp.json();

        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Erro: ' + data.message);
            btn.disabled = false;
        }
    } catch(e) {
        alert('Erro ao enviar. Tente novamente.');
        btn.disabled = false;
    }

    loading.classList.remove('show');
});
</script>

</body>
</html>
