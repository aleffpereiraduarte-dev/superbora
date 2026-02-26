<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * 👷 PAINEL DO WORKER - ONEMUNDO MERCADO
 * Design Premium estilo Instacart
 * Upload em: /mercado/worker/index.php
 */

session_start();
error_reporting(0);

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Verificar login
$logado = isset($_SESSION['worker_id']);
$worker = null;

if ($logado) {
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$_SESSION['worker_id']]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$worker) {
        session_destroy();
        header("Location: login.php");
        exit;
    }
}

// Stats
$stats = [];
if ($logado) {
    $worker_id = intval($worker['worker_id']);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE (shopper_id = ? OR delivery_driver_id = ?) AND DATE(created_at) = CURRENT_DATE");
    $stmt->execute([$worker_id, $worker_id]);
    $stats['pedidos_hoje'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_market_worker_earnings WHERE worker_id = ? AND DATE(created_at) = CURRENT_DATE");
    $stmt->execute([$worker_id]);
    $stats['ganhos_hoje'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_market_worker_earnings WHERE worker_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt->execute([$worker_id]);
    $stats['ganhos_semana'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_worker_badges WHERE worker_id = ?");
    $stmt->execute([$worker_id]);
    $stats['badges'] = $stmt->fetchColumn();

    // Pedidos disponíveis (no user input in this query)
    $stats['disponiveis'] = $pdo->query("SELECT COUNT(*) FROM om_market_orders WHERE status = 'paid' AND shopper_id IS NULL")->fetchColumn();
}

// Toggle online/offline
if (isset($_POST['toggle_online']) && $logado) {
    $novoStatus = $worker['is_online'] ? 0 : 1;
    $pdo->prepare("UPDATE om_market_workers SET is_online = ? WHERE worker_id = ?")->execute([$novoStatus, $worker['worker_id']]);
    header("Location: ?");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>👷 Worker - OneMundo</title>
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
            --success: #28A745;
            --error: #DC3545;
            --warning: #FFC107;
            --shadow: 0 2px 8px rgba(0,0,0,0.1);
            --radius: 12px;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--light);
            color: var(--dark);
            min-height: 100vh;
        }
        
        /* Header */
        .header {
            background: var(--primary);
            color: var(--white);
            padding: 16px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow);
        }
        .header-content {
            max-width: 600px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo { font-size: 1.2rem; font-weight: 700; }
        .header-right { display: flex; align-items: center; gap: 15px; }
        
        /* Status Badge */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .status-online { background: rgba(255,255,255,0.2); }
        .status-offline { background: rgba(0,0,0,0.2); }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--white);
            animation: pulse 2s infinite;
        }
        .status-offline .status-dot { background: var(--gray); animation: none; }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Container */
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Welcome Card */
        .welcome-card {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: var(--radius);
            padding: 24px;
            color: var(--white);
            margin-bottom: 20px;
        }
        .welcome-card h1 { font-size: 1.5rem; margin-bottom: 8px; }
        .welcome-card p { opacity: 0.9; font-size: 0.9rem; }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
        }
        .stat-value { font-size: 1.8rem; font-weight: 700; color: var(--primary); }
        .stat-label { font-size: 0.8rem; color: var(--gray); margin-top: 4px; }
        .stat-card.highlight { background: var(--secondary); color: var(--white); }
        .stat-card.highlight .stat-value { color: var(--white); }
        .stat-card.highlight .stat-label { color: rgba(255,255,255,0.9); }
        
        /* Action Buttons */
        .action-grid {
            display: grid;
            gap: 12px;
            margin-bottom: 20px;
        }
        .action-btn {
            background: var(--white);
            border: none;
            border-radius: var(--radius);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
            color: var(--dark);
        }
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .action-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .action-icon.green { background: rgba(0, 176, 79, 0.1); }
        .action-icon.orange { background: rgba(255, 107, 53, 0.1); }
        .action-icon.blue { background: rgba(59, 130, 246, 0.1); }
        .action-icon.purple { background: rgba(139, 92, 246, 0.1); }
        .action-info h3 { font-size: 1rem; font-weight: 600; margin-bottom: 4px; }
        .action-info p { font-size: 0.8rem; color: var(--gray); }
        .action-badge {
            margin-left: auto;
            background: var(--secondary);
            color: var(--white);
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        /* Toggle Switch */
        .toggle-section {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .toggle-info h3 { font-size: 1rem; font-weight: 600; margin-bottom: 4px; }
        .toggle-info p { font-size: 0.8rem; color: var(--gray); }
        .toggle-switch {
            width: 60px;
            height: 34px;
            background: #ddd;
            border-radius: 17px;
            position: relative;
            cursor: pointer;
            transition: background 0.3s;
        }
        .toggle-switch.active { background: var(--primary); }
        .toggle-switch::after {
            content: '';
            position: absolute;
            width: 28px;
            height: 28px;
            background: var(--white);
            border-radius: 50%;
            top: 3px;
            left: 3px;
            transition: left 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .toggle-switch.active::after { left: 29px; }
        
        /* Login Form */
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }
        .login-card {
            background: var(--white);
            border-radius: 20px;
            padding: 40px 30px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .login-card h1 { text-align: center; margin-bottom: 8px; font-size: 1.5rem; }
        .login-card .subtitle { text-align: center; color: var(--gray); margin-bottom: 30px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: var(--radius);
            font-size: 1rem;
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
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 176, 79, 0.3);
        }
        
        .nav-bottom {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
            padding-bottom: max(10px, env(safe-area-inset-bottom));
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            color: var(--gray);
            font-size: 0.75rem;
            padding: 5px 15px;
        }
        .nav-item.active { color: var(--primary); }
        .nav-item span { font-size: 1.3rem; }
        
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .alert-error { background: #fee2e2; color: var(--error); }
    </style>
</head>
<body>
    <?php if (!$logado): ?>
    <!-- Redirecionar para login -->
    <script>window.location.href = 'login.php';</script>
    <?php else: ?>
    
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo">👷 OneMundo Worker</div>
            <div class="header-right">
                <div class="status-badge <?= $worker['is_online'] ? 'status-online' : 'status-offline' ?>">
                    <span class="status-dot"></span>
                    <?= $worker['is_online'] ? 'Online' : 'Offline' ?>
                </div>
            </div>
        </div>
    </header>
    
    <div class="container" style="padding-bottom: 100px;">
        
        <!-- Welcome -->
        <div class="welcome-card">
            <h1>Olá, <?= explode(' ', $worker['name'])[0] ?>! 👋</h1>
            <p>Pronto para ganhar dinheiro hoje?</p>
        </div>
        
        <!-- Toggle Online -->
        <form method="POST" class="toggle-section">
            <div class="toggle-info">
                <h3>Aceitar Pedidos</h3>
                <p><?= $worker['is_online'] ? 'Você está recebendo ofertas' : 'Ative para receber pedidos' ?></p>
            </div>
            <button type="submit" name="toggle_online" class="toggle-switch <?= $worker['is_online'] ? 'active' : '' ?>" style="border: none;"></button>
        </form>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['pedidos_hoje'] ?></div>
                <div class="stat-label">Pedidos Hoje</div>
            </div>
            <div class="stat-card highlight">
                <div class="stat-value">R$ <?= number_format($stats['ganhos_hoje'], 0, ',', '.') ?></div>
                <div class="stat-label">Ganhos Hoje</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($stats['ganhos_semana'], 0, ',', '.') ?></div>
                <div class="stat-label">Esta Semana</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['badges'] ?></div>
                <div class="stat-label">🏅 Badges</div>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="action-grid">
            <a href="pedidos.php" class="action-btn">
                <div class="action-icon green">📦</div>
                <div class="action-info">
                    <h3>Pedidos Disponíveis</h3>
                    <p>Veja ofertas perto de você</p>
                </div>
                <?php if ($stats['disponiveis'] > 0): ?>
                <span class="action-badge"><?= $stats['disponiveis'] ?> novos</span>
                <?php endif; ?>
            </a>
            
            <a href="meus-pedidos.php" class="action-btn">
                <div class="action-icon blue">📋</div>
                <div class="action-info">
                    <h3>Meus Pedidos</h3>
                    <p>Pedidos aceitos e em andamento</p>
                </div>
            </a>
            
            <a href="ganhos.php" class="action-btn">
                <div class="action-icon orange">💰</div>
                <div class="action-info">
                    <h3>Meus Ganhos</h3>
                    <p>Histórico e saque</p>
                </div>
            </a>
            
            <a href="perfil.php" class="action-btn">
                <div class="action-icon purple">👤</div>
                <div class="action-info">
                    <h3>Meu Perfil</h3>
                    <p>Dados, documentos e badges</p>
                </div>
            </a>
        </div>
        
    </div>
    
    <!-- Nav Bottom -->
    <nav class="nav-bottom">
        <a href="index.php" class="nav-item active">
            <span>🏠</span>
            Início
        </a>
        <a href="pedidos.php" class="nav-item">
            <span>📦</span>
            Pedidos
        </a>
        <a href="ganhos.php" class="nav-item">
            <span>💰</span>
            Ganhos
        </a>
        <a href="perfil.php" class="nav-item">
            <span>👤</span>
            Perfil
        </a>
    </nav>
    
    <?php endif; ?>
</body>
</html>
