-- Shift Notes Database Schema
-- Allows employees to leave notes for handover between shifts

CREATE TABLE IF NOT EXISTS shift_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_id INT NOT NULL,
    created_by INT NOT NULL,
    note TEXT NOT NULL,
    is_important TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_shift_id (shift_id),
    INDEX idx_created_by (created_by),
    INDEX idx_important (is_important),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add sample data (optional - for testing)
-- INSERT INTO shift_notes (shift_id, created_by, note, is_important) 
-- SELECT id, user_id, 'Remember to check the inventory before closing', 0 
-- FROM shifts 
-- WHERE shift_date >= CURDATE() 
-- LIMIT 1;
