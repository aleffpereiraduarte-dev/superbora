-- =====================================================
-- Push Notification Subscriptions Table
-- For Web Push API support
-- =====================================================

-- Push subscriptions table
CREATE TABLE IF NOT EXISTS om_push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type ENUM('partner', 'customer', 'worker', 'shopper', 'admin') NOT NULL DEFAULT 'partner',
    endpoint TEXT NOT NULL,
    p256dh VARCHAR(255) DEFAULT NULL COMMENT 'Public key for encryption',
    auth VARCHAR(255) DEFAULT NULL COMMENT 'Auth secret for encryption',
    device_info VARCHAR(255) DEFAULT NULL COMMENT 'Browser/device info',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,

    -- Indexes
    KEY idx_user (user_id, user_type),
    KEY idx_active (is_active),
    KEY idx_endpoint (endpoint(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Printer settings table (for auto-print configuration)
CREATE TABLE IF NOT EXISTS om_partner_printer_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL,
    auto_print_enabled TINYINT(1) DEFAULT 0,
    paper_width ENUM('58mm', '80mm') DEFAULT '80mm',
    printer_type ENUM('serial', 'usb', 'browser') DEFAULT 'browser',
    open_drawer_on_print TINYINT(1) DEFAULT 0,
    print_copies INT DEFAULT 1,
    auto_accept_and_print TINYINT(1) DEFAULT 0 COMMENT 'Auto accept orders when printing',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_partner (partner_id),
    FOREIGN KEY (partner_id) REFERENCES om_partner(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification log for debugging and analytics
CREATE TABLE IF NOT EXISTS om_push_notification_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT DEFAULT NULL,
    user_id INT DEFAULT NULL,
    user_type VARCHAR(20) DEFAULT NULL,
    notification_type VARCHAR(50) DEFAULT NULL COMMENT 'new_order, order_update, chat, etc',
    title VARCHAR(255) DEFAULT NULL,
    body TEXT DEFAULT NULL,
    data JSON DEFAULT NULL,
    status ENUM('pending', 'sent', 'failed', 'expired') DEFAULT 'pending',
    error_message TEXT DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    KEY idx_subscription (subscription_id),
    KEY idx_user (user_id, user_type),
    KEY idx_type (notification_type),
    KEY idx_status (status),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample data for testing
-- INSERT INTO om_push_subscriptions (user_id, user_type, endpoint, p256dh, auth)
-- VALUES (1, 'partner', 'https://fcm.googleapis.com/fcm/send/test-endpoint', 'test-key', 'test-auth');
