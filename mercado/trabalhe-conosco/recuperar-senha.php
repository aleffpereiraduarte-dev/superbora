<?php
session_start();
$msg = "";
$success = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Aqui seria enviado o email de recuperação
        $msg = "Se o email existir, você receberá um link de recuperação.";
        $success = true;
    } else {
        $msg = "Email inválido.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Recuperar Senha</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--brand:#00C853;--brand-light:#E8F5E9;--dark:#1A1A2E;--gray:#6B7280;--gray-light:#F3F4F6;--white:#FFF;--safe-top:env(safe-area-inset-top);--safe-bottom:env(safe-area-inset-bottom)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Plus Jakarta Sans",sans-serif;background:var(--dark);min-height:100vh;display:flex;flex-direction:column}
        .header{padding:calc(40px + var(--safe-top)) 24px 40px;text-align:center}
        .header h1{color:var(--white);font-size:28px;margin-bottom:8px}
        .header p{color:rgba(255,255,255,0.7);font-size:15px}
        .container{flex:1;background:var(--white);border-radius:32px 32px 0 0;padding:40px 24px calc(40px + var(--safe-bottom))}
        .alert{padding:16px;border-radius:12px;margin-bottom:24px;font-size:14px}
        .alert-success{background:var(--brand-light);color:var(--brand)}
        .alert-error{background:#FEF2F2;color:#EF4444}
        .form-group{margin-bottom:20px}
        .form-label{display:block;font-size:14px;font-weight:600;color:var(--dark);margin-bottom:8px}
        .form-input{width:100%;padding:16px;border:2px solid var(--gray-light);border-radius:12px;font-family:inherit;font-size:16px}
        .form-input:focus{outline:none;border-color:var(--brand)}
        .btn{width:100%;padding:18px;background:var(--brand);color:var(--white);border:none;border-radius:14px;font-family:inherit;font-size:16px;font-weight:700;cursor:pointer;margin-top:24px}
        .link{display:block;text-align:center;margin-top:24px;color:var(--brand);text-decoration:none;font-weight:600}
    </style>
</head>
<body>
    <div class="header">
        <h1>Recuperar Senha</h1>
        <p>Digite seu email para receber o link</p>
    </div>
    
    <div class="container">
        <?php if ($msg): ?>
        <div class="alert <?= $success ? "alert-success" : "alert-error" ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" placeholder="seu@email.com" required>
            </div>
            
            <button type="submit" class="btn">Enviar Link</button>
        </form>
        
        <a href="login.php" class="link">← Voltar para o login</a>
    </div>
</body>
</html>