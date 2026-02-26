<?php
/**
 * Cron: Auto-cancelar pedidos com timer expirado
 * Executar a cada 1 minuto: * * * * * php /var/www/html/api/cron/auto-cancelar-pedidos.php
 *
 * Cancela pedidos pendentes que excederam o timer_expires (5 min default)
 */

// Pode ser executado via CLI ou HTTP
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
}

require_once dirname(__DIR__) . '/mercado/config/database.php';
require_once dirname(__DIR__) . '/mercado/helpers/notify.php';

try {
    $db = getDB();

    // Buscar pedidos com timer expirado
    $stmt = $db->prepare("
        SELECT o.*, p.name as partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.status = 'pendente'
          AND o.timer_expires IS NOT NULL
          AND o.timer_expires < NOW()
        LIMIT 100
    ");
    $stmt->execute();
    $pedidos = $stmt->fetchAll();

    $cancelados = 0;

    foreach ($pedidos as $pedido) {
        $order_id = $pedido['order_id'];

        // Cancelar pedido
        $stmt = $db->prepare("
            UPDATE om_market_orders SET
                status = 'cancelado',
                cancel_reason = 'Auto-cancelado: parceiro nao respondeu no prazo',
                date_modified = NOW()
            WHERE order_id = ? AND status = 'pendente'
        ");
        $stmt->execute([$order_id]);

        if ($stmt->rowCount() > 0) {
            $cancelados++;

            // Devolver estoque
            $stmtItens = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
            $stmtItens->execute([$order_id]);
            $itens = $stmtItens->fetchAll();

            foreach ($itens as $item) {
                if ($item['product_id']) {
                    $stmtEstoque = $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?");
                    $stmtEstoque->execute([$item['quantity'], $item['product_id']]);
                }
            }

            // Notificar cliente
            $customer_id = (int)($pedido['customer_id'] ?? 0);
            if ($customer_id) {
                notifyCustomer($db, $customer_id,
                    'Pedido cancelado',
                    "Seu pedido #{$pedido['order_number']} foi cancelado porque o estabelecimento nao respondeu a tempo.",
                    '/mercado/'
                );
            }

            error_log("[auto-cancel] Pedido #$order_id cancelado (timer expirado) | Parceiro: {$pedido['partner_name']}");
        }
    }

    $msg = "Auto-cancel: $cancelados pedidos cancelados";
    error_log("[auto-cancel] $msg");

    if (php_sapi_name() === 'cli') {
        echo $msg . "\n";
    } else {
        response(true, ["cancelados" => $cancelados], $msg);
    }

} catch (Exception $e) {
    error_log("[auto-cancel] Erro: " . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo "Erro: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        response(false, null, "Erro no auto-cancel", 500);
    }
}
