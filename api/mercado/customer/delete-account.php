<?php
/**
 * POST /api/mercado/customer/delete-account.php
 * LGPD - Solicitar exclusao de conta
 * Anonimiza dados do cliente e marca como deletado
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once __DIR__ . "/../helpers/EmailService.php";
setCorsHeaders();

try {
    $db = getDB();
    // SECURITY: Use proper JWT validation with type check
    OmAuth::getInstance()->setDb($db);
    $customerId = 0;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $tokenPayload = om_auth()->validateToken($token);
        if ($tokenPayload && $tokenPayload['type'] === 'customer') {
            $customerId = (int)$tokenPayload['uid'];
        }
    }
    if (!$customerId) response(false, null, 'Nao autorizado', 401);
    $input = json_decode(file_get_contents('php://input'), true);
    $reason = trim($input['reason'] ?? 'Nao informado');
    $password = $input['password'] ?? '';

    // Verificar senha para confirmar identidade
    $stmt = $db->prepare("SELECT name, password_hash, email, phone FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) response(false, null, 'Conta nao encontrada', 404);

    // SECURITY: Senha obrigatoria para confirmar exclusao de conta
    if (empty($password)) {
        response(false, null, 'Senha obrigatoria para confirmar exclusao', 400);
    }
    $hash = $user['password_hash'] ?? null;
    if (!$hash) {
        // Social login accounts with no password — deny deletion via this method
        response(false, null, 'Conta sem senha cadastrada. Use o suporte para solicitar exclusao.', 400);
    }
    if (!password_verify($password, $hash)) {
        response(false, null, 'Senha incorreta', 403);
    }

    $db->beginTransaction();

    // Verificar pedidos ativos DENTRO da transacao para evitar race condition (TOCTOU)
    $activeStmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status NOT IN ('entregue', 'cancelado', 'cancelled', 'completed', 'retirado', 'finalizado')");
    $activeStmt->execute([$customerId]);
    if ((int)$activeStmt->fetchColumn() > 0) {
        $db->rollBack();
        response(false, null, 'Voce tem pedidos em andamento. Aguarde a conclusao antes de excluir a conta.', 400);
    }

    // Registrar solicitacao de exclusao
    $db->prepare("INSERT INTO om_account_deletions (customer_id, reason, email_backup, phone_backup) VALUES (?, ?, ?, ?)")
        ->execute([$customerId, $reason, $user['email'], $user['phone']]);

    // Anonimizar dados do cliente (om_customers = tabela principal)
    $anonEmail = "deleted_{$customerId}@removed.superbora.com";
    $db->prepare("UPDATE om_customers SET
        is_active = '0',
        name = 'Conta Removida',
        email = ?,
        phone = NULL,
        cpf = NULL,
        password_hash = NULL,
        foto = NULL,
        deleted_at = NOW(),
        updated_at = NOW()
    WHERE customer_id = ?")->execute([$anonEmail, $customerId]);

    // Also update legacy om_market_customers table if it exists
    try {
        $db->prepare("UPDATE om_market_customers SET
            name = 'Conta Removida',
            email = ?,
            phone = NULL,
            whatsapp = NULL,
            cpf = NULL,
            password = NULL,
            password_hash = NULL,
            address = NULL,
            address_number = NULL,
            address_complement = NULL,
            neighborhood = NULL,
            city = NULL,
            state = NULL,
            cep = NULL,
            latitude = NULL,
            longitude = NULL,
            status = 0,
            updated_at = NOW()
        WHERE customer_id = ?")->execute([$anonEmail, $customerId]);
    } catch (Exception $e) {
        // Legacy table may not exist in all environments
        error_log("[DeleteAccount] om_market_customers update: " . $e->getMessage());
    }

    // Limpar carrinho (including extras)
    $db->prepare("DELETE FROM om_market_cart WHERE customer_id = ?")->execute([$customerId]);

    // Limpar tokens de push
    $db->prepare("DELETE FROM om_market_push_tokens WHERE user_id = ? AND user_type = 'customer'")->execute([$customerId]);

    // SECURITY: Revoke all JWT auth tokens to prevent deleted account from making API calls
    om_auth()->revokeAllTokens('customer', $customerId);

    // Zerar cashback wallet
    $db->prepare("UPDATE om_cashback_wallet SET balance = 0, total_earned = 0, total_used = 0 WHERE customer_id = ?")->execute([$customerId]);

    // Limpar dados pessoais em transacoes (manter registros para auditoria)
    // Cancelar assinaturas ativas
    $db->prepare("UPDATE om_subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE customer_id = ? AND status IN ('active', 'trial')")->execute([$customerId]);

    // Limpar recomendacoes e verificacoes
    try { $db->prepare("DELETE FROM om_smart_recommendations WHERE customer_id = ?")->execute([$customerId]); } catch (Exception $e) { /* table may not exist */ }
    try { $db->prepare("DELETE FROM om_age_verifications WHERE customer_id = ?")->execute([$customerId]); } catch (Exception $e) { /* table may not exist */ }

    // Limpar favoritos
    $db->prepare("DELETE FROM om_favorites WHERE customer_id = ?")->execute([$customerId]);

    // Limpar notificacoes
    $db->prepare("DELETE FROM om_market_notifications WHERE recipient_id = ? AND recipient_type = 'customer'")->execute([$customerId]);

    // Marcar como concluida
    $db->prepare("UPDATE om_account_deletions SET status = 'completed', completed_at = NOW() WHERE customer_id = ? AND status = 'pending'")
        ->execute([$customerId]);

    $db->commit();

    // Enviar email de confirmacao de exclusao (usa dados de backup)
    try {
        $emailService = new EmailService($db);
        $emailService->sendAccountDeleted($user['email'], $user['name'] ?? 'Cliente');
    } catch (Exception $emailErr) {
        error_log("[DeleteAccount] Email error: " . $emailErr->getMessage());
    }

    response(true, null, 'Conta excluida com sucesso. Seus dados foram anonimizados conforme a LGPD.');

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[DeleteAccount] " . $e->getMessage());
    response(false, null, 'Erro ao excluir conta', 500);
}
