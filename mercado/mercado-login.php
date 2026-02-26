<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO MERCADO - LOGIN INTEGRADO COM OPENCART
 * ══════════════════════════════════════════════════════════════════════════════
 * 
 * ✅ Usa as mesmas credenciais do OpenCart (tabela oc_customer)
 * ✅ Se já estiver logado no OpenCart, já entra logado no Mercado
 * ✅ Sessão compartilhada entre OpenCart e Mercado
 * 
 * ══════════════════════════════════════════════════════════════════════════════
 */

// Iniciar sessão (mesmo nome do OpenCart para compartilhar)
if (session_status() === PHP_SESSION_NONE) {
    // Usar mesmo cookie do OpenCart
    session_name('OCSESSID');
    session_start();
}

// Conexão com banco usando config.php do OpenCart
$pdo = null;
try {
    require_once __DIR__ . '/includes/env_loader.php';
    $pdo = getDbConnection();
} catch (Exception $e) {
    $config_file = dirname(__DIR__) . '/config.php';
    if (file_exists($config_file)) {
        require_once $config_file;
        $pdo = new PDO(
            "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
            DB_USERNAME, DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
}

// ============================================================================
// VERIFICAR SE JÁ ESTÁ LOGADO NO OPENCART
// ============================================================================

$customer_id = 0;
$customer = null;
$already_logged = false;

// Método 1: Verificar sessão direta
if (isset($_SESSION['customer_id']) && $_SESSION['customer_id'] > 0) {
    $customer_id = (int)$_SESSION['customer_id'];
    $already_logged = true;
}

// Método 2: Verificar na tabela de sessões do OpenCart (se existir)
if (!$customer_id && isset($_COOKIE['OCSESSID'])) {
    try {
        $session_id = $_COOKIE['OCSESSID'];
        $stmt = $pdo->prepare("SELECT data FROM oc_session WHERE session_id = ?");
        $stmt->execute([$session_id]);
        $session_data = $stmt->fetchColumn();
        
        if ($session_data) {
            // Decodificar dados da sessão do OpenCart
            $data = @unserialize($session_data);
            if ($data && isset($data['customer_id']) && $data['customer_id'] > 0) {
                $customer_id = (int)$data['customer_id'];
                $_SESSION['customer_id'] = $customer_id;
                $already_logged = true;
            }
        }
    } catch (Exception $e) {
        // Tabela oc_session pode não existir em todas as versões
    }
}

// Método 3: Verificar cookie de "lembrar-me" do OpenCart
if (!$customer_id && isset($_COOKIE['customer'])) {
    try {
        $token = $_COOKIE['customer'];
        $stmt = $pdo->prepare("
            SELECT customer_id FROM oc_customer 
            WHERE token = ? AND token != '' AND status = 1
        ");
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        
        if ($result) {
            $customer_id = (int)$result['customer_id'];
            $_SESSION['customer_id'] = $customer_id;
            $already_logged = true;
        }
    } catch (Exception $e) {}
}

// Se já logado, buscar dados do cliente
if ($customer_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ? AND status = '1'");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    
    if ($customer) {
        // Atualizar sessão com dados completos
        $_SESSION['customer_id'] = $customer['customer_id'];
        $_SESSION['customer_name'] = $customer['firstname'];
        $_SESSION['customer_email'] = $customer['email'];
        $_SESSION['customer_group_id'] = $customer['customer_group_id'];
        
        // Redirecionar para o Mercado
        $redirect = $_GET['redirect'] ?? '/mercado/';
        header('Location: ' . $redirect);
        exit;
    }
}

// ============================================================================
// PROCESSAR LOGIN/CADASTRO
// ============================================================================

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ========================================================================
    // LOGIN
    // ========================================================================
    if ($_POST['action'] === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);
        
        if ($email && $password) {
            // Buscar cliente no OpenCart
            $stmt = $pdo->prepare("
                SELECT * FROM oc_customer 
                WHERE LOWER(email) = LOWER(?) AND status = 1
            ");
            $stmt->execute([$email]);
            $customer = $stmt->fetch();
            
            if ($customer) {
                $valid = false;
                
                // Verificar senha - OpenCart pode usar diferentes métodos de hash
                // Método 1: password_verify (PHP 5.5+)
                if (password_verify($password, $customer['password'])) {
                    $valid = true;
                }
                // Método 2: SHA1 com salt (OpenCart antigo)
                elseif (isset($customer['salt']) && sha1($customer['salt'] . sha1($customer['salt'] . sha1($password))) === $customer['password']) {
                    $valid = true;
                }
                // Método 3: MD5 (muito antigo)
                elseif (md5($password) === $customer['password']) {
                    $valid = true;
                }
                // Método 4: SHA1 simples
                elseif (sha1($password) === $customer['password']) {
                    $valid = true;
                }
                
                if ($valid) {
                    // Login bem-sucedido!
                    $_SESSION['customer_id'] = $customer['customer_id'];
                    $_SESSION['customer_name'] = $customer['firstname'];
                    $_SESSION['customer_email'] = $customer['email'];
                    $_SESSION['customer_group_id'] = $customer['customer_group_id'];
                    
                    // Lembrar-me: criar token
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        $stmt = $pdo->prepare("UPDATE oc_customer SET token = ? WHERE customer_id = ?");
                        $stmt->execute([$token, $customer['customer_id']]);
                        setcookie('customer', $token, time() + (86400 * 30), '/', '', false, true);
                    }
                    
                    // Atualizar último login
                    $pdo->prepare("UPDATE oc_customer SET ip = ? WHERE customer_id = ?")->execute([
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $customer['customer_id']
                    ]);
                    
                    // Redirecionar
                    $redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '/mercado/';
                    header('Location: ' . $redirect);
                    exit;
                } else {
                    $error = 'Senha incorreta';
                }
            } else {
                $error = 'E-mail não cadastrado';
            }
        } else {
            $error = 'Preencha e-mail e senha';
        }
    }
    
    // ========================================================================
    // CADASTRO
    // ========================================================================
    if ($_POST['action'] === 'register') {
        $nome = trim($_POST['nome'] ?? '');
        $sobrenome = trim($_POST['sobrenome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = preg_replace('/\D/', '', $_POST['telefone'] ?? '');
        $password = $_POST['password'] ?? '';
        $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
        
        // Validações
        if (empty($nome)) {
            $error = 'Informe seu nome';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Informe um e-mail válido';
        } elseif (strlen($password) < 4) {
            $error = 'Senha deve ter no mínimo 4 caracteres';
        } else {
            // Verificar se email já existe
            $stmt = $pdo->prepare("SELECT customer_id FROM oc_customer WHERE LOWER(email) = LOWER(?)");
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $error = 'Este e-mail já está cadastrado. <a href="#" onclick="showTab(\'login\')">Fazer login</a>';
            } else {
                // Criar hash da senha (compatível com OpenCart 3.x+)
                $hash = password_hash($password, PASSWORD_DEFAULT);
                
                // Inserir cliente
                $stmt = $pdo->prepare("
                    INSERT INTO oc_customer 
                    (customer_group_id, store_id, language_id, firstname, lastname, email, telephone, 
                     password, custom_field, newsletter, ip, status, safe, token, code, date_added)
                    VALUES 
                    (1, 0, 2, ?, ?, ?, ?, ?, '[]', 0, ?, 1, 0, '', '', NOW())
                ");
                
                $stmt->execute([
                    $nome,
                    $sobrenome ?: '',
                    $email,
                    $telefone,
                    $hash,
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                $customer_id = $pdo->lastInsertId();
                
                // Salvar CPF em custom_field se informado
                if ($cpf && strlen($cpf) === 11) {
                    $custom = json_encode(['cpf' => $cpf]);
                    $pdo->prepare("UPDATE oc_customer SET custom_field = ? WHERE customer_id = ?")->execute([$custom, $customer_id]);
                }
                
                // Login automático após cadastro
                $_SESSION['customer_id'] = $customer_id;
                $_SESSION['customer_name'] = $nome;
                $_SESSION['customer_email'] = $email;
                $_SESSION['customer_group_id'] = 1;
                
                // Redirecionar
                $redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '/mercado/';
                header('Location: ' . $redirect);
                exit;
            }
        }
    }
}

// Redirect URL
$redirect_url = $_GET['redirect'] ?? '/mercado/';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar - OneMundo Mercado</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            display: flex;
            background: linear-gradient(135deg, #047857 0%, #059669 50%, #10b981 100%);
        }
        
        .auth-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .auth-card {
            background: white;
            border-radius: 32px;
            width: 100%;
            max-width: 460px;
            padding: 48px 40px;
            box-shadow: 0 25px 80px rgba(0,0,0,0.2);
        }
        
        .auth-logo { text-align: center; margin-bottom: 32px; }
        .auth-logo-icon { font-size: 56px; margin-bottom: 12px; }
        .auth-logo h1 { font-size: 1.8rem; font-weight: 800; color: #1e293b; }
        .auth-logo p { color: #64748b; margin-top: 4px; }
        
        .auth-logo .integration-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ecfdf5;
            color: #059669;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 12px;
        }
        
        .auth-tabs { display: flex; background: #f1f5f9; border-radius: 14px; padding: 4px; margin-bottom: 28px; }
        .auth-tab { flex: 1; padding: 14px; background: transparent; border: none; border-radius: 12px; font-size: 15px; font-weight: 600; color: #64748b; cursor: pointer; transition: all 0.3s; }
        .auth-tab.active { background: white; color: #059669; box-shadow: 0 2px 10px rgba(0,0,0,0.06); }
        
        .auth-form { display: none; }
        .auth-form.active { display: block; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px; }
        .form-input-wrapper { position: relative; }
        .form-input-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 18px; }
        .form-input { width: 100%; padding: 16px 16px 16px 50px; border: 2px solid #e2e8f0; border-radius: 14px; font-size: 16px; transition: all 0.2s; }
        .form-input:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 4px rgba(16,185,129,0.1); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .toggle-password { position: absolute; right: 16px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 18px; }
        
        .form-options { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .remember-me { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #64748b; cursor: pointer; }
        .remember-me input { width: 18px; height: 18px; accent-color: #10b981; }
        .forgot-link { color: #059669; font-size: 14px; font-weight: 600; text-decoration: none; }
        .forgot-link:hover { text-decoration: underline; }
        
        .auth-submit { width: 100%; padding: 18px; background: linear-gradient(135deg, #10b981, #059669); border: none; border-radius: 14px; color: white; font-size: 17px; font-weight: 700; cursor: pointer; transition: all 0.3s; box-shadow: 0 8px 25px rgba(16,185,129,0.35); display: flex; align-items: center; justify-content: center; gap: 10px; }
        .auth-submit:hover { transform: translateY(-2px); box-shadow: 0 12px 35px rgba(16,185,129,0.45); }
        
        .auth-divider { display: flex; align-items: center; gap: 16px; margin: 28px 0; }
        .auth-divider::before, .auth-divider::after { content: ""; flex: 1; height: 1px; background: #e2e8f0; }
        .auth-divider span { color: #94a3b8; font-size: 13px; }
        
        .social-buttons { display: flex; gap: 12px; }
        .social-btn { flex: 1; padding: 14px; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 12px; display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 15px; font-weight: 600; color: #374151; cursor: pointer; transition: all 0.2s; text-decoration: none; }
        .social-btn:hover { border-color: #cbd5e1; background: white; }
        .social-btn.google i { color: #ea4335; }
        
        .auth-message { padding: 14px 16px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .auth-message.error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
        .auth-message.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #059669; }
        .auth-message a { color: inherit; font-weight: 600; }
        
        .auth-terms { text-align: center; font-size: 13px; color: #64748b; margin-top: 24px; }
        .auth-terms a { color: #059669; text-decoration: none; }
        
        .auth-back { position: absolute; top: 20px; left: 20px; width: 44px; height: 44px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; text-decoration: none; font-size: 20px; transition: all 0.2s; }
        .auth-back:hover { background: rgba(255,255,255,0.3); }
        
        .opencart-link { text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
        .opencart-link a { display: inline-flex; align-items: center; gap: 8px; color: #64748b; text-decoration: none; font-size: 14px; }
        .opencart-link a:hover { color: #059669; }
        
        @media (max-width: 500px) {
            .auth-card { padding: 32px 24px; border-radius: 24px; }
            .social-buttons { flex-direction: column; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>

<!-- HEADER PREMIUM v3.0 -->
<style>

/* ══════════════════════════════════════════════════════════════════════════════
   🎨 HEADER PREMIUM v3.0 - OneMundo Mercado
   ══════════════════════════════════════════════════════════════════════════════ */

/* Variáveis do Header */
:root {
    --header-bg: rgba(255, 255, 255, 0.92);
    --header-bg-scrolled: rgba(255, 255, 255, 0.98);
    --header-blur: 20px;
    --header-shadow: 0 4px 30px rgba(0, 0, 0, 0.08);
    --header-border: rgba(0, 0, 0, 0.04);
    --header-height: 72px;
    --header-height-mobile: 64px;
}

/* Header Principal */
.header, .site-header, [class*="header-main"] {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 1000 !important;
    background: var(--header-bg) !important;
    backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    -webkit-backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    border-bottom: 1px solid var(--header-border) !important;
    box-shadow: none !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    height: auto !important;
    min-height: var(--header-height) !important;
}

.header.scrolled, .site-header.scrolled {
    background: var(--header-bg-scrolled) !important;
    box-shadow: var(--header-shadow) !important;
}

/* Container do Header */
.header-inner, .header-content, .header > div:first-child {
    max-width: 1400px !important;
    margin: 0 auto !important;
    padding: 12px 24px !important;
    display: flex !important;
    align-items: center !important;
    gap: 20px !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOCALIZAÇÃO - Estilo Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.location-btn, .endereco, [class*="location"], [class*="endereco"], [class*="address"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 10px 18px !important;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(16, 185, 129, 0.04)) !important;
    border: 1px solid rgba(16, 185, 129, 0.15) !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    min-width: 200px !important;
    max-width: 320px !important;
}

.location-btn:hover, .endereco:hover, [class*="location"]:hover {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.06)) !important;
    border-color: rgba(16, 185, 129, 0.25) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15) !important;
}

/* Ícone de localização */
.location-btn svg, .location-btn i, [class*="location"] svg {
    width: 22px !important;
    height: 22px !important;
    color: #10b981 !important;
    flex-shrink: 0 !important;
}

/* Texto da localização */
.location-text, .endereco-text {
    flex: 1 !important;
    min-width: 0 !important;
}

.location-label, .entregar-em {
    font-size: 11px !important;
    font-weight: 500 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin-bottom: 2px !important;
}

.location-address, .endereco-rua {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Seta da localização */
.location-arrow, .location-btn > svg:last-child {
    width: 16px !important;
    height: 16px !important;
    color: #94a3b8 !important;
    transition: transform 0.2s ease !important;
}

.location-btn:hover .location-arrow {
    transform: translateX(3px) !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   TEMPO DE ENTREGA - Badge Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.delivery-time, .tempo-entrega, [class*="delivery-time"], [class*="tempo"] {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 10px 16px !important;
    background: linear-gradient(135deg, #0f172a, #1e293b) !important;
    border-radius: 12px !important;
    color: white !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2) !important;
    transition: all 0.3s ease !important;
}

.delivery-time:hover, .tempo-entrega:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.25) !important;
}

.delivery-time svg, .tempo-entrega svg, .delivery-time i {
    width: 18px !important;
    height: 18px !important;
    color: #10b981 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOGO - Design Moderno
   ══════════════════════════════════════════════════════════════════════════════ */

.logo, .site-logo, [class*="logo"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    text-decoration: none !important;
    transition: transform 0.3s ease !important;
}

.logo:hover {
    transform: scale(1.02) !important;
}

.logo-icon, .logo img, .logo svg {
    width: 48px !important;
    height: 48px !important;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    border-radius: 14px !important;
    padding: 10px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.logo:hover .logo-icon, .logo:hover img {
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
    transform: rotate(-3deg) !important;
}

.logo-text, .logo span, .site-title {
    font-size: 1.5rem !important;
    font-weight: 800 !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
    background-clip: text !important;
    letter-spacing: -0.02em !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   BUSCA - Search Bar Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.search-container, .search-box, [class*="search"], .busca {
    flex: 1 !important;
    max-width: 600px !important;
    position: relative !important;
}

.search-input, input[type="search"], input[name*="search"], input[name*="busca"], .busca input {
    width: 100% !important;
    padding: 14px 20px 14px 52px !important;
    background: #f1f5f9 !important;
    border: 2px solid transparent !important;
    border-radius: 16px !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
}

.search-input:hover, input[type="search"]:hover {
    background: #e2e8f0 !important;
}

.search-input:focus, input[type="search"]:focus {
    background: #ffffff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12), inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
    outline: none !important;
}

.search-input::placeholder {
    color: #94a3b8 !important;
    font-weight: 400 !important;
}

/* Ícone da busca */
.search-icon, .search-container svg, .busca svg {
    position: absolute !important;
    left: 18px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 22px !important;
    height: 22px !important;
    color: #94a3b8 !important;
    pointer-events: none !important;
    transition: color 0.3s ease !important;
}

.search-input:focus + .search-icon,
.search-container:focus-within svg {
    color: #10b981 !important;
}

/* Botão de busca por voz (opcional) */
.search-voice-btn {
    position: absolute !important;
    right: 12px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 36px !important;
    height: 36px !important;
    background: transparent !important;
    border: none !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.search-voice-btn:hover {
    background: rgba(16, 185, 129, 0.1) !important;
}

.search-voice-btn svg {
    width: 20px !important;
    height: 20px !important;
    color: #64748b !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   CARRINHO - Cart Button Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.cart-btn, .carrinho-btn, [class*="cart"], [class*="carrinho"], a[href*="cart"], a[href*="carrinho"] {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 52px !important;
    height: 52px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border: none !important;
    border-radius: 16px !important;
    cursor: pointer !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
}

.cart-btn:hover, .carrinho-btn:hover, [class*="cart"]:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4) !important;
}

.cart-btn:active {
    transform: translateY(-1px) scale(0.98) !important;
}

.cart-btn svg, .carrinho-btn svg, [class*="cart"] svg {
    width: 26px !important;
    height: 26px !important;
    color: white !important;
}

/* Badge do carrinho */
.cart-badge, .carrinho-badge, [class*="cart-count"], [class*="badge"] {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 24px !important;
    height: 24px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: white !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 6px !important;
    border: 3px solid white !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: badge-pulse 2s ease-in-out infinite !important;
}

@keyframes badge-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* ══════════════════════════════════════════════════════════════════════════════
   MENU MOBILE
   ══════════════════════════════════════════════════════════════════════════════ */

.menu-btn, .hamburger, [class*="menu-toggle"] {
    display: none !important;
    width: 44px !important;
    height: 44px !important;
    background: #f1f5f9 !important;
    border: none !important;
    border-radius: 12px !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.menu-btn:hover {
    background: #e2e8f0 !important;
}

.menu-btn svg {
    width: 24px !important;
    height: 24px !important;
    color: #475569 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   RESPONSIVO
   ══════════════════════════════════════════════════════════════════════════════ */

@media (max-width: 1024px) {
    .search-container, .search-box {
        max-width: 400px !important;
    }
    
    .location-btn, .endereco {
        max-width: 250px !important;
    }
}

@media (max-width: 768px) {
    :root {
        --header-height: var(--header-height-mobile);
    }
    
    .header-inner, .header-content {
        padding: 10px 16px !important;
        gap: 12px !important;
    }
    
    /* Esconder busca no header mobile - mover para baixo */
    .search-container, .search-box, [class*="search"]:not(.search-icon) {
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        right: 0 !important;
        max-width: 100% !important;
        padding: 12px 16px !important;
        background: white !important;
        border-top: 1px solid #e2e8f0 !important;
        display: none !important;
    }
    
    .search-container.active {
        display: block !important;
    }
    
    /* Logo menor */
    .logo-icon, .logo img {
        width: 42px !important;
        height: 42px !important;
        border-radius: 12px !important;
    }
    
    .logo-text {
        display: none !important;
    }
    
    /* Localização compacta */
    .location-btn, .endereco {
        min-width: auto !important;
        max-width: 180px !important;
        padding: 8px 12px !important;
    }
    
    .location-label, .entregar-em {
        display: none !important;
    }
    
    .location-address {
        font-size: 13px !important;
    }
    
    /* Tempo de entrega menor */
    .delivery-time, .tempo-entrega {
        padding: 8px 12px !important;
        font-size: 12px !important;
    }
    
    /* Carrinho menor */
    .cart-btn, .carrinho-btn {
        width: 46px !important;
        height: 46px !important;
        border-radius: 14px !important;
    }
    
    .cart-btn svg {
        width: 22px !important;
        height: 22px !important;
    }
    
    /* Mostrar menu button */
    .menu-btn, .hamburger {
        display: flex !important;
    }
}

@media (max-width: 480px) {
    .location-btn, .endereco {
        max-width: 140px !important;
    }
    
    .delivery-time, .tempo-entrega {
        display: none !important;
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   ANIMAÇÕES DE ENTRADA
   ══════════════════════════════════════════════════════════════════════════════ */

@keyframes headerSlideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.header, .site-header {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *, .header-content > * {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *:nth-child(1) { animation-delay: 0.05s !important; }
.header-inner > *:nth-child(2) { animation-delay: 0.1s !important; }
.header-inner > *:nth-child(3) { animation-delay: 0.15s !important; }
.header-inner > *:nth-child(4) { animation-delay: 0.2s !important; }
.header-inner > *:nth-child(5) { animation-delay: 0.25s !important; }

/* ══════════════════════════════════════════════════════════════════════════════
   AJUSTES DE BODY PARA HEADER FIXED
   ══════════════════════════════════════════════════════════════════════════════ */

body {
    padding-top: calc(var(--header-height) + 10px) !important;
}

@media (max-width: 768px) {
    body {
        padding-top: calc(var(--header-height-mobile) + 10px) !important;
    }
}

</style>
</head>
<body>

<a href="/mercado/" class="auth-back"><i class="fas fa-arrow-left"></i></a>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="auth-logo-icon">🛒</div>
            <h1>OneMundo Mercado</h1>
            <p>Seu mercado online favorito</p>
            <div class="integration-badge">
                <i class="fas fa-link"></i>
                Mesmo login do site OneMundo
            </div>
        </div>
        
        <div class="auth-tabs">
            <button class="auth-tab active" onclick="showTab('login')">Entrar</button>
            <button class="auth-tab" onclick="showTab('register')">Criar Conta</button>
        </div>
        
        <?php if ($error): ?>
        <div class="auth-message error">
            <i class="fas fa-exclamation-circle"></i>
            <?= $error ?>
        </div>
        <?php endif; ?>
        
        <!-- LOGIN FORM -->
        <form class="auth-form active" id="loginForm" method="POST">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect_url) ?>">
            
            <div class="form-group">
                <label class="form-label">E-mail</label>
                <div class="form-input-wrapper">
                    <i class="fas fa-envelope form-input-icon"></i>
                    <input type="email" name="email" class="form-input" placeholder="seu@email.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Senha</label>
                <div class="form-input-wrapper">
                    <i class="fas fa-lock form-input-icon"></i>
                    <input type="password" name="password" class="form-input" id="loginPassword" placeholder="Sua senha" required>
                    <button type="button" class="toggle-password" onclick="togglePassword('loginPassword')"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            
            <div class="form-options">
                <label class="remember-me">
                    <input type="checkbox" name="remember">
                    Lembrar-me
                </label>
                <a href="/index.php?route=account/forgotten" class="forgot-link">Esqueci minha senha</a>
            </div>
            
            <button type="submit" class="auth-submit">
                <i class="fas fa-sign-in-alt"></i> Entrar
            </button>
        </form>
        
        <!-- REGISTER FORM -->
        <form class="auth-form" id="registerForm" method="POST">
            <input type="hidden" name="action" value="register">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect_url) ?>">
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nome</label>
                    <div class="form-input-wrapper">
                        <i class="fas fa-user form-input-icon"></i>
                        <input type="text" name="nome" class="form-input" placeholder="Seu nome" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Sobrenome</label>
                    <div class="form-input-wrapper">
                        <i class="fas fa-user form-input-icon"></i>
                        <input type="text" name="sobrenome" class="form-input" placeholder="Sobrenome">
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">E-mail</label>
                <div class="form-input-wrapper">
                    <i class="fas fa-envelope form-input-icon"></i>
                    <input type="email" name="email" class="form-input" placeholder="seu@email.com" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Telefone</label>
                    <div class="form-input-wrapper">
                        <i class="fas fa-phone form-input-icon"></i>
                        <input type="tel" name="telefone" class="form-input" id="telefoneInput" placeholder="(11) 99999-9999" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">CPF <small style="color:#94a3b8">(opcional)</small></label>
                    <div class="form-input-wrapper">
                        <i class="fas fa-id-card form-input-icon"></i>
                        <input type="text" name="cpf" class="form-input" id="cpfInput" placeholder="000.000.000-00">
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Senha</label>
                <div class="form-input-wrapper">
                    <i class="fas fa-lock form-input-icon"></i>
                    <input type="password" name="password" class="form-input" id="registerPassword" placeholder="Mínimo 4 caracteres" required minlength="4">
                    <button type="button" class="toggle-password" onclick="togglePassword('registerPassword')"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            
            <button type="submit" class="auth-submit">
                <i class="fas fa-user-plus"></i> Criar Conta
            </button>
            
            <p class="auth-terms">
                Ao criar uma conta, você concorda com nossos<br>
                <a href="/termos">Termos de Uso</a> e <a href="/privacidade">Política de Privacidade</a>
            </p>
        </form>
        
        <div class="auth-divider"><span>ou continue com</span></div>
        
        <div class="social-buttons">
            <a href="/index.php?route=extension/module/social_login/google" class="social-btn google">
                <i class="fab fa-google"></i> Google
            </a>
            <a href="/index.php?route=extension/module/social_login/facebook" class="social-btn" style="color:#1877f2">
                <i class="fab fa-facebook"></i> Facebook
            </a>
        </div>
        
        <div class="opencart-link">
            <a href="/index.php?route=account/login">
                <i class="fas fa-external-link-alt"></i>
                Ir para o site principal OneMundo
            </a>
        </div>
    </div>
</div>

<script>
function showTab(tab) {
    document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
    
    if (tab === 'login') {
        document.querySelector('.auth-tab:nth-child(1)').classList.add('active');
        document.getElementById('loginForm').classList.add('active');
    } else {
        document.querySelector('.auth-tab:nth-child(2)').classList.add('active');
        document.getElementById('registerForm').classList.add('active');
    }
}

function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = input.nextElementSibling.querySelector('i');
    input.type = input.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}

document.getElementById('telefoneInput')?.addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '').slice(0, 11);
    if (v.length > 0) { v = '(' + v; if (v.length > 3) v = v.slice(0,3) + ') ' + v.slice(3); if (v.length > 10) v = v.slice(0,10) + '-' + v.slice(10); }
    e.target.value = v;
});

document.getElementById('cpfInput')?.addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '').slice(0, 11);
    v = v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    e.target.value = v;
});

<?php if ($error && isset($_POST['action']) && $_POST['action'] === 'register'): ?>
showTab('register');
<?php endif; ?>
</script>


<script>
// Header scroll effect
(function() {
    const header = document.querySelector('.header, .site-header, [class*="header-main"]');
    if (!header) return;
    
    let lastScroll = 0;
    let ticking = false;
    
    function updateHeader() {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
        
        // Hide/show on scroll (opcional)
        /*
        if (currentScroll > lastScroll && currentScroll > 100) {
            header.style.transform = 'translateY(-100%)';
        } else {
            header.style.transform = 'translateY(0)';
        }
        */
        
        lastScroll = currentScroll;
        ticking = false;
    }
    
    window.addEventListener('scroll', function() {
        if (!ticking) {
            requestAnimationFrame(updateHeader);
            ticking = true;
        }
    });
    
    // Cart badge animation
    window.animateCartBadge = function() {
        const badge = document.querySelector('.cart-badge, .carrinho-badge, [class*="cart-count"]');
        if (badge) {
            badge.style.transform = 'scale(1.3)';
            setTimeout(() => {
                badge.style.transform = 'scale(1)';
            }, 200);
        }
    };
    
    // Mobile search toggle
    const searchToggle = document.querySelector('.search-toggle, [class*="search-btn"]');
    const searchContainer = document.querySelector('.search-container, .search-box');
    
    if (searchToggle && searchContainer) {
        searchToggle.addEventListener('click', function() {
            searchContainer.classList.toggle('active');
        });
    }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     🎨 ONEMUNDO HEADER PREMIUM v3.0 - CSS FINAL UNIFICADO
═══════════════════════════════════════════════════════════════════════════════ -->
<style id="om-header-final">
/* RESET */
.mkt-header, .mkt-header-row, .mkt-logo, .mkt-logo-box, .mkt-logo-text,
.mkt-user, .mkt-user-avatar, .mkt-guest, .mkt-cart, .mkt-cart-count, .mkt-search,
.om-topbar, .om-topbar-main, .om-topbar-icon, .om-topbar-content,
.om-topbar-label, .om-topbar-address, .om-topbar-arrow, .om-topbar-time {
    all: revert;
}

/* TOPBAR VERDE */
.om-topbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 14px 20px !important;
    background: linear-gradient(135deg, #047857 0%, #059669 40%, #10b981 100%) !important;
    color: #fff !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    position: relative !important;
    overflow: hidden !important;
}

.om-topbar::before {
    content: '' !important;
    position: absolute !important;
    top: 0 !important;
    left: -100% !important;
    width: 100% !important;
    height: 100% !important;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent) !important;
    transition: left 0.6s ease !important;
}

.om-topbar:hover::before { left: 100% !important; }
.om-topbar:hover { background: linear-gradient(135deg, #065f46 0%, #047857 40%, #059669 100%) !important; }

.om-topbar-main {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.om-topbar-icon {
    width: 40px !important;
    height: 40px !important;
    background: rgba(255,255,255,0.18) !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar:hover .om-topbar-icon {
    background: rgba(255,255,255,0.25) !important;
    transform: scale(1.05) !important;
}

.om-topbar-icon svg { width: 20px !important; height: 20px !important; color: #fff !important; }

.om-topbar-content { flex: 1 !important; min-width: 0 !important; }

.om-topbar-label {
    font-size: 11px !important;
    font-weight: 500 !important;
    opacity: 0.85 !important;
    margin-bottom: 2px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: block !important;
}

.om-topbar-address {
    font-size: 14px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: 220px !important;
}

.om-topbar-arrow {
    width: 32px !important;
    height: 32px !important;
    background: rgba(255,255,255,0.12) !important;
    border-radius: 8px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    transition: all 0.3s ease !important;
    margin-right: 12px !important;
}

.om-topbar:hover .om-topbar-arrow {
    background: rgba(255,255,255,0.2) !important;
    transform: translateX(3px) !important;
}

.om-topbar-arrow svg { width: 16px !important; height: 16px !important; color: #fff !important; }

.om-topbar-time {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 8px 14px !important;
    background: rgba(0,0,0,0.2) !important;
    border-radius: 50px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar-time:hover { background: rgba(0,0,0,0.3) !important; transform: scale(1.02) !important; }
.om-topbar-time svg { width: 16px !important; height: 16px !important; color: #34d399 !important; }

/* HEADER BRANCO */
.mkt-header {
    background: #ffffff !important;
    padding: 0 !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 9999 !important;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08) !important;
    border-bottom: none !important;
}

.mkt-header-row {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 14px 20px !important;
    margin-bottom: 0 !important;
    background: #fff !important;
    border-bottom: 1px solid rgba(0,0,0,0.06) !important;
}

/* LOGO */
.mkt-logo {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-decoration: none !important;
    flex-shrink: 0 !important;
}

.mkt-logo-box {
    width: 44px !important;
    height: 44px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 14px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 22px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-logo:hover .mkt-logo-box {
    transform: scale(1.05) rotate(-3deg) !important;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45) !important;
}

.mkt-logo-text {
    font-size: 20px !important;
    font-weight: 800 !important;
    color: #10b981 !important;
    letter-spacing: -0.02em !important;
}

/* USER */
.mkt-user { margin-left: auto !important; text-decoration: none !important; }

.mkt-user-avatar {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 50% !important;
    color: #fff !important;
    font-weight: 700 !important;
    font-size: 16px !important;
    box-shadow: 0 3px 12px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.mkt-user-avatar:hover {
    transform: scale(1.08) !important;
    box-shadow: 0 5px 18px rgba(16, 185, 129, 0.4) !important;
}

.mkt-user.mkt-guest {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: #f1f5f9 !important;
    border-radius: 12px !important;
    transition: all 0.3s ease !important;
}

.mkt-user.mkt-guest:hover { background: #e2e8f0 !important; }
.mkt-user.mkt-guest svg { width: 24px !important; height: 24px !important; color: #64748b !important; }

/* CARRINHO */
.mkt-cart {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 46px !important;
    height: 46px !important;
    background: linear-gradient(135deg, #1e293b, #0f172a) !important;
    border: none !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    box-shadow: 0 4px 15px rgba(15, 23, 42, 0.25) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-cart:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.3) !important;
}

.mkt-cart:active { transform: translateY(-1px) scale(0.98) !important; }
.mkt-cart svg { width: 22px !important; height: 22px !important; color: #fff !important; }

.mkt-cart-count {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 22px !important;
    height: 22px !important;
    padding: 0 6px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border-radius: 11px !important;
    color: #fff !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 2px solid #fff !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: cartPulse 2s ease-in-out infinite !important;
}

@keyframes cartPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

/* BUSCA */
.mkt-search {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    background: #f1f5f9 !important;
    border-radius: 14px !important;
    padding: 0 16px !important;
    margin: 0 16px 16px !important;
    border: 2px solid transparent !important;
    transition: all 0.3s ease !important;
}

.mkt-search:focus-within {
    background: #fff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1) !important;
}

.mkt-search svg {
    width: 20px !important;
    height: 20px !important;
    color: #94a3b8 !important;
    flex-shrink: 0 !important;
    transition: color 0.3s ease !important;
}

.mkt-search:focus-within svg { color: #10b981 !important; }

.mkt-search input {
    flex: 1 !important;
    border: none !important;
    background: transparent !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    outline: none !important;
    padding: 14px 0 !important;
    width: 100% !important;
}

.mkt-search input::placeholder { color: #94a3b8 !important; }

/* RESPONSIVO */
@media (max-width: 480px) {
    .om-topbar { padding: 12px 16px !important; }
    .om-topbar-icon { width: 36px !important; height: 36px !important; }
    .om-topbar-address { max-width: 150px !important; font-size: 13px !important; }
    .om-topbar-arrow { display: none !important; }
    .om-topbar-time { padding: 6px 10px !important; font-size: 11px !important; }
    .mkt-header-row { padding: 12px 16px !important; }
    .mkt-logo-box { width: 40px !important; height: 40px !important; font-size: 18px !important; }
    .mkt-logo-text { font-size: 18px !important; }
    .mkt-cart { width: 42px !important; height: 42px !important; }
    .mkt-search { margin: 0 12px 12px !important; }
    .mkt-search input { font-size: 14px !important; padding: 12px 0 !important; }
}

/* ANIMAÇÕES */
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.mkt-header { animation: slideDown 0.4s ease !important; }

::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
::selection { background: rgba(16, 185, 129, 0.2); color: #047857; }
</style>

<script>
(function() {
    var h = document.querySelector('.mkt-header');
    if (h && !document.querySelector('.om-topbar')) {
        var t = document.createElement('div');
        t.className = 'om-topbar';
        t.innerHTML = '<div class="om-topbar-main"><div class="om-topbar-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><div class="om-topbar-content"><div class="om-topbar-label">Entregar em</div><div class="om-topbar-address" id="omAddrFinal">Carregando...</div></div><div class="om-topbar-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div></div><div class="om-topbar-time"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>25-35 min</div>';
        h.insertBefore(t, h.firstChild);
        fetch('/mercado/api/address.php?action=list').then(r=>r.json()).then(d=>{var el=document.getElementById('omAddrFinal');if(el&&d.current)el.textContent=d.current.address_1||'Selecionar';}).catch(()=>{});
    }
    var l = document.querySelector('.mkt-logo');
    if (l && !l.querySelector('.mkt-logo-text')) {
        var s = document.createElement('span');
        s.className = 'mkt-logo-text';
        s.textContent = 'Mercado';
        l.appendChild(s);
    }
})();
</script>
</body>
</html>
