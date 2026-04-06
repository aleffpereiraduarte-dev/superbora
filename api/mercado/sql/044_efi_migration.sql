-- Migration: Add EFI payment support columns
-- SuperBora payment migration from Woovi/Pagar.me to EFI (Efi/Gerencianet)

-- Add efi_charge_id column to orders table for card payments via EFI
ALTER TABLE om_market_orders ADD COLUMN IF NOT EXISTS efi_charge_id INTEGER DEFAULT NULL;

-- Add index for EFI charge lookups
CREATE INDEX IF NOT EXISTS idx_orders_efi_charge_id ON om_market_orders(efi_charge_id) WHERE efi_charge_id IS NOT NULL;

-- Add index for payment_id lookups (used for EFI e2eId in PIX)
CREATE INDEX IF NOT EXISTS idx_orders_payment_id ON om_market_orders(payment_id) WHERE payment_id IS NOT NULL AND payment_id != '';
