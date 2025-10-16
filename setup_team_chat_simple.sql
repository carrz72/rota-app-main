-- =====================================================
-- TEAM CHAT FEATURE - DATABASE SCHEMA (Simplified)
-- Created: October 16, 2025
-- =====================================================

-- 1. Chat Channels (Branch/Role/General/Direct Messages)
CREATE TABLE IF NOT EXISTS chat_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('branch', 'role', 'general', 'direct') DEFAULT 'general',
    branch_id INT NULL,
    role_id INT NULL,
    created_by INT NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type_branch (type, branch_id),
    INDEX idx_type_role (type, role_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Chat Messages
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    message_type ENUM('text', 'file', 'system') DEFAULT 'text',
    file_url VARCHAR(255) NULL,
    file_name VARCHAR(255) NULL,
    file_type VARCHAR(50) NULL,
    file_size INT NULL,
    reply_to_id INT NULL,
    is_edited TINYINT(1) DEFAULT 0,
    is_deleted TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_channel_created (channel_id, created_at DESC),
    INDEX idx_user_id (user_id),
    INDEX idx_deleted (is_deleted),
    INDEX idx_channel_user_created (channel_id, user_id, created_at DESC),
    FULLTEXT INDEX idx_message_search (message)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Chat Channel Members
CREATE TABLE IF NOT EXISTS chat_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('member', 'admin', 'owner') DEFAULT 'member',
    last_read_at TIMESTAMP NULL,
    is_muted TINYINT(1) DEFAULT 0,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    left_at TIMESTAMP NULL,
    UNIQUE KEY unique_membership (channel_id, user_id),
    INDEX idx_user_unread (user_id, last_read_at),
    INDEX idx_channel_members (channel_id, left_at),
    INDEX idx_user_channel_read (user_id, channel_id, last_read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Message Reactions
CREATE TABLE IF NOT EXISTS chat_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    emoji VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reaction (message_id, user_id, emoji),
    INDEX idx_message_reactions (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Typing Indicators
CREATE TABLE IF NOT EXISTS chat_typing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    user_id INT NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_typing (channel_id, user_id),
    INDEX idx_channel_typing (channel_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- INITIAL DATA SETUP
-- =====================================================

-- Create default general channel
INSERT IGNORE INTO chat_channels (id, name, type, created_by, description) 
VALUES (1, 'General', 'general', 1, 'Main company-wide chat for all employees');

-- Add all active users to general channel
INSERT IGNORE INTO chat_members (channel_id, user_id, role)
SELECT 
    1,
    u.id,
    CASE 
        WHEN u.role IN ('admin', 'super_admin') THEN 'admin'
        ELSE 'member'
    END
FROM users u
WHERE u.id IS NOT NULL;

SELECT 'Team Chat database schema installed successfully!' as Status;
