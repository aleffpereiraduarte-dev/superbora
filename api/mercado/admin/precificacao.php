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

    $pricing_keys = [
        "delivery_fee_base", "delivery_fee_per_km", "delivery_fee_min", "delivery_fee_max",
        "commission_rate", "commission_min", "platform_fee", "surge_multiplier", "free_delivery_threshold"
    ];

    $defaults = [
        "delivery_fee_base" => "5.00", "delivery_fee_per_km" => "1.50",
        "delivery_fee_min" => "5.00", "delivery_fee_max" => "25.00",
        "commission_rate" => "15", "commission_min" => "2.00",
        "platform_fee" => "1.00", "surge_multiplier" => "1.0",
        "free_delivery_threshold" => "80.00"
    ];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $placeholders = implode(",", array_fill(0, count($pricing_keys), "?"));
        $stmt = $db->prepare("SELECT chave AS config_key, valor AS config_value, updated_at FROM om_config WHERE chave IN ({$placeholders})");
        $stmt->execute($pricing_keys);
        $rows = $stmt->fetchAll();

        $pricing = [];
        foreach ($rows as $row) {
            $pricing[$row["config_key"]] = ["value" => $row["config_value"], "updated_at" => $row["updated_at"]];
        }
        foreach ($defaults as $key => $default) {
            if (!isset($pricing[$key])) {
                $pricing[$key] = ["value" => $default, "updated_at" => null];
            }
        }
        response(true, ["pricing" => $pricing], "Regras de precificacao");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        // SECURITY: Only manager/rh can update platform pricing
        $admin_role = $payload['data']['role'] ?? $payload['type'] ?? '';
        if (!in_array($admin_role, ['manager', 'rh', 'superadmin'])) {
            http_response_code(403);
            response(false, null, "Apenas manager ou RH podem alterar precificacao", 403);
        }

        $input = getInput();
        $updates = $input["pricing"] ?? $input;
        if (empty($updates) || !is_array($updates)) {
            response(false, null, "Dados de precificacao obrigatorios", 400);
        }
        $db->beginTransaction();
        $updated = [];
        foreach ($updates as $key => $value) {
            if (!in_array($key, $pricing_keys)) continue;
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
            $updated[$key] = $value;
        }
        $db->commit();
        response(true, ["updated" => $updated], "Precificacao atualizada");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/precificacao] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
