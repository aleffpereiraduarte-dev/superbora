<?php
/**
 * WebSocket Notifier Helper Class
 * Easy integration for sending notifications from anywhere in the application
 *
 * Usage:
 *   require_once '/var/www/html/websocket/WebSocketNotifier.php';
 *
 *   // Notify partner of new order
 *   WebSocketNotifier::newOrder($partnerId, $orderData);
 *
 *   // Send custom notification
 *   WebSocketNotifier::notify($partnerId, 'Custom Title', 'Message here');
 */

class WebSocketNotifier
{
    private static $apiUrl = 'http://127.0.0.1/api/mercado/partner/ws-broadcast.php';
    private static $apiSecret = 'superbora_ws_secret_2024';

    /**
     * Send notification via API
     */
    private static function send(array $data): array
    {
        $ch = curl_init(self::$apiUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . self::$apiSecret
            ],
            CURLOPT_TIMEOUT => 5
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        $result = json_decode($response, true) ?: [];
        $result['http_code'] = $httpCode;

        return $result;
    }

    /**
     * Notify partner of new order
     *
     * @param int $partnerId
     * @param array $orderData Order details (id, numero_pedido, total, etc.)
     */
    public static function newOrder(int $partnerId, array $orderData): array
    {
        return self::send([
            'partner_id' => $partnerId,
            'type' => 'new_order',
            'data' => [
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['numero_pedido'] ?? null,
                'customer_name' => $orderData['cliente_nome'] ?? 'Cliente',
                'total' => $orderData['total'] ?? 0,
                'items_count' => $orderData['items_count'] ?? count($orderData['items'] ?? []),
                'delivery_type' => $orderData['tipo_entrega'] ?? 'delivery',
                'payment_method' => $orderData['forma_pagamento'] ?? null,
                'address' => $orderData['endereco'] ?? null,
                'sound' => 'new_order',
                'priority' => 'high'
            ]
        ]);
    }

    /**
     * Notify partner of order status update
     *
     * @param int $partnerId
     * @param array $orderData Order details with status information
     */
    public static function orderUpdate(int $partnerId, array $orderData): array
    {
        return self::send([
            'partner_id' => $partnerId,
            'type' => 'order_update',
            'data' => [
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['numero_pedido'] ?? null,
                'status' => $orderData['status'] ?? null,
                'status_label' => $orderData['status_label'] ?? null,
                'previous_status' => $orderData['previous_status'] ?? null,
                'updated_by' => $orderData['updated_by'] ?? 'system',
                'updated_at' => $orderData['updated_at'] ?? date('Y-m-d H:i:s')
            ]
        ]);
    }

    /**
     * Notify partner of new chat message
     *
     * @param int $partnerId
     * @param array $messageData Message details
     */
    public static function chatMessage(int $partnerId, array $messageData): array
    {
        return self::send([
            'partner_id' => $partnerId,
            'type' => 'chat_message',
            'data' => [
                'order_id' => $messageData['order_id'] ?? null,
                'message_id' => $messageData['id'] ?? null,
                'from' => $messageData['from'] ?? 'customer',
                'from_name' => $messageData['from_name'] ?? 'Cliente',
                'message' => $messageData['message'] ?? '',
                'timestamp' => $messageData['timestamp'] ?? time(),
                'sound' => 'chat'
            ]
        ]);
    }

    /**
     * Send system notification to partner
     *
     * @param int $partnerId
     * @param string $title Notification title
     * @param string $message Notification message
     * @param string $level Level: info, success, warning, error
     */
    public static function notify(
        int $partnerId,
        string $title,
        string $message,
        string $level = 'info'
    ): array {
        return self::send([
            'partner_id' => $partnerId,
            'type' => 'notification',
            'data' => [
                'title' => $title,
                'message' => $message,
                'level' => $level,
                'dismissible' => true,
                'timestamp' => time()
            ]
        ]);
    }

    /**
     * Send alert notification (high priority)
     *
     * @param int $partnerId
     * @param string $title Alert title
     * @param string $message Alert message
     */
    public static function alert(int $partnerId, string $title, string $message): array
    {
        return self::send([
            'partner_id' => $partnerId,
            'type' => 'notification',
            'data' => [
                'title' => $title,
                'message' => $message,
                'level' => 'error',
                'dismissible' => false,
                'priority' => 'high',
                'sound' => 'alert',
                'timestamp' => time()
            ]
        ]);
    }

    /**
     * Broadcast message to all partners
     *
     * @param string $type Message type
     * @param array $data Message data
     */
    public static function broadcast(string $type, array $data): array
    {
        return self::send([
            'broadcast' => true,
            'type' => $type,
            'data' => $data
        ]);
    }

    /**
     * Send to specific channel
     *
     * @param string $channel Channel name
     * @param string $type Message type
     * @param array $data Message data
     */
    public static function toChannel(string $channel, string $type, array $data): array
    {
        return self::send([
            'channel' => $channel,
            'type' => $type,
            'data' => $data
        ]);
    }

    /**
     * Notify partner that store is going online/offline
     *
     * @param int $partnerId
     * @param bool $isOnline
     */
    public static function storeStatus(int $partnerId, bool $isOnline): array
    {
        return self::notify(
            $partnerId,
            $isOnline ? 'Loja Online' : 'Loja Offline',
            $isOnline
                ? 'Sua loja agora está visível para os clientes'
                : 'Sua loja foi desativada temporariamente',
            $isOnline ? 'success' : 'warning'
        );
    }

    /**
     * Notify partner of low stock
     *
     * @param int $partnerId
     * @param array $product Product details
     */
    public static function lowStock(int $partnerId, array $product): array
    {
        return self::notify(
            $partnerId,
            'Estoque Baixo',
            "O produto \"{$product['nome']}\" está com estoque baixo ({$product['estoque']} unidades)",
            'warning'
        );
    }

    /**
     * Notify partner of new review
     *
     * @param int $partnerId
     * @param array $review Review details
     */
    public static function newReview(int $partnerId, array $review): array
    {
        $stars = str_repeat('★', $review['rating'] ?? 5);
        return self::notify(
            $partnerId,
            'Nova Avaliação',
            "{$stars} - {$review['customer_name']} avaliou seu pedido",
            'info'
        );
    }
}
