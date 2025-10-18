-- Add notification preferences to users table
ALTER TABLE users 
ADD COLUMN push_notifications_enabled TINYINT(1) DEFAULT 1 AFTER theme,
ADD COLUMN notify_shift_assigned TINYINT(1) DEFAULT 1,
ADD COLUMN notify_shift_updated TINYINT(1) DEFAULT 1,
ADD COLUMN notify_shift_deleted TINYINT(1) DEFAULT 1,
ADD COLUMN notify_shift_invitation TINYINT(1) DEFAULT 1,
ADD COLUMN notify_shift_swap TINYINT(1) DEFAULT 1,
ADD COLUMN shift_reminder_24h TINYINT(1) DEFAULT 1,
ADD COLUMN shift_reminder_1h TINYINT(1) DEFAULT 0;

-- Table to track sent reminders (prevent duplicates)
CREATE TABLE IF NOT EXISTS shift_reminders_sent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    shift_id INT NOT NULL,
    -- Use a varchar so we can store built identifiers like '24h', '1h' and 'custom_{id}'
    reminder_type VARCHAR(64) NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reminder (user_id, shift_id, reminder_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
    INDEX idx_sent_at (sent_at)
);
