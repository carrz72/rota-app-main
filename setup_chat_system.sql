-- ==========================================
-- Team Communication Hub - Database Schema
-- ==========================================
-- Run this file to create all chat-related tables
-- Command: mysql -u root -p rota_app < setup_chat_system.sql

-- 1. Chat Channels Table
-- Stores different chat channels (branch, role, general, direct messages)
CREATE TABLE IF NOT EXISTS chat_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    type ENUM('branch', 'role', 'general', 'direct') DEFAULT 'general',
    branch_id INT NULL,
    role_id INT NULL,
    created_by INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_type (type),
    INDEX idx_branch (branch_id),
    INDEX idx_role (role_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Chat Messages Table
-- Stores all chat messages with support for files, replies, and editing
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    message_type ENUM('text', 'file', 'system', 'shift_note') DEFAULT 'text',
    file_url VARCHAR(500) NULL,
    file_name VARCHAR(255) NULL,
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

-- 3. Chat Members Table
-- Tracks which users are in which channels and their read status
CREATE TABLE IF NOT EXISTS chat_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    user_id INT NOT NULL,
    last_read_message_id INT NULL,
    last_read_at TIMESTAMP NULL,
    is_muted TINYINT(1) DEFAULT 0,
    is_admin TINYINT(1) DEFAULT 0,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_membership (channel_id, user_id),
    INDEX idx_user_unread (user_id, last_read_at),
    INDEX idx_channel_user (channel_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Shift Notes Table
-- Dedicated table for shift handover notes
CREATE TABLE IF NOT EXISTS shift_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_id INT NOT NULL,
    created_by INT NOT NULL,
    note TEXT NOT NULL,
    is_important TINYINT(1) DEFAULT 0,
    is_private TINYINT(1) DEFAULT 0,
    attachments JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_shift_id (shift_id),
    INDEX idx_important (is_important),
    INDEX idx_created_at (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Message Reactions Table (optional - for emoji reactions)
CREATE TABLE IF NOT EXISTS chat_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    reaction VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_reaction (message_id, user_id, reaction),
    INDEX idx_message_id (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create default channels
-- General channel (everyone can access)
INSERT INTO chat_channels (name, description, type, created_by) 
SELECT 'General', 'General discussion for all team members', 'general', id 
FROM users 
WHERE role IN ('super_admin', 'admin') 
LIMIT 1;

-- Announcements channel (read-only for non-admins)
INSERT INTO chat_channels (name, description, type, created_by) 
SELECT 'Announcements', 'Important announcements from management', 'general', id 
FROM users 
WHERE role IN ('super_admin', 'admin') 
LIMIT 1;

-- Add all active users to default channels
INSERT INTO chat_members (channel_id, user_id, is_admin)
SELECT c.id, u.id, IF(u.role IN ('super_admin', 'admin'), 1, 0)
FROM chat_channels c
CROSS JOIN users u
WHERE c.type = 'general' 
AND u.id IS NOT NULL
ON DUPLICATE KEY UPDATE channel_id=channel_id;

-- Create branch-specific channels for each branch
INSERT INTO chat_channels (name, description, type, branch_id, created_by)
SELECT 
    CONCAT(b.name, ' Team'), 
    CONCAT('Chat for ', b.name, ' branch'),
    'branch',
    b.id,
    (SELECT id FROM users WHERE role IN ('super_admin', 'admin') LIMIT 1)
FROM branches b;

-- Add users to their branch channels
INSERT INTO chat_members (channel_id, user_id, is_admin)
SELECT c.id, u.id, IF(u.role IN ('super_admin', 'admin'), 1, 0)
FROM chat_channels c
INNER JOIN users u ON u.branch_id = c.branch_id
WHERE c.type = 'branch'
ON DUPLICATE KEY UPDATE channel_id=channel_id;

-- Success message
SELECT 'Chat system database setup complete!' as Status,
       (SELECT COUNT(*) FROM chat_channels) as Channels_Created,
       (SELECT COUNT(*) FROM chat_members) as Members_Added;
