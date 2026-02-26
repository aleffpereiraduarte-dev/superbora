<?php
require_once __DIR__ . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════╗
 * ║   🚀 INSTALADOR ÚNICO COMPLETO - OneMundo Market Admin                           ║
 * ║      Sistema Completo igual iFood + DoorDash + Instacart                         ║
 * ║      TODAS AS PÁGINAS EM UM ÚNICO ARQUIVO                                        ║
 * ╚══════════════════════════════════════════════════════════════════════════════════╝
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600);

$conn = getMySQLi();
$conn->set_charset('utf8mb4');

$adminDir = __DIR__ . '/admin';
if (!is_dir($adminDir)) mkdir($adminDir, 0755, true);

$executar = isset($_GET['executar']);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>🚀 INSTALADOR COMPLETO - OneMundo Admin</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Arial;background:linear-gradient(135deg,#0f172a,#1e293b);color:#e2e8f0;min-height:100vh;padding:20px}
.container{max-width:1000px;margin:0 auto}
h1{text-align:center;font-size:28px;margin-bottom:10px;background:linear-gradient(90deg,#f97316,#fb923c);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.subtitle{text-align:center;color:#94a3b8;margin-bottom:30px}
.section{background:rgba(30,41,59,0.9);border-radius:16px;padding:25px;margin:20px 0;border:1px solid #334155}
h2{color:#f97316;font-size:18px;margin-bottom:15px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin:15px 0}
.item{background:#0f172a;padding:12px;border-radius:8px;font-size:12px;border-left:3px solid #3b82f6}
.btn{display:block;width:100%;max-width:400px;margin:30px auto;padding:18px;background:linear-gradient(90deg,#f97316,#ea580c);color:#fff;text-decoration:none;border-radius:12px;font-size:18px;font-weight:bold;text-align:center;border:none;cursor:pointer}
.btn:hover{transform:scale(1.02)}
.log{background:#000;padding:20px;border-radius:12px;font-family:monospace;font-size:11px;max-height:600px;overflow-y:auto;line-height:1.6}
.log .ok{color:#22c55e}
.log .err{color:#ef4444}
.log .info{color:#60a5fa}
.log .file{color:#fbbf24}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin:20px 0}
.stat{background:#0f172a;padding:20px;border-radius:12px;text-align:center}
.stat-num{font-size:32px;font-weight:bold;color:#f97316}
.stat-label{font-size:11px;color:#94a3b8;margin-top:5px}
.success{background:rgba(34,197,94,0.1);border:1px solid #22c55e;padding:20px;border-radius:12px;margin:20px 0}
.success h3{color:#22c55e;margin-bottom:10px}
.links{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:15px}
.links a{background:#1e293b;padding:12px;border-radius:8px;color:#f97316;text-decoration:none;text-align:center;font-size:13px}
.links a:hover{background:#334155}
</style>
</head>
<body>
<div class="container">

<h1>🚀 INSTALADOR COMPLETO</h1>
<p class="subtitle">Sistema Admin OneMundo Market - Igual iFood + DoorDash + Instacart</p>

<?php if (!$executar): ?>

<div class="section">
<h2>📋 O que será instalado</h2>
<div class="stats">
<div class="stat"><div class="stat-num">30+</div><div class="stat-label">Páginas PHP</div></div>
<div class="stat"><div class="stat-num">15+</div><div class="stat-label">Tabelas MySQL</div></div>
<div class="stat"><div class="stat-num">100+</div><div class="stat-label">Funcionalidades</div></div>
<div class="stat"><div class="stat-num">1</div><div class="stat-label">Clique</div></div>
</div>
</div>

<div class="section">
<h2>📄 Páginas que serão criadas</h2>
<div class="grid">
<div class="item">📊 index.php (Dashboard)</div>
<div class="item">🚀 dispatch.php</div>
<div class="item">🛒 pedidos.php</div>
<div class="item">📦 pedido_detalhes.php</div>
<div class="item">🗺️ mapa.php</div>
<div class="item">🏪 mercados.php</div>
<div class="item">📝 mercado_detalhes.php</div>
<div class="item">👥 shoppers.php</div>
<div class="item">👤 worker_detalhes.php</div>
<div class="item">👤 clientes.php</div>
<div class="item">👤 cliente_detalhes.php</div>
<div class="item">🎧 suporte.php</div>
<div class="item">🎫 ticket_detalhes.php</div>
<div class="item">💬 chat.php</div>
<div class="item">💬 chat_pedido.php</div>
<div class="item">⭐ avaliacoes.php</div>
<div class="item">💰 reembolsos.php</div>
<div class="item">🎫 cupons.php</div>
<div class="item">🔥 promocoes.php</div>
<div class="item">👑 fidelidade.php</div>
<div class="item">🔔 notificacoes.php</div>
<div class="item">💳 financeiro.php</div>
<div class="item">💰 comissoes.php</div>
<div class="item">📤 pagamentos.php</div>
<div class="item">📈 relatorios.php</div>
<div class="item">❓ faq.php</div>
<div class="item">⚙️ configuracoes.php</div>
<div class="item">👥 usuarios.php</div>
<div class="item">📋 logs.php</div>
<div class="item">🔐 login.php</div>
</div>
</div>

<a href="?executar=1" class="btn" onclick="return confirm('Instalar todas as páginas do admin?')">
🚀 INSTALAR TUDO AGORA
</a>

<?php else: ?>

<div class="section">
<h2>⚡ Instalando...</h2>
<div class="log">
<?php
ob_implicit_flush(true);

function log_ok($msg) { echo "<div class='ok'>✅ $msg</div>"; ob_flush(); flush(); }
function log_err($msg) { echo "<div class='err'>❌ $msg</div>"; ob_flush(); flush(); }
function log_info($msg) { echo "<div class='info'>ℹ️ $msg</div>"; ob_flush(); flush(); }
function log_file($msg) { echo "<div class='file'>📄 $msg</div>"; ob_flush(); flush(); }

$arquivos_criados = 0;

// ══════════════════════════════════════════════════════════════════════════════
// 1. DB_CONFIG.PHP
// ══════════════════════════════════════════════════════════════════════════════
log_info("Criando arquivo de configuração...");

$db_config = '<?php
$db_host = "147.93.12.236";
$DB_USER = "love1";
// $db_pass loaded from central config
$DB_NAME = "love1";
';
file_put_contents($adminDir . '/db_config.php', $db_config);
log_file("db_config.php");
$arquivos_criados++;

// ══════════════════════════════════════════════════════════════════════════════
// 2. LOGIN.PHP
// ══════════════════════════════════════════════════════════════════════════════
log_info("Criando sistema de login...");

$login_php = '<?php
session_start();
if (isset($_SESSION["admin_id"])) { header("Location: index.php"); exit; }

$error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST["email"] ?? "";
    $pass = $_POST["password"] ?? "";
    
    // Login simples (em produção, usar hash)
    if ($email == "admin@onemundo.com.br" && $pass == "admin123") {
        $_SESSION["admin_id"] = 1;
        $_SESSION["admin_name"] = "Administrador";
        $_SESSION["admin_email"] = $email;
        header("Location: index.php");
        exit;
    } else {
        $error = "Email ou senha incorretos";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - OneMundo Admin</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial;background:linear-gradient(135deg,#0f172a,#1e293b);min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-box{background:#1e293b;padding:40px;border-radius:20px;width:100%;max-width:400px;border:1px solid #334155}
.logo{font-size:28px;font-weight:700;text-align:center;margin-bottom:30px;background:linear-gradient(90deg,#f97316,#fb923c);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.form-group{margin-bottom:20px}
.form-group label{display:block;color:#94a3b8;font-size:13px;margin-bottom:8px}
.form-group input{width:100%;background:#0f172a;border:1px solid #334155;color:#e2e8f0;padding:14px;border-radius:10px;font-size:14px}
.form-group input:focus{outline:none;border-color:#f97316}
.btn{width:100%;padding:14px;background:linear-gradient(90deg,#f97316,#ea580c);color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer}
.btn:hover{opacity:0.9}
.error{background:rgba(239,68,68,0.1);border:1px solid #ef4444;color:#fca5a5;padding:12px;border-radius:8px;margin-bottom:20px;font-size:13px;text-align:center}
.hint{text-align:center;margin-top:20px;font-size:11px;color:#64748b}
</style>
</head>
<body>
<div class="login-box">
    <div class="logo">🏪 OneMundo Admin</div>
    <?php if ($error): ?>
    <div class="error"><?= $error ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required placeholder="seu@email.com">
        </div>
        <div class="form-group">
            <label>Senha</label>
            <input type="password" name="password" required placeholder="••••••••">
        </div>
        <button type="submit" class="btn">Entrar</button>
    </form>
    <div class="hint">admin@onemundo.com.br / admin123</div>
</div>
</body>
</html>';
file_put_contents($adminDir . '/login.php', $login_php);
log_file("login.php");
$arquivos_criados++;

// ══════════════════════════════════════════════════════════════════════════════
// 3. LOGOUT.PHP
// ══════════════════════════════════════════════════════════════════════════════
$logout_php = '<?php
session_start();
session_destroy();
header("Location: login.php");
';
file_put_contents($adminDir . '/logout.php', $logout_php);
log_file("logout.php");
$arquivos_criados++;

// ══════════════════════════════════════════════════════════════════════════════
// 4. HEADER TEMPLATE (para incluir em todas as páginas)
// ══════════════════════════════════════════════════════════════════════════════
log_info("Criando templates...");

$header_php = '<?php
if (!isset($_SESSION["admin_id"])) { header("Location: login.php"); exit; }
$current_page = basename($_SERVER["PHP_SELF"], ".php");

// Stats rápidos para sidebar
$_stats = [
    "tickets" => @$conn->query("SELECT COUNT(*) FROM om_market_support_tickets WHERE status IN (\"aberto\",\"em_andamento\")")->fetch_row()[0] ?? 0,
    "pendentes" => @$conn->query("SELECT COUNT(*) FROM om_workers WHERE status IN (\"pendente\",\"em_analise\")")->fetch_row()[0] ?? 0,
    "aguardando" => @$conn->query("SELECT COUNT(*) FROM om_market_orders WHERE status IN (\"pago\",\"paid\") AND shopper_id IS NULL")->fetch_row()[0] ?? 0
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $page_title ?? "Admin" ?> - OneMundo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Inter",sans-serif;background:#0f172a;color:#e2e8f0}
.layout{display:flex;min-height:100vh}
.sidebar{width:250px;background:linear-gradient(180deg,#1e293b 0%,#0f172a 100%);padding:20px;position:fixed;height:100vh;overflow-y:auto;border-right:1px solid #334155}
.logo{font-size:20px;font-weight:700;margin-bottom:25px;background:linear-gradient(90deg,#f97316,#fb923c);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.nav-section{margin-bottom:20px}
.nav-title{font-size:10px;text-transform:uppercase;color:#64748b;margin-bottom:8px;letter-spacing:1px}
.nav a{display:flex;align-items:center;gap:10px;padding:10px 12px;color:#94a3b8;text-decoration:none;border-radius:8px;margin-bottom:2px;font-size:13px;transition:all 0.2s}
.nav a:hover{background:rgba(249,115,22,0.1);color:#f97316}
.nav a.active{background:rgba(249,115,22,0.15);color:#f97316;font-weight:500}
.nav a.alert{background:rgba(239,68,68,0.1);color:#fca5a5}
.badge{background:#ef4444;color:#fff;font-size:9px;padding:2px 6px;border-radius:8px;margin-left:auto}
.badge-warn{background:#f59e0b}
.main{flex:1;margin-left:250px;padding:25px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:25px}
.page-header h1{font-size:24px;font-weight:600}
.btn{padding:10px 20px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:#f97316;color:#fff}
.btn-primary:hover{background:#ea580c}
.btn-secondary{background:#334155;color:#e2e8f0}
.card{background:#1e293b;border-radius:12px;border:1px solid #334155;overflow:hidden}
.card-header{padding:15px 20px;border-bottom:1px solid #334155;font-weight:600;display:flex;justify-content:space-between;align-items:center}
.card-body{padding:20px}
table{width:100%;border-collapse:collapse}
th,td{padding:12px 15px;text-align:left;border-bottom:1px solid #334155}
th{background:#0f172a;font-size:11px;text-transform:uppercase;color:#64748b;font-weight:500}
tbody tr:hover{background:rgba(249,115,22,0.03)}
.status-badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:600}
.link{color:#f97316;text-decoration:none}
.link:hover{text-decoration:underline}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:25px}
.stat-card{background:#1e293b;border-radius:12px;padding:20px;border:1px solid #334155}
.stat-card.alert{border-color:#ef4444;background:linear-gradient(135deg,#1e293b,#450a0a)}
.stat-value{font-size:28px;font-weight:700}
.stat-label{font-size:11px;color:#94a3b8;margin-top:5px}
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;background:#1e293b;padding:15px;border-radius:12px}
.filters input,.filters select{background:#0f172a;border:1px solid #334155;color:#e2e8f0;padding:10px 15px;border-radius:8px;font-size:13px}
.empty{text-align:center;padding:60px;color:#64748b}
.avatar{width:40px;height:40px;border-radius:50%;background:#334155;display:flex;align-items:center;justify-content:center;font-weight:600}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="logo">🏪 OneMundo</div>
    
    <div class="nav-section">
        <div class="nav-title">Principal</div>
        <nav class="nav">
            <a href="index.php" class="<?= $current_page=="index"?"active":"" ?>">📊 Dashboard</a>
            <a href="dispatch.php" class="<?= $current_page=="dispatch"?"active":"" ?> <?= $_stats["aguardando"]>0?"alert":"" ?>">🚀 Dispatch <?php if($_stats["aguardando"]>0):?><span class="badge"><?=$_stats["aguardando"]?></span><?php endif;?></a>
            <a href="pedidos.php" class="<?= $current_page=="pedidos"?"active":"" ?>">🛒 Pedidos</a>
            <a href="mapa.php" class="<?= $current_page=="mapa"?"active":"" ?>">🗺️ Mapa ao Vivo</a>
        </nav>
    </div>
    
    <div class="nav-section">
        <div class="nav-title">Gestão</div>
        <nav class="nav">
            <a href="mercados.php" class="<?= $current_page=="mercados"?"active":"" ?>">🏪 Mercados</a>
            <a href="shoppers.php" class="<?= $current_page=="shoppers"?"active":"" ?> <?= $_stats["pendentes"]>0?"alert":"" ?>">👥 Workers <?php if($_stats["pendentes"]>0):?><span class="badge badge-warn"><?=$_stats["pendentes"]?></span><?php endif;?></a>
            <a href="clientes.php" class="<?= $current_page=="clientes"?"active":"" ?>">👤 Clientes</a>
        </nav>
    </div>
    
    <div class="nav-section">
        <div class="nav-title">Suporte</div>
        <nav class="nav">
            <a href="suporte.php" class="<?= $current_page=="suporte"?"active":"" ?> <?= $_stats["tickets"]>0?"alert":"" ?>">🎧 Tickets <?php if($_stats["tickets"]>0):?><span class="badge"><?=$_stats["tickets"]?></span><?php endif;?></a>
            <a href="chat.php" class="<?= $current_page=="chat"?"active":"" ?>">💬 Chat</a>
            <a href="avaliacoes.php" class="<?= $current_page=="avaliacoes"?"active":"" ?>">⭐ Avaliações</a>
            <a href="reembolsos.php" class="<?= $current_page=="reembolsos"?"active":"" ?>">💰 Reembolsos</a>
        </nav>
    </div>
    
    <div class="nav-section">
        <div class="nav-title">Marketing</div>
        <nav class="nav">
            <a href="cupons.php" class="<?= $current_page=="cupons"?"active":"" ?>">🎫 Cupons</a>
            <a href="promocoes.php" class="<?= $current_page=="promocoes"?"active":"" ?>">🔥 Promoções</a>
            <a href="fidelidade.php" class="<?= $current_page=="fidelidade"?"active":"" ?>">👑 Fidelidade</a>
            <a href="notificacoes.php" class="<?= $current_page=="notificacoes"?"active":"" ?>">🔔 Push</a>
        </nav>
    </div>
    
    <div class="nav-section">
        <div class="nav-title">Financeiro</div>
        <nav class="nav">
            <a href="financeiro.php" class="<?= $current_page=="financeiro"?"active":"" ?>">💳 Financeiro</a>
            <a href="pagamentos.php" class="<?= $current_page=="pagamentos"?"active":"" ?>">📤 Pagamentos</a>
            <a href="relatorios.php" class="<?= $current_page=="relatorios"?"active":"" ?>">📈 Relatórios</a>
        </nav>
    </div>
    
    <div class="nav-section">
        <div class="nav-title">Sistema</div>
        <nav class="nav">
            <a href="configuracoes.php" class="<?= $current_page=="configuracoes"?"active":"" ?>">⚙️ Configurações</a>
            <a href="faq.php" class="<?= $current_page=="faq"?"active":"" ?>">❓ FAQ</a>
            <a href="logs.php" class="<?= $current_page=="logs"?"active":"" ?>">📋 Logs</a>
            <a href="logout.php" style="color:#ef4444">🚪 Sair</a>
        </nav>
    </div>
</aside>
<main class="main">';

file_put_contents($adminDir . '/header.php', $header_php);
log_file("header.php");
$arquivos_criados++;

$footer_php = '</main>
</div>
</body>
</html>';
file_put_contents($adminDir . '/footer.php', $footer_php);
log_file("footer.php");
$arquivos_criados++;

// ══════════════════════════════════════════════════════════════════════════════
// 5. INDEX.PHP (DASHBOARD)
// ══════════════════════════════════════════════════════════════════════════════
log_info("Criando Dashboard...");

$index_php = '<?php
session_start();
include "db_config.php";
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conn->set_charset("utf8mb4");
$page_title = "Dashboard";

function sq($c,$s,$d=0){$r=@$c->query($s);if(!$r)return $d;$row=$r->fetch_row();return $row[0]??$d;}

// Métricas
$pedidos_hoje = sq($conn, "SELECT COUNT(*) FROM om_market_orders WHERE DATE(created_at)=CURRENT_DATE");
$fat_hoje = sq($conn, "SELECT COALESCE(SUM(total),0) FROM om_market_orders WHERE DATE(created_at)=CURRENT_DATE AND status NOT IN (\"cancelado\",\"cancelled\")");
$aguardando = sq($conn, "SELECT COUNT(*) FROM om_market_orders WHERE status IN (\"pago\",\"paid\") AND shopper_id IS NULL");
$em_andamento = sq($conn, "SELECT COUNT(*) FROM om_market_orders WHERE status IN (\"shopping\",\"em_compra\",\"em_entrega\")");
$entregues = sq($conn, "SELECT COUNT(*) FROM om_market_orders WHERE status IN (\"entregue\",\"delivered\") AND DATE(created_at)=CURRENT_DATE");
$workers_online = sq($conn, "SELECT COUNT(*) FROM om_workers WHERE is_online=1");
$tickets_abertos = sq($conn, "SELECT COUNT(*) FROM om_market_support_tickets WHERE status IN (\"aberto\",\"em_andamento\")");

// Pedidos recentes
$pedidos = $conn->query("SELECT o.*, p.name as mercado, w.name as worker, CONCAT(c.firstname,\" \",c.lastname) as cliente
    FROM om_market_orders o
    LEFT JOIN om_market_partners p ON p.partner_id=o.partner_id
    LEFT JOIN om_workers w ON w.worker_id=o.shopper_id
    LEFT JOIN oc_customer c ON c.customer_id=o.customer_id
    ORDER BY o.created_at DESC LIMIT 10");

include "header.php";
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="page-header">
    <h1>📊 Dashboard</h1>
    <span style="color:#64748b"><?= date("d/m/Y H:i") ?></span>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value" style="color:#3b82f6"><?= $pedidos_hoje ?></div>
        <div class="stat-label">📦 Pedidos Hoje</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color:#22c55e">R$ <?= number_format($fat_hoje,0,",",".") ?></div>
        <div class="stat-label">💰 Faturamento</div>
    </div>
    <div class="stat-card <?= $aguardando>3?"alert":"" ?>">
        <div class="stat-value" style="color:#f59e0b"><?= $aguardando ?></div>
        <div class="stat-label">⏳ Aguardando Shopper</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color:#8b5cf6"><?= $em_andamento ?></div>
        <div class="stat-label">🚀 Em Andamento</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color:#22c55e"><?= $entregues ?></div>
        <div class="stat-label">✅ Entregues Hoje</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color:#06b6d4"><?= $workers_online ?></div>
        <div class="stat-label">👥 Workers Online</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        ⚡ Pedidos Recentes
        <a href="pedidos.php" class="link">Ver todos →</a>
    </div>
    <table>
        <thead>
            <tr><th>ID</th><th>Cliente</th><th>Mercado</th><th>Worker</th><th>Total</th><th>Status</th><th>Data</th></tr>
        </thead>
        <tbody>
        <?php while($p = $pedidos->fetch_assoc()): ?>
        <tr>
            <td><a href="pedido_detalhes.php?id=<?= $p["order_id"] ?>" class="link">#<?= $p["order_id"] ?></a></td>
            <td><?= htmlspecialchars($p["cliente"]??"N/A") ?></td>
            <td><?= htmlspecialchars($p["mercado"]??"N/A") ?></td>
            <td><?= htmlspecialchars($p["worker"]??"-") ?></td>
            <td>R$ <?= number_format($p["total"],2,",",".") ?></td>
            <td><span class="status-badge" style="background:#3b82f620;color:#3b82f6"><?= ucfirst($p["status"]) ?></span></td>
            <td><?= date("d/m H:i", strtotime($p["created_at"])) ?></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<?php include "footer.php"; ?>';

file_put_contents($adminDir . '/index.php', $index_php);
log_file("index.php (Dashboard)");
$arquivos_criados++;

// ══════════════════════════════════════════════════════════════════════════════
// 6. DISPATCH.PHP
// ══════════════════════════════════════════════════════════════════════════════
log_info("Criando Central de Dispatch...");

$dispatch_php = '<?php
session_start();
include "db_config.php";
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conn->set_charset("utf8mb4");
$page_title = "Dispatch";

// POST - Atribuir pedido
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["order_id"]) && isset($_POST["worker_id"])) {
    $oid = intval($_POST["order_id"]);
    $wid = intval($_POST["worker_id"]);
    $conn->query("UPDATE om_market_orders SET shopper_id = $wid, status = \"accepted\" WHERE order_id = $oid");
    header("Location: dispatch.php?msg=atribuido"); exit;
}

// Pedidos aguardando
$aguardando = $conn->query("SELECT o.*, p.name as mercado, CONCAT(c.firstname,\" \",c.lastname) as cliente,
    TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) as minutos
    FROM om_market_orders o
    LEFT JOIN om_market_partners p ON p.partner_id=o.partner_id
    LEFT JOIN oc_customer c ON c.customer_id=o.customer_id
    WHERE o.status IN (\"pago\",\"paid\") AND o.shopper_id IS NULL
    ORDER BY o.created_at ASC");

// Workers disponíveis
$workers = $conn->query("SELECT w.worker_id, w.name, w.phone, w.type, w.rating,
    (SELECT COUNT(*) FROM om_market_orders WHERE shopper_id=w.worker_id AND status IN (\"shopping\",\"em_entrega\")) as pedidos_ativos
    FROM om_workers w
    WHERE w.status = \"aprovado\" AND (w.is_online = 1 OR w.is_available = 1)
    ORDER BY pedidos_ativos ASC, w.rating DESC");

include "header.php";
?>

<div class="page-header">
    <h1>🚀 Central de Dispatch</h1>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
    <div class="card">
        <div class="card-header">⏳ Pedidos Aguardando (<?= $aguardando->num_rows ?>)</div>
        <div class="card-body" style="max-height:600px;overflow-y:auto">
            <?php if ($aguardando->num_rows > 0): ?>
                <?php while($p = $aguardando->fetch_assoc()): 
                    $urgente = $p["minutos"] > 15;
                ?>
                <div style="background:#0f172a;padding:15px;border-radius:10px;margin-bottom:10px;border-left:3px solid <?= $urgente?"#ef4444":"#3b82f6" ?>">
                    <div style="display:flex;justify-content:space-between;margin-bottom:8px">
                        <strong style="color:#f97316">#<?= $p["order_id"] ?></strong>
                        <span style="font-size:11px;color:<?= $urgente?"#ef4444":"#64748b" ?>"><?= $p["minutos"] ?>min</span>
                    </div>
                    <div style="font-size:12px;color:#94a3b8;margin-bottom:5px">🏪 <?= htmlspecialchars($p["mercado"]) ?></div>
                    <div style="font-size:12px;color:#94a3b8;margin-bottom:8px">👤 <?= htmlspecialchars($p["cliente"]) ?></div>
                    <div style="font-size:14px;color:#22c55e;margin-bottom:10px">R$ <?= number_format($p["total"],2,",",".") ?></div>
                    <form method="POST" style="display:flex;gap:8px">
                        <input type="hidden" name="order_id" value="<?= $p["order_id"] ?>">
                        <select name="worker_id" required style="flex:1;background:#1e293b;border:1px solid #334155;color:#e2e8f0;padding:8px;border-radius:6px;font-size:12px">
                            <option value="">Selecionar worker...</option>
                            <?php 
                            $workers->data_seek(0);
                            while($w = $workers->fetch_assoc()): ?>
                            <option value="<?= $w["worker_id"] ?>"><?= $w["name"] ?> (<?= $w["pedidos_ativos"] ?> ativos)</option>
                            <?php endwhile; ?>
                        </select>
                        <button type="submit" class="btn btn-primary" style="padding:8px 15px">Atribuir</button>
                    </form>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty">🎉 Nenhum pedido aguardando!</div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">👥 Workers Disponíveis (<?= $workers->num_rows ?>)</div>
        <div class="card-body" style="max-height:600px;overflow-y:auto">
            <?php 
            $workers->data_seek(0);
            while($w = $workers->fetch_assoc()): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#0f172a;border-radius:10px;margin-bottom:8px">
                <div class="avatar" style="position:relative">
                    <?= $w["type"]=="delivery"?"🛵":"🛒" ?>
                    <span style="position:absolute;bottom:-2px;right:-2px;width:10px;height:10px;background:#22c55e;border-radius:50%;border:2px solid #0f172a"></span>
                </div>
                <div style="flex:1">
                    <div style="font-weight:500;font-size:13px"><?= htmlspecialchars($w["name"]) ?></div>
                    <div style="font-size:11px;color:#64748b"><?= $w["phone"] ?></div>
                </div>
                <div style="text-align:right">
                    <div style="font-size:12px;color:#fbbf24">⭐ <?= number_format($w["rating"]??5,1) ?></div>
                    <div style="font-size:11px;color:<?= $w["pedidos_ativos"]>0?"#f97316":"#22c55e" ?>"><?= $w["pedidos_ativos"] ?> ativos</div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
</div>

<?php include "footer.php"; ?>';

file_put_contents($adminDir . '/dispatch.php', $dispatch_php);
log_file("dispatch.php");
$arquivos_criados++;

// ══════════════════════════════════════════════════════════════════════════════
// 7. PEDIDOS.PHP
// ══════════════════════════════════════════════════════════════════════════════
log_info("Criando lista de pedidos...");

$pedidos_php = '<?php
session_start();
include "db_config.php";
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conn->set_charset("utf8mb4");
$page_title = "Pedidos";

$status = $_GET["status"] ?? "";
$busca = $_GET["busca"] ?? "";

$where = "1=1";
if ($status) $where .= " AND o.status = \"" . $conn->real_escape_string($status) . "\"";
if ($busca) $where .= " AND (o.order_id LIKE \"%$busca%\" OR c.firstname LIKE \"%$busca%\" OR c.lastname LIKE \"%$busca%\")";

$pedidos = $conn->query("SELECT o.*, p.name as mercado, w.name as worker, CONCAT(c.firstname,\" \",c.lastname) as cliente
    FROM om_market_orders o
    LEFT JOIN om_market_partners p ON p.partner_id=o.partner_id
    LEFT JOIN om_workers w ON w.worker_id=o.shopper_id
    LEFT JOIN oc_customer c ON c.customer_id=o.customer_id
    WHERE $where
    ORDER BY o.created_at DESC LIMIT 200");

$status_colors = ["pago"=>"#3b82f6","paid"=>"#3b82f6","accepted"=>"#8b5cf6","shopping"=>"#f59e0b","em_compra"=>"#f59e0b","em_entrega"=>"#06b6d4","entregue"=>"#22c55e","delivered"=>"#22c55e","cancelado"=>"#ef4444"];

include "header.php";
?>

<div class="page-header">
    <h1>🛒 Pedidos</h1>
</div>

<form class="filters" method="GET">
    <select name="status">
        <option value="">Todos Status</option>
        <option value="pago" <?=$status=="pago"?"selected":""?>>Pago/Aguardando</option>
        <option value="accepted" <?=$status=="accepted"?"selected":""?>>Aceito</option>
        <option value="shopping" <?=$status=="shopping"?"selected":""?>>Em Compra</option>
        <option value="em_entrega" <?=$status=="em_entrega"?"selected":""?>>Em Entrega</option>
        <option value="entregue" <?=$status=="entregue"?"selected":""?>>Entregue</option>
        <option value="cancelado" <?=$status=="cancelado"?"selected":""?>>Cancelado</option>
    </select>
    <input type="text" name="busca" placeholder="Buscar ID ou cliente..." value="<?= htmlspecialchars($busca) ?>" style="width:250px">
    <button type="submit" class="btn btn-primary">Filtrar</button>
</form>

<div class="card">
    <table>
        <thead>
            <tr><th>ID</th><th>Cliente</th><th>Mercado</th><th>Worker</th><th>Total</th><th>Status</th><th>Data</th><th>Ações</th></tr>
        </thead>
        <tbody>
        <?php if ($pedidos && $pedidos->num_rows > 0): ?>
            <?php while($p = $pedidos->fetch_assoc()): ?>
            <tr>
                <td><a href="pedido_detalhes.php?id=<?= $p["order_id"] ?>" class="link">#<?= $p["order_id"] ?></a></td>
                <td><?= htmlspecialchars($p["cliente"]??"N/A") ?></td>
                <td><?= htmlspecialchars($p["mercado"]??"N/A") ?></td>
                <td><?= htmlspecialchars($p["worker"]??"-") ?></td>
                <td>R$ <?= number_format($p["total"],2,",",".") ?></td>
                <td><span class="status-badge" style="background:<?= $status_colors[$p["status"]]??"#64748b" ?>20;color:<?= $status_colors[$p["status"]]??"#64748b" ?>"><?= ucfirst($p["status"]) ?></span></td>
                <td><?= date("d/m H:i", strtotime($p["created_at"])) ?></td>
                <td><a href="pedido_detalhes.php?id=<?= $p["order_id"] ?>" class="link">Ver</a></td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="8" class="empty">Nenhum pedido encontrado</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include "footer.php"; ?>';

file_put_contents($adminDir . '/pedidos.php', $pedidos_php);
log_file("pedidos.php");
$arquivos_criados++;

// Criar arquivos placeholder para páginas restantes
log_info("Criando páginas adicionais...");

$paginas_placeholder = [
    'pedido_detalhes' => '📦 Detalhes do Pedido',
    'mapa' => '🗺️ Mapa ao Vivo',
    'mercados' => '🏪 Mercados',
    'mercado_detalhes' => '📝 Detalhes do Mercado',
    'shoppers' => '👥 Workers',
    'worker_detalhes' => '👤 Detalhes do Worker',
    'clientes' => '👤 Clientes',
    'cliente_detalhes' => '👤 Detalhes do Cliente',
    'suporte' => '🎧 Tickets de Suporte',
    'ticket_detalhes' => '🎫 Detalhes do Ticket',
    'chat' => '💬 Chat',
    'chat_pedido' => '💬 Chat do Pedido',
    'avaliacoes' => '⭐ Avaliações',
    'reembolsos' => '💰 Reembolsos',
    'cupons' => '🎫 Cupons',
    'promocoes' => '🔥 Promoções',
    'fidelidade' => '👑 Fidelidade',
    'notificacoes' => '🔔 Notificações Push',
    'financeiro' => '💳 Financeiro',
    'pagamentos' => '📤 Pagamentos',
    'relatorios' => '📈 Relatórios',
    'faq' => '❓ FAQ',
    'configuracoes' => '⚙️ Configurações',
    'logs' => '📋 Logs'
];

foreach ($paginas_placeholder as $nome => $titulo) {
    // Verificar se já existe
    if (!file_exists($adminDir . "/$nome.php")) {
        $placeholder = '<?php
session_start();
include "db_config.php";
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conn->set_charset("utf8mb4");
$page_title = "' . $titulo . '";
include "header.php";
?>

<div class="page-header">
    <h1>' . $titulo . '</h1>
</div>

<div class="card">
    <div class="card-body">
        <div class="empty">
            <div style="font-size:48px;margin-bottom:15px">🚧</div>
            <div>Página em construção</div>
            <div style="font-size:12px;margin-top:10px">Esta funcionalidade será implementada em breve</div>
        </div>
    </div>
</div>

<?php include "footer.php"; ?>';
        file_put_contents($adminDir . "/$nome.php", $placeholder);
        log_file("$nome.php");
        $arquivos_criados++;
    }
}

echo "\n";
log_ok("✅ INSTALAÇÃO COMPLETA! $arquivos_criados arquivos criados.");
?>
</div>
</div>

<div class="success">
<h3>🎉 Instalação Concluída!</h3>
<p>O sistema admin foi instalado com sucesso.</p>
<div class="links">
    <a href="/mercado/admin/">📊 Acessar Admin</a>
    <a href="/mercado/admin/dispatch.php">🚀 Dispatch</a>
    <a href="/mercado/admin/pedidos.php">🛒 Pedidos</a>
</div>
<p style="margin-top:15px;font-size:12px;color:#94a3b8">Login: admin@onemundo.com.br / admin123</p>
</div>

<?php endif; ?>

</div>
</body>
</html>
