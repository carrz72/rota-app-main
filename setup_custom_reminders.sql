-- Add custom shift reminders table
CREATE TABLE IF NOT EXISTS shift_reminder_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    reminder_type ENUM('minutes', 'hours', 'days') NOT NULL DEFAULT 'hours',
    reminder_value INT NOT NULL,
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_enabled (user_id, enabled)
);

-- Add some default custom reminders for existing users (optional)
-- INSERT INTO shift_reminder_preferences (user_id, reminder_type, reminder_value, enabled)
-- SELECT id, 'hours', 2, 1 FROM users WHERE push_notifications_enabled = 1;
