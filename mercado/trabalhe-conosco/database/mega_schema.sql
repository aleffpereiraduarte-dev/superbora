-- ═══════════════════════════════════════════════════════════════════════════════
-- GAMIFICAÇÃO E TIERS (Instacart Cart Star / iFood Super Entregadores)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_worker_tiers (
    tier_id INT AUTO_INCREMENT PRIMARY KEY,
    tier_name VARCHAR(50) NOT NULL,
    tier_slug VARCHAR(30) NOT NULL UNIQUE,
    tier_level INT NOT NULL DEFAULT 1,
    icon VARCHAR(10) DEFAULT '⭐',
    color VARCHAR(7) DEFAULT '#FFD700',
    min_deliveries INT DEFAULT 0,
    min_rating DECIMAL(3,2) DEFAULT 0,
    min_acceptance_rate INT DEFAULT 0,
    priority_boost INT DEFAULT 0 COMMENT 'Segundos de vantagem para ver pedidos',
    earnings_bonus_percent DECIMAL(5,2) DEFAULT 0,
    benefits JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO om_worker_tiers (tier_name, tier_slug, tier_level, icon, color, min_deliveries, min_rating, min_acceptance_rate, priority_boost, earnings_bonus_percent, benefits) VALUES
('Bronze', 'bronze', 1, '🥉', '#CD7F32', 0, 0, 0, 0, 0, '{"badge": true}'),
('Prata', 'silver', 2, '🥈', '#C0C0C0', 50, 4.5, 70, 5, 5, '{"badge": true, "priority": true}'),
('Ouro', 'gold', 3, '🥇', '#FFD700', 200, 4.7, 80, 10, 10, '{"badge": true, "priority": true, "exclusive_missions": true}'),
('Platina', 'platinum', 4, '💎', '#E5E4E2', 500, 4.8, 85, 15, 15, '{"badge": true, "priority": true, "exclusive_missions": true, "cashback": true}'),
('Diamante', 'diamond', 5, '👑', '#B9F2FF', 1000, 4.9, 90, 20, 30, '{"badge": true, "priority": true, "exclusive_missions": true, "cashback": true, "vip_support": true}');

-- ═══════════════════════════════════════════════════════════════════════════════
-- PEAK PAY / BOOST (DoorDash / Instacart)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_peak_pay (
    peak_id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT DEFAULT NULL,
    partner_id INT DEFAULT NULL,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    bonus_amount DECIMAL(10,2) NOT NULL,
    bonus_type ENUM('fixed', 'percent', 'multiplier') DEFAULT 'fixed',
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    day_of_week SET('mon','tue','wed','thu','fri','sat','sun') DEFAULT NULL,
    min_deliveries INT DEFAULT 1,
    max_uses INT DEFAULT NULL,
    current_uses INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- CHALLENGES / STREAKS / MISSIONS (DoorDash / iFood)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_challenges (
    challenge_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    type ENUM('deliveries', 'earnings', 'streak', 'rating', 'acceptance', 'hours', 'distance') NOT NULL,
    target_value INT NOT NULL,
    reward_amount DECIMAL(10,2) NOT NULL,
    reward_type ENUM('cash', 'bonus', 'points', 'badge') DEFAULT 'cash',
    tier_required VARCHAR(30) DEFAULT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_recurring TINYINT(1) DEFAULT 0,
    recurrence_type ENUM('daily', 'weekly', 'monthly') DEFAULT NULL,
    max_participants INT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS om_worker_challenges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    challenge_id INT NOT NULL,
    current_progress INT DEFAULT 0,
    status ENUM('active', 'completed', 'failed', 'claimed') DEFAULT 'active',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    claimed_at DATETIME DEFAULT NULL,
    UNIQUE KEY unique_worker_challenge (worker_id, challenge_id)
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- HOTSPOTS / ÁREAS DE DEMANDA (DoorDash / Rappi)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_hotspots (
    hotspot_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    lat DECIMAL(10,8) NOT NULL,
    lng DECIMAL(11,8) NOT NULL,
    radius_meters INT DEFAULT 500,
    demand_level ENUM('low', 'medium', 'high', 'very_high') DEFAULT 'medium',
    estimated_wait_minutes INT DEFAULT 15,
    active_orders INT DEFAULT 0,
    active_workers INT DEFAULT 0,
    bonus_active TINYINT(1) DEFAULT 0,
    bonus_amount DECIMAL(10,2) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- EARNINGS MODES (DoorDash Earn by Time / Earn per Offer)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_earnings_modes (
    mode_id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    mode_type ENUM('per_offer', 'by_time') DEFAULT 'per_offer',
    hourly_rate DECIMAL(10,2) DEFAULT NULL COMMENT 'Para modo by_time',
    active_since DATETIME DEFAULT NULL,
    total_active_minutes INT DEFAULT 0,
    total_earned DECIMAL(10,2) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_worker_mode (worker_id)
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- HEAVY PAY / TAXA EXTRA (Instacart)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_heavy_pay_rules (
    rule_id INT AUTO_INCREMENT PRIMARY KEY,
    min_weight_kg DECIMAL(10,2) NOT NULL,
    min_items INT DEFAULT NULL,
    bonus_amount DECIMAL(10,2) NOT NULL,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

INSERT IGNORE INTO om_heavy_pay_rules (min_weight_kg, min_items, bonus_amount) VALUES
(10, 5, 3.00),
(20, 10, 5.00),
(30, 15, 8.00),
(50, 20, 12.00);

-- ═══════════════════════════════════════════════════════════════════════════════
-- DAILY GOALS / METAS DIÁRIAS (99Food R$250/dia)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_daily_goals (
    goal_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    required_deliveries INT NOT NULL,
    required_shopping INT DEFAULT 0,
    guaranteed_amount DECIMAL(10,2) NOT NULL,
    valid_from TIME DEFAULT '00:00:00',
    valid_until TIME DEFAULT '23:59:59',
    days_active SET('mon','tue','wed','thu','fri','sat','sun') DEFAULT 'mon,tue,wed,thu,fri,sat,sun',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO om_daily_goals (title, required_deliveries, required_shopping, guaranteed_amount, description) VALUES
('Meta Básica', 10, 0, 100.00, 'Complete 10 entregas e ganhe R$100 garantidos'),
('Meta Shopper', 5, 10, 150.00, 'Complete 5 entregas + 10 compras e ganhe R$150 garantidos'),
('Super Meta', 15, 5, 250.00, 'Complete 15 entregas + 5 compras e ganhe R$250 garantidos');

CREATE TABLE IF NOT EXISTS om_worker_daily_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    goal_id INT NOT NULL,
    date DATE NOT NULL,
    deliveries_done INT DEFAULT 0,
    shopping_done INT DEFAULT 0,
    is_completed TINYINT(1) DEFAULT 0,
    bonus_paid TINYINT(1) DEFAULT 0,
    bonus_amount DECIMAL(10,2) DEFAULT 0,
    completed_at DATETIME DEFAULT NULL,
    UNIQUE KEY unique_daily_goal (worker_id, goal_id, date)
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- TIPS / GORJETAS (100% para worker - todos os apps)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_tips (
    tip_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    worker_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    tip_type ENUM('pre_order', 'post_delivery', 'increased') DEFAULT 'pre_order',
    original_amount DECIMAL(10,2) DEFAULT NULL COMMENT 'Se cliente aumentou depois',
    paid_to_worker TINYINT(1) DEFAULT 0,
    paid_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- TIP PROTECTION (Instacart - cobre até R$10 se cliente zerar)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_tip_protection (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    worker_id INT NOT NULL,
    original_tip DECIMAL(10,2) NOT NULL,
    final_tip DECIMAL(10,2) NOT NULL,
    protection_amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- QUALITY BONUS (Instacart - bônus por 5 estrelas)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_quality_bonuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    order_id INT NOT NULL,
    rating INT NOT NULL,
    bonus_amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- FAST PAY / SAQUE INSTANTÂNEO (DoorDash / iFood / 99Food)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_fast_pay_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    fee DECIMAL(10,2) DEFAULT 0,
    net_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    payment_method VARCHAR(50) DEFAULT 'pix',
    payment_key VARCHAR(255) DEFAULT NULL,
    transaction_id VARCHAR(100) DEFAULT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- POINTS / SELOS (iFood - trocar por créditos)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_worker_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    points INT NOT NULL,
    source ENUM('delivery', 'rating', 'challenge', 'streak', 'referral', 'bonus') NOT NULL,
    reference_id INT DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS om_rewards_store (
    reward_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    image VARCHAR(255) DEFAULT NULL,
    points_required INT NOT NULL,
    reward_type ENUM('cash', 'voucher', 'product', 'service') NOT NULL,
    reward_value DECIMAL(10,2) DEFAULT NULL,
    stock INT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO om_rewards_store (title, description, points_required, reward_type, reward_value) VALUES
('R$10 em Crédito', 'Crédito de R$10 para usar em compras', 500, 'cash', 10.00),
('R$25 em Crédito', 'Crédito de R$25 para usar em compras', 1000, 'cash', 25.00),
('R$50 em Crédito', 'Crédito de R$50 para usar em compras', 1800, 'cash', 50.00),
('Manutenção Moto', 'Voucher para troca de óleo', 800, 'service', 50.00),
('Capacete Premium', 'Capacete de alta qualidade', 3000, 'product', 150.00),
('Bag Térmica', 'Bag térmica profissional', 1500, 'product', 80.00);

CREATE TABLE IF NOT EXISTS om_reward_redemptions (
    redemption_id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    reward_id INT NOT NULL,
    points_spent INT NOT NULL,
    status ENUM('pending', 'approved', 'delivered', 'cancelled') DEFAULT 'pending',
    delivery_address TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- BATCHING / PEDIDOS AGRUPADOS (Instacart Multi-store)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_batches (
    batch_id INT AUTO_INCREMENT PRIMARY KEY,
    batch_code VARCHAR(20) NOT NULL UNIQUE,
    worker_id INT DEFAULT NULL,
    status ENUM('available', 'assigned', 'in_progress', 'completed', 'cancelled') DEFAULT 'available',
    total_items INT DEFAULT 0,
    total_weight_kg DECIMAL(10,2) DEFAULT 0,
    total_distance_km DECIMAL(10,2) DEFAULT 0,
    estimated_time_minutes INT DEFAULT 0,
    base_pay DECIMAL(10,2) NOT NULL,
    heavy_pay DECIMAL(10,2) DEFAULT 0,
    boost_pay DECIMAL(10,2) DEFAULT 0,
    total_tips DECIMAL(10,2) DEFAULT 0,
    total_earnings DECIMAL(10,2) NOT NULL,
    priority_level INT DEFAULT 0,
    expires_at DATETIME DEFAULT NULL,
    assigned_at DATETIME DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS om_batch_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    order_id INT NOT NULL,
    sequence INT DEFAULT 1,
    store_name VARCHAR(100) DEFAULT NULL,
    items_count INT DEFAULT 0,
    UNIQUE KEY unique_batch_order (batch_id, order_id)
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- QUEUED BATCHES (Instacart - aceitar próximo antes de terminar)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_queued_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    batch_id INT NOT NULL,
    queue_position INT DEFAULT 1,
    queued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY unique_worker_queue (worker_id, batch_id)
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- ROUTE DESTINATION / ESCOLHA DE DESTINO (iFood)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_worker_route_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    destination_lat DECIMAL(10,8) DEFAULT NULL,
    destination_lng DECIMAL(11,8) DEFAULT NULL,
    destination_address VARCHAR(255) DEFAULT NULL,
    max_distance_km DECIMAL(10,2) DEFAULT 10,
    prefer_direction TINYINT(1) DEFAULT 0 COMMENT 'Preferir pedidos na direção do destino',
    active_until DATETIME DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_worker_pref (worker_id)
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- WAIT TIME PAY / TAXA POR ESPERA (iFood / DoorDash)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_wait_time_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    worker_id INT NOT NULL,
    wait_type ENUM('store', 'customer') NOT NULL,
    wait_minutes INT NOT NULL,
    payment_amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- REFERRAL PROGRAM / INDICAÇÃO (Todos os apps)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_referrals (
    referral_id INT AUTO_INCREMENT PRIMARY KEY,
    referrer_worker_id INT NOT NULL,
    referred_worker_id INT NOT NULL,
    referral_code VARCHAR(20) NOT NULL,
    status ENUM('pending', 'active', 'qualified', 'paid') DEFAULT 'pending',
    bonus_referrer DECIMAL(10,2) DEFAULT 50.00,
    bonus_referred DECIMAL(10,2) DEFAULT 25.00,
    required_deliveries INT DEFAULT 10,
    deliveries_completed INT DEFAULT 0,
    paid_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- WORKER STATS EXTENDED (Métricas completas)
-- ═══════════════════════════════════════════════════════════════════════════════

ALTER TABLE om_market_workers 
ADD COLUMN IF NOT EXISTS tier_id INT DEFAULT 1,
ADD COLUMN IF NOT EXISTS total_points INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS total_earnings DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS total_tips DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS total_deliveries INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS total_shopping INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS total_distance_km DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS average_rating DECIMAL(3,2) DEFAULT 5.00,
ADD COLUMN IF NOT EXISTS acceptance_rate INT DEFAULT 100,
ADD COLUMN IF NOT EXISTS completion_rate INT DEFAULT 100,
ADD COLUMN IF NOT EXISTS on_time_rate INT DEFAULT 100,
ADD COLUMN IF NOT EXISTS current_streak INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS best_streak INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS five_star_count INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS referral_code VARCHAR(20) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS referred_by INT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS pix_key VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS bank_account JSON DEFAULT NULL,
ADD COLUMN IF NOT EXISTS available_balance DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS pending_balance DECIMAL(12,2) DEFAULT 0;

-- ═══════════════════════════════════════════════════════════════════════════════
-- INSURANCE / SEGUROS (iFood)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_worker_insurance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    insurance_type ENUM('accident', 'life', 'theft', 'assistance') NOT NULL,
    provider VARCHAR(100) DEFAULT 'OneMundo',
    policy_number VARCHAR(50) DEFAULT NULL,
    coverage_amount DECIMAL(12,2) DEFAULT NULL,
    monthly_cost DECIMAL(10,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    valid_from DATE NOT NULL,
    valid_until DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_worker_insurance (worker_id, insurance_type)
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- ACCOUNT HEALTH / SAÚDE DA CONTA (iFood)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_account_health (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    health_status ENUM('excellent', 'good', 'attention', 'warning', 'critical') DEFAULT 'good',
    rating_score INT DEFAULT 100,
    acceptance_score INT DEFAULT 100,
    completion_score INT DEFAULT 100,
    fraud_score INT DEFAULT 100,
    overall_score INT DEFAULT 100,
    warnings_count INT DEFAULT 0,
    suspensions_count INT DEFAULT 0,
    last_warning_at DATETIME DEFAULT NULL,
    next_review_at DATETIME DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_worker_health (worker_id)
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- EARNINGS HISTORY (Histórico detalhado)
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS om_earnings_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    date DATE NOT NULL,
    base_earnings DECIMAL(12,2) DEFAULT 0,
    tips_earnings DECIMAL(12,2) DEFAULT 0,
    bonus_earnings DECIMAL(12,2) DEFAULT 0,
    peak_pay_earnings DECIMAL(12,2) DEFAULT 0,
    challenge_earnings DECIMAL(12,2) DEFAULT 0,
    heavy_pay_earnings DECIMAL(12,2) DEFAULT 0,
    wait_time_earnings DECIMAL(12,2) DEFAULT 0,
    quality_bonus_earnings DECIMAL(12,2) DEFAULT 0,
    total_earnings DECIMAL(12,2) DEFAULT 0,
    deliveries_count INT DEFAULT 0,
    shopping_count INT DEFAULT 0,
    hours_online DECIMAL(5,2) DEFAULT 0,
    hours_active DECIMAL(5,2) DEFAULT 0,
    distance_km DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_worker_date (worker_id, date)
) ENGINE=InnoDB;

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_peak_pay_active ON om_peak_pay(is_active, start_time, end_time);
CREATE INDEX IF NOT EXISTS idx_challenges_active ON om_challenges(is_active, start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_hotspots_demand ON om_hotspots(demand_level, bonus_active);
CREATE INDEX IF NOT EXISTS idx_batches_status ON om_batches(status, priority_level);
CREATE INDEX IF NOT EXISTS idx_earnings_date ON om_earnings_history(worker_id, date);