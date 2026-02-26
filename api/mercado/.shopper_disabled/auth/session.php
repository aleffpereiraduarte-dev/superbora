<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/shopper/auth/session.php
 * Verifica sessao do shopper, retorna dados ou null
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Header: Authorization: Bearer {token}
 * Retorna dados do shopper autenticado
 */

require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();

    if (!$token) {
        response(true, ["authenticated" => false, "shopper" => null]);
    }

    $payload = om_auth()->validateToken($token);

    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_SHOPPER) {
        response(true, ["authenticated" => false, "shopper" => null]);
    }

    // Buscar dados atualizados do shopper
    $stmt = $db->prepare("
        SELECT shopper_id, name, email, phone, status, photo,
               cpf, data_nascimento,
               rating, is_online, pix_key, banco_nome AS bank_name,
               data_aprovacao, created_at, last_login
        FROM om_market_shoppers
        WHERE shopper_id = ?
    ");
    $stmt->execute([$payload['uid']]);
    $shopper = $stmt->fetch();

    if (!$shopper) {
        response(true, ["authenticated" => false, "shopper" => null]);
    }

    // Mapear status
    $status = (int)$shopper['status'];
    $statusMap = [
        0 => 'pending',
        1 => 'approved',
        2 => 'rejected',
        3 => 'suspended'
    ];
    $statusLabel = $statusMap[$status] ?? 'unknown';

    // Buscar saldo
    $saldo = 0;
    $saldoPendente = 0;
    try {
        $stmtSaldo = $db->prepare("SELECT saldo_disponivel, saldo_pendente FROM om_shopper_saldo WHERE shopper_id = ?");
        $stmtSaldo->execute([$shopper['shopper_id']]);
        $saldoData = $stmtSaldo->fetch();
        if ($saldoData) {
            $saldo = floatval($saldoData['saldo_disponivel'] ?? 0);
            $saldoPendente = floatval($saldoData['saldo_pendente'] ?? 0);
        }
    } catch (Exception $e) {
        // Tabela pode nao existir ainda
        error_log("[shopper/auth/session] Saldo nao disponivel: " . $e->getMessage());
    }

    response(true, [
        "authenticated" => true,
        "shopper" => [
            "id" => (int)$shopper['shopper_id'],
            "nome" => $shopper['name'],
            "email" => $shopper['email'],
            "telefone" => $shopper['phone'],
            "foto" => $shopper['photo'],
            "cpf" => $shopper['cpf'] ?? null,
            "data_nascimento" => $shopper['data_nascimento'] ?? null,
            "rating" => floatval($shopper['rating'] ?? 0),
            "is_online" => (bool)($shopper['is_online'] ?? false),
            "saldo" => $saldo,
            "saldo_pendente" => $saldoPendente,
            "status" => $statusLabel,
            "data_aprovacao" => $shopper['data_aprovacao'] ?? null,
            "criado_em" => $shopper['created_at'],
            "ultimo_login" => $shopper['last_login']
        ]
    ]);

} catch (Exception $e) {
    error_log("[shopper/auth/session] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar sessao", 500);
}
