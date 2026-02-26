<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * 🔧 INSTALADOR 2 - Páginas Faltando
 * Upload em: /mercado/trabalhe-conosco/INSTALAR_02_PAGINAS.php
 * 
 * Cria as páginas que faltam:
 * - verificar.php (verificação SMS/Email)
 * - aguardando.php (tela aguardando aprovação)
 * - ofertas.php (lista de ofertas)
 * - pedido.php (detalhes do pedido)
 * - handoff.php (QR Code para entrega)
 * - saque.php (solicitar saque PIX)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 2 - Páginas</title>";
echo "<style>
body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: #fff; padding: 30px; min-height: 100vh; }
.container { max-width: 900px; margin: 0 auto; }
h1 { color: #667eea; }
.card { background: rgba(255,255,255,0.05); border-radius: 16px; padding: 25px; margin-bottom: 20px; border: 1px solid rgba(255,255,255,0.1); }
.ok { color: #00b894; }
.erro { color: #e74c3c; }
.step { padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; gap: 10px; }
.btn { display: inline-block; padding: 15px 30px; background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold; margin-top: 20px; }
</style></head><body>";

echo "<div class='container'>";
echo "<h1>🔧 Instalador 2 - Páginas Faltando</h1>";

$basePath = __DIR__;
$created = 0;
$skipped = 0;

// ==================== verificar.php ====================
$verificarPHP = '<?php
/**
 * Verificação SMS/Email - Trabalhe Conosco
 * /mercado/trabalhe-conosco/verificar.php
 */
session_start();

if (!isset($_SESSION["pending_worker_id"])) {
    header("Location: cadastro.php");
    exit;
}

$workerId = $_SESSION["pending_worker_id"];
$phone = $_SESSION["pending_phone"] ?? "";
$email = $_SESSION["pending_email"] ?? "";

// Processar verificação
$error = "";
$success = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $code = $_POST["code"] ?? "";
    
    try {
        $pdo = getPDO();
        
        $stmt = $pdo->prepare("SELECT verification_code, verification_code_expires FROM om_market_workers WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($worker && $worker["verification_code"] === $code) {
            if (strtotime($worker["verification_code_expires"]) > time()) {
                // Código válido
                $stmt = $pdo->prepare("UPDATE om_market_workers SET 
                    verified_at = NOW(), 
                    verified_phone = 1,
                    verification_code = NULL 
                    WHERE worker_id = ?");
                $stmt->execute([$workerId]);
                
                $success = true;
                $_SESSION["worker_verified"] = true;
                
                // Redirecionar após 2 segundos
                header("Refresh: 2; url=documentos.php");
            } else {
                $error = "Código expirado. Solicite um novo.";
            }
        } else {
            $error = "Código inválido.";
        }
    } catch (Exception $e) {
        $error = "Erro ao verificar: " . $e->getMessage();
    }
}

// Reenviar código
if (isset($_GET["resend"])) {
    try {
        $pdo = getPDO();
        
        $newCode = str_pad(rand(0, 999999), 6, "0", STR_PAD_LEFT);
        $expires = date("Y-m-d H:i:s", strtotime("+10 minutes"));
        
        $stmt = $pdo->prepare("UPDATE om_market_workers SET verification_code = ?, verification_code_expires = ? WHERE worker_id = ?");
        $stmt->execute([$newCode, $expires, $workerId]);
        
        // TODO: Enviar SMS/Email com o código
        // Por enquanto, mostrar na tela para teste
        $_SESSION["debug_code"] = $newCode;
        
        header("Location: verificar.php?sent=1");
        exit;
    } catch (Exception $e) {
        $error = "Erro ao reenviar: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Verificar - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 20px;
            padding: 40px 30px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .icon { font-size: 4rem; margin-bottom: 20px; }
        h1 { font-size: 1.5rem; margin-bottom: 10px; color: #1a1a2e; }
        p { color: #666; margin-bottom: 30px; }
        .phone { font-weight: bold; color: #667eea; }
        .code-inputs {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 30px;
        }
        .code-inputs input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: bold;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            outline: none;
            transition: all 0.2s;
        }
        .code-inputs input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.2);
        }
        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn:disabled { opacity: 0.5; }
        .resend {
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .error { background: #fee; color: #c00; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .success { background: #efe; color: #080; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .debug { background: #fff3cd; color: #856404; padding: 10px; border-radius: 8px; margin-top: 20px; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($success): ?>
            <div class="icon">✅</div>
            <h1>Verificado!</h1>
            <p>Redirecionando para upload de documentos...</p>
        <?php else: ?>
            <div class="icon">📱</div>
            <h1>Verificar Telefone</h1>
            <p>Digite o código de 6 dígitos enviado para<br><span class="phone"><?= substr($phone, 0, 4) ?>****<?= substr($phone, -2) ?></span></p>
            
            <?php if ($error): ?>
                <div class="error"><?= $error ?></div>
            <?php endif; ?>
            
            <?php if (isset($_GET["sent"])): ?>
                <div class="success">Código reenviado!</div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="code-inputs">
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric" autofocus>
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric">
                </div>
                <input type="hidden" name="code" id="fullCode">
                <button type="submit" class="btn" id="submitBtn" disabled>Verificar</button>
            </form>
            
            <a href="?resend=1" class="resend">Não recebeu? Reenviar código</a>
            
            <?php if (isset($_SESSION["debug_code"])): ?>
                <div class="debug">🔧 Debug: Código = <strong><?= $_SESSION["debug_code"] ?></strong></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
    const inputs = document.querySelectorAll(".code-inputs input");
    const fullCode = document.getElementById("fullCode");
    const submitBtn = document.getElementById("submitBtn");
    
    inputs.forEach((input, index) => {
        input.addEventListener("input", (e) => {
            if (e.target.value && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
            updateCode();
        });
        
        input.addEventListener("keydown", (e) => {
            if (e.key === "Backspace" && !e.target.value && index > 0) {
                inputs[index - 1].focus();
            }
        });
        
        input.addEventListener("paste", (e) => {
            e.preventDefault();
            const paste = e.clipboardData.getData("text").replace(/\D/g, "").slice(0, 6);
            paste.split("").forEach((char, i) => {
                if (inputs[i]) inputs[i].value = char;
            });
            updateCode();
        });
    });
    
    function updateCode() {
        const code = Array.from(inputs).map(i => i.value).join("");
        fullCode.value = code;
        submitBtn.disabled = code.length !== 6;
    }
    </script>
</body>
</html>';

// ==================== aguardando.php ====================
$aguardandoPHP = '<?php
/**
 * Tela Aguardando Aprovação - Trabalhe Conosco
 * /mercado/trabalhe-conosco/aguardando.php
 */
session_start();

$workerId = $_SESSION["worker_id"] ?? $_SESSION["pending_worker_id"] ?? 0;

if (!$workerId) {
    header("Location: login.php");
    exit;
}

// Buscar status
try {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT name, worker_type, application_status, created_at FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$worker) {
        header("Location: login.php");
        exit;
    }
    
    // Se já foi aprovado, redirecionar para o app
    if (in_array($worker["application_status"], ["approved", "active"])) {
        $_SESSION["worker_id"] = $workerId;
        header("Location: app.php");
        exit;
    }
    
} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}

$statusMessages = [
    "draft" => ["📝", "Cadastro Incompleto", "Complete seu cadastro para continuar."],
    "submitted" => ["📤", "Enviado para Análise", "Seus documentos estão sendo analisados."],
    "analyzing" => ["🤖", "Em Análise", "Nossa equipe está verificando seus dados."],
    "pending_rh" => ["👥", "Aguardando RH", "Estamos finalizando sua aprovação."],
    "rejected" => ["❌", "Não Aprovado", "Infelizmente não foi possível aprovar seu cadastro."],
];

$status = $worker["application_status"] ?? "submitted";
$s = $statusMessages[$status] ?? $statusMessages["submitted"];

$typeLabels = ["shopper" => "Shopper", "driver" => "Entregador", "full_service" => "Full Service"];
$typeColors = ["shopper" => "#00b894", "driver" => "#e17055", "full_service" => "#6c5ce7"];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aguardando Aprovação - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            padding: 40px 20px;
            text-align: center;
        }
        .header h1 { font-size: 1.5rem; margin-bottom: 5px; }
        .header p { opacity: 0.9; }
        .container { padding: 20px; max-width: 500px; margin: 0 auto; }
        .status-card {
            background: #fff;
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            margin-top: -40px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .status-icon { font-size: 4rem; margin-bottom: 20px; }
        .status-title { font-size: 1.3rem; font-weight: 600; margin-bottom: 10px; color: #1a1a2e; }
        .status-desc { color: #666; margin-bottom: 30px; }
        .type-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            color: #fff;
            background: <?= $typeColors[$worker["worker_type"]] ?? "#667eea" ?>;
        }
        .timeline {
            margin-top: 30px;
            text-align: left;
        }
        .timeline-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px 0;
            border-left: 2px solid #e0e0e0;
            margin-left: 10px;
            padding-left: 25px;
            position: relative;
        }
        .timeline-item::before {
            content: "";
            position: absolute;
            left: -7px;
            top: 18px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #e0e0e0;
        }
        .timeline-item.done::before { background: #00b894; }
        .timeline-item.current::before { background: #667eea; animation: pulse 1s infinite; }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.3); }
        }
        .timeline-item.done { color: #00b894; }
        .timeline-item.current { color: #667eea; font-weight: 600; }
        .info-box {
            background: #f0f4ff;
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            text-align: left;
        }
        .info-box h4 { color: #667eea; margin-bottom: 10px; }
        .info-box p { color: #666; font-size: 0.9rem; line-height: 1.6; }
        .refresh-btn {
            display: block;
            width: 100%;
            padding: 15px;
            background: #667eea;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            margin-top: 20px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Olá, <?= htmlspecialchars($worker["name"] ?? "Worker") ?>!</h1>
        <p>Seu cadastro está em análise</p>
    </div>
    
    <div class="container">
        <div class="status-card">
            <div class="status-icon"><?= $s[0] ?></div>
            <div class="status-title"><?= $s[1] ?></div>
            <div class="status-desc"><?= $s[2] ?></div>
            <span class="type-badge"><?= $typeLabels[$worker["worker_type"]] ?? $worker["worker_type"] ?></span>
            
            <div class="timeline">
                <div class="timeline-item done">Cadastro realizado</div>
                <div class="timeline-item <?= in_array($status, ["submitted","analyzing","pending_rh"]) ? "done" : "" ?>">Documentos enviados</div>
                <div class="timeline-item <?= $status === "analyzing" ? "current" : ($status === "pending_rh" ? "done" : "") ?>">Análise de documentos</div>
                <div class="timeline-item <?= $status === "pending_rh" ? "current" : "" ?>">Aprovação final</div>
            </div>
            
            <div class="info-box">
                <h4>📱 Fique atento!</h4>
                <p>Você receberá uma notificação por SMS e e-mail assim que seu cadastro for aprovado. O processo geralmente leva até 48 horas úteis.</p>
            </div>
            
            <button class="refresh-btn" onclick="location.reload()">🔄 Atualizar Status</button>
        </div>
    </div>
    
    <script>
    // Auto-refresh a cada 30 segundos
    setTimeout(() => location.reload(), 30000);
    </script>
</body>
</html>';

// ==================== ofertas.php ====================
$ofertasPHP = '<?php
/**
 * Lista de Ofertas - Trabalhe Conosco
 * /mercado/trabalhe-conosco/ofertas.php
 */
session_start();

if (!isset($_SESSION["worker_id"])) {
    header("Location: login.php");
    exit;
}

$workerId = $_SESSION["worker_id"];

try {
    $pdo = getPDO();
    
    // Buscar worker
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$worker || !$worker["is_online"]) {
        header("Location: app.php?msg=offline");
        exit;
    }
    
    // Buscar ofertas disponíveis
    $workerType = $worker["worker_type"];
    $workMode = $worker["work_mode"] ?? "both";
    $lat = $worker["current_lat"];
    $lng = $worker["current_lng"];
    $maxDistance = $worker["max_distance_km"] ?? 10;
    
    // Determinar quais status buscar baseado no tipo/modo
    $validStatuses = [];
    if ($workerType === "shopper" || ($workerType === "full_service" && in_array($workMode, ["shopping", "both"]))) {
        $validStatuses[] = "paid"; // Aguardando shopper
    }
    if ($workerType === "driver" || ($workerType === "full_service" && in_array($workMode, ["delivery", "both"]))) {
        $validStatuses[] = "ready_for_pickup"; // Aguardando driver
    }
    
    $statusPlaceholders = implode(",", array_fill(0, count($validStatuses), "?"));
    
    $sql = "SELECT o.*, 
                   p.store_name,
                   (6371 * acos(cos(radians(?)) * cos(radians(store_lat)) * 
                    cos(radians(store_lng) - radians(?)) + sin(radians(?)) * 
                    sin(radians(store_lat)))) AS distance
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status IN ($statusPlaceholders)
            AND o.worker_id IS NULL
            HAVING distance <= ?
            ORDER BY distance ASC, o.created_at ASC
            LIMIT 20";
    
    $params = array_merge([$lat, $lng, $lat], $validStatuses, [$maxDistance]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $offers = [];
    $error = $e->getMessage();
}

$typeColors = ["shopper" => "#00b894", "driver" => "#e17055", "full_service" => "#6c5ce7"];
$workerColor = $typeColors[$worker["worker_type"]] ?? "#667eea";
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ofertas - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding-bottom: 80px;
        }
        .header {
            background: <?= $workerColor ?>;
            color: #fff;
            padding: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header h1 { font-size: 1.3rem; }
        .header p { opacity: 0.9; font-size: 0.9rem; }
        .container { padding: 15px; }
        .offer-card {
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .offer-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        .offer-store { font-weight: 600; font-size: 1.1rem; }
        .offer-distance { 
            background: #f0f0f0; 
            padding: 5px 12px; 
            border-radius: 20px; 
            font-size: 0.85rem;
            color: #666;
        }
        .offer-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        .offer-info-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 10px;
        }
        .offer-info-label { font-size: 0.75rem; color: #999; margin-bottom: 3px; }
        .offer-info-value { font-weight: 600; color: #1a1a2e; }
        .offer-earnings {
            background: linear-gradient(135deg, <?= $workerColor ?>22, <?= $workerColor ?>11);
            padding: 15px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .offer-earnings-label { color: #666; }
        .offer-earnings-value { font-size: 1.5rem; font-weight: 700; color: <?= $workerColor ?>; }
        .offer-type {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 15px;
        }
        .type-shopping { background: #00b89422; color: #00b894; }
        .type-delivery { background: #e1705522; color: #e17055; }
        .type-full { background: #6c5ce722; color: #6c5ce7; }
        .accept-btn {
            width: 100%;
            padding: 16px;
            background: <?= $workerColor ?>;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        .accept-btn:active { transform: scale(0.98); }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state .icon { font-size: 4rem; margin-bottom: 20px; }
        .nav-bottom {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            padding: 10px 20px;
            display: flex;
            justify-content: space-around;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
        .nav-item {
            text-align: center;
            color: #999;
            text-decoration: none;
            padding: 10px;
        }
        .nav-item.active { color: <?= $workerColor ?>; }
        .nav-item .icon { font-size: 1.5rem; }
        .nav-item .label { font-size: 0.75rem; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📋 Ofertas Disponíveis</h1>
        <p><?= count($offers) ?> ofertas na sua região</p>
    </div>
    
    <div class="container">
        <?php if (empty($offers)): ?>
            <div class="empty-state">
                <div class="icon">🔍</div>
                <h3>Nenhuma oferta no momento</h3>
                <p>Novas ofertas aparecerão aqui automaticamente</p>
            </div>
        <?php else: ?>
            <?php foreach ($offers as $offer): 
                $isDelivery = $offer["status"] === "ready_for_pickup";
                $isShopping = $offer["status"] === "paid";
            ?>
            <div class="offer-card">
                <div class="offer-header">
                    <div class="offer-store"><?= htmlspecialchars($offer["store_name"] ?? "Mercado") ?></div>
                    <div class="offer-distance">📍 <?= number_format($offer["distance"], 1) ?> km</div>
                </div>
                
                <span class="offer-type <?= $isShopping ? "type-shopping" : "type-delivery" ?>">
                    <?= $isShopping ? "🛒 Compras" : "🚴 Entrega" ?>
                </span>
                
                <div class="offer-info">
                    <div class="offer-info-item">
                        <div class="offer-info-label">Itens</div>
                        <div class="offer-info-value"><?= $offer["total_items"] ?? "?" ?> produtos</div>
                    </div>
                    <div class="offer-info-item">
                        <div class="offer-info-label">Tempo estimado</div>
                        <div class="offer-info-value"><?= $isShopping ? "30-45 min" : "15-25 min" ?></div>
                    </div>
                </div>
                
                <div class="offer-earnings">
                    <span class="offer-earnings-label">Você ganha:</span>
                    <span class="offer-earnings-value">R$ <?= number_format($offer["worker_fee"] ?? 15, 2, ",", ".") ?></span>
                </div>
                
                <button class="accept-btn" onclick="acceptOffer(<?= $offer["order_id"] ?>)">
                    Aceitar Oferta
                </button>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <nav class="nav-bottom">
        <a href="app.php" class="nav-item"><div class="icon">🏠</div><div class="label">Início</div></a>
        <a href="ofertas.php" class="nav-item active"><div class="icon">📋</div><div class="label">Ofertas</div></a>
        <a href="ganhos.php" class="nav-item"><div class="icon">💰</div><div class="label">Ganhos</div></a>
        <a href="perfil.php" class="nav-item"><div class="icon">👤</div><div class="label">Perfil</div></a>
    </nav>
    
    <script>
    async function acceptOffer(orderId) {
        if (!confirm("Aceitar esta oferta?")) return;
        
        try {
            const res = await fetch("api/accept-offer.php", {
                method: "POST",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify({order_id: orderId})
            });
            const data = await res.json();
            
            if (data.success) {
                window.location.href = "pedido.php?id=" + orderId;
            } else {
                alert("Erro: " + (data.error || "Não foi possível aceitar"));
            }
        } catch (e) {
            alert("Erro de conexão");
        }
    }
    
    // Auto-refresh a cada 15 segundos
    setTimeout(() => location.reload(), 15000);
    </script>
</body>
</html>';

// ==================== pedido.php ====================
$pedidoPHP = '<?php
/**
 * Detalhes do Pedido - Trabalhe Conosco
 * /mercado/trabalhe-conosco/pedido.php
 */
session_start();

if (!isset($_SESSION["worker_id"])) {
    header("Location: login.php");
    exit;
}

$workerId = $_SESSION["worker_id"];
$orderId = $_GET["id"] ?? 0;

if (!$orderId) {
    header("Location: app.php");
    exit;
}

try {
    $pdo = getPDO();
    
    // Buscar pedido
    $stmt = $pdo->prepare("SELECT o.*, p.store_name, p.store_address, p.store_lat, p.store_lng,
                                  c.firstname, c.lastname, c.telephone as customer_phone
                           FROM om_market_orders o
                           LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                           LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
                           WHERE o.order_id = ? AND o.worker_id = ?");
    $stmt->execute([$orderId, $workerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header("Location: app.php?error=pedido_nao_encontrado");
        exit;
    }
    
    // Buscar itens
    $stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ? ORDER BY scanned_at IS NULL DESC, name ASC");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar worker
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}

$isShopping = in_array($order["status"], ["paid", "shopping"]);
$isDelivery = in_array($order["status"], ["ready_for_pickup", "delivering"]);

$scannedCount = 0;
foreach ($items as $item) {
    if (!empty($item["scanned_at"])) $scannedCount++;
}
$progress = count($items) > 0 ? ($scannedCount / count($items)) * 100 : 0;

$typeColors = ["shopper" => "#00b894", "driver" => "#e17055", "full_service" => "#6c5ce7"];
$workerColor = $typeColors[$worker["worker_type"]] ?? "#667eea";
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido #<?= $orderId ?> - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding-bottom: 100px;
        }
        .header {
            background: <?= $workerColor ?>;
            color: #fff;
            padding: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .header h1 { font-size: 1.2rem; }
        .header .back { color: #fff; text-decoration: none; font-size: 1.5rem; }
        .progress-bar { background: rgba(255,255,255,0.3); height: 8px; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: #fff; transition: width 0.3s; }
        .progress-text { font-size: 0.85rem; margin-top: 8px; opacity: 0.9; }
        .container { padding: 15px; }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .card-title { font-weight: 600; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; }
        .store-info h3 { font-size: 1.1rem; margin-bottom: 5px; }
        .store-info p { color: #666; font-size: 0.9rem; }
        .customer-info { display: flex; align-items: center; gap: 15px; }
        .customer-avatar { width: 50px; height: 50px; background: #f0f0f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .customer-details h4 { font-size: 1rem; margin-bottom: 3px; }
        .customer-details p { color: #666; font-size: 0.85rem; }
        .action-btns { display: flex; gap: 10px; margin-top: 15px; }
        .action-btn { flex: 1; padding: 12px; border: none; border-radius: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-call { background: #e0f7fa; color: #00838f; }
        .btn-chat { background: #e8f5e9; color: #2e7d32; }
        .btn-map { background: #fff3e0; color: #e65100; }
        .items-list { margin-top: 10px; }
        .item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .item:last-child { border-bottom: none; }
        .item-check {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .item-check.scanned { background: #00b894; border-color: #00b894; color: #fff; }
        .item-info { flex: 1; }
        .item-name { font-weight: 500; }
        .item-qty { font-size: 0.85rem; color: #666; }
        .item.scanned .item-name { text-decoration: line-through; opacity: 0.6; }
        .scan-btn {
            position: fixed;
            bottom: 20px;
            left: 20px;
            right: 20px;
            padding: 18px;
            background: <?= $workerColor ?>;
            color: #fff;
            border: none;
            border-radius: 16px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 5px 20px <?= $workerColor ?>66;
        }
        .complete-btn {
            background: linear-gradient(135deg, #00b894, #00cec9);
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-top">
            <a href="app.php" class="back">←</a>
            <h1>Pedido #<?= $orderId ?></h1>
            <span></span>
        </div>
        <?php if ($isShopping): ?>
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?= $progress ?>%"></div>
        </div>
        <div class="progress-text"><?= $scannedCount ?> de <?= count($items) ?> itens coletados</div>
        <?php endif; ?>
    </div>
    
    <div class="container">
        <!-- Mercado -->
        <div class="card">
            <div class="card-title">🏪 Mercado</div>
            <div class="store-info">
                <h3><?= htmlspecialchars($order["store_name"] ?? "Mercado") ?></h3>
                <p><?= htmlspecialchars($order["store_address"] ?? "") ?></p>
            </div>
            <div class="action-btns">
                <button class="action-btn btn-map" onclick="openMap(<?= $order["store_lat"] ?>, <?= $order["store_lng"] ?>)">📍 Mapa</button>
            </div>
        </div>
        
        <!-- Cliente -->
        <div class="card">
            <div class="card-title">👤 Cliente</div>
            <div class="customer-info">
                <div class="customer-avatar">👤</div>
                <div class="customer-details">
                    <h4><?= htmlspecialchars(($order["firstname"] ?? "") . " " . ($order["lastname"] ?? "")) ?></h4>
                    <p><?= htmlspecialchars($order["delivery_address"] ?? "") ?></p>
                </div>
            </div>
            <div class="action-btns">
                <a href="tel:<?= $order["customer_phone"] ?>" class="action-btn btn-call">📞 Ligar</a>
                <a href="chat.php?order=<?= $orderId ?>" class="action-btn btn-chat">💬 Chat</a>
            </div>
        </div>
        
        <?php if ($isShopping): ?>
        <!-- Lista de Itens -->
        <div class="card">
            <div class="card-title">🛒 Itens (<?= count($items) ?>)</div>
            <div class="items-list">
                <?php foreach ($items as $item): 
                    $scanned = !empty($item["scanned_at"]);
                ?>
                <div class="item <?= $scanned ? "scanned" : "" ?>" data-id="<?= $item["item_id"] ?>">
                    <div class="item-check <?= $scanned ? "scanned" : "" ?>" onclick="toggleItem(<?= $item["item_id"] ?>)">
                        <?= $scanned ? "✓" : "" ?>
                    </div>
                    <div class="item-info">
                        <div class="item-name"><?= htmlspecialchars($item["name"]) ?></div>
                        <div class="item-qty">Qtd: <?= $item["quantity"] ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if ($isShopping): ?>
    <button class="scan-btn" onclick="openScanner()">📷 Escanear Produto</button>
    <?php elseif ($isDelivery): ?>
    <button class="scan-btn complete-btn" onclick="location.href=\'delivery.php?order=<?= $orderId ?>\'">🚴 Iniciar Entrega</button>
    <?php endif; ?>
    
    <script>
    function openMap(lat, lng) {
        window.open("https://www.google.com/maps/dir/?api=1&destination=" + lat + "," + lng, "_blank");
    }
    
    function openScanner() {
        window.location.href = "shopping.php?order=<?= $orderId ?>";
    }
    
    async function toggleItem(itemId) {
        try {
            const res = await fetch("api/scan-item.php", {
                method: "POST",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify({item_id: itemId, order_id: <?= $orderId ?>})
            });
            const data = await res.json();
            if (data.success) {
                location.reload();
            }
        } catch (e) {
            console.error(e);
        }
    }
    </script>
</body>
</html>';

// ==================== handoff.php ====================
$handoffPHP = '<?php
/**
 * Handoff (QR Code) - Trabalhe Conosco
 * /mercado/trabalhe-conosco/handoff.php
 * 
 * Shopper terminou as compras, mostra QR para driver escanear
 */
session_start();

if (!isset($_SESSION["worker_id"])) {
    header("Location: login.php");
    exit;
}

$workerId = $_SESSION["worker_id"];
$orderId = $_GET["order"] ?? 0;

if (!$orderId) {
    header("Location: app.php");
    exit;
}

try {
    $pdo = getPDO();
    
    $stmt = $pdo->prepare("SELECT o.*, w.name as worker_name, w.worker_type
                           FROM om_market_orders o
                           LEFT JOIN om_market_workers w ON o.worker_id = w.worker_id
                           WHERE o.order_id = ? AND o.worker_id = ?");
    $stmt->execute([$orderId, $workerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header("Location: app.php?error=nao_encontrado");
        exit;
    }
    
    // Gerar código de handoff se não existir
    if (empty($order["handoff_code"])) {
        $handoffCode = strtoupper(substr(md5($orderId . time()), 0, 8));
        $stmt = $pdo->prepare("UPDATE om_market_orders SET handoff_code = ? WHERE order_id = ?");
        $stmt->execute([$handoffCode, $orderId]);
        $order["handoff_code"] = $handoffCode;
    }
    
} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}

// QR Code data
$qrData = json_encode([
    "type" => "handoff",
    "order_id" => $orderId,
    "code" => $order["handoff_code"],
    "ts" => time()
]);
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode($qrData);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Handoff - Pedido #<?= $orderId ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #00b894 0%, #00cec9 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            text-align: center;
            color: #fff;
        }
        .icon { font-size: 4rem; margin-bottom: 20px; }
        h1 { font-size: 1.5rem; margin-bottom: 10px; }
        p { opacity: 0.9; margin-bottom: 30px; }
        .qr-container {
            background: #fff;
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .qr-container img { display: block; }
        .code {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 5px;
            background: rgba(255,255,255,0.2);
            padding: 15px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        .waiting {
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0.9;
        }
        .spinner {
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .info-box {
            background: rgba(255,255,255,0.15);
            padding: 20px;
            border-radius: 12px;
            margin-top: 30px;
            max-width: 350px;
        }
        .info-box h4 { margin-bottom: 10px; }
        .info-box p { font-size: 0.9rem; opacity: 0.9; margin: 0; }
        .complete-btn {
            margin-top: 30px;
            padding: 16px 40px;
            background: #fff;
            color: #00b894;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: none;
        }
        .complete-btn.show { display: block; }
    </style>
</head>
<body>
    <div class="icon">✅</div>
    <h1>Compras Finalizadas!</h1>
    <p>Mostre este QR Code para o entregador</p>
    
    <div class="qr-container">
        <img src="<?= $qrUrl ?>" alt="QR Code" width="250" height="250">
    </div>
    
    <div class="code"><?= $order["handoff_code"] ?></div>
    
    <div class="waiting" id="waitingMsg">
        <div class="spinner"></div>
        <span>Aguardando entregador escanear...</span>
    </div>
    
    <div class="info-box">
        <h4>📋 Pedido #<?= $orderId ?></h4>
        <p>O entregador escaneará este código para confirmar o recebimento das compras.</p>
    </div>
    
    <button class="complete-btn" id="completeBtn" onclick="goToApp()">
        ✅ Concluído - Voltar ao Início
    </button>
    
    <script>
    // Verificar se foi escaneado a cada 3 segundos
    async function checkStatus() {
        try {
            const res = await fetch("api/check-handoff.php?order=<?= $orderId ?>");
            const data = await res.json();
            
            if (data.handed_off) {
                document.getElementById("waitingMsg").innerHTML = "✅ Entregador recebeu as compras!";
                document.getElementById("completeBtn").classList.add("show");
            } else {
                setTimeout(checkStatus, 3000);
            }
        } catch (e) {
            setTimeout(checkStatus, 5000);
        }
    }
    
    checkStatus();
    
    function goToApp() {
        window.location.href = "app.php?completed=1";
    }
    </script>
</body>
</html>';

// ==================== saque.php ====================
$saquePHP = '<?php
/**
 * Solicitar Saque PIX - Trabalhe Conosco
 * /mercado/trabalhe-conosco/saque.php
 */
session_start();

if (!isset($_SESSION["worker_id"])) {
    header("Location: login.php");
    exit;
}

$workerId = $_SESSION["worker_id"];
$error = "";
$success = "";

try {
    $pdo = getPDO();
    
    // Buscar worker
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $balance = $worker["balance"] ?? 0;
    $minWithdraw = 20; // Mínimo R$ 20
    
    // Processar saque
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $amount = floatval($_POST["amount"] ?? 0);
        $pixKey = $_POST["pix_key"] ?? $worker["bank_pix_key"] ?? "";
        
        if ($amount < $minWithdraw) {
            $error = "Valor mínimo para saque é R$ " . number_format($minWithdraw, 2, ",", ".");
        } elseif ($amount > $balance) {
            $error = "Saldo insuficiente.";
        } elseif (empty($pixKey)) {
            $error = "Informe sua chave PIX.";
        } else {
            // Criar solicitação de saque
            $stmt = $pdo->prepare("INSERT INTO om_market_worker_payouts 
                (worker_id, amount, pix_key, status, requested_at) 
                VALUES (?, ?, ?, \"pending\", NOW())");
            $stmt->execute([$workerId, $amount, $pixKey]);
            
            // Atualizar saldo
            $stmt = $pdo->prepare("UPDATE om_market_workers SET balance = balance - ? WHERE worker_id = ?");
            $stmt->execute([$amount, $workerId]);
            
            // Atualizar chave PIX se diferente
            if ($pixKey !== $worker["bank_pix_key"]) {
                $stmt = $pdo->prepare("UPDATE om_market_workers SET bank_pix_key = ? WHERE worker_id = ?");
                $stmt->execute([$pixKey, $workerId]);
            }
            
            $success = "Saque de R$ " . number_format($amount, 2, ",", ".") . " solicitado com sucesso!";
            $balance -= $amount;
        }
    }
    
    // Histórico de saques
    $stmt = $pdo->prepare("SELECT * FROM om_market_worker_payouts WHERE worker_id = ? ORDER BY requested_at DESC LIMIT 10");
    $stmt->execute([$workerId]);
    $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Erro: " . $e->getMessage();
}

$typeColors = ["shopper" => "#00b894", "driver" => "#e17055", "full_service" => "#6c5ce7"];
$workerColor = $typeColors[$worker["worker_type"]] ?? "#667eea";
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saque PIX - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        .header {
            background: <?= $workerColor ?>;
            color: #fff;
            padding: 20px;
        }
        .header-top { display: flex; align-items: center; gap: 15px; }
        .header .back { color: #fff; text-decoration: none; font-size: 1.5rem; }
        .header h1 { font-size: 1.3rem; }
        .balance-card {
            background: linear-gradient(135deg, <?= $workerColor ?>, <?= $workerColor ?>cc);
            margin: -20px 20px 20px;
            padding: 30px;
            border-radius: 20px;
            color: #fff;
            text-align: center;
            box-shadow: 0 10px 30px <?= $workerColor ?>44;
        }
        .balance-label { opacity: 0.9; margin-bottom: 5px; }
        .balance-value { font-size: 2.5rem; font-weight: 700; }
        .container { padding: 0 20px 20px; }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .card-title { font-weight: 600; margin-bottom: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        .form-group input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        .form-group input:focus { border-color: <?= $workerColor ?>; outline: none; }
        .amount-input { font-size: 1.5rem !important; font-weight: 600; text-align: center; }
        .quick-amounts { display: flex; gap: 10px; margin-top: 10px; }
        .quick-amount {
            flex: 1;
            padding: 10px;
            background: #f5f5f5;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }
        .quick-amount:hover { background: #e0e0e0; }
        .submit-btn {
            width: 100%;
            padding: 18px;
            background: <?= $workerColor ?>;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
        }
        .submit-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .error { background: #fee; color: #c00; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .success { background: #efe; color: #080; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .payout-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .payout-item:last-child { border-bottom: none; }
        .payout-info small { color: #999; }
        .payout-amount { font-weight: 600; }
        .payout-status { font-size: 0.8rem; padding: 4px 10px; border-radius: 20px; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-top">
            <a href="carteira.php" class="back">←</a>
            <h1>Saque PIX</h1>
        </div>
    </div>
    
    <div class="balance-card">
        <div class="balance-label">Saldo Disponível</div>
        <div class="balance-value">R$ <?= number_format($balance, 2, ",", ".") ?></div>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?= $success ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="card">
                <div class="card-title">💰 Valor do Saque</div>
                <div class="form-group">
                    <input type="number" name="amount" class="amount-input" 
                           placeholder="0,00" step="0.01" min="<?= $minWithdraw ?>" max="<?= $balance ?>"
                           value="" required>
                    <div class="quick-amounts">
                        <button type="button" class="quick-amount" onclick="setAmount(50)">R$ 50</button>
                        <button type="button" class="quick-amount" onclick="setAmount(100)">R$ 100</button>
                        <button type="button" class="quick-amount" onclick="setAmount(<?= $balance ?>)">Tudo</button>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-title">🔑 Chave PIX</div>
                <div class="form-group">
                    <input type="text" name="pix_key" placeholder="CPF, E-mail, Telefone ou Chave aleatória"
                           value="<?= htmlspecialchars($worker["bank_pix_key"] ?? "") ?>" required>
                </div>
            </div>
            
            <button type="submit" class="submit-btn" <?= $balance < $minWithdraw ? "disabled" : "" ?>>
                Solicitar Saque
            </button>
            
            <p style="text-align:center;margin-top:15px;color:#999;font-size:0.85rem;">
                Mínimo: R$ <?= number_format($minWithdraw, 2, ",", ".") ?> • Prazo: até 24h úteis
            </p>
        </form>
        
        <?php if (!empty($payouts)): ?>
        <div class="card" style="margin-top:30px;">
            <div class="card-title">📜 Últimos Saques</div>
            <?php foreach ($payouts as $p): ?>
            <div class="payout-item">
                <div class="payout-info">
                    <div>R$ <?= number_format($p["amount"], 2, ",", ".") ?></div>
                    <small><?= date("d/m/Y H:i", strtotime($p["requested_at"])) ?></small>
                </div>
                <span class="payout-status status-<?= $p["status"] ?>">
                    <?= $p["status"] === "pending" ? "⏳ Pendente" : ($p["status"] === "completed" ? "✅ Pago" : "❌ Falhou") ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    function setAmount(value) {
        document.querySelector(".amount-input").value = value.toFixed(2);
    }
    </script>
</body>
</html>';

// ==================== CRIAR ARQUIVOS ====================
echo "<div class='card'>";
echo "<h3>📄 Criando Páginas</h3>";

$pages = [
    'verificar.php' => $verificarPHP,
    'aguardando.php' => $aguardandoPHP,
    'ofertas.php' => $ofertasPHP,
    'pedido.php' => $pedidoPHP,
    'handoff.php' => $handoffPHP,
    'saque.php' => $saquePHP,
];

foreach ($pages as $filename => $content) {
    $filepath = $basePath . '/' . $filename;
    echo "<div class='step'>";
    
    if (file_exists($filepath)) {
        echo "<span class='aviso'>⏭️</span> <code>$filename</code> - Já existe";
        $skipped++;
    } else {
        if (file_put_contents($filepath, $content)) {
            echo "<span class='ok'>✅</span> <code>$filename</code> - Criado";
            $created++;
        } else {
            echo "<span class='erro'>❌</span> <code>$filename</code> - Erro ao criar";
        }
    }
    echo "</div>";
}

echo "</div>";

// Resumo
echo "<div class='card'>";
echo "<h3>📋 Resumo</h3>";
echo "<div class='step'><span class='ok'>✅</span> $created páginas criadas</div>";
echo "<div class='step'><span class='aviso'>⏭️</span> $skipped páginas já existiam</div>";
echo "</div>";

echo "<div class='card'>";
echo "<h3>✅ Instalação Concluída!</h3>";
echo "<p>Próximo passo: <a href='INSTALAR_03_APIS.php' class='btn'>Instalar APIs →</a></p>";
echo "</div>";

echo "<p style='margin-top:30px;opacity:0.5;text-align:center;'>⚠️ Delete este arquivo após usar</p>";
echo "</div></body></html>";
?>
