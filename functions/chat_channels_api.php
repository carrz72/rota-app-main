<?php
/**
 * Chat Channels API - Manage chat channels
 * Handles: creating channels, joining, leaving, member management
 */

session_start();
require_once '../includes/db.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'employee';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

header('Content-Type: application/json');

try {
    switch ($action) {

        // Get user's channels
        case 'get_channels':
            $query = "
                SELECT 
                    c.id, c.name, c.description, c.type, c.branch_id, c.role_id,
                    c.created_at, c.updated_at,
                    cm.is_muted, cm.is_admin as is_channel_admin, cm.last_read_message_id,
                    (SELECT COUNT(*) FROM chat_messages m 
                     WHERE m.channel_id = c.id 
                     AND m.id > COALESCE(cm.last_read_message_id, 0)
                     AND m.user_id != ? 
                     AND m.is_deleted = 0) as unread_count,
                    (SELECT m2.message FROM chat_messages m2 
                     WHERE m2.channel_id = c.id AND m2.is_deleted = 0 
                     ORDER BY m2.created_at DESC LIMIT 1) as last_message,
                    (SELECT m3.created_at FROM chat_messages m3 
                     WHERE m3.channel_id = c.id AND m3.is_deleted = 0 
                     ORDER BY m3.created_at DESC LIMIT 1) as last_message_at,
                    (SELECT COUNT(DISTINCT cm2.user_id) FROM chat_members cm2 
                     WHERE cm2.channel_id = c.id) as member_count
                FROM chat_channels c
                INNER JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = ?
                WHERE c.is_active = 1
                ORDER BY last_message_at DESC, c.name ASC
            ";

            $stmt = $conn->prepare($query);
            $stmt->execute([$user_id, $user_id]);
            $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'channels' => $channels]);
            break;

        // Create a new channel
        case 'create_channel':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $type = $_POST['type'] ?? 'general';
            $branch_id = (int) ($_POST['branch_id'] ?? 0) ?: null;
            $role_id = (int) ($_POST['role_id'] ?? 0) ?: null;

            // Validation
            if (empty($name)) {
                throw new Exception('Channel name required');
            }

            if (!in_array($type, ['general', 'branch', 'role', 'direct'])) {
                throw new Exception('Invalid channel type');
            }

            // Only admins can create non-direct channels
            if ($type !== 'direct' && !in_array($user_role, ['admin', 'super_admin'])) {
                throw new Exception('Only admins can create channels');
            }

            // Create channel
            $insertStmt = $conn->prepare("
                INSERT INTO chat_channels (name, description, type, branch_id, role_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([$name, $description, $type, $branch_id, $role_id, $user_id]);
            $channel_id = $conn->lastInsertId();

            // Add creator as admin member
            $memberStmt = $conn->prepare("
                INSERT INTO chat_members (channel_id, user_id, is_admin)
                VALUES (?, ?, 1)
            ");
            $memberStmt->execute([$channel_id, $user_id]);

            // Auto-add members based on type
            if ($type === 'branch' && $branch_id) {
                $autoAddStmt = $conn->prepare("
                    INSERT INTO chat_members (channel_id, user_id, is_admin)
                    SELECT ?, id, IF(role IN ('admin', 'super_admin'), 1, 0)
                    FROM users 
                    WHERE branch_id = ?
                    ON DUPLICATE KEY UPDATE channel_id=channel_id
                ");
                $autoAddStmt->execute([$channel_id, $branch_id]);
            }

            if ($type === 'role' && $role_id) {
                $autoAddStmt = $conn->prepare("
                    INSERT INTO chat_members (channel_id, user_id)
                    SELECT DISTINCT ?, s.user_id
                    FROM shifts s
                    WHERE s.role_id = ?
                    ON DUPLICATE KEY UPDATE channel_id=channel_id
                ");
                $autoAddStmt->execute([$channel_id, $role_id]);
            }

            echo json_encode(['success' => true, 'channel_id' => $channel_id, 'message' => 'Channel created']);
            break;

        // Create or get direct message channel
        case 'create_direct':
            $target_user_id = (int) ($_POST['target_user_id'] ?? 0);

            if (!$target_user_id || $target_user_id === $user_id) {
                throw new Exception('Invalid target user');
            }

            // Check if direct channel already exists
            $checkStmt = $conn->prepare("
                SELECT c.id
                FROM chat_channels c
                INNER JOIN chat_members cm1 ON cm1.channel_id = c.id AND cm1.user_id = ?
                INNER JOIN chat_members cm2 ON cm2.channel_id = c.id AND cm2.user_id = ?
                WHERE c.type = 'direct'
                AND (SELECT COUNT(*) FROM chat_members WHERE channel_id = c.id) = 2
                LIMIT 1
            ");
            $checkStmt->execute([$user_id, $target_user_id]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                echo json_encode(['success' => true, 'channel_id' => $existing['id'], 'existing' => true]);
                break;
            }

            // Get target user info
            $userStmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
            $userStmt->execute([$target_user_id]);
            $target_user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$target_user) {
                throw new Exception('User not found');
            }

            $my_username = $_SESSION['username'] ?? 'User';
            $channel_name = "{$my_username}, {$target_user['username']}";

            // Create direct channel
            $insertStmt = $conn->prepare("
                INSERT INTO chat_channels (name, type, created_by)
                VALUES (?, 'direct', ?)
            ");
            $insertStmt->execute([$channel_name, $user_id]);
            $channel_id = $conn->lastInsertId();

            // Add both users
            $memberStmt = $conn->prepare("INSERT INTO chat_members (channel_id, user_id) VALUES (?, ?)");
            $memberStmt->execute([$channel_id, $user_id]);
            $memberStmt->execute([$channel_id, $target_user_id]);

            echo json_encode(['success' => true, 'channel_id' => $channel_id, 'existing' => false]);
            break;

        // Join a channel
        case 'join_channel':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);

            if (!$channel_id) {
                throw new Exception('Channel ID required');
            }

            // Check if channel allows joining
            $channelStmt = $conn->prepare("SELECT type FROM chat_channels WHERE id = ? AND is_active = 1");
            $channelStmt->execute([$channel_id]);
            $channel = $channelStmt->fetch(PDO::FETCH_ASSOC);

            if (!$channel) {
                throw new Exception('Channel not found');
            }

            if ($channel['type'] === 'direct') {
                throw new Exception('Cannot join direct message channels');
            }

            // Add member
            $memberStmt = $conn->prepare("
                INSERT INTO chat_members (channel_id, user_id)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE joined_at = NOW()
            ");
            $memberStmt->execute([$channel_id, $user_id]);

            echo json_encode(['success' => true, 'message' => 'Joined channel']);
            break;

        // Leave a channel
        case 'leave_channel':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);

            if (!$channel_id) {
                throw new Exception('Channel ID required');
            }

            // Check if user is the only admin
            $adminCheckStmt = $conn->prepare("
                SELECT COUNT(*) as admin_count 
                FROM chat_members 
                WHERE channel_id = ? AND is_admin = 1
            ");
            $adminCheckStmt->execute([$channel_id]);
            $adminCount = $adminCheckStmt->fetchColumn();

            $isAdminStmt = $conn->prepare("
                SELECT is_admin 
                FROM chat_members 
                WHERE channel_id = ? AND user_id = ?
            ");
            $isAdminStmt->execute([$channel_id, $user_id]);
            $isAdmin = $isAdminStmt->fetchColumn();

            if ($isAdmin && $adminCount === 1) {
                throw new Exception('Cannot leave - you are the only admin. Promote someone else first.');
            }

            // Remove member
            $deleteStmt = $conn->prepare("DELETE FROM chat_members WHERE channel_id = ? AND user_id = ?");
            $deleteStmt->execute([$channel_id, $user_id]);

            echo json_encode(['success' => true, 'message' => 'Left channel']);
            break;

        // Toggle mute
        case 'toggle_mute':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);
            $mute = (int) ($_POST['mute'] ?? 0);

            if (!$channel_id) {
                throw new Exception('Channel ID required');
            }

            $updateStmt = $conn->prepare("
                UPDATE chat_members 
                SET is_muted = ? 
                WHERE channel_id = ? AND user_id = ?
            ");
            $updateStmt->execute([$mute, $channel_id, $user_id]);

            echo json_encode(['success' => true, 'message' => $mute ? 'Channel muted' : 'Channel unmuted']);
            break;

        // Get channel members
        case 'get_members':
            $channel_id = (int) ($_GET['channel_id'] ?? 0);

            if (!$channel_id) {
                throw new Exception('Channel ID required');
            }

            // Verify access
            $accessStmt = $conn->prepare("SELECT id FROM chat_members WHERE channel_id = ? AND user_id = ?");
            $accessStmt->execute([$channel_id, $user_id]);
            if (!$accessStmt->fetch()) {
                throw new Exception('Access denied');
            }

            $membersStmt = $conn->prepare("
                SELECT 
                    u.id, u.username, u.profile_picture, u.role,
                    cm.is_admin, cm.joined_at, cm.last_read_at
                FROM chat_members cm
                INNER JOIN users u ON cm.user_id = u.id
                WHERE cm.channel_id = ?
                ORDER BY cm.is_admin DESC, u.username ASC
            ");
            $membersStmt->execute([$channel_id]);
            $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'members' => $members]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>