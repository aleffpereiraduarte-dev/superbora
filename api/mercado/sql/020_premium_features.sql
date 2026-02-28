-- =====================================================
-- SuperBora Premium Features Migration
-- Features: Club, Express, Photo Reviews, Favorites,
--           Tips, Bill Split, Corporate, Stories, Recurring
-- =====================================================

-- =====================================================
-- 1. SUPERBORA CLUB (Assinatura Premium)
-- =====================================================

CREATE TABLE IF NOT EXISTS om_subscription_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    billing_cycle ENUM('monthly', 'quarterly', 'yearly') DEFAULT 'monthly',
    benefits JSON,
    -- Benefits JSON: {"free_delivery": true, "cashback_multiplier": 2, "discount_percent": 10, "priority_support": true}
    free_delivery TINYINT(1) DEFAULT 1,
    cashback_multiplier DECIMAL(3,2) DEFAULT 2.00,
    discount_percent DECIMAL(5,2) DEFAULT 10.00,
    priority_support TINYINT(1) DEFAULT 1,
    express_delivery_free TINYINT(1) DEFAULT 0,
    max_orders_month INT DEFAULT NULL,
    trial_days INT DEFAULT 7,
    status ENUM('active', 'inactive') DEFAULT 'active',
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS om_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    plan_id INT NOT NULL,
    status ENUM('trial', 'active', 'paused', 'cancelled', 'expired') DEFAULT 'trial',
    trial_ends_at DATETIME,
    current_period_start DATETIME,
    current_period_end DATETIME,
    cancelled_at DATETIME,
    cancel_reason TEXT,
    payment_method ENUM('pix', 'credit_card', 'boleto') DEFAULT 'credit_card',
    stripe_subscription_id VARCHAR(255),
    auto_renew TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_period_end (current_period_end),
    FOREIGN KEY (plan_id) REFERENCES om_subscription_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS om_subscription_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    payment_method VARCHAR(50),
    payment_reference VARCHAR(255),
    paid_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_subscription (subscription_id),
    INDEX idx_status (status),
    FOREIGN KEY (subscription_id) REFERENCES om_subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default plans
INSERT INTO om_subscription_plans (name, slug, description, price, billing_cycle, free_delivery, cashback_multiplier, discount_percent, priority_support, express_delivery_free, trial_days, sort_order) VALUES
('SuperBora Club', 'club-mensal', 'Frete gratis ilimitado + 2x cashback + 10% desconto', 19.90, 'monthly', 1, 2.00, 10.00, 1, 0, 7, 1),
('SuperBora Club Anual', 'club-anual', 'Todos beneficios + 2 meses gratis + entrega expressa gratis', 199.00, 'yearly', 1, 2.00, 15.00, 1, 1, 14, 2),
('SuperBora Club Empresarial', 'club-empresarial', 'Para empresas - ate 50 funcionarios', 299.00, 'monthly', 1, 1.50, 10.00, 1, 1, 0, 3);

-- =====================================================
-- 2. ENTREGA EXPRESSA
-- =====================================================

CREATE TABLE IF NOT EXISTS om_express_delivery_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT,
    -- NULL = configuracao global
    enabled TINYINT(1) DEFAULT 1,
    express_fee DECIMAL(10,2) DEFAULT 9.90,
    express_time_minutes INT DEFAULT 20,
    -- Tempo maximo prometido
    normal_time_minutes INT DEFAULT 45,
    -- Tempo normal para comparacao
    max_distance_km DECIMAL(5,2) DEFAULT 5.00,
    available_from TIME DEFAULT '10:00:00',
    available_until TIME DEFAULT '22:00:00',
    max_orders_per_hour INT DEFAULT 10,
    -- Limite de pedidos expressos por hora
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_partner (partner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coluna no pedido para marcar como expresso
ALTER TABLE om_market_orders
ADD COLUMN IF NOT EXISTS is_express TINYINT(1) DEFAULT 0 AFTER is_pickup,
ADD COLUMN IF NOT EXISTS express_fee DECIMAL(10,2) DEFAULT 0 AFTER is_express,
ADD COLUMN IF NOT EXISTS express_promised_at DATETIME AFTER express_fee;

-- Config global padrao
INSERT INTO om_express_delivery_config (partner_id, enabled, express_fee, express_time_minutes) VALUES
(NULL, 1, 9.90, 20);

-- =====================================================
-- 3. FOTOS NAS AVALIACOES
-- =====================================================

CREATE TABLE IF NOT EXISTS om_review_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    review_id INT NOT NULL,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    photo_url VARCHAR(500) NOT NULL,
    caption VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    moderation_note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_review (review_id),
    INDEX idx_order (order_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar contador de fotos na tabela de reviews
ALTER TABLE om_market_reviews
ADD COLUMN IF NOT EXISTS photo_count INT DEFAULT 0 AFTER rating;

-- =====================================================
-- 4. FAVORITOS / COLECOES
-- =====================================================

CREATE TABLE IF NOT EXISTS om_favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    name VARCHAR(100) NOT NULL DEFAULT 'Favoritos',
    description VARCHAR(255),
    icon VARCHAR(50) DEFAULT 'heart',
    color VARCHAR(20) DEFAULT '#ef4444',
    is_default TINYINT(1) DEFAULT 0,
    item_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_default (customer_id, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS om_favorite_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    favorite_id INT NOT NULL,
    customer_id INT NOT NULL,
    item_type ENUM('product', 'store', 'combo') NOT NULL,
    item_id INT NOT NULL,
    notes VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_favorite (favorite_id),
    INDEX idx_customer (customer_id),
    INDEX idx_item (item_type, item_id),
    UNIQUE KEY idx_unique_item (favorite_id, item_type, item_id),
    FOREIGN KEY (favorite_id) REFERENCES om_favorites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 5. GORJETA POS-ENTREGA
-- =====================================================

CREATE TABLE IF NOT EXISTS om_post_delivery_tips (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    shopper_id INT,
    driver_id INT,
    amount DECIMAL(10,2) NOT NULL,
    message VARCHAR(255),
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'paid', 'cancelled')),
    paid_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_post_tips_order ON om_post_delivery_tips (order_id);
CREATE INDEX IF NOT EXISTS idx_post_tips_shopper ON om_post_delivery_tips (shopper_id);
CREATE INDEX IF NOT EXISTS idx_post_tips_driver ON om_post_delivery_tips (driver_id);
CREATE INDEX IF NOT EXISTS idx_post_tips_status ON om_post_delivery_tips (status);

-- Flag no pedido para indicar se gorjeta pos foi dada
ALTER TABLE om_market_orders
ADD COLUMN IF NOT EXISTS post_tip_given SMALLINT DEFAULT 0;
ALTER TABLE om_market_orders
ADD COLUMN IF NOT EXISTS post_tip_amount DECIMAL(10,2) DEFAULT 0;

-- =====================================================
-- 6. DIVISAO DE CONTA (Bill Split)
-- =====================================================

CREATE TABLE IF NOT EXISTS om_bill_splits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    created_by INT NOT NULL,
    -- customer_id do criador
    share_code VARCHAR(20) NOT NULL UNIQUE,
    total_amount DECIMAL(10,2) NOT NULL,
    split_type ENUM('equal', 'by_item', 'custom') DEFAULT 'equal',
    status ENUM('pending', 'partial', 'complete', 'cancelled') DEFAULT 'pending',
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_code (share_code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS om_bill_split_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    split_id INT NOT NULL,
    customer_id INT,
    -- NULL se convidado externo
    name VARCHAR(100),
    email VARCHAR(255),
    phone VARCHAR(20),
    amount DECIMAL(10,2) NOT NULL,
    items JSON,
    -- IDs dos itens que esta pessoa paga
    status ENUM('pending', 'paid', 'declined') DEFAULT 'pending',
    payment_reference VARCHAR(255),
    paid_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_split (split_id),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    FOREIGN KEY (split_id) REFERENCES om_bill_splits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 7. CONTA CORPORATIVA
-- =====================================================

CREATE TABLE IF NOT EXISTS om_corporate_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(200) NOT NULL,
    cnpj VARCHAR(20) NOT NULL UNIQUE,
    contact_name VARCHAR(100) NOT NULL,
    contact_email VARCHAR(255) NOT NULL,
    contact_phone VARCHAR(20),
    billing_email VARCHAR(255),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(2),
    cep VARCHAR(10),
    monthly_limit DECIMAL(12,2) DEFAULT 10000.00,
    per_employee_limit DECIMAL(10,2) DEFAULT 50.00,
    per_order_limit DECIMAL(10,2) DEFAULT 100.00,
    allowed_categories JSON,
    -- Categorias permitidas (restaurante, mercado, etc)
    allowed_partners JSON,
    -- IDs de parceiros especificos permitidos
    billing_cycle ENUM('weekly', 'biweekly', 'monthly') DEFAULT 'monthly',
    payment_terms INT DEFAULT 30,
    -- Dias para pagamento
    discount_percent DECIMAL(5,2) DEFAULT 0,
    status ENUM('pending', 'active', 'suspended', 'cancelled') DEFAULT 'pending',
    subscription_plan_id INT,
    -- Pode ter plano especial
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cnpj (cnpj),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS om_corporate_employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    corporate_id INT NOT NULL,
    customer_id INT,
    -- Vinculo com conta de cliente (opcional)
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    department VARCHAR(100),
    employee_id VARCHAR(50),
    -- Matricula
    daily_limit DECIMAL(10,2),
    monthly_limit DECIMAL(10,2),
    current_month_spent DECIMAL(10,2) DEFAULT 0,
    status ENUM('pending', 'active', 'suspended') DEFAULT 'pending',
    invite_token VARCHAR(100),
    invite_sent_at DATETIME,
    joined_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_corporate (corporate_id),
    INDEX idx_customer (customer_id),
    INDEX idx_email (email),
    INDEX idx_status (status),
    UNIQUE KEY idx_corp_email (corporate_id, email),
    FOREIGN KEY (corporate_id) REFERENCES om_corporate_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS om_corporate_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    corporate_id INT NOT NULL,
    employee_id INT NOT NULL,
    order_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'approved', 'invoiced', 'paid') DEFAULT 'pending',
    invoice_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_corporate (corporate_id),
    INDEX idx_employee (employee_id),
    INDEX idx_order (order_id),
    INDEX idx_status (status),
    FOREIGN KEY (corporate_id) REFERENCES om_corporate_accounts(id),
    FOREIGN KEY (employee_id) REFERENCES om_corporate_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS om_corporate_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    corporate_id INT NOT NULL,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    discount DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) NOT NULL,
    order_count INT DEFAULT 0,
    status ENUM('draft', 'sent', 'paid', 'overdue', 'cancelled') DEFAULT 'draft',
    due_date DATE,
    paid_at DATETIME,
    payment_reference VARCHAR(255),
    pdf_url VARCHAR(500),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_corporate (corporate_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    FOREIGN KEY (corporate_id) REFERENCES om_corporate_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link no pedido para conta corporativa
ALTER TABLE om_market_orders
ADD COLUMN IF NOT EXISTS corporate_id INT AFTER customer_id,
ADD COLUMN IF NOT EXISTS corporate_employee_id INT AFTER corporate_id;

-- =====================================================
-- 8. STORIES DE RESTAURANTES
-- =====================================================

CREATE TABLE IF NOT EXISTS om_restaurant_stories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL,
    media_type ENUM('image', 'video') DEFAULT 'image',
    media_url VARCHAR(500) NOT NULL,
    thumbnail_url VARCHAR(500),
    caption VARCHAR(500),
    link_type ENUM('none', 'product', 'category', 'promo', 'external') DEFAULT 'none',
    link_id INT,
    -- product_id, category_id, promo_id
    link_url VARCHAR(500),
    -- Para links externos
    view_count INT DEFAULT 0,
    click_count INT DEFAULT 0,
    expires_at DATETIME NOT NULL,
    status ENUM('active', 'expired', 'deleted') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_partner (partner_id),
    INDEX idx_status (status),
    INDEX idx_expires (expires_at),
    INDEX idx_partner_active (partner_id, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS om_story_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    story_id INT NOT NULL,
    customer_id INT,
    session_id VARCHAR(100),
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_story (story_id),
    INDEX idx_customer (customer_id),
    UNIQUE KEY idx_unique_view (story_id, customer_id),
    FOREIGN KEY (story_id) REFERENCES om_restaurant_stories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 9. PEDIDOS RECORRENTES
-- =====================================================

CREATE TABLE IF NOT EXISTS om_recurring_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    partner_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    -- "Almoco de sexta", "Cafe da manha"
    items JSON NOT NULL,
    -- Array de {product_id, quantity, options}
    address_id INT,
    payment_method VARCHAR(50),
    frequency ENUM('daily', 'weekly', 'biweekly', 'monthly') DEFAULT 'weekly',
    day_of_week TINYINT,
    -- 0=domingo, 1=segunda, etc (para weekly)
    day_of_month TINYINT,
    -- 1-28 (para monthly)
    preferred_time TIME,
    -- Horario preferido de entrega
    last_order_id INT,
    last_order_at DATETIME,
    next_order_at DATETIME,
    total_orders INT DEFAULT 0,
    status ENUM('active', 'paused', 'cancelled') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_partner (partner_id),
    INDEX idx_status (status),
    INDEX idx_next_order (next_order_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link no pedido indicando que veio de pedido recorrente
ALTER TABLE om_market_orders
ADD COLUMN IF NOT EXISTS recurring_order_id INT AFTER corporate_employee_id;

-- =====================================================
-- 10. RECOMENDACOES INTELIGENTES
-- =====================================================

CREATE TABLE IF NOT EXISTS om_smart_recommendations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    recommendation_type ENUM('reorder', 'time_based', 'similar', 'trending', 'personalized') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description VARCHAR(500),
    item_type ENUM('product', 'store', 'order') NOT NULL,
    item_id INT NOT NULL,
    score DECIMAL(5,4) DEFAULT 0,
    -- Relevancia 0-1
    context JSON,
    -- {"day": "friday", "time": "12:00", "weather": "sunny"}
    shown_count INT DEFAULT 0,
    clicked_count INT DEFAULT 0,
    converted_count INT DEFAULT 0,
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_type (recommendation_type),
    INDEX idx_score (customer_id, score DESC),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 11. VERIFICACAO DE IDADE (Alcool)
-- =====================================================

CREATE TABLE IF NOT EXISTS om_age_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    verification_type ENUM('document', 'selfie', 'third_party') NOT NULL,
    document_type VARCHAR(50),
    -- RG, CNH, etc
    document_number VARCHAR(50),
    birth_date DATE,
    verified_at DATETIME,
    expires_at DATETIME,
    status ENUM('pending', 'verified', 'rejected', 'expired') DEFAULT 'pending',
    rejection_reason VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    UNIQUE KEY idx_customer_verified (customer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Flag em produtos que requerem verificacao de idade
ALTER TABLE om_market_products
ADD COLUMN IF NOT EXISTS requires_age_verification TINYINT(1) DEFAULT 0 AFTER status,
ADD COLUMN IF NOT EXISTS min_age INT DEFAULT 18 AFTER requires_age_verification;

-- =====================================================
-- INDICES ADICIONAIS PARA PERFORMANCE
-- =====================================================

-- Index para buscar assinantes ativos
CREATE INDEX IF NOT EXISTS idx_active_subscriptions
ON om_subscriptions(status, current_period_end);

-- Index para buscar pedidos corporativos pendentes
CREATE INDEX IF NOT EXISTS idx_corp_orders_pending
ON om_corporate_orders(corporate_id, status);

-- Index para stories ativos por parceiro
CREATE INDEX IF NOT EXISTS idx_active_stories
ON om_restaurant_stories(partner_id, status, expires_at);

-- =====================================================
-- EVENTOS AGENDADOS
-- =====================================================

-- Evento para expirar stories automaticamente
DROP EVENT IF EXISTS evt_expire_stories;
CREATE EVENT evt_expire_stories
ON SCHEDULE EVERY 1 HOUR
DO
UPDATE om_restaurant_stories
SET status = 'expired'
WHERE status = 'active' AND expires_at < NOW();

-- Evento para processar pedidos recorrentes
DROP EVENT IF EXISTS evt_process_recurring_orders;
CREATE EVENT evt_process_recurring_orders
ON SCHEDULE EVERY 1 HOUR
DO
UPDATE om_recurring_orders
SET next_order_at = CASE
    WHEN frequency = 'daily' THEN DATE_ADD(next_order_at, INTERVAL 1 DAY)
    WHEN frequency = 'weekly' THEN DATE_ADD(next_order_at, INTERVAL 1 WEEK)
    WHEN frequency = 'biweekly' THEN DATE_ADD(next_order_at, INTERVAL 2 WEEK)
    WHEN frequency = 'monthly' THEN DATE_ADD(next_order_at, INTERVAL 1 MONTH)
END
WHERE status = 'active' AND next_order_at < NOW();

-- Evento para resetar gastos mensais de funcionarios corporativos
DROP EVENT IF EXISTS evt_reset_corporate_monthly;
CREATE EVENT evt_reset_corporate_monthly
ON SCHEDULE EVERY 1 MONTH
STARTS CONCAT(DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), '-01 00:00:00')
DO
UPDATE om_corporate_employees SET current_month_spent = 0;
