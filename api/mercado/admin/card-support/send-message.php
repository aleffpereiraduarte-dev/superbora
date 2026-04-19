<?php
/**
 * POST /admin/card-support/send-message.php
 *
 * Send a message to a customer (push + WhatsApp optional).
 *
 * Body:
 *   {
 *     customer_id,
 *     title,
 *     message,
 *     channels?: ['push','whatsapp']   // default both
 *     card_id?,
 *     url?   // deep link - default /cartao
 *   }
 */

require_once __DIR__ . "/_common.php";
require_once __DIR__ . "/../../helpers/notify.php";
require_once __DIR__ . "/../../helpers/zapi-whatsapp.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db  = $ctx['db'];
    $adminId = $ctx['admin_id'];

    $input = getInput();
    $customerId = (int)($input['customer_id'] ?? 0);
    $cardId     = !empty($input['card_id']) ? (int)$input['card_id'] : null;
    $title      = trim((string)($input['title']   ?? 'SuperBora'));
    $message    = trim((string)($input['message'] ?? ''));
    $url        = (string)($input['url'] ?? '/cartao');
    $channels   = isset($input['channels']) && is_array($input['channels'])
        ? $input['channels']
        : ['push', 'whatsapp'];

    if ($customerId <= 0) response(false, null, 'customer_id obrigatorio', 400);
    if ($message === '')  response(false, null, 'message obrigatorio',    400);
    if (strlen($title) > 120)   $title = substr($title, 0, 120);
    if (strlen($message) > 2000) $message = substr($message, 0, 2000);

    // Look up phone
    $stmt = $db->prepare("SELECT name, phone, email FROM om_customers WHERE customer_id = ? LIMIT 1");
    $stmt->execute([$customerId]);
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cust) response(false, null, 'Cliente nao encontrado', 404);

    $results = [
        'push'     => null,
        'whatsapp' => null,
    ];

    if (in_array('push', $channels, true)) {
        try {
            $n = notifyCustomer($db, $customerId, $title, $message, $url, [
                'type'    => 'card_support_message',
                'card_id' => $cardId,
            ]);
            $results['push'] = ['sent' => true, 'devices' => $n];
        } catch (Exception $e) {
            $results['push'] = ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    if (in_array('whatsapp', $channels, true) && !empty($cust['phone'])) {
        try {
            $waMsg = "*{$title}*\n\n{$message}\n\nEquipe SuperBora";
            $res = sendWhatsApp($cust['phone'], $waMsg);
            $results['whatsapp'] = ['sent' => !empty($res['success']), 'details' => $res];
        } catch (Exception $e) {
            $results['whatsapp'] = ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    // Audit: add a support note, log event when card_id present
    try {
        $noteStmt = $db->prepare("
            INSERT INTO om_card_support_notes (customer_id, card_id, agent_id, agent_name, note, visibility)
            VALUES (?, ?, ?, ?, ?, 'internal')
        ");
        $noteStmt->execute([
            $customerId,
            $cardId,
            $adminId,
            $ctx['admin_name'],
            sprintf("[Mensagem enviada - %s] %s: %s", implode(', ', $channels), $title, $message),
        ]);
    } catch (Exception $e) { /* ignore */ }

    if ($cardId) {
        logCardSupportEvent($db, $cardId, $customerId, 'support_message_sent', $adminId, [
            'channels' => $channels,
            'title'    => $title,
        ], substr($message, 0, 240));
    }

    response(true, [
        'customer_id' => $customerId,
        'channels'    => $channels,
        'results'     => $results,
    ]);
} catch (Exception $e) {
    error_log('[card-support-send-message] ' . $e->getMessage());
    response(false, null, 'Erro ao enviar mensagem', 500);
}
