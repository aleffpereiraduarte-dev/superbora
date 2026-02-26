<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/partner/auth/session.php
 * Verifica sessao do parceiro, retorna dados ou null
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Header: Authorization: Bearer {token}
 * Retorna dados do parceiro autenticado
 */

require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();

    if (!$token) {
        response(true, ["authenticated" => false, "partner" => null]);
    }

    $payload = om_auth()->validateToken($token);

    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(true, ["authenticated" => false, "partner" => null]);
    }

    // Buscar dados atualizados do parceiro
    $stmt = $db->prepare("
        SELECT partner_id, name, cnpj, email, login_email, phone, status, logo,
               address, city, state, cep, categoria, created_at, last_login, is_open,
               contract_signed_at,
               COALESCE(first_setup_complete::int, 0) as first_setup_complete,
               COALESCE(totp_enabled::int, 0) as totp_enabled
        FROM om_market_partners
        WHERE partner_id = ?
    ");
    $stmt->execute([$payload['uid']]);
    $partner = $stmt->fetch();

    if (!$partner) {
        response(true, ["authenticated" => false, "partner" => null]);
    }

    // Mapear status
    $status = (int)$partner['status'];
    $statusMap = [
        0 => 'pending',
        1 => 'approved',
        2 => 'rejected',
        3 => 'suspended'
    ];
    $statusLabel = $statusMap[$status] ?? 'unknown';

    response(true, [
        "authenticated" => true,
        "partner" => [
            "id" => (int)$partner['partner_id'],
            "nome" => $partner['name'],
            "cnpj" => $partner['cnpj'],
            "email" => $partner['email'],
            "login_email" => $partner['login_email'],
            "telefone" => $partner['phone'],
            "logo" => $partner['logo'],
            "categoria" => $partner['categoria'],
            "endereco" => $partner['address'],
            "cidade" => $partner['city'],
            "estado" => $partner['state'],
            "cep" => $partner['cep'],
            "status" => $statusLabel,
            "criado_em" => $partner['created_at'],
            "ultimo_login" => $partner['last_login'],
            "is_open" => (bool)(int)$partner['is_open'],
            "contract_signed" => !empty($partner['contract_signed_at']),
            "first_setup_complete" => (bool)(int)$partner['first_setup_complete'],
            "totp_enabled" => (bool)(int)$partner['totp_enabled']
        ]
    ]);

} catch (Exception $e) {
    error_log("[partner/auth/session] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar sessao", 500);
}
