<?php
/**
 * GET/POST /api/mercado/partner/plan.php
 * Plano do parceiro — visualizacao e solicitacao de upgrade/downgrade
 *
 * GET  - Retorna plano atual, planos disponiveis, historico de mudancas, stats
 * POST action=request_upgrade - Solicita mudanca de plano
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

    // Ensure plan_change_requests table exists
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS om_plan_change_requests (
                id SERIAL PRIMARY KEY,
                partner_id INTEGER NOT NULL,
                from_plan_id INTEGER,
                to_plan_id INTEGER NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                requested_at TIMESTAMP NOT NULL DEFAULT NOW(),
                processed_at TIMESTAMP,
                processed_by INTEGER,
                notes TEXT,
                CONSTRAINT fk_pcr_partner FOREIGN KEY (partner_id) REFERENCES om_market_partners(partner_id),
                CONSTRAINT fk_pcr_from_plan FOREIGN KEY (from_plan_id) REFERENCES om_partner_plans(id),
                CONSTRAINT fk_pcr_to_plan FOREIGN KEY (to_plan_id) REFERENCES om_partner_plans(id)
            )
        ");
    } catch (Exception $e) {
        // table already exists
    }

    // Ensure plan_id column exists on partners
    try {
        $db->exec("ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS plan_id INTEGER REFERENCES om_partner_plans(id)");
    } catch (Exception $e) {
        // column already exists
    }

    // Ensure 3 plan tiers exist (basico, profissional, enterprise)
    try {
        // Profissional plan (if not exists)
        $existsProf = dbQuery($db, "SELECT id FROM om_partner_plans WHERE slug = 'profissional' LIMIT 1")->fetch();
        if (!$existsProf) {
            dbQuery($db, "
                INSERT INTO om_partner_plans (slug, name, description, commission_rate, commission_online_rate, uses_platform_delivery, delivery_commission, status)
                VALUES ('profissional', 'Profissional', 'Plano completo com entrega BoraUm, analytics avancado, suporte prioritario e ferramentas IA', 8, 8, true, 5, 1)
            ");
        }
        // Enterprise plan (if not exists)
        $existsEnt = dbQuery($db, "SELECT id FROM om_partner_plans WHERE slug = 'enterprise' LIMIT 1")->fetch();
        if (!$existsEnt) {
            dbQuery($db, "
                INSERT INTO om_partner_plans (slug, name, description, commission_rate, commission_online_rate, uses_platform_delivery, delivery_commission, status)
                VALUES ('enterprise', 'Enterprise', 'Plano premium com gerente dedicado, API, white-label, suporte 24/7 e menor comissao', 5, 5, true, 3, 1)
            ");
        }
        // Update basico plan description/rates if needed
        $existsBasico = dbQuery($db, "SELECT id FROM om_partner_plans WHERE slug = 'basico' LIMIT 1")->fetch();
        if ($existsBasico) {
            dbQuery($db, "
                UPDATE om_partner_plans SET commission_rate = 12, commission_online_rate = 12,
                description = 'Plano gratuito com dashboard basico, gestao de pedidos e entrega propria'
                WHERE slug = 'basico' AND commission_rate < 12
            ");
        }
    } catch (Exception $e) {
        // Plans already set up
    }

    // GET — Return plan data
    if ($method === 'GET') {
        // 1. Current partner with plan info
        $partner = dbQuery($db, "
            SELECT p.partner_id, p.name, p.plan_id,
                   pp.slug AS plan_slug, pp.name AS plan_name, pp.description AS plan_description,
                   pp.commission_rate, pp.commission_online_rate,
                   pp.uses_platform_delivery, pp.delivery_commission
            FROM om_market_partners p
            LEFT JOIN om_partner_plans pp ON pp.id = p.plan_id
            WHERE p.partner_id = ?
        ", [$partnerId])->fetch();

        if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

        // 2. All available plans
        $plans = dbQuery($db, "
            SELECT id, slug, name, description, commission_rate, commission_online_rate,
                   uses_platform_delivery, delivery_commission, status
            FROM om_partner_plans
            WHERE status = 1
            ORDER BY commission_rate ASC
        ")->fetchAll();

        // 3. Plan change history
        $history = dbQuery($db, "
            SELECT pcr.id, pcr.status, pcr.requested_at, pcr.processed_at, pcr.notes,
                   fp.name AS from_plan_name, fp.slug AS from_plan_slug,
                   tp.name AS to_plan_name, tp.slug AS to_plan_slug
            FROM om_plan_change_requests pcr
            LEFT JOIN om_partner_plans fp ON fp.id = pcr.from_plan_id
            LEFT JOIN om_partner_plans tp ON tp.id = pcr.to_plan_id
            WHERE pcr.partner_id = ?
            ORDER BY pcr.requested_at DESC
            LIMIT 20
        ", [$partnerId])->fetchAll();

        // 4. Usage stats this month
        $monthStart = date('Y-m-01 00:00:00');
        $stats = dbQuery($db, "
            SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(total), 0) AS total_revenue
            FROM om_market_orders
            WHERE partner_id = ?
              AND status NOT IN ('cancelado', 'recusado')
              AND created_at >= ?
        ", [$partnerId, $monthStart])->fetch();

        // 5. Check if there's a pending request
        $pendingRequest = dbQuery($db, "
            SELECT pcr.id, pcr.status, pcr.requested_at,
                   tp.name AS to_plan_name, tp.slug AS to_plan_slug
            FROM om_plan_change_requests pcr
            LEFT JOIN om_partner_plans tp ON tp.id = pcr.to_plan_id
            WHERE pcr.partner_id = ? AND pcr.status = 'pending'
            ORDER BY pcr.requested_at DESC
            LIMIT 1
        ", [$partnerId])->fetch();

        $result = [
            'current_plan' => [
                'id' => $partner['plan_id'],
                'slug' => $partner['plan_slug'] ?? 'basico',
                'name' => $partner['plan_name'] ?? 'Basico',
                'description' => $partner['plan_description'] ?? '',
                'commission_rate' => (float)($partner['commission_rate'] ?? 5),
                'commission_online_rate' => (float)($partner['commission_online_rate'] ?? 8),
                'uses_platform_delivery' => (bool)($partner['uses_platform_delivery'] ?? false),
                'delivery_commission' => (float)($partner['delivery_commission'] ?? 0),
            ],
            'available_plans' => array_map(function ($p) {
                return [
                    'id' => (int)$p['id'],
                    'slug' => $p['slug'],
                    'name' => $p['name'],
                    'description' => $p['description'],
                    'commission_rate' => (float)$p['commission_rate'],
                    'commission_online_rate' => (float)$p['commission_online_rate'],
                    'uses_platform_delivery' => (bool)$p['uses_platform_delivery'],
                    'delivery_commission' => (float)$p['delivery_commission'],
                    'status' => $p['status'],
                ];
            }, $plans),
            'history' => array_map(function ($h) {
                return [
                    'id' => (int)$h['id'],
                    'from_plan' => $h['from_plan_name'],
                    'from_slug' => $h['from_plan_slug'],
                    'to_plan' => $h['to_plan_name'],
                    'to_slug' => $h['to_plan_slug'],
                    'status' => $h['status'],
                    'requested_at' => $h['requested_at'],
                    'processed_at' => $h['processed_at'],
                    'notes' => $h['notes'],
                ];
            }, $history),
            'stats' => [
                'total_orders' => (int)($stats['total_orders'] ?? 0),
                'total_revenue' => (float)($stats['total_revenue'] ?? 0),
            ],
            'pending_request' => $pendingRequest ? [
                'id' => (int)$pendingRequest['id'],
                'to_plan' => $pendingRequest['to_plan_name'],
                'to_slug' => $pendingRequest['to_plan_slug'],
                'requested_at' => $pendingRequest['requested_at'],
            ] : null,
        ];

        response(true, $result);
    }

    // POST — Request plan change
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';

        if ($action !== 'request_upgrade') {
            response(false, null, "Acao invalida. Use action=request_upgrade", 400);
        }

        $toPlanId = (int)($input['to_plan_id'] ?? 0);
        if (!$toPlanId) {
            response(false, null, "Plano destino obrigatorio (to_plan_id)", 400);
        }

        // Validate target plan exists and is active
        $targetPlan = dbQuery($db, "
            SELECT id, slug, name FROM om_partner_plans WHERE id = ? AND status = 1
        ", [$toPlanId])->fetch();

        if (!$targetPlan) {
            response(false, null, "Plano destino nao encontrado ou inativo", 404);
        }

        // Get current plan
        $currentPartner = dbQuery($db, "
            SELECT plan_id FROM om_market_partners WHERE partner_id = ?
        ", [$partnerId])->fetch();

        $currentPlanId = $currentPartner['plan_id'] ?? null;

        // Don't allow requesting same plan
        if ($currentPlanId && (int)$currentPlanId === $toPlanId) {
            response(false, null, "Voce ja esta neste plano", 400);
        }

        // Check for existing pending request
        $existing = dbQuery($db, "
            SELECT id FROM om_plan_change_requests
            WHERE partner_id = ? AND status = 'pending'
            LIMIT 1
        ", [$partnerId])->fetch();

        if ($existing) {
            response(false, null, "Ja existe uma solicitacao pendente. Aguarde o processamento ou cancele a anterior.", 400);
        }

        // Create the request
        dbQuery($db, "
            INSERT INTO om_plan_change_requests (partner_id, from_plan_id, to_plan_id, status, requested_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ", [$partnerId, $currentPlanId, $toPlanId]);

        response(true, [
            'requested' => true,
            'to_plan' => $targetPlan['name'],
            'to_slug' => $targetPlan['slug'],
        ], "Solicitacao de mudanca de plano enviada com sucesso! Nossa equipe ira analisar em ate 24 horas.");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/plan] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar plano", 500);
}
