<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET/POST /api/mercado/partner/cashback-config.php
 * Configuracao de cashback da loja
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * GET: Retorna configuracao atual de cashback da loja
 * POST: Atualiza configuracao de cashback
 *
 * Body POST: {
 *   "cashback_percent": 5.0,    // 0-20%
 *   "min_order_value": 30.00,   // valor minimo do pedido
 *   "max_cashback": 50.00,      // maximo de cashback por pedido
 *   "expiry_days": 30,           // dias para expirar
 *   "status": 1                 // 1=ativo, 0=inativo
 * }
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

    // ─── GET: Retornar configuracao atual ───────────────────────────────────────
    if ($method === 'GET') {
        // Buscar config especifica do parceiro
        $stmt = $db->prepare("
            SELECT id, partner_id, cashback_percent, min_order_value, max_cashback, expiry_days, status
            FROM om_cashback_config
            WHERE partner_id = ?
        ");
        $stmt->execute([$partnerId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        // Se nao tem config especifica, buscar config global
        $globalConfig = null;
        if (!$config) {
            $stmt = $db->prepare("
                SELECT id, partner_id, cashback_percent, min_order_value, max_cashback, expiry_days, status
                FROM om_cashback_config
                WHERE partner_id IS NULL AND status = '1'
                LIMIT 1
            ");
            $stmt->execute();
            $globalConfig = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Estatisticas de cashback da loja
        $stmt = $db->prepare("
            SELECT
                COUNT(DISTINCT ct.customer_id) as total_customers,
                SUM(CASE WHEN ct.type = 'credit' THEN ct.amount ELSE 0 END) as total_given,
                SUM(CASE WHEN ct.type = 'debit' THEN ct.amount ELSE 0 END) as total_used,
                SUM(CASE WHEN ct.type = 'expired' THEN ct.amount ELSE 0 END) as total_expired
            FROM om_cashback_transactions ct
            WHERE ct.partner_id = ?
        ");
        $stmt->execute([$partnerId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Ultimas transacoes
        $stmt = $db->prepare("
            SELECT
                ct.id, ct.customer_id, ct.order_id, ct.type, ct.amount, ct.description, ct.created_at,
                c.name as customer_name,
                o.order_number
            FROM om_cashback_transactions ct
            LEFT JOIN om_customers c ON c.customer_id = ct.customer_id
            LEFT JOIN om_market_orders o ON o.order_id = ct.order_id
            WHERE ct.partner_id = ?
            ORDER BY ct.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$partnerId]);
        $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Formatar dados
        $configData = $config ?: [
            'id' => null,
            'partner_id' => $partnerId,
            'cashback_percent' => $globalConfig['cashback_percent'] ?? 5.00,
            'min_order_value' => $globalConfig['min_order_value'] ?? 0.00,
            'max_cashback' => $globalConfig['max_cashback'] ?? 50.00,
            'expiry_days' => $globalConfig['expiry_days'] ?? 30,
            'status' => 0 // se nao tem config propria, usa global
        ];

        response(true, [
            'config' => [
                'id' => $configData['id'],
                'enabled' => (bool)$configData['status'],
                'cashback_percent' => (float)$configData['cashback_percent'],
                'min_order_value' => (float)$configData['min_order_value'],
                'max_cashback' => (float)$configData['max_cashback'],
                'expiry_days' => (int)$configData['expiry_days'],
                'using_global' => $config === null || $config === false
            ],
            'global_config' => $globalConfig ? [
                'cashback_percent' => (float)$globalConfig['cashback_percent'],
                'min_order_value' => (float)$globalConfig['min_order_value'],
                'max_cashback' => (float)$globalConfig['max_cashback'],
                'expiry_days' => (int)$globalConfig['expiry_days']
            ] : null,
            'stats' => [
                'total_customers' => (int)($stats['total_customers'] ?? 0),
                'total_given' => (float)($stats['total_given'] ?? 0),
                'total_used' => (float)($stats['total_used'] ?? 0),
                'total_expired' => (float)($stats['total_expired'] ?? 0),
                'net_outstanding' => (float)(($stats['total_given'] ?? 0) - ($stats['total_used'] ?? 0) - ($stats['total_expired'] ?? 0))
            ],
            'recent_transactions' => array_map(function($t) {
                return [
                    'id' => (int)$t['id'],
                    'customer_name' => $t['customer_name'] ?? 'Cliente',
                    'order_number' => $t['order_number'],
                    'type' => $t['type'],
                    'amount' => (float)$t['amount'],
                    'description' => $t['description'],
                    'created_at' => $t['created_at']
                ];
            }, $recentTransactions)
        ]);
    }

    // ─── POST: Atualizar configuracao ───────────────────────────────────────────
    if ($method === 'POST') {
        $input = getInput();

        // Validar campos
        $cashbackPercent = isset($input['cashback_percent']) ? (float)$input['cashback_percent'] : null;
        $minOrderValue = isset($input['min_order_value']) ? (float)$input['min_order_value'] : null;
        $maxCashback = isset($input['max_cashback']) ? (float)$input['max_cashback'] : null;
        $validDays = isset($input['expiry_days']) ? (int)$input['expiry_days'] : null;
        $status = isset($input['status']) ? (int)$input['status'] : null;
        $enabled = isset($input['enabled']) ? (int)$input['enabled'] : null;

        // Usar enabled se status nao foi passado
        if ($status === null && $enabled !== null) {
            $status = $enabled;
        }

        // Validacoes
        if ($cashbackPercent !== null && ($cashbackPercent < 0 || $cashbackPercent > 20)) {
            response(false, null, "Porcentagem de cashback deve ser entre 0% e 20%", 400);
        }

        if ($minOrderValue !== null && $minOrderValue < 0) {
            response(false, null, "Valor minimo nao pode ser negativo", 400);
        }

        if ($maxCashback !== null && $maxCashback < 0) {
            response(false, null, "Cashback maximo nao pode ser negativo", 400);
        }

        if ($validDays !== null && ($validDays < 1 || $validDays > 365)) {
            response(false, null, "Validade deve ser entre 1 e 365 dias", 400);
        }

        // Verificar se ja existe config para este parceiro
        $stmt = $db->prepare("SELECT id FROM om_cashback_config WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Atualizar config existente
            $sets = [];
            $params = [];

            if ($cashbackPercent !== null) {
                $sets[] = "cashback_percent = ?";
                $params[] = $cashbackPercent;
            }
            if ($minOrderValue !== null) {
                $sets[] = "min_order_value = ?";
                $params[] = $minOrderValue;
            }
            if ($maxCashback !== null) {
                $sets[] = "max_cashback = ?";
                $params[] = $maxCashback;
            }
            if ($validDays !== null) {
                $sets[] = "expiry_days = ?";
                $params[] = $validDays;
            }
            if ($status !== null) {
                $sets[] = "status = ?";
                $params[] = $status;
            }

            if (!empty($sets)) {
                $params[] = $partnerId;
                $stmt = $db->prepare("
                    UPDATE om_cashback_config
                    SET " . implode(', ', $sets) . "
                    WHERE partner_id = ?
                ");
                $stmt->execute($params);
            }
        } else {
            // Criar nova config
            $stmt = $db->prepare("
                INSERT INTO om_cashback_config
                (partner_id, cashback_percent, min_order_value, max_cashback, expiry_days, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $partnerId,
                $cashbackPercent ?? 5.00,
                $minOrderValue ?? 0.00,
                $maxCashback ?? 50.00,
                $validDays ?? 30,
                $status ?? 1
            ]);
        }

        // Buscar config atualizada
        $stmt = $db->prepare("SELECT id, partner_id, cashback_percent, min_order_value, max_cashback, expiry_days, status FROM om_cashback_config WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $config = $stmt->fetch();

        response(true, [
            'config' => [
                'id' => (int)$config['id'],
                'enabled' => (bool)$config['status'],
                'cashback_percent' => (float)$config['cashback_percent'],
                'min_order_value' => (float)$config['min_order_value'],
                'max_cashback' => (float)$config['max_cashback'],
                'expiry_days' => (int)$config['expiry_days']
            ]
        ], "Configuracao de cashback atualizada!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/cashback-config] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
