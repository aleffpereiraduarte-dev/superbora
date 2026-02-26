<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Login (with 2FA / TOTP support)
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_start();

// Se já estiver logado, redirecionar
if (isset($_SESSION['mercado_id'])) {
    header('Location: index.php');
    exit;
}

// ── Rate Limiting (IP-based, file-backed) ──────────────────────────────────

function checkRateLimit($ip, $maxAttempts = 5, $lockoutMinutes = 15) {
    $file = sys_get_temp_dir() . '/login_attempts_' . md5($ip) . '.json';
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : ['attempts' => 0, 'locked_until' => 0];

    if (!is_array($data)) {
        $data = ['attempts' => 0, 'locked_until' => 0];
    }

    // Check if locked
    if ($data['locked_until'] > time()) {
        $remaining = ceil(($data['locked_until'] - time()) / 60);
        return ['blocked' => true, 'minutes' => $remaining];
    }

    // Reset if lock expired
    if ($data['locked_until'] > 0 && $data['locked_until'] <= time()) {
        $data = ['attempts' => 0, 'locked_until' => 0];
        file_put_contents($file, json_encode($data));
    }

    return ['blocked' => false, 'attempts' => $data['attempts']];
}

function recordFailedAttempt($ip, $maxAttempts = 5, $lockoutMinutes = 15) {
    $file = sys_get_temp_dir() . '/login_attempts_' . md5($ip) . '.json';
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : ['attempts' => 0, 'locked_until' => 0];

    if (!is_array($data)) {
        $data = ['attempts' => 0, 'locked_until' => 0];
    }

    $data['attempts']++;
    if ($data['attempts'] >= $maxAttempts) {
        $data['locked_until'] = time() + ($lockoutMinutes * 60);
    }
    file_put_contents($file, json_encode($data));
}

function clearRateLimit($ip) {
    $file = sys_get_temp_dir() . '/login_attempts_' . md5($ip) . '.json';
    if (file_exists($file)) {
        unlink($file);
    }
}

// ── Login Handler ───────────────────────────────────────────────────────────

$error = '';
$show2fa = false;

// Check if we are in 2FA pending state
if (isset($_SESSION['2fa_pending_id'])) {
    $show2fa = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // ── Handle 2FA code verification ──
    $totp_code = $_POST['totp_code'] ?? '';
    if (isset($_SESSION['2fa_pending_id']) && $totp_code !== '') {
        require_once dirname(__DIR__, 2) . '/database.php';
        require_once dirname(__DIR__, 2) . '/includes/classes/SimpleTOTP.php';

        $db = getDB();
        $pending_id = $_SESSION['2fa_pending_id'];

        $stmt = $db->prepare("SELECT partner_id, name, totp_secret, contract_signed_at, first_setup_complete FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$pending_id]);
        $mercado = $stmt->fetch();

        if ($mercado && SimpleTOTP::verify($mercado['totp_secret'], $totp_code)) {
            // 2FA passed — create full session
            unset($_SESSION['2fa_pending_id']);
            clearRateLimit($clientIp);

            $_SESSION['mercado_id'] = $mercado['partner_id'];
            $_SESSION['mercado_nome'] = $mercado['name'];

            // Atualizar ultimo login
            $stmt = $db->prepare("UPDATE om_market_partners SET last_login = NOW() WHERE partner_id = ?");
            $stmt->execute([$mercado['partner_id']]);

            // Verificar fluxo pos-aprovacao
            if (empty($mercado['contract_signed_at'])) {
                header('Location: contrato.php');
                exit;
            }
            if (empty($mercado['first_setup_complete']) || $mercado['first_setup_complete'] == 0) {
                header('Location: setup.php');
                exit;
            }
            header('Location: index.php');
            exit;
        } else {
            recordFailedAttempt($clientIp);
            $error = 'Codigo de verificacao invalido';
            $show2fa = true;
        }
    }
    // ── Handle cancel 2FA ──
    elseif (isset($_POST['cancel_2fa'])) {
        unset($_SESSION['2fa_pending_id']);
        $show2fa = false;
    }
    // ── Handle normal login ──
    elseif (!isset($_SESSION['2fa_pending_id'])) {
        // Check rate limit before processing
        $rateCheck = checkRateLimit($clientIp);
        if ($rateCheck['blocked']) {
            $error = 'Muitas tentativas. Tente novamente em ' . $rateCheck['minutes'] . ' minuto' . ($rateCheck['minutes'] > 1 ? 's' : '') . '.';
        } else {
            require_once dirname(__DIR__, 2) . '/database.php';

            $email = trim($_POST['email'] ?? '');
            $senha = $_POST['senha'] ?? '';

            if ($email && $senha) {
                $db = getDB();

                $stmt = $db->prepare("SELECT * FROM om_market_partners WHERE (email = ? OR login_email = ?)");
                $stmt->execute([$email, $email]);
                $mercado = $stmt->fetch();

                if ($mercado && $mercado['status'] == 0) {
                    $error = 'Seu cadastro esta em analise. Aguarde a aprovacao da nossa equipe.';
                } elseif ($mercado && $mercado['status'] == 1 && password_verify($senha, $mercado['login_password'] ?? '')) {
                    // Password OK — check if 2FA is enabled
                    if (!empty($mercado['totp_enabled']) && !empty($mercado['totp_secret'])) {
                        // Don't create session yet, ask for 2FA code
                        $_SESSION['2fa_pending_id'] = $mercado['partner_id'];
                        $show2fa = true;
                    } else {
                        // No 2FA — normal login flow
                        clearRateLimit($clientIp);

                        $_SESSION['mercado_id'] = $mercado['partner_id'];
                        $_SESSION['mercado_nome'] = $mercado['name'];

                        // Atualizar ultimo login
                        $stmt = $db->prepare("UPDATE om_market_partners SET last_login = NOW() WHERE partner_id = ?");
                        $stmt->execute([$mercado['partner_id']]);

                        // Verificar fluxo pos-aprovacao
                        if (empty($mercado['contract_signed_at'])) {
                            header('Location: contrato.php');
                            exit;
                        }
                        if (empty($mercado['first_setup_complete']) || $mercado['first_setup_complete'] == 0) {
                            header('Location: setup.php');
                            exit;
                        }
                        header('Location: index.php');
                        exit;
                    }
                } else {
                    // Failed login — record attempt
                    recordFailedAttempt($clientIp);
                    $error = 'Email ou senha invalidos';
                }
            } else {
                $error = 'Preencha todos os campos';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Painel do Mercado</title>
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
            background: linear-gradient(135deg, var(--om-primary-50) 0%, var(--om-gray-100) 100%);
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            padding: var(--om-space-4);
        }

        .login-card {
            background: var(--om-white);
            border-radius: var(--om-radius-2xl);
            box-shadow: var(--om-shadow-xl);
            padding: var(--om-space-8);
        }

        .login-logo {
            text-align: center;
            margin-bottom: var(--om-space-6);
        }

        .login-logo img {
            height: 60px;
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

        .forgot-link {
            display: block;
            text-align: center;
            margin-top: var(--om-space-3);
            color: var(--om-text-secondary);
            font-size: var(--om-font-sm);
            text-decoration: none;
            transition: color 0.2s;
        }

        .forgot-link:hover {
            color: var(--om-primary);
        }

        .reset-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .reset-box {
            background: var(--om-white);
            border-radius: var(--om-radius-xl);
            padding: var(--om-space-8);
            max-width: 380px;
            width: 90%;
            text-align: center;
            box-shadow: var(--om-shadow-xl);
        }

        .reset-box h3 {
            font-size: var(--om-font-xl);
            font-weight: var(--om-font-bold);
            color: var(--om-text-primary);
            margin-bottom: var(--om-space-3);
        }

        .reset-box p {
            color: var(--om-text-secondary);
            margin-bottom: var(--om-space-5);
            line-height: 1.5;
        }

        .whatsapp-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #25D366;
            color: white;
            padding: 12px 24px;
            border-radius: var(--om-radius-lg);
            text-decoration: none;
            font-weight: 600;
            font-size: var(--om-font-sm);
            transition: background 0.2s;
            margin-bottom: var(--om-space-4);
        }

        .whatsapp-btn:hover {
            background: #1ebe57;
        }

        .reset-close-btn {
            display: block;
            margin: 0 auto;
            background: none;
            border: 1px solid var(--om-gray-300);
            padding: 8px 24px;
            border-radius: var(--om-radius-lg);
            cursor: pointer;
            color: var(--om-text-secondary);
            font-size: var(--om-font-sm);
            transition: all 0.2s;
        }

        .reset-close-btn:hover {
            background: var(--om-gray-50);
            border-color: var(--om-gray-400);
        }

        .totp-input {
            text-align: center;
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 0.5em;
            padding: 12px;
        }

        .totp-icon {
            display: flex;
            justify-content: center;
            margin-bottom: var(--om-space-4);
        }

        .totp-icon svg {
            width: 48px;
            height: 48px;
            color: var(--om-primary);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <img src="/assets/img/logo-onemundo.png" alt="OneMundo" onerror="this.outerHTML='<span style=\'font-size:2rem;font-weight:bold;color:var(--om-primary)\'>OneMundo</span>'">
            </div>

            <?php if ($show2fa): ?>
            <!-- ══════ 2FA Verification Form ══════ -->
            <div class="totp-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>

            <h1 class="login-title">Verificacao em 2 Etapas</h1>
            <p class="login-subtitle">Digite o codigo de 6 digitos do seu aplicativo autenticador</p>

            <?php if ($error): ?>
            <div class="om-alert om-alert-error om-mb-4">
                <div class="om-alert-content">
                    <div class="om-alert-message"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <div class="om-form-group">
                    <input type="text" id="totp_code" name="totp_code" class="om-input totp-input"
                           placeholder="000000" required autofocus
                           maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                           autocomplete="one-time-code">
                </div>

                <button type="submit" class="om-btn om-btn-primary om-btn-block om-btn-lg">
                    Verificar
                </button>
            </form>

            <form method="POST" style="margin-top: var(--om-space-3);">
                <input type="hidden" name="cancel_2fa" value="1">
                <button type="submit" class="om-btn om-btn-outline om-btn-block">
                    Voltar ao login
                </button>
            </form>

            <?php else: ?>
            <!-- ══════ Normal Login Form ══════ -->
            <h1 class="login-title">Painel do Mercado</h1>
            <p class="login-subtitle">Entre com suas credenciais para acessar</p>

            <?php if (isset($_GET['registered']) && $_GET['registered'] == '1'): ?>
            <div class="om-alert om-alert-success om-mb-4">
                <div class="om-alert-content">
                    <div class="om-alert-message">Cadastro realizado com sucesso! Sua conta sera analisada pela nossa equipe. Voce recebera uma notificacao quando for aprovado.</div>
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

            <form method="POST" class="login-form">
                <div class="om-form-group">
                    <label class="om-label" for="email">Email</label>
                    <input type="email" id="email" name="email" class="om-input" placeholder="seu@email.com" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="om-form-group">
                    <label class="om-label" for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" class="om-input" placeholder="Sua senha" required>
                </div>

                <button type="submit" class="om-btn om-btn-primary om-btn-block om-btn-lg">
                    Entrar
                </button>
            </form>

            <a href="#" onclick="document.getElementById('resetModal').style.display='flex'; return false;" class="forgot-link">Esqueceu a senha?</a>

            <div class="login-footer">
                <div style="margin-bottom: var(--om-space-3);">
                    <a href="cadastro.php" style="color: var(--om-primary); font-weight: 500;">Cadastre-se como parceiro</a>
                </div>
                Problemas para acessar? <a href="mailto:suporte@onemundo.com.br">Fale com o suporte</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal: Redefinir Senha -->
    <div id="resetModal" class="reset-modal" style="display:none" onclick="if(event.target===this)this.style.display='none'">
        <div class="reset-box">
            <h3>Redefinir Senha</h3>
            <p>Entre em contato com nosso suporte para redefinir sua senha:</p>
            <a href="https://wa.me/5511999999999?text=Preciso%20redefinir%20minha%20senha%20do%20painel%20do%20mercado" class="whatsapp-btn" target="_blank">
                Falar no WhatsApp
            </a>
            <br>
            <button class="reset-close-btn" onclick="document.getElementById('resetModal').style.display='none'">Fechar</button>
        </div>
    </div>

    <script>
    // Auto-focus and auto-submit when 6 digits entered
    const totpInput = document.getElementById('totp_code');
    if (totpInput) {
        totpInput.addEventListener('input', function(e) {
            // Only allow digits
            this.value = this.value.replace(/\D/g, '').substring(0, 6);
            // Auto-submit when 6 digits
            if (this.value.length === 6) {
                this.closest('form').submit();
            }
        });
    }
    </script>
</body>
</html>
