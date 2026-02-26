-- =====================================================
-- SuperBora Marketplace - Delivery Zones
-- Table for partner delivery zone management
-- =====================================================

CREATE TABLE IF NOT EXISTS om_partner_delivery_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL,
    label VARCHAR(100) NOT NULL COMMENT 'Zone name (e.g., Centro, Zona Sul)',
    radius_min_km DECIMAL(5,2) DEFAULT 0 COMMENT 'Minimum distance from store in km',
    radius_max_km DECIMAL(5,2) DEFAULT 5 COMMENT 'Maximum distance from store in km',
    fee DECIMAL(10,2) DEFAULT 5.00 COMMENT 'Delivery fee for this zone',
    estimated_time VARCHAR(50) DEFAULT '30-45 min' COMMENT 'Estimated delivery time',
    status TINYINT(1) DEFAULT 1 COMMENT '1=active, 0=inactive',
    sort_order INT DEFAULT 0 COMMENT 'Display order',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_partner (partner_id),
    KEY idx_partner_status (partner_id, status),
    KEY idx_radius (partner_id, radius_min_km, radius_max_km)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Sample data for testing
-- =====================================================

-- Insert sample zones for partner_id = 1 (for testing purposes)
-- Uncomment to use:
/*
INSERT INTO om_partner_delivery_zones (partner_id, label, radius_min_km, radius_max_km, fee, estimated_time, sort_order) VALUES
(1, 'Muito Proximo', 0, 2, 0.00, '15-25 min', 1),
(1, 'Proximo', 2, 5, 5.00, '25-35 min', 2),
(1, 'Medio', 5, 8, 8.00, '35-45 min', 3),
(1, 'Distante', 8, 12, 12.00, '45-60 min', 4);
*/

-- =====================================================
-- Useful queries
-- =====================================================

-- Get delivery zones for a partner
-- SELECT * FROM om_partner_delivery_zones WHERE partner_id = ? AND status = 1 ORDER BY radius_min_km ASC;

-- Get delivery fee based on customer distance
-- SELECT fee, estimated_time, label FROM om_partner_delivery_zones
-- WHERE partner_id = ? AND status = 1 AND ? >= radius_min_km AND ? < radius_max_km
-- LIMIT 1;

-- Check if partner delivers to a distance
-- SELECT COUNT(*) > 0 AS can_deliver FROM om_partner_delivery_zones
-- WHERE partner_id = ? AND status = 1 AND ? < radius_max_km;
