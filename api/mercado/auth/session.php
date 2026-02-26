<?php
/**
 * GET /api/mercado/auth/session.php
 * Verifica sessao do cliente, retorna dados ou null
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();

    if (!$token) {
        response(true, ["authenticated" => false, "customer" => null]);
    }

    $payload = om_auth()->validateToken($token);

    if (!$payload || $payload['type'] !== 'customer') {
        response(true, ["authenticated" => false, "customer" => null]);
    }

    $stmt = $db->prepare("
        SELECT customer_id, name, email, phone, cpf, foto, is_active
        FROM om_customers
        WHERE customer_id = ? AND is_active = '1'
    ");
    $stmt->execute([$payload['uid']]);
    $customer = $stmt->fetch();

    if (!$customer) {
        response(true, ["authenticated" => false, "customer" => null]);
    }

    // Buscar endereco padrao
    $stmtAddr = $db->prepare("
        SELECT address_id, label, street, number, neighborhood, city, state, zipcode
        FROM om_customer_addresses
        WHERE customer_id = ? AND is_default = 1 AND is_active = '1'
        LIMIT 1
    ");
    $stmtAddr->execute([$customer['customer_id']]);
    $defaultAddress = $stmtAddr->fetch();

    $addrFormatted = null;
    if ($defaultAddress) {
        $addrFormatted = [
            "id" => (int)$defaultAddress['address_id'],
            "label" => $defaultAddress['label'],
            "logradouro" => $defaultAddress['street'],
            "numero" => $defaultAddress['number'],
            "bairro" => $defaultAddress['neighborhood'],
            "cidade" => $defaultAddress['city'],
            "estado" => $defaultAddress['state'],
            "cep" => $defaultAddress['zipcode'],
        ];
    }

    response(true, [
        "authenticated" => true,
        "customer" => [
            "id" => (int)$customer['customer_id'],
            "nome" => $customer['name'],
            "email" => $customer['email'],
            "telefone" => $customer['phone'],
            "cpf" => $customer['cpf'],
            "foto" => $customer['foto'],
            "avatar" => $customer['foto'],
            "endereco_padrao" => $addrFormatted
        ]
    ]);

} catch (Exception $e) {
    error_log("[API Auth Session] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar sessao", 500);
}
