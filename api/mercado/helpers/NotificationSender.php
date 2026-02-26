<?php
/**
 * ====================================================================
 * NotificationSender - High-Level Notification Dispatcher
 * ====================================================================
 *
 * Sends push notifications to customers, partners, and shoppers.
 * Looks up device tokens in om_market_push_tokens and dispatches
 * via FCMHelper. Also inserts into om_market_notifications for
 * in-app notification feed.
 *
 * This is a convenience wrapper. For lower-level control, use
 * FCMHelper directly.
 *
 * Usage:
 *   require_once __DIR__ . '/NotificationSender.php';
 *   $sender = NotificationSender::getInstance($db);
 *   $sender->notifyCustomer($customerId, 'Title', 'Body', ['order_id' => 1]);
 *   $sender->notifyPartner($partnerId, 'New Order!', 'Order #SB-250203-ABC');
 *   $sender->notifyShopper($shopperId, 'Available!', 'New order nearby');
 */

require_once __DIR__ . '/FCMHelper.php';

class NotificationSender
{
    private const LOG_PREFIX = '[NotifSender]';

    private PDO $db;
    private FCMHelper $fcm;
    private static ?self $instance = null;

    private function __construct(PDO $db)
    {
        $this->db = $db;
        $this->fcm = FCMHelper::getInstance($db);
    }

    public static function getInstance(PDO $db): self
    {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }

    /**
     * Send push notification to a customer
     *
     * @param int    $customerId  Customer ID
     * @param string $title       Notification title
     * @param string $body        Notification body
     * @param array  $data        Extra data payload (order_id, url, status, etc.)
     * @return array              ['success' => bool, 'sent' => int, 'failed' => int]
     */
    public function notifyCustomer(int $customerId, string $title, string $body, array $data = []): array
    {
        return $this->notifyUser($customerId, 'customer', $title, $body, $data);
    }

    /**
     * Send push notification to a partner (store owner)
     *
     * @param int    $partnerId  Partner ID
     * @param string $title      Notification title
     * @param string $body       Notification body
     * @param array  $data       Extra data payload
     * @return array
     */
    public function notifyPartner(int $partnerId, string $title, string $body, array $data = []): array
    {
        return $this->notifyUser($partnerId, 'partner', $title, $body, $data);
    }

    /**
     * Send push notification to a shopper
     *
     * @param int    $shopperId  Shopper ID
     * @param string $title      Notification title
     * @param string $body       Notification body
     * @param array  $data       Extra data payload
     * @return array
     */
    public function notifyShopper(int $shopperId, string $title, string $body, array $data = []): array
    {
        // Shopper system disabled
        return ['success' => true, 'sent' => 0, 'failed' => 0];
    }

    public function notifyAllShoppers(string $title, string $body, array $data = []): array
    {
        // Shopper system disabled
        return ['success' => true, 'sent' => 0, 'failed' => 0];
    }

    /**
     * Send push notification to all users of a given type
     *
     * @param string $userType  'customer', 'partner', or 'shopper'
     * @param string $title     Notification title
     * @param string $body      Notification body
     * @param array  $data      Extra data payload
     * @return array
     */
    public function broadcast(string $userType, string $title, string $body, array $data = []): array
    {
        return $this->fcm->sendToTopic($userType . '_all', $title, $body, $data);
    }

    // ─── Private ──────────────────────────────────────────────────────

    /**
     * Core method: look up tokens and dispatch via FCM
     */
    private function notifyUser(int $userId, string $userType, string $title, string $body, array $data): array
    {
        if (!$userId) {
            return ['success' => false, 'sent' => 0, 'failed' => 0, 'reason' => 'Invalid user ID'];
        }

        try {
            // 1. Store in notifications table for in-app feed (polling)
            $this->storeNotification($userId, $userType, $title, $body, $data);

            // 2. Get FCM tokens for the user
            $tokens = $this->getTokensForUser($userId, $userType);

            if (empty($tokens)) {
                $this->log('INFO', "No FCM tokens for $userType #$userId. Stored in-app only.");
                return ['success' => true, 'sent' => 0, 'failed' => 0, 'reason' => 'No tokens registered'];
            }

            // 3. Send via FCM
            if (count($tokens) === 1) {
                $result = $this->fcm->sendToToken($tokens[0], $title, $body, $data);
                return [
                    'success' => $result['success'],
                    'sent' => $result['success'] ? 1 : 0,
                    'failed' => $result['success'] ? 0 : 1,
                ];
            }

            return $this->fcm->sendToTokens($tokens, $title, $body, $data);

        } catch (\Exception $e) {
            $this->log('ERROR', "Failed to notify $userType #$userId: " . $e->getMessage());
            return ['success' => false, 'sent' => 0, 'failed' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Retrieve FCM tokens for a user from om_market_push_tokens
     */
    private function getTokensForUser(int $userId, string $userType): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT token FROM om_market_push_tokens
                WHERE user_id = ? AND user_type = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$userId, $userType]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            $this->log('ERROR', "Failed to fetch tokens for $userType #$userId: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Store notification in om_market_notifications for in-app polling
     */
    private function storeNotification(int $userId, string $userType, string $title, string $body, array $data): void
    {
            // Sanitize text inputs before DB storage
            $title = strip_tags($title);
            $body = strip_tags($body);
        try {
            // Dedup: if data contains an order_id, skip if same notification was sent recently
            $orderId = $data['order_id'] ?? null;
            if ($orderId !== null) {
                // Use JSON extraction for exact match (avoids order_id 12 matching 123)
                $dedupStmt = $this->db->prepare("
                    SELECT COUNT(*) FROM om_market_notifications
                    WHERE recipient_id = ?
                      AND recipient_type = ?
                      AND title = ?
                      AND (data::jsonb->>'order_id')::int = ?
                      AND sent_at >= NOW() - INTERVAL '5 minutes'
                ");
                $dedupStmt->execute([
                    $userId,
                    $userType,
                    $title,
                    (int)$orderId,
                ]);
                if ((int)$dedupStmt->fetchColumn() > 0) {
                    $this->log('INFO', "Dedup: skipping duplicate notification for $userType #$userId, order #$orderId, title '$title'");
                    return;
                }
            }

            $stmt = $this->db->prepare("
                INSERT INTO om_market_notifications
                    (recipient_id, recipient_type, title, message, data, is_read, sent_at)
                VALUES (?, ?, ?, ?, ?, 0, NOW())
            ");
            $stmt->execute([
                $userId,
                $userType,
                $title,
                $body,
                json_encode($data, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Exception $e) {
            // Don't fail the notification if DB insert fails
            $this->log('ERROR', "Failed to store notification: " . $e->getMessage());
        }
    }

    /**
     * Write to error log
     */
    private function log(string $level, string $message): void
    {
        error_log(self::LOG_PREFIX . " [$level] $message");
    }
}
