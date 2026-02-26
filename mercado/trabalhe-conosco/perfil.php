<?php
session_start();
require_once __DIR__ . "/includes/config.php";
require_once __DIR__ . "/includes/functions.php";
requireLogin();
$worker = getWorker();
$firstName = explode(" ", $worker["name"])[0];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Perfil - OneMundo</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--brand:#00C853;--brand-light:#E8F5E9;--dark:#1A1A2E;--gray:#6B7280;--gray-light:#F3F4F6;--white:#FFF;--red:#FF5252;--safe-top:env(safe-area-inset-top);--safe-bottom:env(safe-area-inset-bottom)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Plus Jakarta Sans",sans-serif;background:#F8FAFC;min-height:100vh;padding-bottom:calc(100px + var(--safe-bottom))}
        .header{background:linear-gradient(135deg,var(--brand),#00E676);padding:calc(20px + var(--safe-top)) 20px 80px;text-align:center}
        .header::before{content:"";position:absolute;bottom:0;left:0;right:0;height:40px;background:#F8FAFC;border-radius:40px 40px 0 0}
        .avatar-section{position:relative;z-index:10;margin-top:-60px;text-align:center;padding:0 20px}
        .avatar{width:100px;height:100px;background:var(--white);border-radius:28px;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:800;color:var(--brand);box-shadow:0 8px 32px rgba(0,0,0,0.15);border:4px solid var(--white)}
        .user-name{font-size:24px;font-weight:800;color:var(--dark)}
        .user-email{font-size:14px;color:var(--gray);margin-top:4px}
        .user-badge{display:inline-flex;align-items:center;gap:6px;background:var(--brand-light);color:var(--brand);padding:8px 16px;border-radius:20px;font-size:13px;font-weight:600;margin-top:12px}
        .stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:24px 20px}
        .stat-card{background:var(--white);border-radius:16px;padding:16px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
        .stat-value{font-size:24px;font-weight:800}
        .stat-label{font-size:12px;color:var(--gray);margin-top:4px}
        .menu-section{padding:0 20px 20px}
        .menu-title{font-size:13px;font-weight:600;color:var(--gray);text-transform:uppercase;margin-bottom:12px;padding-left:4px}
        .menu-card{background:var(--white);border-radius:20px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
        .menu-item{display:flex;align-items:center;gap:14px;padding:16px 20px;text-decoration:none;color:var(--dark);border-bottom:1px solid var(--gray-light)}
        .menu-item:last-child{border-bottom:none}
        .menu-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center}
        .menu-icon svg{width:22px;height:22px}
        .menu-icon.green{background:var(--brand-light);color:var(--brand)}
        .menu-icon.blue{background:#E3F2FD;color:#2979FF}
        .menu-icon.orange{background:#FFF3E0;color:#FF9100}
        .menu-icon.red{background:#FFEBEE;color:var(--red)}
        .menu-text{flex:1}
        .menu-text h3{font-size:15px;font-weight:600}
        .menu-text p{font-size:12px;color:var(--gray)}
        .logout-btn{display:block;width:calc(100% - 40px);margin:20px auto;padding:16px;background:var(--red);color:var(--white);border:none;border-radius:14px;font-family:inherit;font-size:15px;font-weight:600;text-align:center;text-decoration:none;cursor:pointer}
    </style>
</head>
<body>
    <header class="header"></header>
    
    <div class="avatar-section">
        <div class="avatar"><?= strtoupper(substr($firstName, 0, 1)) ?></div>
        <h1 class="user-name"><?= htmlspecialchars($worker["name"]) ?></h1>
        <p class="user-email"><?= htmlspecialchars($worker["email"]) ?></p>
        <div class="user-badge">⭐ Nível <?= $worker["level"] ?? 1 ?> • <?= ucfirst($worker["worker_type"]) ?></div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $worker["total_orders"] ?? 0 ?></div>
            <div class="stat-label">Pedidos</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($worker["rating"] ?? 5, 1) ?></div>
            <div class="stat-label">Avaliação</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($worker["completion_rate"] ?? 100, 0) ?>%</div>
            <div class="stat-label">Conclusão</div>
        </div>
    </div>
    
    <div class="menu-section">
        <div class="menu-title">Conta</div>
        <div class="menu-card">
            <a href="editar-perfil.php" class="menu-item">
                <div class="menu-icon green"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
                <div class="menu-text"><h3>Editar Perfil</h3><p>Nome, foto, telefone</p></div>
            </a>
            <a href="dados-bancarios.php" class="menu-item">
                <div class="menu-icon blue"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg></div>
                <div class="menu-text"><h3>Dados Bancários</h3><p>Chave PIX para receber</p></div>
            </a>
            <a href="score.php" class="menu-item">
                <div class="menu-icon orange"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg></div>
                <div class="menu-text"><h3>Seu Score</h3><p>Desempenho e indicadores</p></div>
            </a>
        </div>
    </div>
    
    <a href="logout.php" class="logout-btn">Sair da Conta</a>
</body>
</html>