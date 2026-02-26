<?php
session_start();
if (isset($_SESSION["fin_user_id"])) { header("Location: dashboard.php"); exit; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Financeiro OneMundo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0f172a,#1e293b);padding:20px}
        .card{background:#1e293b;border-radius:20px;padding:40px;width:100%;max-width:420px;box-shadow:0 20px 40px rgba(0,0,0,0.4)}
        .logo{text-align:center;margin-bottom:30px}
        .logo-icon{width:70px;height:70px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 15px;font-size:30px;color:#fff}
        .logo h1{color:#f1f5f9;font-size:22px}
        .logo p{color:#94a3b8;font-size:13px;margin-top:5px}
        .form-group{margin-bottom:20px}
        .form-group label{display:block;color:#94a3b8;font-size:13px;margin-bottom:8px}
        .input-wrap{position:relative}
        .input-wrap i{position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#64748b}
        input{width:100%;padding:14px 15px 14px 45px;background:#0f172a;border:2px solid #334155;border-radius:10px;color:#f1f5f9;font-size:15px}
        input:focus{outline:none;border-color:#22c55e}
        input.center{text-align:center;font-size:24px;letter-spacing:8px;padding-left:15px}
        button{width:100%;padding:14px;background:linear-gradient(135deg,#22c55e,#16a34a);border:none;border-radius:10px;color:#fff;font-size:16px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px}
        button:hover{opacity:0.9}
        button:disabled{opacity:0.6;cursor:not-allowed}
        .alert{padding:12px;border-radius:8px;margin-bottom:15px;font-size:14px;display:none}
        .alert-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5}
        .alert-success{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#86efac}
        .alert-info{background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.3);color:#93c5fd}
        .spinner{width:18px;height:18px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;display:none}
        @keyframes spin{to{transform:rotate(360deg)}}
        .step{display:none}
        .step.active{display:block}
        .qr-container{text-align:center;margin:20px 0}
        .qr-container img{border-radius:10px;border:3px solid #22c55e}
        .secret-box{background:#0f172a;padding:15px;border-radius:8px;text-align:center;margin:15px 0;font-family:monospace;font-size:18px;letter-spacing:2px;color:#22c55e}
        .instructions{background:#0f172a;padding:15px;border-radius:8px;margin:15px 0;font-size:13px;color:#94a3b8}
        .instructions ol{margin-left:20px}
        .instructions li{margin:8px 0}
        .password-requirements{font-size:12px;color:#64748b;margin-top:10px}
        .password-requirements li{margin:3px 0}
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-dollar-sign"></i></div>
            <h1>Sistema Financeiro</h1>
            <p>OneMundo</p>
        </div>
        
        <div class="alert alert-error" id="alertError"></div>
        <div class="alert alert-success" id="alertSuccess"></div>
        <div class="alert alert-info" id="alertInfo"></div>
        
        <!-- STEP 1: LOGIN -->
        <div class="step active" id="stepLogin">
            <form id="formLogin">
                <div class="form-group">
                    <label>CPF</label>
                    <div class="input-wrap">
                        <i class="fas fa-id-card"></i>
                        <input type="text" id="cpf" placeholder="000.000.000-00" maxlength="14" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Senha</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" placeholder="Sua senha" required>
                    </div>
                </div>
                <button type="submit">
                    <span class="spinner" id="spinnerLogin"></span>
                    <span id="btnLoginText">Entrar</span>
                </button>
            </form>
        </div>
        
        <!-- STEP 2: TROCAR SENHA -->
        <div class="step" id="stepPassword">
            <h3 style="color:#f1f5f9;margin-bottom:20px;text-align:center">
                <i class="fas fa-key" style="color:#f59e0b"></i> Criar Nova Senha
            </h3>
            <form id="formPassword">
                <div class="form-group">
                    <label>Nova Senha</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="newPassword" placeholder="Digite sua nova senha" required minlength="6">
                    </div>
                </div>
                <div class="form-group">
                    <label>Confirmar Senha</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirmPassword" placeholder="Confirme a senha" required>
                    </div>
                </div>
                <ul class="password-requirements">
                    <li>Mínimo 6 caracteres</li>
                    <li>Use letras e números para maior segurança</li>
                </ul>
                <button type="submit">
                    <span class="spinner" id="spinnerPassword"></span>
                    <span>Salvar Nova Senha</span>
                </button>
            </form>
        </div>
        
        <!-- STEP 3: CONFIGURAR 2FA -->
        <div class="step" id="step2FASetup">
            <h3 style="color:#f1f5f9;margin-bottom:15px;text-align:center">
                <i class="fas fa-shield-alt" style="color:#22c55e"></i> Configurar Autenticação
            </h3>
            
            <div class="instructions">
                <ol>
                    <li>Baixe o <strong>Google Authenticator</strong> no celular</li>
                    <li>Abra o app e toque em <strong>+</strong></li>
                    <li>Escaneie o QR Code abaixo</li>
                    <li>Digite o código de 6 dígitos</li>
                </ol>
            </div>
            
            <div class="qr-container">
                <img id="qrCode" src="" alt="QR Code">
            </div>
            
            <p style="color:#94a3b8;font-size:12px;text-align:center;margin-bottom:10px">Ou digite manualmente:</p>
            <div class="secret-box" id="secretKey"></div>
            
            <form id="form2FASetup">
                <div class="form-group">
                    <label>Código do Authenticator</label>
                    <div class="input-wrap">
                        <i class="fas fa-key"></i>
                        <input type="text" id="setupCode" class="center" placeholder="000000" maxlength="6" required pattern="[0-9]{6}">
                    </div>
                </div>
                <button type="submit">
                    <span class="spinner" id="spinner2FASetup"></span>
                    <span>Ativar 2FA e Entrar</span>
                </button>
            </form>
        </div>
        
        <!-- STEP 4: VERIFICAR 2FA -->
        <div class="step" id="step2FAVerify">
            <h3 style="color:#f1f5f9;margin-bottom:20px;text-align:center">
                <i class="fas fa-mobile-alt" style="color:#3b82f6"></i> Verificação em Duas Etapas
            </h3>
            <p style="color:#94a3b8;text-align:center;margin-bottom:20px">
                Abra o Google Authenticator e digite o código de 6 dígitos
            </p>
            <form id="form2FAVerify">
                <div class="form-group">
                    <div class="input-wrap">
                        <i class="fas fa-key"></i>
                        <input type="text" id="verifyCode" class="center" placeholder="000000" maxlength="6" required pattern="[0-9]{6}" autofocus>
                    </div>
                </div>
                <button type="submit">
                    <span class="spinner" id="spinner2FAVerify"></span>
                    <span>Verificar e Entrar</span>
                </button>
            </form>
        </div>
    </div>
    
    <script>
        // Helpers
        function showStep(stepId) {
            document.querySelectorAll(".step").forEach(s => s.classList.remove("active"));
            document.getElementById(stepId).classList.add("active");
        }
        
        function showAlert(type, msg) {
            document.querySelectorAll(".alert").forEach(a => a.style.display = "none");
            const alert = document.getElementById("alert" + type.charAt(0).toUpperCase() + type.slice(1));
            alert.textContent = msg;
            alert.style.display = "block";
        }
        
        function hideAlerts() {
            document.querySelectorAll(".alert").forEach(a => a.style.display = "none");
        }
        
        // Máscara CPF
        document.getElementById("cpf").addEventListener("input", function(e) {
            let v = e.target.value.replace(/\D/g, "");
            if (v.length > 11) v = v.slice(0, 11);
            v = v.replace(/(\d{3})(\d)/, "$1.$2").replace(/(\d{3})(\d)/, "$1.$2").replace(/(\d{3})(\d{1,2})$/, "$1-$2");
            e.target.value = v;
        });
        
        // Só números no código 2FA
        ["setupCode", "verifyCode"].forEach(id => {
            document.getElementById(id).addEventListener("input", function(e) {
                e.target.value = e.target.value.replace(/\D/g, "").slice(0, 6);
            });
        });
        
        // STEP 1: Login
        document.getElementById("formLogin").addEventListener("submit", async function(e) {
            e.preventDefault();
            hideAlerts();
            
            const btn = this.querySelector("button");
            const spinner = document.getElementById("spinnerLogin");
            btn.disabled = true;
            spinner.style.display = "block";
            
            try {
                const formData = new FormData();
                formData.append("action", "login");
                formData.append("cpf", document.getElementById("cpf").value);
                formData.append("password", document.getElementById("password").value);
                
                const r = await fetch("api/auth.php", { method: "POST", body: formData });
                const result = await r.json();
                
                if (!result.success) {
                    showAlert("error", result.error);
                } else if (result.requires_password_change) {
                    showAlert("info", result.message);
                    showStep("stepPassword");
                } else if (result.requires_2fa_setup) {
                    document.getElementById("qrCode").src = result.qr_url;
                    document.getElementById("secretKey").textContent = result.secret;
                    showAlert("info", result.message);
                    showStep("step2FASetup");
                } else if (result.requires_2fa_code) {
                    showStep("step2FAVerify");
                    document.getElementById("verifyCode").focus();
                }
            } catch (err) {
                showAlert("error", "Erro de conexão");
            }
            
            btn.disabled = false;
            spinner.style.display = "none";
        });
        
        // STEP 2: Trocar Senha
        document.getElementById("formPassword").addEventListener("submit", async function(e) {
            e.preventDefault();
            hideAlerts();
            
            const newPass = document.getElementById("newPassword").value;
            const confirmPass = document.getElementById("confirmPassword").value;
            
            if (newPass !== confirmPass) {
                showAlert("error", "Senhas não conferem");
                return;
            }
            
            const btn = this.querySelector("button");
            const spinner = document.getElementById("spinnerPassword");
            btn.disabled = true;
            spinner.style.display = "block";
            
            try {
                const formData = new FormData();
                formData.append("action", "change_password");
                formData.append("new_password", newPass);
                formData.append("confirm_password", confirmPass);
                
                const r = await fetch("api/auth.php", { method: "POST", body: formData });
                const result = await r.json();
                
                if (!result.success) {
                    showAlert("error", result.error);
                } else if (result.requires_2fa_setup) {
                    document.getElementById("qrCode").src = result.qr_url;
                    document.getElementById("secretKey").textContent = result.secret;
                    showAlert("success", result.message);
                    showStep("step2FASetup");
                } else if (result.requires_2fa_code) {
                    showAlert("success", result.message);
                    showStep("step2FAVerify");
                }
            } catch (err) {
                showAlert("error", "Erro de conexão");
            }
            
            btn.disabled = false;
            spinner.style.display = "none";
        });
        
        // STEP 3: Configurar 2FA
        document.getElementById("form2FASetup").addEventListener("submit", async function(e) {
            e.preventDefault();
            hideAlerts();
            
            const btn = this.querySelector("button");
            const spinner = document.getElementById("spinner2FASetup");
            btn.disabled = true;
            spinner.style.display = "block";
            
            try {
                const formData = new FormData();
                formData.append("action", "setup_2fa");
                formData.append("code", document.getElementById("setupCode").value);
                
                const r = await fetch("api/auth.php", { method: "POST", body: formData });
                const result = await r.json();
                
                if (result.success) {
                    showAlert("success", result.message);
                    setTimeout(() => { window.location.href = "dashboard.php"; }, 1000);
                } else {
                    showAlert("error", result.error);
                    document.getElementById("setupCode").value = "";
                    document.getElementById("setupCode").focus();
                }
            } catch (err) {
                showAlert("error", "Erro de conexão");
            }
            
            btn.disabled = false;
            spinner.style.display = "none";
        });
        
        // STEP 4: Verificar 2FA
        document.getElementById("form2FAVerify").addEventListener("submit", async function(e) {
            e.preventDefault();
            hideAlerts();
            
            const btn = this.querySelector("button");
            const spinner = document.getElementById("spinner2FAVerify");
            btn.disabled = true;
            spinner.style.display = "block";
            
            try {
                const formData = new FormData();
                formData.append("action", "verify_2fa");
                formData.append("code", document.getElementById("verifyCode").value);
                
                const r = await fetch("api/auth.php", { method: "POST", body: formData });
                const result = await r.json();
                
                if (result.success) {
                    showAlert("success", result.message);
                    setTimeout(() => { window.location.href = "dashboard.php"; }, 500);
                } else {
                    showAlert("error", result.error);
                    document.getElementById("verifyCode").value = "";
                    document.getElementById("verifyCode").focus();
                }
            } catch (err) {
                showAlert("error", "Erro de conexão");
            }
            
            btn.disabled = false;
            spinner.style.display = "none";
        });
    </script>
</body>
</html>