<?php
/**
 * PushNotification - Delegates to NotificationSender for actual push delivery.
 * Used by shopper endpoints (finalizar-entrega, aceitar-pedido, iniciar-entrega)
 * and financeiro endpoints (cancelar-repasse, estornar-repasse).
 */

class PushNotification {
    const USER_TYPE_CUSTOMER = 'customer';
    const USER_TYPE_PARTNER = 'partner';
    const USER_TYPE_SHOPPER = 'worker';
    const USER_TYPE_MOTORISTA = 'motorista';

    private static $instance = null;
    private $db = null;
    private $sender = null;

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setDb(PDO $db): self {
        $this->db = $db;
        return $this;
    }

    /**
     * Send push notification
     * Supports multiple calling conventions found in the codebase:
     *   send($userId, $userType, $title, $body, $data, $url)
     *   send($userId, $title, $body, $data)
     */
    public function send(): bool {
        $args = func_get_args();

        if (!$this->db) {
            error_log("[PushNotification] send() called without setDb()");
            return false;
        }

        if (!$this->ensureSender()) {
            return false;
        }

        // Detect calling convention
        if (count($args) >= 4 && is_string($args[1]) && in_array($args[1], [
            self::USER_TYPE_CUSTOMER, self::USER_TYPE_PARTNER,
            self::USER_TYPE_SHOPPER, self::USER_TYPE_MOTORISTA,
            'customer', 'partner', 'worker', 'motorista', 'shopper'
        ])) {
            // send($userId, $userType, $title, $body, $data = [], $url = '')
            $userId = (int)$args[0];
            $userType = $args[1];
            $title = $args[2] ?? '';
            $body = $args[3] ?? '';
            $data = $args[4] ?? [];
            $url = $args[5] ?? '';
        } else {
            // send($userId, $title, $body, $data = [])
            $userId = (int)($args[0] ?? 0);
            $title = $args[1] ?? '';
            $body = $args[2] ?? '';
            $data = $args[3] ?? [];
            $userType = self::USER_TYPE_CUSTOMER;
            $url = '';
        }

        if (!$userId || empty($title)) {
            return false;
        }

        if (!empty($url) && is_array($data)) {
            $data['url'] = $url;
        }

        // Map motorista to worker for DB lookup
        $dbType = $userType;
        if ($dbType === 'motorista') $dbType = 'worker';
        if ($dbType === 'shopper') $dbType = 'worker';

        try {
            $method = match($dbType) {
                'customer' => 'notifyCustomer',
                'partner' => 'notifyPartner',
                'worker' => 'notifyShopper',
                default => 'notifyCustomer',
            };

            $result = $this->sender->$method($userId, $title, $body, $data);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            error_log("[PushNotification] send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Convenience: notify delivery started
     */
    public function notifyDeliveryStarted(int $orderId, int $customerId, string $shopperName = ''): bool {
        return $this->send(
            $customerId,
            self::USER_TYPE_CUSTOMER,
            'Entrega a caminho!',
            $shopperName ? "{$shopperName} saiu para entregar seu pedido #{$orderId}" : "Seu pedido #{$orderId} saiu para entrega!",
            ['order_id' => $orderId, 'action' => 'delivery_started']
        );
    }

    /**
     * Convenience: notify order accepted by shopper
     */
    public function notifyOrderAccepted(int $orderId, int $customerId, string $shopperName = ''): bool {
        return $this->send(
            $customerId,
            self::USER_TYPE_CUSTOMER,
            'Pedido aceito!',
            $shopperName ? "{$shopperName} aceitou seu pedido #{$orderId}" : "Seu pedido #{$orderId} foi aceito!",
            ['order_id' => $orderId, 'action' => 'order_accepted']
        );
    }

    private function ensureSender(): bool {
        if ($this->sender === null) {
            // Try multiple paths
            $paths = [
                dirname(__DIR__) . '/../api/mercado/helpers/NotificationSender.php',
                '/var/www/html/api/mercado/helpers/NotificationSender.php',
            ];
            foreach ($paths as $p) {
                if (file_exists($p)) {
                    require_once $p;
                    break;
                }
            }
            if (!class_exists('NotificationSender')) {
                error_log("[PushNotification] NotificationSender class not found");
                return false;
            }
            $this->sender = NotificationSender::getInstance($this->db);
        }
        return true;
    }
}

/**
 * Global helper function used by shopper endpoints
 */
function om_push(): PushNotification {
    return PushNotification::getInstance();
}

/**
 * Legacy alias
 */
function push_notify(): PushNotification {
    return PushNotification::getInstance();
}
