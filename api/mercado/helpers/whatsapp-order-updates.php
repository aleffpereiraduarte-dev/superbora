<?php
/**
 * Proactive WhatsApp Order Status Updates
 *
 * Sends WhatsApp notifications to customers when their order status changes.
 * Called from pedido/*.php lifecycle endpoints after successful status transitions.
 *
 * This helper:
 * 1. Looks up the order and customer phone
 * 2. Checks if customer has opted out of proactive messages
 * 3. Sends the appropriate WhatsApp message via Z-API
 * 4. Logs to om_callcenter_wa_messages if a conversation exists
 *
 * When $skipSend is true (default for pedido files that already send their own
 * WhatsApp notifications), only the opt-out check and conversation logging are
 * performed — the message text is logged to the conversation but NOT re-sent
 * via Z-API to avoid duplicate messages.
 *
 * IMPORTANT: All calls are wrapped in try/catch. A WhatsApp failure
 * must NEVER block or break the order lifecycle flow.
 */

require_once __DIR__ . '/zapi-whatsapp.php';

/**
 * Send a proactive WhatsApp message when order status changes.
 *
 * @param PDO    $db        Database connection
 * @param int    $orderId   The order ID
 * @param string $newStatus The new status (aceito, preparando, pronto, em_entrega, entregue)
 * @param bool   $skipSend  If true, skip sending (just log to conversation). Default false.
 * @return void
 */
function sendOrderStatusWhatsApp(PDO $db, int $orderId, string $newStatus, bool $skipSend = false): void
{
    try {
        // 1. Query order details
        $stmt = $db->prepare("
            SELECT o.order_id, o.order_number, o.customer_id, o.customer_phone,
                   o.partner_name, o.total, o.distancia_km, o.source,
                   p.name as partner_db_name, p.trade_name
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            error_log("[wa-order-updates] Order #{$orderId} not found");
            return;
        }

        $customerPhone = $order['customer_phone'] ?? '';
        if (empty($customerPhone)) {
            error_log("[wa-order-updates] No phone for order #{$orderId}");
            return;
        }

        $customerId = (int)($order['customer_id'] ?? 0);
        $orderNumber = $order['order_number'] ?? "#{$orderId}";
        $partnerName = $order['partner_name'] ?? $order['trade_name'] ?? $order['partner_db_name'] ?? '';

        // 2. Check opt-out (respect customer preference)
        if ($customerId > 0) {
            try {
                $optOutStmt = $db->prepare("SELECT 1 FROM om_whatsapp_proactive_optout WHERE customer_id = ? LIMIT 1");
                $optOutStmt->execute([$customerId]);
                if ($optOutStmt->fetch()) {
                    error_log("[wa-order-updates] Customer #{$customerId} opted out — skipping WhatsApp for order #{$orderId}");
                    return;
                }
            } catch (\Throwable $e) {
                // Table might not exist yet — proceed anyway
                error_log("[wa-order-updates] Opt-out check error (proceeding): " . $e->getMessage());
            }
        }

        // 3. Build message based on status
        $message = buildOrderStatusMessage($orderNumber, $partnerName, $newStatus);
        if (empty($message)) {
            error_log("[wa-order-updates] No message template for status '{$newStatus}'");
            return;
        }

        // 4. Send message (unless caller already sent one via existing functions)
        $success = true;
        if (!$skipSend) {
            $result = sendWhatsAppWithRetry($customerPhone, $message);
            $success = !empty($result['success']);

            error_log("[wa-order-updates] Order #{$orderNumber} status={$newStatus} phone=****"
                . substr($customerPhone, -4) . " success=" . ($success ? 'yes' : 'no'));
        }

        // 4b. SMS fallback for phone-originated orders (callcenter_ai / callcenter)
        $orderSource = $order['source'] ?? '';
        if (in_array($orderSource, ['callcenter_ai', 'callcenter', 'phone'], true)) {
            try {
                $smsHelper = __DIR__ . '/twilio-sms.php';
                if (file_exists($smsHelper)) {
                    require_once $smsHelper;
                    // Strip emojis/markdown for SMS (plain text only)
                    $smsMessage = preg_replace('/\*([^*]+)\*/', '$1', $message); // remove markdown bold
                    $smsMessage = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $smsMessage); // strip emojis
                    $smsMessage = trim($smsMessage);
                    if (!empty($smsMessage)) {
                        $smsResult = sendSMS($customerPhone, $smsMessage);
                        error_log("[wa-order-updates] SMS fallback for {$orderSource} order #{$orderNumber}: " . ($smsResult['success'] ? 'sent' : 'failed'));
                    }
                }
            } catch (\Throwable $smsErr) {
                error_log("[wa-order-updates] SMS fallback error: " . $smsErr->getMessage());
            }
        }

        // 5. Log to conversation if one exists
        if ($success) {
            try {
                $normalizedPhone = preg_replace('/\D/', '', $customerPhone);
                $convStmt = $db->prepare("
                    SELECT id FROM om_callcenter_whatsapp
                    WHERE phone = ?
                    ORDER BY last_message_at DESC
                    LIMIT 1
                ");
                $convStmt->execute([$normalizedPhone]);
                $conv = $convStmt->fetch(PDO::FETCH_ASSOC);

                if ($conv) {
                    $db->prepare("
                        INSERT INTO om_callcenter_wa_messages
                            (conversation_id, direction, sender_type, message, created_at)
                        VALUES (?, 'outbound', 'system', ?, NOW())
                    ")->execute([(int)$conv['id'], $message]);
                }
            } catch (\Throwable $logErr) {
                // Logging failure is non-critical
                error_log("[wa-order-updates] Message log error: " . $logErr->getMessage());
            }
        }

    } catch (\Throwable $e) {
        // NEVER let WhatsApp errors break order flow
        error_log("[wa-order-updates] Error for order #{$orderId} status={$newStatus}: " . $e->getMessage());
    }
}

/**
 * Build the WhatsApp message for a given order status.
 *
 * @param string $orderNumber
 * @param string $partnerName
 * @param string $status
 * @return string The message text, or empty string if status not handled
 */
function buildOrderStatusMessage(string $orderNumber, string $partnerName, string $status): string
{
    $storeRef = $partnerName ? " da *{$partnerName}*" : '';

    switch ($status) {
        case 'aceito':
            return "Boa noticia! A{$storeRef} aceitou seu pedido *#{$orderNumber}*! "
                . "Ja ja comecam a preparar. Fique de olho aqui que te aviso cada etapa!";

        case 'preparando':
            return "Seu pedido *#{$orderNumber}* ja esta sendo preparado{$storeRef}! "
                . "\xF0\x9F\x91\xA8\xE2\x80\x8D\xF0\x9F\x8D\xB3 Te aviso quando ficar pronto!";

        case 'pronto':
            return "Pedido *#{$orderNumber}* pronto! Ja ja sai pra entrega. "
                . "\xF0\x9F\x93\xA6 Prepare-se para receber!";

        case 'em_entrega':
            return "Seu pedido *#{$orderNumber}* saiu pra entrega! Chega em breve! "
                . "\xF0\x9F\x8F\x8D\xEF\xB8\x8F";

        case 'entregue':
        case 'retirado':
            return "Pedido *#{$orderNumber}* entregue! Bom apetite! "
                . "\xF0\x9F\x98\x8B Como foi a experiencia? Me avalie de 1 a 5!";

        default:
            return '';
    }
}
