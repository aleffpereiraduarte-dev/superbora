<?php
/**
 * GET/POST /api/mercado/partner/notification-settings.php
 * GET  - Retorna preferencias de notificacao do parceiro
 * POST - Salva preferencias { settings: { new_order: true, ... } }
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

    // Garantir coluna existe
    try {
        $db->exec("ALTER TABLE om_market_partners ADD COLUMN notification_settings TEXT DEFAULT NULL");
    } catch (Exception $e) {
        // Column may already exist
    }

    $defaults = [
        'new_order' => true,
        'order_cancelled' => true,
        'new_review' => true,
        'low_stock' => true,
        'daily_summary' => true,
        'promotions' => true,
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare("SELECT notification_settings FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $row = $stmt->fetch();

        $settings = $defaults;
        if ($row && !empty($row['notification_settings'])) {
            $stored = json_decode($row['notification_settings'], true);
            if (is_array($stored)) {
                $settings = array_merge($defaults, $stored);
            }
        }

        response(true, ['settings' => $settings]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $newSettings = $input['settings'] ?? [];

        if (!is_array($newSettings) || empty($newSettings)) {
            response(false, null, "settings obrigatorio", 400);
        }

        // Filtrar apenas keys validas
        $validKeys = array_keys($defaults);
        $filtered = [];
        foreach ($validKeys as $key) {
            if (array_key_exists($key, $newSettings)) {
                $filtered[$key] = (bool)$newSettings[$key];
            }
        }

        // Carregar settings atuais e mesclar
        $stmt = $db->prepare("SELECT notification_settings FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $row = $stmt->fetch();

        $current = $defaults;
        if ($row && !empty($row['notification_settings'])) {
            $stored = json_decode($row['notification_settings'], true);
            if (is_array($stored)) {
                $current = array_merge($defaults, $stored);
            }
        }

        $merged = array_merge($current, $filtered);

        $stmt = $db->prepare("UPDATE om_market_partners SET notification_settings = ? WHERE partner_id = ?");
        $stmt->execute([json_encode($merged), $partnerId]);

        response(true, ['settings' => $merged], "Preferencias salvas com sucesso!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/notification-settings] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar preferencias", 500);
}
