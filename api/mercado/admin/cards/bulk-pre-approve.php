<?php
/**
 * POST /admin/cards/bulk-pre-approve.php
 *
 * Admin creates pre-approvals in bulk. Two modes:
 *
 *   Mode A (explicit list of customer_ids):
 *     {
 *       "customer_ids": [1, 2, 3, ...],
 *       "limit_mode":   "recommended" | "fixed" | "score_based",
 *       "fixed_limit":  1500,          // required when limit_mode = fixed
 *       "interest_rate": 12.99,
 *       "annual_fee":    0,
 *       "offer_expires_days": 30
 *     }
 *
 *   Mode B (filter by score threshold — reads latest om_credit_evaluations):
 *     {
 *       "min_score":    700,           // eligible: overall_score >= min_score
 *       "max_score":    1000,
 *       "limit_mode":   "recommended" | "fixed",
 *       "fixed_limit":  1500,
 *       "interest_rate": 12.99,
 *       "annual_fee":    0,
 *       "offer_expires_days": 30,
 *       "dry_run":       false         // when true, returns list without sending
 *     }
 *
 * Skips customers that already have a non-terminal card. Returns
 *   { sent: N, skipped: M, already_has_card: K, details: [{customer_id, status, card_id?}] }
 */

require_once __DIR__ . "/../../customer/card/_common.php";
require_once __DIR__ . "/../../helpers/notify.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

$waHelper = __DIR__ . "/../../helpers/twilio-whatsapp.php";
if (file_exists($waHelper)) require_once $waHelper;

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $adminPayload = om_auth()->requireAdmin();
    $adminId = (int)($adminPayload['uid'] ?? $adminPayload['user_id'] ?? 0);
    ensureCardTables($db);

    $input = getInput();
    $customerIds   = isset($input['customer_ids']) && is_array($input['customer_ids']) ? $input['customer_ids'] : [];
    $minScore      = isset($input['min_score'])     ? (int)$input['min_score']     : null;
    $maxScore      = isset($input['max_score'])     ? (int)$input['max_score']     : 1000;
    $limitMode     = (string)($input['limit_mode'] ?? 'recommended');
    $fixedLimit    = isset($input['fixed_limit'])   ? (float)$input['fixed_limit']   : 0;
    $interestRate  = isset($input['interest_rate']) ? (float)$input['interest_rate'] : 12.99;
    $annualFee     = isset($input['annual_fee'])    ? (float)$input['annual_fee']    : 0.0;
    $offerDays     = max(1, min(90, (int)($input['offer_expires_days'] ?? 30)));
    $dryRun        = (bool)($input['dry_run'] ?? false);

    if (!in_array($limitMode, ['recommended', 'fixed', 'score_based'], true)) {
        response(false, null, 'limit_mode invalido', 400);
    }
    if ($limitMode === 'fixed' && $fixedLimit <= 0) {
        response(false, null, 'fixed_limit obrigatorio quando limit_mode=fixed', 400);
    }

    // Mode B: expand score filter to customer list with recommended_limit from latest evaluation
    $candidates = []; // [{customer_id, recommended_limit, overall_score, phone, name, email}]
    if (empty($customerIds) && $minScore !== null) {
        $stmt = $db->prepare("
            WITH latest AS (
                SELECT DISTINCT ON (customer_id) customer_id, overall_score, recommended_limit, final_decision
                FROM om_credit_evaluations
                ORDER BY customer_id, evaluated_at DESC
            )
            SELECT l.customer_id, l.overall_score, l.recommended_limit,
                   c.name, c.email, c.phone
            FROM latest l
            LEFT JOIN om_customers c ON c.customer_id = l.customer_id
            WHERE l.overall_score BETWEEN ? AND ?
              AND l.final_decision != 'negado'
              AND NOT EXISTS (
                  SELECT 1 FROM om_credit_cards cc
                  WHERE cc.customer_id = l.customer_id
                    AND cc.status IN ('pending_approval','pre_approved','active','blocked')
              )
            ORDER BY l.overall_score DESC
            LIMIT 500
        ");
        $stmt->execute([$minScore, $maxScore]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif (!empty($customerIds)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        if (empty($ids)) response(false, null, 'Nenhum customer_id valido', 400);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT c.customer_id, c.name, c.email, c.phone,
                   (SELECT overall_score FROM om_credit_evaluations e
                    WHERE e.customer_id = c.customer_id
                    ORDER BY evaluated_at DESC LIMIT 1) AS overall_score,
                   (SELECT recommended_limit FROM om_credit_evaluations e
                    WHERE e.customer_id = c.customer_id
                    ORDER BY evaluated_at DESC LIMIT 1) AS recommended_limit
            FROM om_customers c
            WHERE c.customer_id IN ({$placeholders})
        ");
        $stmt->execute($ids);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        response(false, null, 'Informe customer_ids ou min_score', 400);
    }

    $sent = 0;
    $skipped = 0;
    $alreadyHasCard = 0;
    $details = [];
    $expiresAt = (new DateTime("+{$offerDays} days"))->format('Y-m-d H:i:s');

    foreach ($candidates as $cand) {
        $cid = (int)$cand['customer_id'];
        $recommended = $cand['recommended_limit'] !== null ? (float)$cand['recommended_limit'] : 0;
        $score = $cand['overall_score'] !== null ? (int)$cand['overall_score'] : 0;

        $customerLimit = $fixedLimit;
        if ($limitMode === 'recommended') {
            $customerLimit = $recommended > 0 ? $recommended : 500;
        } elseif ($limitMode === 'score_based') {
            // Map score 0..1000 to limit R$200..R$5000 (linear), capped.
            $customerLimit = max(200, min(5000, round(($score / 1000) * 5000, -1)));
        }

        if ($customerLimit <= 0) { $skipped++; $details[] = ['customer_id' => $cid, 'status' => 'skipped', 'reason' => 'limite invalido']; continue; }

        if ($dryRun) {
            $details[] = [
                'customer_id' => $cid,
                'status'      => 'eligible',
                'limit'       => $customerLimit,
                'score'       => $score,
            ];
            $sent++;
            continue;
        }

        // Check existing non-terminal card
        $stmt = $db->prepare("
            SELECT id FROM om_credit_cards
            WHERE customer_id = ? AND status IN ('pending_approval','pre_approved','active','blocked')
            LIMIT 1
        ");
        $stmt->execute([$cid]);
        if ($stmt->fetch()) {
            $alreadyHasCard++;
            $details[] = ['customer_id' => $cid, 'status' => 'already_has_card'];
            continue;
        }

        try {
            $ins = $db->prepare("
                INSERT INTO om_credit_cards (
                    customer_id, card_brand, card_number_encrypted, card_last4, cvv_encrypted,
                    expires_at, credit_limit, used_limit, status, virtual,
                    offer_created_at, offer_expires_at, offer_terms_interest_rate, offer_terms_annual_fee,
                    offered_by_admin_id, score
                ) VALUES (
                    ?, 'Mastercard', '', '0000', '',
                    '', ?, 0, 'pre_approved', true,
                    NOW(), ?, ?, ?,
                    ?, ?
                ) RETURNING id
            ");
            $ins->execute([$cid, $customerLimit, $expiresAt, $interestRate, $annualFee, $adminId ?: null, $score ?: null]);
            $cardId = (int)$ins->fetchColumn();

            logCardEvent($db, $cardId, $cid, 'pre_approved_bulk', 'admin', $adminId, [
                'limit' => $customerLimit, 'score' => $score, 'mode' => $limitMode,
            ]);

            // Push
            try {
                notifyCustomer($db, $cid,
                    'Cartao SuperBora pre-aprovado!',
                    sprintf('Voce tem R$ %s de limite. Aceite agora no app.', number_format($customerLimit, 2, ',', '.')),
                    '/cartao/oferta',
                    ['type' => 'card_offer', 'card_id' => $cardId, 'limit' => $customerLimit, 'deep_link' => '/cartao/oferta']
                );
            } catch (Exception $e) {
                error_log('[bulk-pre-approve notify push] ' . $e->getMessage());
            }

            // In-app inbox
            try {
                $db->prepare("
                    INSERT INTO om_market_notifications (customer_id, title, message, type, data)
                    VALUES (?, ?, ?, 'card_offer', ?)
                ")->execute([
                    $cid,
                    'Cartao SuperBora pre-aprovado!',
                    sprintf('Voce tem R$ %s de limite esperando.', number_format($customerLimit, 2, ',', '.')),
                    json_encode(['card_id' => $cardId, 'limit' => $customerLimit, 'deep_link' => '/cartao/oferta'], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (Exception $e) { /* ignore missing table */ }

            $sent++;
            $details[] = ['customer_id' => $cid, 'status' => 'sent', 'card_id' => $cardId, 'limit' => $customerLimit];
        } catch (Exception $e) {
            error_log('[bulk-pre-approve insert] ' . $e->getMessage());
            $skipped++;
            $details[] = ['customer_id' => $cid, 'status' => 'error', 'reason' => $e->getMessage()];
        }
    }

    response(true, [
        'sent'              => $sent,
        'skipped'           => $skipped,
        'already_has_card'  => $alreadyHasCard,
        'total_candidates'  => count($candidates),
        'offer_expires_at'  => $expiresAt,
        'dry_run'           => $dryRun,
        'details'           => $details,
    ]);
} catch (Exception $e) {
    error_log('[admin-cards-bulk-pre-approve] ' . $e->getMessage());
    response(false, null, 'Erro ao processar pre-aprovacoes em massa', 500);
}
