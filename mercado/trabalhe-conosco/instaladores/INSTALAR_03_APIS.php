<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * 🔧 INSTALADOR 3 - APIs Faltando
 * Upload em: /mercado/trabalhe-conosco/INSTALAR_03_APIS.php
 * 
 * Cria as APIs que faltam:
 * - api/register.php (cadastro de worker)
 * - api/upload-doc.php (upload de documentos)
 * - api/offers.php (listar ofertas)
 * - api/complete-shopping.php (finalizar compras)
 * - api/withdraw.php (solicitar saque)
 * - api/check-handoff.php (verificar handoff)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 3 - APIs</title>";
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
echo "<h1>🔧 Instalador 3 - APIs Faltando</h1>";

$basePath = __DIR__ . '/api';
$created = 0;
$skipped = 0;

// Criar pasta api se não existir
if (!is_dir($basePath)) {
    mkdir($basePath, 0755, true);
    echo "<div class='card'><p class='ok'>✅ Pasta /api/ criada</p></div>";
}

// ==================== register.php ====================
$registerAPI = '<?php
/**
 * API: Cadastro de Worker
 * POST /api/register.php
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "error" => "Método não permitido"]);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) $data = $_POST;
    
    // Validações
    $required = ["name", "email", "phone", "cpf", "worker_type"];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception("Campo obrigatório: $field");
        }
    }
    
    // Validar tipo
    $validTypes = ["shopper", "driver", "full_service"];
    if (!in_array($data["worker_type"], $validTypes)) {
        throw new Exception("Tipo de worker inválido");
    }
    
    // Validar CPF único
    $cpf = preg_replace("/[^0-9]/", "", $data["cpf"]);
    $stmt = $pdo->prepare("SELECT worker_id FROM om_market_workers WHERE cpf = ?");
    $stmt->execute([$cpf]);
    if ($stmt->fetch()) {
        throw new Exception("CPF já cadastrado");
    }
    
    // Validar email único
    $stmt = $pdo->prepare("SELECT worker_id FROM om_market_workers WHERE email = ?");
    $stmt->execute([$data["email"]]);
    if ($stmt->fetch()) {
        throw new Exception("E-mail já cadastrado");
    }
    
    // Gerar código de verificação
    $verificationCode = str_pad(rand(0, 999999), 6, "0", STR_PAD_LEFT);
    $codeExpires = date("Y-m-d H:i:s", strtotime("+10 minutes"));
    
    // Hash da senha (se fornecida)
    $passwordHash = null;
    if (!empty($data["password"])) {
        $passwordHash = password_hash($data["password"], PASSWORD_DEFAULT);
    }
    
    // Inserir worker
    $stmt = $pdo->prepare("INSERT INTO om_market_workers 
        (name, email, phone, cpf, worker_type, birth_date, password_hash,
         address, address_number, neighborhood, city, state, cep,
         verification_code, verification_code_expires,
         application_status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \"submitted\", NOW())");
    
    $stmt->execute([
        $data["name"],
        $data["email"],
        preg_replace("/[^0-9]/", "", $data["phone"]),
        $cpf,
        $data["worker_type"],
        $data["birth_date"] ?? null,
        $passwordHash,
        $data["address"] ?? null,
        $data["address_number"] ?? null,
        $data["neighborhood"] ?? null,
        $data["city"] ?? null,
        $data["state"] ?? null,
        $data["cep"] ?? null,
        $verificationCode,
        $codeExpires
    ]);
    
    $workerId = $pdo->lastInsertId();
    
    // TODO: Enviar SMS/Email com código de verificação
    // sendSMS($data["phone"], "Seu código OneMundo: $verificationCode");
    
    // Salvar na sessão para verificação
    session_start();
    $_SESSION["pending_worker_id"] = $workerId;
    $_SESSION["pending_phone"] = $data["phone"];
    $_SESSION["pending_email"] = $data["email"];
    $_SESSION["debug_code"] = $verificationCode; // Remover em produção
    
    echo json_encode([
        "success" => true,
        "message" => "Cadastro realizado! Verifique seu telefone.",
        "worker_id" => $workerId,
        "redirect" => "verificar.php"
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}';

// ==================== upload-doc.php ====================
$uploadDocAPI = '<?php
/**
 * API: Upload de Documentos
 * POST /api/upload-doc.php
 */
header("Content-Type: application/json");

session_start();

$workerId = $_SESSION["worker_id"] ?? $_SESSION["pending_worker_id"] ?? 0;
if (!$workerId) {
    echo json_encode(["success" => false, "error" => "Não autenticado"]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "error" => "Método não permitido"]);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $docType = $_POST["doc_type"] ?? "";
    $validTypes = ["rg_front", "rg_back", "cpf", "selfie", "proof_address", "mei", "cnh_front", "cnh_back", "crlv"];
    
    if (!in_array($docType, $validTypes)) {
        throw new Exception("Tipo de documento inválido");
    }
    
    if (!isset($_FILES["file"]) || $_FILES["file"]["error"] !== UPLOAD_ERR_OK) {
        throw new Exception("Erro no upload do arquivo");
    }
    
    $file = $_FILES["file"];
    $allowedMimes = ["image/jpeg", "image/png", "image/webp", "application/pdf"];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file["tmp_name"]);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedMimes)) {
        throw new Exception("Tipo de arquivo não permitido");
    }
    
    // Criar pasta de uploads
    $uploadDir = dirname(__DIR__) . "/uploads/workers/$workerId/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Gerar nome único
    $ext = pathinfo($file["name"], PATHINFO_EXTENSION);
    $filename = $docType . "_" . time() . "." . $ext;
    $filepath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file["tmp_name"], $filepath)) {
        throw new Exception("Erro ao salvar arquivo");
    }
    
    // URL pública
    $publicUrl = "/mercado/trabalhe-conosco/uploads/workers/$workerId/$filename";
    
    // Atualizar coluna no worker
    $colName = "doc_" . $docType;
    $stmt = $pdo->prepare("UPDATE om_market_workers SET $colName = ? WHERE worker_id = ?");
    $stmt->execute([$publicUrl, $workerId]);
    
    // Registrar na tabela de documentos
    $stmt = $pdo->prepare("INSERT INTO om_market_worker_documents 
        (worker_id, doc_type, file_path, uploaded_at) 
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE file_path = ?, uploaded_at = NOW()");
    $stmt->execute([$workerId, $docType, $publicUrl, $publicUrl]);
    
    echo json_encode([
        "success" => true,
        "message" => "Documento enviado com sucesso",
        "url" => $publicUrl
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}';

// ==================== offers.php ====================
$offersAPI = '<?php
/**
 * API: Listar Ofertas Disponíveis
 * GET /api/offers.php
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

session_start();

$workerId = $_SESSION["worker_id"] ?? 0;
if (!$workerId) {
    echo json_encode(["success" => false, "error" => "Não autenticado"]);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buscar worker
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$worker) {
        throw new Exception("Worker não encontrado");
    }
    
    if (!$worker["is_online"]) {
        echo json_encode(["success" => true, "offers" => [], "message" => "Você está offline"]);
        exit;
    }
    
    $lat = $worker["current_lat"] ?? -23.55;
    $lng = $worker["current_lng"] ?? -46.63;
    $maxDistance = $worker["max_distance_km"] ?? 10;
    $workerType = $worker["worker_type"];
    $workMode = $worker["work_mode"] ?? "both";
    
    // Determinar status válidos
    $validStatuses = [];
    if ($workerType === "shopper" || ($workerType === "full_service" && in_array($workMode, ["shopping", "both"]))) {
        $validStatuses[] = "paid";
    }
    if ($workerType === "driver" || ($workerType === "full_service" && in_array($workMode, ["delivery", "both"]))) {
        $validStatuses[] = "ready_for_pickup";
    }
    
    if (empty($validStatuses)) {
        echo json_encode(["success" => true, "offers" => []]);
        exit;
    }
    
    $placeholders = implode(",", array_fill(0, count($validStatuses), "?"));
    
    $sql = "SELECT o.order_id, o.status, o.total_items, o.subtotal, o.worker_fee,
                   o.delivery_address, o.delivery_lat, o.delivery_lng,
                   p.store_name, p.store_address, p.store_lat, p.store_lng,
                   (6371 * acos(cos(radians(?)) * cos(radians(p.store_lat)) * 
                    cos(radians(p.store_lng) - radians(?)) + sin(radians(?)) * 
                    sin(radians(p.store_lat)))) AS distance
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status IN ($placeholders)
            AND o.worker_id IS NULL
            HAVING distance <= ?
            ORDER BY distance ASC, o.created_at ASC
            LIMIT 20";
    
    $params = array_merge([$lat, $lng, $lat], $validStatuses, [$maxDistance]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar ofertas
    foreach ($offers as &$offer) {
        $offer["distance"] = round($offer["distance"], 1);
        $offer["type"] = $offer["status"] === "paid" ? "shopping" : "delivery";
        $offer["worker_fee"] = floatval($offer["worker_fee"] ?? 15);
    }
    
    echo json_encode([
        "success" => true,
        "offers" => $offers,
        "count" => count($offers)
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}';

// ==================== complete-shopping.php ====================
$completeShoppingAPI = '<?php
/**
 * API: Finalizar Compras
 * POST /api/complete-shopping.php
 */
header("Content-Type: application/json");

session_start();

$workerId = $_SESSION["worker_id"] ?? 0;
if (!$workerId) {
    echo json_encode(["success" => false, "error" => "Não autenticado"]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "error" => "Método não permitido"]);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $data = json_decode(file_get_contents("php://input"), true);
    $orderId = $data["order_id"] ?? 0;
    
    if (!$orderId) {
        throw new Exception("ID do pedido não informado");
    }
    
    // Verificar pedido
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND worker_id = ?");
    $stmt->execute([$orderId, $workerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception("Pedido não encontrado");
    }
    
    if (!in_array($order["status"], ["paid", "shopping"])) {
        throw new Exception("Pedido não está em status de compras");
    }
    
    // Verificar se todos os itens foram escaneados
    $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN scanned_at IS NOT NULL THEN 1 ELSE 0 END) as scanned 
                           FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Permitir finalizar mesmo sem todos os itens (pode ter item indisponível)
    // if ($items["scanned"] < $items["total"]) {
    //     throw new Exception("Escaneie todos os itens antes de finalizar");
    // }
    
    // Gerar código de handoff
    $handoffCode = strtoupper(substr(md5($orderId . time()), 0, 8));
    
    // Buscar worker para ver se é Full Service
    $stmt = $pdo->prepare("SELECT worker_type, work_mode FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $isFullBoth = ($worker["worker_type"] === "full_service" && $worker["work_mode"] === "both");
    
    // Atualizar status
    $newStatus = "ready_for_pickup"; // Aguardando driver por padrão
    
    $stmt = $pdo->prepare("UPDATE om_market_orders SET 
        status = ?, 
        handoff_code = ?,
        shopping_completed_at = NOW()
        WHERE order_id = ?");
    $stmt->execute([$newStatus, $handoffCode, $orderId]);
    
    // Se for Full Service com modo "both", perguntar se quer entregar
    $redirect = "handoff.php?order=$orderId";
    if ($isFullBoth) {
        $redirect = "delivery-choice.php?order=$orderId";
    }
    
    echo json_encode([
        "success" => true,
        "message" => "Compras finalizadas!",
        "handoff_code" => $handoffCode,
        "redirect" => $redirect,
        "is_full_service" => $isFullBoth
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}';

// ==================== withdraw.php ====================
$withdrawAPI = '<?php
/**
 * API: Solicitar Saque
 * POST /api/withdraw.php
 */
header("Content-Type: application/json");

session_start();

$workerId = $_SESSION["worker_id"] ?? 0;
if (!$workerId) {
    echo json_encode(["success" => false, "error" => "Não autenticado"]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "error" => "Método não permitido"]);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $data = json_decode(file_get_contents("php://input"), true);
    $amount = floatval($data["amount"] ?? 0);
    $pixKey = $data["pix_key"] ?? "";
    
    $minWithdraw = 20;
    
    // Buscar worker
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$worker) {
        throw new Exception("Worker não encontrado");
    }
    
    $balance = floatval($worker["balance"] ?? 0);
    
    // Validações
    if ($amount < $minWithdraw) {
        throw new Exception("Valor mínimo para saque é R$ " . number_format($minWithdraw, 2, ",", "."));
    }
    
    if ($amount > $balance) {
        throw new Exception("Saldo insuficiente");
    }
    
    if (empty($pixKey)) {
        throw new Exception("Informe sua chave PIX");
    }
    
    // Verificar se tem saque pendente
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_worker_payouts WHERE worker_id = ? AND status = \"pending\"");
    $stmt->execute([$workerId]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception("Você já tem um saque pendente. Aguarde o processamento.");
    }
    
    $pdo->beginTransaction();
    
    // Criar solicitação de saque
    $stmt = $pdo->prepare("INSERT INTO om_market_worker_payouts 
        (worker_id, amount, pix_key, status, requested_at) 
        VALUES (?, ?, ?, \"pending\", NOW())");
    $stmt->execute([$workerId, $amount, $pixKey]);
    $payoutId = $pdo->lastInsertId();
    
    // Deduzir do saldo
    $stmt = $pdo->prepare("UPDATE om_market_workers SET balance = balance - ? WHERE worker_id = ?");
    $stmt->execute([$amount, $workerId]);
    
    // Atualizar chave PIX
    $stmt = $pdo->prepare("UPDATE om_market_workers SET bank_pix_key = ? WHERE worker_id = ?");
    $stmt->execute([$pixKey, $workerId]);
    
    $pdo->commit();
    
    // TODO: Integrar com Pagar.me para transferência automática
    // $pagarme->transfer($amount, $pixKey);
    
    echo json_encode([
        "success" => true,
        "message" => "Saque solicitado com sucesso!",
        "payout_id" => $payoutId,
        "amount" => $amount,
        "new_balance" => $balance - $amount
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}';

// ==================== check-handoff.php ====================
$checkHandoffAPI = '<?php
/**
 * API: Verificar Status do Handoff
 * GET /api/check-handoff.php?order=123
 */
header("Content-Type: application/json");

$orderId = $_GET["order"] ?? 0;

if (!$orderId) {
    echo json_encode(["success" => false, "error" => "ID do pedido não informado"]);
    exit;
}

try {
    $pdo = getPDO();
    
    $stmt = $pdo->prepare("SELECT status, handoff_at, delivery_worker_id FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception("Pedido não encontrado");
    }
    
    $handedOff = !empty($order["handoff_at"]) || !empty($order["delivery_worker_id"]) || 
                 in_array($order["status"], ["picked_up", "delivering", "delivered"]);
    
    echo json_encode([
        "success" => true,
        "handed_off" => $handedOff,
        "status" => $order["status"]
    ]);
    
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}';

// ==================== CRIAR ARQUIVOS ====================
echo "<div class='card'>";
echo "<h3>🔌 Criando APIs</h3>";

$apis = [
    'register.php' => $registerAPI,
    'upload-doc.php' => $uploadDocAPI,
    'offers.php' => $offersAPI,
    'complete-shopping.php' => $completeShoppingAPI,
    'withdraw.php' => $withdrawAPI,
    'check-handoff.php' => $checkHandoffAPI,
];

foreach ($apis as $filename => $content) {
    $filepath = $basePath . '/' . $filename;
    echo "<div class='step'>";
    
    if (file_exists($filepath)) {
        echo "<span class='aviso'>⏭️</span> <code>api/$filename</code> - Já existe";
        $skipped++;
    } else {
        if (file_put_contents($filepath, $content)) {
            echo "<span class='ok'>✅</span> <code>api/$filename</code> - Criado";
            $created++;
        } else {
            echo "<span class='erro'>❌</span> <code>api/$filename</code> - Erro ao criar";
        }
    }
    echo "</div>";
}

echo "</div>";

// Criar pasta uploads
$uploadsDir = dirname(__DIR__) . '/uploads/workers';
if (!is_dir($uploadsDir)) {
    if (mkdir($uploadsDir, 0755, true)) {
        echo "<div class='card'><p class='ok'>✅ Pasta uploads/workers/ criada</p></div>";
    }
}

// Resumo
echo "<div class='card'>";
echo "<h3>📋 Resumo</h3>";
echo "<div class='step'><span class='ok'>✅</span> $created APIs criadas</div>";
echo "<div class='step'><span class='aviso'>⏭️</span> $skipped APIs já existiam</div>";
echo "</div>";

echo "<div class='card'>";
echo "<h3>✅ Instalação Concluída!</h3>";
echo "<p>Todos os instaladores foram executados!</p>";
echo "<p style='margin-top:15px;'><a href='DIAG_TRABALHE_CONOSCO.php' class='btn'>🔍 Rodar Diagnóstico Novamente</a></p>";
echo "</div>";

echo "<p style='margin-top:30px;opacity:0.5;text-align:center;'>⚠️ Delete este arquivo após usar</p>";
echo "</div></body></html>";
?>
