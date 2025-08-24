-- Migration: add theme column to users for storing per-user theme preference
ALTER TABLE users ADD COLUMN IF NOT EXISTS theme VARCHAR(16) DEFAULT NULL;

-- You can run this on your MySQL server (use phpMyAdmin or mysql CLI)
-- Example:
-- mysql -u root -p rota_app_db < migrations/20250824_add_user_theme.sql
