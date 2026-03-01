<?php
/**
 * POST /campaign/resgatar.php
 * Redeem a campaign via QR code scan.
 * Body: { campaign_id, qr_data, lat?, lng? }
 *
 * Anti-fraud:
 * 1. JWT auth required
 * 2. Rate limit: max 10 attempts per 5 minutes
 * 3. HMAC signature validation (timing-safe)
 * 4. Timestamp freshness (configurable window, default 60s)
 * 5. UNIQUE(campaign_id, customer_id) constraint
 * 6. Atomic counter (prevents overselling)
 * 7. New customer check (0 completed orders)
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Method not allowed", 405);
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth();
    $input = getInput();

    $campaignId = (int)($input['campaign_id'] ?? 0);
    $qrData = trim($input['qr_data'] ?? '');
    $lat = isset($input['lat']) ? (float)$input['lat'] : null;
    $lng = isset($input['lng']) ? (float)$input['lng'] : null;

    if (!$campaignId || empty($qrData)) {
        response(false, null, "Dados incompletos", 400);
    }

    // ─── Rate limit: max 10 attempts per 5 minutes ───
    $rlStmt = $db->prepare("
        SELECT COUNT(*) FROM om_campaign_qr_attempts
        WHERE customer_id = ? AND attempted_at > NOW() - INTERVAL '5 minutes'
    ");
    $rlStmt->execute([$customerId]);
    if ((int)$rlStmt->fetchColumn() >= 10) {
        response(false, null, "Muitas tentativas. Aguarde alguns minutos.", 429);
    }

    // ─── Parse QR data: superbora://camp/{campaign_id}/{timestamp}/{hmac} ───
    if (!preg_match('#^superbora://camp/(\d+)/(\d+)/([a-f0-9]+)$#', $qrData, $m)) {
        // Log failed attempt
        $logStmt = $db->prepare("INSERT INTO om_campaign_qr_attempts (campaign_id, customer_id, failure_reason) VALUES (?, ?, 'invalid_format')");
        $logStmt->execute([$campaignId, $customerId]);
        response(false, null, "QR code invalido", 400);
    }

    $qrCampaignId = (int)$m[1];
    $qrTimestamp = (int)$m[2];
    $qrHmac = $m[3];

    // ─── Validate campaign_id matches ───
    if ($qrCampaignId !== $campaignId) {
        $logStmt = $db->prepare("INSERT INTO om_campaign_qr_attempts (campaign_id, customer_id, failure_reason) VALUES (?, ?, 'campaign_mismatch')");
        $logStmt->execute([$campaignId, $customerId]);
        response(false, null, "QR code invalido para esta campanha", 400);
    }

    // ─── Begin transaction ───
    $db->beginTransaction();

    try {
        // ─── Lock and fetch campaign ───
        $campStmt = $db->prepare("
            SELECT campaign_id, qr_secret, qr_validity_seconds, max_redemptions,
                   current_redemptions, status, start_date, end_date, new_customers_only, reward_text
            FROM om_campaigns
            WHERE campaign_id = ?
            FOR UPDATE
        ");
        $campStmt->execute([$campaignId]);
        $campaign = $campStmt->fetch(PDO::FETCH_ASSOC);

        if (!$campaign) {
            $db->rollBack();
            response(false, null, "Campanha nao encontrada", 404);
        }

        // ─── Validate HMAC (timing-safe) ───
        $expectedHmac = hash_hmac('sha256', "{$campaignId}:{$qrTimestamp}", $campaign['qr_secret']);
        if (!hash_equals($expectedHmac, $qrHmac)) {
            $db->rollBack();
            $logStmt = $db->prepare("INSERT INTO om_campaign_qr_attempts (campaign_id, customer_id, failure_reason) VALUES (?, ?, 'invalid_hmac')");
            $logStmt->execute([$campaignId, $customerId]);
            response(false, null, "QR code invalido ou expirado", 400);
        }

        // ─── Validate timestamp freshness ───
        $validitySeconds = (int)($campaign['qr_validity_seconds'] ?: 60);
        if (abs(time() - $qrTimestamp) > $validitySeconds) {
            $db->rollBack();
            $logStmt = $db->prepare("INSERT INTO om_campaign_qr_attempts (campaign_id, customer_id, failure_reason) VALUES (?, ?, 'expired')");
            $logStmt->execute([$campaignId, $customerId]);
            response(false, null, "QR code expirado. Aponte para o QR code atualizado.", 400);
        }

        // ─── Validate campaign is active ───
        $now = date('Y-m-d H:i:s');
        if ($campaign['status'] !== 'active') {
            $db->rollBack();
            response(false, null, "Campanha encerrada", 400);
        }
        if ($now < $campaign['start_date']) {
            $db->rollBack();
            response(false, null, "Campanha ainda nao comecou", 400);
        }
        if ($now > $campaign['end_date']) {
            $db->rollBack();
            response(false, null, "Campanha encerrada", 400);
        }
        if ((int)$campaign['current_redemptions'] >= (int)$campaign['max_redemptions']) {
            $db->rollBack();
            response(false, null, "Campanha esgotada! Todos os brindes ja foram retirados.", 400);
        }

        // ─── New customer check ───
        if ($campaign['new_customers_only']) {
            $orderStmt = $db->prepare("
                SELECT COUNT(*) FROM om_market_orders
                WHERE customer_id = ? AND status NOT IN ('cancelado', 'pendente')
            ");
            $orderStmt->execute([$customerId]);
            $orderCount = (int)$orderStmt->fetchColumn();

            if ($orderCount > 0) {
                $db->rollBack();
                $logStmt = $db->prepare("INSERT INTO om_campaign_qr_attempts (campaign_id, customer_id, failure_reason) VALUES (?, ?, 'not_new_customer')");
                $logStmt->execute([$campaignId, $customerId]);
                response(false, null, "Esta promocao e exclusiva para novos clientes do SuperBora", 400);
            }
        }

        // ─── Get customer info ───
        $custStmt = $db->prepare("SELECT name, phone FROM om_customers WHERE customer_id = ?");
        $custStmt->execute([$customerId]);
        $customer = $custStmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            $db->rollBack();
            response(false, null, "Cliente nao encontrado", 404);
        }

        // ─── Generate unique redemption code ───
        $redemptionCode = strtoupper(gerarCodigo(6));

        // ─── Insert redemption (UNIQUE constraint catches duplicates) ───
        try {
            $insertStmt = $db->prepare("
                INSERT INTO om_campaign_redemptions
                    (campaign_id, customer_id, customer_name, customer_phone, redemption_code, qr_timestamp, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([
                $campaignId,
                $customerId,
                $customer['name'] ?? '',
                $customer['phone'] ?? '',
                $redemptionCode,
                $qrTimestamp,
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        } catch (PDOException $e) {
            $db->rollBack();
            // UNIQUE violation = already redeemed
            if (strpos($e->getCode(), '23505') !== false || strpos($e->getMessage(), 'unique') !== false) {
                response(false, null, "Voce ja resgatou esta promocao!", 409);
            }
            throw $e;
        }

        // ─── Increment counter atomically ───
        $updStmt = $db->prepare("
            UPDATE om_campaigns
            SET current_redemptions = current_redemptions + 1
            WHERE campaign_id = ? AND current_redemptions < max_redemptions
        ");
        $updStmt->execute([$campaignId]);
        if ($updStmt->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Campanha esgotada!", 400);
        }

        // ─── Log successful attempt ───
        $logStmt = $db->prepare("INSERT INTO om_campaign_qr_attempts (campaign_id, customer_id, success) VALUES (?, ?, true)");
        $logStmt->execute([$campaignId, $customerId]);

        $db->commit();

        response(true, [
            'redemption_code' => $redemptionCode,
            'reward_text' => $campaign['reward_text'] ?? 'Brinde gratis',
            'message' => 'Mostre este codigo para o atendente!',
        ], "Resgate realizado com sucesso!");

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[campaign/resgatar] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar resgate", 500);
}
