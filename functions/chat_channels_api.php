<?php
/**
 * Chat Channels API
 * Handles channel creation, joining, leaving, management
 * Actions: create_channel, join_channel, leave_channel, get_members, 
 *          add_member, remove_member, mute_channel, create_direct_channel
 */

session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        // ===== CREATE CHANNEL =====
        case 'create_channel':
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? 'general';
            $description = trim($_POST['description'] ?? '');
            $branch_id = (int) ($_POST['branch_id'] ?? 0) ?: null;
            $role_id = (int) ($_POST['role_id'] ?? 0) ?: null;

            if (empty($name)) {
                throw new Exception('Channel name is required');
            }

            if (!in_array($type, ['general', 'branch', 'role', 'direct'])) {
                throw new Exception('Invalid channel type');
            }

            // Check if user has permission (only admins can create non-direct channels)
            if ($type !== 'direct' && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
                throw new Exception('Only administrators can create channels');
            }

            // Create channel
            $stmt = $conn->prepare("
                INSERT INTO chat_channels (name, type, description, branch_id, role_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $type, $description, $branch_id, $role_id, $user_id]);
            $channel_id = $conn->lastInsertId();

            // Add creator as owner
            $stmt = $conn->prepare("
                INSERT INTO chat_members (channel_id, user_id, role)
                VALUES (?, ?, 'owner')
            ");
            $stmt->execute([$channel_id, $user_id]);

            echo json_encode([
                'success' => true,
                'channel_id' => $channel_id,
                'message' => 'Channel created successfully'
            ]);
            break;

        // ===== CREATE DIRECT MESSAGE CHANNEL =====
        case 'create_direct_channel':
            $other_user_id = (int) ($_POST['other_user_id'] ?? 0);

            if ($other_user_id <= 0) {
                throw new Exception('Invalid user ID');
            }

            if ($other_user_id == $user_id) {
                throw new Exception('Cannot create direct message with yourself');
            }

            // Check if DM channel already exists between these users
            $stmt = $conn->prepare("
                SELECT c.id
                FROM chat_channels c
                JOIN chat_members cm1 ON cm1.channel_id = c.id AND cm1.user_id = ?
                JOIN chat_members cm2 ON cm2.channel_id = c.id AND cm2.user_id = ?
                WHERE c.type = 'direct'
                LIMIT 1
            ");
            $stmt->execute([$user_id, $other_user_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                echo json_encode([
                    'success' => true,
                    'channel_id' => $existing['id'],
                    'message' => 'Direct message channel already exists'
                ]);
                break;
            }

            // Create new DM channel
            $stmt = $conn->prepare("
                INSERT INTO chat_channels (name, type, created_by)
                VALUES (?, 'direct', ?)
            ");
            $channel_name = "DM-{$user_id}-{$other_user_id}";
            $stmt->execute([$channel_name, $user_id]);
            $channel_id = $conn->lastInsertId();

            // Add both users
            $stmt = $conn->prepare("
                INSERT INTO chat_members (channel_id, user_id, role)
                VALUES (?, ?, 'member'), (?, ?, 'member')
            ");
            $stmt->execute([$channel_id, $user_id, $channel_id, $other_user_id]);

            echo json_encode([
                'success' => true,
                'channel_id' => $channel_id,
                'message' => 'Direct message created'
            ]);
            break;

        // ===== JOIN CHANNEL =====
        case 'join_channel':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);

            if ($channel_id <= 0) {
                throw new Exception('Invalid channel ID');
            }

            // Check if channel exists and is active
            $stmt = $conn->prepare("SELECT id, type FROM chat_channels WHERE id = ? AND is_active = 1");
            $stmt->execute([$channel_id]);
            $channel = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$channel) {
                throw new Exception('Channel not found');
            }

            // Can't join direct channels
            if ($channel['type'] === 'direct') {
                throw new Exception('Cannot join direct message channels');
            }

            // Check if already a member
            $stmt = $conn->prepare("SELECT id FROM chat_members WHERE channel_id = ? AND user_id = ? AND left_at IS NULL");
            $stmt->execute([$channel_id, $user_id]);
            if ($stmt->fetch()) {
                throw new Exception('Already a member of this channel');
            }

            // Join channel
            $stmt = $conn->prepare("
                INSERT INTO chat_members (channel_id, user_id, role)
                VALUES (?, ?, 'member')
                ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$channel_id, $user_id]);

            echo json_encode(['success' => true, 'message' => 'Joined channel successfully']);
            break;

        // ===== LEAVE CHANNEL =====
        case 'leave_channel':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);

            if ($channel_id <= 0) {
                throw new Exception('Invalid channel ID');
            }

            // Update membership to mark as left
            $stmt = $conn->prepare("
                UPDATE chat_members 
                SET left_at = CURRENT_TIMESTAMP 
                WHERE channel_id = ? AND user_id = ?
            ");
            $stmt->execute([$channel_id, $user_id]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('You are not a member of this channel');
            }

            echo json_encode(['success' => true, 'message' => 'Left channel successfully']);
            break;

        // ===== GET CHANNEL MEMBERS =====
        case 'get_members':
            $channel_id = (int) ($_GET['channel_id'] ?? 0);

            if ($channel_id <= 0) {
                throw new Exception('Invalid channel ID');
            }

            // Verify user is member
            $stmt = $conn->prepare("SELECT id FROM chat_members WHERE channel_id = ? AND user_id = ? AND left_at IS NULL");
            $stmt->execute([$channel_id, $user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('You are not a member of this channel');
            }

            // Get members
            $stmt = $conn->prepare("
                SELECT 
                    u.id,
                    u.username,
                    u.role as user_role,
                    cm.role as channel_role,
                    cm.joined_at,
                    cm.is_muted
                FROM chat_members cm
                JOIN users u ON cm.user_id = u.id
                WHERE cm.channel_id = ? AND cm.left_at IS NULL
                ORDER BY cm.role DESC, u.username ASC
            ");
            $stmt->execute([$channel_id]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'members' => $members]);
            break;

        // ===== ADD MEMBER TO CHANNEL (Admin only) =====
        case 'add_member':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);
            $new_user_id = (int) ($_POST['user_id'] ?? 0);

            if ($channel_id <= 0 || $new_user_id <= 0) {
                throw new Exception('Invalid channel or user ID');
            }

            // Verify current user is admin of the channel
            $stmt = $conn->prepare("
                SELECT role FROM chat_members 
                WHERE channel_id = ? AND user_id = ? AND left_at IS NULL
            ");
            $stmt->execute([$channel_id, $user_id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$member || !in_array($member['role'], ['admin', 'owner'])) {
                throw new Exception('You do not have permission to add members');
            }

            // Add new member
            $stmt = $conn->prepare("
                INSERT INTO chat_members (channel_id, user_id, role)
                VALUES (?, ?, 'member')
                ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$channel_id, $new_user_id]);

            echo json_encode(['success' => true, 'message' => 'Member added successfully']);
            break;

        // ===== REMOVE MEMBER FROM CHANNEL (Admin only) =====
        case 'remove_member':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);
            $remove_user_id = (int) ($_POST['user_id'] ?? 0);

            if ($channel_id <= 0 || $remove_user_id <= 0) {
                throw new Exception('Invalid channel or user ID');
            }

            // Verify current user is admin of the channel
            $stmt = $conn->prepare("
                SELECT role FROM chat_members 
                WHERE channel_id = ? AND user_id = ? AND left_at IS NULL
            ");
            $stmt->execute([$channel_id, $user_id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$member || !in_array($member['role'], ['admin', 'owner'])) {
                throw new Exception('You do not have permission to remove members');
            }

            // Can't remove owner
            $stmt = $conn->prepare("SELECT role FROM chat_members WHERE channel_id = ? AND user_id = ?");
            $stmt->execute([$channel_id, $remove_user_id]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($target && $target['role'] === 'owner') {
                throw new Exception('Cannot remove channel owner');
            }

            // Remove member
            $stmt = $conn->prepare("
                UPDATE chat_members 
                SET left_at = CURRENT_TIMESTAMP 
                WHERE channel_id = ? AND user_id = ?
            ");
            $stmt->execute([$channel_id, $remove_user_id]);

            echo json_encode(['success' => true, 'message' => 'Member removed successfully']);
            break;

        // ===== MUTE/UNMUTE CHANNEL =====
        case 'mute_channel':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);

            if ($channel_id <= 0) {
                throw new Exception('Invalid channel ID');
            }

            $stmt = $conn->prepare("
                SELECT is_muted 
                FROM chat_members 
                WHERE channel_id = ? AND user_id = ? AND left_at IS NULL
            ");
            $stmt->execute([$channel_id, $user_id]);
            $membership = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$membership) {
                throw new Exception('You are not a member of this channel');
            }

            $newState = $membership['is_muted'] ? 0 : 1;

            $stmt = $conn->prepare("
                UPDATE chat_members 
                SET is_muted = ? 
                WHERE channel_id = ? AND user_id = ?
            ");
            $stmt->execute([$newState, $channel_id, $user_id]);

            $message = $newState ? 'Channel muted' : 'Channel unmuted';
            echo json_encode([
                'success' => true,
                'message' => $message,
                'is_muted' => (bool) $newState
            ]);
            break;

        // ===== GET AVAILABLE USERS FOR DM =====
        case 'get_users_for_dm':
            $search = trim($_GET['search'] ?? '');

            $sql = "
                SELECT 
                    u.id,
                    u.username,
                    u.role,
                    CASE WHEN dm.channel_id IS NOT NULL THEN 1 ELSE 0 END as has_dm_channel,
                    dm.channel_id
                FROM users u
                LEFT JOIN (
                    SELECT c.id as channel_id, 
                           CASE WHEN cm1.user_id = ? THEN cm2.user_id ELSE cm1.user_id END as other_user_id
                    FROM chat_channels c
                    JOIN chat_members cm1 ON cm1.channel_id = c.id
                    JOIN chat_members cm2 ON cm2.channel_id = c.id AND cm2.user_id != cm1.user_id
                    WHERE c.type = 'direct' AND (cm1.user_id = ? OR cm2.user_id = ?)
                ) dm ON dm.other_user_id = u.id
                WHERE u.id != ?
            ";

            $params = [$user_id, $user_id, $user_id, $user_id];

            if (!empty($search)) {
                $sql .= " AND u.username LIKE ?";
                $params[] = "%{$search}%";
            }

            $sql .= " ORDER BY u.username ASC LIMIT 50";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'users' => $users]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>