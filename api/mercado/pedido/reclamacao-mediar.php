<?php
/**
 * POST /api/mercado/pedido/reclamacao-mediar.php
 * iFood-style mediation: store responds, customer reviews, SuperBora arbitrates
 *
 * Actions:
 *   store_respond   — Store offers resolution (refund, replacement, coupon)
 *   customer_review — Customer accepts or rejects store's offer
 *   escalate        — Auto-escalate after 15min deadline
 *   superbora_decide — Admin makes final decision
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Method not allowed', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $action = $input['action'] ?? $_GET['action'] ?? '';
    $complaintId = (int)($input['complaint_id'] ?? $input['id'] ?? 0);

    if (!$complaintId || !$action) {
        response(false, null, 'complaint_id e action obrigatorios', 400);
    }

    // Load complaint
    $stmt = $db->prepare("SELECT * FROM om_store_penalties WHERE id = ? FOR UPDATE");
    $db->beginTransaction();
    $stmt->execute([$complaintId]);
    $complaint = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$complaint) {
        $db->rollBack();
        response(false, null, 'Reclamacao nao encontrada', 404);
    }

    switch ($action) {

        // ═══ STORE RESPONDS (within 15 min) ═══
        case 'store_respond':
            $token = om_auth()->getTokenFromRequest();
            $payload = $token ? om_auth()->validateToken($token) : null;
            // Allow partner or admin
            if (!$payload || !in_array($payload['type'], ['partner', 'admin'])) {
                $db->rollBack();
                response(false, null, 'Nao autorizado', 401);
            }

            if (!in_array($complaint['mediation_step'], ['store', null, ''], true) || $complaint['status'] === 'resolved') {
                $db->rollBack();
                response(false, null, 'Fora da etapa de resolucao da loja', 400);
            }

            $offer = trim($input['offer'] ?? '');
            $offerType = $input['offer_type'] ?? 'other'; // refund, replacement, coupon, apology, other
            $offerValue = (float)($input['offer_value'] ?? 0);

            if (!$offer) {
                $db->rollBack();
                response(false, null, 'Oferta de resolucao obrigatoria', 400);
            }

            $db->prepare("
                UPDATE om_store_penalties
                SET store_offer = ?, store_response = ?, mediation_step = 'customer_review',
                    status = 'store_responded', updated_at = NOW()
                WHERE id = ?
            ")->execute([
                json_encode(['type' => $offerType, 'value' => $offerValue, 'message' => $offer], JSON_UNESCAPED_UNICODE),
                $offer,
                $complaintId,
            ]);

            $db->commit();

            // Notify customer AFTER commit (non-blocking)
            try {
                require_once __DIR__ . '/../config/notify.php';
                sendNotification($db, $complaint['reported_by_id'], 'customer',
                    'A loja respondeu sua reclamacao!',
                    "Sobre seu pedido: " . $offer,
                    ['type' => 'complaint_response', 'complaint_id' => $complaintId, 'order_id' => $complaint['order_id']]
                );
            } catch (\Throwable $e) {}
            response(true, ['mediation_step' => 'customer_review'], 'Resposta enviada! Aguardando cliente avaliar.');
            break;

        // ═══ CUSTOMER REVIEWS STORE'S OFFER ═══
        case 'customer_review':
            $token = om_auth()->getTokenFromRequest();
            $payload = $token ? om_auth()->validateToken($token) : null;
            if (!$payload || $payload['type'] !== 'customer') {
                $db->rollBack();
                response(false, null, 'Nao autorizado', 401);
            }
            if ((int)$payload['uid'] !== (int)$complaint['reported_by_id']) {
                $db->rollBack();
                response(false, null, 'Nao autorizado — reclamacao de outro cliente', 403);
            }

            if ($complaint['mediation_step'] !== 'customer_review') {
                $db->rollBack();
                response(false, null, 'Loja ainda nao respondeu', 400);
            }

            $satisfied = (bool)($input['satisfied'] ?? false);

            if ($satisfied) {
                // Customer accepted store's resolution → case closed, NO penalty
                $db->prepare("
                    UPDATE om_store_penalties
                    SET customer_satisfied = true, status = 'resolved', mediation_step = 'closed',
                        resolution = 'Cliente aceitou resolucao da loja', resolved_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$complaintId]);

                $db->commit();
                response(true, ['status' => 'resolved', 'mediation_step' => 'closed'], 'Obrigado! Fico feliz que a loja resolveu.');
            } else {
                // Customer NOT satisfied → escalate to SuperBora
                $db->prepare("
                    UPDATE om_store_penalties
                    SET customer_satisfied = false, status = 'escalated', mediation_step = 'superbora',
                        escalated_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$complaintId]);

                $db->commit();

                // Notify admin AFTER commit
                try {
                    require_once __DIR__ . '/../config/notify.php';
                    sendNotification($db, 1, 'admin',
                        'Reclamacao escalada',
                        "Cliente rejeitou resolucao da loja",
                        ['type' => 'complaint_escalated', 'complaint_id' => $complaintId]
                    );
                } catch (\Throwable $e) {}
                response(true, ['status' => 'escalated', 'mediation_step' => 'superbora'], 'Entendi! Nossa equipe vai analisar e resolver pra voce em ate 1 hora.');
            }
            break;

        // ═══ AUTO-ESCALATE (called by cron after 15min) ═══
        case 'escalate':
            // Verify admin/cron auth
            $cronSecret = $_ENV['CRON_SECRET'] ?? '';
            $provided = $_SERVER['HTTP_X_CRON_SECRET'] ?? $input['cron_secret'] ?? '';
            $isAdmin = false;
            if ($cronSecret && hash_equals($cronSecret, $provided)) {
                $isAdmin = true;
            } else {
                $token = om_auth()->getTokenFromRequest();
                $payload = $token ? om_auth()->validateToken($token) : null;
                $isAdmin = $payload && $payload['type'] === 'admin';
            }
            if (!$isAdmin) {
                $db->rollBack();
                response(false, null, 'Nao autorizado', 401);
            }

            // Auto-escalate all complaints past deadline where store didn't respond
            $stmtExpired = $db->prepare("
                UPDATE om_store_penalties
                SET status = 'escalated', mediation_step = 'superbora', escalated_at = NOW(), updated_at = NOW(),
                    resolution = 'Loja nao respondeu dentro do prazo de 15 minutos'
                WHERE mediation_step = 'store' AND status = 'waiting_store'
                    AND store_deadline IS NOT NULL AND store_deadline < NOW()
                RETURNING id, order_id, partner_id
            ");
            $stmtExpired->execute();
            $escalated = $stmtExpired->fetchAll(PDO::FETCH_ASSOC);

            // Auto-apply penalty + refund for escalated complaints (store ignored)
            foreach ($escalated as $esc) {
                // Credit cashback to customer
                $escDetail = $db->prepare("SELECT reported_by_id, penalty_amount, refund_to_customer FROM om_store_penalties WHERE id = ?")->fetch(PDO::FETCH_ASSOC);
                // ... refund logic would go here

                error_log("[reclamacao-mediar] Auto-escalated complaint #{$esc['id']} — store didn't respond");
            }

            $db->commit();
            response(true, ['escalated_count' => count($escalated)], count($escalated) . ' reclamacoes escaladas automaticamente.');
            break;

        // ═══ SUPERBORA DECIDES (admin) ═══
        case 'superbora_decide':
            $token = om_auth()->getTokenFromRequest();
            $payload = $token ? om_auth()->validateToken($token) : null;
            if (!$payload || $payload['type'] !== 'admin') {
                $db->rollBack();
                response(false, null, 'Apenas admin', 401);
            }

            $decision = $input['decision'] ?? ''; // 'refund_customer', 'penalize_store', 'both', 'dismiss'
            $resolution = trim($input['resolution'] ?? '');
            $refundAmount = (float)($input['refund_amount'] ?? $complaint['refund_to_customer'] ?? 0);
            $penaltyAmount = (float)($input['penalty_amount'] ?? $complaint['penalty_amount'] ?? 0);

            $newStatus = 'confirmed';
            if ($decision === 'dismiss') $newStatus = 'cancelled';

            $db->prepare("
                UPDATE om_store_penalties
                SET status = ?, mediation_step = 'closed', resolution = ?,
                    penalty_amount = ?, refund_to_customer = ?,
                    resolved_at = NOW(), resolved_by = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$newStatus, $resolution ?: "Decisao SuperBora: $decision", $penaltyAmount, $refundAmount, (int)$payload['uid'], $complaintId]);

            // If refunding customer
            if (in_array($decision, ['refund_customer', 'both']) && $refundAmount > 0 && $complaint['reported_by_id']) {
                require_once __DIR__ . '/../helpers/cashback.php';
                $db->prepare("
                    INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
                    VALUES (?, ?, ?)
                    ON CONFLICT (customer_id) DO UPDATE SET
                        balance = om_cashback_wallet.balance + EXCLUDED.balance,
                        total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
                ")->execute([$complaint['reported_by_id'], $refundAmount, $refundAmount]);

                $db->prepare("
                    INSERT INTO om_cashback (customer_id, order_id, type, amount, description, status, expires_at)
                    VALUES (?, ?, 'refund', ?, ?, 'available', NOW() + INTERVAL '90 days')
                ")->execute([
                    $complaint['reported_by_id'], $complaint['order_id'], $refundAmount,
                    "Reembolso reclamacao #{$complaintId}"
                ]);

            }

            $db->commit();

            // Notify customer AFTER commit
            if (in_array($decision, ['refund_customer', 'both']) && $refundAmount > 0 && $complaint['reported_by_id']) {
                try {
                    require_once __DIR__ . '/../config/notify.php';
                    sendNotification($db, $complaint['reported_by_id'], 'customer',
                        'Reembolso aprovado!',
                        'Voce recebeu R$ ' . number_format($refundAmount, 2, ',', '.') . ' de cashback.',
                        ['type' => 'refund', 'complaint_id' => $complaintId, 'amount' => $refundAmount]
                    );
                } catch (\Throwable $e) {}
            }
            response(true, ['status' => $newStatus, 'decision' => $decision], "Decisao aplicada: $decision");
            break;

        default:
            $db->rollBack();
            response(false, null, 'Acao invalida: ' . $action, 400);
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[reclamacao-mediar] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
