<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  🚀 MEGA INSTALADOR - TODAS FUNCIONALIDADES DOS PRINCIPAIS APPS DE DELIVERY
 *  
 *  Baseado em: Rappi | Instacart | DoorDash | iFood | 99Food
 *  
 *  OneMundo Shopper App - Sistema Completo de Gamificação e Earnings
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

$baseDir = __DIR__;
$results = [];

function saveFile($path, $content) {
    global $results, $baseDir;
    $fullPath = $baseDir . '/' . $path;
    $dir = dirname($fullPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (file_put_contents($fullPath, $content)) {
        $results[] = ['status' => 'ok', 'file' => $path, 'size' => strlen($content)];
        return true;
    }
    $results[] = ['status' => 'error', 'file' => $path];
    return false;
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// PARTE 1: NOVAS TABELAS DO BANCO DE DADOS
// ═══════════════════════════════════════════════════════════════════════════════════════════════

$sqlSchema = <<<'SQL'
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
SQL;
saveFile('database/mega_schema.sql', $sqlSchema);

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// PARTE 2: CLASSES HELPER PARA TODAS AS FUNCIONALIDADES
// ═══════════════════════════════════════════════════════════════════════════════════════════════

$gamificationHelper = <<<'PHP'
<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * GAMIFICATION HELPER - Sistema completo de gamificação
 * Baseado em: Instacart Cart Star, iFood Super Entregadores, DoorDash Top Dasher
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class GamificationHelper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // TIERS / NÍVEIS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Obter tier atual do worker
     */
    public function getWorkerTier($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT t.* FROM om_worker_tiers t
            JOIN om_market_workers w ON w.tier_id = t.tier_id
            WHERE w.worker_id = ?
        ");
        $stmt->execute([$workerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Calcular e atualizar tier do worker
     */
    public function updateWorkerTier($workerId) {
        // Buscar stats do worker
        $stmt = $this->pdo->prepare("
            SELECT total_deliveries, average_rating, acceptance_rate 
            FROM om_market_workers WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        
        if (!$worker) return false;
        
        // Encontrar tier adequado
        $stmt = $this->pdo->prepare("
            SELECT * FROM om_worker_tiers 
            WHERE min_deliveries <= ? AND min_rating <= ? AND min_acceptance_rate <= ?
            ORDER BY tier_level DESC LIMIT 1
        ");
        $stmt->execute([
            $worker['total_deliveries'],
            $worker['average_rating'],
            $worker['acceptance_rate']
        ]);
        $newTier = $stmt->fetch();
        
        if ($newTier) {
            $this->pdo->prepare("UPDATE om_market_workers SET tier_id = ? WHERE worker_id = ?")
                      ->execute([$newTier['tier_id'], $workerId]);
            return $newTier;
        }
        return false;
    }
    
    /**
     * Obter benefícios do tier
     */
    public function getTierBenefits($tierId) {
        $stmt = $this->pdo->prepare("SELECT benefits, earnings_bonus_percent, priority_boost FROM om_worker_tiers WHERE tier_id = ?");
        $stmt->execute([$tierId]);
        $tier = $stmt->fetch();
        
        if ($tier) {
            return [
                'benefits' => json_decode($tier['benefits'], true),
                'earnings_bonus' => $tier['earnings_bonus_percent'],
                'priority_seconds' => $tier['priority_boost']
            ];
        }
        return null;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // CHALLENGES / DESAFIOS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Obter desafios disponíveis para worker
     */
    public function getAvailableChallenges($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT c.*, 
                   COALESCE(wc.current_progress, 0) as progress,
                   COALESCE(wc.status, 'available') as worker_status
            FROM om_challenges c
            LEFT JOIN om_worker_challenges wc ON c.challenge_id = wc.challenge_id AND wc.worker_id = ?
            WHERE c.is_active = 1 
              AND c.start_date <= CURRENT_DATE 
              AND c.end_date >= CURRENT_DATE
              AND (c.tier_required IS NULL OR c.tier_required IN (
                  SELECT t.tier_slug FROM om_worker_tiers t
                  JOIN om_market_workers w ON w.tier_id = t.tier_id
                  WHERE w.worker_id = ?
              ))
            ORDER BY c.reward_amount DESC
        ");
        $stmt->execute([$workerId, $workerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Participar de um desafio
     */
    public function joinChallenge($workerId, $challengeId) {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO om_worker_challenges (worker_id, challenge_id, status)
            VALUES (?, ?, 'active')
        ");
        return $stmt->execute([$workerId, $challengeId]);
    }
    
    /**
     * Atualizar progresso do desafio
     */
    public function updateChallengeProgress($workerId, $type, $value = 1) {
        // Buscar desafios ativos do tipo
        $stmt = $this->pdo->prepare("
            SELECT wc.*, c.target_value, c.reward_amount, c.reward_type
            FROM om_worker_challenges wc
            JOIN om_challenges c ON wc.challenge_id = c.challenge_id
            WHERE wc.worker_id = ? AND wc.status = 'active' AND c.type = ?
        ");
        $stmt->execute([$workerId, $type]);
        $challenges = $stmt->fetchAll();
        
        $completed = [];
        foreach ($challenges as $ch) {
            $newProgress = $ch['current_progress'] + $value;
            
            if ($newProgress >= $ch['target_value']) {
                // Completou!
                $this->pdo->prepare("
                    UPDATE om_worker_challenges 
                    SET current_progress = ?, status = 'completed', completed_at = NOW()
                    WHERE id = ?
                ")->execute([$ch['target_value'], $ch['id']]);
                
                $completed[] = [
                    'challenge_id' => $ch['challenge_id'],
                    'reward_amount' => $ch['reward_amount'],
                    'reward_type' => $ch['reward_type']
                ];
            } else {
                $this->pdo->prepare("UPDATE om_worker_challenges SET current_progress = ? WHERE id = ?")
                          ->execute([$newProgress, $ch['id']]);
            }
        }
        
        return $completed;
    }
    
    /**
     * Reivindicar recompensa de desafio
     */
    public function claimChallengeReward($workerId, $challengeId) {
        $stmt = $this->pdo->prepare("
            SELECT wc.*, c.reward_amount, c.reward_type
            FROM om_worker_challenges wc
            JOIN om_challenges c ON wc.challenge_id = c.challenge_id
            WHERE wc.worker_id = ? AND wc.challenge_id = ? AND wc.status = 'completed'
        ");
        $stmt->execute([$workerId, $challengeId]);
        $challenge = $stmt->fetch();
        
        if (!$challenge) return false;
        
        $this->pdo->beginTransaction();
        try {
            // Marcar como reivindicado
            $this->pdo->prepare("
                UPDATE om_worker_challenges SET status = 'claimed', claimed_at = NOW() WHERE id = ?
            ")->execute([$challenge['id']]);
            
            // Dar recompensa
            if ($challenge['reward_type'] === 'cash' || $challenge['reward_type'] === 'bonus') {
                $this->pdo->prepare("
                    UPDATE om_market_workers 
                    SET available_balance = available_balance + ? 
                    WHERE worker_id = ?
                ")->execute([$challenge['reward_amount'], $workerId]);
            } elseif ($challenge['reward_type'] === 'points') {
                $this->addPoints($workerId, $challenge['reward_amount'], 'challenge', $challengeId);
            }
            
            $this->pdo->commit();
            return $challenge;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // POINTS / PONTOS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Adicionar pontos ao worker
     */
    public function addPoints($workerId, $points, $source, $referenceId = null, $description = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO om_worker_points (worker_id, points, source, reference_id, description)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$workerId, $points, $source, $referenceId, $description]);
        
        // Atualizar total
        $this->pdo->prepare("UPDATE om_market_workers SET total_points = total_points + ? WHERE worker_id = ?")
                  ->execute([$points, $workerId]);
        
        return true;
    }
    
    /**
     * Obter saldo de pontos
     */
    public function getPointsBalance($workerId) {
        $stmt = $this->pdo->prepare("SELECT total_points FROM om_market_workers WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        return $stmt->fetchColumn() ?: 0;
    }
    
    /**
     * Obter histórico de pontos
     */
    public function getPointsHistory($workerId, $limit = 50) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM om_worker_points 
            WHERE worker_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$workerId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // REWARDS STORE / LOJA DE RECOMPENSAS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Obter itens da loja
     */
    public function getRewardsStore() {
        $stmt = $this->pdo->query("
            SELECT * FROM om_rewards_store 
            WHERE is_active = 1 AND (stock IS NULL OR stock > 0)
            ORDER BY points_required ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Resgatar recompensa
     */
    public function redeemReward($workerId, $rewardId) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_rewards_store WHERE reward_id = ? AND is_active = 1");
        $stmt->execute([$rewardId]);
        $reward = $stmt->fetch();
        
        if (!$reward) return ['success' => false, 'error' => 'Recompensa não encontrada'];
        
        $balance = $this->getPointsBalance($workerId);
        if ($balance < $reward['points_required']) {
            return ['success' => false, 'error' => 'Pontos insuficientes'];
        }
        
        if ($reward['stock'] !== null && $reward['stock'] <= 0) {
            return ['success' => false, 'error' => 'Recompensa esgotada'];
        }
        
        $this->pdo->beginTransaction();
        try {
            // Criar resgate
            $stmt = $this->pdo->prepare("
                INSERT INTO om_reward_redemptions (worker_id, reward_id, points_spent)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$workerId, $rewardId, $reward['points_required']]);
            
            // Descontar pontos
            $this->pdo->prepare("
                UPDATE om_market_workers SET total_points = total_points - ? WHERE worker_id = ?
            ")->execute([$reward['points_required'], $workerId]);
            
            // Descontar estoque
            if ($reward['stock'] !== null) {
                $this->pdo->prepare("UPDATE om_rewards_store SET stock = stock - 1 WHERE reward_id = ?")
                          ->execute([$rewardId]);
            }
            
            // Se for cash, creditar
            if ($reward['reward_type'] === 'cash') {
                $this->pdo->prepare("
                    UPDATE om_market_workers SET available_balance = available_balance + ? WHERE worker_id = ?
                ")->execute([$reward['reward_value'], $workerId]);
            }
            
            $this->pdo->commit();
            return ['success' => true, 'reward' => $reward];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // STREAKS / SEQUÊNCIAS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Atualizar streak do worker
     */
    public function updateStreak($workerId) {
        // Verificar se fez entrega hoje e ontem
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT DATE(completed_at)) as days
            FROM om_market_orders 
            WHERE (shopper_id = ? OR delivery_id = ?) 
              AND status = 'delivered'
              AND completed_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
            ORDER BY completed_at DESC
        ");
        $stmt->execute([$workerId, $workerId]);
        
        // Lógica simplificada - verificar dias consecutivos
        $stmt = $this->pdo->prepare("
            SELECT current_streak, best_streak FROM om_market_workers WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        
        // Verificar se entregou ontem
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM om_market_orders 
            WHERE (shopper_id = ? OR delivery_id = ?) 
              AND status = 'delivered'
              AND DATE(completed_at) = DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY)
        ");
        $stmt->execute([$workerId, $workerId]);
        $yesterdayDeliveries = $stmt->fetchColumn();
        
        // Verificar se entregou hoje
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM om_market_orders 
            WHERE (shopper_id = ? OR delivery_id = ?) 
              AND status = 'delivered'
              AND DATE(completed_at) = CURRENT_DATE
        ");
        $stmt->execute([$workerId, $workerId]);
        $todayDeliveries = $stmt->fetchColumn();
        
        $newStreak = $worker['current_streak'];
        
        if ($todayDeliveries > 0) {
            if ($yesterdayDeliveries > 0) {
                $newStreak++;
            } else {
                $newStreak = 1;
            }
        }
        
        $bestStreak = max($worker['best_streak'], $newStreak);
        
        $this->pdo->prepare("
            UPDATE om_market_workers SET current_streak = ?, best_streak = ? WHERE worker_id = ?
        ")->execute([$newStreak, $bestStreak, $workerId]);
        
        return ['current' => $newStreak, 'best' => $bestStreak];
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // REFERRAL / INDICAÇÃO
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Gerar código de indicação
     */
    public function generateReferralCode($workerId) {
        $code = strtoupper(substr(md5($workerId . time()), 0, 8));
        $this->pdo->prepare("UPDATE om_market_workers SET referral_code = ? WHERE worker_id = ?")
                  ->execute([$code, $workerId]);
        return $code;
    }
    
    /**
     * Aplicar código de indicação
     */
    public function applyReferralCode($newWorkerId, $code) {
        $stmt = $this->pdo->prepare("SELECT worker_id FROM om_market_workers WHERE referral_code = ?");
        $stmt->execute([$code]);
        $referrer = $stmt->fetch();
        
        if (!$referrer || $referrer['worker_id'] == $newWorkerId) {
            return false;
        }
        
        // Criar registro de indicação
        $stmt = $this->pdo->prepare("
            INSERT INTO om_referrals (referrer_worker_id, referred_worker_id, referral_code)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$referrer['worker_id'], $newWorkerId, $code]);
        
        // Marcar quem indicou
        $this->pdo->prepare("UPDATE om_market_workers SET referred_by = ? WHERE worker_id = ?")
                  ->execute([$referrer['worker_id'], $newWorkerId]);
        
        return true;
    }
    
    /**
     * Verificar e pagar bônus de indicação
     */
    public function checkReferralBonus($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT r.*, w.total_deliveries
            FROM om_referrals r
            JOIN om_market_workers w ON r.referred_worker_id = w.worker_id
            WHERE r.referred_worker_id = ? AND r.status IN ('pending', 'active')
        ");
        $stmt->execute([$workerId]);
        $referral = $stmt->fetch();
        
        if (!$referral) return null;
        
        if ($referral['status'] === 'pending' && $referral['total_deliveries'] > 0) {
            // Ativou
            $this->pdo->prepare("UPDATE om_referrals SET status = 'active' WHERE referral_id = ?")
                      ->execute([$referral['referral_id']]);
        }
        
        if ($referral['total_deliveries'] >= $referral['required_deliveries'] && $referral['status'] !== 'paid') {
            // Qualificou - pagar bônus
            $this->pdo->beginTransaction();
            try {
                // Bônus para quem indicou
                $this->pdo->prepare("
                    UPDATE om_market_workers SET available_balance = available_balance + ? WHERE worker_id = ?
                ")->execute([$referral['bonus_referrer'], $referral['referrer_worker_id']]);
                
                // Bônus para indicado
                $this->pdo->prepare("
                    UPDATE om_market_workers SET available_balance = available_balance + ? WHERE worker_id = ?
                ")->execute([$referral['bonus_referred'], $workerId]);
                
                // Marcar como pago
                $this->pdo->prepare("UPDATE om_referrals SET status = 'paid', paid_at = NOW() WHERE referral_id = ?")
                          ->execute([$referral['referral_id']]);
                
                $this->pdo->commit();
                return $referral;
            } catch (Exception $e) {
                $this->pdo->rollBack();
            }
        }
        
        return null;
    }
}
PHP;
saveFile('includes/GamificationHelper.php', $gamificationHelper);

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// PARTE 3: EARNINGS HELPER (Peak Pay, Heavy Pay, Tips, Fast Pay)
// ═══════════════════════════════════════════════════════════════════════════════════════════════

$earningsHelper = <<<'PHP'
<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * EARNINGS HELPER - Sistema completo de ganhos
 * Baseado em: DoorDash Peak Pay, Instacart Heavy Pay, iFood/99Food Tips
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class EarningsHelper {
    private $pdo;
    
    // Configurações
    const QUALITY_BONUS_5_STAR = 3.00;      // Bônus por avaliação 5 estrelas
    const TIP_PROTECTION_MAX = 10.00;       // Máximo de proteção de gorjeta
    const WAIT_TIME_THRESHOLD = 10;         // Minutos para começar pagar espera
    const WAIT_TIME_RATE = 0.50;            // R$ por minuto de espera
    const FAST_PAY_FEE = 1.99;              // Taxa de saque rápido
    const FAST_PAY_FREE_TIER = 'gold';      // Tier com saque grátis
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // PEAK PAY / BOOST
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Obter Peak Pay ativo para região
     */
    public function getActivePeakPay($regionId = null, $partnerId = null) {
        $sql = "
            SELECT * FROM om_peak_pay 
            WHERE is_active = 1 
              AND NOW() BETWEEN start_time AND end_time
              AND (max_uses IS NULL OR current_uses < max_uses)
        ";
        $params = [];
        
        if ($regionId) {
            $sql .= " AND (region_id IS NULL OR region_id = ?)";
            $params[] = $regionId;
        }
        if ($partnerId) {
            $sql .= " AND (partner_id IS NULL OR partner_id = ?)";
            $params[] = $partnerId;
        }
        
        $sql .= " ORDER BY bonus_amount DESC LIMIT 1";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Aplicar Peak Pay a uma entrega
     */
    public function applyPeakPay($orderId, $peakId) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_peak_pay WHERE peak_id = ?");
        $stmt->execute([$peakId]);
        $peak = $stmt->fetch();
        
        if (!$peak) return 0;
        
        // Incrementar uso
        $this->pdo->prepare("UPDATE om_peak_pay SET current_uses = current_uses + 1 WHERE peak_id = ?")
                  ->execute([$peakId]);
        
        return $peak['bonus_amount'];
    }
    
    /**
     * Criar novo Peak Pay
     */
    public function createPeakPay($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO om_peak_pay 
            (region_id, partner_id, title, description, bonus_amount, bonus_type, start_time, end_time, day_of_week, min_deliveries, max_uses)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $data['region_id'] ?? null,
            $data['partner_id'] ?? null,
            $data['title'],
            $data['description'] ?? null,
            $data['bonus_amount'],
            $data['bonus_type'] ?? 'fixed',
            $data['start_time'],
            $data['end_time'],
            $data['day_of_week'] ?? null,
            $data['min_deliveries'] ?? 1,
            $data['max_uses'] ?? null
        ]);
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // HEAVY PAY (Instacart)
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Calcular Heavy Pay baseado no peso do pedido
     */
    public function calculateHeavyPay($totalWeightKg, $totalItems = 0) {
        $stmt = $this->pdo->prepare("
            SELECT bonus_amount FROM om_heavy_pay_rules 
            WHERE is_active = 1 
              AND (min_weight_kg <= ? OR (min_items IS NOT NULL AND min_items <= ?))
            ORDER BY bonus_amount DESC LIMIT 1
        ");
        $stmt->execute([$totalWeightKg, $totalItems]);
        return $stmt->fetchColumn() ?: 0;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // TIPS / GORJETAS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Registrar gorjeta
     */
    public function recordTip($orderId, $workerId, $customerId, $amount, $type = 'pre_order') {
        $stmt = $this->pdo->prepare("
            INSERT INTO om_tips (order_id, worker_id, customer_id, amount, tip_type)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE amount = ?, tip_type = ?
        ");
        $stmt->execute([$orderId, $workerId, $customerId, $amount, $type, $amount, $type]);
        
        // Atualizar total de gorjetas do worker
        $this->pdo->prepare("
            UPDATE om_market_workers SET total_tips = total_tips + ? WHERE worker_id = ?
        ")->execute([$amount, $workerId]);
        
        return true;
    }
    
    /**
     * Cliente aumentou gorjeta após entrega
     */
    public function increaseTip($orderId, $newAmount) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_tips WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $tip = $stmt->fetch();
        
        if (!$tip) return false;
        
        $increase = $newAmount - $tip['amount'];
        if ($increase <= 0) return false;
        
        $this->pdo->prepare("
            UPDATE om_tips SET amount = ?, tip_type = 'increased', original_amount = ? WHERE tip_id = ?
        ")->execute([$newAmount, $tip['amount'], $tip['tip_id']]);
        
        // Atualizar total
        $this->pdo->prepare("
            UPDATE om_market_workers SET total_tips = total_tips + ? WHERE worker_id = ?
        ")->execute([$increase, $tip['worker_id']]);
        
        return $increase;
    }
    
    /**
     * Tip Protection - Cobrir gorjeta zerada (Instacart)
     */
    public function applyTipProtection($orderId, $originalTip, $finalTip, $workerId) {
        if ($finalTip >= $originalTip) return 0;
        
        $protectionAmount = min($originalTip - $finalTip, self::TIP_PROTECTION_MAX);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO om_tip_protection (order_id, worker_id, original_tip, final_tip, protection_amount)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$orderId, $workerId, $originalTip, $finalTip, $protectionAmount]);
        
        // Creditar proteção
        $this->pdo->prepare("
            UPDATE om_market_workers SET available_balance = available_balance + ? WHERE worker_id = ?
        ")->execute([$protectionAmount, $workerId]);
        
        return $protectionAmount;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // QUALITY BONUS (Instacart - bônus por 5 estrelas)
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Dar bônus por avaliação 5 estrelas
     */
    public function giveQualityBonus($workerId, $orderId, $rating) {
        if ($rating < 5) return 0;
        
        $bonus = self::QUALITY_BONUS_5_STAR;
        
        $stmt = $this->pdo->prepare("
            INSERT INTO om_quality_bonuses (worker_id, order_id, rating, bonus_amount)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$workerId, $orderId, $rating, $bonus]);
        
        // Creditar
        $this->pdo->prepare("
            UPDATE om_market_workers 
            SET available_balance = available_balance + ?, five_star_count = five_star_count + 1 
            WHERE worker_id = ?
        ")->execute([$bonus, $workerId]);
        
        return $bonus;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // WAIT TIME PAY (iFood / DoorDash)
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Calcular pagamento por tempo de espera
     */
    public function calculateWaitTimePay($orderId, $workerId, $waitType, $waitMinutes) {
        if ($waitMinutes <= self::WAIT_TIME_THRESHOLD) return 0;
        
        $extraMinutes = $waitMinutes - self::WAIT_TIME_THRESHOLD;
        $payment = $extraMinutes * self::WAIT_TIME_RATE;
        
        $stmt = $this->pdo->prepare("
            INSERT INTO om_wait_time_payments (order_id, worker_id, wait_type, wait_minutes, payment_amount)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$orderId, $workerId, $waitType, $waitMinutes, $payment]);
        
        return $payment;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // DAILY GOALS / METAS DIÁRIAS (99Food)
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Obter metas diárias ativas
     */
    public function getDailyGoals() {
        $dayOfWeek = strtolower(date('D'));
        $stmt = $this->pdo->prepare("
            SELECT * FROM om_daily_goals 
            WHERE is_active = 1 
              AND FIND_IN_SET(?, days_active)
              AND CURTIME() BETWEEN valid_from AND valid_until
            ORDER BY guaranteed_amount DESC
        ");
        $stmt->execute([$dayOfWeek]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter progresso do worker nas metas
     */
    public function getWorkerDailyProgress($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT g.*, 
                   COALESCE(p.deliveries_done, 0) as deliveries_done,
                   COALESCE(p.shopping_done, 0) as shopping_done,
                   COALESCE(p.is_completed, 0) as is_completed,
                   COALESCE(p.bonus_paid, 0) as bonus_paid
            FROM om_daily_goals g
            LEFT JOIN om_worker_daily_progress p ON g.goal_id = p.goal_id 
                AND p.worker_id = ? AND p.date = CURRENT_DATE
            WHERE g.is_active = 1
        ");
        $stmt->execute([$workerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Atualizar progresso da meta diária
     */
    public function updateDailyProgress($workerId, $type = 'delivery') {
        $goals = $this->getDailyGoals();
        $completedGoals = [];
        
        foreach ($goals as $goal) {
            // Inserir ou atualizar progresso
            $field = $type === 'delivery' ? 'deliveries_done' : 'shopping_done';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO om_worker_daily_progress (worker_id, goal_id, date, {$field})
                VALUES (?, ?, CURRENT_DATE, 1)
                ON DUPLICATE KEY UPDATE {$field} = {$field} + 1
            ");
            $stmt->execute([$workerId, $goal['goal_id']]);
            
            // Verificar se completou
            $stmt = $this->pdo->prepare("
                SELECT * FROM om_worker_daily_progress 
                WHERE worker_id = ? AND goal_id = ? AND date = CURRENT_DATE
            ");
            $stmt->execute([$workerId, $goal['goal_id']]);
            $progress = $stmt->fetch();
            
            if ($progress && !$progress['is_completed'] &&
                $progress['deliveries_done'] >= $goal['required_deliveries'] &&
                $progress['shopping_done'] >= $goal['required_shopping']) {
                
                // Completou a meta!
                $this->pdo->prepare("
                    UPDATE om_worker_daily_progress 
                    SET is_completed = 1, completed_at = NOW() 
                    WHERE id = ?
                ")->execute([$progress['id']]);
                
                $completedGoals[] = $goal;
            }
        }
        
        return $completedGoals;
    }
    
    /**
     * Pagar bônus de meta diária
     */
    public function payDailyGoalBonus($workerId, $goalId) {
        $stmt = $this->pdo->prepare("
            SELECT p.*, g.guaranteed_amount 
            FROM om_worker_daily_progress p
            JOIN om_daily_goals g ON p.goal_id = g.goal_id
            WHERE p.worker_id = ? AND p.goal_id = ? AND p.date = CURRENT_DATE
              AND p.is_completed = 1 AND p.bonus_paid = 0
        ");
        $stmt->execute([$workerId, $goalId]);
        $progress = $stmt->fetch();
        
        if (!$progress) return false;
        
        $this->pdo->beginTransaction();
        try {
            // Marcar como pago
            $this->pdo->prepare("
                UPDATE om_worker_daily_progress 
                SET bonus_paid = 1, bonus_amount = ? 
                WHERE id = ?
            ")->execute([$progress['guaranteed_amount'], $progress['id']]);
            
            // Creditar
            $this->pdo->prepare("
                UPDATE om_market_workers 
                SET available_balance = available_balance + ? 
                WHERE worker_id = ?
            ")->execute([$progress['guaranteed_amount'], $workerId]);
            
            $this->pdo->commit();
            return $progress['guaranteed_amount'];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // FAST PAY / SAQUE INSTANTÂNEO (DoorDash / iFood / 99Food)
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Verificar se pode fazer Fast Pay
     */
    public function canFastPay($workerId) {
        // Verificar saldo disponível
        $stmt = $this->pdo->prepare("SELECT available_balance, tier_id FROM om_market_workers WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        
        if (!$worker || $worker['available_balance'] < 10) {
            return ['can' => false, 'reason' => 'Saldo mínimo de R$10 necessário'];
        }
        
        // Verificar se já fez hoje
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM om_fast_pay_requests 
            WHERE worker_id = ? AND DATE(requested_at) = CURRENT_DATE AND status != 'failed'
        ");
        $stmt->execute([$workerId]);
        if ($stmt->fetchColumn() > 0) {
            return ['can' => false, 'reason' => 'Limite de 1 saque por dia'];
        }
        
        // Verificar tier para taxa
        $stmt = $this->pdo->prepare("SELECT tier_slug FROM om_worker_tiers WHERE tier_id = ?");
        $stmt->execute([$worker['tier_id']]);
        $tier = $stmt->fetch();
        
        $fee = self::FAST_PAY_FEE;
        if ($tier && in_array($tier['tier_slug'], ['gold', 'platinum', 'diamond'])) {
            $fee = 0; // Grátis para tiers altos
        }
        
        return [
            'can' => true,
            'available' => $worker['available_balance'],
            'fee' => $fee,
            'net' => $worker['available_balance'] - $fee
        ];
    }
    
    /**
     * Solicitar Fast Pay
     */
    public function requestFastPay($workerId, $amount = null, $pixKey = null) {
        $check = $this->canFastPay($workerId);
        if (!$check['can']) {
            return ['success' => false, 'error' => $check['reason']];
        }
        
        $amount = $amount ?: $check['available'];
        if ($amount > $check['available']) {
            return ['success' => false, 'error' => 'Saldo insuficiente'];
        }
        
        $fee = $check['fee'];
        $net = $amount - $fee;
        
        $this->pdo->beginTransaction();
        try {
            // Criar requisição
            $stmt = $this->pdo->prepare("
                INSERT INTO om_fast_pay_requests 
                (worker_id, amount, fee, net_amount, payment_key)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$workerId, $amount, $fee, $net, $pixKey]);
            $requestId = $this->pdo->lastInsertId();
            
            // Debitar saldo
            $this->pdo->prepare("
                UPDATE om_market_workers 
                SET available_balance = available_balance - ?, pending_balance = pending_balance + ?
                WHERE worker_id = ?
            ")->execute([$amount, $net, $workerId]);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'request_id' => $requestId,
                'amount' => $amount,
                'fee' => $fee,
                'net' => $net
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // EARNINGS HISTORY
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Registrar ganhos do dia
     */
    public function recordDailyEarnings($workerId, $data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO om_earnings_history 
            (worker_id, date, base_earnings, tips_earnings, bonus_earnings, peak_pay_earnings,
             challenge_earnings, heavy_pay_earnings, wait_time_earnings, quality_bonus_earnings,
             total_earnings, deliveries_count, shopping_count, hours_online, hours_active, distance_km)
            VALUES (?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            base_earnings = base_earnings + VALUES(base_earnings),
            tips_earnings = tips_earnings + VALUES(tips_earnings),
            bonus_earnings = bonus_earnings + VALUES(bonus_earnings),
            peak_pay_earnings = peak_pay_earnings + VALUES(peak_pay_earnings),
            challenge_earnings = challenge_earnings + VALUES(challenge_earnings),
            heavy_pay_earnings = heavy_pay_earnings + VALUES(heavy_pay_earnings),
            wait_time_earnings = wait_time_earnings + VALUES(wait_time_earnings),
            quality_bonus_earnings = quality_bonus_earnings + VALUES(quality_bonus_earnings),
            total_earnings = total_earnings + VALUES(total_earnings),
            deliveries_count = deliveries_count + VALUES(deliveries_count),
            shopping_count = shopping_count + VALUES(shopping_count),
            hours_online = hours_online + VALUES(hours_online),
            hours_active = hours_active + VALUES(hours_active),
            distance_km = distance_km + VALUES(distance_km)
        ");
        
        return $stmt->execute([
            $workerId,
            $data['base'] ?? 0,
            $data['tips'] ?? 0,
            $data['bonus'] ?? 0,
            $data['peak_pay'] ?? 0,
            $data['challenge'] ?? 0,
            $data['heavy_pay'] ?? 0,
            $data['wait_time'] ?? 0,
            $data['quality_bonus'] ?? 0,
            array_sum($data),
            $data['deliveries'] ?? 0,
            $data['shopping'] ?? 0,
            $data['hours_online'] ?? 0,
            $data['hours_active'] ?? 0,
            $data['distance'] ?? 0
        ]);
    }
    
    /**
     * Obter histórico de ganhos
     */
    public function getEarningsHistory($workerId, $days = 30) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM om_earnings_history 
            WHERE worker_id = ? AND date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
            ORDER BY date DESC
        ");
        $stmt->execute([$workerId, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter resumo de ganhos
     */
    public function getEarningsSummary($workerId, $period = 'week') {
        $interval = $period === 'week' ? 7 : ($period === 'month' ? 30 : 1);
        
        $stmt = $this->pdo->prepare("
            SELECT 
                SUM(total_earnings) as total,
                SUM(base_earnings) as base,
                SUM(tips_earnings) as tips,
                SUM(bonus_earnings) as bonus,
                SUM(peak_pay_earnings) as peak_pay,
                SUM(deliveries_count) as deliveries,
                SUM(shopping_count) as shopping,
                SUM(hours_active) as hours,
                SUM(distance_km) as distance,
                AVG(total_earnings) as daily_avg
            FROM om_earnings_history 
            WHERE worker_id = ? AND date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
        ");
        $stmt->execute([$workerId, $interval]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
PHP;
saveFile('includes/EarningsHelper.php', $earningsHelper);

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// PARTE 4: HOTSPOTS HELPER (DoorDash / Rappi)
// ═══════════════════════════════════════════════════════════════════════════════════════════════

$hotspotsHelper = <<<'PHP'
<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * HOTSPOTS HELPER - Áreas de alta demanda
 * Baseado em: DoorDash Hotspots, Rappi Mapa de Demanda
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class HotspotsHelper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Obter todos os hotspots ativos
     */
    public function getActiveHotspots() {
        $stmt = $this->pdo->query("
            SELECT h.*, 
                   CASE 
                       WHEN h.demand_level = 'very_high' THEN 4
                       WHEN h.demand_level = 'high' THEN 3
                       WHEN h.demand_level = 'medium' THEN 2
                       ELSE 1
                   END as priority
            FROM om_hotspots h
            ORDER BY priority DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter hotspots próximos a uma localização
     */
    public function getNearbyHotspots($lat, $lng, $radiusKm = 5) {
        $stmt = $this->pdo->prepare("
            SELECT h.*,
                   (6371 * acos(cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat)))) AS distance_km
            FROM om_hotspots h
            HAVING distance_km <= ?
            ORDER BY demand_level DESC, distance_km ASC
        ");
        $stmt->execute([$lat, $lng, $lat, $radiusKm]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Atualizar demanda de um hotspot baseado em pedidos
     */
    public function updateHotspotDemand($hotspotId) {
        // Contar pedidos ativos na área
        $stmt = $this->pdo->prepare("
            SELECT h.*, 
                   COUNT(DISTINCT o.order_id) as active_orders,
                   COUNT(DISTINCT w.worker_id) as active_workers
            FROM om_hotspots h
            LEFT JOIN om_market_orders o ON (
                6371 * acos(cos(radians(h.lat)) * cos(radians(o.store_lat)) * cos(radians(o.store_lng) - radians(h.lng)) + sin(radians(h.lat)) * sin(radians(o.store_lat)))
            ) <= (h.radius_meters / 1000) AND o.status IN ('pending', 'accepted', 'shopping')
            LEFT JOIN om_market_workers w ON w.is_online = 1 AND (
                6371 * acos(cos(radians(h.lat)) * cos(radians(w.current_lat)) * cos(radians(w.current_lng) - radians(h.lng)) + sin(radians(h.lat)) * sin(radians(w.current_lat)))
            ) <= (h.radius_meters / 1000)
            WHERE h.hotspot_id = ?
            GROUP BY h.hotspot_id
        ");
        $stmt->execute([$hotspotId]);
        $data = $stmt->fetch();
        
        if (!$data) return false;
        
        // Calcular nível de demanda
        $ratio = $data['active_workers'] > 0 ? $data['active_orders'] / $data['active_workers'] : $data['active_orders'];
        
        $demandLevel = 'low';
        $waitMinutes = 30;
        
        if ($ratio >= 3) {
            $demandLevel = 'very_high';
            $waitMinutes = 5;
        } elseif ($ratio >= 2) {
            $demandLevel = 'high';
            $waitMinutes = 10;
        } elseif ($ratio >= 1) {
            $demandLevel = 'medium';
            $waitMinutes = 15;
        }
        
        $this->pdo->prepare("
            UPDATE om_hotspots 
            SET demand_level = ?, active_orders = ?, active_workers = ?, estimated_wait_minutes = ?
            WHERE hotspot_id = ?
        ")->execute([$demandLevel, $data['active_orders'], $data['active_workers'], $waitMinutes, $hotspotId]);
        
        return $demandLevel;
    }
    
    /**
     * Ativar bônus em hotspot
     */
    public function activateHotspotBonus($hotspotId, $bonusAmount) {
        $this->pdo->prepare("
            UPDATE om_hotspots SET bonus_active = 1, bonus_amount = ? WHERE hotspot_id = ?
        ")->execute([$bonusAmount, $hotspotId]);
        return true;
    }
    
    /**
     * Criar hotspot
     */
    public function createHotspot($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO om_hotspots (name, lat, lng, radius_meters)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['lat'],
            $data['lng'],
            $data['radius'] ?? 500
        ]);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Obter mapa de calor para o app
     */
    public function getHeatmapData() {
        $hotspots = $this->getActiveHotspots();
        
        return array_map(function($h) {
            return [
                'lat' => (float)$h['lat'],
                'lng' => (float)$h['lng'],
                'weight' => $h['priority'],
                'radius' => $h['radius_meters'],
                'demand' => $h['demand_level'],
                'bonus' => $h['bonus_active'] ? $h['bonus_amount'] : 0,
                'wait' => $h['estimated_wait_minutes']
            ];
        }, $hotspots);
    }
}
PHP;
saveFile('includes/HotspotsHelper.php', $hotspotsHelper);

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// PARTE 5: BATCHING HELPER (Instacart Multi-store / Queued Batches)
// ═══════════════════════════════════════════════════════════════════════════════════════════════

$batchingHelper = <<<'PHP'
<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * BATCHING HELPER - Sistema de lotes de pedidos
 * Baseado em: Instacart Multi-store Batching, Queued Batches
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class BatchingHelper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Criar batch de pedidos
     */
    public function createBatch($orderIds, $basePay = null) {
        $this->pdo->beginTransaction();
        
        try {
            // Calcular totais
            $totals = $this->calculateBatchTotals($orderIds);
            
            $basePay = $basePay ?: $this->calculateBasePay($totals);
            $heavyPay = $totals['heavy_pay'];
            $totalTips = $totals['tips'];
            $totalEarnings = $basePay + $heavyPay + $totalTips;
            
            // Criar batch
            $batchCode = 'B' . strtoupper(substr(uniqid(), -8));
            
            $stmt = $this->pdo->prepare("
                INSERT INTO om_batches 
                (batch_code, total_items, total_weight_kg, total_distance_km, estimated_time_minutes,
                 base_pay, heavy_pay, total_tips, total_earnings, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
            ");
            $stmt->execute([
                $batchCode,
                $totals['items'],
                $totals['weight'],
                $totals['distance'],
                $totals['time'],
                $basePay,
                $heavyPay,
                $totalTips,
                $totalEarnings
            ]);
            $batchId = $this->pdo->lastInsertId();
            
            // Adicionar pedidos ao batch
            $seq = 1;
            foreach ($orderIds as $orderId) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO om_batch_orders (batch_id, order_id, sequence)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$batchId, $orderId, $seq++]);
                
                // Atualizar pedido
                $this->pdo->prepare("UPDATE om_market_orders SET batch_id = ? WHERE order_id = ?")
                          ->execute([$batchId, $orderId]);
            }
            
            $this->pdo->commit();
            
            return [
                'batch_id' => $batchId,
                'batch_code' => $batchCode,
                'orders_count' => count($orderIds),
                'total_earnings' => $totalEarnings,
                'totals' => $totals
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }
    
    /**
     * Calcular totais do batch
     */
    private function calculateBatchTotals($orderIds) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        
        $stmt = $this->pdo->prepare("
            SELECT 
                SUM(items_count) as items,
                SUM(total_weight) as weight,
                SUM(distance_km) as distance,
                SUM(estimated_minutes) as time,
                SUM(tip_amount) as tips
            FROM om_market_orders 
            WHERE order_id IN ($placeholders)
        ");
        $stmt->execute($orderIds);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Heavy pay
        require_once __DIR__ . '/EarningsHelper.php';
        $earningsHelper = new EarningsHelper($this->pdo);
        $totals['heavy_pay'] = $earningsHelper->calculateHeavyPay($totals['weight'] ?? 0, $totals['items'] ?? 0);
        
        return $totals;
    }
    
    /**
     * Calcular pagamento base
     */
    private function calculateBasePay($totals) {
        $basePay = 7.00; // Mínimo
        
        // Adicionar por distância
        $basePay += ($totals['distance'] ?? 0) * 1.50;
        
        // Adicionar por tempo estimado
        $basePay += (($totals['time'] ?? 0) / 60) * 5.00;
        
        return max($basePay, 7.00);
    }
    
    /**
     * Obter batches disponíveis para worker
     */
    public function getAvailableBatches($workerId, $lat = null, $lng = null) {
        // Buscar tier do worker para prioridade
        $stmt = $this->pdo->prepare("
            SELECT t.priority_boost, t.tier_level 
            FROM om_market_workers w
            JOIN om_worker_tiers t ON w.tier_id = t.tier_id
            WHERE w.worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $workerTier = $stmt->fetch();
        
        $priorityBoost = $workerTier['priority_boost'] ?? 0;
        
        // Buscar batches disponíveis
        $stmt = $this->pdo->prepare("
            SELECT b.*,
                   GROUP_CONCAT(bo.order_id) as order_ids,
                   COUNT(bo.order_id) as orders_count
            FROM om_batches b
            LEFT JOIN om_batch_orders bo ON b.batch_id = bo.batch_id
            WHERE b.status = 'available' 
              AND (b.expires_at IS NULL OR b.expires_at > DATE_ADD(NOW(), INTERVAL ? SECOND))
            GROUP BY b.batch_id
            ORDER BY b.priority_level DESC, b.total_earnings DESC
            LIMIT 10
        ");
        $stmt->execute([$priorityBoost]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Aceitar batch
     */
    public function acceptBatch($workerId, $batchId) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_batches WHERE batch_id = ? AND status = 'available'");
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch();
        
        if (!$batch) return ['success' => false, 'error' => 'Batch não disponível'];
        
        $this->pdo->beginTransaction();
        try {
            // Atribuir batch
            $this->pdo->prepare("
                UPDATE om_batches SET status = 'assigned', worker_id = ?, assigned_at = NOW() WHERE batch_id = ?
            ")->execute([$workerId, $batchId]);
            
            // Atribuir pedidos
            $this->pdo->prepare("
                UPDATE om_market_orders SET shopper_id = ?, status = 'accepted' 
                WHERE batch_id = ?
            ")->execute([$workerId, $batchId]);
            
            $this->pdo->commit();
            return ['success' => true, 'batch' => $batch];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Queued Batches - Aceitar próximo antes de terminar atual (Instacart)
     */
    public function queueNextBatch($workerId, $batchId) {
        // Verificar se já tem batch na fila
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM om_queued_batches WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'error' => 'Já tem um batch na fila'];
        }
        
        // Verificar se batch está disponível
        $stmt = $this->pdo->prepare("SELECT * FROM om_batches WHERE batch_id = ? AND status = 'available'");
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch();
        
        if (!$batch) return ['success' => false, 'error' => 'Batch não disponível'];
        
        // Adicionar à fila
        $stmt = $this->pdo->prepare("
            INSERT INTO om_queued_batches (worker_id, batch_id, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
        ");
        $stmt->execute([$workerId, $batchId]);
        
        // Reservar batch
        $this->pdo->prepare("UPDATE om_batches SET status = 'reserved' WHERE batch_id = ?")
                  ->execute([$batchId]);
        
        return ['success' => true, 'batch' => $batch];
    }
    
    /**
     * Iniciar batch da fila
     */
    public function startQueuedBatch($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT qb.*, b.* 
            FROM om_queued_batches qb
            JOIN om_batches b ON qb.batch_id = b.batch_id
            WHERE qb.worker_id = ? AND qb.expires_at > NOW()
            ORDER BY qb.queued_at ASC LIMIT 1
        ");
        $stmt->execute([$workerId]);
        $queued = $stmt->fetch();
        
        if (!$queued) return null;
        
        // Remover da fila e aceitar
        $this->pdo->prepare("DELETE FROM om_queued_batches WHERE worker_id = ? AND batch_id = ?")
                  ->execute([$workerId, $queued['batch_id']]);
        
        return $this->acceptBatch($workerId, $queued['batch_id']);
    }
    
    /**
     * Completar batch
     */
    public function completeBatch($batchId) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_batches WHERE batch_id = ?");
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch();
        
        if (!$batch) return false;
        
        $this->pdo->beginTransaction();
        try {
            // Atualizar batch
            $this->pdo->prepare("
                UPDATE om_batches SET status = 'completed', completed_at = NOW() WHERE batch_id = ?
            ")->execute([$batchId]);
            
            // Creditar worker
            $this->pdo->prepare("
                UPDATE om_market_workers 
                SET available_balance = available_balance + ?,
                    total_earnings = total_earnings + ?,
                    total_tips = total_tips + ?
                WHERE worker_id = ?
            ")->execute([
                $batch['total_earnings'],
                $batch['total_earnings'],
                $batch['total_tips'],
                $batch['worker_id']
            ]);
            
            $this->pdo->commit();
            return $batch;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }
}
PHP;
saveFile('includes/BatchingHelper.php', $batchingHelper);

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// PARTE 6: ACCOUNT HEALTH HELPER (iFood Saúde da Conta)
// ═══════════════════════════════════════════════════════════════════════════════════════════════

$accountHealthHelper = <<<'PHP'
<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * ACCOUNT HEALTH HELPER - Saúde da conta do worker
 * Baseado em: iFood Saúde da Conta
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class AccountHealthHelper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Obter saúde da conta
     */
    public function getAccountHealth($workerId) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_account_health WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $health = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$health) {
            // Criar registro
            $this->pdo->prepare("INSERT INTO om_account_health (worker_id) VALUES (?)")->execute([$workerId]);
            return $this->getAccountHealth($workerId);
        }
        
        return $health;
    }
    
    /**
     * Atualizar scores de saúde
     */
    public function updateHealthScores($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT average_rating, acceptance_rate, completion_rate, on_time_rate
            FROM om_market_workers WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        
        if (!$worker) return false;
        
        // Calcular scores (0-100)
        $ratingScore = min(100, ($worker['average_rating'] / 5) * 100);
        $acceptanceScore = $worker['acceptance_rate'];
        $completionScore = $worker['completion_rate'];
        
        // Verificar fraudes (placeholder)
        $fraudScore = 100;
        
        // Score geral
        $overallScore = round(($ratingScore + $acceptanceScore + $completionScore + $fraudScore) / 4);
        
        // Determinar status
        $status = 'good';
        if ($overallScore >= 90) $status = 'excellent';
        elseif ($overallScore >= 70) $status = 'good';
        elseif ($overallScore >= 50) $status = 'attention';
        elseif ($overallScore >= 30) $status = 'warning';
        else $status = 'critical';
        
        $this->pdo->prepare("
            UPDATE om_account_health 
            SET rating_score = ?, acceptance_score = ?, completion_score = ?, 
                fraud_score = ?, overall_score = ?, health_status = ?
            WHERE worker_id = ?
        ")->execute([$ratingScore, $acceptanceScore, $completionScore, $fraudScore, $overallScore, $status, $workerId]);
        
        return [
            'rating_score' => $ratingScore,
            'acceptance_score' => $acceptanceScore,
            'completion_score' => $completionScore,
            'fraud_score' => $fraudScore,
            'overall_score' => $overallScore,
            'status' => $status
        ];
    }
    
    /**
     * Adicionar warning
     */
    public function addWarning($workerId, $reason) {
        $this->pdo->prepare("
            UPDATE om_account_health 
            SET warnings_count = warnings_count + 1, last_warning_at = NOW()
            WHERE worker_id = ?
        ")->execute([$workerId]);
        
        // Criar notificação
        $this->pdo->prepare("
            INSERT INTO om_worker_notifications (worker_id, title, message, type)
            VALUES (?, 'Aviso na sua conta', ?, 'warning')
        ")->execute([$workerId, $reason]);
        
        return true;
    }
    
    /**
     * Verificar risco de desativação
     */
    public function checkDeactivationRisk($workerId) {
        $health = $this->getAccountHealth($workerId);
        
        $risks = [];
        
        if ($health['rating_score'] < 40) {
            $risks[] = ['type' => 'rating', 'message' => 'Avaliação muito baixa', 'severity' => 'high'];
        }
        if ($health['acceptance_score'] < 50) {
            $risks[] = ['type' => 'acceptance', 'message' => 'Taxa de aceite baixa', 'severity' => 'medium'];
        }
        if ($health['completion_score'] < 80) {
            $risks[] = ['type' => 'completion', 'message' => 'Muitos pedidos cancelados', 'severity' => 'high'];
        }
        if ($health['warnings_count'] >= 3) {
            $risks[] = ['type' => 'warnings', 'message' => 'Muitos avisos recebidos', 'severity' => 'critical'];
        }
        
        return [
            'at_risk' => count($risks) > 0,
            'risks' => $risks,
            'overall_score' => $health['overall_score'],
            'status' => $health['health_status']
        ];
    }
}
PHP;
saveFile('includes/AccountHealthHelper.php', $accountHealthHelper);

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// PARTE 7: API ENDPOINTS PARA TODAS AS FUNCIONALIDADES
// ═══════════════════════════════════════════════════════════════════════════════════════════════

$apiGamification = <<<'PHP'
<?php
/**
 * API: Gamificação (Tiers, Challenges, Points, Rewards)
 */
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/GamificationHelper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = getDB();
$gamification = new GamificationHelper($pdo);

switch ($action) {
    case 'get-tier':
        $tier = $gamification->getWorkerTier($workerId);
        $benefits = $gamification->getTierBenefits($tier['tier_id']);
        echo json_encode(['success' => true, 'tier' => $tier, 'benefits' => $benefits]);
        break;
        
    case 'get-challenges':
        $challenges = $gamification->getAvailableChallenges($workerId);
        echo json_encode(['success' => true, 'challenges' => $challenges]);
        break;
        
    case 'join-challenge':
        $challengeId = $_POST['challenge_id'] ?? 0;
        $result = $gamification->joinChallenge($workerId, $challengeId);
        echo json_encode(['success' => $result]);
        break;
        
    case 'claim-reward':
        $challengeId = $_POST['challenge_id'] ?? 0;
        $result = $gamification->claimChallengeReward($workerId, $challengeId);
        echo json_encode(['success' => (bool)$result, 'reward' => $result]);
        break;
        
    case 'get-points':
        $balance = $gamification->getPointsBalance($workerId);
        $history = $gamification->getPointsHistory($workerId, 20);
        echo json_encode(['success' => true, 'balance' => $balance, 'history' => $history]);
        break;
        
    case 'get-rewards-store':
        $rewards = $gamification->getRewardsStore();
        $balance = $gamification->getPointsBalance($workerId);
        echo json_encode(['success' => true, 'rewards' => $rewards, 'balance' => $balance]);
        break;
        
    case 'redeem-reward':
        $rewardId = $_POST['reward_id'] ?? 0;
        $result = $gamification->redeemReward($workerId, $rewardId);
        echo json_encode($result);
        break;
        
    case 'get-referral-code':
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT referral_code FROM om_market_workers WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $code = $stmt->fetchColumn();
        
        if (!$code) {
            $code = $gamification->generateReferralCode($workerId);
        }
        echo json_encode(['success' => true, 'code' => $code]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}
PHP;
saveFile('api/gamification.php', $apiGamification);

$apiEarnings = <<<'PHP'
<?php
/**
 * API: Earnings (Peak Pay, Tips, Fast Pay, Daily Goals)
 */
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/EarningsHelper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = getDB();
$earnings = new EarningsHelper($pdo);

switch ($action) {
    case 'get-peak-pay':
        $regionId = $_GET['region_id'] ?? null;
        $peakPay = $earnings->getActivePeakPay($regionId);
        echo json_encode(['success' => true, 'peak_pay' => $peakPay]);
        break;
        
    case 'get-daily-goals':
        $goals = $earnings->getWorkerDailyProgress($workerId);
        echo json_encode(['success' => true, 'goals' => $goals]);
        break;
        
    case 'claim-daily-goal':
        $goalId = $_POST['goal_id'] ?? 0;
        $bonus = $earnings->payDailyGoalBonus($workerId, $goalId);
        echo json_encode(['success' => (bool)$bonus, 'bonus' => $bonus]);
        break;
        
    case 'can-fast-pay':
        $result = $earnings->canFastPay($workerId);
        echo json_encode(['success' => true, 'data' => $result]);
        break;
        
    case 'request-fast-pay':
        $amount = $_POST['amount'] ?? null;
        $pixKey = $_POST['pix_key'] ?? null;
        $result = $earnings->requestFastPay($workerId, $amount, $pixKey);
        echo json_encode($result);
        break;
        
    case 'get-history':
        $days = $_GET['days'] ?? 30;
        $history = $earnings->getEarningsHistory($workerId, $days);
        echo json_encode(['success' => true, 'history' => $history]);
        break;
        
    case 'get-summary':
        $period = $_GET['period'] ?? 'week';
        $summary = $earnings->getEarningsSummary($workerId, $period);
        echo json_encode(['success' => true, 'summary' => $summary]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}
PHP;
saveFile('api/earnings.php', $apiEarnings);

$apiHotspots = <<<'PHP'
<?php
/**
 * API: Hotspots (Mapa de demanda)
 */
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/HotspotsHelper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$action = $_GET['action'] ?? '';
$pdo = getDB();
$hotspots = new HotspotsHelper($pdo);

switch ($action) {
    case 'get-all':
        $data = $hotspots->getActiveHotspots();
        echo json_encode(['success' => true, 'hotspots' => $data]);
        break;
        
    case 'get-nearby':
        $lat = $_GET['lat'] ?? 0;
        $lng = $_GET['lng'] ?? 0;
        $radius = $_GET['radius'] ?? 5;
        $data = $hotspots->getNearbyHotspots($lat, $lng, $radius);
        echo json_encode(['success' => true, 'hotspots' => $data]);
        break;
        
    case 'get-heatmap':
        $data = $hotspots->getHeatmapData();
        echo json_encode(['success' => true, 'heatmap' => $data]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}
PHP;
saveFile('api/hotspots.php', $apiHotspots);

$apiBatches = <<<'PHP'
<?php
/**
 * API: Batches (Lotes de pedidos)
 */
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/BatchingHelper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = getDB();
$batching = new BatchingHelper($pdo);

switch ($action) {
    case 'get-available':
        $lat = $_GET['lat'] ?? null;
        $lng = $_GET['lng'] ?? null;
        $batches = $batching->getAvailableBatches($workerId, $lat, $lng);
        echo json_encode(['success' => true, 'batches' => $batches]);
        break;
        
    case 'accept':
        $batchId = $_POST['batch_id'] ?? 0;
        $result = $batching->acceptBatch($workerId, $batchId);
        echo json_encode($result);
        break;
        
    case 'queue':
        $batchId = $_POST['batch_id'] ?? 0;
        $result = $batching->queueNextBatch($workerId, $batchId);
        echo json_encode($result);
        break;
        
    case 'start-queued':
        $result = $batching->startQueuedBatch($workerId);
        echo json_encode(['success' => (bool)$result, 'batch' => $result]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}
PHP;
saveFile('api/batches.php', $apiBatches);

$apiAccountHealth = <<<'PHP'
<?php
/**
 * API: Account Health (Saúde da conta)
 */
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/AccountHealthHelper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$action = $_GET['action'] ?? '';
$pdo = getDB();
$health = new AccountHealthHelper($pdo);

switch ($action) {
    case 'get-status':
        $status = $health->getAccountHealth($workerId);
        echo json_encode(['success' => true, 'health' => $status]);
        break;
        
    case 'update':
        $scores = $health->updateHealthScores($workerId);
        echo json_encode(['success' => true, 'scores' => $scores]);
        break;
        
    case 'check-risk':
        $risk = $health->checkDeactivationRisk($workerId);
        echo json_encode(['success' => true, 'risk' => $risk]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}
PHP;
saveFile('api/account-health.php', $apiAccountHealth);

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// RESULTADO DA INSTALAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚀 MEGA Features Instaladas!</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        .header { text-align: center; color: #fff; margin-bottom: 30px; }
        .header h1 { font-size: 2.5rem; margin-bottom: 10px; }
        .header p { opacity: 0.9; }
        .card { background: #fff; border-radius: 20px; padding: 30px; margin-bottom: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .card-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .features-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
        .feature { background: #f8f9fa; padding: 15px; border-radius: 12px; }
        .feature-icon { font-size: 24px; margin-bottom: 8px; }
        .feature-name { font-weight: 600; font-size: 14px; margin-bottom: 4px; }
        .feature-source { font-size: 11px; color: #6c757d; }
        .files-list { max-height: 300px; overflow-y: auto; }
        .file { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #eee; }
        .file:last-child { border-bottom: none; }
        .file-icon { width: 24px; height: 24px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 12px; }
        .file-icon.ok { background: #d4edda; color: #155724; }
        .file-icon.error { background: #f8d7da; color: #721c24; }
        .file-name { flex: 1; font-size: 13px; font-family: monospace; }
        .file-size { font-size: 11px; color: #6c757d; }
        .stats { display: flex; justify-content: center; gap: 40px; margin: 30px 0; }
        .stat { text-align: center; }
        .stat-value { font-size: 2.5rem; font-weight: 800; color: #667eea; }
        .stat-label { font-size: 12px; color: #6c757d; text-transform: uppercase; }
        .next-steps { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: #fff; }
        .next-steps .card-title { color: #fff; }
        .step { display: flex; gap: 15px; margin-bottom: 15px; }
        .step-num { width: 30px; height: 30px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; }
        .step-text { flex: 1; }
        code { background: rgba(0,0,0,0.1); padding: 2px 6px; border-radius: 4px; font-size: 13px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🚀 MEGA Features Instaladas!</h1>
        <p>Baseado em: Rappi • Instacart • DoorDash • iFood • 99Food</p>
    </div>
    
    <div class="stats">
        <div class="stat">
            <div class="stat-value"><?= count($results) ?></div>
            <div class="stat-label">Arquivos Criados</div>
        </div>
        <div class="stat">
            <div class="stat-value">50+</div>
            <div class="stat-label">Funcionalidades</div>
        </div>
        <div class="stat">
            <div class="stat-value">5</div>
            <div class="stat-label">Apps de Referência</div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-title">✨ Funcionalidades Implementadas</div>
        <div class="features-grid">
            <div class="feature">
                <div class="feature-icon">🏆</div>
                <div class="feature-name">Sistema de Tiers</div>
                <div class="feature-source">Instacart Cart Star / iFood Super</div>
            </div>
            <div class="feature">
                <div class="feature-icon">🎯</div>
                <div class="feature-name">Challenges/Desafios</div>
                <div class="feature-source">DoorDash Streaks</div>
            </div>
            <div class="feature">
                <div class="feature-icon">⚡</div>
                <div class="feature-name">Peak Pay/Boost</div>
                <div class="feature-source">DoorDash / Instacart</div>
            </div>
            <div class="feature">
                <div class="feature-icon">🔥</div>
                <div class="feature-name">Hotspots/Demanda</div>
                <div class="feature-source">DoorDash / Rappi</div>
            </div>
            <div class="feature">
                <div class="feature-icon">📦</div>
                <div class="feature-name">Multi-store Batching</div>
                <div class="feature-source">Instacart</div>
            </div>
            <div class="feature">
                <div class="feature-icon">⏭️</div>
                <div class="feature-name">Queued Batches</div>
                <div class="feature-source">Instacart</div>
            </div>
            <div class="feature">
                <div class="feature-icon">🏋️</div>
                <div class="feature-name">Heavy Pay</div>
                <div class="feature-source">Instacart</div>
            </div>
            <div class="feature">
                <div class="feature-icon">💰</div>
                <div class="feature-name">Metas Diárias R$250</div>
                <div class="feature-source">99Food</div>
            </div>
            <div class="feature">
                <div class="feature-icon">💵</div>
                <div class="feature-name">Fast Pay/Saque</div>
                <div class="feature-source">DoorDash / iFood</div>
            </div>
            <div class="feature">
                <div class="feature-icon">💝</div>
                <div class="feature-name">Tips 100%</div>
                <div class="feature-source">Todos</div>
            </div>
            <div class="feature">
                <div class="feature-icon">🛡️</div>
                <div class="feature-name">Tip Protection</div>
                <div class="feature-source">Instacart</div>
            </div>
            <div class="feature">
                <div class="feature-icon">⭐</div>
                <div class="feature-name">Quality Bonus 5★</div>
                <div class="feature-source">Instacart</div>
            </div>
            <div class="feature">
                <div class="feature-icon">⏱️</div>
                <div class="feature-name">Wait Time Pay</div>
                <div class="feature-source">iFood / DoorDash</div>
            </div>
            <div class="feature">
                <div class="feature-icon">🎁</div>
                <div class="feature-name">Loja de Pontos</div>
                <div class="feature-source">iFood</div>
            </div>
            <div class="feature">
                <div class="feature-icon">👥</div>
                <div class="feature-name">Programa Indicação</div>
                <div class="feature-source">Todos</div>
            </div>
            <div class="feature">
                <div class="feature-icon">❤️</div>
                <div class="feature-name">Saúde da Conta</div>
                <div class="feature-source">iFood</div>
            </div>
            <div class="feature">
                <div class="feature-icon">🗺️</div>
                <div class="feature-name">Escolha Destino</div>
                <div class="feature-source">iFood</div>
            </div>
            <div class="feature">
                <div class="feature-icon">📊</div>
                <div class="feature-name">Histórico Detalhado</div>
                <div class="feature-source">Todos</div>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-title">📁 Arquivos Criados</div>
        <div class="files-list">
            <?php foreach ($results as $r): ?>
            <div class="file">
                <div class="file-icon <?= $r['status'] ?>"><?= $r['status'] === 'ok' ? '✓' : '✕' ?></div>
                <div class="file-name"><?= $r['file'] ?></div>
                <?php if (isset($r['size'])): ?>
                <div class="file-size"><?= number_format($r['size'] / 1024, 1) ?> KB</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="card next-steps">
        <div class="card-title">🎯 Próximos Passos</div>
        <div class="step">
            <div class="step-num">1</div>
            <div class="step-text">Execute o SQL: <code>database/mega_schema.sql</code> no phpMyAdmin</div>
        </div>
        <div class="step">
            <div class="step-num">2</div>
            <div class="step-text">Copie os arquivos <code>includes/*.php</code> para seu projeto</div>
        </div>
        <div class="step">
            <div class="step-num">3</div>
            <div class="step-text">Copie as APIs <code>api/*.php</code> para seu projeto</div>
        </div>
        <div class="step">
            <div class="step-num">4</div>
            <div class="step-text">Configure os hotspots para sua região</div>
        </div>
        <div class="step">
            <div class="step-num">5</div>
            <div class="step-text">Crie os primeiros Peak Pay e Challenges no admin</div>
        </div>
    </div>
</div>
</body>
</html>
