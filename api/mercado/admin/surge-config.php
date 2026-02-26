<?php
/**
 * GET/PUT /api/mercado/admin/surge-config.php
 * Manage surge pricing configuration
 *
 * GET: Returns current surge config
 * PUT: Update surge config (enable/disable, thresholds, max multiplier)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload["uid"];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        // Load all surge-related configs
        $stmt = $db->query("SELECT chave, valor FROM om_config WHERE chave LIKE 'surge_%'");
        $rows = $stmt->fetchAll();

        $config = [
            'enabled' => true,
            'thresholds' => [
                ['ratio' => 2, 'multiplier' => 1.5],
                ['ratio' => 4, 'multiplier' => 2.0],
                ['ratio' => 6, 'multiplier' => 2.5],
            ],
            'max_multiplier' => 3.0,
        ];

        foreach ($rows as $row) {
            switch ($row['chave']) {
                case 'surge_enabled':
                    $config['enabled'] = (bool)(int)$row['valor'];
                    break;
                case 'surge_thresholds':
                    $decoded = json_decode($row['valor'], true);
                    if (is_array($decoded)) $config['thresholds'] = $decoded;
                    break;
                case 'surge_max_multiplier':
                    $config['max_multiplier'] = (float)$row['valor'];
                    break;
            }
        }

        // Also get current surge status
        $sql = "SELECT
            (SELECT COUNT(*) FROM om_market_orders WHERE status IN ('pending','pendente','aceito','preparando','pronto') AND DATE(date_added) = CURRENT_DATE) as active_orders,
            (SELECT COUNT(*) FROM om_market_shoppers WHERE disponivel = 1 AND online = 1) as available_shoppers";
        $stmt = $db->query($sql);
        $load = $stmt->fetch();

        $config['current_load'] = [
            'active_orders' => (int)$load['active_orders'],
            'available_shoppers' => (int)$load['available_shoppers'],
            'ratio' => (int)$load['available_shoppers'] > 0
                ? round((int)$load['active_orders'] / (int)$load['available_shoppers'], 1)
                : 0,
        ];

        response(true, $config, "Configuracao de surge carregada");

    } elseif ($_SERVER["REQUEST_METHOD"] === "PUT") {
        $input = getInput();

        $updates = [];

        if (isset($input['enabled'])) {
            $val = $input['enabled'] ? '1' : '0';
            upsertConfig($db, 'surge_enabled', $val, $admin_id);
            $updates[] = 'enabled=' . $val;
        }

        if (isset($input['thresholds']) && is_array($input['thresholds'])) {
            // Validate thresholds
            $valid = [];
            foreach ($input['thresholds'] as $t) {
                if (isset($t['ratio']) && isset($t['multiplier'])) {
                    $ratio = (float)$t['ratio'];
                    $mult = (float)$t['multiplier'];
                    if ($ratio > 0 && $mult >= 1.0 && $mult <= 5.0) {
                        $valid[] = ['ratio' => $ratio, 'multiplier' => $mult];
                    }
                }
            }
            if (!empty($valid)) {
                upsertConfig($db, 'surge_thresholds', json_encode($valid), $admin_id);
                $updates[] = 'thresholds updated';
            }
        }

        if (isset($input['max_multiplier'])) {
            $max = max(1.0, min(5.0, (float)$input['max_multiplier']));
            upsertConfig($db, 'surge_max_multiplier', (string)$max, $admin_id);
            $updates[] = 'max=' . $max;
        }

        if (empty($updates)) {
            response(false, null, "Nenhum campo para atualizar", 400);
        }

        om_audit()->log('update', 'config', null, null, $input, "Surge config atualizada: " . implode(', ', $updates));

        response(true, ['updated' => $updates], "Configuracao atualizada");

    } else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[admin/surge-config] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

function upsertConfig($db, $key, $value, $admin_id) {
    $stmt = $db->prepare("SELECT id FROM om_config WHERE chave = ?");
    $stmt->execute([$key]);
    if ($stmt->fetch()) {
        $stmt = $db->prepare("UPDATE om_config SET valor = ?, updated_by = ?, updated_at = NOW() WHERE chave = ?");
        $stmt->execute([$value, $admin_id, $key]);
    } else {
        $stmt = $db->prepare("INSERT INTO om_config (chave, valor, updated_by, updated_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$key, $value, $admin_id]);
    }
}
