<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO - SEJA UM VENDEDOR - Cadastro Inteligente Estilo Mercado Livre
 * Wizard passo-a-passo com validacao em tempo real
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
$vendedor = null;
$erro = '';
$sucesso = '';

// Buscar dados do cliente logado
if ($customer_id) {
    $stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();

    // Verificar se ja e vendedor
    $stmt = $pdo->prepare("SELECT * FROM oc_purpletree_vendor_stores WHERE seller_id = ? AND is_removed = 0");
    $stmt->execute([$customer_id]);
    $vendedor = $stmt->fetch();

    // Buscar vendedor om_vendedores para mais detalhes
    $stmt = $pdo->prepare("SELECT * FROM om_vendedores WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $om_vendedor = $stmt->fetch();
}

// Buscar categorias disponiveis
$categorias = $pdo->query("
    SELECT category_id, name FROM oc_category_description
    WHERE language_id = 1
    ORDER BY name
")->fetchAll();

// Processar formulario via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'salvar_cadastro' && $customer_id && !$vendedor) {
        $dados = json_decode($_POST['dados'], true);

        $tipo_pessoa = $dados['tipo_pessoa'] ?? 'pf';
        $tipo_vendedor = $dados['tipo_vendedor'] ?? 'simples';
        $nome_loja = trim($dados['nome_loja'] ?? '');
        $descricao = trim($dados['descricao_loja'] ?? '');
        $cpf_cnpj = preg_replace('/\D/', '', $dados['cpf_cnpj'] ?? '');
        $telefone = trim($dados['telefone'] ?? '');
        $whatsapp = trim($dados['whatsapp'] ?? '');
        $email = trim($dados['email'] ?? $customer['email']);

        // Pessoa Juridica
        $razao_social = trim($dados['razao_social'] ?? '');
        $nome_fantasia = trim($dados['nome_fantasia'] ?? '');
        $inscricao_estadual = trim($dados['inscricao_estadual'] ?? '');

        // Endereco
        $cep = preg_replace('/\D/', '', $dados['cep'] ?? '');
        $endereco = trim($dados['endereco'] ?? '');
        $numero = trim($dados['numero'] ?? '');
        $complemento = trim($dados['complemento'] ?? '');
        $bairro = trim($dados['bairro'] ?? '');
        $cidade = trim($dados['cidade'] ?? '');
        $estado = trim($dados['estado'] ?? '');

        // Categorias
        $categorias_selecionadas = $dados['categorias'] ?? [];

        // PIX
        $pix_tipo = $dados['pix_tipo'] ?? '';
        $pix_chave = trim($dados['pix_chave'] ?? '');

        // Validacoes
        $erros = [];

        if (empty($nome_loja)) $erros[] = 'Nome da loja e obrigatorio';
        if ($tipo_pessoa === 'pf' && strlen($cpf_cnpj) !== 11) $erros[] = 'CPF invalido';
        if ($tipo_pessoa === 'pj' && strlen($cpf_cnpj) !== 14) $erros[] = 'CNPJ invalido';
        if ($tipo_pessoa === 'pj' && empty($razao_social)) $erros[] = 'Razao social e obrigatoria';
        if (empty($telefone)) $erros[] = 'Telefone e obrigatorio';
        if (empty($cep)) $erros[] = 'CEP e obrigatorio';
        if (empty($endereco)) $erros[] = 'Endereco e obrigatorio';
        if (empty($cidade)) $erros[] = 'Cidade e obrigatoria';
        if (empty($estado)) $erros[] = 'Estado e obrigatorio';
        if (empty($pix_tipo) || empty($pix_chave)) $erros[] = 'Chave PIX e obrigatoria';
        if (empty($categorias_selecionadas)) $erros[] = 'Selecione pelo menos uma categoria';

        if (!empty($erros)) {
            echo json_encode(['success' => false, 'errors' => $erros]);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Dados bancarios
            $bank_details = json_encode([
                'pix_tipo' => $pix_tipo,
                'pix_chave' => $pix_chave
            ]);

            // Criar no PurpleTree
            $stmt = $pdo->prepare("
                INSERT INTO oc_purpletree_vendor_stores (
                    seller_id, store_name, store_email, store_phone,
                    store_address, store_city, store_state, store_zipcode,
                    store_number, store_complement, store_neighborhood,
                    store_bank_details, store_status, is_removed,
                    tipo_pessoa, cpf_cnpj, store_description,
                    store_created_at, store_updated_at,
                    verificacao_status
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, 0, 0,
                    ?, ?, ?,
                    CURRENT_DATE, CURRENT_DATE,
                    'pendente'
                )
            ");
            $stmt->execute([
                $customer_id, $nome_loja, $email, $telefone,
                $endereco, $cidade, $estado, $cep,
                $numero, $complemento, $bairro,
                $bank_details,
                $tipo_pessoa, $cpf_cnpj, $descricao
            ]);

            // Criar em om_vendedores
            $codigo = 'OM-' . strtoupper(substr(md5($customer_id . time()), 0, 6));
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $nome_loja));
            $slug = trim($slug, '-');
            $tem_loja_publica = ($tipo_vendedor === 'loja_oficial') ? 1 : 0;

            $stmt = $pdo->prepare("
                INSERT INTO om_vendedores (
                    customer_id, opencart_customer_id, codigo, nome_loja, slug, slug_loja,
                    email, telefone, whatsapp, cpf, cnpj,
                    razao_social, nome_fantasia, inscricao_estadual,
                    cep, endereco, numero, complemento, bairro, cidade, estado,
                    pix_tipo, pix_chave, descricao_loja,
                    tipo_pessoa, tipo_vendedor, tem_loja_publica,
                    categorias, status, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, 'pendente', NOW()
                )
                ON DUPLICATE KEY UPDATE
                    nome_loja = VALUES(nome_loja),
                    telefone = VALUES(telefone),
                    whatsapp = VALUES(whatsapp),
                    tipo_vendedor = VALUES(tipo_vendedor),
                    tem_loja_publica = VALUES(tem_loja_publica),
                    categorias = VALUES(categorias),
                    descricao_loja = VALUES(descricao_loja),
                    updated_at = NOW()
            ");
            $stmt->execute([
                $customer_id, $customer_id, $codigo, $nome_loja, $slug, $slug,
                $email, $telefone, $whatsapp,
                $tipo_pessoa === 'pf' ? $cpf_cnpj : null,
                $tipo_pessoa === 'pj' ? $cpf_cnpj : null,
                $razao_social, $nome_fantasia, $inscricao_estadual,
                $cep, $endereco, $numero, $complemento, $bairro, $cidade, $estado,
                $pix_tipo, $pix_chave, $descricao,
                $tipo_pessoa === 'pf' ? 'fisica' : 'juridica',
                $tipo_vendedor, $tem_loja_publica,
                json_encode($categorias_selecionadas)
            ]);

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Cadastro realizado com sucesso!']);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'errors' => ['Erro ao processar cadastro: ' . $e->getMessage()]]);
            exit;
        }
    }

    exit;
}

// Status do vendedor
$status_texto = '';
$status_class = '';
if ($vendedor) {
    if ($vendedor['store_status'] == 1) {
        $status_texto = 'Aprovado';
        $status_class = 'aprovado';
    } elseif ($vendedor['verificacao_status'] === 'rejeitado') {
        $status_texto = 'Rejeitado';
        $status_class = 'rejeitado';
    } else {
        $status_texto = 'Em Analise';
        $status_class = 'analise';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comece a Vender - OneMundo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            color: #333;
        }

        /* Header minimalista estilo ML */
        .header {
            background: #fff159;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #333;
        }

        .header-logo img {
            height: 34px;
        }

        .header-logo span {
            font-weight: 600;
            font-size: 14px;
            color: #666;
        }

        .header-help {
            font-size: 14px;
            color: #3483fa;
            text-decoration: none;
        }

        /* Progress Steps */
        .progress-container {
            background: white;
            padding: 24px 20px;
            border-bottom: 1px solid #eee;
        }

        .progress-inner {
            max-width: 900px;
            margin: 0 auto;
        }

        .progress-steps {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
        }

        .progress-line {
            position: absolute;
            top: 16px;
            left: 40px;
            right: 40px;
            height: 2px;
            background: #e0e0e0;
            z-index: 1;
        }

        .progress-line-fill {
            height: 100%;
            background: #3483fa;
            transition: width 0.4s ease;
            width: 0%;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 2;
        }

        .step-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
            color: #999;
            transition: all 0.3s;
        }

        .progress-step.active .step-circle,
        .progress-step.completed .step-circle {
            background: #3483fa;
            color: white;
        }

        .progress-step.completed .step-circle {
            background: #00a650;
        }

        .step-label {
            font-size: 12px;
            color: #999;
            font-weight: 500;
            text-align: center;
            max-width: 80px;
        }

        .progress-step.active .step-label {
            color: #3483fa;
            font-weight: 600;
        }

        .progress-step.completed .step-label {
            color: #00a650;
        }

        /* Main Container */
        .main-container {
            max-width: 680px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }

        /* Card */
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .card-header {
            padding: 24px 32px;
            border-bottom: 1px solid #eee;
        }

        .card-header h1 {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }

        .card-header p {
            font-size: 14px;
            color: #666;
        }

        .card-body {
            padding: 32px;
        }

        /* Form Elements */
        .form-section {
            margin-bottom: 32px;
        }

        .form-section:last-child {
            margin-bottom: 0;
        }

        .form-section-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-section-title i {
            color: #3483fa;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
        }

        .form-group label .required {
            color: #f23d4f;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.2s;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3483fa;
            box-shadow: 0 0 0 3px rgba(52,131,250,0.1);
        }

        .form-group input.error,
        .form-group select.error {
            border-color: #f23d4f;
        }

        .form-group .helper {
            font-size: 12px;
            color: #999;
            margin-top: 6px;
        }

        .form-group .error-msg {
            font-size: 12px;
            color: #f23d4f;
            margin-top: 6px;
        }

        /* Tipo Selector Cards */
        .tipo-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .tipo-card {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 24px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            background: white;
        }

        .tipo-card:hover {
            border-color: #3483fa;
            background: #f8fbff;
        }

        .tipo-card.active {
            border-color: #3483fa;
            background: #f0f7ff;
        }

        .tipo-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 20px;
            color: #666;
        }

        .tipo-card.active .tipo-card-icon {
            background: #3483fa;
            color: white;
        }

        .tipo-card h3 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }

        .tipo-card p {
            font-size: 13px;
            color: #666;
        }

        /* Vendedor Type Cards */
        .vendedor-tipo-cards {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .vendedor-tipo-card {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .vendedor-tipo-card:hover {
            border-color: #3483fa;
        }

        .vendedor-tipo-card.active {
            border-color: #3483fa;
            background: #f8fbff;
        }

        .vendedor-tipo-radio {
            margin-top: 2px;
        }

        .vendedor-tipo-radio input {
            width: 20px;
            height: 20px;
            accent-color: #3483fa;
        }

        .vendedor-tipo-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #666;
            flex-shrink: 0;
        }

        .vendedor-tipo-card.loja-oficial .vendedor-tipo-icon {
            background: linear-gradient(135deg, #fff7e6, #ffe4b5);
            color: #d97706;
        }

        .vendedor-tipo-content {
            flex: 1;
        }

        .vendedor-tipo-content h4 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .vendedor-tipo-content p {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .vendedor-tipo-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-free {
            background: #d1fae5;
            color: #059669;
        }

        .badge-premium {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #b45309;
        }

        /* Categorias */
        .categorias-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            max-height: 300px;
            overflow-y: auto;
            padding: 4px;
        }

        .categoria-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
        }

        .categoria-item:hover {
            border-color: #3483fa;
            background: #f8fbff;
        }

        .categoria-item.selected {
            border-color: #3483fa;
            background: #f0f7ff;
        }

        .categoria-item input {
            accent-color: #3483fa;
        }

        /* Buttons */
        .btn {
            padding: 14px 32px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
        }

        .btn-primary {
            background: #3483fa;
            color: white;
        }

        .btn-primary:hover {
            background: #2968c8;
        }

        .btn-secondary {
            background: transparent;
            color: #3483fa;
            border: 1px solid #3483fa;
        }

        .btn-secondary:hover {
            background: #f0f7ff;
        }

        .btn-success {
            background: #00a650;
            color: white;
        }

        .btn-success:hover {
            background: #008c44;
        }

        .card-footer {
            padding: 24px 32px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Step Hidden */
        .step-content {
            display: none;
        }

        .step-content.active {
            display: block;
        }

        /* Status Cards */
        .status-card {
            text-align: center;
            padding: 48px 32px;
        }

        .status-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 36px;
        }

        .status-icon.success {
            background: #d1fae5;
            color: #00a650;
        }

        .status-icon.pending {
            background: #fff7e6;
            color: #d97706;
        }

        .status-icon.error {
            background: #fee2e2;
            color: #f23d4f;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .status-badge.success {
            background: #d1fae5;
            color: #00a650;
        }

        .status-badge.pending {
            background: #fff7e6;
            color: #d97706;
        }

        .status-card h2 {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .status-card p {
            font-size: 14px;
            color: #666;
            margin-bottom: 24px;
        }

        /* Login Card */
        .login-card {
            text-align: center;
            padding: 60px 32px;
        }

        .login-card i {
            font-size: 64px;
            color: #3483fa;
            margin-bottom: 24px;
        }

        .login-card h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .login-card p {
            color: #666;
            margin-bottom: 24px;
        }

        /* Benefits */
        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-top: 32px;
        }

        .benefit-item {
            background: white;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }

        .benefit-item i {
            font-size: 28px;
            color: #3483fa;
            margin-bottom: 12px;
        }

        .benefit-item h4 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .benefit-item p {
            font-size: 12px;
            color: #666;
        }

        /* Resume Section */
        .resume-section {
            background: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .resume-section h4 {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .resume-section h4 button {
            background: none;
            border: none;
            color: #3483fa;
            font-size: 13px;
            cursor: pointer;
            font-weight: 500;
        }

        .resume-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        .resume-item:last-child {
            border-bottom: none;
        }

        .resume-item span:first-child {
            color: #666;
        }

        .resume-item span:last-child {
            color: #333;
            font-weight: 500;
        }

        /* Alert */
        .alert {
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .alert-info {
            background: #e8f4fd;
            color: #1d4ed8;
        }

        .alert-success {
            background: #d1fae5;
            color: #059669;
        }

        .alert-error {
            background: #fee2e2;
            color: #dc2626;
        }

        /* Loading */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            flex-direction: column;
            gap: 16px;
        }

        .loading-overlay.active {
            display: flex;
        }

        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #e0e0e0;
            border-top-color: #3483fa;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .progress-steps {
                overflow-x: auto;
                padding-bottom: 8px;
            }

            .step-label {
                display: none;
            }

            .tipo-selector {
                grid-template-columns: 1fr;
            }

            .benefits-grid {
                grid-template-columns: 1fr 1fr;
            }

            .card-footer {
                flex-direction: column;
                gap: 12px;
            }

            .card-footer .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<header class="header">
    <a href="/" class="header-logo">
        <img src="/image/catalog/logo.png" alt="OneMundo" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 30%22><text y=%2222%22 font-size=%2220%22 font-weight=%22bold%22>OneMundo</text></svg>'">
        <span>| Vender</span>
    </a>
    <a href="#" class="header-help"><i class="fas fa-question-circle"></i> Ajuda</a>
</header>

<?php if (!$customer_id): ?>
<!-- Nao Logado -->
<div class="main-container">
    <div class="card">
        <div class="login-card">
            <i class="fas fa-store"></i>
            <h2>Comece a vender no OneMundo</h2>
            <p>Entre na sua conta ou crie uma para comecar</p>
            <a href="/index.php?route=account/login&redirect=<?= urlencode('/mercado/seja-vendedor.php') ?>" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i> Entrar ou criar conta
            </a>
        </div>
    </div>

    <div class="benefits-grid">
        <div class="benefit-item">
            <i class="fas fa-users"></i>
            <h4>Milhoes de clientes</h4>
            <p>Acesso a nossa base</p>
        </div>
        <div class="benefit-item">
            <i class="fas fa-truck"></i>
            <h4>Logistica integrada</h4>
            <p>Envios simplificados</p>
        </div>
        <div class="benefit-item">
            <i class="fas fa-shield-alt"></i>
            <h4>Pagamento seguro</h4>
            <p>Receba sem risco</p>
        </div>
        <div class="benefit-item">
            <i class="fas fa-chart-line"></i>
            <h4>Ferramentas</h4>
            <p>Gerencie tudo</p>
        </div>
    </div>
</div>

<?php elseif ($vendedor && $vendedor['store_status'] == 1): ?>
<!-- Vendedor Aprovado -->
<div class="main-container">
    <div class="card">
        <div class="status-card">
            <div class="status-icon success">
                <i class="fas fa-check"></i>
            </div>
            <span class="status-badge success">
                <i class="fas fa-certificate"></i> Vendedor Ativo
            </span>
            <h2><?= htmlspecialchars($vendedor['store_name']) ?></h2>
            <p>Sua conta esta ativa! Acesse o painel para gerenciar produtos e vendas.</p>
            <a href="/vendedor/dashboard.php" class="btn btn-success">
                <i class="fas fa-tachometer-alt"></i> Acessar Painel do Vendedor
            </a>
        </div>
    </div>
</div>

<?php elseif ($vendedor): ?>
<!-- Vendedor Pendente -->
<div class="main-container">
    <div class="card">
        <div class="status-card">
            <div class="status-icon <?= $status_class === 'rejeitado' ? 'error' : 'pending' ?>">
                <i class="fas fa-<?= $status_class === 'rejeitado' ? 'times' : 'clock' ?>"></i>
            </div>
            <span class="status-badge <?= $status_class === 'rejeitado' ? 'error' : 'pending' ?>">
                <?= $status_class === 'rejeitado' ? '<i class="fas fa-times-circle"></i> Rejeitado' : '<i class="fas fa-hourglass-half"></i> Em Analise' ?>
            </span>
            <h2><?= htmlspecialchars($vendedor['store_name']) ?></h2>
            <?php if ($status_class === 'rejeitado'): ?>
                <p>Seu cadastro foi rejeitado. <?= $vendedor['verificacao_motivo_rejeicao'] ? 'Motivo: ' . htmlspecialchars($vendedor['verificacao_motivo_rejeicao']) : '' ?></p>
            <?php else: ?>
                <p>Sua solicitacao esta sendo analisada. Isso pode levar ate 48 horas uteis.</p>
                <div class="alert alert-info" style="text-align: left; margin-top: 24px;">
                    <i class="fas fa-envelope"></i>
                    <span>Voce recebera um e-mail quando sua conta for aprovada.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Formulario de Cadastro - Wizard -->
<div class="progress-container">
    <div class="progress-inner">
        <div class="progress-steps">
            <div class="progress-line">
                <div class="progress-line-fill" id="progressFill"></div>
            </div>
            <div class="progress-step active" data-step="1">
                <div class="step-circle">1</div>
                <span class="step-label">Tipo de conta</span>
            </div>
            <div class="progress-step" data-step="2">
                <div class="step-circle">2</div>
                <span class="step-label">Seus dados</span>
            </div>
            <div class="progress-step" data-step="3">
                <div class="step-circle">3</div>
                <span class="step-label">Sua loja</span>
            </div>
            <div class="progress-step" data-step="4">
                <div class="step-circle">4</div>
                <span class="step-label">Endereco</span>
            </div>
            <div class="progress-step" data-step="5">
                <div class="step-circle">5</div>
                <span class="step-label">Pagamento</span>
            </div>
            <div class="progress-step" data-step="6">
                <div class="step-circle">6</div>
                <span class="step-label">Confirmacao</span>
            </div>
        </div>
    </div>
</div>

<div class="main-container">
    <form id="formVendedor">
        <!-- Step 1: Tipo de Conta -->
        <div class="step-content active" data-step="1">
            <div class="card">
                <div class="card-header">
                    <h1>Como voce quer vender?</h1>
                    <p>Escolha o tipo de conta que melhor se encaixa no seu perfil</p>
                </div>
                <div class="card-body">
                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-user"></i> Tipo de pessoa</div>
                        <div class="tipo-selector">
                            <div class="tipo-card active" data-tipo="pf" onclick="selecionarTipoPessoa('pf')">
                                <div class="tipo-card-icon"><i class="fas fa-user"></i></div>
                                <h3>Pessoa Fisica</h3>
                                <p>Venda com CPF</p>
                            </div>
                            <div class="tipo-card" data-tipo="pj" onclick="selecionarTipoPessoa('pj')">
                                <div class="tipo-card-icon"><i class="fas fa-building"></i></div>
                                <h3>Pessoa Juridica</h3>
                                <p>Venda com CNPJ</p>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-store"></i> Tipo de vendedor</div>
                        <div class="vendedor-tipo-cards">
                            <div class="vendedor-tipo-card active" data-tipo="simples" onclick="selecionarTipoVendedor('simples')">
                                <div class="vendedor-tipo-radio">
                                    <input type="radio" name="tipo_vendedor" value="simples" checked>
                                </div>
                                <div class="vendedor-tipo-icon"><i class="fas fa-user-tag"></i></div>
                                <div class="vendedor-tipo-content">
                                    <h4>Vendedor Simples</h4>
                                    <p>Seus produtos aparecem no marketplace junto com outros vendedores. Ideal para quem esta comecando ou vende ocasionalmente.</p>
                                    <span class="vendedor-tipo-badge badge-free">Gratis</span>
                                </div>
                            </div>
                            <div class="vendedor-tipo-card loja-oficial" data-tipo="loja_oficial" onclick="selecionarTipoVendedor('loja_oficial')">
                                <div class="vendedor-tipo-radio">
                                    <input type="radio" name="tipo_vendedor" value="loja_oficial">
                                </div>
                                <div class="vendedor-tipo-icon"><i class="fas fa-crown"></i></div>
                                <div class="vendedor-tipo-content">
                                    <h4>Loja Oficial <i class="fas fa-crown" style="color: #d97706; font-size: 14px;"></i></h4>
                                    <p>Tenha seu mini-site personalizado com logo, banner e URL propria. Mais visibilidade e credibilidade para seus clientes.</p>
                                    <span class="vendedor-tipo-badge badge-premium">Destaque</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <div></div>
                    <button type="button" class="btn btn-primary" onclick="proximaEtapa()">
                        Continuar <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 2: Dados Pessoais -->
        <div class="step-content" data-step="2">
            <div class="card">
                <div class="card-header">
                    <h1 id="step2Title">Seus dados pessoais</h1>
                    <p id="step2Subtitle">Informe seus dados para identificacao</p>
                </div>
                <div class="card-body">
                    <!-- Campos PF -->
                    <div id="camposPF">
                        <div class="form-row">
                            <div class="form-group">
                                <label>CPF <span class="required">*</span></label>
                                <input type="text" name="cpf_cnpj" id="cpf_cnpj" placeholder="000.000.000-00" maxlength="14">
                            </div>
                            <div class="form-group">
                                <label>Nome completo</label>
                                <input type="text" value="<?= htmlspecialchars(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? '')) ?>" readonly style="background: #f5f5f5;">
                            </div>
                        </div>
                    </div>

                    <!-- Campos PJ -->
                    <div id="camposPJ" style="display: none;">
                        <div class="form-row">
                            <div class="form-group">
                                <label>CNPJ <span class="required">*</span></label>
                                <input type="text" name="cnpj" id="cnpj" placeholder="00.000.000/0000-00" maxlength="18">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Razao Social <span class="required">*</span></label>
                            <input type="text" name="razao_social" id="razao_social" placeholder="Nome registrado na Receita Federal">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Nome Fantasia</label>
                                <input type="text" name="nome_fantasia" id="nome_fantasia" placeholder="Como sua empresa e conhecida">
                            </div>
                            <div class="form-group">
                                <label>Inscricao Estadual</label>
                                <input type="text" name="inscricao_estadual" id="inscricao_estadual" placeholder="Se houver">
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Telefone <span class="required">*</span></label>
                            <input type="text" name="telefone" id="telefone" placeholder="(00) 00000-0000" value="<?= htmlspecialchars($customer['telephone'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>WhatsApp</label>
                            <input type="text" name="whatsapp" id="whatsapp" placeholder="(00) 00000-0000">
                            <span class="helper">Para contato rapido com clientes</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>E-mail <span class="required">*</span></label>
                        <input type="email" name="email" id="email" value="<?= htmlspecialchars($customer['email'] ?? '') ?>">
                    </div>
                </div>
                <div class="card-footer">
                    <button type="button" class="btn btn-secondary" onclick="etapaAnterior()">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="proximaEtapa()">
                        Continuar <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 3: Dados da Loja -->
        <div class="step-content" data-step="3">
            <div class="card">
                <div class="card-header">
                    <h1>Sobre sua loja</h1>
                    <p>Conte mais sobre o que voce vende</p>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Nome da loja <span class="required">*</span></label>
                        <input type="text" name="nome_loja" id="nome_loja" placeholder="Como sua loja vai aparecer para os clientes">
                        <span class="helper">Este sera o nome visivel no marketplace</span>
                    </div>

                    <div class="form-group">
                        <label>Descricao da loja</label>
                        <textarea name="descricao_loja" id="descricao_loja" rows="3" placeholder="Descreva sua loja, o que voce vende, diferenciais..."></textarea>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-tags"></i> Categorias que voce vende <span class="required">*</span></div>
                        <p style="font-size: 13px; color: #666; margin-bottom: 12px;">Selecione as categorias dos produtos que voce pretende vender</p>
                        <div class="categorias-grid">
                            <?php foreach ($categorias as $cat): ?>
                            <label class="categoria-item">
                                <input type="checkbox" name="categorias[]" value="<?= $cat['category_id'] ?>">
                                <?= htmlspecialchars($cat['name']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="button" class="btn btn-secondary" onclick="etapaAnterior()">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="proximaEtapa()">
                        Continuar <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 4: Endereco -->
        <div class="step-content" data-step="4">
            <div class="card">
                <div class="card-header">
                    <h1>Endereco de origem</h1>
                    <p>De onde seus produtos serao enviados</p>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group" style="max-width: 200px;">
                            <label>CEP <span class="required">*</span></label>
                            <input type="text" name="cep" id="cep" placeholder="00000-000" maxlength="9" onblur="buscarCEP()">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>&nbsp;</label>
                            <a href="https://buscacepinter.correios.com.br" target="_blank" style="font-size: 13px; color: #3483fa;">Nao sei meu CEP</a>
                        </div>
                    </div>

                    <div id="enderecoFields" style="display: none;">
                        <div class="form-group">
                            <label>Endereco <span class="required">*</span></label>
                            <input type="text" name="endereco" id="endereco" placeholder="Rua, Avenida...">
                        </div>

                        <div class="form-row">
                            <div class="form-group" style="max-width: 120px;">
                                <label>Numero <span class="required">*</span></label>
                                <input type="text" name="numero" id="numero" placeholder="123">
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Complemento</label>
                                <input type="text" name="complemento" id="complemento" placeholder="Apto, Sala, Bloco...">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Bairro <span class="required">*</span></label>
                                <input type="text" name="bairro" id="bairro">
                            </div>
                            <div class="form-group">
                                <label>Cidade <span class="required">*</span></label>
                                <input type="text" name="cidade" id="cidade" readonly style="background: #f9f9f9;">
                            </div>
                            <div class="form-group" style="max-width: 100px;">
                                <label>Estado <span class="required">*</span></label>
                                <input type="text" name="estado" id="estado" readonly style="background: #f9f9f9;" maxlength="2">
                            </div>
                        </div>
                    </div>

                    <div id="cepLoading" style="display: none; text-align: center; padding: 40px;">
                        <div class="spinner" style="margin: 0 auto;"></div>
                        <p style="margin-top: 12px; color: #666;">Buscando endereco...</p>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="button" class="btn btn-secondary" onclick="etapaAnterior()">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="proximaEtapa()">
                        Continuar <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 5: Pagamento -->
        <div class="step-content" data-step="5">
            <div class="card">
                <div class="card-header">
                    <h1>Como voce quer receber</h1>
                    <p>Configure sua chave PIX para receber seus pagamentos</p>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <span>Seus pagamentos serao depositados automaticamente via PIX apos a confirmacao de entrega dos produtos.</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Tipo de chave PIX <span class="required">*</span></label>
                            <select name="pix_tipo" id="pix_tipo">
                                <option value="">Selecione</option>
                                <option value="cpf">CPF</option>
                                <option value="cnpj">CNPJ</option>
                                <option value="email">E-mail</option>
                                <option value="telefone">Telefone</option>
                                <option value="aleatoria">Chave Aleatoria</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Chave PIX <span class="required">*</span></label>
                            <input type="text" name="pix_chave" id="pix_chave" placeholder="Sua chave PIX">
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="button" class="btn btn-secondary" onclick="etapaAnterior()">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="proximaEtapa()">
                        Continuar <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 6: Confirmacao -->
        <div class="step-content" data-step="6">
            <div class="card">
                <div class="card-header">
                    <h1>Confirme seus dados</h1>
                    <p>Revise as informacoes antes de enviar</p>
                </div>
                <div class="card-body">
                    <div class="resume-section">
                        <h4>Tipo de conta <button type="button" onclick="irParaEtapa(1)">Editar</button></h4>
                        <div class="resume-item">
                            <span>Tipo de pessoa</span>
                            <span id="resumeTipoPessoa">Pessoa Fisica</span>
                        </div>
                        <div class="resume-item">
                            <span>Tipo de vendedor</span>
                            <span id="resumeTipoVendedor">Vendedor Simples</span>
                        </div>
                    </div>

                    <div class="resume-section">
                        <h4>Dados pessoais <button type="button" onclick="irParaEtapa(2)">Editar</button></h4>
                        <div class="resume-item">
                            <span>Documento</span>
                            <span id="resumeDocumento">-</span>
                        </div>
                        <div class="resume-item">
                            <span>Telefone</span>
                            <span id="resumeTelefone">-</span>
                        </div>
                        <div class="resume-item">
                            <span>E-mail</span>
                            <span id="resumeEmail">-</span>
                        </div>
                    </div>

                    <div class="resume-section">
                        <h4>Sua loja <button type="button" onclick="irParaEtapa(3)">Editar</button></h4>
                        <div class="resume-item">
                            <span>Nome da loja</span>
                            <span id="resumeNomeLoja">-</span>
                        </div>
                        <div class="resume-item">
                            <span>Categorias</span>
                            <span id="resumeCategorias">-</span>
                        </div>
                    </div>

                    <div class="resume-section">
                        <h4>Endereco <button type="button" onclick="irParaEtapa(4)">Editar</button></h4>
                        <div class="resume-item">
                            <span>Endereco completo</span>
                            <span id="resumeEndereco">-</span>
                        </div>
                    </div>

                    <div class="resume-section">
                        <h4>Pagamento <button type="button" onclick="irParaEtapa(5)">Editar</button></h4>
                        <div class="resume-item">
                            <span>Chave PIX</span>
                            <span id="resumePix">-</span>
                        </div>
                    </div>

                    <div class="alert alert-info" style="margin-top: 24px;">
                        <i class="fas fa-shield-alt"></i>
                        <span>Ao enviar, voce concorda com os <a href="#">Termos de Uso</a> e <a href="#">Politica de Privacidade</a> do OneMundo.</span>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="button" class="btn btn-secondary" onclick="etapaAnterior()">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </button>
                    <button type="button" class="btn btn-success" onclick="enviarCadastro()">
                        <i class="fas fa-check"></i> Enviar cadastro
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <p>Processando seu cadastro...</p>
</div>
<?php endif; ?>

<script>
// Dados do formulario
const formData = {
    tipo_pessoa: 'pf',
    tipo_vendedor: 'simples',
    cpf_cnpj: '',
    razao_social: '',
    nome_fantasia: '',
    inscricao_estadual: '',
    telefone: '',
    whatsapp: '',
    email: '<?= htmlspecialchars($customer['email'] ?? '') ?>',
    nome_loja: '',
    descricao_loja: '',
    categorias: [],
    cep: '',
    endereco: '',
    numero: '',
    complemento: '',
    bairro: '',
    cidade: '',
    estado: '',
    pix_tipo: '',
    pix_chave: ''
};

let etapaAtual = 1;
const totalEtapas = 6;

function atualizarProgress() {
    const fill = document.getElementById('progressFill');
    const percent = ((etapaAtual - 1) / (totalEtapas - 1)) * 100;
    fill.style.width = percent + '%';

    document.querySelectorAll('.progress-step').forEach((step, i) => {
        const stepNum = i + 1;
        step.classList.remove('active', 'completed');
        if (stepNum < etapaAtual) {
            step.classList.add('completed');
        } else if (stepNum === etapaAtual) {
            step.classList.add('active');
        }
    });
}

function mostrarEtapa(num) {
    document.querySelectorAll('.step-content').forEach(step => {
        step.classList.remove('active');
    });
    document.querySelector(`.step-content[data-step="${num}"]`).classList.add('active');
    etapaAtual = num;
    atualizarProgress();
    window.scrollTo(0, 0);
}

function proximaEtapa() {
    if (!validarEtapa(etapaAtual)) return;
    salvarDadosEtapa();

    if (etapaAtual < totalEtapas) {
        if (etapaAtual === 5) {
            atualizarResumo();
        }
        mostrarEtapa(etapaAtual + 1);
    }
}

function etapaAnterior() {
    if (etapaAtual > 1) {
        mostrarEtapa(etapaAtual - 1);
    }
}

function irParaEtapa(num) {
    mostrarEtapa(num);
}

function validarEtapa(num) {
    let valid = true;
    let msg = '';

    switch(num) {
        case 2:
            const doc = formData.tipo_pessoa === 'pf' ?
                document.getElementById('cpf_cnpj').value :
                document.getElementById('cnpj').value;
            if (!doc.trim()) {
                msg = 'Informe o documento';
                valid = false;
            }
            if (!document.getElementById('telefone').value.trim()) {
                msg = 'Informe o telefone';
                valid = false;
            }
            break;
        case 3:
            if (!document.getElementById('nome_loja').value.trim()) {
                msg = 'Informe o nome da loja';
                valid = false;
            }
            const cats = document.querySelectorAll('input[name="categorias[]"]:checked');
            if (cats.length === 0) {
                msg = 'Selecione pelo menos uma categoria';
                valid = false;
            }
            break;
        case 4:
            if (!document.getElementById('cep').value.trim()) {
                msg = 'Informe o CEP';
                valid = false;
            }
            if (!document.getElementById('endereco').value.trim()) {
                msg = 'Informe o endereco';
                valid = false;
            }
            if (!document.getElementById('numero').value.trim()) {
                msg = 'Informe o numero';
                valid = false;
            }
            break;
        case 5:
            if (!document.getElementById('pix_tipo').value) {
                msg = 'Selecione o tipo de chave PIX';
                valid = false;
            }
            if (!document.getElementById('pix_chave').value.trim()) {
                msg = 'Informe a chave PIX';
                valid = false;
            }
            break;
    }

    if (!valid) {
        alert(msg);
    }
    return valid;
}

function salvarDadosEtapa() {
    // Coletar todos os dados do formulario
    formData.cpf_cnpj = formData.tipo_pessoa === 'pf' ?
        document.getElementById('cpf_cnpj').value :
        document.getElementById('cnpj')?.value || '';
    formData.razao_social = document.getElementById('razao_social')?.value || '';
    formData.nome_fantasia = document.getElementById('nome_fantasia')?.value || '';
    formData.inscricao_estadual = document.getElementById('inscricao_estadual')?.value || '';
    formData.telefone = document.getElementById('telefone').value;
    formData.whatsapp = document.getElementById('whatsapp').value;
    formData.email = document.getElementById('email').value;
    formData.nome_loja = document.getElementById('nome_loja').value;
    formData.descricao_loja = document.getElementById('descricao_loja').value;
    formData.cep = document.getElementById('cep').value;
    formData.endereco = document.getElementById('endereco').value;
    formData.numero = document.getElementById('numero').value;
    formData.complemento = document.getElementById('complemento').value;
    formData.bairro = document.getElementById('bairro').value;
    formData.cidade = document.getElementById('cidade').value;
    formData.estado = document.getElementById('estado').value;
    formData.pix_tipo = document.getElementById('pix_tipo').value;
    formData.pix_chave = document.getElementById('pix_chave').value;

    // Categorias
    formData.categorias = [];
    document.querySelectorAll('input[name="categorias[]"]:checked').forEach(cb => {
        formData.categorias.push(cb.value);
    });
}

function atualizarResumo() {
    salvarDadosEtapa();

    document.getElementById('resumeTipoPessoa').textContent =
        formData.tipo_pessoa === 'pf' ? 'Pessoa Fisica' : 'Pessoa Juridica';
    document.getElementById('resumeTipoVendedor').textContent =
        formData.tipo_vendedor === 'simples' ? 'Vendedor Simples' : 'Loja Oficial';
    document.getElementById('resumeDocumento').textContent = formData.cpf_cnpj || '-';
    document.getElementById('resumeTelefone').textContent = formData.telefone || '-';
    document.getElementById('resumeEmail').textContent = formData.email || '-';
    document.getElementById('resumeNomeLoja').textContent = formData.nome_loja || '-';

    const catCount = formData.categorias.length;
    document.getElementById('resumeCategorias').textContent =
        catCount > 0 ? catCount + ' categoria(s) selecionada(s)' : '-';

    let enderecoCompleto = formData.endereco;
    if (formData.numero) enderecoCompleto += ', ' + formData.numero;
    if (formData.complemento) enderecoCompleto += ' - ' + formData.complemento;
    if (formData.bairro) enderecoCompleto += ', ' + formData.bairro;
    if (formData.cidade) enderecoCompleto += ' - ' + formData.cidade + '/' + formData.estado;
    document.getElementById('resumeEndereco').textContent = enderecoCompleto || '-';

    document.getElementById('resumePix').textContent =
        formData.pix_chave ? formData.pix_tipo.toUpperCase() + ': ' + formData.pix_chave : '-';
}

function selecionarTipoPessoa(tipo) {
    formData.tipo_pessoa = tipo;
    document.querySelectorAll('.tipo-card').forEach(card => {
        card.classList.remove('active');
    });
    document.querySelector(`.tipo-card[data-tipo="${tipo}"]`).classList.add('active');

    // Atualizar step 2
    if (tipo === 'pj') {
        document.getElementById('camposPF').style.display = 'none';
        document.getElementById('camposPJ').style.display = 'block';
        document.getElementById('step2Title').textContent = 'Dados da empresa';
        document.getElementById('step2Subtitle').textContent = 'Informe os dados da sua empresa';
    } else {
        document.getElementById('camposPF').style.display = 'block';
        document.getElementById('camposPJ').style.display = 'none';
        document.getElementById('step2Title').textContent = 'Seus dados pessoais';
        document.getElementById('step2Subtitle').textContent = 'Informe seus dados para identificacao';
    }
}

function selecionarTipoVendedor(tipo) {
    formData.tipo_vendedor = tipo;
    document.querySelectorAll('.vendedor-tipo-card').forEach(card => {
        card.classList.remove('active');
    });
    document.querySelector(`.vendedor-tipo-card[data-tipo="${tipo}"]`).classList.add('active');
    document.querySelector(`.vendedor-tipo-card[data-tipo="${tipo}"] input`).checked = true;
}

// Mascara CEP
document.getElementById('cep')?.addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '');
    if (v.length > 5) {
        v = v.substring(0,5) + '-' + v.substring(5,8);
    }
    e.target.value = v;
});

// Mascara CPF
document.getElementById('cpf_cnpj')?.addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '');
    if (v.length > 11) v = v.substring(0,11);
    if (v.length > 9) {
        v = v.substring(0,3) + '.' + v.substring(3,6) + '.' + v.substring(6,9) + '-' + v.substring(9);
    } else if (v.length > 6) {
        v = v.substring(0,3) + '.' + v.substring(3,6) + '.' + v.substring(6);
    } else if (v.length > 3) {
        v = v.substring(0,3) + '.' + v.substring(3);
    }
    e.target.value = v;
});

// Mascara CNPJ
document.getElementById('cnpj')?.addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '');
    if (v.length > 14) v = v.substring(0,14);
    if (v.length > 12) {
        v = v.substring(0,2) + '.' + v.substring(2,5) + '.' + v.substring(5,8) + '/' + v.substring(8,12) + '-' + v.substring(12);
    } else if (v.length > 8) {
        v = v.substring(0,2) + '.' + v.substring(2,5) + '.' + v.substring(5,8) + '/' + v.substring(8);
    } else if (v.length > 5) {
        v = v.substring(0,2) + '.' + v.substring(2,5) + '.' + v.substring(5);
    } else if (v.length > 2) {
        v = v.substring(0,2) + '.' + v.substring(2);
    }
    e.target.value = v;
});

// Mascara telefone
['telefone', 'whatsapp'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', function(e) {
        let v = e.target.value.replace(/\D/g, '');
        if (v.length > 11) v = v.substring(0,11);
        if (v.length > 6) {
            v = '(' + v.substring(0,2) + ') ' + v.substring(2,7) + '-' + v.substring(7);
        } else if (v.length > 2) {
            v = '(' + v.substring(0,2) + ') ' + v.substring(2);
        }
        e.target.value = v;
    });
});

// Buscar CEP
async function buscarCEP() {
    const cep = document.getElementById('cep').value.replace(/\D/g, '');
    if (cep.length !== 8) return;

    document.getElementById('cepLoading').style.display = 'block';
    document.getElementById('enderecoFields').style.display = 'none';

    try {
        const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
        const data = await response.json();

        if (data.erro) {
            alert('CEP nao encontrado');
            return;
        }

        document.getElementById('endereco').value = data.logradouro || '';
        document.getElementById('bairro').value = data.bairro || '';
        document.getElementById('cidade').value = data.localidade || '';
        document.getElementById('estado').value = data.uf || '';

        document.getElementById('enderecoFields').style.display = 'block';
        document.getElementById('numero').focus();
    } catch (e) {
        alert('Erro ao buscar CEP');
    } finally {
        document.getElementById('cepLoading').style.display = 'none';
    }
}

// Categoria toggle
document.querySelectorAll('.categoria-item').forEach(item => {
    item.addEventListener('click', function() {
        const cb = this.querySelector('input');
        cb.checked = !cb.checked;
        this.classList.toggle('selected', cb.checked);
    });
});

// Enviar cadastro
async function enviarCadastro() {
    salvarDadosEtapa();

    document.getElementById('loadingOverlay').classList.add('active');

    try {
        const form = new FormData();
        form.append('action', 'salvar_cadastro');
        form.append('dados', JSON.stringify(formData));

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: form
        });

        const result = await response.json();

        if (result.success) {
            alert('Cadastro enviado com sucesso! Sua solicitacao sera analisada em ate 48 horas.');
            window.location.reload();
        } else {
            alert('Erro: ' + (result.errors ? result.errors.join('\n') : 'Tente novamente'));
        }
    } catch (e) {
        alert('Erro ao enviar cadastro. Tente novamente.');
    } finally {
        document.getElementById('loadingOverlay').classList.remove('active');
    }
}

// Init
atualizarProgress();
</script>

</body>
</html>
