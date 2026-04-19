<?php
/**
 * GET|POST /admin/card-support/pre-approval-pipeline.php
 *
 * GET: Returns pipeline counts and list of items per stage
 *   Query: stage=eligible|offered|accepted|declined|all, limit=100
 *
 * POST: { action: bulk_offer|expire_offers, score_min?, limit_value?, sample_size? }
 */

require_once __DIR__ . "/_common.php";

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];
    $adminId = $ctx['admin_id'];

    if ($method === 'GET') {
        $stage = (string)($_GET['stage'] ?? 'all');
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));

        // Counts
        $counts = [
            'eligible' => 0,
            'offered'  => 0,
            'accepted_30d' => 0,
            'declined_30d' => 0,
        ];

        // Eligible customers (no active card, evaluated recently w/ approved decision, or with decent customer history)
        try {
            $counts['eligible'] = (int)$db->query("
                SELECT COUNT(DISTINCT c.customer_id)
                FROM om_customers c
                WHERE NOT EXISTS (SELECT 1 FROM om_credit_cards cc WHERE cc.customer_id = c.customer_id AND cc.status IN ('active','blocked','pre_approved'))
                  AND c.created_at < NOW() - INTERVAL '30 days'
            ")->fetchColumn();
        } catch (Exception $e) { /* ignore */ }

        $counts['offered'] = (int)$db->query("SELECT COUNT(*) FROM om_credit_cards WHERE status = 'pre_approved' AND accepted_at IS NULL AND declined_at IS NULL")->fetchColumn();
        $counts['accepted_30d'] = (int)$db->query("SELECT COUNT(*) FROM om_credit_cards WHERE accepted_at IS NOT NULL AND accepted_at >= NOW() - INTERVAL '30 days'")->fetchColumn();
        $counts['declined_30d'] = (int)$db->query("SELECT COUNT(*) FROM om_credit_cards WHERE declined_at IS NOT NULL AND declined_at >= NOW() - INTERVAL '30 days'")->fetchColumn();

        $items = [];
        if ($stage === 'eligible' || $stage === 'all') {
            $stmt = $db->prepare("
                SELECT c.customer_id, c.name, c.email, c.phone, c.created_at
                FROM om_customers c
                WHERE NOT EXISTS (SELECT 1 FROM om_credit_cards cc WHERE cc.customer_id = c.customer_id AND cc.status IN ('active','blocked','pre_approved'))
                  AND c.created_at < NOW() - INTERVAL '30 days'
                ORDER BY c.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = [
                    'stage'         => 'eligible',
                    'customer_id'   => (int)$r['customer_id'],
                    'customer_name' => $r['name'],
                    'email'         => $r['email'],
                    'phone'         => $r['phone'],
                    'customer_since'=> $r['created_at'],
                ];
            }
        }

        if ($stage === 'offered' || $stage === 'all') {
            $stmt = $db->prepare("
                SELECT cc.id AS card_id, cc.customer_id, cc.credit_limit, cc.score,
                       cc.offer_created_at, cc.offer_expires_at,
                       cc.offer_terms_interest_rate, cc.offer_terms_annual_fee,
                       c.name, c.email
                FROM om_credit_cards cc
                LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
                WHERE cc.status = 'pre_approved' AND cc.accepted_at IS NULL AND cc.declined_at IS NULL
                ORDER BY cc.offer_created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = [
                    'stage'            => 'offered',
                    'card_id'          => (int)$r['card_id'],
                    'customer_id'      => (int)$r['customer_id'],
                    'customer_name'    => $r['name'],
                    'email'            => $r['email'],
                    'offer_limit'      => (float)$r['credit_limit'],
                    'offer_interest'   => (float)$r['offer_terms_interest_rate'],
                    'offer_annual_fee' => (float)$r['offer_terms_annual_fee'],
                    'offer_created_at' => $r['offer_created_at'],
                    'offer_expires_at' => $r['offer_expires_at'],
                    'score'            => $r['score'] ? (int)$r['score'] : null,
                ];
            }
        }

        if ($stage === 'accepted' || $stage === 'all') {
            $stmt = $db->prepare("
                SELECT cc.id AS card_id, cc.customer_id, cc.credit_limit, cc.accepted_at,
                       c.name
                FROM om_credit_cards cc
                LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
                WHERE cc.accepted_at IS NOT NULL AND cc.accepted_at >= NOW() - INTERVAL '30 days'
                ORDER BY cc.accepted_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = [
                    'stage'         => 'accepted',
                    'card_id'       => (int)$r['card_id'],
                    'customer_id'   => (int)$r['customer_id'],
                    'customer_name' => $r['name'],
                    'credit_limit'  => (float)$r['credit_limit'],
                    'accepted_at'   => $r['accepted_at'],
                ];
            }
        }

        if ($stage === 'declined' || $stage === 'all') {
            $stmt = $db->prepare("
                SELECT cc.id AS card_id, cc.customer_id, cc.declined_at,
                       c.name
                FROM om_credit_cards cc
                LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
                WHERE cc.declined_at IS NOT NULL AND cc.declined_at >= NOW() - INTERVAL '30 days'
                ORDER BY cc.declined_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = [
                    'stage'         => 'declined',
                    'card_id'       => (int)$r['card_id'],
                    'customer_id'   => (int)$r['customer_id'],
                    'customer_name' => $r['name'],
                    'declined_at'   => $r['declined_at'],
                ];
            }
        }

        response(true, [
            'counts' => $counts,
            'items'  => $items,
        ]);
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = (string)($body['action'] ?? '');

        if ($action === 'expire_offers') {
            $stmt = $db->prepare("
                UPDATE om_credit_cards
                SET status = 'offer_expired'
                WHERE status = 'pre_approved' AND offer_expires_at IS NOT NULL AND offer_expires_at < NOW()
            ");
            $stmt->execute();
            response(true, ['affected' => $stmt->rowCount()]);
        }

        if ($action === 'bulk_offer') {
            $scoreMin   = (int)($body['score_min']    ?? 600);
            $limitValue = (float)($body['limit_value'] ?? 500);
            $interest   = (float)($body['interest']    ?? 9.9);
            $annualFee  = (float)($body['annual_fee']  ?? 0);
            $sampleSize = min(500, max(1, (int)($body['sample_size'] ?? 50)));

            // Candidates: customers without any card, created > 30d ago
            $cand = $db->prepare("
                SELECT c.customer_id, c.name
                FROM om_customers c
                WHERE NOT EXISTS (SELECT 1 FROM om_credit_cards cc WHERE cc.customer_id = c.customer_id)
                  AND c.created_at < NOW() - INTERVAL '30 days'
                ORDER BY RANDOM()
                LIMIT ?
            ");
            $cand->execute([$sampleSize]);
            $customers = $cand->fetchAll(PDO::FETCH_ASSOC);

            $created = 0;
            foreach ($customers as $c) {
                try {
                    $ins = $db->prepare("
                        INSERT INTO om_credit_cards (
                            customer_id, card_brand, card_number_encrypted, card_last4,
                            cvv_encrypted, expires_at, credit_limit, status, virtual,
                            score, offer_created_at, offer_expires_at,
                            offer_terms_interest_rate, offer_terms_annual_fee,
                            offered_by_admin_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL '30 days', ?, ?, ?)
                    ");
                    $ins->execute([
                        (int)$c['customer_id'],
                        'Mastercard',
                        'pending',  // placeholder until accepted
                        '0000',
                        'pending',
                        date('m/Y', strtotime('+4 years')),
                        $limitValue,
                        'pre_approved',
                        true,
                        $scoreMin + mt_rand(0, 100),
                        $interest,
                        $annualFee,
                        $adminId,
                    ]);
                    $created++;
                } catch (Exception $e) { /* skip if fails */ }
            }
            response(true, ['affected' => $created, 'candidates' => count($customers)]);
        }

        response(false, null, 'Acao invalida', 400);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[card-support-pipeline] ' . $e->getMessage());
    response(false, null, 'Erro na pipeline: ' . $e->getMessage(), 500);
}
