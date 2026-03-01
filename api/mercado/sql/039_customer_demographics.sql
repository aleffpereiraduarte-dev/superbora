-- 039: Add gender and birth_date to om_customers for personalization
ALTER TABLE om_customers ADD COLUMN IF NOT EXISTS gender VARCHAR(20);
ALTER TABLE om_customers ADD COLUMN IF NOT EXISTS birth_date DATE;
