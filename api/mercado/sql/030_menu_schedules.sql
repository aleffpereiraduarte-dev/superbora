-- 030_menu_schedules.sql
-- Menu schedules: time-based, day-based, and seasonal menus

CREATE TABLE IF NOT EXISTS om_menu_schedules (
    id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    schedule_type VARCHAR(20) DEFAULT 'time_based',
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    days VARCHAR(20) DEFAULT NULL,
    days_of_week VARCHAR(50) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    status SMALLINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_menu_schedules_partner ON om_menu_schedules(partner_id, status);

-- Add columns if they don't exist (for existing installations)
ALTER TABLE om_menu_schedules ADD COLUMN IF NOT EXISTS days_of_week VARCHAR(50) DEFAULT NULL;
ALTER TABLE om_menu_schedules ADD COLUMN IF NOT EXISTS start_date DATE DEFAULT NULL;
ALTER TABLE om_menu_schedules ADD COLUMN IF NOT EXISTS end_date DATE DEFAULT NULL;
ALTER TABLE om_menu_schedules ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW();

CREATE TABLE IF NOT EXISTS om_product_schedule_links (
    id SERIAL PRIMARY KEY,
    schedule_id INT NOT NULL,
    product_id INT NOT NULL,
    UNIQUE(schedule_id, product_id)
);
CREATE INDEX IF NOT EXISTS idx_psl_schedule ON om_product_schedule_links(schedule_id);
