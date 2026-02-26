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
define("ZAPI_INSTANCE", "3EB5EA8848393161FED3AEC2FCF0DF7D");
define("ZAPI_TOKEN", "F8435919A26A2D35D60DAEBE");
define("ZAPI_CLIENT_TOKEN", "F78499793bdac4071996955c45c8511cdS");
define("ZAPI_URL", "https://api.z-api.io/instances/" . ZAPI_INSTANCE . "/token/" . ZAPI_TOKEN);

/**
 * Envia mensagem WhatsApp via Z-API
 */
function enviarWhatsApp($telefone, $mensagem) {
    // Limpar telefone - só números
    $telefone = preg_replace("/[^0-9]/", "", $telefone);
    
    // Adicionar 55 se for número brasileiro sem código do país
    if (strlen($telefone) == 11 || strlen($telefone) == 10) {
        $telefone = "55" . $telefone;
    }
    
    $url = ZAPI_URL . "/send-text";
    
    $data = json_encode(array(
        "phone" => $telefone,
        "message" => $mensagem
    ));
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Client-Token: ' . ZAPI_CLIENT_TOKEN
    ));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $result = array(
        "success" => false,
        "http_code" => $httpCode,
        "phone" => $telefone,
        "message_id" => null,
        "error" => null
    );
    
    if ($curlError) {
        $result["error"] = "cURL: " . $curlError;
        return $result;
    }
    
    $json = json_decode($response, true);
    
    // Z-API retorna zaapId, messageId ou id quando sucesso
    if (isset($json['zaapId']) || isset($json['messageId']) || isset($json['id'])) {
        $result["success"] = true;
        if (isset($json['messageId'])) {
            $result["message_id"] = $json['messageId'];
        } elseif (isset($json['zaapId'])) {
            $result["message_id"] = $json['zaapId'];
        } else {
            $result["message_id"] = $json['id'];
        }
    } else {
        $result["error"] = isset($json['error']) ? $json['error'] : "Erro desconhecido";
    }
    
    return $result;
}

/**
 * Verifica status da conexão Z-API
 */
function verificarStatusZAPI() {
    $url = ZAPI_URL . "/status";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Client-Token: ' . ZAPI_CLIENT_TOKEN
    ));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $json = json_decode($response, true);
    
    return array(
        "connected" => isset($json['connected']) && $json['connected'] === true,
        "phone" => isset($json['phone']) ? $json['phone'] : null,
        "smartphone_online" => isset($json['smartphoneConnected']) ? $json['smartphoneConnected'] : false
    );
}

// ══════════════════════════════════════════════════════════════════════════════
// SE CHAMADO DIRETAMENTE (API REST)
// ══════════════════════════════════════════════════════════════════════════════

if (basename($_SERVER['PHP_SELF']) === 'whatsapp.php') {
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = "";
    if (isset($input["action"])) {
        $action = $input["action"];
    } elseif (isset($_GET["action"])) {
        $action = $_GET["action"];
    }
    
    // Verificar status
    if ($action === "status") {
        echo json_encode(verificarStatusZAPI());
        exit;
    }
    
    // Enviar mensagem
    if ($action === "send") {
        $phone = isset($input["phone"]) ? $input["phone"] : "";
        $message = isset($input["message"]) ? $input["message"] : "";
        
        if (empty($phone) || empty($message)) {
            echo json_encode(array("success" => false, "error" => "phone e message obrigatórios"));
            exit;
        }
        
        echo json_encode(enviarWhatsApp($phone, $message));
        exit;
    }
    
    // Teste rápido
    if ($action === "test") {
        $phone = "";
        if (isset($input["phone"])) {
            $phone = $input["phone"];
        } elseif (isset($_GET["phone"])) {
            $phone = $_GET["phone"];
        }
        
        if (empty($phone)) {
            echo json_encode(array("success" => false, "error" => "phone obrigatório"));
            exit;
        }
        
        $msg = "🧪 *Teste OneMundo!*\n\nWhatsApp funcionando! ✅\n" . date("d/m H:i:s");
        echo json_encode(enviarWhatsApp($phone, $msg));
        exit;
    }
    
    echo json_encode(array("success" => false, "error" => "Ação inválida. Use: status, send, test"));
}
?>
