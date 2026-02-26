<?php
/**
 * GET /api/pagamento/cartao/listar.php?customer_id=1
 */

require_once dirname(dirname(dirname(__DIR__))) . '/includes/env_loader.php';

define("DB_HOST", env('DB_HOSTNAME', 'localhost'));
define("DB_NAME", env('DB_DATABASE', ''));
define("DB_USER", env('DB_USERNAME', ''));
define("DB_PASS", env('DB_PASSWORD', ''));

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    
    $customer_id = (int)($_GET["customer_id"] ?? 0);

    if ($customer_id <= 0) {
        echo json_encode(["success" => true, "message" => "", "data" => ["cartoes" => []]]);
        exit;
    }

    // Verificar se tabela existe
    $tableExists = $db->query("SHOW TABLES LIKE 'om_payment_methods'")->fetch();

    if (!$tableExists) {
        echo json_encode(["success" => true, "message" => "", "data" => ["cartoes" => []]]);
        exit;
    }

    $stmt = $db->prepare("SELECT id, bandeira, ultimos_digitos, apelido, principal FROM om_payment_methods WHERE customer_id = ? AND tipo = 'cartao_credito' AND ativo = 1");
    $stmt->execute([$customer_id]);
    $cartoes = $stmt->fetchAll();
    
    echo json_encode(["success" => true, "message" => "", "data" => ["cartoes" => $cartoes]]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("[pagamento/cartao/listar] Erro: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Erro interno do servidor", "data" => null]);
}
