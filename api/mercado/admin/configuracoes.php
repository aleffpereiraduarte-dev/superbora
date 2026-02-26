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
    $admin_id = $payload["uid"];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $stmt = $db->query("SELECT id, chave AS config_key, valor AS config_value, updated_by, updated_at FROM om_config ORDER BY chave ASC");
        $configs = $stmt->fetchAll();

        // Filter sensitive keys from response
        $sensitiveKeys = ['claude_api_key', 'stripe_secret', 'stripe_secret_key', 'db_password', 'db_pass', 'vapid_private_key', 'webhook_secret', 'zapi_token', 'jwt_secret'];
        $configs = array_map(function($c) use ($sensitiveKeys) {
            if (in_array($c['config_key'], $sensitiveKeys, true)) {
                $c['config_value'] = '********';
            }
            return $c;
        }, $configs);

        response(true, ["configs" => $configs], "Configuracoes carregadas");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        // Only manager or rh roles can modify configurations
        $adminRole = $payload['role'] ?? '';
        $adminType = $payload['type'] ?? '';
        if ($adminType !== 'rh' && !in_array($adminRole, ['manager', 'rh'], true)) {
            response(false, null, "Apenas gerentes e RH podem alterar configuracoes", 403);
        }

        $input = getInput();
        $key = trim($input["key"] ?? "");
        $value = $input["value"] ?? "";
        if (!$key) response(false, null, "key obrigatoria", 400);

        // Whitelist of allowed config keys
        $allowedKeys = [
            'store_name', 'store_description', 'store_phone', 'store_email', 'store_address',
            'min_order_value', 'delivery_fee', 'free_delivery_threshold',
            'delivery_fee_base', 'delivery_fee_per_km', 'delivery_fee_min', 'delivery_fee_max',
            'business_hours', 'maintenance_mode',
            'pix_enabled', 'stripe_enabled',
            'cashback_percent', 'cashback_enabled',
            'commission_rate', 'commission_min', 'platform_fee',
            'surge_enabled', 'surge_thresholds', 'surge_max_multiplier', 'surge_multiplier',
        ];
        if (!in_array($key, $allowedKeys, true)) {
            response(false, null, "Chave de configuracao invalida: {$key}", 400);
        }

        $stmt = $db->prepare("SELECT id, valor FROM om_config WHERE chave = ?");
        $stmt->execute([$key]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $db->prepare("UPDATE om_config SET valor = ?, updated_by = ?, updated_at = NOW() WHERE chave = ?");
            $stmt->execute([$value, $admin_id, $key]);
        } else {
            $stmt = $db->prepare("INSERT INTO om_config (chave, valor, updated_by, updated_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$key, $value, $admin_id]);
        }
        response(true, ["key" => $key, "value" => $value], "Configuracao salva");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/configuracoes] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
