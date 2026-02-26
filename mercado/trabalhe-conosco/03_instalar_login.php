<?php
/**
 * ╔═══════════════════════════════════════════════════════════════════════════════╗
 * ║  03 - INSTALAR LOGIN                                                         ║
 * ║  OneMundo Shopper App                                                         ║
 * ╚═══════════════════════════════════════════════════════════════════════════════╝
 */

$baseDir = __DIR__;

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>03 - Login</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;padding:20px}
.container{max-width:900px;margin:0 auto}
h1{color:#10b981;margin-bottom:20px}
.card{background:#1e293b;border-radius:12px;padding:20px;margin-bottom:16px}
.success{color:#10b981}
.btn{display:inline-block;padding:12px 24px;background:#10b981;color:#fff;text-decoration:none;border-radius:8px;margin:5px}
</style></head><body><div class='container'>";

echo "<h1>🔐 03 - Instalar Login</h1>";

// ═══════════════════════════════════════════════════════════════════════════════
// LOGIN.PHP
// ═══════════════════════════════════════════════════════════════════════════════

$loginContent = '<?php
/**
 * 🔐 LOGIN - OneMundo Shopper
 */
session_start();

if (isset($_SESSION["worker_id"])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    require_once __DIR__ . "/includes/config.php";
    
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE email = ? OR phone = ?");
    $stmt->execute([$email, preg_replace("/\D/", "", $email)]);
    $worker = $stmt->fetch();
    
    if ($worker && password_verify($password, $worker["password_hash"])) {
        if (in_array($worker["status"], ["pending", "analyzing"])) {
            $error = "Seu cadastro está em análise.";
        } elseif ($worker["status"] === "rejected") {
            $error = "Cadastro não aprovado.";
        } elseif (in_array($worker["status"], ["inactive", "blocked"])) {
            $error = "Conta suspensa.";
        } else {
            $_SESSION["worker_id"] = $worker["worker_id"];
            $_SESSION["worker_name"] = $worker["name"];
            $_SESSION["worker_type"] = $worker["worker_type"];
            
            $pdo->prepare("UPDATE om_market_workers SET last_login_at = NOW() WHERE worker_id = ?")
                ->execute([$worker["worker_id"]]);
            
            header("Location: dashboard.php");
            exit;
        }
    } else {
        $error = "E-mail ou senha incorretos.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title>Entrar - OneMundo Shopper</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: #00C853;
            --brand-dark: #00A844;
            --brand-light: #E8F5E9;
            --dark: #1A1A2E;
            --gray: #6B7280;
            --gray-light: #F3F4F6;
            --white: #FFFFFF;
            --red: #EF4444;
            --safe-top: env(safe-area-inset-top);
            --safe-bottom: env(safe-area-inset-bottom);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Plus Jakarta Sans", -apple-system, sans-serif;
            background: var(--dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .hero {
            background: linear-gradient(135deg, #00C853 0%, #00E676 50%, #69F0AE 100%);
            padding: calc(60px + var(--safe-top)) 24px 80px;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: "";
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.2); opacity: 0.8; }
        }
        .hero-content { position: relative; z-index: 1; text-align: center; }
        .logo { display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 16px; }
        .logo-icon {
            width: 56px; height: 56px;
            background: var(--white);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        }
        .logo-icon svg { width: 32px; height: 32px; color: var(--brand); }
        .logo-text { font-size: 32px; font-weight: 800; color: var(--white); text-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .hero-subtitle { color: rgba(255,255,255,0.9); font-size: 16px; font-weight: 500; }
        .login-container {
            flex: 1;
            background: var(--white);
            margin-top: -40px;
            border-radius: 32px 32px 0 0;
            padding: 40px 24px calc(40px + var(--safe-bottom));
            position: relative;
            z-index: 10;
        }
        .login-title { font-size: 24px; font-weight: 800; color: var(--dark); margin-bottom: 8px; text-align: center; }
        .login-subtitle { font-size: 15px; color: var(--gray); text-align: center; margin-bottom: 32px; }
        .alert {
            display: flex; align-items: center; gap: 12px;
            padding: 16px; background: #FEF2F2; border-radius: 16px; margin-bottom: 24px;
            animation: shake 0.5s ease-in-out;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }
        .alert svg { width: 24px; height: 24px; color: var(--red); flex-shrink: 0; }
        .alert p { font-size: 14px; color: var(--red); font-weight: 500; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 14px; font-weight: 600; color: var(--dark); margin-bottom: 8px; }
        .input-wrapper { position: relative; }
        .input-wrapper svg {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            width: 20px; height: 20px; color: var(--gray); transition: color 0.2s;
        }
        .form-input {
            width: 100%; padding: 18px 18px 18px 52px;
            border: 2px solid var(--gray-light); border-radius: 16px;
            font-family: inherit; font-size: 16px; color: var(--dark);
            transition: all 0.2s; background: var(--white);
        }
        .form-input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 4px var(--brand-light); }
        .form-input::placeholder { color: #9CA3AF; }
        .toggle-password {
            position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; padding: 4px;
        }
        .toggle-password svg { position: static; transform: none; }
        .btn-primary {
            width: 100%; padding: 18px;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: var(--white); border: none; border-radius: 16px;
            font-family: inherit; font-size: 16px; font-weight: 700;
            cursor: pointer; transition: all 0.3s;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            margin-top: 32px;
            box-shadow: 0 8px 24px rgba(0, 200, 83, 0.3);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(0, 200, 83, 0.4); }
        .btn-primary svg { width: 20px; height: 20px; }
        .links { margin-top: 24px; text-align: center; }
        .link { color: var(--brand); text-decoration: none; font-weight: 600; font-size: 15px; }
        .links p { margin-top: 16px; color: var(--gray); font-size: 14px; }
        .links p a { color: var(--brand); text-decoration: none; font-weight: 600; }
        .features { display: flex; justify-content: center; gap: 32px; margin-top: 40px; padding-top: 32px; border-top: 1px solid var(--gray-light); }
        .feature { text-align: center; }
        .feature-icon {
            width: 48px; height: 48px; background: var(--brand-light); border-radius: 14px;
            display: flex; align-items: center; justify-content: center; margin: 0 auto 8px;
        }
        .feature-icon svg { width: 24px; height: 24px; color: var(--brand); }
        .feature-text { font-size: 12px; color: var(--gray); font-weight: 500; }
    </style>
</head>
<body>
    <div class="hero">
        <div class="hero-content">
            <div class="logo">
                <div class="logo-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <span class="logo-text">OneMundo</span>
            </div>
            <p class="hero-subtitle">Área do Shopper</p>
        </div>
    </div>
    
    <div class="login-container">
        <h1 class="login-title">Bem-vindo de volta!</h1>
        <p class="login-subtitle">Entre para começar a ganhar</p>
        
        <?php if ($error): ?>
        <div class="alert">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p><?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">E-mail ou Telefone</label>
                <div class="input-wrapper">
                    <input type="text" name="email" class="form-input" placeholder="seu@email.com" required>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Senha</label>
                <div class="input-wrapper">
                    <input type="password" name="password" id="password" class="form-input" placeholder="••••••••" required>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    <button type="button" class="toggle-password" onclick="togglePassword()">
                        <svg id="eye-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <button type="submit" class="btn-primary">
                Entrar
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                </svg>
            </button>
        </form>
        
        <div class="links">
            <a href="recuperar-senha.php" class="link">Esqueci minha senha</a>
            <p>Não tem conta? <a href="cadastro.php">Cadastre-se</a></p>
        </div>
        
        <div class="features">
            <div class="feature">
                <div class="feature-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <span class="feature-text">Ganhos rápidos</span>
            </div>
            <div class="feature">
                <div class="feature-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <span class="feature-text">Horário flexível</span>
            </div>
            <div class="feature">
                <div class="feature-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <span class="feature-text">100% seguro</span>
            </div>
        </div>
    </div>
    
    <script>
    function togglePassword() {
        const input = document.getElementById("password");
        input.type = input.type === "password" ? "text" : "password";
    }
    </script>
</body>
</html>';

$loginPath = $baseDir . '/login.php';
if (file_put_contents($loginPath, $loginContent)) {
    echo "<div class='card'><p class='success'>✅ login.php criado com sucesso!</p></div>";
} else {
    echo "<div class='card'><p style='color:#ef4444'>❌ Erro ao criar login.php</p></div>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// LOGOUT.PHP
// ═══════════════════════════════════════════════════════════════════════════════

$logoutContent = '<?php
session_start();
session_destroy();
header("Location: login.php");
exit;';

file_put_contents($baseDir . '/logout.php', $logoutContent);
echo "<div class='card'><p class='success'>✅ logout.php criado</p></div>";

echo "<div style='text-align:center;margin-top:20px'>";
echo "<a href='02_instalar_arquivos.php' class='btn' style='background:#64748b'>← Anterior</a>";
echo "<a href='04_instalar_dashboard.php' class='btn'>Próximo: 04 - Dashboard →</a>";
echo "</div>";

echo "</div></body></html>";
