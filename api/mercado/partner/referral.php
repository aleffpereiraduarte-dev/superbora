<?php
/**
 * GET/POST /api/mercado/partner/referral.php
 * Referral program management for partners
 *
 * GET:                  Get referral config + stats
 * GET action=referrals: List all referrals
 * POST action=configure: Set up referral program
 * POST action=generate_code: Generate referral code for a customer
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];
    $method = $_SERVER["REQUEST_METHOD"];

    // Ensure tables exist
    ensureReferralTables($db);

    // ===== GET =====
    if ($method === "GET") {
        $action = $_GET['action'] ?? '';

        // ----- List referrals -----
        if ($action === 'referrals') {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $status = trim($_GET['status'] ?? '');

            $where = ["r.partner_id = ?"];
            $params = [$partner_id];

            if ($status !== '' && in_array($status, ['pending', 'converted', 'expired'], true)) {
                $where[] = "r.status = ?";
                $params[] = $status;
            }

            $whereSQL = implode(" AND ", $where);

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_referrals r WHERE {$whereSQL}");
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();

            $stmt = $db->prepare("
                SELECT
                    r.id,
                    r.code,
                    r.status,
                    r.order_id,
                    r.created_at,
                    r.converted_at,
                    r.referrer_customer_id,
                    r.referee_customer_id,
                    COALESCE(c1.name, 'Cliente #' || r.referrer_customer_id::text) as referrer_name,
                    COALESCE(c2.name, '') as referee_name
                FROM om_market_referrals r
                LEFT JOIN om_market_customers c1 ON c1.customer_id = r.referrer_customer_id
                LEFT JOIN om_market_customers c2 ON c2.customer_id = r.referee_customer_id
                WHERE {$whereSQL}
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute(array_merge($params, [$limit, $offset]));
            $referrals = $stmt->fetchAll();

            $items = [];
            foreach ($referrals as $ref) {
                $items[] = [
                    "id" => (int)$ref['id'],
                    "code" => $ref['code'],
                    "referrer_name" => $ref['referrer_name'] ?: 'Desconhecido',
                    "referee_name" => $ref['referee_name'] ?: '',
                    "status" => $ref['status'],
                    "order_id" => $ref['order_id'] ? (int)$ref['order_id'] : null,
                    "created_at" => $ref['created_at'],
                    "converted_at" => $ref['converted_at'],
                ];
            }

            $pages = $total > 0 ? (int)ceil($total / $limit) : 1;

            response(true, [
                "items" => $items,
                "pagination" => [
                    "total" => $total,
                    "page" => $page,
                    "pages" => $pages,
                    "limit" => $limit,
                ]
            ], "Indicacoes listadas");
        }

        // ----- Default GET: Config + stats -----
        else {
            // Get config
            $stmt = $db->prepare("
                SELECT partner_id, enabled, reward_type, referrer_reward_value, referee_reward_value, min_order_value, max_uses_per_customer
                FROM om_market_referral_config WHERE partner_id = ?
            ");
            $stmt->execute([$partner_id]);
            $config = $stmt->fetch();

            if (!$config) {
                $config = [
                    'enabled' => false,
                    'reward_type' => 'discount',
                    'referrer_reward_value' => 10,
                    'referee_reward_value' => 10,
                    'min_order_value' => 0,
                    'max_uses_per_customer' => 5,
                ];
            } else {
                $config = [
                    'enabled' => (bool)$config['enabled'],
                    'reward_type' => $config['reward_type'],
                    'referrer_reward_value' => (float)$config['referrer_reward_value'],
                    'referee_reward_value' => (float)$config['referee_reward_value'],
                    'min_order_value' => (float)$config['min_order_value'],
                    'max_uses_per_customer' => (int)$config['max_uses_per_customer'],
                ];
            }

            // Get stats
            $stmtTotal = $db->prepare("
                SELECT COUNT(*) FROM om_market_referrals WHERE partner_id = ?
            ");
            $stmtTotal->execute([$partner_id]);
            $totalReferrals = (int)$stmtTotal->fetchColumn();

            $stmtConverted = $db->prepare("
                SELECT COUNT(*) FROM om_market_referrals WHERE partner_id = ? AND status = 'converted'
            ");
            $stmtConverted->execute([$partner_id]);
            $totalConversions = (int)$stmtConverted->fetchColumn();

            $stmtRevenue = $db->prepare("
                SELECT COALESCE(SUM(o.total), 0)
                FROM om_market_referrals r
                JOIN om_market_orders o ON o.order_id = r.order_id
                WHERE r.partner_id = ? AND r.status = 'converted'
            ");
            $stmtRevenue->execute([$partner_id]);
            $totalRevenue = (float)$stmtRevenue->fetchColumn();

            $stmtPending = $db->prepare("
                SELECT COUNT(*) FROM om_market_referrals WHERE partner_id = ? AND status = 'pending'
            ");
            $stmtPending->execute([$partner_id]);
            $totalPending = (int)$stmtPending->fetchColumn();

            $conversionRate = $totalReferrals > 0
                ? round(($totalConversions / $totalReferrals) * 100, 1)
                : 0;

            response(true, [
                "config" => $config,
                "stats" => [
                    "total_referrals" => $totalReferrals,
                    "total_conversions" => $totalConversions,
                    "total_pending" => $totalPending,
                    "total_revenue" => $totalRevenue,
                    "conversion_rate" => $conversionRate,
                ],
            ], "Configuracao de indicacao");
        }
    }

    // ===== POST =====
    elseif ($method === "POST") {
        $input = getInput();
        $action = $input['action'] ?? '';

        // ----- Configure referral program -----
        if ($action === 'configure') {
            $enabled = !empty($input['enabled']);
            $reward_type = trim($input['reward_type'] ?? 'discount');
            $referrer_reward_value = (float)($input['referrer_reward_value'] ?? 10);
            $referee_reward_value = (float)($input['referee_reward_value'] ?? 10);
            $min_order_value = (float)($input['min_order_value'] ?? 0);
            $max_uses_per_customer = (int)($input['max_uses_per_customer'] ?? 5);

            // Validations
            if (!in_array($reward_type, ['discount', 'cashback', 'free_item'], true)) {
                response(false, null, "Tipo de recompensa invalido (discount, cashback, free_item)", 400);
            }

            if ($referrer_reward_value < 0 || $referrer_reward_value > 1000) {
                response(false, null, "Valor da recompensa do indicador invalido", 400);
            }

            if ($referee_reward_value < 0 || $referee_reward_value > 1000) {
                response(false, null, "Valor da recompensa do indicado invalido", 400);
            }

            if ($min_order_value < 0) {
                response(false, null, "Valor minimo do pedido invalido", 400);
            }

            if ($max_uses_per_customer < 1 || $max_uses_per_customer > 1000) {
                response(false, null, "Limite de usos por cliente invalido", 400);
            }

            // Upsert config
            $stmt = $db->prepare("
                INSERT INTO om_market_referral_config
                    (partner_id, enabled, reward_type, referrer_reward_value, referee_reward_value, min_order_value, max_uses_per_customer, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON CONFLICT (partner_id)
                DO UPDATE SET
                    enabled = EXCLUDED.enabled,
                    reward_type = EXCLUDED.reward_type,
                    referrer_reward_value = EXCLUDED.referrer_reward_value,
                    referee_reward_value = EXCLUDED.referee_reward_value,
                    min_order_value = EXCLUDED.min_order_value,
                    max_uses_per_customer = EXCLUDED.max_uses_per_customer,
                    updated_at = NOW()
            ");
            $stmt->execute([
                $partner_id, $enabled, $reward_type,
                $referrer_reward_value, $referee_reward_value,
                $min_order_value, $max_uses_per_customer
            ]);

            om_audit()->log(OmAudit::ACTION_UPDATE, 'referral_config', $partner_id, null,
                ['enabled' => $enabled, 'reward_type' => $reward_type, 'referrer' => $referrer_reward_value, 'referee' => $referee_reward_value],
                "Programa de indicacao " . ($enabled ? "ativado" : "desativado"), 'partner', $partner_id);

            response(true, null, "Programa de indicacao " . ($enabled ? "ativado" : "atualizado"));
        }

        // ----- Generate referral code -----
        elseif ($action === 'generate_code') {
            $customer_id = (int)($input['customer_id'] ?? 0);

            // Generate a unique referral code
            $code = generateReferralCode($db, $partner_id);

            $stmt = $db->prepare("
                INSERT INTO om_market_referrals
                    (partner_id, referrer_customer_id, code, status, created_at)
                VALUES (?, ?, ?, 'pending', NOW())
                RETURNING id
            ");
            $stmt->execute([$partner_id, $customer_id ?: null, $code]);
            $newId = (int)$stmt->fetchColumn();

            om_audit()->log(OmAudit::ACTION_CREATE, 'referral', $newId, null,
                ['code' => $code, 'customer_id' => $customer_id],
                "Codigo de indicacao gerado: {$code}", 'partner', $partner_id);

            response(true, [
                "id" => $newId,
                "code" => $code,
            ], "Codigo de indicacao gerado");
        }

        else {
            response(false, null, "Acao invalida", 400);
        }
    }

    else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/referral] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// ===== Helper Functions =====

function ensureReferralTables(PDO $db): void {
    // No-op: tables created via migration
}

function generateReferralCode(PDO $db, int $partner_id): string {
    $maxAttempts = 10;
    for ($i = 0; $i < $maxAttempts; $i++) {
        // Generate code like: SB-XXXX-XXXX
        $code = 'SB-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));

        // Check uniqueness
        $stmt = $db->prepare("SELECT id FROM om_market_referrals WHERE code = ?");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }

    // Fallback with partner prefix
    return 'SB-' . $partner_id . '-' . strtoupper(bin2hex(random_bytes(3)));
}
