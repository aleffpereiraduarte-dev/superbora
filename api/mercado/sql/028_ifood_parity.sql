-- Migration 028: iFood feature parity
-- Extras/addons, ratings separados, store types, account deletion, scheduled orders

-- ═══════════════════════════════════════════
-- 1. PRODUCT EXTRAS / ADDONS (complementos)
-- ═══════════════════════════════════════════

-- Grupo de extras (ex: "Tamanho", "Extras", "Molho")
CREATE TABLE IF NOT EXISTS om_product_extra_groups (
    group_id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL,
    product_id INT, -- NULL = aplica a todos do parceiro
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    min_select INT DEFAULT 0,       -- minimo obrigatorio (0 = opcional)
    max_select INT DEFAULT 1,       -- maximo selecionavel (1 = radio, N = checkbox)
    sort_order INT DEFAULT 0,
    is_active SMALLINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_extra_groups_partner ON om_product_extra_groups(partner_id);
CREATE INDEX IF NOT EXISTS idx_extra_groups_product ON om_product_extra_groups(product_id);

-- Opcoes dentro do grupo (ex: "Bacon +R$3", "Tamanho G +R$5")
CREATE TABLE IF NOT EXISTS om_product_extra_options (
    option_id SERIAL PRIMARY KEY,
    group_id INT NOT NULL REFERENCES om_product_extra_groups(group_id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    price DECIMAL(10,2) DEFAULT 0,  -- preco adicional
    sort_order INT DEFAULT 0,
    is_active SMALLINT DEFAULT 1,
    max_quantity INT DEFAULT 1      -- max desse item (ex: 3x bacon)
);
CREATE INDEX IF NOT EXISTS idx_extra_options_group ON om_product_extra_options(group_id);

-- Extras selecionados no item do carrinho
CREATE TABLE IF NOT EXISTS om_cart_item_extras (
    id SERIAL PRIMARY KEY,
    cart_id INT NOT NULL,
    group_id INT NOT NULL,
    option_id INT NOT NULL REFERENCES om_product_extra_options(option_id),
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2) DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_cart_extras_cart ON om_cart_item_extras(cart_id);

-- Extras no pedido finalizado
CREATE TABLE IF NOT EXISTS om_order_item_extras (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL,
    order_item_id INT,
    product_id INT NOT NULL,
    group_name VARCHAR(100),
    option_name VARCHAR(100),
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2) DEFAULT 0,
    total_price DECIMAL(10,2) DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_order_extras_order ON om_order_item_extras(order_id);

-- ═══════════════════════════════════════════
-- 2. RATINGS SEPARADOS (comida, entrega, embalagem)
-- ═══════════════════════════════════════════
ALTER TABLE om_market_ratings ADD COLUMN IF NOT EXISTS rating_food SMALLINT;
ALTER TABLE om_market_ratings ADD COLUMN IF NOT EXISTS rating_delivery SMALLINT;
ALTER TABLE om_market_ratings ADD COLUMN IF NOT EXISTS rating_packaging SMALLINT;
ALTER TABLE om_market_ratings ADD COLUMN IF NOT EXISTS photos TEXT; -- JSON array of photo URLs

-- ═══════════════════════════════════════════
-- 3. CPF NA NOTA + OBSERVACAO POR ITEM
-- ═══════════════════════════════════════════
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS cpf_nota VARCHAR(14);
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS cancel_category VARCHAR(50);

-- Observacao por item no carrinho
ALTER TABLE om_market_cart ADD COLUMN IF NOT EXISTS observation TEXT;

-- ═══════════════════════════════════════════
-- 4. STORE TYPES (categorias de loja)
-- ═══════════════════════════════════════════
ALTER TABLE om_market_partners ADD COLUMN IF NOT EXISTS store_type VARCHAR(30) DEFAULT 'restaurante';
-- store_type: restaurante, mercado, farmacia, bebidas, pet, conveniencia

-- Horarios por dia da semana (JSON) - weekly_hours ja existe como text
-- Vamos garantir que funciona como JSON
-- Ex: {"seg":{"abre":"08:00","fecha":"22:00"},"ter":{...},"dom":null}

-- ═══════════════════════════════════════════
-- 5. ACCOUNT DELETION (LGPD)
-- ═══════════════════════════════════════════
CREATE TABLE IF NOT EXISTS om_account_deletions (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    reason VARCHAR(255),
    email_backup VARCHAR(255),
    phone_backup VARCHAR(20),
    requested_at TIMESTAMP DEFAULT NOW(),
    completed_at TIMESTAMP,
    status VARCHAR(20) DEFAULT 'pending' -- pending, processing, completed
);

-- ═══════════════════════════════════════════
-- 6. SCHEDULED ORDERS improvements
-- ═══════════════════════════════════════════
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS scheduled_reminder_sent SMALLINT DEFAULT 0;

-- ═══════════════════════════════════════════
-- 7. DRIVER / SHOPPER verification docs
-- ═══════════════════════════════════════════
CREATE TABLE IF NOT EXISTS om_shopper_documents (
    id SERIAL PRIMARY KEY,
    shopper_id INT NOT NULL,
    doc_type VARCHAR(30) NOT NULL, -- cnh, rg, selfie, antecedentes, veiculo
    file_url VARCHAR(500) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending', -- pending, approved, rejected
    reviewed_by INT,
    reviewed_at TIMESTAMP,
    rejection_reason VARCHAR(255),
    uploaded_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_shopper_docs ON om_shopper_documents(shopper_id);

-- ═══════════════════════════════════════════
-- 8. RECURRING ORDERS
-- ═══════════════════════════════════════════
CREATE TABLE IF NOT EXISTS om_recurring_orders (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    partner_id INT NOT NULL,
    frequency VARCHAR(20) NOT NULL, -- weekly, biweekly, monthly
    day_of_week INT, -- 0=dom ... 6=sab
    preferred_time VARCHAR(10),
    items JSONB NOT NULL, -- [{product_id, quantity, extras}]
    address_id INT,
    payment_method VARCHAR(30),
    is_active SMALLINT DEFAULT 1,
    last_order_at TIMESTAMP,
    next_order_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_recurring_customer ON om_recurring_orders(customer_id);
