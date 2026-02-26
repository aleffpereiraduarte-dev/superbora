<?php
/**
 * LOGIN/CADASTRO UNIFICADO - ONEMUNDO MERCADO
 * Usa a mesma base de clientes do OpenCart (oc_customer)
 *
 * Upload: /mercado/login.php
 */

// Configurações de sessão
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Carregar config do OpenCart
$_oc_root = dirname(__DIR__);
if (file_exists($_oc_root . '/config.php') && !defined('DB_HOSTNAME')) {
    require_once($_oc_root . '/config.php');
}

// Conexão com banco
try {
    $db_host = defined('DB_HOSTNAME') ? DB_HOSTNAME : '127.0.0.1';
    $db_name = defined('DB_DATABASE') ? DB_DATABASE : 'love1';
    $db_user = defined('DB_USERNAME') ? DB_USERNAME : 'root';
    $db_pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';

    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    error_log('Erro de conexão BD: ' . $e->getMessage());
    die("Serviço temporariamente indisponível.");
}

$prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';
$error = '';
$success = '';
$tab = $_GET['tab'] ?? 'login';

// Verificar se já está logado
if (isset($_SESSION['customer_id']) && $_SESSION['customer_id'] > 0) {
    $redirect = $_GET['redirect'] ?? '/mercado/';
    header('Location: ' . $redirect);
    exit;
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // === LOGIN ===
    if ($_POST['action'] === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Preencha todos os campos.';
        } else {
            // Buscar cliente no OpenCart
            $stmt = $pdo->prepare("
                SELECT customer_id, customer_group_id, store_id, language_id,
                       firstname, lastname, email, telephone, password, salt,
                       custom_field, ip, status, safe, token, code
                FROM {$prefix}customer
                WHERE LOWER(email) = LOWER(?) AND status = 1
            ");
            $stmt->execute([$email]);
            $customer = $stmt->fetch();

            if ($customer) {
                $valid = false;

                // Verificar senha - OpenCart usa SHA1 com salt ou password_hash
                if (!empty($customer['salt'])) {
                    $hash = sha1($customer['salt'] . sha1($customer['salt'] . sha1($password)));
                    if ($hash === $customer['password']) {
                        $valid = true;
                    }
                }

                // Método password_hash (OpenCart 3.x+)
                if (!$valid && password_verify($password, $customer['password'])) {
                    $valid = true;
                }

                if ($valid) {
                    // Login OK - Configurar sessão
                    $_SESSION['customer_id'] = $customer['customer_id'];
                    $_SESSION['customer_name'] = $customer['firstname'];
                    $_SESSION['customer_email'] = $customer['email'];
                    $_SESSION['customer_telephone'] = $customer['telephone'];
                    $_SESSION['mercado_customer_id'] = $customer['customer_id'];
                    $_SESSION['mercado_customer_name'] = $customer['firstname'];
                    $_SESSION['mercado_logged_at'] = time();

                    // Sincronizar sessão com OpenCart
                    $session_id = session_id();
                    $session_data = serialize([
                        'customer_id' => $customer['customer_id'],
                        'customer' => [
                            'customer_id' => $customer['customer_id'],
                            'customer_group_id' => $customer['customer_group_id'],
                            'firstname' => $customer['firstname'],
                            'lastname' => $customer['lastname'],
                            'email' => $customer['email'],
                            'telephone' => $customer['telephone']
                        ],
                        'shipping_address' => [],
                        'payment_address' => []
                    ]);

                    try {
                        $stmt = $pdo->prepare("REPLACE INTO {$prefix}session (session_id, data, expire) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))");
                        $stmt->execute([$session_id, $session_data]);
                        setcookie('OCSESSID', $session_id, time() + 86400, '/', '', false, true);
                    } catch (Exception $e) {
                        error_log("Erro sync sessão: " . $e->getMessage());
                    }

                    // Registrar login
                    try {
                        $pdo->prepare("UPDATE {$prefix}customer SET ip = ? WHERE customer_id = ?")
                            ->execute([$_SERVER['REMOTE_ADDR'] ?? '', $customer['customer_id']]);
                    } catch (Exception $e) {}

                    // Redirecionar
                    $redirect = $_GET['redirect'] ?? '/mercado/';
                    header('Location: ' . $redirect);
                    exit;
                } else {
                    $error = 'Senha incorreta.';
                }
            } else {
                $error = 'E-mail não encontrado. Cadastre-se primeiro.';
                $tab = 'cadastro';
            }
        }
    }

    // === CADASTRO ===
    if ($_POST['action'] === 'cadastro') {
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname = trim($_POST['lastname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';

        // Validações
        if (empty($firstname) || empty($email) || empty($password)) {
            $error = 'Preencha todos os campos obrigatórios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'E-mail inválido.';
        } elseif (strlen($password) < 4) {
            $error = 'A senha deve ter pelo menos 4 caracteres.';
        } elseif ($password !== $confirm) {
            $error = 'As senhas não coincidem.';
        } else {
            // Verificar se email já existe
            $stmt = $pdo->prepare("SELECT customer_id FROM {$prefix}customer WHERE LOWER(email) = LOWER(?)");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $error = 'Este e-mail já está cadastrado. Faça login.';
                $tab = 'login';
            } else {
                // Criar cliente no OpenCart
                try {
                    $salt = token(9);
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare("
                        INSERT INTO {$prefix}customer
                        (customer_group_id, store_id, language_id, firstname, lastname, email, telephone,
                         password, salt, custom_field, ip, status, safe, token, code, date_added)
                        VALUES
                        (1, 0, 1, ?, ?, ?, ?, ?, ?, '[]', ?, 1, 0, '', '', NOW())
                    ");
                    $stmt->execute([
                        $firstname,
                        $lastname ?: $firstname,
                        $email,
                        $telephone,
                        $password_hash,
                        $salt,
                        $_SERVER['REMOTE_ADDR'] ?? ''
                    ]);

                    $customer_id = $pdo->lastInsertId();

                    // Login automático
                    $_SESSION['customer_id'] = $customer_id;
                    $_SESSION['customer_name'] = $firstname;
                    $_SESSION['customer_email'] = $email;
                    $_SESSION['customer_telephone'] = $telephone;
                    $_SESSION['mercado_customer_id'] = $customer_id;
                    $_SESSION['mercado_customer_name'] = $firstname;
                    $_SESSION['mercado_logged_at'] = time();

                    // Sincronizar sessão
                    $session_id = session_id();
                    $session_data = serialize([
                        'customer_id' => $customer_id,
                        'customer' => [
                            'customer_id' => $customer_id,
                            'customer_group_id' => 1,
                            'firstname' => $firstname,
                            'lastname' => $lastname ?: $firstname,
                            'email' => $email,
                            'telephone' => $telephone
                        ]
                    ]);

                    $pdo->prepare("REPLACE INTO {$prefix}session (session_id, data, expire) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))")
                        ->execute([$session_id, $session_data]);
                    setcookie('OCSESSID', $session_id, time() + 86400, '/', '', false, true);

                    // Redirecionar
                    $redirect = $_GET['redirect'] ?? '/mercado/';
                    header('Location: ' . $redirect);
                    exit;

                } catch (Exception $e) {
                    error_log("Erro cadastro: " . $e->getMessage());
                    $error = 'Erro ao criar conta. Tente novamente.';
                }
            }
        }
        $tab = 'cadastro';
    }
}

// Função para gerar token
function token($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

// Saudação
$hora = (int)date('H');
if ($hora >= 5 && $hora < 12) {
    $saudacao = 'Bom dia';
} elseif ($hora >= 12 && $hora < 18) {
    $saudacao = 'Boa tarde';
} else {
    $saudacao = 'Boa noite';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $tab === 'cadastro' ? 'Criar Conta' : 'Entrar' ?> - OneMundo Mercado</title>
    <link rel="icon" href="/image/catalog/cart.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --primary-light: #d1fae5;
            --error: #ef4444;
            --error-light: #fef2f2;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: linear-gradient(135deg, #030712 0%, #0f172a 50%, #1e1b4b 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(at 20% 30%, rgba(16, 185, 129, 0.15) 0px, transparent 50%),
                radial-gradient(at 80% 20%, rgba(59, 130, 246, 0.1) 0px, transparent 50%);
            pointer-events: none;
        }

        /* Header */
        .header {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 16px 24px;
            position: relative;
            z-index: 10;
        }

        .header-inner {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 800;
            color: white;
        }

        .logo-text span { color: var(--primary); }

        .header-link {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .header-link:hover { color: white; }

        /* Main */
        .main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            position: relative;
            z-index: 1;
        }

        /* Card */
        .login-card {
            background: white;
            border-radius: 24px;
            padding: 40px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-greeting {
            font-size: 14px;
            color: var(--gray-500);
            margin-bottom: 8px;
        }

        .login-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .login-subtitle {
            font-size: 15px;
            color: var(--gray-500);
        }

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--gray-100);
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 24px;
        }

        .tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            border-radius: 10px;
            font-weight: 600;
            color: var(--gray-500);
            text-decoration: none;
            transition: all 0.3s;
        }

        .tab.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .tab:hover:not(.active) {
            color: var(--gray-700);
        }

        /* Form */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 16px;
            font-family: inherit;
            transition: all 0.3s;
            background: var(--gray-50);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* Button */
        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        }

        /* Alert */
        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: var(--error-light);
            color: var(--error);
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: var(--primary-light);
            color: var(--primary-dark);
            border: 1px solid #a7f3d0;
        }

        /* Footer link */
        .footer-text {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            color: var(--gray-500);
        }

        .footer-text a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }

        .footer-text a:hover {
            text-decoration: underline;
        }

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            margin: 24px 0;
            color: var(--gray-400);
            font-size: 13px;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--gray-200);
        }

        .divider span {
            padding: 0 16px;
        }

        /* Social login */
        .btn-social {
            width: 100%;
            padding: 14px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            background: white;
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-700);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
            text-decoration: none;
        }

        .btn-social:hover {
            border-color: var(--gray-300);
            background: var(--gray-50);
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-card {
                padding: 24px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-inner">
            <a href="/mercado/" class="logo">
                <div class="logo-icon">🛒</div>
                <div class="logo-text">One<span>Mundo</span></div>
            </a>
            <a href="/mercado/" class="header-link">← Voltar para loja</a>
        </div>
    </header>

    <main class="main">
        <div class="login-card">
            <div class="login-header">
                <p class="login-greeting"><?= $saudacao ?>! 👋</p>
                <h1 class="login-title"><?= $tab === 'cadastro' ? 'Criar sua conta' : 'Bem-vindo de volta' ?></h1>
                <p class="login-subtitle"><?= $tab === 'cadastro' ? 'Preencha seus dados para começar' : 'Entre para continuar suas compras' ?></p>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <a href="?tab=login<?= isset($_GET['redirect']) ? '&redirect=' . urlencode($_GET['redirect']) : '' ?>"
                   class="tab <?= $tab === 'login' ? 'active' : '' ?>">Entrar</a>
                <a href="?tab=cadastro<?= isset($_GET['redirect']) ? '&redirect=' . urlencode($_GET['redirect']) : '' ?>"
                   class="tab <?= $tab === 'cadastro' ? 'active' : '' ?>">Criar Conta</a>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-error">
                <span>⚠️</span>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success">
                <span>✅</span>
                <?= htmlspecialchars($success) ?>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'login'): ?>
            <!-- FORM LOGIN -->
            <form method="POST" action="">
                <input type="hidden" name="action" value="login">

                <div class="form-group">
                    <label class="form-label">E-mail</label>
                    <input type="email" name="email" class="form-input" placeholder="seu@email.com" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Senha</label>
                    <input type="password" name="password" class="form-input" placeholder="••••••••" required>
                </div>

                <button type="submit" class="btn">Entrar</button>

                <p class="footer-text">
                    Esqueceu a senha? <a href="/index.php?route=account/forgotten">Recuperar</a>
                </p>
            </form>

            <?php else: ?>
            <!-- FORM CADASTRO -->
            <form method="POST" action="">
                <input type="hidden" name="action" value="cadastro">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nome *</label>
                        <input type="text" name="firstname" class="form-input" placeholder="Seu nome" required
                               value="<?= htmlspecialchars($_POST['firstname'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Sobrenome</label>
                        <input type="text" name="lastname" class="form-input" placeholder="Sobrenome"
                               value="<?= htmlspecialchars($_POST['lastname'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">E-mail *</label>
                    <input type="email" name="email" class="form-input" placeholder="seu@email.com" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Telefone / WhatsApp</label>
                    <input type="tel" name="telephone" class="form-input" placeholder="(00) 00000-0000"
                           value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Senha *</label>
                        <input type="password" name="password" class="form-input" placeholder="Mínimo 4 caracteres" required minlength="4">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Confirmar *</label>
                        <input type="password" name="confirm" class="form-input" placeholder="Repita a senha" required>
                    </div>
                </div>

                <button type="submit" class="btn">Criar Conta</button>

                <p class="footer-text" style="font-size: 12px; margin-top: 16px;">
                    Ao criar sua conta, você concorda com nossos<br>
                    <a href="/termos">Termos de Uso</a> e <a href="/privacidade">Política de Privacidade</a>
                </p>
            </form>
            <?php endif; ?>

            <div class="divider"><span>ou continue com</span></div>

            <a href="/" class="btn-social">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M3 9L12 2L21 9V20C21 21.1 20.1 22 19 22H5C3.9 22 3 21.1 3 20V9Z" stroke="currentColor" stroke-width="2"/>
                    <path d="M9 22V12H15V22" stroke="currentColor" stroke-width="2"/>
                </svg>
                Ir para loja principal (OpenCart)
            </a>
        </div>
    </main>
</body>
</html>
