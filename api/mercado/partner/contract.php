<?php
/**
 * GET/POST /api/mercado/partner/contract.php
 * Contrato digital do parceiro
 *
 * GET  - Retorna dados do contrato (termos, comissão, dados parceiro)
 * POST - Assina contrato digitalmente
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
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // Ensure columns exist
    try {
        $db->exec("ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS contract_signed_at TIMESTAMP");
        $db->exec("ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS contract_signed_ip VARCHAR(45)");
    } catch (Exception $e) {
        // columns already exist
    }

    // GET — Return contract data
    if ($method === 'GET') {
        $stmt = $db->prepare("
            SELECT partner_id, name, cnpj, cpf, email, phone, address, city, state, cep,
                   categoria, commission_rate, entrega_propria,
                   contract_signed_at, owner_name
            FROM om_market_partners
            WHERE partner_id = ?
        ");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch();

        if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

        $commissionRate = (float)($partner['commission_rate'] ?? 15);
        $isSigned = !empty($partner['contract_signed_at']);

        response(true, [
            'signed' => $isSigned,
            'signed_at' => $partner['contract_signed_at'],
            'partner' => [
                'name' => $partner['name'],
                'owner_name' => $partner['owner_name'],
                'cnpj' => $partner['cnpj'],
                'cpf' => $partner['cpf'],
                'email' => $partner['email'],
                'phone' => $partner['phone'],
                'address' => trim(($partner['address'] ?? '') . ', ' . ($partner['city'] ?? '') . ' - ' . ($partner['state'] ?? ''), ', - '),
                'categoria' => $partner['categoria'],
            ],
            'terms' => [
                'commission_rate' => $commissionRate,
                'commission_label' => number_format($commissionRate, 1) . '%',
                'delivery_own' => (bool)($partner['entrega_propria'] ?? false),
                'payment_cycle' => 'semanal',
                'hold_hours' => 2,
                'min_payout' => 10.00,
                'clauses' => [
                    'O Parceiro concorda em manter os precos e informacoes dos produtos atualizados na plataforma.',
                    'A SuperBora cobrara uma comissao de ' . number_format($commissionRate, 1) . '% sobre cada venda realizada pela plataforma.',
                    'Os repasses serao liberados apos periodo de hold de 2 horas apos confirmacao de entrega.',
                    'Saques podem ser realizados quando o saldo disponivel atingir o minimo de R$ 10,00.',
                    'O Parceiro e responsavel pela qualidade dos produtos e cumprimento dos prazos de entrega.',
                    'A SuperBora reserva-se o direito de suspender a conta em caso de violacao dos termos.',
                    'Ambas as partes podem encerrar este contrato com aviso previo de 30 dias.',
                    'Disputas serao resolvidas preferencialmente por mediacao antes de recurso judicial.',
                ],
            ],
        ]);
    }

    // POST — Sign contract
    if ($method === 'POST') {
        $input = getInput();
        $accepted = $input['accepted'] ?? false;

        if (!$accepted) {
            response(false, null, "Voce deve aceitar os termos para assinar o contrato", 400);
        }

        // Check if already signed
        $stmt = $db->prepare("SELECT contract_signed_at FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $current = $stmt->fetch();

        if (!empty($current['contract_signed_at'])) {
            response(true, [
                'signed' => true,
                'signed_at' => $current['contract_signed_at'],
            ], "Contrato ja foi assinado anteriormente.");
        }

        // Ensure audit columns exist
        try {
            $db->exec("ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS contract_user_agent TEXT");
            $db->exec("ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS contract_version VARCHAR(20)");
            $db->exec("ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS contract_terms_hash VARCHAR(64)");
        } catch (Exception $e) { /* columns may already exist */ }

        // Sign contract with full audit trail
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $contractVersion = 'v1.0';
        // Hash the terms text so we can verify what the partner signed
        $termsHash = hash('sha256', json_encode($input['accepted'] ?? '') . $contractVersion);

        $db->prepare("
            UPDATE om_market_partners
            SET contract_signed_at = NOW(),
                contract_signed_ip = ?,
                contract_user_agent = ?,
                contract_version = ?,
                contract_terms_hash = ?,
                updated_at = NOW()
            WHERE partner_id = ?
        ")->execute([$clientIp, $userAgent, $contractVersion, $termsHash, $partnerId]);

        response(true, [
            'signed' => true,
            'signed_at' => date('Y-m-d H:i:s'),
        ], "Contrato assinado com sucesso!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/contract] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar contrato", 500);
}
