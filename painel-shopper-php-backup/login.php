<?php
/**
 * Login Shopper - OneMundo
 * Interface moderna mobile-first
 */

session_start();
require_once dirname(__DIR__, 2) . '/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();
    $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if (strlen($phone) >= 10 && $password) {
        $stmt = $db->prepare("
            SELECT * FROM om_market_shoppers
            WHERE (phone = ? OR telefone = ? OR REPLACE(REPLACE(phone, '-', ''), ' ', '') LIKE ?)
            AND status = 1
        ");
        $stmt->execute([$phone, $phone, "%$phone%"]);
        $shopper = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($shopper) {
            $senhaValida = false;
            if (!empty($shopper['password_hash']) && password_verify($password, $shopper['password_hash'])) {
                $senhaValida = true;
            } elseif (!empty($shopper['password']) && (password_verify($password, $shopper['password']) || $shopper['password'] === $password)) {
                $senhaValida = true;
            }

            if ($senhaValida) {
                $_SESSION['shopper_id'] = $shopper['shopper_id'];
                $_SESSION['shopper_name'] = $shopper['name'] ?? $shopper['nome'];

                // Atualizar último login
                $db->prepare("UPDATE om_market_shoppers SET last_login = NOW() WHERE shopper_id = ?")->execute([$shopper['shopper_id']]);

                header('Location: /painel/shopper/');
                exit;
            }
        }
        $error = 'Telefone ou senha incorretos';
    } else {
        $error = 'Preencha todos os campos';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login Shopper - OneMundo</title>
    <meta name="theme-color" content="#4a6cf7">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #4a6cf7;
            --primary-dark: #3d5bd9;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }

        .login-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 24px;
            max-width: 400px;
            margin: 0 auto;
            width: 100%;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 48px;
            color: white;
        }

        .logo-icon {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            margin: 0 auto 16px;
            backdrop-filter: blur(10px);
        }

        .logo-section h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .logo-section p {
            opacity: 0.8;
            font-size: 14px;
        }

        .login-card {
            background: white;
            border-radius: 24px;
            padding: 32px 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
            color: #374151;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 18px;
        }

        .input-wrapper input {
            width: 100%;
            padding: 16px 16px 16px 48px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.2s;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(74, 108, 247, 0.1);
        }

        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-login {
            width: 100%;
            padding: 16px;
            background: var(--primary);
            color: white;
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
        }

        .btn-login:active {
            transform: scale(0.98);
            background: var(--primary-dark);
        }

        .register-link {
            text-align: center;
            margin-top: 24px;
            color: white;
            font-size: 14px;
        }

        .register-link a {
            color: white;
            font-weight: 600;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-section">
            <div class="logo-icon">🛒</div>
            <h1>OneMundo Shopper</h1>
            <p>Faça entregas e ganhe dinheiro</p>
        </div>

        <div class="login-card">
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Telefone</label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone"></i>
                        <input type="tel" name="phone" placeholder="(00) 00000-0000" required autocomplete="tel">
                    </div>
                </div>

                <div class="form-group">
                    <label>Senha</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" placeholder="Sua senha" required>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i>
                    Entrar
                </button>
            </form>
        </div>

        <p class="register-link">
            Ainda não é shopper? <a href="/painel/shopper/cadastro.php">Cadastre-se</a>
        </p>
    </div>

    <script>
        // Máscara de telefone
        document.querySelector('input[name="phone"]').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) value = value.slice(0, 11);

            if (value.length > 6) {
                value = '(' + value.slice(0,2) + ') ' + value.slice(2,7) + '-' + value.slice(7);
            } else if (value.length > 2) {
                value = '(' + value.slice(0,2) + ') ' + value.slice(2);
            } else if (value.length > 0) {
                value = '(' + value;
            }

            e.target.value = value;
        });
    </script>
</body>
</html>
