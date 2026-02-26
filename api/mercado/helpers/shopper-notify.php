<?php
/**
 * Notifica shoppers disponiveis sobre novo pedido de mercado/supermercado
 */

require_once __DIR__ . '/NotificationSender.php';

/**
 * Notifica todos os shoppers aprovados e disponiveis
 * @param PDO $db
 * @param int $orderId
 * @param string $orderNumber
 * @param float $total
 * @param string $partnerName
 * @return int Numero de shoppers notificados
 */
function notifyAvailableShoppers(PDO $db, int $orderId, string $orderNumber, float $total, string $partnerName): int {
    try {
        // Buscar shoppers aprovados (status = '1') que estao online/disponiveis
        $stmt = $db->query("
            SELECT shopper_id, name
            FROM om_market_shoppers
            WHERE status = '1'
            AND (is_online = 1 OR is_online IS NULL)
            LIMIT 50
        ");
        $shoppers = $stmt->fetchAll();

        if (empty($shoppers)) {
            error_log("[shopper-notify] Nenhum shopper disponivel para pedido #$orderNumber");
            return 0;
        }

        $totalFormatted = 'R$ ' . number_format($total, 2, ',', '.');
        $notified = 0;
        $sender = NotificationSender::getInstance($db);

        foreach ($shoppers as $shopper) {
            $result = $sender->notifyShopper(
                (int)$shopper['shopper_id'],
                'Novo pedido disponivel!',
                "Pedido #$orderNumber - $totalFormatted - $partnerName",
                ['order_id' => $orderId, 'url' => '/pedidos-disponiveis']
            );
            if ($result['success']) $notified++;
        }

        error_log("[shopper-notify] $notified shoppers notificados para pedido #$orderNumber");
        return $notified;

    } catch (Exception $e) {
        error_log("[shopper-notify] Erro: " . $e->getMessage());
        return 0;
    }
}
