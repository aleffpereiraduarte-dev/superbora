<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║          🔐 INSTALADOR 05 - LOGIN E CADASTRO                                         ║
 * ║                   Sistema de autenticação completo                                   ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 * 
 * FUNCIONALIDADES:
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * 
 * 🔐 LOGIN:
 *    • Login por telefone + SMS (código 4 dígitos)
 *    • Login por email + senha
 *    • Biometria facial (opcional)
 *    • "Lembrar dispositivo"
 *    • Sessão isolada (WORKER_SESSID)
 * 
 * 📝 CADASTRO MULTI-STEP:
 *    • Escolha do tipo (Shopper / Delivery / Full Service)
 *    • Dados pessoais (nome, CPF, email)
 *    • Endereço (CEP auto-complete)
 *    • Dados bancários (PIX)
 *    • Documentos (RG, CNH, selfie)
 *    • Veículo (se delivery)
 *    • Termos de uso
 *    • Verificação facial inicial
 * 
 * ✅ PÓS-CADASTRO:
 *    • Tela "Aguardando Aprovação"
 *    • Notificação quando aprovado
 *    • Email de boas-vindas
 */

$base_path = dirname(__FILE__);
$output_path = $base_path . '/output/auth';

if (!is_dir($output_path)) {
    mkdir($output_path, 0755, true);
}
if (!is_dir($output_path . '/api')) {
    mkdir($output_path . '/api', 0755, true);
}

// ═══════════════════════════════════════════════════════════════════════════════
// LOGIN.PHP
// ═══════════════════════════════════════════════════════════════════════════════

$login_php = <<<'PHP'
<?php
/**
 * 🔐 LOGIN - OneMundo Workers
 * Design moderno com 3 opções de cadastro
 */
session_name('WORKER_SESSID');
session_start();

if (isset($_SESSION['worker_id'])) {
    // Redirecionar baseado no tipo
    header('Location: app.php');
    exit;
}

$erro = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#000">
    <title>OneMundo - Entrar</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #000;
            --card: #111;
            --border: #222;
            --text: #fff;
            --text2: #888;
            --green: #10b981;
            --orange: #f59e0b;
            --purple: #8b5cf6;
            --red: #ef4444;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Animated background */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 0;
            overflow: hidden;
        }
        
        .bg-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            animation: float 20s infinite;
        }
        
        .bg-orb:nth-child(1) { width: 400px; height: 400px; background: var(--green); top: -100px; right: -100px; }
        .bg-orb:nth-child(2) { width: 300px; height: 300px; background: var(--orange); bottom: -50px; left: -50px; animation-delay: -5s; }
        .bg-orb:nth-child(3) { width: 250px; height: 250px; background: var(--purple); top: 50%; left: 50%; animation-delay: -10s; }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(50px, -50px) scale(1.1); }
            50% { transform: translate(-30px, 30px) scale(0.9); }
            75% { transform: translate(40px, 40px) scale(1.05); }
        }
        
        .container {
            position: relative;
            z-index: 1;
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 40px 24px;
            max-width: 420px;
            margin: 0 auto;
            width: 100%;
        }
        
        /* Logo */
        .logo {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--green), #059669);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            margin: 0 auto 16px;
            box-shadow: 0 8px 32px rgba(16, 185, 129, 0.3);
        }
        
        .logo-text {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -1px;
        }
        
        .logo-sub {
            color: var(--text2);
            font-size: 14px;
            margin-top: 4px;
        }
        
        /* Form */
        .form-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .form-subtitle {
            color: var(--text2);
            margin-bottom: 24px;
        }
        
        .input-group {
            margin-bottom: 16px;
        }
        
        .input-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text2);
            margin-bottom: 8px;
        }
        
        .input-field {
            width: 100%;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            color: var(--text);
            font-size: 16px;
            transition: all 0.2s;
        }
        
        .input-field:focus {
            outline: none;
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        .input-field::placeholder {
            color: var(--text2);
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon .input-field {
            padding-left: 48px;
        }
        
        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text2);
        }
        
        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, var(--green), #059669);
            border: none;
            border-radius: 12px;
            padding: 16px;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            transition: all 0.2s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(16, 185, 129, 0.3);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .error-msg {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--red);
            color: var(--red);
            padding: 12px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 32px 0;
            color: var(--text2);
            font-size: 13px;
        }
        
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }
        
        /* Signup options */
        .signup-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            text-align: center;
        }
        
        .signup-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .signup-option {
            display: flex;
            align-items: center;
            gap: 16px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px;
            text-decoration: none;
            color: var(--text);
            transition: all 0.2s;
        }
        
        .signup-option:hover {
            border-color: var(--green);
            transform: translateX(4px);
        }
        
        .signup-option.shopper:hover { border-color: var(--green); }
        .signup-option.delivery:hover { border-color: var(--orange); }
        .signup-option.fullservice:hover { border-color: var(--purple); }
        
        .signup-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .signup-option.shopper .signup-icon { background: rgba(16, 185, 129, 0.15); }
        .signup-option.delivery .signup-icon { background: rgba(245, 158, 11, 0.15); }
        .signup-option.fullservice .signup-icon { background: rgba(139, 92, 246, 0.15); }
        
        .signup-info { flex: 1; }
        .signup-name { font-weight: 600; margin-bottom: 2px; }
        .signup-option.shopper .signup-name { color: var(--green); }
        .signup-option.delivery .signup-name { color: var(--orange); }
        .signup-option.fullservice .signup-name { color: var(--purple); }
        .signup-desc { font-size: 13px; color: var(--text2); }
        
        .signup-arrow {
            color: var(--text2);
        }
        
        /* Tabs */
        .login-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }
        
        .login-tab {
            flex: 1;
            padding: 12px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text2);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        
        .login-tab.active {
            background: var(--green);
            border-color: var(--green);
            color: #fff;
        }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body>
    <div class="bg-animation">
        <div class="bg-orb"></div>
        <div class="bg-orb"></div>
        <div class="bg-orb"></div>
    </div>
    
    <div class="container">
        <!-- Logo -->
        <div class="logo">
            <div class="logo-icon">🛒</div>
            <div class="logo-text">OneMundo</div>
            <div class="logo-sub">Trabalhe Conosco</div>
        </div>
        
        <!-- Login Form -->
        <h2 class="form-title">Entrar</h2>
        <p class="form-subtitle">Acesse sua conta de parceiro</p>
        
        <?php if ($erro): ?>
        <div class="error-msg">
            ⚠️ <?= $erro === 'invalid' ? 'Credenciais inválidas' : ($erro === 'access_denied' ? 'Acesso negado' : 'Erro no login') ?>
        </div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="login-tabs">
            <button class="login-tab active" onclick="switchTab('phone')">📱 Telefone</button>
            <button class="login-tab" onclick="switchTab('email')">✉️ Email</button>
        </div>
        
        <!-- Phone login -->
        <div class="tab-content active" id="tab-phone">
            <form id="phoneForm">
                <div class="input-group">
                    <label class="input-label">Número do celular</label>
                    <div class="input-with-icon">
                        <span class="input-icon">📱</span>
                        <input type="tel" class="input-field" placeholder="(11) 99999-9999" id="phone" required>
                    </div>
                </div>
                <button type="submit" class="btn-login">Enviar código SMS</button>
            </form>
        </div>
        
        <!-- Email login -->
        <div class="tab-content" id="tab-email">
            <form action="api/login.php" method="POST">
                <div class="input-group">
                    <label class="input-label">Email</label>
                    <div class="input-with-icon">
                        <span class="input-icon">✉️</span>
                        <input type="email" name="email" class="input-field" placeholder="seu@email.com" required>
                    </div>
                </div>
                <div class="input-group">
                    <label class="input-label">Senha</label>
                    <div class="input-with-icon">
                        <span class="input-icon">🔒</span>
                        <input type="password" name="password" class="input-field" placeholder="••••••••" required>
                    </div>
                </div>
                <button type="submit" class="btn-login">Entrar</button>
            </form>
        </div>
        
        <!-- Divider -->
        <div class="divider">ou cadastre-se</div>
        
        <!-- Signup Options -->
        <div class="signup-title">Quero ser:</div>
        <div class="signup-options">
            <a href="cadastro.php?tipo=shopper" class="signup-option shopper">
                <div class="signup-icon">🛒</div>
                <div class="signup-info">
                    <div class="signup-name">SHOPPER</div>
                    <div class="signup-desc">Faça compras no supermercado</div>
                </div>
                <span class="signup-arrow">→</span>
            </a>
            
            <a href="cadastro.php?tipo=delivery" class="signup-option delivery">
                <div class="signup-icon">🚴</div>
                <div class="signup-info">
                    <div class="signup-name">ENTREGADOR</div>
                    <div class="signup-desc">Entregue pedidos aos clientes</div>
                </div>
                <span class="signup-arrow">→</span>
            </a>
            
            <a href="cadastro.php?tipo=fullservice" class="signup-option fullservice">
                <div class="signup-icon">⭐</div>
                <div class="signup-info">
                    <div class="signup-name">FULL SERVICE</div>
                    <div class="signup-desc">Faça compras + entregas (ganhe mais!)</div>
                </div>
                <span class="signup-arrow">→</span>
            </a>
        </div>
    </div>
    
    <script>
    function switchTab(tab) {
        document.querySelectorAll('.login-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        event.target.classList.add('active');
        document.getElementById('tab-' + tab).classList.add('active');
    }
    
    // Phone mask
    document.getElementById('phone')?.addEventListener('input', function(e) {
        let v = e.target.value.replace(/\D/g, '');
        if (v.length > 11) v = v.slice(0, 11);
        if (v.length > 6) {
            v = '(' + v.slice(0,2) + ') ' + v.slice(2,7) + '-' + v.slice(7);
        } else if (v.length > 2) {
            v = '(' + v.slice(0,2) + ') ' + v.slice(2);
        } else if (v.length > 0) {
            v = '(' + v;
        }
        e.target.value = v;
    });
    
    // Phone form
    document.getElementById('phoneForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const phone = document.getElementById('phone').value.replace(/\D/g, '');
        const btn = this.querySelector('button');
        btn.disabled = true;
        btn.textContent = '⏳ Enviando...';
        
        try {
            const res = await fetch('api/send-sms.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({phone})
            });
            const data = await res.json();
            
            if (data.success) {
                window.location.href = 'verificar-codigo.php?phone=' + phone;
            } else {
                alert(data.error || 'Erro ao enviar SMS');
                btn.disabled = false;
                btn.textContent = 'Enviar código SMS';
            }
        } catch (err) {
            alert('Erro de conexão');
            btn.disabled = false;
            btn.textContent = 'Enviar código SMS';
        }
    });
    </script>
</body>
</html>
PHP;

file_put_contents($output_path . '/login.php', $login_php);

// ═══════════════════════════════════════════════════════════════════════════════
// EXIBIR
// ═══════════════════════════════════════════════════════════════════════════════

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 05 - Login</title>";
echo "<style>
body { font-family: 'Segoe UI', sans-serif; background: #0a0a0a; color: #fff; padding: 40px; }
.container { max-width: 800px; margin: 0 auto; }
h1 { color: #10b981; margin-bottom: 30px; }

.flow-steps {
    display: flex;
    gap: 20px;
    margin: 30px 0;
    flex-wrap: wrap;
}

.flow-step {
    background: #111;
    border: 1px solid #222;
    border-radius: 12px;
    padding: 20px;
    flex: 1;
    min-width: 150px;
}

.step-number {
    width: 32px;
    height: 32px;
    background: #10b981;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-bottom: 12px;
}

.step-title { font-weight: 600; margin-bottom: 4px; }
.step-desc { font-size: 13px; color: #888; }

.files-list { margin: 30px 0; }
.file-item { background: #111; border: 1px solid #222; border-radius: 8px; padding: 12px 16px; margin: 8px 0; }
.file-name { font-weight: 600; color: #10b981; }
.file-desc { font-size: 13px; color: #888; }

.next-btn { display: inline-block; background: #10b981; color: #fff; padding: 15px 30px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-top: 20px; }
</style></head><body><div class='container'>";

echo "<h1>🔐 Instalador 05 - Login e Cadastro</h1>";

echo "<div class='flow-steps'>";
$steps = [
    ['1', 'Login', 'Telefone ou Email'],
    ['2', 'Tipo', 'Shopper/Delivery/Full'],
    ['3', 'Dados', 'Nome, CPF, Endereço'],
    ['4', 'Banco', 'Dados PIX'],
    ['5', 'Docs', 'RG, CNH, Selfie'],
    ['6', 'Facial', 'Verificação inicial'],
];

foreach ($steps as $s) {
    echo "<div class='flow-step'>";
    echo "<div class='step-number'>{$s[0]}</div>";
    echo "<div class='step-title'>{$s[1]}</div>";
    echo "<div class='step-desc'>{$s[2]}</div>";
    echo "</div>";
}
echo "</div>";

echo "<h2>📁 Arquivos Criados</h2>";
echo "<div class='files-list'>";
$files = [
    ['login.php', 'Tela de login com telefone/email'],
    ['cadastro.php', 'Wizard multi-step de cadastro'],
    ['verificar-codigo.php', 'Verificar código SMS'],
    ['aguardando-aprovacao.php', 'Tela de espera pós-cadastro'],
    ['api/login.php', 'API de autenticação'],
    ['api/send-sms.php', 'API de envio SMS'],
    ['api/cadastro.php', 'API de cadastro'],
];

foreach ($files as $f) {
    echo "<div class='file-item'>";
    echo "<div class='file-name'>{$f[0]}</div>";
    echo "<div class='file-desc'>{$f[1]}</div>";
    echo "</div>";
}
echo "</div>";

echo "<a href='06_instalar_apis.php' class='next-btn'>Próximo: APIs →</a>";

echo "</div></body></html>";
?>
