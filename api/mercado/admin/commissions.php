<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $partner_id = isset($_GET['partner_id']) ? (int)$_GET['partner_id'] : null;
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Get default platform commission from config
        $stmt = $db->prepare("SELECT value FROM om_market_config WHERE config_key = 'default_commission_rate' LIMIT 1");
        $stmt->execute();
        $default_config = $stmt->fetch();
        $default_commission = $default_config ? (float)$default_config['value'] : 10.00;

        $where = ["p.status != 'excluido'"];
        $params = [];

        if ($partner_id) {
            $where[] = "p.partner_id = ?";
            $params[] = $partner_id;
        }

        if ($search) {
            $where[] = "(p.name LIKE ? OR p.nome LIKE ? OR p.email LIKE ?)";
            $search_param = "%{$search}%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }

        $where_sql = implode(' AND ', $where);

        // Count
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_partners p WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        // Get partners with their commission data
        $stmt = $db->prepare("
            SELECT
                p.partner_id,
                COALESCE(p.name, p.nome) as partner_name,
                p.email,
                p.categoria,
                p.status,
                p.commission_rate,
                p.commission_type,
                p.partnership_type,
                p.total_orders,
                p.total_vendas,
                pc.commission_value as custom_commission,
                pc.commission_type as custom_commission_type,
                pc.is_active as custom_active
            FROM om_market_partners p
            LEFT JOIN om_market_partner_commissions pc ON p.partner_id = pc.partner_id AND pc.is_active = '1'
            WHERE {$where_sql}
            ORDER BY p.name ASC
            LIMIT ? OFFSET ?
        ");
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        $stmt->execute($params);
        $partners = $stmt->fetchAll();

        // Calculate effective commission for each partner
        foreach ($partners as &$partner) {
            if ($partner['custom_commission'] !== null && $partner['custom_active']) {
                $partner['effective_rate'] = (float)$partner['custom_commission'];
                $partner['rate_source'] = 'custom';
            } elseif ($partner['commission_rate'] > 0) {
                $partner['effective_rate'] = (float)$partner['commission_rate'];
                $partner['rate_source'] = 'partner';
            } else {
                $partner['effective_rate'] = $default_commission;
                $partner['rate_source'] = 'default';
            }
        }

        // Get commission rules by category
        $stmt2 = $db->query("
            SELECT cr.*, c.name as category_name
            FROM om_market_commission_rules cr
            LEFT JOIN om_market_categories c ON cr.category_id = c.category_id
            WHERE cr.partner_id IS NULL AND cr.is_active = '1'
            ORDER BY c.name ASC
        ");
        $category_rules = $stmt2->fetchAll();

        response(true, [
            'partners' => $partners,
            'default_commission' => $default_commission,
            'category_rules' => $category_rules,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ], "Configuracao de comissoes");

    } elseif ($_SERVER["REQUEST_METHOD"] === "PUT") {
        // SECURITY: Only superadmin can change commission rates
        if ($payload['type'] !== 'superadmin') {
            $callerRole = $payload['data']['role'] ?? '';
            if ($callerRole !== 'admin') {
                response(false, null, "Apenas superadmin ou admin podem alterar comissoes", 403);
            }
        }

        $input = getInput();
        $action = $input['action'] ?? 'update_partner';

        if ($action === 'update_default') {
            // Update default platform commission rate
            $rate = (float)($input['rate'] ?? 0);
            if ($rate < 0 || $rate > 100) {
                response(false, null, "Taxa deve ser entre 0 e 100", 400);
            }

            $stmt = $db->prepare("SELECT config_id FROM om_market_config WHERE config_key = 'default_commission_rate' LIMIT 1");
            $stmt->execute();
            $existing = $stmt->fetch();

            if ($existing) {
                $stmt = $db->prepare("UPDATE om_market_config SET value = ?, updated_at = NOW() WHERE config_key = 'default_commission_rate'");
                $stmt->execute([(string)$rate]);
            } else {
                $stmt = $db->prepare("INSERT INTO om_market_config (config_key, value, updated_at) VALUES ('default_commission_rate', ?, NOW())");
                $stmt->execute([(string)$rate]);
            }

            om_audit()->log('update', 'commission_config', 0, null, ['default_rate' => $rate]);
            response(true, ['rate' => $rate], "Taxa padrao atualizada para {$rate}%");

        } elseif ($action === 'update_partner') {
            // Update commission for a specific partner
            $partner_id = (int)($input['partner_id'] ?? 0);
            $rate = (float)($input['rate'] ?? 0);
            $commission_type = $input['commission_type'] ?? 'percentage';

            if (!$partner_id) {
                response(false, null, "partner_id e obrigatorio", 400);
            }
            if ($rate < 0 || $rate > 100) {
                response(false, null, "Taxa deve ser entre 0 e 100", 400);
            }

            // Check partner exists
            $stmt = $db->prepare("SELECT partner_id, name FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$partner_id]);
            $partner = $stmt->fetch();
            if (!$partner) {
                response(false, null, "Parceiro nao encontrado", 404);
            }

            // Update commission_rate on the partner itself
            $stmt = $db->prepare("UPDATE om_market_partners SET commission_rate = ?, commission_type = ? WHERE partner_id = ?");
            $stmt->execute([$rate, $commission_type, $partner_id]);

            // Also upsert into partner_commissions table
            $stmt = $db->prepare("SELECT id FROM om_market_partner_commissions WHERE partner_id = ? LIMIT 1");
            $stmt->execute([$partner_id]);
            $existing = $stmt->fetch();

            if ($existing) {
                $stmt = $db->prepare("UPDATE om_market_partner_commissions SET commission_value = ?, commission_type = ?, is_active = '1' WHERE partner_id = ?");
                $stmt->execute([$rate, $commission_type, $partner_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO om_market_partner_commissions (partner_id, commission_type, commission_value, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
                $stmt->execute([$partner_id, $commission_type, $rate]);
            }

            om_audit()->log('update', 'partner_commission', $partner_id, null, [
                'rate' => $rate,
                'type' => $commission_type,
                'partner_name' => $partner['name']
            ]);
            response(true, ['partner_id' => $partner_id, 'rate' => $rate], "Comissao do parceiro atualizada");

        } elseif ($action === 'reset_partner') {
            // Reset partner to use default commission
            $partner_id = (int)($input['partner_id'] ?? 0);
            if (!$partner_id) {
                response(false, null, "partner_id e obrigatorio", 400);
            }

            $stmt = $db->prepare("UPDATE om_market_partners SET commission_rate = 0.00 WHERE partner_id = ?");
            $stmt->execute([$partner_id]);

            $stmt = $db->prepare("UPDATE om_market_partner_commissions SET is_active = 0 WHERE partner_id = ?");
            $stmt->execute([$partner_id]);

            om_audit()->log('update', 'partner_commission', $partner_id, null, ['action' => 'reset_to_default']);
            response(true, ['partner_id' => $partner_id], "Parceiro resetado para taxa padrao");

        } elseif ($action === 'update_category_rule') {
            // Update category-level commission rule
            $category_id = (int)($input['category_id'] ?? 0);
            $rate = (float)($input['rate'] ?? 0);

            if (!$category_id) {
                response(false, null, "category_id e obrigatorio", 400);
            }
            if ($rate < 0 || $rate > 100) {
                response(false, null, "Taxa deve ser entre 0 e 100", 400);
            }

            $stmt = $db->prepare("SELECT id FROM om_market_commission_rules WHERE category_id = ? AND partner_id IS NULL LIMIT 1");
            $stmt->execute([$category_id]);
            $existing = $stmt->fetch();

            if ($existing) {
                $stmt = $db->prepare("UPDATE om_market_commission_rules SET commission_value = ?, is_active = '1' WHERE id = ?");
                $stmt->execute([$rate, $existing['id']]);
            } else {
                $stmt = $db->prepare("INSERT INTO om_market_commission_rules (partner_id, category_id, commission_type, commission_value, is_active, created_at) VALUES (NULL, ?, 'percentage', ?, 1, NOW())");
                $stmt->execute([$category_id, $rate]);
            }

            om_audit()->log('update', 'commission_rule', $category_id, null, ['category_rate' => $rate]);
            response(true, ['category_id' => $category_id, 'rate' => $rate], "Regra de comissao por categoria atualizada");

        } else {
            response(false, null, "Acao invalida", 400);
        }

    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/commissions] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
