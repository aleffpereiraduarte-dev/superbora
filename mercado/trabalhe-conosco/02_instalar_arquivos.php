<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ╔═══════════════════════════════════════════════════════════════════════════════╗
 * ║  02 - CRIAR ESTRUTURA DE ARQUIVOS                                            ║
 * ║  OneMundo Shopper App                                                         ║
 * ╚═══════════════════════════════════════════════════════════════════════════════╝
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>02 - Estrutura</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;padding:20px;line-height:1.6}
.container{max-width:900px;margin:0 auto}
h1{color:#10b981;margin-bottom:20px}
.card{background:#1e293b;border-radius:12px;padding:20px;margin-bottom:16px}
.success{color:#10b981}.error{color:#ef4444}.warning{color:#f59e0b}
.log{font-family:monospace;font-size:13px;padding:8px 0;border-bottom:1px solid #334155}
.btn{display:inline-block;padding:12px 24px;background:#10b981;color:#fff;text-decoration:none;border-radius:8px;margin:5px}
pre{background:#0f172a;padding:15px;border-radius:8px;overflow-x:auto;font-size:12px}
</style></head><body><div class='container'>";

echo "<h1>📁 02 - Criar Estrutura de Arquivos</h1>";

$baseDir = __DIR__;
$logs = [];

function criar_pasta($path) {
    global $logs;
    if (!is_dir($path)) {
        if (mkdir($path, 0755, true)) {
            $logs[] = "<div class='log success'>✅ Pasta criada: $path</div>";
            return true;
        } else {
            $logs[] = "<div class='log error'>❌ Erro ao criar: $path</div>";
            return false;
        }
    }
    $logs[] = "<div class='log warning'>⏭️ Já existe: $path</div>";
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════════
// CRIAR PASTAS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='card'><h3>📁 Criando Pastas</h3>";

$pastas = [
    'api',
    'includes',
    'assets',
    'assets/css',
    'assets/js',
    'assets/img',
    'uploads',
    'uploads/documents',
    'uploads/photos'
];

foreach ($pastas as $pasta) {
    criar_pasta($baseDir . '/' . $pasta);
}

echo implode('', $logs);
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIG.PHP
// ═══════════════════════════════════════════════════════════════════════════════

$logs = [];
echo "<div class='card'><h3>⚙️ Criando config.php</h3>";

$configContent = '<?php
/**
 * Configurações do Sistema
 */

// Sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Timezone
date_default_timezone_set("America/Sao_Paulo");

// Erros (desabilitar em produção)
error_reporting(E_ALL);
ini_set("display_errors", 0);

// Banco de dados
define("DB_HOST", "localhost");
define("DB_NAME", "love1");
define("DB_USER", "love1");
// DB_PASS loaded from central config

// URLs
define("BASE_URL", "/mercado/trabalhe-conosco");
define("SITE_URL", "https://onemundo.com.br");

// Uploads
define("UPLOAD_DIR", __DIR__ . "/uploads");
define("MAX_UPLOAD_SIZE", 10 * 1024 * 1024); // 10MB

// API Keys (loaded from environment)
define("PAGARME_API_KEY", getenv("PAGARME_API_KEY") ?: "");
define("TWILIO_SID", getenv("TWILIO_SID") ?: "");
define("TWILIO_TOKEN", getenv("TWILIO_TOKEN") ?: "");
define("TWILIO_FROM", getenv("TWILIO_PHONE") ?: "");

// Conexão PDO
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Erro de conexão: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Funções de Autenticação
function isLoggedIn() {
    return isset($_SESSION["worker_id"]) && !empty($_SESSION["worker_id"]);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function getWorkerId() {
    return $_SESSION["worker_id"] ?? null;
}

function getWorker() {
    if (!isLoggedIn()) return null;
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([getWorkerId()]);
    return $stmt->fetch();
}

// Funções de Segurança
function generateCSRF() {
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

function validateCSRF($token) {
    return isset($_SESSION["csrf_token"]) && hash_equals($_SESSION["csrf_token"], $token);
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, "UTF-8");
}

// Funções de Formatação
function formatMoney($value) {
    return "R$ " . number_format($value, 2, ",", ".");
}

function formatDate($date, $format = "d/m/Y") {
    return date($format, strtotime($date));
}

function formatPhone($phone) {
    $phone = preg_replace("/\D/", "", $phone);
    if (strlen($phone) == 11) {
        return "(" . substr($phone, 0, 2) . ") " . substr($phone, 2, 5) . "-" . substr($phone, 7);
    }
    return $phone;
}

// Resposta JSON
function jsonResponse($success, $message = "", $data = []) {
    header("Content-Type: application/json");
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);
    exit;
}
';

$configPath = $baseDir . '/includes/config.php';
if (file_put_contents($configPath, $configContent)) {
    $logs[] = "<div class='log success'>✅ config.php criado</div>";
} else {
    $logs[] = "<div class='log error'>❌ Erro ao criar config.php</div>";
}

echo implode('', $logs);
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// FUNCTIONS.PHP
// ═══════════════════════════════════════════════════════════════════════════════

$logs = [];
echo "<div class='card'><h3>🔧 Criando functions.php</h3>";

$functionsContent = '<?php
/**
 * Funções do Sistema Shopper
 */

require_once __DIR__ . "/config.php";

// ═══════════════════════════════════════════════════════════════════════════════
// CARTEIRA / WALLET
// ═══════════════════════════════════════════════════════════════════════════════

function getBalance($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT balance FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    return floatval($stmt->fetchColumn() ?: 0);
}

function getPendingBalance($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT pending_balance FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    return floatval($stmt->fetchColumn() ?: 0);
}

function getEarningsToday($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(shopper_earnings + COALESCE(tip_amount, 0)), 0) 
        FROM om_market_orders 
        WHERE shopper_id = ? AND DATE(completed_at) = CURRENT_DATE AND status = \"completed\"
    ");
    $stmt->execute([$workerId]);
    return floatval($stmt->fetchColumn());
}

function getEarningsWeek($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(shopper_earnings + COALESCE(tip_amount, 0)), 0) 
        FROM om_market_orders 
        WHERE shopper_id = ? AND YEARWEEK(completed_at) = YEARWEEK(CURRENT_DATE) AND status = \"completed\"
    ");
    $stmt->execute([$workerId]);
    return floatval($stmt->fetchColumn());
}

function getEarningsMonth($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(shopper_earnings + COALESCE(tip_amount, 0)), 0) 
        FROM om_market_orders 
        WHERE shopper_id = ? AND MONTH(completed_at) = MONTH(CURRENT_DATE) AND YEAR(completed_at) = YEAR(CURRENT_DATE) AND status = \"completed\"
    ");
    $stmt->execute([$workerId]);
    return floatval($stmt->fetchColumn());
}

function getOrdersToday($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM om_market_orders 
        WHERE shopper_id = ? AND DATE(completed_at) = CURRENT_DATE AND status = \"completed\"
    ");
    $stmt->execute([$workerId]);
    return intval($stmt->fetchColumn());
}

function getOrdersWeek($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM om_market_orders 
        WHERE shopper_id = ? AND YEARWEEK(completed_at) = YEARWEEK(CURRENT_DATE) AND status = \"completed\"
    ");
    $stmt->execute([$workerId]);
    return intval($stmt->fetchColumn());
}

// ═══════════════════════════════════════════════════════════════════════════════
// SAQUES
// ═══════════════════════════════════════════════════════════════════════════════

function getWithdrawals($workerId, $limit = 10) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM om_market_worker_payouts 
        WHERE worker_id = ? 
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$workerId, $limit]);
    return $stmt->fetchAll();
}

function hasPendingWithdrawal($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM om_market_worker_payouts 
        WHERE worker_id = ? AND status IN (\"pending\", \"processing\")
    ");
    $stmt->execute([$workerId]);
    return intval($stmt->fetchColumn()) > 0;
}

function requestWithdrawal($workerId, $amount) {
    $pdo = getDB();
    
    // Verificar saldo
    $balance = getBalance($workerId);
    if ($amount > $balance) {
        return ["success" => false, "message" => "Saldo insuficiente"];
    }
    
    if ($amount < 20) {
        return ["success" => false, "message" => "Valor mínimo: R$ 20,00"];
    }
    
    // Verificar saque pendente
    if (hasPendingWithdrawal($workerId)) {
        return ["success" => false, "message" => "Você já tem um saque pendente"];
    }
    
    // Buscar dados PIX
    $worker = getWorker();
    if (empty($worker["bank_pix_key"])) {
        return ["success" => false, "message" => "Configure sua chave PIX primeiro"];
    }
    
    try {
        $pdo->beginTransaction();
        
        // Inserir saque
        $stmt = $pdo->prepare("
            INSERT INTO om_market_worker_payouts (worker_id, amount, pix_key, pix_type, status, requested_at) 
            VALUES (?, ?, ?, ?, \"pending\", NOW())
        ");
        $stmt->execute([$workerId, $amount, $worker["bank_pix_key"], $worker["bank_pix_type"]]);
        
        // Deduzir do saldo
        $pdo->prepare("UPDATE om_market_workers SET balance = balance - ? WHERE worker_id = ?")
            ->execute([$amount, $workerId]);
        
        // Registrar transação
        $newBalance = getBalance($workerId);
        $pdo->prepare("
            INSERT INTO om_market_wallet_transactions (worker_id, type, amount, balance_after, description, reference_type, reference_id) 
            VALUES (?, \"withdrawal\", ?, ?, \"Saque PIX\", \"payout\", ?)
        ")->execute([$workerId, -$amount, $newBalance, $pdo->lastInsertId()]);
        
        $pdo->commit();
        
        return ["success" => true, "message" => "Saque solicitado com sucesso!"];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ["success" => false, "message" => "Erro ao processar saque"];
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// TRANSAÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

function getTransactions($workerId, $limit = 20, $type = null, $month = null) {
    $pdo = getDB();
    
    $sql = "SELECT * FROM om_market_wallet_transactions WHERE worker_id = ?";
    $params = [$workerId];
    
    if ($type) {
        $sql .= " AND type = ?";
        $params[] = $type;
    }
    
    if ($month) {
        $sql .= " AND DATE_FORMAT(created_at, \"%Y-%m\") = ?";
        $params[] = $month;
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════════════════════════════════════
// OFERTAS / PEDIDOS
// ═══════════════════════════════════════════════════════════════════════════════

function getAvailableOffers($workerId, $limit = 10) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT o.*, ord.order_number, ord.partner_name, ord.delivery_address,
               (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = ord.order_id) as items_count
        FROM om_shopper_offers o
        JOIN om_market_orders ord ON o.order_id = ord.order_id
        WHERE o.status = \"pending\" 
        AND (o.shopper_id IS NULL OR o.shopper_id = ?)
        AND o.expires_at > NOW()
        ORDER BY o.priority DESC, o.created_at ASC
        LIMIT ?
    ");
    $stmt->execute([$workerId, $limit]);
    return $stmt->fetchAll();
}

function acceptOffer($offerId, $workerId) {
    $pdo = getDB();
    
    try {
        $pdo->beginTransaction();
        
        // Verificar se oferta ainda está disponível
        $stmt = $pdo->prepare("SELECT * FROM om_shopper_offers WHERE offer_id = ? AND status = \"pending\" FOR UPDATE");
        $stmt->execute([$offerId]);
        $offer = $stmt->fetch();
        
        if (!$offer) {
            $pdo->rollBack();
            return ["success" => false, "message" => "Oferta não disponível"];
        }
        
        // Aceitar oferta
        $pdo->prepare("UPDATE om_shopper_offers SET status = \"accepted\", shopper_id = ?, accepted_at = NOW() WHERE offer_id = ?")
            ->execute([$workerId, $offerId]);
        
        // Atualizar pedido
        $pdo->prepare("UPDATE om_market_orders SET shopper_id = ?, status = \"accepted\", accepted_at = NOW() WHERE order_id = ?")
            ->execute([$workerId, $offer["order_id"]]);
        
        // Rejeitar outras ofertas do mesmo pedido
        $pdo->prepare("UPDATE om_shopper_offers SET status = \"expired\" WHERE order_id = ? AND offer_id != ?")
            ->execute([$offer["order_id"], $offerId]);
        
        $pdo->commit();
        
        return ["success" => true, "message" => "Pedido aceito!", "order_id" => $offer["order_id"]];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ["success" => false, "message" => "Erro ao aceitar pedido"];
    }
}

function rejectOffer($offerId, $workerId) {
    $pdo = getDB();
    $pdo->prepare("UPDATE om_shopper_offers SET status = \"rejected\", rejected_at = NOW() WHERE offer_id = ? AND shopper_id = ?")
        ->execute([$offerId, $workerId]);
    return ["success" => true];
}

// ═══════════════════════════════════════════════════════════════════════════════
// SCORE / GAMIFICAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

function getLevel($xp) {
    if ($xp < 500) return 1;
    if ($xp < 1500) return 2;
    if ($xp < 3000) return 3;
    if ($xp < 5000) return 4;
    return 5;
}

function getLevelName($level) {
    $names = [
        1 => "Iniciante",
        2 => "Bronze",
        3 => "Prata",
        4 => "Ouro",
        5 => "Diamante"
    ];
    return $names[$level] ?? "Iniciante";
}

function getXpForNextLevel($level) {
    $xp = [1 => 500, 2 => 1500, 3 => 3000, 4 => 5000, 5 => 999999];
    return $xp[$level] ?? 500;
}

function addXp($workerId, $amount, $reason = "") {
    $pdo = getDB();
    $pdo->prepare("UPDATE om_market_workers SET xp_points = xp_points + ? WHERE worker_id = ?")
        ->execute([$amount, $workerId]);
    
    // Verificar se subiu de nível
    $worker = getWorker();
    $newLevel = getLevel($worker["xp_points"]);
    if ($newLevel > $worker["level"]) {
        $pdo->prepare("UPDATE om_market_workers SET level = ? WHERE worker_id = ?")
            ->execute([$newLevel, $workerId]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// NOTIFICAÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

function getNotifications($workerId, $limit = 20, $unreadOnly = false) {
    $pdo = getDB();
    
    $sql = "SELECT * FROM om_worker_notifications WHERE worker_id = ?";
    if ($unreadOnly) {
        $sql .= " AND is_read = 0";
    }
    $sql .= " ORDER BY created_at DESC LIMIT ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$workerId, $limit]);
    return $stmt->fetchAll();
}

function getUnreadCount($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_worker_notifications WHERE worker_id = ? AND is_read = 0");
    $stmt->execute([$workerId]);
    return intval($stmt->fetchColumn());
}

function markNotificationRead($notificationId, $workerId) {
    $pdo = getDB();
    $pdo->prepare("UPDATE om_worker_notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND worker_id = ?")
        ->execute([$notificationId, $workerId]);
}

function createNotification($workerId, $type, $title, $message, $data = []) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO om_worker_notifications (worker_id, type, title, message, data) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$workerId, $type, $title, $message, json_encode($data)]);
    return $pdo->lastInsertId();
}

// ═══════════════════════════════════════════════════════════════════════════════
// ONLINE/OFFLINE
// ═══════════════════════════════════════════════════════════════════════════════

function setOnlineStatus($workerId, $isOnline) {
    $pdo = getDB();
    $pdo->prepare("UPDATE om_market_workers SET is_online = ?, last_online_at = NOW() WHERE worker_id = ?")
        ->execute([$isOnline ? 1 : 0, $workerId]);
}

function updateLocation($workerId, $lat, $lng) {
    $pdo = getDB();
    
    // Atualizar localização atual
    $pdo->prepare("UPDATE om_market_workers SET current_lat = ?, current_lng = ?, last_location_at = NOW() WHERE worker_id = ?")
        ->execute([$lat, $lng, $workerId]);
    
    // Histórico (opcional - pode gerar muitos dados)
    // $pdo->prepare("INSERT INTO om_market_worker_locations (worker_id, latitude, longitude) VALUES (?, ?, ?)")
    //     ->execute([$workerId, $lat, $lng]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROMOÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

function getActivePromotions() {
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT * FROM om_market_promotions 
        WHERE status = \"active\" 
        AND (start_date IS NULL OR start_date <= NOW())
        AND (end_date IS NULL OR end_date >= NOW())
        ORDER BY created_at DESC
    ");
    return $stmt->fetchAll();
}
';

$functionsPath = $baseDir . '/includes/functions.php';
if (file_put_contents($functionsPath, $functionsContent)) {
    $logs[] = "<div class='log success'>✅ functions.php criado</div>";
} else {
    $logs[] = "<div class='log error'>❌ Erro ao criar functions.php</div>";
}

echo implode('', $logs);
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// ESTRUTURA CRIADA
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='card'><h3>📋 Estrutura Final</h3>";
echo "<pre>";
echo "trabalhe-conosco/
├── 📁 api/
├── 📁 includes/
│   ├── config.php ✅
│   └── functions.php ✅
├── 📁 assets/
│   ├── css/
│   ├── js/
│   └── img/
├── 📁 uploads/
│   ├── documents/
│   └── photos/
├── login.php (próximo)
├── dashboard.php (próximo)
├── carteira.php (próximo)
└── ...
</pre>";
echo "</div>";

echo "<div style='text-align:center;margin-top:20px'>";
echo "<a href='01_criar_banco.php' class='btn' style='background:#64748b'>← Anterior</a>";
echo "<a href='03_instalar_login.php' class='btn'>Próximo: 03 - Login →</a>";
echo "</div>";

echo "</div></body></html>";
