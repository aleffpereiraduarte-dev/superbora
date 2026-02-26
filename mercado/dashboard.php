<?php
require_once __DIR__ . '/config/database.php';
include 'seguranca-check.php'; 
requireLogin();

// Buscar nome do usuário logado
$userName = 'Administrador';
$userRole = 'Admin';
$userInitials = 'AD';

try {
    $pdo = getPDO();
    
    // Verifica se tem sessão com admin_id
    if (isset($_SESSION['admin_id'])) {
        $stmt = $pdo->prepare("SELECT name, username, role FROM om_rh_admins WHERE admin_id = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($admin) {
            $userName = $admin['name'] ?: $admin['username'];
            $userRole = $admin['role'] ?: 'Admin';
        }
    }
    // Ou se tem username na sessão
    elseif (isset($_SESSION['username'])) {
        $stmt = $pdo->prepare("SELECT name, username, role FROM om_rh_admins WHERE username = ?");
        $stmt->execute([$_SESSION['username']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($admin) {
            $userName = $admin['name'] ?: $admin['username'];
            $userRole = $admin['role'] ?: 'Admin';
        }
    }
    
    // Gera iniciais
    $parts = explode(' ', $userName);
    $userInitials = strtoupper(substr($parts[0], 0, 1));
    if (isset($parts[1])) $userInitials .= strtoupper(substr($parts[1], 0, 1));
    
    // ========== DADOS DO MERCADO (MEI) ==========
    $mercadoPendentes = 0;
    $mercadoAprovados = 0;
    $mercadoShoppers = 0;
    $mercadoDrivers = 0;
    $mercadoListaPendentes = [];
    
    try {
        $mercadoPendentes = $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE status = 'pending'")->fetchColumn() ?: 0;
        $mercadoAprovados = $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE status = 'approved'")->fetchColumn() ?: 0;
        $mercadoShoppers = $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE is_shopper = 1 AND status = 'approved'")->fetchColumn() ?: 0;
        $mercadoDrivers = $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE is_driver = 1 AND status = 'approved'")->fetchColumn() ?: 0;
        
        $stmt = $pdo->query("
            SELECT w.*, 
                   (SELECT COUNT(*) FROM om_worker_documents WHERE worker_id = w.worker_id) as docs_count
            FROM om_market_workers w 
            WHERE w.status = 'pending' 
            ORDER BY w.created_at DESC 
            LIMIT 5
        ");
        $mercadoListaPendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Tabelas podem não existir ainda
    }
    
} catch (Exception $e) {
    // Mantém valores padrão
}

// Primeiro nome para saudação
$firstName = explode(' ', $userName)[0];

// Hora para saudação
$hour = (int)date('H');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'Bom dia';
    $greetingIcon = '☀️';
    $bannerGradient = 'linear-gradient(135deg, #f97316 0%, #fb923c 50%, #fbbf24 100%)';
    $bannerEmoji = '🌅';
} elseif ($hour >= 12 && $hour < 18) {
    $greeting = 'Boa tarde';
    $greetingIcon = '🌤️';
    $bannerGradient = 'linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%)';
    $bannerEmoji = '✨';
} else {
    $greeting = 'Boa noite';
    $greetingIcon = '🌙';
    $bannerGradient = 'linear-gradient(135deg, #1e3a5f 0%, #2d3748 50%, #4a5568 100%)';
    $bannerEmoji = '🌟';
}

// Data formatada
$dias = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
$meses = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$dateFormatted = $dias[date('w')] . ', ' . date('d') . ' de ' . $meses[(int)date('m')] . ' de ' . date('Y');

// Config de tipos de worker
$tipoLabels = [
    'shopper' => ['🛒', 'Shopper', '#10b981'],
    'driver' => ['🚴', 'Entregador', '#f97316'],
    'fullservice' => ['⭐', 'Full Service', '#8b5cf6'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard RH - OneMundo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
    <style>
        :root{--primary:#667eea;--primary-dark:#5a67d8;--secondary:#764ba2;--success:#10b981;--success-light:#d1fae5;--warning:#f59e0b;--warning-light:#fef3c7;--danger:#ef4444;--danger-light:#fee2e2;--info:#3b82f6;--info-light:#dbeafe;--purple:#8b5cf6;--pink:#ec4899;--cyan:#06b6d4;--gray-50:#f9fafb;--gray-100:#f3f4f6;--gray-200:#e5e7eb;--gray-300:#d1d5db;--gray-400:#9ca3af;--gray-500:#6b7280;--gray-600:#4b5563;--gray-700:#374151;--gray-800:#1f2937;--gray-900:#111827;--sidebar-width:260px;--header-height:65px;--radius:12px;--shadow:0 1px 3px 0 rgb(0 0 0/.1);--shadow-lg:0 10px 15px -3px rgb(0 0 0/.1);--shadow-xl:0 20px 25px -5px rgb(0 0 0/.1)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',-apple-system,sans-serif;background:linear-gradient(135deg,#f5f7fa,#e4e8ec);color:var(--gray-900);min-height:100vh}
        .app{display:flex;min-height:100vh}
        .sidebar{width:var(--sidebar-width);background:linear-gradient(180deg,#1e293b,#0f172a);position:fixed;left:0;top:0;bottom:0;z-index:1000;display:flex;flex-direction:column;transition:transform .3s;box-shadow:4px 0 24px rgba(0,0,0,.15)}
        .sidebar-header{padding:24px 20px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:14px}
        .sidebar-logo{width:48px;height:48px;background:linear-gradient(135deg,var(--primary),var(--secondary));border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;font-size:16px;box-shadow:0 4px 12px rgba(102,126,234,.4)}
        .sidebar-title{font-size:20px;font-weight:800;color:#fff;letter-spacing:-.5px}
        .sidebar-subtitle{font-size:11px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:1.5px;margin-top:2px}
        .sidebar-nav{flex:1;padding:20px 0;overflow-y:auto}
        .sidebar-nav::-webkit-scrollbar{width:4px}
        .sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.2);border-radius:2px}
        .nav-section{margin-bottom:24px}
        .nav-section-title{padding:8px 24px;font-size:10px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:1.5px}
        .nav-item{display:flex;align-items:center;padding:12px 24px;color:rgba(255,255,255,.7);text-decoration:none;gap:14px;border-left:3px solid transparent;transition:all .2s;margin:2px 0}
        .nav-item:hover{background:rgba(255,255,255,.06);color:#fff}
        .nav-item.active{background:linear-gradient(90deg,rgba(102,126,234,.2),transparent);color:#fff;border-left-color:var(--primary)}
        .nav-item i{width:20px;font-size:16px;text-align:center;opacity:.9}
        .nav-item span{font-size:13px;font-weight:500}
        .nav-badge{margin-left:auto;background:var(--danger);color:#fff;font-size:10px;font-weight:700;padding:3px 8px;border-radius:20px;min-width:20px;text-align:center}
        .nav-badge.success{background:var(--success)}
        .sidebar-user{padding:20px 24px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:14px;background:rgba(0,0,0,.2)}
        .sidebar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#f97316,#ea580c);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;box-shadow:0 2px 8px rgba(249,115,22,.4)}
        .sidebar-user-name{font-size:14px;font-weight:600;color:#fff}
        .sidebar-user-role{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px}
        .main{flex:1;margin-left:var(--sidebar-width);display:flex;flex-direction:column;min-height:100vh}
        .header{height:var(--header-height);background:#fff;border-bottom:1px solid var(--gray-200);display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100;box-shadow:var(--shadow)}
        .header-left{display:flex;align-items:center;gap:24px}
        .menu-toggle{display:none;width:42px;height:42px;align-items:center;justify-content:center;cursor:pointer;border-radius:8px;background:var(--gray-100);border:none;color:var(--gray-600);font-size:18px;transition:all .2s}
        .header-title h1{font-size:22px;font-weight:700;color:var(--gray-900);letter-spacing:-.5px}
        .header-title p{font-size:13px;color:var(--gray-500);margin-top:2px}
        .header-search{position:relative;width:320px}
        .header-search input{width:100%;padding:11px 16px 11px 44px;border:1px solid var(--gray-200);border-radius:var(--radius);font-size:14px;background:var(--gray-50);transition:all .2s}
        .header-search input:focus{outline:none;border-color:var(--primary);background:#fff;box-shadow:0 0 0 3px rgba(102,126,234,.1)}
        .header-search i{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--gray-400)}
        .header-right{display:flex;align-items:center;gap:10px}
        .header-btn{width:42px;height:42px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;cursor:pointer;background:var(--gray-100);color:var(--gray-600);position:relative;border:none;font-size:17px;transition:all .2s}
        .header-btn:hover{background:var(--gray-200);color:var(--gray-800)}
        .header-btn .badge{position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;background:var(--danger);color:#fff;font-size:10px;font-weight:700;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff}
        .btn-logout{background:#fff;border:1px solid var(--gray-200);color:var(--gray-700);padding:10px 18px;border-radius:var(--radius);font-size:13px;font-weight:500;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s;margin-left:8px}
        .btn-logout:hover{background:var(--danger-light);border-color:#fecaca;color:var(--danger)}
        .content{flex:1;padding:28px;max-width:1600px;margin:0 auto;width:100%}
        
        /* ==================== BANNER BONITO ==================== */
        .welcome-banner{
            background:<?php echo $bannerGradient; ?>;
            border-radius:20px;
            padding:0;
            margin-bottom:28px;
            display:flex;
            align-items:stretch;
            color:#fff;
            position:relative;
            overflow:hidden;
            box-shadow:0 20px 60px rgba(0,0,0,.2);
            min-height:200px;
        }
        .welcome-banner::before{
            content:'';
            position:absolute;
            top:-50%;
            right:-10%;
            width:500px;
            height:500px;
            background:radial-gradient(circle,rgba(255,255,255,.15) 0%,transparent 70%);
            animation:float 6s ease-in-out infinite;
        }
        .welcome-banner::after{
            content:'';
            position:absolute;
            bottom:-60%;
            left:20%;
            width:400px;
            height:400px;
            background:radial-gradient(circle,rgba(255,255,255,.1) 0%,transparent 70%);
            animation:float 8s ease-in-out infinite reverse;
        }
        @keyframes float{
            0%,100%{transform:translateY(0) rotate(0deg)}
            50%{transform:translateY(-20px) rotate(5deg)}
        }
        .welcome-left{
            flex:1;
            padding:40px 48px;
            display:flex;
            flex-direction:column;
            justify-content:center;
            position:relative;
            z-index:1;
        }
        .welcome-greeting{
            display:flex;
            align-items:center;
            gap:16px;
            margin-bottom:8px;
        }
        .welcome-emoji{
            font-size:56px;
            animation:wave 2s ease-in-out infinite;
        }
        @keyframes wave{
            0%,100%{transform:rotate(0deg)}
            25%{transform:rotate(20deg)}
            75%{transform:rotate(-10deg)}
        }
        .welcome-text-group h2{
            font-size:16px;
            font-weight:500;
            opacity:.9;
            letter-spacing:1px;
            text-transform:uppercase;
            margin-bottom:4px;
        }
        .welcome-text-group h1{
            font-size:36px;
            font-weight:800;
            letter-spacing:-1px;
            text-shadow:0 2px 10px rgba(0,0,0,.2);
        }
        .welcome-subtitle{
            margin-top:16px;
            font-size:15px;
            opacity:.85;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .welcome-right{
            width:350px;
            background:rgba(0,0,0,.15);
            backdrop-filter:blur(10px);
            padding:32px;
            display:flex;
            flex-direction:column;
            justify-content:center;
            position:relative;
            z-index:1;
        }
        .welcome-stats{
            display:flex;
            flex-direction:column;
            gap:16px;
        }
        .welcome-stat{
            display:flex;
            align-items:center;
            gap:14px;
            background:rgba(255,255,255,.1);
            padding:14px 18px;
            border-radius:var(--radius);
            transition:all .3s;
        }
        .welcome-stat:hover{
            background:rgba(255,255,255,.2);
            transform:translateX(5px);
        }
        .welcome-stat-icon{
            width:44px;
            height:44px;
            background:rgba(255,255,255,.2);
            border-radius:10px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:18px;
        }
        .welcome-stat-value{
            font-size:26px;
            font-weight:800;
            line-height:1;
        }
        .welcome-stat-label{
            font-size:12px;
            opacity:.8;
            margin-top:2px;
        }
        
        /* ==================== BANNER MERCADO MEI ==================== */
        .mercado-banner{
            background:linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-radius:20px;
            padding:25px;
            margin-bottom:28px;
            color:#fff;
            box-shadow:0 10px 40px rgba(0,0,0,.15);
        }
        .mercado-banner-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:20px;
        }
        .mercado-banner-header h2{
            display:flex;
            align-items:center;
            gap:10px;
            font-size:1.2rem;
            margin:0;
        }
        .mercado-badge-pending{
            background:#ef4444;
            padding:4px 12px;
            border-radius:20px;
            font-size:0.8rem;
            font-weight:600;
            animation:pulse 2s infinite;
        }
        @keyframes pulse{
            0%,100%{opacity:1}
            50%{opacity:0.7}
        }
        .mercado-banner-header a{
            background:rgba(255,255,255,.1);
            color:#fff;
            padding:10px 20px;
            border-radius:10px;
            text-decoration:none;
            font-size:0.9rem;
            font-weight:500;
            transition:all .3s;
        }
        .mercado-banner-header a:hover{
            background:rgba(255,255,255,.2);
        }
        .mercado-stats{
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:15px;
            margin-bottom:20px;
        }
        .mercado-stat{
            background:rgba(255,255,255,.05);
            border-radius:12px;
            padding:15px;
            text-align:center;
        }
        .mercado-stat .value{
            font-size:1.8rem;
            font-weight:700;
        }
        .mercado-stat .value.pending{color:#fbbf24}
        .mercado-stat .value.approved{color:#10b981}
        .mercado-stat .value.shopper{color:#10b981}
        .mercado-stat .value.driver{color:#f97316}
        .mercado-stat .label{
            font-size:0.8rem;
            color:#94a3b8;
            margin-top:5px;
        }
        .mercado-pendentes{
            background:rgba(255,255,255,.05);
            border-radius:12px;
            overflow:hidden;
        }
        .mercado-pendentes-header{
            padding:12px 15px;
            background:rgba(255,255,255,.05);
            font-weight:600;
            font-size:0.9rem;
            display:flex;
            justify-content:space-between;
        }
        .mercado-pendentes-list{
            max-height:200px;
            overflow-y:auto;
        }
        .mercado-pendente-item{
            display:flex;
            align-items:center;
            gap:12px;
            padding:12px 15px;
            border-bottom:1px solid rgba(255,255,255,.05);
            transition:all .3s;
        }
        .mercado-pendente-item:hover{
            background:rgba(255,255,255,.05);
        }
        .mercado-pendente-item:last-child{
            border-bottom:none;
        }
        .mercado-pendente-avatar{
            width:40px;
            height:40px;
            border-radius:50%;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:1.1rem;
        }
        .mercado-pendente-info{
            flex:1;
        }
        .mercado-pendente-info .name{
            font-weight:600;
            font-size:0.9rem;
        }
        .mercado-pendente-info .details{
            font-size:0.75rem;
            color:#94a3b8;
            display:flex;
            gap:15px;
            margin-top:3px;
        }
        .mercado-pendente-type{
            padding:4px 10px;
            border-radius:6px;
            font-size:0.7rem;
            font-weight:600;
        }
        .mercado-pendente-actions{
            display:flex;
            gap:6px;
        }
        .mercado-pendente-actions button{
            width:32px;
            height:32px;
            border:none;
            border-radius:8px;
            cursor:pointer;
            font-size:0.9rem;
            transition:all .3s;
        }
        .mercado-pendente-actions .btn-aprovar{
            background:rgba(16,185,129,.2);
            color:#10b981;
        }
        .mercado-pendente-actions .btn-aprovar:hover{
            background:#10b981;
            color:#fff;
        }
        .mercado-pendente-actions .btn-rejeitar{
            background:rgba(239,68,68,.2);
            color:#ef4444;
        }
        .mercado-pendente-actions .btn-rejeitar:hover{
            background:#ef4444;
            color:#fff;
        }
        .mercado-pendente-actions .btn-ver{
            background:rgba(59,130,246,.2);
            color:#3b82f6;
        }
        .mercado-pendente-actions .btn-ver:hover{
            background:#3b82f6;
            color:#fff;
        }
        .mercado-empty{
            text-align:center;
            padding:30px 20px;
            color:#64748b;
        }
        .mercado-empty .icon{
            font-size:2.5rem;
            margin-bottom:10px;
        }
        
        /* ==================== STATS GRID ==================== */
        .stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:20px;margin-bottom:28px}
        .stat-card{background:#fff;border-radius:var(--radius);padding:24px;display:flex;align-items:center;gap:20px;cursor:pointer;transition:all .3s;border:1px solid var(--gray-200);position:relative;overflow:hidden}
        .stat-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:var(--card-color,var(--primary))}
        .stat-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
        .stat-icon{width:56px;height:56px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:22px}
        .stat-icon.blue{background:var(--info-light);color:var(--info)}
        .stat-icon.green{background:var(--success-light);color:var(--success)}
        .stat-icon.orange{background:var(--warning-light);color:var(--warning)}
        .stat-icon.pink{background:#fce7f3;color:var(--pink)}
        .stat-icon.red{background:var(--danger-light);color:var(--danger)}
        .stat-value{font-size:28px;font-weight:800;color:var(--gray-900);letter-spacing:-1px}
        .stat-label{font-size:13px;color:var(--gray-500);margin-top:4px}
        .stat-change{font-size:12px;margin-top:8px;display:flex;align-items:center;gap:4px}
        .stat-change.up{color:var(--success)}
        .stat-change.down{color:var(--danger)}
        .stat-change.neutral{color:var(--gray-400)}
        .quick-actions{display:flex;gap:12px;margin-bottom:28px;flex-wrap:wrap}
        .quick-btn{padding:12px 24px;border-radius:var(--radius);font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:10px;transition:all .2s;border:1px solid var(--gray-200);background:#fff;color:var(--gray-700)}
        .quick-btn:hover{background:var(--gray-50);border-color:var(--gray-300);transform:translateY(-2px)}
        .quick-btn.primary{background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;border:none;box-shadow:0 4px 12px rgba(102,126,234,.4)}
        .quick-btn.primary:hover{box-shadow:0 6px 20px rgba(102,126,234,.5)}
        .charts-row{display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:28px}
        .chart-card,.alerts-card,.activity-card{background:#fff;border-radius:var(--radius);padding:24px;border:1px solid var(--gray-200)}
        .chart-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
        .chart-title{font-size:16px;font-weight:700;color:var(--gray-900);display:flex;align-items:center;gap:12px}
        .chart-title-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--primary),var(--secondary));border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px}
        .chart-body{height:280px;position:relative}
        .chart-body.small{height:200px}
        .section-badge{padding:6px 14px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
        .section-badge.live{background:#fee2e2;color:#dc2626;animation:pulse 2s infinite}
        .section-badge.count{background:var(--info-light);color:var(--info)}
        .alert-item{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:var(--radius);margin-bottom:10px;cursor:pointer;transition:all .2s}
        .alert-item:hover{transform:translateX(4px)}
        .alert-item.warning{background:linear-gradient(135deg,#fef3c7,#fef9c3)}
        .alert-item.info{background:linear-gradient(135deg,#dbeafe,#e0f2fe)}
        .alert-item.danger{background:linear-gradient(135deg,#fee2e2,#fecaca)}
        .alert-item.success{background:linear-gradient(135deg,#d1fae5,#a7f3d0)}
        .alert-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px}
        .alert-item.warning .alert-icon{background:#fbbf24;color:#fff}
        .alert-item.info .alert-icon{background:#3b82f6;color:#fff}
        .alert-item.danger .alert-icon{background:#ef4444;color:#fff}
        .alert-item.success .alert-icon{background:#10b981;color:#fff}
        .alert-title{font-size:14px;font-weight:600;color:var(--gray-900)}
        .alert-desc{font-size:12px;color:var(--gray-500);margin-top:2px}
        .alert-count{margin-left:auto;min-width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}
        .alert-item.warning .alert-count{background:#f59e0b;color:#fff}
        .alert-item.info .alert-count{background:#3b82f6;color:#fff}
        .alert-item.danger .alert-count{background:#ef4444;color:#fff}
        .alert-item.success .alert-count{background:#10b981;color:#fff}
        .two-col{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px}
        .three-col{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-bottom:28px}
        .kpi-card{background:#fff;border-radius:var(--radius);padding:24px;border:1px solid var(--gray-200)}
        .kpi-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px}
        .kpi-title{font-size:14px;font-weight:600;color:var(--gray-600)}
        .kpi-icon{width:44px;height:44px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:18px}
        .kpi-value{font-size:36px;font-weight:800;color:var(--gray-900);letter-spacing:-1px}
        .kpi-subtitle{font-size:13px;color:var(--gray-500);margin-top:4px;margin-bottom:20px}
        .progress-list{display:flex;flex-direction:column;gap:12px}
        .progress-item .progress-header{display:flex;justify-content:space-between;margin-bottom:6px}
        .progress-label{font-size:12px;color:var(--gray-600)}
        .progress-value{font-size:12px;font-weight:700;color:var(--gray-900)}
        .progress-bar{height:8px;background:var(--gray-100);border-radius:4px;overflow:hidden}
        .progress-fill{height:100%;border-radius:4px;transition:width .6s}
        .progress-fill.green{background:var(--success)}
        .progress-fill.blue{background:var(--info)}
        .progress-fill.orange{background:var(--warning)}
        .progress-fill.purple{background:var(--purple)}
        .section{margin-bottom:28px}
        .section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
        .section-title{font-size:18px;font-weight:700;color:var(--gray-900);display:flex;align-items:center;gap:12px}
        .section-title-icon{width:40px;height:40px;background:linear-gradient(135deg,var(--primary),var(--secondary));border-radius:var(--radius);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px}
        .modules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}
        .module-card{background:#fff;border-radius:var(--radius);padding:24px;border:1px solid var(--gray-200);cursor:pointer;transition:all .3s;position:relative;overflow:hidden}
        .module-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--module-color,var(--primary))}
        .module-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:var(--module-color,var(--primary))}
        .module-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
        .module-icon{width:48px;height:48px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:20px;background:linear-gradient(135deg,var(--module-color,var(--primary)),color-mix(in srgb,var(--module-color,var(--primary)) 80%,#fff));color:#fff}
        .module-status{padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase}
        .module-status.active{background:var(--success-light);color:var(--success)}
        .module-status.pending{background:var(--warning-light);color:var(--warning)}
        .module-status.inactive{background:var(--gray-100);color:var(--gray-500)}
        .module-title{font-size:16px;font-weight:700;color:var(--gray-900);margin-bottom:6px}
        .module-desc{font-size:13px;color:var(--gray-500);margin-bottom:16px}
        .module-stats{display:flex;gap:16px;padding-top:16px;border-top:1px solid var(--gray-100)}
        .module-stat{text-align:center}
        .module-stat-value{font-size:20px;font-weight:800;color:var(--module-color,var(--primary))}
        .module-stat-label{font-size:11px;color:var(--gray-500);margin-top:2px}
        .activity-list{max-height:320px;overflow-y:auto}
        .activity-item{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid var(--gray-100)}
        .activity-item:last-child{border-bottom:none}
        .activity-avatar{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff}
        .activity-avatar.entrada{background:linear-gradient(135deg,#10b981,#059669)}
        .activity-avatar.saida{background:linear-gradient(135deg,#ef4444,#dc2626)}
        .activity-avatar.intervalo{background:linear-gradient(135deg,#f59e0b,#d97706)}
        .activity-avatar.retorno{background:linear-gradient(135deg,#3b82f6,#2563eb)}
        .activity-name{font-size:14px;font-weight:600;color:var(--gray-900)}
        .activity-action{font-size:12px;color:var(--gray-500);margin-top:2px}
        .activity-time{margin-left:auto;font-size:13px;font-weight:600;color:var(--gray-600);background:var(--gray-100);padding:6px 12px;border-radius:8px}
        .fab{position:fixed;bottom:28px;right:28px;z-index:1000}
        .fab-main{width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;border:none;cursor:pointer;font-size:24px;box-shadow:0 4px 20px rgba(102,126,234,.5);transition:all .3s;display:flex;align-items:center;justify-content:center}
        .fab-main:hover{transform:scale(1.1)}
        .fab-main.open{transform:rotate(45deg);background:var(--danger)}
        .fab-items{position:absolute;bottom:70px;right:0;display:flex;flex-direction:column;gap:12px;opacity:0;visibility:hidden;transition:all .3s}
        .fab.open .fab-items{opacity:1;visibility:visible}
        .fab-item{width:48px;height:48px;border-radius:50%;border:none;cursor:pointer;color:#fff;font-size:18px;box-shadow:var(--shadow-lg);transition:all .3s;display:flex;align-items:center;justify-content:center}
        .fab-item:hover{transform:scale(1.1)}
        .fab-item.green{background:var(--success)}
        .fab-item.blue{background:var(--info)}
        .fab-item.orange{background:var(--warning)}
        .fab-item.purple{background:var(--purple)}
        @media(max-width:1200px){.stats-grid{grid-template-columns:repeat(3,1fr)}.charts-row{grid-template-columns:1fr}.two-col,.three-col{grid-template-columns:1fr}}
        @media(max-width:768px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.menu-toggle{display:flex}.header-search{display:none}.welcome-banner{flex-direction:column;min-height:auto}.welcome-right{width:100%}.stats-grid{grid-template-columns:repeat(2,1fr)}.mercado-stats{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">OM</div>
            <div>
                <div class="sidebar-title">OneMundo</div>
                <div class="sidebar-subtitle">Sistema RH</div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">Principal</div>
                <a href="dashboard.php" class="nav-item active"><i class="fas fa-home"></i><span>Dashboard</span></a>
                <a href="employees.php" class="nav-item"><i class="fas fa-users"></i><span>Colaboradores</span></a>
                <a href="ponto.php" class="nav-item"><i class="fas fa-clock"></i><span>Ponto</span></a>
                <a href="ponto-live.php" class="nav-item"><i class="fas fa-broadcast-tower"></i><span>Ponto Live</span><span class="nav-badge success">AO VIVO</span></a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Gestão</div>
                <a href="ferias.php" class="nav-item"><i class="fas fa-umbrella-beach"></i><span>Férias</span><span class="nav-badge" id="navFeriasBadge">0</span></a>
                <a href="treinamentos.php" class="nav-item"><i class="fas fa-graduation-cap"></i><span>Treinamentos</span></a>
                <a href="avaliacoes.php" class="nav-item"><i class="fas fa-chart-line"></i><span>Avaliações</span></a>
                <a href="recrutamento.php" class="nav-item"><i class="fas fa-user-plus"></i><span>Recrutamento</span></a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Mercado</div>
                <a href="mercado-workers.php" class="nav-item"><i class="fas fa-shopping-cart"></i><span>Workers MEI</span><span class="nav-badge" id="navMercadoBadge"><?= $mercadoPendentes ?></span></a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Relatórios</div>
                <a href="relatorios-ponto.php" class="nav-item"><i class="fas fa-file-pdf"></i><span>Relatório Ponto</span></a>
                <a href="relatorios.php" class="nav-item"><i class="fas fa-chart-bar"></i><span>Analytics</span></a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Comunicação</div>
                <a href="chat.php" class="nav-item"><i class="fas fa-comments"></i><span>Chat</span><span class="nav-badge" id="navChatBadge">0</span></a>
            </div>
        </nav>
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?php echo $userInitials; ?></div>
            <div>
                <div class="sidebar-user-name"><?php echo htmlspecialchars($userName); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($userRole); ?></div>
            </div>
        </div>
    </aside>
    <main class="main">
        <header class="header">
            <div class="header-left">
                <button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                <div class="header-title"><h1>Dashboard</h1><p><?php echo $dateFormatted; ?></p></div>
            </div>
            <div class="header-search"><i class="fas fa-search"></i><input type="text" id="globalSearch" placeholder="Buscar colaborador..."></div>
            <div class="header-right">
                <button class="header-btn" onclick="window.location.href='chat.php'"><i class="fas fa-comments"></i><span class="badge" id="headerChatBadge">0</span></button>
                <button class="header-btn"><i class="fas fa-bell"></i><span class="badge" id="headerNotifBadge">0</span></button>
                <button class="btn-logout" onclick="logout()"><i class="fas fa-sign-out-alt"></i>Sair</button>
            </div>
        </header>
        <div class="content">
            <div class="welcome-banner">
                <div class="welcome-left">
                    <div class="welcome-greeting">
                        <div class="welcome-emoji"><?php echo $greetingIcon; ?></div>
                        <div class="welcome-text-group">
                            <h2><?php echo $greeting; ?></h2>
                            <h1><?php echo htmlspecialchars($firstName); ?>!</h1>
                        </div>
                    </div>
                    <p class="welcome-subtitle"><i class="fas fa-calendar-day"></i> <?php echo $dateFormatted; ?> <?php echo $bannerEmoji; ?></p>
                </div>
                <div class="welcome-right">
                    <div class="welcome-stats">
                        <div class="welcome-stat">
                            <div class="welcome-stat-icon"><i class="fas fa-users"></i></div>
                            <div class="welcome-stat-content">
                                <div class="welcome-stat-value" id="welcomeAtivos">-</div>
                                <div class="welcome-stat-label">Colaboradores</div>
                            </div>
                        </div>
                        <div class="welcome-stat">
                            <div class="welcome-stat-icon"><i class="fas fa-user-check"></i></div>
                            <div class="welcome-stat-content">
                                <div class="welcome-stat-value" id="welcomeJornada">-</div>
                                <div class="welcome-stat-label">Em Jornada</div>
                            </div>
                        </div>
                        <div class="welcome-stat">
                            <div class="welcome-stat-icon"><i class="fas fa-exclamation-circle"></i></div>
                            <div class="welcome-stat-content">
                                <div class="welcome-stat-value" id="welcomePendencias">-</div>
                                <div class="welcome-stat-label">Pendências</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== BANNER MERCADO MEI ==================== -->
            <div class="mercado-banner">
                <div class="mercado-banner-header">
                    <h2>
                        🛒 Mercado (MEI/Freelancers)
                        <?php if ($mercadoPendentes > 0): ?>
                        <span class="mercado-badge-pending"><?= $mercadoPendentes ?> pendente<?= $mercadoPendentes > 1 ? 's' : '' ?></span>
                        <?php endif; ?>
                    </h2>
                    <a href="mercado-workers.php">Ver todos →</a>
                </div>
                
                <div class="mercado-stats">
                    <div class="mercado-stat">
                        <div class="value pending"><?= $mercadoPendentes ?></div>
                        <div class="label">Pendentes</div>
                    </div>
                    <div class="mercado-stat">
                        <div class="value approved"><?= $mercadoAprovados ?></div>
                        <div class="label">Aprovados</div>
                    </div>
                    <div class="mercado-stat">
                        <div class="value shopper"><?= $mercadoShoppers ?></div>
                        <div class="label">Shoppers</div>
                    </div>
                    <div class="mercado-stat">
                        <div class="value driver"><?= $mercadoDrivers ?></div>
                        <div class="label">Drivers</div>
                    </div>
                </div>
                
                <div class="mercado-pendentes">
                    <div class="mercado-pendentes-header">
                        <span>📋 Aguardando Aprovação</span>
                        <span><?= count($mercadoListaPendentes) ?> de <?= $mercadoPendentes ?></span>
                    </div>
                    
                    <div class="mercado-pendentes-list">
                        <?php if (empty($mercadoListaPendentes)): ?>
                        <div class="mercado-empty">
                            <div class="icon">✅</div>
                            <p>Nenhum cadastro pendente!</p>
                        </div>
                        <?php else: ?>
                            <?php foreach ($mercadoListaPendentes as $worker): 
                                $tipo = $tipoLabels[$worker['worker_type']] ?? ['👤', 'Worker', '#64748b'];
                            ?>
                            <div class="mercado-pendente-item">
                                <div class="mercado-pendente-avatar" style="background: <?= $tipo[2] ?>20;">
                                    <?= $tipo[0] ?>
                                </div>
                                <div class="mercado-pendente-info">
                                    <div class="name"><?= htmlspecialchars($worker['name']) ?></div>
                                    <div class="details">
                                        <span>📧 <?= htmlspecialchars($worker['email']) ?></span>
                                        <span>📄 <?= $worker['docs_count'] ?? 0 ?> docs</span>
                                        <span>📅 <?= date('d/m H:i', strtotime($worker['created_at'])) ?></span>
                                    </div>
                                </div>
                                <span class="mercado-pendente-type" style="background: <?= $tipo[2] ?>20; color: <?= $tipo[2] ?>;">
                                    <?= $tipo[1] ?>
                                </span>
                                <div class="mercado-pendente-actions">
                                    <button class="btn-ver" title="Ver detalhes" onclick="verWorker(<?= $worker['worker_id'] ?>)">👁️</button>
                                    <button class="btn-aprovar" title="Aprovar" onclick="aprovarWorker(<?= $worker['worker_id'] ?>)">✓</button>
                                    <button class="btn-rejeitar" title="Rejeitar" onclick="rejeitarWorker(<?= $worker['worker_id'] ?>)">✗</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- ==================== FIM BANNER MERCADO ==================== -->

            <div class="stats-grid">
                <div class="stat-card" style="--card-color:#3b82f6" onclick="window.location.href='employees.php'">
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                    <div class="stat-content"><div class="stat-value" id="statTotal">-</div><div class="stat-label">Colaboradores Ativos</div><div class="stat-change up" id="statTotalChange"><i class="fas fa-arrow-up"></i> +0 este mês</div></div>
                </div>
                <div class="stat-card" style="--card-color:#10b981" onclick="window.location.href='ponto-live.php'">
                    <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                    <div class="stat-content"><div class="stat-value" id="statJornada">-</div><div class="stat-label">Em Jornada Agora</div><div class="stat-change neutral"><i class="fas fa-clock"></i> Tempo real</div></div>
                </div>
                <div class="stat-card" style="--card-color:#f97316" onclick="window.location.href='ponto.php'">
                    <div class="stat-icon orange"><i class="fas fa-coffee"></i></div>
                    <div class="stat-content"><div class="stat-value" id="statIntervalo">-</div><div class="stat-label">Em Intervalo</div><div class="stat-change neutral"><i class="fas fa-utensils"></i> Almoço/Pausa</div></div>
                </div>
                <div class="stat-card" style="--card-color:#ec4899" onclick="window.location.href='ferias.php'">
                    <div class="stat-icon pink"><i class="fas fa-umbrella-beach"></i></div>
                    <div class="stat-content"><div class="stat-value" id="statFerias">-</div><div class="stat-label">Em Férias</div><div class="stat-change neutral" id="statFeriasChange"><i class="fas fa-calendar"></i> 0 próximas</div></div>
                </div>
                <div class="stat-card" style="--card-color:#ef4444">
                    <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-content"><div class="stat-value" id="statPendencias">-</div><div class="stat-label">Pendências</div><div class="stat-change down"><i class="fas fa-tasks"></i> Requer atenção</div></div>
                </div>
            </div>
            <div class="quick-actions">
                <button class="quick-btn primary" onclick="window.location.href='employee-register-wizard.php'"><i class="fas fa-user-plus"></i> Novo Colaborador</button>
                <button class="quick-btn" onclick="window.location.href='ponto.php'"><i class="fas fa-clock"></i> Registrar Ponto</button>
                <button class="quick-btn" onclick="window.location.href='ferias.php'"><i class="fas fa-umbrella-beach"></i> Aprovar Férias</button>
                <button class="quick-btn" onclick="window.location.href='relatorios-ponto.php'"><i class="fas fa-file-pdf"></i> Gerar Relatório</button>
                <button class="quick-btn" onclick="window.location.href='treinamentos.php'"><i class="fas fa-graduation-cap"></i> Treinamentos</button>
            </div>
            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-header"><div class="chart-title"><div class="chart-title-icon"><i class="fas fa-chart-area"></i></div>Registros de Ponto - Última Semana</div></div>
                    <div class="chart-body"><canvas id="pontoChart"></canvas></div>
                </div>
                <div class="alerts-card">
                    <div class="chart-header"><div class="chart-title"><div class="chart-title-icon"><i class="fas fa-bell"></i></div>Alertas & Pendências</div></div>
                    <div id="alertsList">
                        <div class="alert-item warning" onclick="window.location.href='ferias.php'"><div class="alert-icon"><i class="fas fa-umbrella-beach"></i></div><div class="alert-content"><div class="alert-title">Férias Pendentes</div><div class="alert-desc">Aguardando aprovação</div></div><div class="alert-count" id="alertFerias">0</div></div>
                        <div class="alert-item info" onclick="window.location.href='avaliacoes.php'"><div class="alert-icon"><i class="fas fa-clipboard-check"></i></div><div class="alert-content"><div class="alert-title">Avaliações Pendentes</div><div class="alert-desc">Aguardando conclusão</div></div><div class="alert-count" id="alertAvaliacoes">0</div></div>
                        <div class="alert-item danger" onclick="window.location.href='treinamentos.php'"><div class="alert-icon"><i class="fas fa-graduation-cap"></i></div><div class="alert-content"><div class="alert-title">Certificados Vencendo</div><div class="alert-desc">Próximos 30 dias</div></div><div class="alert-count" id="alertTreinamentos">0</div></div>
                        <div class="alert-item success" onclick="window.location.href='recrutamento.php'"><div class="alert-icon"><i class="fas fa-briefcase"></i></div><div class="alert-content"><div class="alert-title">Vagas Abertas</div><div class="alert-desc">Em processo seletivo</div></div><div class="alert-count" id="alertVagas">0</div></div>
                    </div>
                </div>
            </div>
            <div class="two-col">
                <div class="chart-card"><div class="chart-header"><div class="chart-title"><div class="chart-title-icon"><i class="fas fa-chart-pie"></i></div>Distribuição por CD</div></div><div class="chart-body"><canvas id="cdChart"></canvas></div></div>
                <div class="chart-card"><div class="chart-header"><div class="chart-title"><div class="chart-title-icon"><i class="fas fa-chart-bar"></i></div>Turnover Mensal</div></div><div class="chart-body"><canvas id="turnoverChart"></canvas></div></div>
            </div>
            <div class="three-col">
                <div class="kpi-card"><div class="kpi-header"><div class="kpi-title">Taxa de Turnover</div><div class="kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fas fa-door-open"></i></div></div><div class="kpi-value" id="kpiTurnover">0%</div><div class="kpi-subtitle">Taxa mensal atual</div><div class="progress-list"><div class="progress-item"><div class="progress-header"><span class="progress-label">Admissões</span><span class="progress-value" id="kpiAdmissoes">0</span></div><div class="progress-bar"><div class="progress-fill green" id="kpiAdmissoesBar" style="width:0%"></div></div></div><div class="progress-item"><div class="progress-header"><span class="progress-label">Demissões</span><span class="progress-value" id="kpiDemissoes">0</span></div><div class="progress-bar"><div class="progress-fill orange" id="kpiDemissoesBar" style="width:0%"></div></div></div></div></div>
                <div class="kpi-card"><div class="kpi-header"><div class="kpi-title">Treinamentos</div><div class="kpi-icon" style="background:#dbeafe;color:#2563eb"><i class="fas fa-graduation-cap"></i></div></div><div class="kpi-value" id="kpiTreinados">0</div><div class="kpi-subtitle">Cursos ativos</div><div class="progress-list"><div class="progress-item"><div class="progress-header"><span class="progress-label">Em Andamento</span><span class="progress-value" id="kpiTreinAndamento">0</span></div><div class="progress-bar"><div class="progress-fill blue" id="kpiTreinAndamentoBar" style="width:0%"></div></div></div><div class="progress-item"><div class="progress-header"><span class="progress-label">Vencendo em 30 dias</span><span class="progress-value" id="kpiTreinVencendo">0</span></div><div class="progress-bar"><div class="progress-fill orange" id="kpiTreinVencendoBar" style="width:0%"></div></div></div></div></div>
                <div class="kpi-card"><div class="kpi-header"><div class="kpi-title">Recrutamento</div><div class="kpi-icon" style="background:#f3e8ff;color:#9333ea"><i class="fas fa-users"></i></div></div><div class="kpi-value" id="kpiVagas">0</div><div class="kpi-subtitle">Vagas em aberto</div><div class="progress-list"><div class="progress-item"><div class="progress-header"><span class="progress-label">Candidatos Ativos</span><span class="progress-value" id="kpiCandidatos">0</span></div><div class="progress-bar"><div class="progress-fill purple" id="kpiCandidatosBar" style="width:0%"></div></div></div><div class="progress-item"><div class="progress-header"><span class="progress-label">Vagas Urgentes</span><span class="progress-value" id="kpiUrgentes">0</span></div><div class="progress-bar"><div class="progress-fill orange" id="kpiUrgentesBar" style="width:0%"></div></div></div></div></div>
            </div>
            <div class="section">
                <div class="section-header"><div class="section-title"><div class="section-title-icon"><i class="fas fa-th-large"></i></div>Módulos do Sistema</div><span class="section-badge count">15 MÓDULOS</span></div>
                <div class="modules-grid">
                    <div class="module-card" style="--module-color:#3b82f6" onclick="window.location.href='employees.php'"><div class="module-header"><div class="module-icon"><i class="fas fa-users"></i></div><span class="module-status active">Ativo</span></div><div class="module-title">👥 Colaboradores</div><div class="module-desc">Gestão completa de funcionários</div><div class="module-stats"><div class="module-stat"><div class="module-stat-value" id="modColabTotal">-</div><div class="module-stat-label">Total</div></div><div class="module-stat"><div class="module-stat-value" id="modColabAtivos">-</div><div class="module-stat-label">Ativos</div></div><div class="module-stat"><div class="module-stat-value" id="modColabNovos">-</div><div class="module-stat-label">Novos</div></div></div></div>
                    <div class="module-card" style="--module-color:#10b981" onclick="window.location.href='ponto.php'"><div class="module-header"><div class="module-icon"><i class="fas fa-clock"></i></div><span class="module-status active">Ativo</span></div><div class="module-title">⏰ Controle de Ponto</div><div class="module-desc">Entrada, saída e intervalos</div><div class="module-stats"><div class="module-stat"><div class="module-stat-value" id="modPontoHoje">-</div><div class="module-stat-label">Registros</div></div><div class="module-stat"><div class="module-stat-value" id="modPontoAtivos">-</div><div class="module-stat-label">Ativos</div></div></div></div>
                    <div class="module-card" style="--module-color:#ec4899" onclick="window.location.href='ferias.php'"><div class="module-header"><div class="module-icon"><i class="fas fa-umbrella-beach"></i></div><span class="module-status" id="modFeriasStatus">Ativo</span></div><div class="module-title">🏖️ Férias</div><div class="module-desc">Solicitações e aprovações</div><div class="module-stats"><div class="module-stat"><div class="module-stat-value" id="modFeriasPend">-</div><div class="module-stat-label">Pendentes</div></div><div class="module-stat"><div class="module-stat-value" id="modFeriasAtual">-</div><div class="module-stat-label">Em Férias</div></div><div class="module-stat"><div class="module-stat-value" id="modFeriasProx">-</div><div class="module-stat-label">Próximas</div></div></div></div>
                    <div class="module-card" style="--module-color:#06b6d4" onclick="window.location.href='treinamentos.php'"><div class="module-header"><div class="module-icon"><i class="fas fa-graduation-cap"></i></div><span class="module-status active">Ativo</span></div><div class="module-title">🎓 Treinamentos</div><div class="module-desc">Cursos e certificações</div><div class="module-stats"><div class="module-stat"><div class="module-stat-value" id="modTreinCursos">-</div><div class="module-stat-label">Cursos</div></div><div class="module-stat"><div class="module-stat-value" id="modTreinAtivos">-</div><div class="module-stat-label">Em Treino</div></div></div></div>
                    <div class="module-card" style="--module-color:#f59e0b" onclick="window.location.href='avaliacoes.php'"><div class="module-header"><div class="module-icon"><i class="fas fa-chart-line"></i></div><span class="module-status" id="modAvalStatus">Ativo</span></div><div class="module-title">📋 Avaliações</div><div class="module-desc">Desempenho e feedback</div><div class="module-stats"><div class="module-stat"><div class="module-stat-value" id="modAvalPend">-</div><div class="module-stat-label">Pendentes</div></div><div class="module-stat"><div class="module-stat-value" id="modAvalConc">-</div><div class="module-stat-label">Concluídas</div></div></div></div>
                    <div class="module-card" style="--module-color:#8b5cf6" onclick="window.location.href='recrutamento.php'"><div class="module-header"><div class="module-icon"><i class="fas fa-user-plus"></i></div><span class="module-status active">Ativo</span></div><div class="module-title">👥 Recrutamento</div><div class="module-desc">Vagas e candidatos</div><div class="module-stats"><div class="module-stat"><div class="module-stat-value" id="modRecVagas">-</div><div class="module-stat-label">Vagas</div></div><div class="module-stat"><div class="module-stat-value" id="modRecCand">-</div><div class="module-stat-label">Candidatos</div></div></div></div>
                </div>
            </div>
            <div class="two-col">
                <div class="activity-card"><div class="chart-header"><div class="chart-title"><div class="chart-title-icon"><i class="fas fa-stream"></i></div>Atividade em Tempo Real</div><span class="section-badge live">🔴 AO VIVO</span></div><div class="activity-list" id="activityList"><div style="text-align:center;padding:60px 20px;color:var(--gray-400)"><i class="fas fa-spinner fa-spin" style="font-size:32px"></i><p style="margin-top:16px;font-size:14px">Carregando...</p></div></div></div>
                <div class="chart-card"><div class="chart-header"><div class="chart-title"><div class="chart-title-icon"><i class="fas fa-building"></i></div>Por Departamento</div></div><div class="chart-body small"><canvas id="deptChart"></canvas></div></div>
            </div>
        </div>
    </main>
</div>
<div class="fab" id="fab">
    <div class="fab-items">
        <button class="fab-item green" onclick="window.location.href='chat.php'" title="Chat"><i class="fas fa-comments"></i></button>
        <button class="fab-item blue" onclick="window.location.href='ponto.php'" title="Ponto"><i class="fas fa-clock"></i></button>
        <button class="fab-item orange" onclick="window.location.href='employee-register-wizard.php'" title="Novo"><i class="fas fa-user-plus"></i></button>
        <button class="fab-item purple" onclick="window.location.href='relatorios-ponto.php'" title="Relatórios"><i class="fas fa-chart-bar"></i></button>
    </div>
    <button class="fab-main" onclick="toggleFab()"><i class="fas fa-plus"></i></button>
</div>
<script>
let pontoChart=null,cdChart=null,turnoverChart=null,deptChart=null;

function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open')}
function toggleFab(){const f=document.getElementById('fab');f.classList.toggle('open');f.querySelector('.fab-main').classList.toggle('open')}
function logout(){localStorage.clear();sessionStorage.clear();window.location.href='login.html'}

// ========== FUNÇÕES DO MERCADO ==========
function verWorker(id) {
    window.location.href = 'mercado-worker-detalhes.html?id=' + id;
}

function aprovarWorker(id) {
    if (confirm('Aprovar este cadastro?')) {
        fetch('./api/worker-approval.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ worker_id: id, action: 'approve' })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Worker aprovado com sucesso!');
                location.reload();
            } else {
                alert('Erro: ' + data.message);
            }
        });
    }
}

function rejeitarWorker(id) {
    const motivo = prompt('Motivo da rejeição:');
    if (motivo) {
        fetch('./api/worker-approval.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ worker_id: id, action: 'reject', reason: motivo })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Worker rejeitado.');
                location.reload();
            } else {
                alert('Erro: ' + data.message);
            }
        });
    }
}

async function loadAllStats(){
    try{const r=await fetch('./api/dashboard-stats-all.php?action=all');const d=await r.json();if(d.success)updateUI(d)}catch(e){console.error('Erro:',e)}
}

function updateUI(d){
    let totalPend=0;
    if(d.colaboradores){const c=d.colaboradores;document.getElementById('statTotal').textContent=c.ativos||0;document.getElementById('welcomeAtivos').textContent=c.ativos||0;document.getElementById('statTotalChange').innerHTML='<i class="fas fa-arrow-up"></i> +'+(c.novos_mes||0)+' este mês';document.getElementById('modColabTotal').textContent=c.total||0;document.getElementById('modColabAtivos').textContent=c.ativos||0;document.getElementById('modColabNovos').textContent=c.novos_mes||0;document.getElementById('kpiAdmissoes').textContent=c.novos_mes||0}
    if(d.ponto){const p=d.ponto;document.getElementById('statJornada').textContent=p.em_jornada||0;document.getElementById('welcomeJornada').textContent=p.em_jornada||0;document.getElementById('statIntervalo').textContent=p.em_intervalo||0;document.getElementById('modPontoHoje').textContent=p.registros_hoje||0;document.getElementById('modPontoAtivos').textContent=p.em_jornada||0}
    if(d.ferias){const f=d.ferias;totalPend+=f.pendentes||0;document.getElementById('statFerias').textContent=f.em_ferias||0;document.getElementById('statFeriasChange').innerHTML='<i class="fas fa-calendar"></i> '+(f.proximas_30_dias||0)+' próximas';document.getElementById('modFeriasPend').textContent=f.pendentes||0;document.getElementById('modFeriasAtual').textContent=f.em_ferias||0;document.getElementById('modFeriasProx').textContent=f.proximas_30_dias||0;document.getElementById('navFeriasBadge').textContent=f.pendentes||0;document.getElementById('alertFerias').textContent=f.pendentes||0;const st=document.getElementById('modFeriasStatus');if(f.pendentes>0){st.className='module-status pending';st.textContent='Pendente'}else{st.className='module-status active';st.textContent='Ativo'}}
    if(d.avaliacoes){const a=d.avaliacoes;totalPend+=a.pendentes||0;document.getElementById('modAvalPend').textContent=a.pendentes||0;document.getElementById('modAvalConc').textContent=a.concluidas||0;document.getElementById('alertAvaliacoes').textContent=a.pendentes||0;const st=document.getElementById('modAvalStatus');if(a.pendentes>0){st.className='module-status pending';st.textContent='Pendente'}else{st.className='module-status active';st.textContent='Ativo'}}
    if(d.treinamentos){const t=d.treinamentos;document.getElementById('modTreinCursos').textContent=t.cursos_ativos||0;document.getElementById('modTreinAtivos').textContent=t.em_treinamento||0;document.getElementById('kpiTreinados').textContent=t.cursos_ativos||0;document.getElementById('kpiTreinAndamento').textContent=t.em_treinamento||0;document.getElementById('kpiTreinVencendo').textContent=t.vencendo||0;document.getElementById('alertTreinamentos').textContent=t.vencendo||0}
    if(d.recrutamento){const r=d.recrutamento;document.getElementById('modRecVagas').textContent=r.vagas_abertas||0;document.getElementById('modRecCand').textContent=r.candidatos||0;document.getElementById('kpiVagas').textContent=r.vagas_abertas||0;document.getElementById('kpiCandidatos').textContent=r.candidatos||0;document.getElementById('kpiUrgentes').textContent=r.urgentes||0;document.getElementById('alertVagas').textContent=r.vagas_abertas||0}
    if(d.chat){document.getElementById('navChatBadge').textContent=d.chat.nao_lidas||0;document.getElementById('headerChatBadge').textContent=d.chat.nao_lidas||0}
    document.getElementById('statPendencias').textContent=totalPend;document.getElementById('welcomePendencias').textContent=totalPend;document.getElementById('headerNotifBadge').textContent=totalPend;
    if(d.charts)updateCharts(d.charts);
    if(d.atividade)updateActivity(d.atividade);
}

function updateCharts(c){
    const opts={responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{padding:20,usePointStyle:true,font:{size:12,family:'Inter'}}}}};
    const pCtx=document.getElementById('pontoChart').getContext('2d');if(pontoChart)pontoChart.destroy();
    const labels=c.ponto_semanal?.map(x=>x.dia)||['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];
    const entradas=c.ponto_semanal?.map(x=>x.entradas)||[0,0,0,0,0,0,0];
    const saidas=c.ponto_semanal?.map(x=>x.saidas)||[0,0,0,0,0,0,0];
    pontoChart=new Chart(pCtx,{type:'line',data:{labels,datasets:[{label:'Entradas',data:entradas,borderColor:'#10b981',backgroundColor:'rgba(16,185,129,.1)',fill:true,tension:.4,pointRadius:6,pointBackgroundColor:'#10b981',pointBorderColor:'#fff',pointBorderWidth:2},{label:'Saídas',data:saidas,borderColor:'#ef4444',backgroundColor:'rgba(239,68,68,.1)',fill:true,tension:.4,pointRadius:6,pointBackgroundColor:'#ef4444',pointBorderColor:'#fff',pointBorderWidth:2}]},options:{...opts,scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.05)'}},x:{grid:{display:false}}}}});
    const cCtx=document.getElementById('cdChart').getContext('2d');if(cdChart)cdChart.destroy();
    const cdL=c.por_cd?.map(x=>(x.cd_name||'CD').substring(0,15))||['SP','RJ','MG','PR','RS'];
    const cdV=c.por_cd?.map(x=>x.total)||[0,0,0,0,0];
    cdChart=new Chart(cCtx,{type:'doughnut',data:{labels:cdL,datasets:[{data:cdV,backgroundColor:['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ec4899','#06b6d4','#64748b'],borderWidth:0,hoverOffset:8}]},options:{...opts,cutout:'60%'}});
    const tCtx=document.getElementById('turnoverChart').getContext('2d');if(turnoverChart)turnoverChart.destroy();
    const tL=c.turnover?.map(x=>x.mes)||['Jan','Fev','Mar','Abr','Mai','Jun'];
    const adm=c.turnover?.map(x=>x.admissoes)||[0,0,0,0,0,0];
    const dem=c.turnover?.map(x=>x.demissoes)||[0,0,0,0,0,0];
    turnoverChart=new Chart(tCtx,{type:'bar',data:{labels:tL,datasets:[{label:'Admissões',data:adm,backgroundColor:'#10b981',borderRadius:6},{label:'Demissões',data:dem,backgroundColor:'#ef4444',borderRadius:6}]},options:{...opts,scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.05)'}},x:{grid:{display:false}}}}});
    const dCtx=document.getElementById('deptChart').getContext('2d');if(deptChart)deptChart.destroy();
    const dL=c.por_departamento?.map(x=>x.dept_name)||['Operações','Administrativo','Comercial','TI','RH'];
    const dV=c.por_departamento?.map(x=>x.total)||[0,0,0,0,0];
    deptChart=new Chart(dCtx,{type:'polarArea',data:{labels:dL,datasets:[{data:dV,backgroundColor:['rgba(59,130,246,.75)','rgba(16,185,129,.75)','rgba(245,158,11,.75)','rgba(139,92,246,.75)','rgba(236,72,153,.75)']}]},options:opts});
}

function updateActivity(activities){
    const c=document.getElementById('activityList');
    if(!activities||!activities.length){c.innerHTML='<div style="text-align:center;padding:60px 20px;color:var(--gray-400)"><i class="fas fa-clock" style="font-size:40px;margin-bottom:16px"></i><p style="font-size:14px">Nenhuma atividade recente</p></div>';return}
    c.innerHTML=activities.map(a=>{const t=a.type||(a.action?.includes('entrada')?'entrada':a.action?.includes('saída')?'saida':a.action?.includes('intervalo')?'intervalo':'retorno');return '<div class="activity-item"><div class="activity-avatar '+t+'">'+(a.initials||'NN')+'</div><div class="activity-content"><div class="activity-name">'+(a.name||'Colaborador')+'</div><div class="activity-action">'+(a.action||'')+'</div></div><div class="activity-time">'+(a.time||'--:--')+'</div></div>'}).join('')}

document.getElementById('globalSearch')?.addEventListener('keypress',function(e){if(e.key==='Enter'&&this.value.trim())window.location.href='employees.php?search='+encodeURIComponent(this.value.trim())});

document.addEventListener('DOMContentLoaded',function(){loadAllStats();setInterval(loadAllStats,30000)});
</script>
</body>
</html>
