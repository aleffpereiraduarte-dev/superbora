<?php
/**
 * GET/POST /api/mercado/vitrine/notification-prefs.php
 * Gerencia preferências de notificação do cliente
 *
 * GET:  ?customer_id=X -> retorna preferências
 * POST: { customer_id, push_orders, push_promos, push_chat, email_orders, email_promos, sms_orders }
 */
require_once __DIR__ . "/../config/database.php";

try {
    $db = getDB();
    $customer_id = requireCustomerAuth();

    // Table om_notification_prefs created via migration

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT * FROM om_notification_prefs WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $prefs = $stmt->fetch();

        if (!$prefs) {
            // Retorna defaults
            $prefs = [
                'customer_id' => $customer_id,
                'push_orders' => 1,
                'push_promos' => 1,
                'push_chat' => 1,
                'email_orders' => 1,
                'email_promos' => 0,
                'sms_orders' => 0,
            ];
        }

        // Cast para int
        foreach (['push_orders', 'push_promos', 'push_chat', 'email_orders', 'email_promos', 'sms_orders'] as $key) {
            $prefs[$key] = (int)$prefs[$key];
        }

        response(true, $prefs);

    } elseif ($method === 'POST') {
        $input = getInput();

        $fields = ['push_orders', 'push_promos', 'push_chat', 'email_orders', 'email_promos', 'sms_orders'];

        // For updates, only change fields explicitly sent (preserve existing prefs)
        // For new records, default to enabled
        $existingStmt = $db->prepare("SELECT * FROM om_notification_prefs WHERE customer_id = ?");
        $existingStmt->execute([$customer_id]);
        $existing = $existingStmt->fetch();

        $values = ['customer_id' => $customer_id];
        foreach ($fields as $f) {
            if (isset($input[$f])) {
                $values[$f] = (int)$input[$f] ? 1 : 0;
            } elseif ($existing) {
                $values[$f] = (int)($existing[$f] ?? 1);
            } else {
                $values[$f] = 1; // Default enabled for new records
            }
        }

        $stmt = $db->prepare("
            INSERT INTO om_notification_prefs (customer_id, push_orders, push_promos, push_chat, email_orders, email_promos, sms_orders)
            VALUES (:customer_id, :push_orders, :push_promos, :push_chat, :email_orders, :email_promos, :sms_orders)
            ON CONFLICT (customer_id) DO UPDATE SET
                push_orders = EXCLUDED.push_orders,
                push_promos = EXCLUDED.push_promos,
                push_chat = EXCLUDED.push_chat,
                email_orders = EXCLUDED.email_orders,
                email_promos = EXCLUDED.email_promos,
                sms_orders = EXCLUDED.sms_orders
        ");
        $stmt->execute($values);

        response(true, $values, "Preferências salvas");

    } else {
        response(false, null, "Método não permitido", 405);
    }

} catch (Exception $e) {
    error_log("[vitrine/notification-prefs] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
