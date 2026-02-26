<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO MARKET - APP DO COMPRADOR/ENTREGADOR
 * ═══════════════════════════════════════════════════════════════════════════════
 * 
 * Funcionário PJ (cadastrado no RH) que:
 * - Recebe pedidos da região
 * - Vai ao mercado e compra
 * - Entrega ao cliente
 * 
 * Mobile-first design
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// Configurar debug baseado no ambiente
if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}
session_start();

// Detectar caminho base
$baseDir = __DIR__;
for ($i = 0; $i < 5; $i++) {
    if (file_exists($baseDir . '/config.php')) break;
    $baseDir = dirname($baseDir);
}

// Configuração do banco
$dbConfig = ['host' => 'localhost', 'name' => 'love1', 'user' => 'root', 'pass' => ''];

if (file_exists($baseDir . '/config.php')) {
    $c = file_get_contents($baseDir . '/config.php');
    if (preg_match("/define\('DB_HOSTNAME',\s*'([^']+)'\)/", $c, $m)) $dbConfig['host'] = $m[1];
    if (preg_match("/define\('DB_DATABASE',\s*'([^']+)'\)/", $c, $m)) $dbConfig['name'] = $m[1];
    if (preg_match("/define\('DB_USERNAME',\s*'([^']+)'\)/", $c, $m)) $dbConfig['user'] = $m[1];
    if (preg_match("/define\('DB_PASSWORD',\s*'([^']+)'\)/", $c, $m)) $dbConfig['pass'] = $m[1];
}

try {
    $pdo = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4", $dbConfig['user'], $dbConfig['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Erro de conexão");
}

// Whitelist de páginas válidas
$validPages = ['home', 'disponiveis', 'pedido', 'historico', 'perfil'];
$page = in_array($_GET['page'] ?? 'home', $validPages) ? $_GET['page'] : 'home';
$id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$msg = $_SESSION['msg'] ?? null;
unset($_SESSION['msg']);

// ═══════════════════════════════════════════════════════════════════════════════
// AUTENTICAÇÃO (via RH)
// ═══════════════════════════════════════════════════════════════════════════════

$employee = null;
$isLoggedIn = false;

if (isset($_SESSION['market_employee_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM om_rh_users WHERE rh_user_id = ? AND status = '1'");
    $stmt->execute([$_SESSION['market_employee_id']]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($employee) $isLoggedIn = true;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROCESSAR POST
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    
    // Login
    if ($postAction === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM om_rh_users WHERE email = ? AND status = '1'");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['market_employee_id'] = $user['rh_user_id'];
            header('Location: ?page=home');
            exit;
        }
        $msg = ['type' => 'error', 'text' => 'Email ou senha incorretos'];
    }
    
    // Logout
    if ($postAction === 'logout') {
        unset($_SESSION['market_employee_id']);
        header('Location: ?');
        exit;
    }
    
    // Aceitar pedido
    if ($postAction === 'accept_order' && $isLoggedIn) {
        $orderId = intval($_POST['order_id']);
        
        // Verificar se pedido está disponível
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND status = 'confirmed' AND employee_id IS NULL");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            $pdo->prepare("UPDATE om_market_orders SET 
                employee_id = ?, 
                employee_name = ?, 
                employee_phone = ?,
                status = 'assigned',
                assigned_at = NOW(),
                employee_accepted_at = NOW()
                WHERE order_id = ?")
                ->execute([
                    $employee['rh_user_id'],
                    $employee['full_name'],
                    $employee['phone'],
                    $orderId
                ]);
            
            // Log
            $pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, user_type, user_id, user_name) VALUES (?, 'assigned', 'Pedido aceito pelo entregador', 'employee', ?, ?)")
                ->execute([$orderId, $employee['rh_user_id'], $employee['full_name']]);
            
            $_SESSION['msg'] = ['type' => 'success', 'text' => 'Pedido aceito!'];
        } else {
            $_SESSION['msg'] = ['type' => 'error', 'text' => 'Pedido não disponível'];
        }
        header('Location: ?page=pedido&id=' . $orderId);
        exit;
    }
    
    // Atualizar status do pedido
    if ($postAction === 'update_status' && $isLoggedIn) {
        $orderId = intval($_POST['order_id']);
        $newStatus = $_POST['new_status'];
        
        $validStatus = ['shopping', 'purchased', 'packing', 'ready', 'delivering', 'delivered'];
        
        if (in_array($newStatus, $validStatus)) {
            // Atualizar status
            // Validar status primeiro
$validTimestampFields = [
    'shopping' => 'shopping_at',
    'purchased' => 'purchased_at', 
    'packing' => 'packing_at',
    'ready' => 'ready_at',
    'delivering' => 'delivering_at',
    'delivered' => 'delivered_at'
];

if (!isset($validTimestampFields[$newStatus])) {
    throw new Exception('Status inválido');
}

$timestampField = $validTimestampFields[$newStatus];
$pdo->prepare("UPDATE om_market_orders SET status = ?, {$timestampField} = NOW() WHERE order_id = ? AND employee_id = ?")
    ->execute([$newStatus, $orderId, $employee['rh_user_id']]);
            
            // Log
            $statusLabels = [
                'shopping' => 'Iniciou as compras',
                'purchased' => 'Finalizou as compras',
                'packing' => 'Embalando produtos',
                'ready' => 'Pronto para entrega',
                'delivering' => 'Saiu para entrega',
                'delivered' => 'Pedido entregue'
            ];
            
            $pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, user_type, user_id, user_name) VALUES (?, ?, ?, 'employee', ?, ?)")
                ->execute([$orderId, $newStatus, $statusLabels[$newStatus], $employee['rh_user_id'], $employee['full_name']]);
            
            $_SESSION['msg'] = ['type' => 'success', 'text' => $statusLabels[$newStatus]];
        }
        header('Location: ?page=pedido&id=' . $orderId);
        exit;
    }
    
    // Atualizar item (encontrado/não encontrado)
    if ($postAction === 'update_item' && $isLoggedIn) {
        $itemId = intval($_POST['item_id']);
        $itemStatus = $_POST['item_status'];
        $quantityFound = floatval($_POST['quantity_found'] ?? 0);
        $notes = $_POST['notes'] ?? '';
        
        $pdo->prepare("UPDATE om_market_order_items SET status = ?, quantity_found = ?, notes = ? WHERE item_id = ?")
            ->execute([$itemStatus, $quantityFound ?: null, $notes, $itemId]);
        
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    // Upload foto cupom
    if ($postAction === 'upload_receipt' && $isLoggedIn) {
        $orderId = intval($_POST['order_id']);
        $receiptTotal = floatval($_POST['receipt_total'] ?? 0);
        
        if (isset($_FILES['receipt_photo']) && $_FILES['receipt_photo']['error'] === 0) {
            // Validar tipo MIME
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
            $fileMime = mime_content_type($_FILES['receipt_photo']['tmp_name']);
            
            if (!in_array($fileMime, $allowedMimes)) {
                $_SESSION['msg'] = ['type' => 'error', 'text' => 'Tipo de arquivo não permitido'];
                header('Location: ?page=pedido&id=' . $orderId);
                exit;
            }
            
            // Validar tamanho (5MB max)
            if ($_FILES['receipt_photo']['size'] > 5 * 1024 * 1024) {
                $_SESSION['msg'] = ['type' => 'error', 'text' => 'Arquivo muito grande'];
                header('Location: ?page=pedido&id=' . $orderId);
                exit;
            }
            
            $uploadDir = $baseDir . '/image/market/receipts/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            // Usar extensão baseada no MIME
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $ext = $extensions[$fileMime];
            $filename = 'receipt_' . $orderId . '_' . time() . '.' . $ext;
            
            if (move_uploaded_file($_FILES['receipt_photo']['tmp_name'], $uploadDir . $filename)) {
                $pdo->prepare("UPDATE om_market_orders SET receipt_photo = ?, receipt_total = ? WHERE order_id = ? AND employee_id = ?")
                    ->execute(['market/receipts/' . $filename, $receiptTotal, $orderId, $employee['rh_user_id']]);
                
                $_SESSION['msg'] = ['type' => 'success', 'text' => 'Cupom enviado!'];
            }
        }
        header('Location: ?page=pedido&id=' . $orderId);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

function getEmployeeStats($pdo, $employeeId) {
    $s = ['today' => 0, 'week' => 0, 'month' => 0, 'pending' => 0, 'earnings' => 0];
    try {
        // Single query para todas as estatísticas de pedidos
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(CASE WHEN DATE(delivered_at) = CURRENT_DATE THEN 1 END) as today,
                COUNT(CASE WHEN delivered_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY) THEN 1 END) as week,
                COUNT(CASE WHEN MONTH(delivered_at) = MONTH(CURRENT_DATE) AND YEAR(delivered_at) = YEAR(CURRENT_DATE) THEN 1 END) as month,
                COUNT(CASE WHEN status NOT IN ('delivered', 'cancelled') THEN 1 END) as pending
            FROM om_market_orders 
            WHERE employee_id = ?
        ");
        $stmt->execute([$employeeId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        $s = array_merge($s, $stats);
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE employee_id = ? AND delivered_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)");
        $stmt->execute([$employeeId]);
        $s['week'] = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE employee_id = ? AND MONTH(delivered_at) = MONTH(CURRENT_DATE) AND YEAR(delivered_at) = YEAR(CURRENT_DATE)");
        $stmt->execute([$employeeId]);
        $s['month'] = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE employee_id = ? AND status NOT IN ('delivered', 'cancelled')");
        $stmt->execute([$employeeId]);
        $s['pending'] = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_earned), 0) FROM om_market_employee_earnings WHERE rh_user_id = ? AND MONTH(date_added) = MONTH(CURRENT_DATE)");
        $stmt->execute([$employeeId]);
        $s['earnings'] = $stmt->fetchColumn();
    } catch(Exception $e) {}
    return $s;
}

function getStatusLabel($status) {
    $labels = [
        'pending' => ['⏳', 'Aguardando', '#F59E0B'],
        'confirmed' => ['✅', 'Confirmado', '#22C55E'],
        'assigned' => ['👤', 'Atribuído', '#3B82F6'],
        'shopping' => ['🛒', 'Comprando', '#8B5CF6'],
        'purchased' => ['📦', 'Comprado', '#6366F1'],
        'packing' => ['📦', 'Embalando', '#EC4899'],
        'ready' => ['✅', 'Pronto', '#10B981'],
        'delivering' => ['🚚', 'Entregando', '#F97316'],
        'delivered' => ['🎉', 'Entregue', '#22C55E'],
        'cancelled' => ['❌', 'Cancelado', '#EF4444']
    ];
    return $labels[$status] ?? ['❓', $status, '#6B7280'];
}

function getNextStatus($currentStatus) {
    $flow = [
        'assigned' => 'shopping',
        'shopping' => 'purchased',
        'purchased' => 'packing',
        'packing' => 'ready',
        'ready' => 'delivering',
        'delivering' => 'delivered'
    ];
    return $flow[$currentStatus] ?? null;
}

function getNextStatusLabel($status) {
    $labels = [
        'shopping' => '🛒 Iniciar Compras',
        'purchased' => '✅ Finalizar Compras',
        'packing' => '📦 Embalar',
        'ready' => '✅ Pronto p/ Entrega',
        'delivering' => '🚚 Sair p/ Entrega',
        'delivered' => '🎉 Confirmar Entrega'
    ];
    return $labels[$status] ?? $status;
}

?>
<?php
// Adicionar headers de segurança
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self';");
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#166534">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>OneMundo Market - Entregador</title>
<?php
$cssVersion = filemtime(__FILE__); // Para cache busting
?>
<link rel="stylesheet" href="market-app.css?v=<?=$cssVersion?>">
<style>
/* Apenas CSS crítico inline */
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,sans-serif}
    --bg:#f0fdf4;
    --card:#ffffff;
    --text:#1a1a1a;
    --text-muted:#6b7280;
    --border:#dcfce7;
    --shadow:0 2px 8px rgba(0,0,0,0.08);
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding-bottom:80px}

/* Login */
.login{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:linear-gradient(135deg,#166534 0%,#22C55E 100%)}
.login-box{background:#fff;border-radius:24px;padding:32px;width:100%;max-width:360px;box-shadow:0 20px 60px rgba(0,0,0,0.2)}
.login-logo{text-align:center;margin-bottom:24px}
.login-logo .icon{font-size:48px;margin-bottom:8px}
.login-logo h1{font-size:22px;color:var(--primary-dark)}
.login-logo p{color:var(--text-muted);font-size:13px}

/* Header */
.header{background:linear-gradient(135deg,#166534,#22C55E);color:#fff;padding:16px 20px;position:sticky;top:0;z-index:100}
.header-top{display:flex;justify-content:space-between;align-items:center}
.header h1{font-size:18px;display:flex;align-items:center;gap:8px}
.header-user{font-size:13px;opacity:.9}
.online-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(255,255,255,0.2);padding:4px 10px;border-radius:20px;font-size:11px}
.online-badge::before{content:'';width:8px;height:8px;background:#4ADE80;border-radius:50%}

/* Nav Bottom */
.nav-bottom{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid var(--border);display:flex;padding:8px 0;padding-bottom:calc(8px + env(safe-area-inset-bottom));z-index:100}
.nav-item{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:8px;color:var(--text-muted);text-decoration:none;font-size:10px;transition:color .2s}
.nav-item.active{color:var(--primary)}
.nav-item .icon{font-size:22px}

/* Container */
.container{padding:16px}

/* Cards */
.card{background:#fff;border-radius:16px;padding:16px;margin-bottom:12px;box-shadow:var(--shadow)}
.card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.card-title{font-size:15px;font-weight:600;display:flex;align-items:center;gap:8px}

/* Stats */
.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px}
.stat{background:#fff;border-radius:14px;padding:14px;text-align:center;box-shadow:var(--shadow)}
.stat .value{font-size:28px;font-weight:700;color:var(--primary-dark)}
.stat .label{font-size:11px;color:var(--text-muted)}

/* Order Card */
.order-card{background:#fff;border-radius:16px;padding:16px;margin-bottom:12px;box-shadow:var(--shadow);border-left:4px solid var(--primary)}
.order-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px}
.order-number{font-weight:700;font-size:15px}
.order-time{font-size:12px;color:var(--text-muted)}
.order-status{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600}
.order-customer{font-size:13px;margin-bottom:8px}
.order-address{font-size:12px;color:var(--text-muted);display:flex;align-items:flex-start;gap:6px}
.order-total{font-size:18px;font-weight:700;color:var(--primary-dark);margin-top:12px}
.order-items-count{font-size:12px;color:var(--text-muted)}

/* Buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 20px;border:none;border-radius:12px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;width:100%}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-dark)}
.btn-secondary{background:#f3f4f6;color:var(--text)}
.btn-success{background:#22C55E;color:#fff}
.btn-warning{background:#F59E0B;color:#fff}
.btn-danger{background:#EF4444;color:#fff}
.btn-sm{padding:8px 14px;font-size:12px}
.btn-lg{padding:16px 24px;font-size:16px}

/* Forms */
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:12px;color:var(--text-muted);margin-bottom:4px}
.form-control{width:100%;padding:12px 14px;background:#f9fafb;border:1px solid var(--border);border-radius:10px;font-size:14px;color:var(--text)}
.form-control:focus{outline:none;border-color:var(--primary);background:#fff}

/* Alert */
.alert{padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px}
.alert-success{background:#dcfce7;color:#166534}
.alert-error{background:#fee2e2;color:#991b1b}

/* Order Detail */
.detail-section{background:#fff;border-radius:16px;padding:16px;margin-bottom:12px;box-shadow:var(--shadow)}
.detail-section h3{font-size:14px;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.detail-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:13px}
.detail-row:last-child{border:none}

/* Shopping List */
.item-card{background:#f9fafb;border-radius:12px;padding:12px;margin-bottom:10px}
.item-header{display:flex;justify-content:space-between;align-items:flex-start}
.item-name{font-weight:600;font-size:14px;flex:1}
.item-qty{background:var(--primary);color:#fff;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:700}
.item-brand{font-size:12px;color:var(--text-muted);margin-top:2px}
.item-location{font-size:11px;color:var(--text-muted);margin-top:4px;display:flex;align-items:center;gap:4px}
.item-status{margin-top:8px;display:flex;gap:6px}
.item-status button{flex:1;padding:8px;border:none;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer}
.item-found{background:#dcfce7;color:#166534}
.item-found.active{background:#22C55E;color:#fff}
.item-notfound{background:#fee2e2;color:#991b1b}
.item-notfound.active{background:#EF4444;color:#fff}

/* Status Flow */
.status-flow{display:flex;flex-direction:column;gap:8px}
.status-step{display:flex;align-items:center;gap:12px;padding:10px;background:#f9fafb;border-radius:10px}
.status-step.done{background:#dcfce7}
.status-step.current{background:var(--primary);color:#fff}
.status-icon{width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:#fff;border-radius:50%;font-size:16px}
.status-step.done .status-icon{background:#22C55E;color:#fff}
.status-step.current .status-icon{background:rgba(255,255,255,0.3)}
.status-info{flex:1}
.status-title{font-weight:600;font-size:13px}
.status-time{font-size:11px;opacity:.7}

/* Map Link */
.map-link{display:flex;align-items:center;gap:8px;padding:12px;background:#eff6ff;border-radius:12px;color:#1d4ed8;text-decoration:none;font-weight:500;font-size:14px;margin-top:12px}

/* Empty State */
.empty{text-align:center;padding:40px 20px}
.empty .icon{font-size:48px;margin-bottom:12px}
.empty h3{font-size:16px;margin-bottom:4px}
.empty p{font-size:13px;color:var(--text-muted)}

/* Pulse animation for new orders */
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.pulse{animation:pulse 1.5s infinite}

/* Badge */
.badge{display:inline-block;padding:3px 8px;border-radius:10px;font-size:10px;font-weight:600}
.badge-new{background:#fef3c7;color:#92400e}
</style>

<!-- HEADER PREMIUM v3.0 -->
<style>

/* ══════════════════════════════════════════════════════════════════════════════
   🎨 HEADER PREMIUM v3.0 - OneMundo Mercado
   ══════════════════════════════════════════════════════════════════════════════ */

/* Variáveis do Header */
:root {
    --header-bg: rgba(255, 255, 255, 0.92);
    --header-bg-scrolled: rgba(255, 255, 255, 0.98);
    --header-blur: 20px;
    --header-shadow: 0 4px 30px rgba(0, 0, 0, 0.08);
    --header-border: rgba(0, 0, 0, 0.04);
    --header-height: 72px;
    --header-height-mobile: 64px;
}

/* Header Principal */
.header, .site-header, [class*="header-main"] {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 1000 !important;
    background: var(--header-bg) !important;
    backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    -webkit-backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    border-bottom: 1px solid var(--header-border) !important;
    box-shadow: none !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    height: auto !important;
    min-height: var(--header-height) !important;
}

.header.scrolled, .site-header.scrolled {
    background: var(--header-bg-scrolled) !important;
    box-shadow: var(--header-shadow) !important;
}

/* Container do Header */
.header-inner, .header-content, .header > div:first-child {
    max-width: 1400px !important;
    margin: 0 auto !important;
    padding: 12px 24px !important;
    display: flex !important;
    align-items: center !important;
    gap: 20px !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOCALIZAÇÃO - Estilo Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.location-btn, .endereco, [class*="location"], [class*="endereco"], [class*="address"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 10px 18px !important;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(16, 185, 129, 0.04)) !important;
    border: 1px solid rgba(16, 185, 129, 0.15) !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    min-width: 200px !important;
    max-width: 320px !important;
}

.location-btn:hover, .endereco:hover, [class*="location"]:hover {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.06)) !important;
    border-color: rgba(16, 185, 129, 0.25) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15) !important;
}

/* Ícone de localização */
.location-btn svg, .location-btn i, [class*="location"] svg {
    width: 22px !important;
    height: 22px !important;
    color: #10b981 !important;
    flex-shrink: 0 !important;
}

/* Texto da localização */
.location-text, .endereco-text {
    flex: 1 !important;
    min-width: 0 !important;
}

.location-label, .entregar-em {
    font-size: 11px !important;
    font-weight: 500 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin-bottom: 2px !important;
}

.location-address, .endereco-rua {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Seta da localização */
.location-arrow, .location-btn > svg:last-child {
    width: 16px !important;
    height: 16px !important;
    color: #94a3b8 !important;
    transition: transform 0.2s ease !important;
}

.location-btn:hover .location-arrow {
    transform: translateX(3px) !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   TEMPO DE ENTREGA - Badge Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.delivery-time, .tempo-entrega, [class*="delivery-time"], [class*="tempo"] {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 10px 16px !important;
    background: linear-gradient(135deg, #0f172a, #1e293b) !important;
    border-radius: 12px !important;
    color: white !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2) !important;
    transition: all 0.3s ease !important;
}

.delivery-time:hover, .tempo-entrega:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.25) !important;
}

.delivery-time svg, .tempo-entrega svg, .delivery-time i {
    width: 18px !important;
    height: 18px !important;
    color: #10b981 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOGO - Design Moderno
   ══════════════════════════════════════════════════════════════════════════════ */

.logo, .site-logo, [class*="logo"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    text-decoration: none !important;
    transition: transform 0.3s ease !important;
}

.logo:hover {
    transform: scale(1.02) !important;
}

.logo-icon, .logo img, .logo svg {
    width: 48px !important;
    height: 48px !important;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    border-radius: 14px !important;
    padding: 10px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.logo:hover .logo-icon, .logo:hover img {
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
    transform: rotate(-3deg) !important;
}

.logo-text, .logo span, .site-title {
    font-size: 1.5rem !important;
    font-weight: 800 !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
    background-clip: text !important;
    letter-spacing: -0.02em !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   BUSCA - Search Bar Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.search-container, .search-box, [class*="search"], .busca {
    flex: 1 !important;
    max-width: 600px !important;
    position: relative !important;
}

.search-input, input[type="search"], input[name*="search"], input[name*="busca"], .busca input {
    width: 100% !important;
    padding: 14px 20px 14px 52px !important;
    background: #f1f5f9 !important;
    border: 2px solid transparent !important;
    border-radius: 16px !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
}

.search-input:hover, input[type="search"]:hover {
    background: #e2e8f0 !important;
}

.search-input:focus, input[type="search"]:focus {
    background: #ffffff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12), inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
    outline: none !important;
}

.search-input::placeholder {
    color: #94a3b8 !important;
    font-weight: 400 !important;
}

/* Ícone da busca */
.search-icon, .search-container svg, .busca svg {
    position: absolute !important;
    left: 18px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 22px !important;
    height: 22px !important;
    color: #94a3b8 !important;
    pointer-events: none !important;
    transition: color 0.3s ease !important;
}

.search-input:focus + .search-icon,
.search-container:focus-within svg {
    color: #10b981 !important;
}

/* Botão de busca por voz (opcional) */
.search-voice-btn {
    position: absolute !important;
    right: 12px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 36px !important;
    height: 36px !important;
    background: transparent !important;
    border: none !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.search-voice-btn:hover {
    background: rgba(16, 185, 129, 0.1) !important;
}

.search-voice-btn svg {
    width: 20px !important;
    height: 20px !important;
    color: #64748b !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   CARRINHO - Cart Button Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.cart-btn, .carrinho-btn, [class*="cart"], [class*="carrinho"], a[href*="cart"], a[href*="carrinho"] {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 52px !important;
    height: 52px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border: none !important;
    border-radius: 16px !important;
    cursor: pointer !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
}

.cart-btn:hover, .carrinho-btn:hover, [class*="cart"]:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4) !important;
}

.cart-btn:active {
    transform: translateY(-1px) scale(0.98) !important;
}

.cart-btn svg, .carrinho-btn svg, [class*="cart"] svg {
    width: 26px !important;
    height: 26px !important;
    color: white !important;
}

/* Badge do carrinho */
.cart-badge, .carrinho-badge, [class*="cart-count"], [class*="badge"] {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 24px !important;
    height: 24px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: white !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 6px !important;
    border: 3px solid white !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: badge-pulse 2s ease-in-out infinite !important;
}

@keyframes badge-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* ══════════════════════════════════════════════════════════════════════════════
   MENU MOBILE
   ══════════════════════════════════════════════════════════════════════════════ */

.menu-btn, .hamburger, [class*="menu-toggle"] {
    display: none !important;
    width: 44px !important;
    height: 44px !important;
    background: #f1f5f9 !important;
    border: none !important;
    border-radius: 12px !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.menu-btn:hover {
    background: #e2e8f0 !important;
}

.menu-btn svg {
    width: 24px !important;
    height: 24px !important;
    color: #475569 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   RESPONSIVO
   ══════════════════════════════════════════════════════════════════════════════ */

@media (max-width: 1024px) {
    .search-container, .search-box {
        max-width: 400px !important;
    }
    
    .location-btn, .endereco {
        max-width: 250px !important;
    }
}

@media (max-width: 768px) {
    :root {
        --header-height: var(--header-height-mobile);
    }
    
    .header-inner, .header-content {
        padding: 10px 16px !important;
        gap: 12px !important;
    }
    
    /* Esconder busca no header mobile - mover para baixo */
    .search-container, .search-box, [class*="search"]:not(.search-icon) {
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        right: 0 !important;
        max-width: 100% !important;
        padding: 12px 16px !important;
        background: white !important;
        border-top: 1px solid #e2e8f0 !important;
        display: none !important;
    }
    
    .search-container.active {
        display: block !important;
    }
    
    /* Logo menor */
    .logo-icon, .logo img {
        width: 42px !important;
        height: 42px !important;
        border-radius: 12px !important;
    }
    
    .logo-text {
        display: none !important;
    }
    
    /* Localização compacta */
    .location-btn, .endereco {
        min-width: auto !important;
        max-width: 180px !important;
        padding: 8px 12px !important;
    }
    
    .location-label, .entregar-em {
        display: none !important;
    }
    
    .location-address {
        font-size: 13px !important;
    }
    
    /* Tempo de entrega menor */
    .delivery-time, .tempo-entrega {
        padding: 8px 12px !important;
        font-size: 12px !important;
    }
    
    /* Carrinho menor */
    .cart-btn, .carrinho-btn {
        width: 46px !important;
        height: 46px !important;
        border-radius: 14px !important;
    }
    
    .cart-btn svg {
        width: 22px !important;
        height: 22px !important;
    }
    
    /* Mostrar menu button */
    .menu-btn, .hamburger {
        display: flex !important;
    }
}

@media (max-width: 480px) {
    .location-btn, .endereco {
        max-width: 140px !important;
    }
    
    .delivery-time, .tempo-entrega {
        display: none !important;
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   ANIMAÇÕES DE ENTRADA
   ══════════════════════════════════════════════════════════════════════════════ */

@keyframes headerSlideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.header, .site-header {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *, .header-content > * {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *:nth-child(1) { animation-delay: 0.05s !important; }
.header-inner > *:nth-child(2) { animation-delay: 0.1s !important; }
.header-inner > *:nth-child(3) { animation-delay: 0.15s !important; }
.header-inner > *:nth-child(4) { animation-delay: 0.2s !important; }
.header-inner > *:nth-child(5) { animation-delay: 0.25s !important; }

/* ══════════════════════════════════════════════════════════════════════════════
   AJUSTES DE BODY PARA HEADER FIXED
   ══════════════════════════════════════════════════════════════════════════════ */

body {
    padding-top: calc(var(--header-height) + 10px) !important;
}

@media (max-width: 768px) {
    body {
        padding-top: calc(var(--header-height-mobile) + 10px) !important;
    }
}

</style>
</head>
<body>

<?php if(!$isLoggedIn): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- LOGIN -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="login">
<div class="login-box">
<div class="login-logo">
<div class="icon">🛒</div>
<h1>Market Entregador</h1>
<p>Acesso para funcionários</p>
</div>

<?php if($msg): ?>
<div class="alert alert-<?=$msg['type']==='success'?'success':'error'?>"><?=$msg['text']?></div>
<?php endif; ?>

<?php
// No início do arquivo, gerar token CSRF
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Função para validar CSRF
function validateCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>

<form method="POST">
<input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
<input type="hidden" name="action" value="login">
<div class="form-group">
<label class="form-label">Email (RH)</label>
<input type="email" name="email" class="form-control" required>
</div>
<div class="form-group">
<label class="form-label">Senha</label>
<input type="password" name="password" class="form-control" required>
</div>
<button type="submit" class="btn btn-primary btn-lg">Entrar</button>
</form>
</div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- APP LOGADO -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<?php $stats = getEmployeeStats($pdo, $employee['rh_user_id']); ?>

<!-- Header -->
<header class="header">
<div class="header-top">
<h1>🛒 Market</h1>
<div style="text-align:right">
<div class="header-user"><?=htmlspecialchars($employee['full_name'] ?? $employee['email'])?></div>
<div class="online-badge">Online</div>
</div>
</div>
</header>

<!-- Content -->
<div class="container">

<?php if($msg): ?>
<div class="alert alert-<?=$msg['type']==='success'?'success':'error'?>"><?=$msg['text']?></div>
<?php endif; ?>

<?php if($page === 'home'): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- HOME -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<!-- Stats -->
<div class="stats">
<div class="stat">
<div class="value"><?=$stats['today']?></div>
<div class="label">Entregas Hoje</div>
</div>
<div class="stat">
<div class="value"><?=$stats['pending']?></div>
<div class="label">Em Andamento</div>
</div>
<div class="stat">
<div class="value"><?=$stats['month']?></div>
<div class="label">Este Mês</div>
</div>
<div class="stat">
<div class="value">R$ <?=number_format($stats['earnings'],0,',','.')?></div>
<div class="label">Ganhos Mês</div>
</div>
</div>

<!-- Meus Pedidos em Andamento -->
<?php
$myOrders = $pdo->prepare("SELECT * FROM om_market_orders WHERE employee_id = ? AND status NOT IN ('delivered', 'cancelled') ORDER BY date_added DESC");
$myOrders->execute([$employee['rh_user_id']]);
$myOrders = $myOrders->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if(!empty($myOrders)): ?>
<div class="card">
<div class="card-header">
<span class="card-title">📋 Meus Pedidos</span>
<span class="badge badge-new"><?=count($myOrders)?></span>
</div>

<?php foreach($myOrders as $order): 
$statusInfo = getStatusLabel($order['status']);
?>
<a href="?page=pedido&id=<?=$order['order_id']?>" style="text-decoration:none;color:inherit">
<div class="order-card" style="border-left-color:<?=$statusInfo[2]?>">
<div class="order-header">
<div>
<div class="order-number"><?=$order['order_number']?></div>
<div class="order-time"><?=date('H:i', strtotime($order['date_added']))?></div>
</div>
<span class="order-status" style="background:<?=$statusInfo[2]?>20;color:<?=$statusInfo[2]?>"><?=$statusInfo[0]?> <?=$statusInfo[1]?></span>
</div>
<div class="order-customer">👤 <?=htmlspecialchars($order['customer_name'])?></div>
<div class="order-address">📍 <?=htmlspecialchars($order['shipping_address'] . ', ' . $order['shipping_neighborhood'])?></div>
<div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
<span class="order-items-count">Ver detalhes →</span>
<span class="order-total">R$ <?=number_format($order['total'],2,',','.')?></span>
</div>
</div>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Ações -->
<div class="card">
<a href="?page=disponiveis" class="btn btn-primary btn-lg" style="margin-bottom:10px">
🔔 Ver Pedidos Disponíveis
</a>
<a href="?page=historico" class="btn btn-secondary">
📜 Meu Histórico
</a>
</div>

<?php elseif($page === 'disponiveis'): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- PEDIDOS DISPONÍVEIS -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<?php
$available = $pdo->query("SELECT * FROM om_market_orders WHERE status = 'confirmed' AND employee_id IS NULL ORDER BY date_added DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card-header" style="margin-bottom:16px">
<span class="card-title">🔔 Pedidos Disponíveis</span>
<a href="?page=home" style="color:var(--primary);text-decoration:none;font-size:13px">← Voltar</a>
</div>

<?php if(empty($available)): ?>
<div class="empty">
<div class="icon">📭</div>
<h3>Nenhum pedido disponível</h3>
<p>Aguarde novos pedidos</p>
</div>
<?php else: ?>

<?php foreach($available as $order): ?>
<div class="order-card pulse">
<div class="order-header">
<div>
<div class="order-number"><?=$order['order_number']?></div>
<div class="order-time"><?=date('d/m H:i', strtotime($order['date_added']))?></div>
</div>
<span class="badge badge-new">NOVO</span>
</div>
<div class="order-customer">👤 <?=htmlspecialchars($order['customer_name'])?></div>
<div class="order-address">📍 <?=htmlspecialchars($order['shipping_address'] . ', ' . $order['shipping_neighborhood'] . ' - ' . $order['shipping_city'])?></div>
<div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
<span class="order-total">R$ <?=number_format($order['total'],2,',','.')?></span>
</div>
<form method="POST" style="margin-top:12px">
<input type="hidden" name="action" value="accept_order">
<input type="hidden" name="order_id" value="<?=$order['order_id']?>">
<button type="submit" class="btn btn-success">✅ Aceitar Pedido</button>
</form>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php elseif($page === 'pedido' && $id > 0): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- DETALHE DO PEDIDO -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<?php
$stmt = $pdo->prepare("SELECT o.*, p.name as partner_name, p.address as partner_address FROM om_market_orders o LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id WHERE o.order_id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

$items = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ?");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$statusInfo = getStatusLabel($order['status']);
$nextStatus = getNextStatus($order['status']);
$isMyOrder = ($order['employee_id'] == $employee['rh_user_id']);
?>

<div class="card-header" style="margin-bottom:16px">
<span class="card-title"><?=$order['order_number']?></span>
<a href="?page=home" style="color:var(--primary);text-decoration:none;font-size:13px">← Voltar</a>
</div>

<!-- Status atual -->
<div class="detail-section">
<div style="display:flex;justify-content:space-between;align-items:center">
<div>
<div style="font-size:12px;color:var(--text-muted)">Status</div>
<div style="font-size:18px;font-weight:700;color:<?=$statusInfo[2]?>"><?=$statusInfo[0]?> <?=$statusInfo[1]?></div>
</div>
<div style="text-align:right">
<div style="font-size:12px;color:var(--text-muted)">Total</div>
<div style="font-size:22px;font-weight:700;color:var(--primary-dark)">R$ <?=number_format($order['total'],2,',','.')?></div>
</div>
</div>

<?php if($isMyOrder && $nextStatus): ?>
<form method="POST" style="margin-top:16px">
<input type="hidden" name="action" value="update_status">
<input type="hidden" name="order_id" value="<?=$order['order_id']?>">
<input type="hidden" name="new_status" value="<?=$nextStatus?>">
<button type="submit" class="btn btn-primary btn-lg"><?=getNextStatusLabel($nextStatus)?></button>
</form>
<?php endif; ?>
</div>

<!-- Cliente -->
<div class="detail-section">
<h3>👤 Cliente</h3>
<div class="detail-row"><span>Nome</span><strong><?=htmlspecialchars($order['customer_name'])?></strong></div>
<div class="detail-row"><span>Telefone</span><a href="tel:<?=$order['customer_phone']?>" style="color:var(--primary)"><?=$order['customer_phone']?></a></div>

<a href="https://www.google.com/maps/search/?api=1&query=<?=urlencode($order['shipping_address'] . ', ' . $order['shipping_number'] . ', ' . $order['shipping_neighborhood'] . ', ' . $order['shipping_city'])?>" target="_blank" class="map-link">
📍 <?=htmlspecialchars($order['shipping_address'] . ', ' . $order['shipping_number'])?><br>
<?=htmlspecialchars($order['shipping_neighborhood'] . ' - ' . $order['shipping_city'])?>
</a>

<?php if($order['shipping_instructions']): ?>
<div style="margin-top:12px;padding:10px;background:#fef3c7;border-radius:8px;font-size:13px">
📝 <?=htmlspecialchars($order['shipping_instructions'])?>
</div>
<?php endif; ?>
</div>

<!-- Lista de Compras -->
<div class="detail-section">
<h3>🛒 Lista de Compras (<?=count($items)?> itens)</h3>

<?php foreach($items as $item): ?>
<div class="item-card">
<div class="item-header">
<div>
<div class="item-name"><?=htmlspecialchars($item['name'])?></div>
<?php if($item['brand']): ?>
<div class="item-brand"><?=htmlspecialchars($item['brand'])?></div>
<?php endif; ?>
</div>
<span class="item-qty"><?=$item['quantity']?> <?=$item['unit']?></span>
</div>

<?php if($isMyOrder && in_array($order['status'], ['shopping', 'purchased'])): ?>
<div class="item-status">
<form method="POST" style="display:contents">
<input type="hidden" name="action" value="update_item">
<input type="hidden" name="item_id" value="<?=$item['item_id']?>">
<input type="hidden" name="quantity_found" value="<?=$item['quantity']?>">
<button type="submit" name="item_status" value="found" class="item-found <?=$item['status']==='found'?'active':''?>">✅ Encontrado</button>
<button type="submit" name="item_status" value="not_found" class="item-notfound <?=$item['status']==='not_found'?'active':''?>">❌ Não tem</button>
</form>
</div>
<?php elseif($item['status'] === 'found'): ?>
<div style="margin-top:8px;font-size:12px;color:#22C55E">✅ Encontrado</div>
<?php elseif($item['status'] === 'not_found'): ?>
<div style="margin-top:8px;font-size:12px;color:#EF4444">❌ Não encontrado</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- Upload Cupom -->
<?php if($isMyOrder && in_array($order['status'], ['purchased', 'packing', 'ready'])): ?>
<div class="detail-section">
<h3>🧾 Cupom Fiscal</h3>

<?php if($order['receipt_photo']): ?>
<div style="text-align:center;margin-bottom:12px">
<img src="/image/<?=$order['receipt_photo']?>" style="max-width:100%;border-radius:12px">
<div style="margin-top:8px;font-size:14px">Total: <strong>R$ <?=number_format($order['receipt_total'],2,',','.')?></strong></div>
</div>
<?php else: ?>
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="upload_receipt">
<input type="hidden" name="order_id" value="<?=$order['order_id']?>">
<div class="form-group">
<label class="form-label">Valor do cupom</label>
<input type="number" name="receipt_total" class="form-control" step="0.01" placeholder="0,00" required>
</div>
<div class="form-group">
<label class="form-label">Foto do cupom</label>
<input type="file" name="receipt_photo" accept="image/*" capture="environment" class="form-control" required>
</div>
<button type="submit" class="btn btn-primary">📸 Enviar Cupom</button>
</form>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- Resumo Financeiro -->
<div class="detail-section">
<h3>💰 Resumo</h3>
<div class="detail-row"><span>Subtotal</span><span>R$ <?=number_format($order['subtotal'],2,',','.')?></span></div>
<div class="detail-row"><span>Taxa entrega</span><span>R$ <?=number_format($order['delivery_fee'],2,',','.')?></span></div>
<?php if($order['discount_amount'] > 0): ?>
<div class="detail-row"><span>Desconto</span><span style="color:#22C55E">-R$ <?=number_format($order['discount_amount'],2,',','.')?></span></div>
<?php endif; ?>
<div class="detail-row" style="font-weight:700;font-size:16px"><span>Total</span><span>R$ <?=number_format($order['total'],2,',','.')?></span></div>
</div>

<?php elseif($page === 'historico'): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- HISTÓRICO -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<?php
$history = $pdo->prepare("SELECT * FROM om_market_orders WHERE employee_id = ? ORDER BY date_added DESC LIMIT 50");
$history->execute([$employee['rh_user_id']]);
$history = $history->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card-header" style="margin-bottom:16px">
<span class="card-title">📜 Meu Histórico</span>
<a href="?page=home" style="color:var(--primary);text-decoration:none;font-size:13px">← Voltar</a>
</div>

<?php if(empty($history)): ?>
<div class="empty">
<div class="icon">📜</div>
<h3>Nenhum pedido ainda</h3>
<p>Seus pedidos aparecerão aqui</p>
</div>
<?php else: ?>

<?php foreach($history as $order): 
$statusInfo = getStatusLabel($order['status']);
?>
<a href="?page=pedido&id=<?=$order['order_id']?>" style="text-decoration:none;color:inherit">
<div class="order-card" style="border-left-color:<?=$statusInfo[2]?>">
<div class="order-header">
<div>
<div class="order-number"><?=$order['order_number']?></div>
<div class="order-time"><?=date('d/m/Y H:i', strtotime($order['date_added']))?></div>
</div>
<span class="order-status" style="background:<?=$statusInfo[2]?>20;color:<?=$statusInfo[2]?>"><?=$statusInfo[0]?> <?=$statusInfo[1]?></span>
</div>
<div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">
<span class="order-customer"><?=htmlspecialchars($order['customer_name'])?></span>
<span class="order-total">R$ <?=number_format($order['total'],2,',','.')?></span>
</div>
</div>
</a>
<?php endforeach; ?>

<?php endif; ?>

<?php elseif($page === 'perfil'): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- PERFIL -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<div class="card-header" style="margin-bottom:16px">
<span class="card-title">👤 Meu Perfil</span>
<a href="?page=home" style="color:var(--primary);text-decoration:none;font-size:13px">← Voltar</a>
</div>

<div class="detail-section">
<div style="text-align:center;margin-bottom:20px">
<div style="width:80px;height:80px;background:var(--primary);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 12px">
<?=strtoupper(substr($employee['full_name'] ?? $employee['email'], 0, 1))?>
</div>
<h2 style="font-size:18px"><?=htmlspecialchars($employee['full_name'] ?? '')?></h2>
<p style="color:var(--text-muted);font-size:13px"><?=htmlspecialchars($employee['email'])?></p>
</div>

<div class="detail-row"><span>Telefone</span><span><?=htmlspecialchars($employee['phone'] ?? '-')?></span></div>
<div class="detail-row"><span>Entregas este mês</span><strong><?=$stats['month']?></strong></div>
<div class="detail-row"><span>Ganhos este mês</span><strong style="color:var(--primary)">R$ <?=number_format($stats['earnings'],2,',','.')?></strong></div>
</div>

<form method="POST">
<input type="hidden" name="action" value="logout">
<button type="submit" class="btn btn-danger">🚪 Sair</button>
</form>

<?php endif; ?>

</div>

<!-- Nav Bottom -->
<nav class="nav-bottom">
<a href="?page=home" class="nav-item <?=$page==='home'?'active':''?>">
<span class="icon">🏠</span>
<span>Início</span>
</a>
<a href="?page=disponiveis" class="nav-item <?=$page==='disponiveis'?'active':''?>">
<span class="icon">🔔</span>
<span>Disponíveis</span>
</a>
<a href="?page=historico" class="nav-item <?=$page==='historico'?'active':''?>">
<span class="icon">📜</span>
<span>Histórico</span>
</a>
<a href="?page=perfil" class="nav-item <?=$page==='perfil'?'active':''?>">
<span class="icon">👤</span>
<span>Perfil</span>
</a>
</nav>

<?php endif; ?>


<script>
// Header scroll effect
(function() {
    const header = document.querySelector('.header, .site-header, [class*="header-main"]');
    if (!header) return;
    
    let lastScroll = 0;
    let ticking = false;
    
    function updateHeader() {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
        
        // Hide/show on scroll (opcional)
        /*
        if (currentScroll > lastScroll && currentScroll > 100) {
            header.style.transform = 'translateY(-100%)';
        } else {
            header.style.transform = 'translateY(0)';
        }
        */
        
        lastScroll = currentScroll;
        ticking = false;
    }
    
    window.addEventListener('scroll', function() {
        if (!ticking) {
            requestAnimationFrame(updateHeader);
            ticking = true;
        }
    });
    
    // Cart badge animation
    window.animateCartBadge = function() {
        const badge = document.querySelector('.cart-badge, .carrinho-badge, [class*="cart-count"]');
        if (badge) {
            badge.style.transform = 'scale(1.3)';
            setTimeout(() => {
                badge.style.transform = 'scale(1)';
            }, 200);
        }
    };
    
    // Mobile search toggle
    const searchToggle = document.querySelector('.search-toggle, [class*="search-btn"]');
    const searchContainer = document.querySelector('.search-container, .search-box');
    
    if (searchToggle && searchContainer) {
        searchToggle.addEventListener('click', function() {
            searchContainer.classList.toggle('active');
        });
    }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     🎨 ONEMUNDO HEADER PREMIUM v3.0 - CSS FINAL UNIFICADO
═══════════════════════════════════════════════════════════════════════════════ -->
<style id="om-header-final">
/* RESET */
.mkt-header, .mkt-header-row, .mkt-logo, .mkt-logo-box, .mkt-logo-text,
.mkt-user, .mkt-user-avatar, .mkt-guest, .mkt-cart, .mkt-cart-count, .mkt-search,
.om-topbar, .om-topbar-main, .om-topbar-icon, .om-topbar-content,
.om-topbar-label, .om-topbar-address, .om-topbar-arrow, .om-topbar-time {
    all: revert;
}

/* TOPBAR VERDE */
.om-topbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 14px 20px !important;
    background: linear-gradient(135deg, #047857 0%, #059669 40%, #10b981 100%) !important;
    color: #fff !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    position: relative !important;
    overflow: hidden !important;
}

.om-topbar::before {
    content: '' !important;
    position: absolute !important;
    top: 0 !important;
    left: -100% !important;
    width: 100% !important;
    height: 100% !important;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent) !important;
    transition: left 0.6s ease !important;
}

.om-topbar:hover::before { left: 100% !important; }
.om-topbar:hover { background: linear-gradient(135deg, #065f46 0%, #047857 40%, #059669 100%) !important; }

.om-topbar-main {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.om-topbar-icon {
    width: 40px !important;
    height: 40px !important;
    background: rgba(255,255,255,0.18) !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar:hover .om-topbar-icon {
    background: rgba(255,255,255,0.25) !important;
    transform: scale(1.05) !important;
}

.om-topbar-icon svg { width: 20px !important; height: 20px !important; color: #fff !important; }

.om-topbar-content { flex: 1 !important; min-width: 0 !important; }

.om-topbar-label {
    font-size: 11px !important;
    font-weight: 500 !important;
    opacity: 0.85 !important;
    margin-bottom: 2px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: block !important;
}

.om-topbar-address {
    font-size: 14px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: 220px !important;
}

.om-topbar-arrow {
    width: 32px !important;
    height: 32px !important;
    background: rgba(255,255,255,0.12) !important;
    border-radius: 8px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    transition: all 0.3s ease !important;
    margin-right: 12px !important;
}

.om-topbar:hover .om-topbar-arrow {
    background: rgba(255,255,255,0.2) !important;
    transform: translateX(3px) !important;
}

.om-topbar-arrow svg { width: 16px !important; height: 16px !important; color: #fff !important; }

.om-topbar-time {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 8px 14px !important;
    background: rgba(0,0,0,0.2) !important;
    border-radius: 50px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar-time:hover { background: rgba(0,0,0,0.3) !important; transform: scale(1.02) !important; }
.om-topbar-time svg { width: 16px !important; height: 16px !important; color: #34d399 !important; }

/* HEADER BRANCO */
.mkt-header {
    background: #ffffff !important;
    padding: 0 !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 9999 !important;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08) !important;
    border-bottom: none !important;
}

.mkt-header-row {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 14px 20px !important;
    margin-bottom: 0 !important;
    background: #fff !important;
    border-bottom: 1px solid rgba(0,0,0,0.06) !important;
}

/* LOGO */
.mkt-logo {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-decoration: none !important;
    flex-shrink: 0 !important;
}

.mkt-logo-box {
    width: 44px !important;
    height: 44px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 14px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 22px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-logo:hover .mkt-logo-box {
    transform: scale(1.05) rotate(-3deg) !important;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45) !important;
}

.mkt-logo-text {
    font-size: 20px !important;
    font-weight: 800 !important;
    color: #10b981 !important;
    letter-spacing: -0.02em !important;
}

/* USER */
.mkt-user { margin-left: auto !important; text-decoration: none !important; }

.mkt-user-avatar {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 50% !important;
    color: #fff !important;
    font-weight: 700 !important;
    font-size: 16px !important;
    box-shadow: 0 3px 12px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.mkt-user-avatar:hover {
    transform: scale(1.08) !important;
    box-shadow: 0 5px 18px rgba(16, 185, 129, 0.4) !important;
}

.mkt-user.mkt-guest {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: #f1f5f9 !important;
    border-radius: 12px !important;
    transition: all 0.3s ease !important;
}

.mkt-user.mkt-guest:hover { background: #e2e8f0 !important; }
.mkt-user.mkt-guest svg { width: 24px !important; height: 24px !important; color: #64748b !important; }

/* CARRINHO */
.mkt-cart {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 46px !important;
    height: 46px !important;
    background: linear-gradient(135deg, #1e293b, #0f172a) !important;
    border: none !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    box-shadow: 0 4px 15px rgba(15, 23, 42, 0.25) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-cart:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.3) !important;
}

.mkt-cart:active { transform: translateY(-1px) scale(0.98) !important; }
.mkt-cart svg { width: 22px !important; height: 22px !important; color: #fff !important; }

.mkt-cart-count {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 22px !important;
    height: 22px !important;
    padding: 0 6px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border-radius: 11px !important;
    color: #fff !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 2px solid #fff !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: cartPulse 2s ease-in-out infinite !important;
}

@keyframes cartPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

/* BUSCA */
.mkt-search {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    background: #f1f5f9 !important;
    border-radius: 14px !important;
    padding: 0 16px !important;
    margin: 0 16px 16px !important;
    border: 2px solid transparent !important;
    transition: all 0.3s ease !important;
}

.mkt-search:focus-within {
    background: #fff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1) !important;
}

.mkt-search svg {
    width: 20px !important;
    height: 20px !important;
    color: #94a3b8 !important;
    flex-shrink: 0 !important;
    transition: color 0.3s ease !important;
}

.mkt-search:focus-within svg { color: #10b981 !important; }

.mkt-search input {
    flex: 1 !important;
    border: none !important;
    background: transparent !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    outline: none !important;
    padding: 14px 0 !important;
    width: 100% !important;
}

.mkt-search input::placeholder { color: #94a3b8 !important; }

/* RESPONSIVO */
@media (max-width: 480px) {
    .om-topbar { padding: 12px 16px !important; }
    .om-topbar-icon { width: 36px !important; height: 36px !important; }
    .om-topbar-address { max-width: 150px !important; font-size: 13px !important; }
    .om-topbar-arrow { display: none !important; }
    .om-topbar-time { padding: 6px 10px !important; font-size: 11px !important; }
    .mkt-header-row { padding: 12px 16px !important; }
    .mkt-logo-box { width: 40px !important; height: 40px !important; font-size: 18px !important; }
    .mkt-logo-text { font-size: 18px !important; }
    .mkt-cart { width: 42px !important; height: 42px !important; }
    .mkt-search { margin: 0 12px 12px !important; }
    .mkt-search input { font-size: 14px !important; padding: 12px 0 !important; }
}

/* ANIMAÇÕES */
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.mkt-header { animation: slideDown 0.4s ease !important; }

::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
::selection { background: rgba(16, 185, 129, 0.2); color: #047857; }
</style>

<script>
(function() {
    var h = document.querySelector('.mkt-header');
    if (h && !document.querySelector('.om-topbar')) {
        var t = document.createElement('div');
        t.className = 'om-topbar';
        t.innerHTML = '<div class="om-topbar-main"><div class="om-topbar-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><div class="om-topbar-content"><div class="om-topbar-label">Entregar em</div><div class="om-topbar-address" id="omAddrFinal">Carregando...</div></div><div class="om-topbar-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div></div><div class="om-topbar-time"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>25-35 min</div>';
        h.insertBefore(t, h.firstChild);
        fetch('/mercado/api/address.php?action=list').then(r=>r.json()).then(d=>{var el=document.getElementById('omAddrFinal');if(el&&d.current)el.textContent=d.current.address_1||'Selecionar';}).catch(()=>{});
    }
    var l = document.querySelector('.mkt-logo');
    if (l && !l.querySelector('.mkt-logo-text')) {
        var s = document.createElement('span');
        s.className = 'mkt-logo-text';
        s.textContent = 'Mercado';
        l.appendChild(s);
    }
})();
</script>
</body>
</html>
