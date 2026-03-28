-- Migration 057: Surge pricing rules table
-- Time-based and condition-based dynamic pricing for delivery fees

CREATE TABLE IF NOT EXISTS om_surge_rules (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    hours_start TIME,
    hours_end TIME,
    days VARCHAR(50) DEFAULT 'all',
    condition VARCHAR(50),
    multiplier NUMERIC(3,2) NOT NULL DEFAULT 1.00,
    active BOOLEAN DEFAULT true,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_surge_rules_active ON om_surge_rules(active);

-- Default surge rules (only insert if table is empty)
INSERT INTO om_surge_rules (name, hours_start, hours_end, days, multiplier)
SELECT 'Pico almoco', '11:30'::time, '13:30'::time, 'mon-fri', 1.20
WHERE NOT EXISTS (SELECT 1 FROM om_surge_rules LIMIT 1);

INSERT INTO om_surge_rules (name, hours_start, hours_end, days, multiplier)
SELECT 'Pico jantar', '19:00'::time, '21:00'::time, 'all', 1.30
WHERE NOT EXISTS (SELECT 1 FROM om_surge_rules WHERE name = 'Pico jantar');

INSERT INTO om_surge_rules (name, hours_start, hours_end, days, multiplier)
SELECT 'Fim de semana almoco', '12:00'::time, '14:00'::time, 'sat-sun', 1.15
WHERE NOT EXISTS (SELECT 1 FROM om_surge_rules WHERE name = 'Fim de semana almoco');
