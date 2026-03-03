-- Collections system for curated discovery
-- Coleções de produtos e lojas para a vitrine

CREATE TABLE IF NOT EXISTS om_market_colecoes (
    id SERIAL PRIMARY KEY,
    titulo VARCHAR(200) NOT NULL,
    subtitulo VARCHAR(300),
    descricao TEXT,
    slug VARCHAR(200) UNIQUE,
    tipo VARCHAR(20) NOT NULL DEFAULT 'produtos', -- produtos, lojas, categorias
    imagem_url VARCHAR(500),
    cor_fundo VARCHAR(20) DEFAULT '#FFFFFF',
    cor_texto VARCHAR(20) DEFAULT '#000000',
    icone VARCHAR(50),
    posicao INT DEFAULT 0,
    ativo BOOLEAN DEFAULT true,
    destaque BOOLEAN DEFAULT false,
    data_inicio TIMESTAMP,
    data_fim TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS om_market_colecao_items (
    id SERIAL PRIMARY KEY,
    colecao_id INT NOT NULL REFERENCES om_market_colecoes(id) ON DELETE CASCADE,
    item_id INT NOT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'produto', -- produto, loja
    posicao INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_colecoes_ativo ON om_market_colecoes(ativo, posicao);
CREATE INDEX IF NOT EXISTS idx_colecoes_slug ON om_market_colecoes(slug);
CREATE INDEX IF NOT EXISTS idx_colecoes_datas ON om_market_colecoes(data_inicio, data_fim);
CREATE INDEX IF NOT EXISTS idx_colecao_items_colecao ON om_market_colecao_items(colecao_id, posicao);

-- Seed some default collections
INSERT INTO om_market_colecoes (titulo, subtitulo, slug, tipo, icone, cor_fundo, posicao, destaque) VALUES
('Mais Vendidos', 'Os produtos favoritos dos nossos clientes', 'mais-vendidos', 'produtos', 'flame', '#FF6B35', 1, true),
('Ofertas do Dia', 'Descontos imperdíveis só hoje', 'ofertas-do-dia', 'produtos', 'tag', '#E63946', 2, true),
('Mercados Próximos', 'Entrega rápida perto de você', 'mercados-proximos', 'lojas', 'map-pin', '#2A9D8F', 3, true),
('Novidades', 'Produtos recém adicionados', 'novidades', 'produtos', 'sparkles', '#7B2CBF', 4, false),
('Saudável & Fit', 'Opções saudáveis e fitness', 'saudavel-fit', 'produtos', 'heart', '#52B788', 5, false),
('Cafés & Padarias', 'Para o seu café da manhã', 'cafes-padarias', 'lojas', 'coffee', '#BC6C25', 6, false)
ON CONFLICT (slug) DO NOTHING;

-- Scheduled orders table (for agendados screen)
CREATE TABLE IF NOT EXISTS om_market_scheduled_orders (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    store_id INT NOT NULL,
    scheduled_date DATE NOT NULL,
    scheduled_time TIME,
    items JSONB NOT NULL DEFAULT '[]',
    subtotal DECIMAL(10,2) DEFAULT 0,
    delivery_fee DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) DEFAULT 0,
    address_id INT,
    payment_method VARCHAR(50),
    notes TEXT,
    status VARCHAR(20) DEFAULT 'agendado', -- agendado, processando, concluido, cancelado
    recurring_id INT, -- links to recurring order
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS om_market_recurring_orders (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    store_id INT NOT NULL,
    frequency VARCHAR(20) NOT NULL DEFAULT 'semanal', -- diario, semanal, quinzenal, mensal
    day_of_week INT, -- 0=domingo, 6=sabado
    day_of_month INT,
    preferred_time TIME,
    items JSONB NOT NULL DEFAULT '[]',
    address_id INT,
    payment_method VARCHAR(50),
    is_active BOOLEAN DEFAULT true,
    last_generated_at TIMESTAMP,
    next_scheduled_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_scheduled_orders_customer ON om_market_scheduled_orders(customer_id, status);
CREATE INDEX IF NOT EXISTS idx_scheduled_orders_date ON om_market_scheduled_orders(scheduled_date, status);
CREATE INDEX IF NOT EXISTS idx_recurring_orders_customer ON om_market_recurring_orders(customer_id, is_active);
CREATE INDEX IF NOT EXISTS idx_recurring_orders_next ON om_market_recurring_orders(next_scheduled_at, is_active);
