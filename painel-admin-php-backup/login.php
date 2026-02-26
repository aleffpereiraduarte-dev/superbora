<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL ADMIN - Login
 * Acesso para funcionários de suporte
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_start();

if (isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once dirname(__DIR__, 2) . '/database.php';

    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if ($email && $senha) {
        $db = getDB();

        // Tentar primeiro na tabela om_admins
        $stmt = $db->prepare("SELECT * FROM om_admins WHERE email = ? AND status = 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($senha, $admin['password'] ?? '')) {
            $_SESSION['admin_id'] = $admin['admin_id'];
            $_SESSION['admin_nome'] = $admin['name'];
            $_SESSION['admin_role'] = $admin['role'];
            $_SESSION['admin_permissions'] = json_decode($admin['permissions'] ?? '[]', true);

            // Atualizar último login
            $stmt = $db->prepare("UPDATE om_admins SET last_login = NOW() WHERE admin_id = ?");
            $stmt->execute([$admin['admin_id']]);

            header('Location: index.php');
            exit;
        } else {
            $error = 'Email ou senha inválidos';
        }
    } else {
        $error = 'Preencha todos os campos';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Admin OneMundo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            padding: var(--om-space-4);
        }

        .login-card {
            background: var(--om-white);
            border-radius: var(--om-radius-2xl);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            padding: var(--om-space-8);
        }

        .login-logo {
            text-align: center;
            margin-bottom: var(--om-space-6);
        }

        .login-logo img {
            height: 60px;
        }

        .login-badge {
            display: inline-flex;
            align-items: center;
            gap: var(--om-space-2);
            background: var(--om-gray-900);
            color: var(--om-white);
            padding: var(--om-space-1) var(--om-space-3);
            border-radius: var(--om-radius-full);
            font-size: var(--om-font-xs);
            font-weight: var(--om-font-semibold);
            margin-top: var(--om-space-2);
        }

        .login-title {
            text-align: center;
            font-size: var(--om-font-2xl);
            font-weight: var(--om-font-bold);
            color: var(--om-text-primary);
            margin-bottom: var(--om-space-2);
        }

        .login-subtitle {
            text-align: center;
            color: var(--om-text-secondary);
            margin-bottom: var(--om-space-6);
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: var(--om-space-4);
        }

        .login-footer {
            text-align: center;
            margin-top: var(--om-space-6);
            font-size: var(--om-font-sm);
            color: var(--om-text-muted);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <img src="/assets/img/logo-onemundo.png" alt="OneMundo" onerror="this.outerHTML='<span style=\'font-size:2rem;font-weight:bold;color:var(--om-primary)\'>OneMundo</span>'">
                <div class="login-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    ADMIN
                </div>
            </div>

            <h1 class="login-title">Painel Administrativo</h1>
            <p class="login-subtitle">Acesso exclusivo para funcionários</p>

            <?php if ($error): ?>
            <div class="om-alert om-alert-error om-mb-4">
                <div class="om-alert-content">
                    <div class="om-alert-message"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <div class="om-form-group">
                    <label class="om-label" for="email">Email corporativo</label>
                    <input type="email" id="email" name="email" class="om-input" placeholder="seu@onemundo.com.br" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="om-form-group">
                    <label class="om-label" for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" class="om-input" placeholder="Sua senha" required>
                </div>

                <button type="submit" class="om-btn om-btn-primary om-btn-block om-btn-lg">
                    Entrar
                </button>
            </form>

            <div class="login-footer">
                Acesso restrito a funcionários autorizados.<br>
                <a href="mailto:rh@onemundo.com.br">Problemas de acesso? Contate o RH</a>
            </div>
        </div>
    </div>
</body>
</html>
