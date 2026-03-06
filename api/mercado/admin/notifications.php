<?php
/**
 * POST /api/mercado/admin/notifications.php
 *
 * Envia notificacao push para um cliente ou worker a partir do painel admin.
 *
 * Body: {
 *   client_id?: int,
 *   worker_id?: int,
 *   title?: string,
 *   message: string,
 *   type?: 'customer'|'worker'
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/notify.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $client_id = (int)($input['client_id'] ?? $input['customer_id'] ?? 0);
    $worker_id = (int)($input['worker_id'] ?? $input['shopper_id'] ?? 0);
    $title = strip_tags(trim($input['title'] ?? 'Mensagem do suporte'));
    $message = strip_tags(trim($input['message'] ?? ''));
    $type = $input['type'] ?? 'customer';

    if (!$message) response(false, null, "message obrigatoria", 400);

    // Determine target
    if ($type === 'worker' && $worker_id) {
        // Send to worker/shopper
        $sent = 0;
        try {
            $sent = notifyPartner($db, $worker_id, $title, $message, '/painel/mercado/', ['type' => 'admin_message', 'admin_id' => $admin_id]);
        } catch (\Exception $e) {
            // Try as shopper via customer push (shoppers may have customer accounts)
            try {
                $stmt = $db->prepare("SELECT customer_id FROM om_market_shoppers WHERE shopper_id = ?");
                $stmt->execute([$worker_id]);
                $row = $stmt->fetch();
                if ($row && $row['customer_id']) {
                    $sent = notifyCustomer($db, (int)$row['customer_id'], $title, $message, '/notificacoes', ['type' => 'admin_message']);
                }
            } catch (\Exception $e2) {
                error_log("[admin/notifications] Worker notify fallback error: " . $e2->getMessage());
            }
        }

        om_audit()->log('send_notification', 'worker', $worker_id, null, ['title' => $title, 'message' => substr($message, 0, 200)], "Notificacao enviada para worker #{$worker_id}");

        response(true, ['worker_id' => $worker_id, 'sent' => $sent], "Notificacao enviada");

    } elseif ($client_id) {
        // Send to customer
        $sent = 0;
        try {
            $sent = notifyCustomer($db, $client_id, $title, $message, '/notificacoes', ['type' => 'admin_message', 'admin_id' => $admin_id]);
        } catch (\Exception $e) {
            error_log("[admin/notifications] Customer notify error: " . $e->getMessage());
        }

        $push_status = $sent > 0 ? 'delivered' : 'no_token';

        om_audit()->log('send_notification', 'customer', $client_id, null, ['title' => $title, 'message' => substr($message, 0, 200), 'push_status' => $push_status], "Notificacao enviada para cliente #{$client_id}");

        response(true, ['client_id' => $client_id, 'sent' => $sent, 'push_status' => $push_status], $sent > 0 ? "Push enviado ({$sent} dispositivo(s))" : "Notificacao salva (sem push token)");

    } else {
        response(false, null, "client_id ou worker_id obrigatorio", 400);
    }

} catch (Exception $e) {
    error_log("[admin/notifications] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
