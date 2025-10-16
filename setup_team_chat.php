<?php
// Team Chat Database Setup Script
require_once 'includes/db.php';

echo "<h2>Setting up Team Chat Database...</h2>";

try {
    // 1. Chat Channels Table
    $conn->exec("CREATE TABLE IF NOT EXISTS chat_channels (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "<p>✅ Created chat_channels table</p>";

    // 2. Chat Messages Table
    $conn->exec("CREATE TABLE IF NOT EXISTS chat_messages (
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
        INDEX idx_channel_user_created (channel_id, user_id, created_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "<p>✅ Created chat_messages table</p>";

    // 3. Chat Members Table
    $conn->exec("CREATE TABLE IF NOT EXISTS chat_members (
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
        INDEX idx_channel_members (channel_id, left_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "<p>✅ Created chat_members table</p>";

    // 4. Chat Reactions Table
    $conn->exec("CREATE TABLE IF NOT EXISTS chat_reactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        user_id INT NOT NULL,
        emoji VARCHAR(10) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_reaction (message_id, user_id, emoji),
        INDEX idx_message_reactions (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "<p>✅ Created chat_reactions table</p>";

    // 5. Typing Indicators Table
    $conn->exec("CREATE TABLE IF NOT EXISTS chat_typing (
        id INT AUTO_INCREMENT PRIMARY KEY,
        channel_id INT NOT NULL,
        user_id INT NOT NULL,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_typing (channel_id, user_id),
        INDEX idx_channel_typing (channel_id, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "<p>✅ Created chat_typing table</p>";

    // Create General Channel
    $stmt = $conn->prepare("INSERT IGNORE INTO chat_channels (id, name, type, created_by, description) 
                           VALUES (1, 'General', 'general', ?, 'Main company-wide chat for all employees')");
    $stmt->execute([1]);
    echo "<p>✅ Created General channel</p>";

    // Add all users to General channel
    $stmt = $conn->prepare("
        INSERT IGNORE INTO chat_members (channel_id, user_id, role)
        SELECT 1, u.id,
            CASE WHEN u.role IN ('admin', 'super_admin') THEN 'admin' ELSE 'member' END
        FROM users u
    ");
    $stmt->execute();
    echo "<p>✅ Added users to General channel</p>";

    // Get counts
    $channels = $conn->query("SELECT COUNT(*) FROM chat_channels")->fetchColumn();
    $members = $conn->query("SELECT COUNT(*) FROM chat_members")->fetchColumn();
    $users = $conn->query("SELECT COUNT(DISTINCT user_id) FROM chat_members")->fetchColumn();

    echo "<h3 style='color: green;'>✅ Team Chat Setup Complete!</h3>";
    echo "<p><strong>Channels:</strong> $channels</p>";
    echo "<p><strong>Memberships:</strong> $members</p>";
    echo "<p><strong>Active Users:</strong> $users</p>";
    echo "<p><a href='users/chat.php'>Go to Team Chat →</a></p>";

} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>
