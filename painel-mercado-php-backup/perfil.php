<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Configurações e Perfil
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_start();

if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/includes/classes/SimpleTOTP.php';
$db = getDB();

$mercado_id = $_SESSION['mercado_id'];
$mercado_nome = $_SESSION['mercado_nome'];

$message = '';
$error = '';
$totp_setup_secret = '';
$totp_setup_qr = '';
$show_2fa_setup = false;
$show_security_tab = false;

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $cep = trim($_POST['cep'] ?? '');
        $min_order = floatval(str_replace(['.', ','], ['', '.'], $_POST['min_order'] ?? 0));
        $delivery_time = intval($_POST['delivery_time'] ?? 30);
        $logo = trim($_POST['logo_url'] ?? '');
        $banner = trim($_POST['banner_url'] ?? '');
        $aceita_retirada = isset($_POST['aceita_retirada']) ? 1 : 0;
        $entrega_propria = isset($_POST['entrega_propria']) ? 1 : 0;

        if ($name) {
            $sql = "UPDATE om_market_partners SET name = ?, phone = ?, description = ?, address = ?, city = ?, state = ?, cep = ?, min_order = ?, delivery_time = ?, aceita_retirada = ?, entrega_propria = ?";
            $params = [$name, $phone, $description, $address, $city, $state, $cep, $min_order, $delivery_time, $aceita_retirada, $entrega_propria];

            if ($logo) {
                $sql .= ", logo = ?";
                $params[] = $logo;
            }
            if ($banner) {
                $sql .= ", banner = ?";
                $params[] = $banner;
            }
            $sql .= " WHERE partner_id = ?";
            $params[] = $mercado_id;

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $_SESSION['mercado_nome'] = $name;
            $message = 'Dados atualizados com sucesso';
        } else {
            $error = 'Nome é obrigatório';
        }
    }

    if ($action === 'update_bank') {
        $bank_name = trim($_POST['bank_name'] ?? '');
        $bank_agency = trim($_POST['bank_agency'] ?? '');
        $bank_account = trim($_POST['bank_account'] ?? '');
        $bank_type = $_POST['bank_type'] ?? 'corrente';
        $pix_key = trim($_POST['pix_key'] ?? '');
        $pix_type = $_POST['pix_type'] ?? '';

        $stmt = $db->prepare("
            UPDATE om_market_partners SET
                bank_name = ?,
                bank_agency = ?,
                bank_account = ?,
                bank_type = ?,
                pix_key = ?,
                pix_type = ?
            WHERE partner_id = ?
        ");
        $stmt->execute([$bank_name, $bank_agency, $bank_account, $bank_type, $pix_key, $pix_type, $mercado_id]);
        $message = 'Dados bancários atualizados';
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($new !== $confirm) {
            $error = 'As senhas não conferem';
        } elseif (strlen($new) < 6) {
            $error = 'A nova senha deve ter no mínimo 6 caracteres';
        } else {
            $stmt = $db->prepare("SELECT login_password FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$mercado_id]);
            $partner = $stmt->fetch();

            if (password_verify($current, $partner['login_password'] ?? '')) {
                $stmt = $db->prepare("UPDATE om_market_partners SET login_password = ? WHERE partner_id = ?");
                $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $mercado_id]);
                $message = 'Senha alterada com sucesso';
            } else {
                $error = 'Senha atual incorreta';
            }
        }
    }

    // ── 2FA: Step 1 — Generate secret and show QR ──
    if ($action === 'enable_2fa_step1') {
        $show_security_tab = true;
        $totp_setup_secret = SimpleTOTP::generateSecret();
        // Store in session temporarily until verified
        $_SESSION['totp_setup_secret'] = $totp_setup_secret;

        // Get partner email for label
        $stmt = $db->prepare("SELECT email FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$mercado_id]);
        $partner_email = $stmt->fetchColumn();

        $totp_setup_qr = SimpleTOTP::getQRUrl($partner_email, $totp_setup_secret);
        $show_2fa_setup = true;
    }

    // ── 2FA: Step 2 — Verify code and enable ──
    if ($action === 'enable_2fa_step2') {
        $show_security_tab = true;
        $code = trim($_POST['totp_verify_code'] ?? '');
        $secret = $_SESSION['totp_setup_secret'] ?? '';

        if (!$secret) {
            $error = 'Sessao expirada. Tente novamente.';
        } elseif (SimpleTOTP::verify($secret, $code)) {
            $stmt = $db->prepare("UPDATE om_market_partners SET totp_secret = ?, totp_enabled = TRUE WHERE partner_id = ?");
            $stmt->execute([$secret, $mercado_id]);
            unset($_SESSION['totp_setup_secret']);
            $message = 'Autenticacao em 2 fatores ativada com sucesso!';
        } else {
            $error = 'Codigo invalido. Tente novamente.';
            // Keep setup state so user can retry
            $totp_setup_secret = $secret;
            $stmt = $db->prepare("SELECT email FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$mercado_id]);
            $partner_email = $stmt->fetchColumn();
            $totp_setup_qr = SimpleTOTP::getQRUrl($partner_email, $totp_setup_secret);
            $show_2fa_setup = true;
        }
    }

    // ── 2FA: Disable ──
    if ($action === 'disable_2fa') {
        $show_security_tab = true;
        $code = trim($_POST['totp_disable_code'] ?? '');

        $stmt = $db->prepare("SELECT totp_secret FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$mercado_id]);
        $current_secret = $stmt->fetchColumn();

        if ($current_secret && SimpleTOTP::verify($current_secret, $code)) {
            $stmt = $db->prepare("UPDATE om_market_partners SET totp_secret = NULL, totp_enabled = FALSE WHERE partner_id = ?");
            $stmt->execute([$mercado_id]);
            $message = 'Autenticacao em 2 fatores desativada.';
        } else {
            $error = 'Codigo invalido. A 2FA nao foi desativada.';
        }
    }
}

// Buscar dados do mercado
$stmt = $db->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
$stmt->execute([$mercado_id]);
$mercado = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - Painel do Mercado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
</head>
<body class="om-app-layout">
    <!-- Sidebar -->
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>OneMundo</span>'">
        </div>

        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link">
                <i class="lucide-layout-dashboard"></i>
                <span>Dashboard</span>
            </a>
            <a href="pedidos.php" class="om-sidebar-link">
                <i class="lucide-shopping-bag"></i>
                <span>Pedidos</span>
            </a>
            <a href="produtos.php" class="om-sidebar-link">
                <i class="lucide-package"></i>
                <span>Produtos</span>
            </a>
                <a href="cardapio-ia.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path><path d="M12 18v4"></path><path d="M8 22h8"></path></svg>
                    <span class="om-sidebar-link-text">Cardapio IA</span>
                    <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;">NOVO</span>
                </a>
            <a href="categorias.php" class="om-sidebar-link">
                <i class="lucide-tags"></i>
                <span>Categorias</span>
            </a>
            <a href="faturamento.php" class="om-sidebar-link">
                <i class="lucide-bar-chart-3"></i>
                <span>Faturamento</span>
            </a>
            <a href="repasses.php" class="om-sidebar-link">
                <i class="lucide-wallet"></i>
                <span>Repasses</span>
            </a>
            <a href="avaliacoes.php" class="om-sidebar-link">
                <i class="lucide-star"></i>
                <span>Avaliacoes</span>
            </a>
            <a href="horarios.php" class="om-sidebar-link">
                <i class="lucide-clock"></i>
                <span>Horários</span>
            </a>
            <a href="perfil.php" class="om-sidebar-link active">
                <i class="lucide-settings"></i>
                <span>Configurações</span>
            </a>
        </nav>

        <div class="om-sidebar-footer">
            <a href="logout.php" class="om-sidebar-link">
                <i class="lucide-log-out"></i>
                <span>Sair</span>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="om-main-content">
        <!-- Topbar -->
        <header class="om-topbar">
            <button class="om-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="lucide-menu"></i>
            </button>

            <h1 class="om-topbar-title">Configurações</h1>

            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($mercado_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="om-page-content">
            <?php if ($message): ?>
            <div class="om-alert om-alert-success om-mb-4">
                <div class="om-alert-content">
                    <div class="om-alert-message"><?= htmlspecialchars($message) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="om-alert om-alert-error om-mb-4">
                <div class="om-alert-content">
                    <div class="om-alert-message"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="om-tabs om-mb-6">
                <button class="om-tab active" onclick="showTab('profile')">
                    <i class="lucide-store"></i> Dados da Loja
                </button>
                <button class="om-tab" onclick="showTab('bank')">
                    <i class="lucide-landmark"></i> Dados Bancários
                </button>
                <button class="om-tab" onclick="showTab('security')">
                    <i class="lucide-shield"></i> Segurança
                </button>
            </div>

            <!-- Tab: Dados da Loja -->
            <div id="tab-profile" class="om-tab-content active">
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">Informações da Loja</h3>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="om-card-body">
                            <div class="om-form-row">
                                <div class="om-form-group om-col-md-6">
                                    <label class="om-label">Nome da Loja *</label>
                                    <input type="text" name="name" class="om-input" value="<?= htmlspecialchars($mercado['name']) ?>" required>
                                </div>

                                <div class="om-form-group om-col-md-6">
                                    <label class="om-label">Telefone</label>
                                    <input type="tel" name="phone" class="om-input" value="<?= htmlspecialchars($mercado['phone'] ?? '') ?>" placeholder="(00) 00000-0000">
                                </div>
                            </div>

                            <div class="om-form-group">
                                <label class="om-label">Descrição</label>
                                <textarea name="description" class="om-input" rows="3" placeholder="Descreva sua loja para os clientes..."><?= htmlspecialchars($mercado['description'] ?? '') ?></textarea>
                            </div>

                            <div class="om-divider om-my-6"></div>

                            <h4 class="om-font-semibold om-mb-4">Imagens da Loja</h4>

                            <div class="om-form-row">
                                <div class="om-form-group om-col-md-6">
                                    <label class="om-label">Logo</label>
                                    <div style="display:flex;align-items:center;gap:12px;">
                                        <div id="logoPreview" style="width:80px;height:80px;border-radius:50%;background:var(--om-gray-100);display:flex;align-items:center;justify-content:center;overflow:hidden;cursor:pointer;border:2px dashed var(--om-gray-300);" onclick="document.getElementById('logoFile').click()">
                                            <?php if ($mercado['logo']): ?>
                                            <img src="<?= htmlspecialchars($mercado['logo']) ?>" style="width:100%;height:100%;object-fit:cover;">
                                            <?php else: ?>
                                            <i class="lucide-camera" style="font-size:1.5rem;color:var(--om-gray-400);"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <input type="file" id="logoFile" accept="image/*" style="display:none;" onchange="uploadImagem(this, 'logo', 'logoPreview', 'logoUrl')">
                                            <input type="hidden" name="logo_url" id="logoUrl" value="">
                                            <button type="button" class="om-btn om-btn-outline om-btn-sm" onclick="document.getElementById('logoFile').click()">
                                                <i class="lucide-upload"></i> Alterar Logo
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="om-form-group om-col-md-6">
                                    <label class="om-label">Banner</label>
                                    <div>
                                        <div id="bannerPreview" style="width:100%;height:80px;border-radius:8px;background:var(--om-gray-100);display:flex;align-items:center;justify-content:center;overflow:hidden;cursor:pointer;border:2px dashed var(--om-gray-300);margin-bottom:8px;" onclick="document.getElementById('bannerFile').click()">
                                            <?php if ($mercado['banner'] ?? ''): ?>
                                            <img src="<?= htmlspecialchars($mercado['banner']) ?>" style="width:100%;height:100%;object-fit:cover;">
                                            <?php else: ?>
                                            <i class="lucide-image" style="font-size:1.5rem;color:var(--om-gray-400);"></i>
                                            <?php endif; ?>
                                        </div>
                                        <input type="file" id="bannerFile" accept="image/*" style="display:none;" onchange="uploadImagem(this, 'banner', 'bannerPreview', 'bannerUrl')">
                                        <input type="hidden" name="banner_url" id="bannerUrl" value="">
                                        <button type="button" class="om-btn om-btn-outline om-btn-sm" onclick="document.getElementById('bannerFile').click()">
                                            <i class="lucide-upload"></i> Alterar Banner
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="om-divider om-my-6"></div>

                            <h4 class="om-font-semibold om-mb-4">Endereço</h4>

                            <div class="om-form-group">
                                <label class="om-label">Endereço Completo</label>
                                <input type="text" name="address" class="om-input" value="<?= htmlspecialchars($mercado['address'] ?? '') ?>" placeholder="Rua, número, complemento">
                            </div>

                            <div class="om-form-row">
                                <div class="om-form-group om-col-md-4">
                                    <label class="om-label">Cidade</label>
                                    <input type="text" name="city" class="om-input" value="<?= htmlspecialchars($mercado['city'] ?? '') ?>">
                                </div>

                                <div class="om-form-group om-col-md-4">
                                    <label class="om-label">Estado</label>
                                    <select name="state" class="om-select">
                                        <option value="">Selecione</option>
                                        <?php
                                        $estados = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
                                        foreach ($estados as $uf):
                                        ?>
                                        <option value="<?= $uf ?>" <?= ($mercado['state'] ?? '') === $uf ? 'selected' : '' ?>><?= $uf ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="om-form-group om-col-md-4">
                                    <label class="om-label">CEP</label>
                                    <input type="text" name="cep" class="om-input" value="<?= htmlspecialchars($mercado['cep'] ?? '') ?>" placeholder="00000-000">
                                </div>
                            </div>

                            <div class="om-divider om-my-6"></div>

                            <h4 class="om-font-semibold om-mb-4">Configurações de Pedido</h4>

                            <div class="om-form-row">
                                <div class="om-form-group om-col-md-6">
                                    <label class="om-label">Pedido Mínimo</label>
                                    <div class="om-input-group">
                                        <span class="om-input-prefix">R$</span>
                                        <input type="text" name="min_order" class="om-input" value="<?= number_format($mercado['min_order'] ?? 0, 2, ',', '.') ?>">
                                    </div>
                                    <span class="om-form-help">0 para sem mínimo</span>
                                </div>

                                <div class="om-form-group om-col-md-6">
                                    <label class="om-label">Tempo Médio de Preparo</label>
                                    <div class="om-input-group">
                                        <input type="number" name="delivery_time" class="om-input" value="<?= $mercado['delivery_time'] ?? 30 ?>" min="5">
                                        <span class="om-input-suffix">min</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Configurações de Entrega -->
                        <div class="om-card-body" style="border-top: 1px solid var(--om-gray-200);">
                            <h4 class="om-font-semibold om-mb-4" style="margin-top: var(--om-space-2);">
                                <i class="lucide-truck" style="margin-right: 8px;"></i>Configurações de Entrega
                            </h4>
                            <div class="om-grid om-grid-2">
                                <div class="om-form-group">
                                    <label class="om-label" style="display:flex;align-items:center;gap:12px;cursor:pointer;">
                                        <input type="checkbox" name="aceita_retirada" value="1" <?= ($mercado['aceita_retirada'] ?? 1) ? 'checked' : '' ?>
                                            style="width:20px;height:20px;accent-color:var(--om-primary);">
                                        <span>Aceita Retirada no Local</span>
                                    </label>
                                    <small class="om-text-secondary">Clientes podem retirar pedidos na loja</small>
                                </div>
                                <div class="om-form-group">
                                    <label class="om-label" style="display:flex;align-items:center;gap:12px;cursor:pointer;">
                                        <input type="checkbox" name="entrega_propria" value="1" <?= ($mercado['entrega_propria'] ?? 0) ? 'checked' : '' ?>
                                            style="width:20px;height:20px;accent-color:var(--om-primary);">
                                        <span>Entrega Própria</span>
                                    </label>
                                    <small class="om-text-secondary">Marque se você tem seus próprios entregadores (comissão 10%). Desmarcado = BoraUm (18%)</small>
                                </div>
                            </div>
                        </div>

                        <div class="om-card-footer">
                            <button type="submit" class="om-btn om-btn-primary">
                                <i class="lucide-save"></i> Salvar Alterações
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tab: Dados Bancários -->
            <div id="tab-bank" class="om-tab-content">
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">Dados para Repasse</h3>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_bank">

                        <div class="om-card-body">
                            <div class="om-alert om-alert-info om-mb-6">
                                <div class="om-alert-icon">
                                    <i class="lucide-info"></i>
                                </div>
                                <div class="om-alert-content">
                                    <div class="om-alert-message">
                                        Os repasses serão feitos para a conta cadastrada abaixo. Certifique-se de que os dados estão corretos.
                                    </div>
                                </div>
                            </div>

                            <h4 class="om-font-semibold om-mb-4">PIX (Preferencial)</h4>

                            <div class="om-form-row">
                                <div class="om-form-group om-col-md-4">
                                    <label class="om-label">Tipo de Chave</label>
                                    <select name="pix_type" class="om-select">
                                        <option value="">Selecione</option>
                                        <option value="cpf" <?= ($mercado['pix_type'] ?? '') === 'cpf' ? 'selected' : '' ?>>CPF</option>
                                        <option value="cnpj" <?= ($mercado['pix_type'] ?? '') === 'cnpj' ? 'selected' : '' ?>>CNPJ</option>
                                        <option value="email" <?= ($mercado['pix_type'] ?? '') === 'email' ? 'selected' : '' ?>>E-mail</option>
                                        <option value="telefone" <?= ($mercado['pix_type'] ?? '') === 'telefone' ? 'selected' : '' ?>>Telefone</option>
                                        <option value="aleatoria" <?= ($mercado['pix_type'] ?? '') === 'aleatoria' ? 'selected' : '' ?>>Chave Aleatória</option>
                                    </select>
                                </div>

                                <div class="om-form-group om-col-md-8">
                                    <label class="om-label">Chave PIX</label>
                                    <input type="text" name="pix_key" class="om-input" value="<?= htmlspecialchars($mercado['pix_key'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="om-divider om-my-6"></div>

                            <h4 class="om-font-semibold om-mb-4">Conta Bancária (Alternativa)</h4>

                            <div class="om-form-row">
                                <div class="om-form-group om-col-md-6">
                                    <label class="om-label">Banco</label>
                                    <input type="text" name="bank_name" class="om-input" value="<?= htmlspecialchars($mercado['bank_name'] ?? '') ?>" placeholder="Ex: Nubank, Bradesco, Itaú">
                                </div>

                                <div class="om-form-group om-col-md-6">
                                    <label class="om-label">Tipo de Conta</label>
                                    <select name="bank_type" class="om-select">
                                        <option value="corrente" <?= ($mercado['bank_type'] ?? '') === 'corrente' ? 'selected' : '' ?>>Conta Corrente</option>
                                        <option value="poupanca" <?= ($mercado['bank_type'] ?? '') === 'poupanca' ? 'selected' : '' ?>>Conta Poupança</option>
                                    </select>
                                </div>
                            </div>

                            <div class="om-form-row">
                                <div class="om-form-group om-col-md-4">
                                    <label class="om-label">Agência</label>
                                    <input type="text" name="bank_agency" class="om-input" value="<?= htmlspecialchars($mercado['bank_agency'] ?? '') ?>" placeholder="0000">
                                </div>

                                <div class="om-form-group om-col-md-8">
                                    <label class="om-label">Conta</label>
                                    <input type="text" name="bank_account" class="om-input" value="<?= htmlspecialchars($mercado['bank_account'] ?? '') ?>" placeholder="00000-0">
                                </div>
                            </div>
                        </div>

                        <div class="om-card-footer">
                            <button type="submit" class="om-btn om-btn-primary">
                                <i class="lucide-save"></i> Salvar Dados Bancários
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tab: Segurança -->
            <div id="tab-security" class="om-tab-content">
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">Alterar Senha</h3>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">

                        <div class="om-card-body">
                            <div class="om-form-group">
                                <label class="om-label">Senha Atual</label>
                                <input type="password" name="current_password" class="om-input" required>
                            </div>

                            <div class="om-form-row">
                                <div class="om-form-group om-col-md-6">
                                    <label class="om-label">Nova Senha</label>
                                    <input type="password" name="new_password" class="om-input" required minlength="6">
                                    <span class="om-form-help">Mínimo 6 caracteres</span>
                                </div>

                                <div class="om-form-group om-col-md-6">
                                    <label class="om-label">Confirmar Nova Senha</label>
                                    <input type="password" name="confirm_password" class="om-input" required>
                                </div>
                            </div>
                        </div>

                        <div class="om-card-footer">
                            <button type="submit" class="om-btn om-btn-primary">
                                <i class="lucide-lock"></i> Alterar Senha
                            </button>
                        </div>
                    </form>
                </div>

                <!-- 2FA Section -->
                <div class="om-card om-mt-6">
                    <div class="om-card-header">
                        <h3 class="om-card-title">Autenticacao em 2 Fatores (2FA)</h3>
                        <?php if (!empty($mercado['totp_enabled'])): ?>
                        <span class="om-badge om-badge-success">Ativada</span>
                        <?php else: ?>
                        <span class="om-badge om-badge-neutral">Desativada</span>
                        <?php endif; ?>
                    </div>
                    <div class="om-card-body">
                        <?php if (!empty($mercado['totp_enabled'])): ?>
                            <!-- 2FA is ON — show disable option -->
                            <div class="om-alert om-alert-success om-mb-4">
                                <div class="om-alert-content">
                                    <div class="om-alert-message">
                                        A verificacao em 2 etapas esta ativa. Sua conta tem uma camada extra de seguranca.
                                    </div>
                                </div>
                            </div>

                            <form method="POST">
                                <input type="hidden" name="action" value="disable_2fa">
                                <p class="om-text-sm om-text-muted om-mb-3">Para desativar, digite o codigo do seu aplicativo autenticador:</p>
                                <div class="om-form-row">
                                    <div class="om-form-group om-col-md-6">
                                        <input type="text" name="totp_disable_code" class="om-input" placeholder="000000"
                                               maxlength="6" pattern="[0-9]{6}" inputmode="numeric" required
                                               style="text-align:center;font-size:1.25rem;letter-spacing:0.3em;">
                                    </div>
                                    <div class="om-form-group om-col-md-6 om-flex om-items-end">
                                        <button type="submit" class="om-btn om-btn-error"
                                                onclick="return confirm('Tem certeza que deseja desativar a autenticacao em 2 fatores?')">
                                            <i class="lucide-shield-off"></i> Desativar 2FA
                                        </button>
                                    </div>
                                </div>
                            </form>

                        <?php elseif ($show_2fa_setup): ?>
                            <!-- 2FA Setup in progress — show QR + verify -->
                            <div class="om-alert om-alert-info om-mb-4">
                                <div class="om-alert-content">
                                    <div class="om-alert-message">
                                        Escaneie o QR Code abaixo com o Google Authenticator ou outro app compativel. Em seguida, digite o codigo de 6 digitos para confirmar.
                                    </div>
                                </div>
                            </div>

                            <div style="text-align:center;margin-bottom:24px;">
                                <img src="<?= htmlspecialchars($totp_setup_qr) ?>" alt="QR Code 2FA"
                                     style="width:200px;height:200px;border:4px solid var(--om-gray-200);border-radius:12px;margin-bottom:12px;">
                                <div class="om-text-sm om-text-muted">
                                    Ou insira manualmente a chave:
                                    <br>
                                    <code style="font-size:1rem;font-weight:700;letter-spacing:2px;color:var(--om-text-primary);background:var(--om-gray-100);padding:4px 12px;border-radius:6px;display:inline-block;margin-top:4px;">
                                        <?= htmlspecialchars($totp_setup_secret) ?>
                                    </code>
                                </div>
                            </div>

                            <form method="POST">
                                <input type="hidden" name="action" value="enable_2fa_step2">
                                <div class="om-form-group" style="max-width:300px;margin:0 auto;">
                                    <label class="om-label" style="text-align:center;display:block;">Codigo de Verificacao</label>
                                    <input type="text" name="totp_verify_code" class="om-input" placeholder="000000"
                                           maxlength="6" pattern="[0-9]{6}" inputmode="numeric" required autofocus
                                           style="text-align:center;font-size:1.5rem;font-weight:700;letter-spacing:0.5em;">
                                </div>
                                <div style="text-align:center;margin-top:16px;display:flex;gap:8px;justify-content:center;">
                                    <button type="submit" class="om-btn om-btn-primary">
                                        <i class="lucide-shield-check"></i> Verificar e Ativar
                                    </button>
                                    <a href="perfil.php" class="om-btn om-btn-outline">Cancelar</a>
                                </div>
                            </form>

                        <?php else: ?>
                            <!-- 2FA is OFF — show enable option -->
                            <p class="om-text-sm om-text-muted om-mb-4">
                                Adicione uma camada extra de seguranca exigindo um codigo temporario do Google Authenticator alem da senha ao fazer login.
                            </p>

                            <form method="POST">
                                <input type="hidden" name="action" value="enable_2fa_step1">
                                <button type="submit" class="om-btn om-btn-primary">
                                    <i class="lucide-shield-check"></i> Ativar 2FA
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Info da Conta -->
                <div class="om-card om-mt-6">
                    <div class="om-card-header">
                        <h3 class="om-card-title">Informações da Conta</h3>
                    </div>
                    <div class="om-card-body">
                        <div class="om-grid om-grid-cols-2 om-gap-4">
                            <div>
                                <p class="om-text-muted om-text-sm">E-mail de acesso</p>
                                <p class="om-font-medium"><?= htmlspecialchars($mercado['email']) ?></p>
                            </div>
                            <div>
                                <p class="om-text-muted om-text-sm">CNPJ</p>
                                <p class="om-font-medium"><?= htmlspecialchars($mercado['cnpj'] ?? 'Não informado') ?></p>
                            </div>
                            <div>
                                <p class="om-text-muted om-text-sm">Cadastrado em</p>
                                <p class="om-font-medium"><?= date('d/m/Y', strtotime($mercado['created_at'])) ?></p>
                            </div>
                            <div>
                                <p class="om-text-muted om-text-sm">Último acesso</p>
                                <p class="om-font-medium"><?= $mercado['last_login'] ? date('d/m/Y H:i', strtotime($mercado['last_login'])) : 'Primeiro acesso' ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <style>
    .om-tabs {
        display: flex;
        gap: var(--om-space-2);
        border-bottom: 1px solid var(--om-gray-200);
        padding-bottom: var(--om-space-2);
    }
    .om-tab {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        padding: var(--om-space-2) var(--om-space-4);
        border: none;
        background: none;
        color: var(--om-text-muted);
        font-size: var(--om-font-sm);
        cursor: pointer;
        border-radius: var(--om-radius-md);
        transition: all 0.2s;
    }
    .om-tab:hover {
        background: var(--om-gray-100);
        color: var(--om-text-primary);
    }
    .om-tab.active {
        background: var(--om-primary-50);
        color: var(--om-primary);
        font-weight: var(--om-font-medium);
    }
    .om-tab-content {
        display: none;
    }
    .om-tab-content.active {
        display: block;
    }
    .om-divider {
        border: none;
        border-top: 1px solid var(--om-gray-200);
    }
    .om-input-group {
        display: flex;
    }
    .om-input-group .om-input {
        border-radius: 0;
    }
    .om-input-group .om-input:first-child,
    .om-input-group .om-input-prefix + .om-input {
        border-radius: var(--om-radius-md) 0 0 var(--om-radius-md);
    }
    .om-input-group .om-input:last-child,
    .om-input-group .om-input:has(+ .om-input-suffix) {
        border-radius: 0 var(--om-radius-md) var(--om-radius-md) 0;
    }
    .om-input-prefix,
    .om-input-suffix {
        display: flex;
        align-items: center;
        padding: 0 var(--om-space-3);
        background: var(--om-gray-100);
        border: 1px solid var(--om-gray-300);
        color: var(--om-text-muted);
        font-size: var(--om-font-sm);
    }
    .om-input-prefix {
        border-right: none;
        border-radius: var(--om-radius-md) 0 0 var(--om-radius-md);
    }
    .om-input-suffix {
        border-left: none;
        border-radius: 0 var(--om-radius-md) var(--om-radius-md) 0;
    }

    @media (max-width: 768px) {
        .om-tabs {
            flex-wrap: wrap;
        }
    }
    </style>

    <script>
    function showTab(tabId) {
        // Remove active from all tabs
        document.querySelectorAll('.om-tab').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.om-tab-content').forEach(content => content.classList.remove('active'));

        // Add active to selected tab
        event.target.closest('.om-tab').classList.add('active');
        document.getElementById('tab-' + tabId).classList.add('active');
    }

    // Auto-show security tab if 2FA actions were performed
    <?php if ($show_security_tab): ?>
    document.addEventListener('DOMContentLoaded', function() {
        showTab('security');
    });
    <?php endif; ?>

    async function uploadImagem(input, type, previewId, urlId) {
        if (!input.files || !input.files[0]) return;

        const file = input.files[0];
        const preview = document.getElementById(previewId);

        // Preview local
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
        };
        reader.readAsDataURL(file);

        // Upload
        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', type);

        try {
            const res = await fetch('/api/mercado/upload.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById(urlId).value = data.data.url;
            } else {
                alert('Erro ao enviar: ' + data.message);
            }
        } catch(e) {
            alert('Erro de conexao ao enviar imagem');
        }
    }
    </script>
</body>
</html>
