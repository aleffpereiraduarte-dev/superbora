<?php
/**
 * API de Autenticação com 2FA Google Authenticator
 */
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

session_start();

$configPath = $_SERVER["DOCUMENT_ROOT"] . "/admin/config.php";
if (!file_exists($configPath)) die(json_encode(["success" => false, "error" => "Config não encontrado"]));
require_once $configPath;

try {
    $pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die(json_encode(["success" => false, "error" => "Erro BD"]));
}

// Classe Google Authenticator
class GoogleAuth {
    private static $base32chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    
    public static function generateSecret($length = 16) {
        $secret = "";
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::$base32chars[random_int(0, 31)];
        }
        return $secret;
    }
    
    public static function getQRCodeUrl($name, $secret, $issuer = "OneMundo Financeiro") {
        $url = "otpauth://totp/" . urlencode($issuer) . ":" . urlencode($name) . "?secret=" . $secret . "&issuer=" . urlencode($issuer);
        return "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($url);
    }
    
    public static function verifyCode($secret, $code) {
        $timeSlice = floor(time() / 30);
        for ($i = -1; $i <= 1; $i++) {
            $calcCode = self::getCode($secret, $timeSlice + $i);
            if ($calcCode == $code) return true;
        }
        return false;
    }
    
    private static function getCode($secret, $timeSlice) {
        $secretKey = self::base32Decode($secret);
        $time = chr(0) . chr(0) . chr(0) . chr(0) . pack("N*", $timeSlice);
        $hash = hash_hmac("SHA1", $time, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $hashPart = substr($hash, $offset, 4);
        $value = unpack("N", $hashPart)[1];
        $value = $value & 0x7FFFFFFF;
        return str_pad($value % 1000000, 6, "0", STR_PAD_LEFT);
    }
    
    private static function base32Decode($secret) {
        $base32chars = self::$base32chars;
        $secret = strtoupper($secret);
        $buffer = 0; $bitsLeft = 0; $result = "";
        for ($i = 0; $i < strlen($secret); $i++) {
            $buffer = ($buffer << 5) | strpos($base32chars, $secret[$i]);
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $result;
    }
}

$action = $_GET["action"] ?? $_POST["action"] ?? "";

switch ($action) {

case "login":
    $cpf = preg_replace("/[^0-9]/", "", $_POST["cpf"] ?? "");
    $password = $_POST["password"] ?? "";
    
    if (empty($cpf) || empty($password)) {
        echo json_encode(["success" => false, "error" => "CPF e senha obrigatórios"]);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT u.rh_user_id, u.full_name, u.cpf, u.email, u.password_hash,
               f.fin_user_id, f.role, f.can_view, f.can_create, f.can_edit, 
               f.can_approve, f.can_delete, f.can_export, f.approval_limit,
               f.must_change_password, f.two_factor_enabled, f.two_factor_secret
        FROM om_rh_users u 
        INNER JOIN om_fin_users f ON f.rh_user_id = u.rh_user_id 
        WHERE u.cpf = ? AND u.status = '1' AND f.is_active = 1
    ");
    $stmt->execute([$cpf]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(["success" => false, "error" => "Usuário não encontrado ou sem acesso"]);
        exit;
    }
    
    if (!password_verify($password, $user["password_hash"])) {
        echo json_encode(["success" => false, "error" => "Senha incorreta"]);
        exit;
    }
    
    // Guardar dados temporários na sessão
    $_SESSION["temp_fin_user_id"] = $user["fin_user_id"];
    $_SESSION["temp_rh_user_id"] = $user["rh_user_id"];
    $_SESSION["temp_user_name"] = $user["full_name"];
    $_SESSION["temp_user_role"] = $user["role"];
    $_SESSION["temp_user_email"] = $user["email"] ?? "";
    $_SESSION["temp_permissions"] = [
        "view" => (bool)$user["can_view"],
        "create" => (bool)$user["can_create"],
        "edit" => (bool)$user["can_edit"],
        "approve" => (bool)$user["can_approve"],
        "delete" => (bool)$user["can_delete"],
        "export" => (bool)$user["can_export"],
        "approval_limit" => (float)$user["approval_limit"]
    ];
    
    // Verificar se precisa trocar senha
    if ($user["must_change_password"]) {
        echo json_encode([
            "success" => true,
            "requires_password_change" => true,
            "message" => "Você precisa criar uma nova senha"
        ]);
        exit;
    }
    
    // Verificar se 2FA está configurado
    if (!$user["two_factor_enabled"]) {
        // Gerar novo secret para configurar 2FA
        $secret = GoogleAuth::generateSecret();
        $pdo->prepare("UPDATE om_fin_users SET two_factor_secret = ? WHERE fin_user_id = ?")
            ->execute([$secret, $user["fin_user_id"]]);
        
        $qrUrl = GoogleAuth::getQRCodeUrl($user["full_name"], $secret);
        
        echo json_encode([
            "success" => true,
            "requires_2fa_setup" => true,
            "secret" => $secret,
            "qr_url" => $qrUrl,
            "message" => "Configure a autenticação de dois fatores"
        ]);
        exit;
    }
    
    // 2FA já configurado, pedir código
    echo json_encode([
        "success" => true,
        "requires_2fa_code" => true,
        "message" => "Digite o código do Google Authenticator"
    ]);
    break;

case "change_password":
    $newPassword = $_POST["new_password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    
    if (empty($newPassword) || strlen($newPassword) < 6) {
        echo json_encode(["success" => false, "error" => "Senha deve ter no mínimo 6 caracteres"]);
        exit;
    }
    
    if ($newPassword !== $confirmPassword) {
        echo json_encode(["success" => false, "error" => "Senhas não conferem"]);
        exit;
    }
    
    if (!isset($_SESSION["temp_rh_user_id"])) {
        echo json_encode(["success" => false, "error" => "Sessão expirada, faça login novamente"]);
        exit;
    }
    
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Atualizar senha no RH
    $pdo->prepare("UPDATE om_rh_users SET password_hash = ? WHERE rh_user_id = ?")
        ->execute([$passwordHash, $_SESSION["temp_rh_user_id"]]);
    
    // Marcar como senha trocada
    $pdo->prepare("UPDATE om_fin_users SET must_change_password = 0, password_changed_at = NOW() WHERE fin_user_id = ?")
        ->execute([$_SESSION["temp_fin_user_id"]]);
    
    // Verificar se precisa configurar 2FA
    $stmt = $pdo->prepare("SELECT two_factor_enabled, two_factor_secret, full_name FROM om_fin_users f JOIN om_rh_users u ON u.rh_user_id = f.rh_user_id WHERE f.fin_user_id = ?");
    $stmt->execute([$_SESSION["temp_fin_user_id"]]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user["two_factor_enabled"]) {
        $secret = GoogleAuth::generateSecret();
        $pdo->prepare("UPDATE om_fin_users SET two_factor_secret = ? WHERE fin_user_id = ?")
            ->execute([$secret, $_SESSION["temp_fin_user_id"]]);
        
        $qrUrl = GoogleAuth::getQRCodeUrl($user["full_name"], $secret);
        
        echo json_encode([
            "success" => true,
            "requires_2fa_setup" => true,
            "secret" => $secret,
            "qr_url" => $qrUrl,
            "message" => "Senha alterada! Agora configure o 2FA"
        ]);
        exit;
    }
    
    echo json_encode(["success" => true, "requires_2fa_code" => true, "message" => "Senha alterada! Digite o código 2FA"]);
    break;

case "setup_2fa":
    $code = $_POST["code"] ?? "";
    
    if (!isset($_SESSION["temp_fin_user_id"])) {
        echo json_encode(["success" => false, "error" => "Sessão expirada"]);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT two_factor_secret FROM om_fin_users WHERE fin_user_id = ?");
    $stmt->execute([$_SESSION["temp_fin_user_id"]]);
    $secret = $stmt->fetchColumn();
    
    if (!GoogleAuth::verifyCode($secret, $code)) {
        echo json_encode(["success" => false, "error" => "Código inválido. Tente novamente."]);
        exit;
    }
    
    // Ativar 2FA
    $pdo->prepare("UPDATE om_fin_users SET two_factor_enabled = 1 WHERE fin_user_id = ?")
        ->execute([$_SESSION["temp_fin_user_id"]]);
    
    // Finalizar login
    $_SESSION["fin_user_id"] = $_SESSION["temp_fin_user_id"];
    $_SESSION["fin_rh_user_id"] = $_SESSION["temp_rh_user_id"];
    $_SESSION["fin_user_name"] = $_SESSION["temp_user_name"];
    $_SESSION["fin_user_role"] = $_SESSION["temp_user_role"];
    $_SESSION["fin_user_email"] = $_SESSION["temp_user_email"];
    $_SESSION["fin_permissions"] = $_SESSION["temp_permissions"];
    
    // Limpar temporários
    unset($_SESSION["temp_fin_user_id"], $_SESSION["temp_rh_user_id"], $_SESSION["temp_user_name"], $_SESSION["temp_user_role"], $_SESSION["temp_user_email"], $_SESSION["temp_permissions"]);
    
    $pdo->prepare("UPDATE om_fin_users SET last_login = NOW() WHERE fin_user_id = ?")
        ->execute([$_SESSION["fin_user_id"]]);
    
    echo json_encode(["success" => true, "message" => "2FA configurado! Redirecionando..."]);
    break;

case "verify_2fa":
    $code = $_POST["code"] ?? "";
    
    if (!isset($_SESSION["temp_fin_user_id"])) {
        echo json_encode(["success" => false, "error" => "Sessão expirada"]);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT two_factor_secret FROM om_fin_users WHERE fin_user_id = ?");
    $stmt->execute([$_SESSION["temp_fin_user_id"]]);
    $secret = $stmt->fetchColumn();
    
    if (!GoogleAuth::verifyCode($secret, $code)) {
        echo json_encode(["success" => false, "error" => "Código inválido"]);
        exit;
    }
    
    // Finalizar login
    $_SESSION["fin_user_id"] = $_SESSION["temp_fin_user_id"];
    $_SESSION["fin_rh_user_id"] = $_SESSION["temp_rh_user_id"];
    $_SESSION["fin_user_name"] = $_SESSION["temp_user_name"];
    $_SESSION["fin_user_role"] = $_SESSION["temp_user_role"];
    $_SESSION["fin_user_email"] = $_SESSION["temp_user_email"];
    $_SESSION["fin_permissions"] = $_SESSION["temp_permissions"];
    
    unset($_SESSION["temp_fin_user_id"], $_SESSION["temp_rh_user_id"], $_SESSION["temp_user_name"], $_SESSION["temp_user_role"], $_SESSION["temp_user_email"], $_SESSION["temp_permissions"]);
    
    $pdo->prepare("UPDATE om_fin_users SET last_login = NOW() WHERE fin_user_id = ?")
        ->execute([$_SESSION["fin_user_id"]]);
    
    echo json_encode(["success" => true, "message" => "Login completo!"]);
    break;

case "logout":
    session_destroy();
    echo json_encode(["success" => true]);
    break;

case "check":
    echo json_encode(["success" => true, "authenticated" => isset($_SESSION["fin_user_id"]), "user" => isset($_SESSION["fin_user_id"]) ? ["name" => $_SESSION["fin_user_name"], "role" => $_SESSION["fin_user_role"]] : null]);
    break;

case "test":
    echo json_encode(["success" => true, "message" => "API OK", "db" => DB_DATABASE]);
    break;

default:
    echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>