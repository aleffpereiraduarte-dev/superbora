<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * 🔑 LOGIN WORKER - ONEMUNDO MERCADO
 * Upload em: /mercado/worker/login.php
 */

session_start();
error_reporting(0);

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$erro = '';

// Já logado?
if (isset($_SESSION['worker_id'])) {
    header("Location: index.php");
    exit;
}

// Processar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    
    if (empty($email)) {
        $erro = "Digite seu email";
    } else {
        // Buscar worker
        $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE email = ? AND application_status = 'approved'");
        $stmt->execute([$email]);
        $worker = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($worker) {
            // Para teste, aceita qualquer senha ou verifica hash
            $senhaOk = empty($worker['password_hash']) || password_verify($senha, $worker['password_hash']) || $senha === 'teste123';
            
            if ($senhaOk) {
                $_SESSION['worker_id'] = $worker['worker_id'];
                $_SESSION['worker_name'] = $worker['name'];
                
                // Atualizar online
                $pdo->prepare("UPDATE om_market_workers SET is_online = 1 WHERE worker_id = ?")->execute([$worker['worker_id']]);
                
                header("Location: index.php");
                exit;
            } else {
                $erro = "Senha incorreta";
            }
        } else {
            $erro = "Email não encontrado ou conta não aprovada";
        }
    }
}

// Buscar workers para facilitar teste
$workersDisponiveis = $pdo->query("SELECT email, name FROM om_market_workers WHERE application_status = 'approved' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Worker - OneMundo</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #00B04F;
            --primary-dark: #009640;
            --secondary: #FF6B35;
            --dark: #1A1A1A;
            --gray: #6B7280;
            --light: #F8F9FA;
            --white: #FFFFFF;
            --error: #DC3545;
            --radius: 12px;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            padding: 20px;
        }
        
        .login-card {
            background: var(--white);
            border-radius: 24px;
            padding: 40px 30px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header .icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        .login-header h1 {
            font-size: 1.5rem;
            margin-bottom: 8px;
            color: var(--dark);
        }
        .login-header p {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: var(--dark);
        }
        .form-group input {
            width: 100%;
            padding: 16px;
            border: 2px solid #e5e7eb;
            border-radius: var(--radius);
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
        }
        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 176, 79, 0.3);
        }
        
        .alert {
            padding: 14px 16px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .alert-error {
            background: #fee2e2;
            color: var(--error);
            border: 1px solid #fecaca;
        }
        
        .divider {
            text-align: center;
            color: var(--gray);
            margin: 25px 0;
            font-size: 0.85rem;
            position: relative;
        }
        .divider::before, .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 40%;
            height: 1px;
            background: #e5e7eb;
        }
        .divider::before { left: 0; }
        .divider::after { right: 0; }
        
        .quick-login {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .quick-btn {
            padding: 12px 16px;
            background: var(--light);
            border: 1px solid #e5e7eb;
            border-radius: var(--radius);
            cursor: pointer;
            text-align: left;
            transition: all 0.2s;
            font-family: inherit;
        }
        .quick-btn:hover {
            background: #e5e7eb;
        }
        .quick-btn .name {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--dark);
        }
        .quick-btn .email {
            font-size: 0.75rem;
            color: var(--gray);
        }
        
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: var(--gray);
            text-decoration: none;
            font-size: 0.9rem;
        }
        .back-link:hover { color: var(--primary); }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="icon">👷</div>
            <h1>Área do Worker</h1>
            <p>Entre para ver seus pedidos e ganhos</p>
        </div>
        
        <?php if ($erro): ?>
        <div class="alert alert-error"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" placeholder="seu@email.com" required>
            </div>
            
            <div class="form-group">
                <label>Senha</label>
                <input type="password" name="senha" placeholder="••••••••">
            </div>
            
            <button type="submit" class="btn btn-primary">Entrar</button>
        </form>
        
        <?php if (!empty($workersDisponiveis)): ?>
        <div class="divider">ou entre rápido como</div>
        
        <div class="quick-login">
            <?php foreach ($workersDisponiveis as $w): ?>
            <button type="button" class="quick-btn" onclick="document.querySelector('input[name=email]').value='<?= $w['email'] ?>'; document.querySelector('input[name=senha]').value='teste123'; document.querySelector('form').submit();">
                <div class="name"><?= $w['name'] ?></div>
                <div class="email"><?= $w['email'] ?></div>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <a href="../" class="back-link">← Voltar para a loja</a>
    </div>
</body>
</html>
