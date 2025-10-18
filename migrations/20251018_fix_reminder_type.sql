-- Migration: change reminder_type ENUM to VARCHAR to support custom_{id} values
-- Run this on the DB: mysql -u root -p rota_app < migrations/20251018_fix_reminder_type.sql

ALTER TABLE shift_reminders_sent
MODIFY COLUMN reminder_type VARCHAR(64) NOT NULL;
