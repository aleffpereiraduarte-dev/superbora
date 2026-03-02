-- Multi-branch (Multi-filial) System
-- Allows a parent partner to manage multiple store locations
-- Migration: 036_multi_branch.sql

BEGIN;

-- Branch group (the "chain" or "rede")
CREATE TABLE IF NOT EXISTS om_partner_groups (
    group_id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    owner_partner_id INT NOT NULL, -- the main/parent partner
    logo_url TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_partner_groups_owner ON om_partner_groups(owner_partner_id);

-- Branch membership
CREATE TABLE IF NOT EXISTS om_partner_group_members (
    id SERIAL PRIMARY KEY,
    group_id INT NOT NULL REFERENCES om_partner_groups(group_id),
    partner_id INT NOT NULL,
    role VARCHAR(20) DEFAULT 'branch', -- 'owner', 'branch', 'manager'
    joined_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(group_id, partner_id)
);

CREATE INDEX IF NOT EXISTS idx_group_members_partner ON om_partner_group_members(partner_id);
CREATE INDEX IF NOT EXISTS idx_group_members_group ON om_partner_group_members(group_id);

COMMIT;
