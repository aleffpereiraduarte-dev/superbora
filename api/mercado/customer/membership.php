<?php
/**
 * /api/mercado/customer/membership.php
 * SuperBora+ Premium Membership
 *
 * GET  - Get membership status
 * POST - Subscribe to premium
 * PUT  - Cancel membership
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Auth
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    // Ensure table exists
    // NOTE: Table om_market_memberships must be created via SQL migration, not at runtime.
    // The original MySQL DDL below is incompatible with PostgreSQL and has been disabled.
    //     $db->exec("
    //         CREATE TABLE IF NOT EXISTS om_market_memberships (
    //             id INT AUTO_INCREMENT PRIMARY KEY,
    //             customer_id INT NOT NULL,
    //             plan ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    //             price DECIMAL(10,2) NOT NULL,
    //             starts_at DATETIME NOT NULL,
    //             expires_at DATETIME NOT NULL,
    //             status ENUM('active','cancelled','expired') NOT NULL DEFAULT 'active',
    //             created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    //             updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    //             INDEX idx_customer (customer_id),
    //             INDEX idx_status (status),
    //             INDEX idx_expires (expires_at)
    //         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    //     ");

    $method = $_SERVER['REQUEST_METHOD'];

    // Plan details
    $plans = [
        'monthly' => [
            'name' => 'SuperBora+ Mensal',
            'price' => 4.90,
            'duration_days' => 30,
            'label' => 'R$ 4,90/mes',
        ],
    ];

    $benefits = [
        [
            'icon' => 'truck',
            'title' => '10% desconto no frete',
            'description' => 'Desconto no frete BoraUm em todos os pedidos',
        ],
        [
            'icon' => 'tag',
            'title' => '5% desconto retirada',
            'description' => 'Desconto em pedidos com retirada na loja',
        ],
        [
            'icon' => 'star',
            'title' => '1.5x pontos de fidelidade',
            'description' => '50% mais pontos em cada compra',
        ],
        [
            'icon' => 'headphones',
            'title' => 'Ofertas exclusivas',
            'description' => 'Acesso antecipado a promocoes e cupons exclusivos',
        ],
    ];

    // ─── GET: Get membership status ──────────────────────────────────────
    if ($method === 'GET') {
        // Auto-expire past memberships
        $db->prepare("
            UPDATE om_market_memberships
            SET status = 'expired'
            WHERE customer_id = ? AND status = 'active' AND expires_at < NOW()
        ")->execute([$customerId]);

        $stmt = $db->prepare("
            SELECT * FROM om_market_memberships
            WHERE customer_id = ? AND status IN ('active','cancelled')
            AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$customerId]);
        $membership = $stmt->fetch();

        if ($membership) {
            $planKey = $membership['plan'];
            $planInfo = $plans[$planKey] ?? $plans['monthly'];

            response(true, [
                'is_premium' => true,
                'membership' => [
                    'id' => (int)$membership['id'],
                    'plan' => $membership['plan'],
                    'plan_name' => $planInfo['name'],
                    'plan_label' => $planInfo['label'],
                    'price' => (float)$membership['price'],
                    'starts_at' => $membership['starts_at'],
                    'expires_at' => $membership['expires_at'],
                    'status' => $membership['status'],
                    'is_cancelled' => $membership['status'] === 'cancelled',
                ],
                'benefits' => $benefits,
                'plans' => $plans,
            ]);
        } else {
            response(true, [
                'is_premium' => false,
                'membership' => null,
                'benefits' => $benefits,
                'plans' => $plans,
            ]);
        }
    }

    // ─── POST: Subscribe to premium ──────────────────────────────────────
    elseif ($method === 'POST') {
        $input = getInput();
        $planKey = $input['plan'] ?? 'monthly';

        if (!isset($plans[$planKey])) {
            response(false, null, "Plano invalido. Use: monthly ou yearly", 400);
        }

        // SECURITY: Transaction + FOR UPDATE to prevent duplicate membership race condition
        $db->beginTransaction();

        $stmtCheck = $db->prepare("
            SELECT id FROM om_market_memberships
            WHERE customer_id = ? AND status = 'active' AND expires_at > NOW()
            LIMIT 1
            FOR UPDATE
        ");
        $stmtCheck->execute([$customerId]);
        if ($stmtCheck->fetch()) {
            $db->rollBack();
            response(false, null, "Voce ja possui uma assinatura ativa", 400);
        }

        $plan = $plans[$planKey];
        $startsAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$plan['duration_days']} days"));

        $stmt = $db->prepare("
            INSERT INTO om_market_memberships (customer_id, plan, price, starts_at, expires_at, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->execute([$customerId, $planKey, $plan['price'], $startsAt, $expiresAt]);
        $db->commit();

        $membershipId = (int)$db->lastInsertId();

        response(true, [
            'membership_id' => $membershipId,
            'plan' => $planKey,
            'plan_name' => $plan['name'],
            'price' => $plan['price'],
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'status' => 'active',
            'benefits' => $benefits,
        ], "Bem-vindo ao SuperBora+! Aproveite seus beneficios.");
    }

    // ─── PUT: Cancel membership ──────────────────────────────────────────
    elseif ($method === 'PUT') {
        $stmtCheck = $db->prepare("
            SELECT id, expires_at FROM om_market_memberships
            WHERE customer_id = ? AND status = 'active' AND expires_at > NOW()
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmtCheck->execute([$customerId]);
        $membership = $stmtCheck->fetch();

        if (!$membership) {
            response(false, null, "Nenhuma assinatura ativa encontrada", 404);
        }

        // Set to cancelled - membership stays active until expires_at
        $db->prepare("UPDATE om_market_memberships SET status = 'cancelled' WHERE id = ?")
            ->execute([(int)$membership['id']]);

        response(true, [
            'id' => (int)$membership['id'],
            'status' => 'cancelled',
            'expires_at' => $membership['expires_at'],
            'message' => 'Assinatura cancelada. Seus beneficios permanecem ativos ate ' . date('d/m/Y', strtotime($membership['expires_at'])),
        ], "Assinatura cancelada. Beneficios ativos ate o fim do periodo.");
    }

    // ─── OPTIONS: CORS preflight ─────────────────────────────────────────
    elseif ($method === 'OPTIONS') {
        header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
        http_response_code(204);
        exit;
    }

    else {
        response(false, null, "Metodo nao suportado", 405);
    }

} catch (Exception $e) {
    error_log("[API Membership] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar assinatura", 500);
}
