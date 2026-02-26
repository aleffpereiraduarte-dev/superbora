<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * 📱 API WHATSAPP Z-API
 * ══════════════════════════════════════════════════════════════════════════════
 * 
 * Arquivo: /api/whatsapp.php
 * Uso: require_once 'api/whatsapp.php';
 *      $result = enviarWhatsApp('33999652818', 'Mensagem aqui');
 */

// Z-API CREDENCIAIS
define("ZAPI_INSTANCE", $_ENV['ZAPI_INSTANCE'] ?? getenv('ZAPI_INSTANCE'));
define("ZAPI_TOKEN", $_ENV['ZAPI_TOKEN'] ?? getenv('ZAPI_TOKEN'));
define("ZAPI_CLIENT_TOKEN", $_ENV['ZAPI_CLIENT_TOKEN'] ?? getenv('ZAPI_CLIENT_TOKEN'));

if (!ZAPI_INSTANCE || !ZAPI_TOKEN || !ZAPI_CLIENT_TOKEN) {
    throw new Exception('Credenciais Z-API não configuradas');
}
define("ZAPI_URL", "https://api.z-api.io/instances/" . ZAPI_INSTANCE . "/token/" . ZAPI_TOKEN);

/**
 * Envia mensagem WhatsApp via Z-API
 * 
 * @param string $telefone - Número com DDD (ex: 33999652818 ou 5533999652818)
 * @param string $mensagem - Texto da mensagem (suporta *negrito* e _itálico_)
 * @return array - ['success' => bool, 'message_id' => string, 'error' => string]
 */
function enviarWhatsApp($telefone, $mensagem) {
    // Limpar telefone - só números
    $telefone = preg_replace("/[^0-9]/", "", $telefone);
    
    // Adicionar 55 se for número brasileiro sem código do país
    if (strlen($telefone) == 11 || strlen($telefone) == 10) {
        $telefone = "55" . $telefone;
    }
    
    $url = ZAPI_URL . "/send-text";
    
    $data = json_encode([
        "phone" => $telefone,
        "message" => $mensagem
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Client-Token: ' . ZAPI_CLIENT_TOKEN
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $result = [
        "success" => false,
        "http_code" => $httpCode,
        "phone" => $telefone,
        "message_id" => null,
        "error" => null
    ];
    
    if ($curlError) {
        $errorMsg = "cURL Error: " . $curlError;
        error_log("WhatsApp API Error: " . $errorMsg . " - Phone: " . $telefone);
        $result["error"] = $errorMsg;
        return $result;
    }
    
    $json = json_decode($response, true);
    
    // Z-API retorna zaapId, messageId ou id quando sucesso
    if (isset($json['zaapId']) || isset($json['messageId']) || isset($json['id'])) {
        $result["success"] = true;
        $result["message_id"] = $json['messageId'] ?? $json['zaapId'] ?? $json['id'];
    } else {
        $errorMsg = $json['error'] ?? $json['message'] ?? "Erro desconhecido na API";
        error_log("WhatsApp API Response Error: " . $errorMsg . " - HTTP: " . $httpCode);
        $result["error"] = $errorMsg;
        $result["raw_response"] = $response;
    }
    
    return $result;
}

/**
 * Verifica status da conexão Z-API
 * 
 * @return array - ['connected' => bool, 'phone' => string]
 */
function verificarStatusZAPI($useCache = true) {
    $cacheKey = 'zapi_status_' . md5(ZAPI_INSTANCE);
    $cacheTime = 300; // 5 minutos
    
    if ($useCache && function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cacheKey);
        if ($cached !== false) {
            return $cached;
        }
    }
    
    $url = ZAPI_URL . "/status";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Client-Token: ' . ZAPI_CLIENT_TOKEN
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $json = json_decode($response, true);
    
    return [
        "connected" => isset($json['connected']) && $json['connected'] === true,
        "phone" => $json['phone'] ?? null,
        "smartphone_online" => $json['smartphoneConnected'] ?? false
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// SE CHAMADO DIRETAMENTE (API REST)
// ══════════════════════════════════════════════════════════════════════════════

if (basename($_SERVER['PHP_SELF']) === 'whatsapp.php') {
    // Verificar autenticação
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (empty($apiKey) || $apiKey !== (getenv('API_KEY') ?? getenv('API_KEY'))) {
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Unauthorized"]);
        exit;
    }
    
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: " . ($_ENV['ALLOWED_ORIGIN'] ?? 'localhost'));
    
    $input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    $action = $input["action"] ?? $_GET["action"] ?? "";
    
    // Verificar status
    if ($action === "status") {
        echo json_encode(verificarStatusZAPI());
        exit;
    }
    
    // Rate limiting simples
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateLimitKey = 'rate_limit_' . md5($clientIp);
    
    if (function_exists('apcu_fetch')) {
        $requests = apcu_fetch($rateLimitKey) ?: 0;
        if ($requests >= 10) { // 10 req/min
            http_response_code(429);
            echo json_encode(["success" => false, "error" => "Rate limit exceeded"]);
            exit;
        }
        apcu_store($rateLimitKey, $requests + 1, 60);
    }
    
    // Enviar mensagem
    if ($action === "send") {
        $phone = filter_var($input["phone"] ?? "", FILTER_SANITIZE_NUMBER_INT);
        $message = htmlspecialchars(trim($input["message"] ?? ""), ENT_QUOTES, 'UTF-8');
        
        if (empty($phone) || empty($message)) {
            echo json_encode(["success" => false, "error" => "phone e message obrigatórios"]);
            exit;
        }
        
        if (strlen($message) > 4096) {
            echo json_encode(["success" => false, "error" => "Mensagem muito longa"]);
            exit;
        }
        
        echo json_encode(enviarWhatsApp($phone, $message));
        exit;
    }
    
    // Teste rápido
    if ($action === "test") {
        $phone = $input["phone"] ?? $_GET["phone"] ?? "";
        
        if (empty($phone)) {
            echo json_encode(["success" => false, "error" => "phone obrigatório"]);
            exit;
        }
        
        $msg = "🧪 *Teste OneMundo!*\n\nWhatsApp funcionando! ✅\n" . date("d/m H:i:s");
        echo json_encode(enviarWhatsApp($phone, $msg));
        exit;
    }
    
    echo json_encode(["success" => false, "error" => "Ação inválida. Use: status, send, test"]);
}
?>
