<?php
// Cabeçalhos de segurança
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

session_start();
// Regenera ID da sessão para prevenir session fixation
if (!isset($_SESSION['session_regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['session_regenerated'] = true;
}
if (isset($_SESSION["fin_user_id"])) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline'; img-src 'self' data:;">
    <title>Login - Sistema Financeiro OneMundo</title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body {
            font-family: "Segoe UI", system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            padding: 20px;
        }
        .login-container {
            width: 100%;
            max-width: 420px;
        }
        .login-card {
            background: rgba(30, 41, 59, 0.95);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 36px;
            color: white;
        }
        .logo h1 {
            color: #f1f5f9;
            font-size: 24px;
            font-weight: 700;
        }
        .logo p {
            color: #94a3b8;
            font-size: 14px;
            margin-top: 5px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            color: #94a3b8;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 8px;
        }
        .input-wrapper {
            position: relative;
        }
        .input-wrapper i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 16px;
        }
        .form-control {
            width: 100%;
            padding: 14px 15px 14px 45px;
            background: #0f172a;
            border: 2px solid #334155;
            border-radius: 12px;
            color: #f1f5f9;
            font-size: 15px;
            transition: all 0.3s;
        }
        .form-control:focus {
            outline: none;
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1);
        }
        .form-control::placeholder {
            color: #475569;
        }
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(34, 197, 94, 0.3);
        }
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }
        .footer {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #334155;
        }
        .footer a {
            color: #22c55e;
            text-decoration: none;
            font-size: 14px;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: none;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <h1>Sistema Financeiro</h1>
                <p>OneMundo</p>
            </div>
            
            <div class="alert alert-error" id="alertError"></div>
            
            <form id="loginForm">
                <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); ?>">
                <div class="form-group">
                    <label>CPF</label>
                    <div class="input-wrapper">
                        <i class="fas fa-id-card"></i>
                        <input type="text" class="form-control" id="cpf" placeholder="000.000.000-00" required maxlength="14">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Senha</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" class="form-control" id="password" placeholder="Digite sua senha" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-login" id="btnLogin" aria-describedby="login-status">
                    <span class="spinner" id="spinner" aria-hidden="true"></span>
                    <span id="btnText">Entrar</span>
                    <i class="fas fa-arrow-right" id="btnIcon" aria-hidden="true"></i>
                </button>
                <div id="login-status" class="sr-only" aria-live="polite"></div>
            </form>
            
            <div class="footer">
                <a href="/rh/"><i class="fas fa-users"></i> Acessar Sistema RH</a>
            </div>
        </div>
    </div>
    
    <script>
        // Máscara CPF
        function validateCPF(cpf) {
            cpf = cpf.replace(/\D/g, '');
            if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
            
            let sum = 0;
            for (let i = 0; i < 9; i++) sum += parseInt(cpf[i]) * (10 - i);
            let digit1 = (sum * 10) % 11;
            if (digit1 === 10) digit1 = 0;
            
            sum = 0;
            for (let i = 0; i < 10; i++) sum += parseInt(cpf[i]) * (11 - i);
            let digit2 = (sum * 10) % 11;
            if (digit2 === 10) digit2 = 0;
            
            return digit1 === parseInt(cpf[9]) && digit2 === parseInt(cpf[10]);
        }
        
        document.getElementById("cpf").addEventListener("input", function(e) {
            let v = e.target.value.replace(/\D/g, "");
            if (v.length > 11) v = v.slice(0, 11);
            v = v.replace(/(\d{3})(\d)/, "$1.$2");
            v = v.replace(/(\d{3})(\d)/, "$1.$2");
            v = v.replace(/(\d{3})(\d{1,2})$/, "$1-$2");
            e.target.value = v;
        });
        
        // Submit
        document.getElementById("loginForm").addEventListener("submit", async function(e) {
            e.preventDefault();
            
            const btn = document.getElementById("btnLogin");
            const spinner = document.getElementById("spinner");
            const btnText = document.getElementById("btnText");
            const btnIcon = document.getElementById("btnIcon");
            const alert = document.getElementById("alertError");
            
            btn.disabled = true;
            spinner.style.display = "block";
            btnText.textContent = "Entrando...";
            btnIcon.style.display = "none";
            alert.style.display = "none";
            
            try {
                const cpfValue = document.getElementById("cpf").value;
                const passwordValue = document.getElementById("password").value;
                
                // Validações client-side
                if (!cpfValue.trim()) {
                    throw new Error("CPF é obrigatório");
                }
                if (!validateCPF(cpfValue)) {
                    throw new Error("CPF inválido");
                }
                if (!passwordValue.trim() || passwordValue.length < 6) {
                    throw new Error("Senha deve ter pelo menos 6 caracteres");
                }
                
                const formData = new FormData();
                formData.append("action", "login");
                formData.append("cpf", cpfValue);
                formData.append("password", passwordValue);
                formData.append("csrf_token", document.getElementById("csrf_token").value);
                
                const response = await fetch("api/auth.php", {
                    method: "POST",
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.location.href = "dashboard.php";
                } else {
                    alert.textContent = result.error || "Erro ao fazer login";
                    alert.style.display = "block";
                }
            } catch (error) {
                alert.textContent = "Erro de conexão. Tente novamente.";
                alert.style.display = "block";
            }
            
            btn.disabled = false;
            spinner.style.display = "none";
            btnText.textContent = "Entrar";
            btnIcon.style.display = "inline";
        });
    </script>
</body>
</html>