<?php
/**
 * POST /api/mercado/admin/whatsapp-send.php
 *
 * Send WhatsApp message via Z-API from admin panel.
 *
 * Body: {
 *   phone: string,          — Phone number with country code (e.g. 5511999999999)
 *   message: string,        — Message text (required if no template)
 *   template?: string       — Template name (order_created, order_accepted, order_cancelled, etc.)
 *   template_data?: object  — Template-specific data (order_number, total, partner_name, reason, etc.)
 * }
 *
 * Supported templates:
 *   order_created   — { order_number, total, partner_name }
 *   order_accepted  — { order_number }
 *   order_cancelled — { order_number, reason? }
 *   order_ready     — { order_number }
 *   order_delivered  — { order_number }
 *   custom          — Uses message field directly
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/zapi-whatsapp.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $phone = strip_tags(trim($input['phone'] ?? ''));
    $message = trim($input['message'] ?? '');
    $template = strip_tags(trim($input['template'] ?? ''));
    $template_data = $input['template_data'] ?? [];

    if (!$phone) response(false, null, "phone obrigatorio", 400);

    // Validate phone format (digits only, 10-15 chars)
    $phone_clean = preg_replace('/\D/', '', $phone);
    if (strlen($phone_clean) < 10 || strlen($phone_clean) > 15) {
        response(false, null, "Telefone invalido. Use formato com codigo do pais (ex: 5511999999999)", 400);
    }

    // Determine message to send
    $final_message = '';
    $result = null;

    if ($template) {
        // Use template function
        switch ($template) {
            case 'order_created':
                $order_number = strip_tags(trim($template_data['order_number'] ?? ''));
                $total = (float)($template_data['total'] ?? 0);
                $partner_name = strip_tags(trim($template_data['partner_name'] ?? ''));
                if (!$order_number || !$total || !$partner_name) {
                    response(false, null, "Template order_created requer: order_number, total, partner_name", 400);
                }
                $result = whatsappOrderCreated($phone_clean, $order_number, $total, $partner_name);
                $final_message = "[template:order_created] #{$order_number}";
                break;

            case 'order_accepted':
                $order_number = strip_tags(trim($template_data['order_number'] ?? ''));
                if (!$order_number) response(false, null, "Template order_accepted requer: order_number", 400);
                $result = whatsappOrderAccepted($phone_clean, $order_number);
                $final_message = "[template:order_accepted] #{$order_number}";
                break;

            case 'order_cancelled':
                $order_number = strip_tags(trim($template_data['order_number'] ?? ''));
                $reason = strip_tags(trim($template_data['reason'] ?? ''));
                if (!$order_number) response(false, null, "Template order_cancelled requer: order_number", 400);
                $result = whatsappOrderCancelled($phone_clean, $order_number, $reason);
                $final_message = "[template:order_cancelled] #{$order_number}";
                break;

            case 'order_ready':
                $order_number = strip_tags(trim($template_data['order_number'] ?? ''));
                if (!$order_number) response(false, null, "Template order_ready requer: order_number", 400);
                $result = whatsappOrderReady($phone_clean, $order_number);
                $final_message = "[template:order_ready] #{$order_number}";
                break;

            case 'order_delivered':
                $order_number = strip_tags(trim($template_data['order_number'] ?? ''));
                if (!$order_number) response(false, null, "Template order_delivered requer: order_number", 400);
                $result = whatsappOrderDelivered($phone_clean, $order_number);
                $final_message = "[template:order_delivered] #{$order_number}";
                break;

            default:
                response(false, null, "Template desconhecido: {$template}. Use: order_created, order_accepted, order_cancelled, order_ready, order_delivered", 400);
        }
    } else {
        // Custom message
        if (!$message) response(false, null, "message obrigatorio quando nao usar template", 400);
        if (strlen($message) > 4096) response(false, null, "message excede limite de 4096 caracteres", 400);

        $result = sendWhatsApp($phone_clean, $message);
        $final_message = $message;
    }

    // Log the message sent
    try {
        $stmt = $db->prepare("
            INSERT INTO om_whatsapp_log (phone, message, template, status, message_id, admin_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        // Mask phone for log (show first 4 + last 2 digits)
        $phone_masked = substr($phone_clean, 0, 4) . str_repeat('*', max(0, strlen($phone_clean) - 6)) . substr($phone_clean, -2);
        $log_status = ($result['success'] ?? false) ? 'sent' : 'failed';
        $message_id = $result['messageId'] ?? null;
        $stmt->execute([
            $phone_masked,
            substr($final_message, 0, 500),
            $template ?: null,
            $log_status,
            $message_id,
            $admin_id,
        ]);
    } catch (Exception $e) {
        // Log table may not exist — non-critical
        error_log("[whatsapp-send] Log insert error: " . $e->getMessage());
    }

    // Audit trail
    om_audit()->log(
        'whatsapp_send',
        'communication',
        0,
        null,
        [
            'phone' => substr($phone_clean, 0, 4) . '****' . substr($phone_clean, -2),
            'template' => $template ?: 'custom',
            'success' => $result['success'] ?? false,
        ],
        "WhatsApp enviado para ****" . substr($phone_clean, -4) . ($template ? " (template: {$template})" : " (mensagem personalizada)")
    );

    if ($result['success'] ?? false) {
        response(true, [
            'message_id' => $result['messageId'] ?? null,
            'phone' => substr($phone_clean, 0, 4) . '****' . substr($phone_clean, -2),
            'template' => $template ?: null,
        ], "Mensagem WhatsApp enviada com sucesso");
    } else {
        $error_msg = $result['message'] ?? 'Erro desconhecido';
        response(false, [
            'error' => $error_msg,
            'phone' => substr($phone_clean, 0, 4) . '****' . substr($phone_clean, -2),
        ], "Falha ao enviar WhatsApp: {$error_msg}", 502);
    }

} catch (Exception $e) {
    error_log("[admin/whatsapp-send] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
