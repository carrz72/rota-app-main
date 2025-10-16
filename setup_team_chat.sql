-- =====================================================
-- TEAM CHAT FEATURE - DATABASE SCHEMA
-- Created: October 16, 2025
-- Purpose: Full team communication system
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
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
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
    FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reply_to_id) REFERENCES chat_messages(id) ON DELETE SET NULL,
    INDEX idx_channel_created (channel_id, created_at DESC),
    INDEX idx_user_id (user_id),
    INDEX idx_deleted (is_deleted),
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
    FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_membership (channel_id, user_id),
    INDEX idx_user_unread (user_id, last_read_at),
    INDEX idx_channel_members (channel_id, left_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Message Reactions (optional but nice to have)
CREATE TABLE IF NOT EXISTS chat_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    emoji VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_reaction (message_id, user_id, emoji),
    INDEX idx_message_reactions (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Typing Indicators (for real-time "user is typing...")
CREATE TABLE IF NOT EXISTS chat_typing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    user_id INT NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_typing (channel_id, user_id),
    INDEX idx_channel_typing (channel_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- INITIAL DATA SETUP
-- =====================================================

-- Create default general channel
INSERT INTO chat_channels (name, type, created_by, description) 
SELECT 'General', 'general', MIN(id), 'Main company-wide chat for all employees'
FROM users 
WHERE role IN ('admin', 'super_admin')
LIMIT 1;

-- Add all active users to general channel
INSERT INTO chat_members (channel_id, user_id, role)
SELECT 
    (SELECT id FROM chat_channels WHERE type = 'general' AND name = 'General' LIMIT 1),
    u.id,
    CASE 
        WHEN u.role IN ('admin', 'super_admin') THEN 'admin'
        ELSE 'member'
    END
FROM users u
WHERE u.id IS NOT NULL;

-- Create branch-specific channels
INSERT INTO chat_channels (name, type, branch_id, created_by, description)
SELECT 
    CONCAT(b.name, ' Team'),
    'branch',
    b.id,
    (SELECT MIN(id) FROM users WHERE role IN ('admin', 'super_admin')),
    CONCAT('Private channel for ', b.name, ' branch team members')
FROM branches b;

-- Add users to their branch channels
INSERT INTO chat_members (channel_id, user_id, role)
SELECT 
    c.id,
    u.id,
    CASE 
        WHEN u.role IN ('admin', 'super_admin') THEN 'admin'
        ELSE 'member'
    END
FROM users u
JOIN branches b ON u.branch_id = b.id
JOIN chat_channels c ON c.branch_id = b.id AND c.type = 'branch'
WHERE u.branch_id IS NOT NULL;

-- Create managers/admin channel
INSERT INTO chat_channels (name, type, created_by, description)
SELECT 
    'Leadership Team',
    'general',
    MIN(id),
    'Private channel for managers and administrators'
FROM users 
WHERE role IN ('admin', 'super_admin')
LIMIT 1;

-- Add admins and managers to leadership channel
INSERT INTO chat_members (channel_id, user_id, role)
SELECT 
    (SELECT id FROM chat_channels WHERE name = 'Leadership Team' LIMIT 1),
    u.id,
    'admin'
FROM users u
WHERE u.role IN ('admin', 'super_admin', 'manager');

-- =====================================================
-- HELPER VIEWS (Optional - for easier queries)
-- =====================================================

-- View for unread message counts per user
CREATE OR REPLACE VIEW user_unread_counts AS
SELECT 
    cm.user_id,
    cm.channel_id,
    c.name as channel_name,
    COUNT(m.id) as unread_count,
    MAX(m.created_at) as last_message_at
FROM chat_members cm
JOIN chat_channels c ON cm.channel_id = c.id
LEFT JOIN chat_messages m ON m.channel_id = cm.channel_id 
    AND m.created_at > COALESCE(cm.last_read_at, '1970-01-01')
    AND m.user_id != cm.user_id
    AND m.is_deleted = 0
WHERE cm.left_at IS NULL
GROUP BY cm.user_id, cm.channel_id, c.name;

-- View for recent channels per user
CREATE OR REPLACE VIEW user_recent_channels AS
SELECT 
    cm.user_id,
    c.id as channel_id,
    c.name as channel_name,
    c.type,
    c.description,
    cm.is_muted,
    COUNT(CASE WHEN m.created_at > COALESCE(cm.last_read_at, '1970-01-01') 
          AND m.user_id != cm.user_id AND m.is_deleted = 0 THEN 1 END) as unread_count,
    MAX(m.created_at) as last_activity,
    (SELECT message FROM chat_messages 
     WHERE channel_id = c.id AND is_deleted = 0 
     ORDER BY created_at DESC LIMIT 1) as last_message
FROM chat_members cm
JOIN chat_channels c ON cm.channel_id = c.id
LEFT JOIN chat_messages m ON m.channel_id = c.id
WHERE cm.left_at IS NULL AND c.is_active = 1
GROUP BY cm.user_id, c.id, c.name, c.type, c.description, cm.is_muted
ORDER BY last_activity DESC;

-- =====================================================
-- UTILITY PROCEDURES
-- =====================================================

-- Procedure to create a direct message channel between two users
DELIMITER //
CREATE PROCEDURE create_direct_channel(
    IN user1_id INT,
    IN user2_id INT,
    OUT channel_id INT
)
BEGIN
    DECLARE existing_channel INT;
    
    -- Check if channel already exists between these users
    SELECT c.id INTO existing_channel
    FROM chat_channels c
    JOIN chat_members cm1 ON cm1.channel_id = c.id AND cm1.user_id = user1_id
    JOIN chat_members cm2 ON cm2.channel_id = c.id AND cm2.user_id = user2_id
    WHERE c.type = 'direct'
    LIMIT 1;
    
    IF existing_channel IS NOT NULL THEN
        SET channel_id = existing_channel;
    ELSE
        -- Create new direct channel
        INSERT INTO chat_channels (name, type, created_by)
        VALUES (CONCAT('DM-', user1_id, '-', user2_id), 'direct', user1_id);
        
        SET channel_id = LAST_INSERT_ID();
        
        -- Add both users as members
        INSERT INTO chat_members (channel_id, user_id, role)
        VALUES 
            (channel_id, user1_id, 'member'),
            (channel_id, user2_id, 'member');
    END IF;
END //
DELIMITER ;

-- Procedure to clean up old typing indicators (run periodically)
DELIMITER //
CREATE PROCEDURE cleanup_old_typing()
BEGIN
    DELETE FROM chat_typing 
    WHERE started_at < DATE_SUB(NOW(), INTERVAL 10 SECOND);
END //
DELIMITER ;

-- =====================================================
-- INDEXES FOR PERFORMANCE
-- =====================================================

-- Additional composite indexes for common queries
ALTER TABLE chat_messages 
ADD INDEX idx_channel_user_created (channel_id, user_id, created_at DESC);

ALTER TABLE chat_members
ADD INDEX idx_user_channel_read (user_id, channel_id, last_read_at);

-- =====================================================
-- COMPLETION MESSAGE
-- =====================================================

SELECT 'Team Chat database schema installed successfully!' as Status,
       (SELECT COUNT(*) FROM chat_channels) as Channels,
       (SELECT COUNT(*) FROM chat_members) as Memberships,
       (SELECT COUNT(DISTINCT user_id) FROM chat_members) as Active_Users;
