<?php
/**
 * Aguardando Aprovação
 */
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#000">
    <title>Aguardando Aprovação</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --bg: #0a0a0a; --card: #141414; --border: #252525; --text: #fff; --text2: #888; --green: #00D26A; --yellow: #FFB800; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { text-align: center; max-width: 400px; }
        .icon { font-size: 80px; margin-bottom: 24px; }
        h1 { font-size: 24px; font-weight: 700; margin-bottom: 12px; }
        p { color: var(--text2); font-size: 15px; line-height: 1.6; margin-bottom: 32px; }
        .status-card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 20px; margin-bottom: 24px; }
        .status-item { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .status-item:last-child { border-bottom: none; }
        .status-icon { width: 32px; height: 32px; background: var(--green); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; }
        .status-icon.pending { background: var(--yellow); }
        .status-text { flex: 1; text-align: left; font-size: 14px; }
        .btn { display: block; width: 100%; padding: 16px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; color: var(--text); font-size: 15px; font-weight: 500; text-decoration: none; text-align: center; margin-bottom: 12px; }
        .btn:hover { border-color: var(--green); }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">⏳</div>
        <h1>Cadastro em Análise</h1>
        <p>Seu cadastro foi recebido e está sendo analisado pela nossa equipe. Você receberá uma notificação quando for aprovado.</p>
        
        <div class="status-card">
            <div class="status-item">
                <div class="status-icon">✓</div>
                <div class="status-text">Cadastro enviado</div>
            </div>
            <div class="status-item">
                <div class="status-icon pending">⏳</div>
                <div class="status-text">Análise em andamento</div>
            </div>
            <div class="status-item">
                <div class="status-icon" style="background: var(--border);">3</div>
                <div class="status-text" style="color: var(--text2);">Aprovação</div>
            </div>
        </div>
        
        <a href="login.php" class="btn">← Voltar ao Login</a>
        <a href="https://wa.me/5531999999999" class="btn">💬 Falar com Suporte</a>
    </div>
</body>
</html>
