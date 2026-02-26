<?php
require_once dirname(__DIR__) . '/config/database.php';
session_start();

if (isset($_SESSION['worker_id'])) {
    header('Location: carteira.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getPDO();
    
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE email = ?");
    $stmt->execute([$email]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // IMPORTANTE: Usar password_hash (não password)
    if ($worker && password_verify($password, $worker['password_hash'])) {
        if ($worker['status'] === 'pending') {
            $error = 'Seu cadastro ainda está em análise.';
        } elseif ($worker['status'] === 'rejected') {
            $error = 'Seu cadastro foi rejeitado.';
        } elseif ($worker['status'] === 'inactive') {
            $error = 'Sua conta está inativa.';
        } else {
            // LOGIN OK!
            $_SESSION['worker_id'] = $worker['worker_id'];
            $_SESSION['worker_name'] = $worker['name'];
            $_SESSION['worker_type'] = $worker['worker_type'];
            
            header('Location: carteira.php');
            exit;
        }
    } else {
        $error = 'E-mail ou senha incorretos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - OneMundo Shopper</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:linear-gradient(135deg,#00C853,#00A844);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .card{background:#fff;border-radius:24px;padding:40px 30px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
        .logo{text-align:center;margin-bottom:30px}
        .logo h1{font-size:28px;color:#1a1a2e}
        .logo h1 span{color:#00C853}
        .logo p{color:#6b7280;font-size:14px;margin-top:5px}
        .form-group{margin-bottom:20px}
        .form-group label{display:block;font-weight:600;margin-bottom:8px;color:#1a1a2e}
        .form-group input{width:100%;padding:16px;border:2px solid #e5e7eb;border-radius:12px;font-size:16px;transition:.2s}
        .form-group input:focus{outline:none;border-color:#00C853}
        .error{background:#fee2e2;color:#dc2626;padding:12px;border-radius:10px;margin-bottom:20px;text-align:center;font-size:14px}
        .btn{width:100%;padding:16px;background:#00C853;color:#fff;border:none;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;transition:.2s}
        .btn:hover{background:#00A844}
        .links{text-align:center;margin-top:20px;font-size:14px;color:#6b7280}
        .links a{color:#00C853;text-decoration:none;font-weight:600}
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <h1>One<span>Mundo</span></h1>
            <p>Área do Shopper</p>
        </div>
        
        <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>E-mail</label>
                <input type="email" name="email" required placeholder="seu@email.com">
            </div>
            <div class="form-group">
                <label>Senha</label>
                <input type="password" name="password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn">Entrar</button>
        </form>
        
        <div class="links">
            <a href="#">Esqueci minha senha</a>
        </div>
    </div>
</body>
</html>