<?php
/**
 * POST /api/mercado/orders/age-verification.php
 * Verificacao de idade para pedidos com produtos restritos (alcool, etc)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Nao autorizado", 401);
    }

    $customerId = (int)$payload['uid'];
    $input = getInput();

    $orderId = (int)($input['order_id'] ?? 0);
    $birthDate = trim($input['birth_date'] ?? '');
    $documentType = $input['document_type'] ?? 'cpf';
    $documentNumber = preg_replace('/[^0-9]/', '', $input['document_number'] ?? '');

    if (!$orderId) {
        response(false, null, "ID do pedido obrigatorio", 400);
    }

    if (empty($birthDate)) {
        response(false, null, "Data de nascimento obrigatoria", 400);
    }

    // Calcular idade
    try {
        $birth = new DateTime($birthDate);
        $today = new DateTime();
        $age = $today->diff($birth)->y;
    } catch (Exception $e) {
        response(false, null, "Data de nascimento invalida", 400);
    }

    if ($age < 18) {
        response(false, null, "Voce deve ter 18 anos ou mais para este pedido", 403);
    }

    // Verificar se pedido pertence ao cliente e requer verificacao
    $stmt = $db->prepare("
        SELECT order_id, status FROM om_market_orders
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Registrar verificacao
    $stmt = $db->prepare("
        INSERT INTO om_age_verifications
        (customer_id, order_id, birth_date, age_at_verification, document_type, document_number, verified)
        VALUES (?, ?, ?, ?, ?, ?, 1)
        ON CONFLICT (customer_id, order_id) DO UPDATE SET
            birth_date = EXCLUDED.birth_date,
            age_at_verification = EXCLUDED.age_at_verification,
            document_type = EXCLUDED.document_type,
            document_number = EXCLUDED.document_number,
            verified = 1,
            verified_at = NOW()
    ");
    $stmt->execute([
        $customerId, $orderId, $birthDate, $age,
        $documentType, $documentNumber ?: null
    ]);

    // Atualizar perfil do cliente com data de nascimento (se ainda nao tiver)
    try {
        $stmt = $db->prepare("
            UPDATE om_market_customers
            SET data_nascimento = COALESCE(data_nascimento, ?)
            WHERE customer_id = ?
        ");
        $stmt->execute([$birthDate, $customerId]);
    } catch (Exception $e) {
        // Column may not exist yet — non-critical
        error_log("[age-verification] Profile update skipped: " . $e->getMessage());
    }

    response(true, [
        'verified' => true,
        'age' => $age,
        'message' => 'Idade verificada com sucesso!'
    ]);

} catch (Exception $e) {
    error_log("[orders/age-verification] Erro: " . $e->getMessage());
    response(false, null, "Erro na verificacao de idade", 500);
}
