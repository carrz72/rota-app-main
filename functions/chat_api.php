<?php
/**
 * Team Chat API
 * Handles all chat-related operations
 * Actions: get_channels, get_messages, send_message, edit_message, delete_message, 
 *          mark_read, get_unread_count, search_messages, get_typing, set_typing
 */

session_start();
require_once '../includes/db.php';
require_once '../includes/notifications.php';

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

        // ===== GET USER'S CHANNELS =====
        case 'get_channels':
            $stmt = $conn->prepare("
                SELECT 
                    c.id,
                    c.name,
                    c.type,
                    c.description,
                    c.branch_id,
                    c.role_id,
                    cm.is_muted,
                    cm.last_read_at,
                    COUNT(DISTINCT CASE WHEN m.created_at > COALESCE(cm.last_read_at, '1970-01-01') 
                          AND m.user_id != ? AND m.is_deleted = 0 THEN m.id END) as unread_count,
                    (SELECT MAX(created_at) FROM chat_messages WHERE channel_id = c.id) as last_activity,
                    (SELECT message FROM chat_messages 
                     WHERE channel_id = c.id AND is_deleted = 0 
                     ORDER BY created_at DESC LIMIT 1) as last_message,
                    (SELECT username COLLATE utf8mb4_general_ci FROM users WHERE id = 
                        (SELECT user_id FROM chat_messages 
                         WHERE channel_id = c.id AND is_deleted = 0 
                         ORDER BY created_at DESC LIMIT 1)) as last_sender
                FROM chat_members cm
                JOIN chat_channels c ON cm.channel_id = c.id
                LEFT JOIN chat_messages m ON m.channel_id = c.id
                WHERE cm.user_id = ? AND cm.left_at IS NULL AND c.is_active = 1
                GROUP BY c.id, c.name, c.type, c.description, c.branch_id, c.role_id, cm.is_muted, cm.last_read_at
                ORDER BY c.name ASC
            ");
            $stmt->execute([$user_id, $user_id]);
            $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Sort channels by last activity (do it in PHP to avoid GROUP BY issues)
            usort($channels, function ($a, $b) {
                $timeA = $a['last_activity'] ?? '1970-01-01';
                $timeB = $b['last_activity'] ?? '1970-01-01';

                if ($timeA === $timeB) {
                    return strcmp($a['name'], $b['name']);
                }

                return ($timeB > $timeA) ? 1 : -1;
            });

            // Add direct message display names
            foreach ($channels as &$channel) {
                if ($channel['type'] === 'direct') {
                    // Get the other user in the DM
                    $stmtOther = $conn->prepare("
                        SELECT u.username COLLATE utf8mb4_general_ci as username, u.id
                        FROM chat_members cm
                        JOIN users u ON cm.user_id = u.id
                        WHERE cm.channel_id = ? AND cm.user_id != ?
                        LIMIT 1
                    ");
                    $stmtOther->execute([$channel['id'], $user_id]);
                    $otherUser = $stmtOther->fetch(PDO::FETCH_ASSOC);
                    if ($otherUser) {
                        $channel['name'] = $otherUser['username'];
                        $channel['other_user_id'] = $otherUser['id'];
                    }
                }
            }

            echo json_encode(['success' => true, 'channels' => $channels]);
            break;

        // ===== GET MESSAGES FOR A CHANNEL =====
        case 'get_messages':
            $channel_id = (int) ($_GET['channel_id'] ?? 0);
            $offset = (int) ($_GET['offset'] ?? 0);
            $limit = (int) ($_GET['limit'] ?? 50);

            if ($channel_id <= 0) {
                throw new Exception('Invalid channel ID');
            }

            // Verify user is member of channel
            $stmt = $conn->prepare("SELECT id FROM chat_members WHERE channel_id = ? AND user_id = ? AND left_at IS NULL");
            $stmt->execute([$channel_id, $user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('You are not a member of this channel');
            }

            // Get messages
            $sql = "
                SELECT 
                    m.id,
                    m.user_id,
                    m.message,
                    m.message_type,
                    m.file_url,
                    m.file_name,
                    m.file_type,
                    m.file_size,
                    m.reply_to_id,
                    m.is_edited,
                    m.created_at,
                    m.updated_at,
                    u.username COLLATE utf8mb4_general_ci as sender_name,
                    COALESCE(
                        (
                            SELECT JSON_ARRAYAGG(
                                JSON_OBJECT(
                                    'emoji', cr.emoji COLLATE utf8mb4_general_ci,
                                    'user_id', cr.user_id,
                                    'username', uu.username COLLATE utf8mb4_general_ci
                                )
                            )
                            FROM chat_reactions cr
                            JOIN users uu ON cr.user_id = uu.id
                            WHERE cr.message_id = m.id
                        ),
                        JSON_ARRAY()
                    ) AS reactions_json
                FROM chat_messages m
                JOIN users u ON m.user_id = u.id
                WHERE m.channel_id = ? AND m.is_deleted = 0
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(1, $channel_id, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Process reactions
            foreach ($messages as &$message) {
                $reactionSummary = [];
                $message['reactions'] = [];

                if (!empty($message['reactions_json'])) {
                    $decoded = json_decode($message['reactions_json'], true);
                    if (is_array($decoded)) {
                        $message['reactions'] = $decoded;
                        foreach ($decoded as $reaction) {
                            $emoji = $reaction['emoji'] ?? '';
                            if ($emoji === '') {
                                continue;
                            }
                            if (!isset($reactionSummary[$emoji])) {
                                $reactionSummary[$emoji] = 0;
                            }
                            $reactionSummary[$emoji]++;
                        }
                    }
                }

                $message['reaction_summary'] = $reactionSummary;
                unset($message['reactions_json']);
            }

            echo json_encode(['success' => true, 'messages' => array_reverse($messages)]);
            break;

        // ===== SEND MESSAGE =====
        case 'send_message':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            $reply_to_id = (int) ($_POST['reply_to_id'] ?? 0) ?: null;

            if ($channel_id <= 0) {
                throw new Exception('Invalid channel ID');
            }

            if (empty($message)) {
                throw new Exception('Message cannot be empty');
            }

            // Verify membership
            $stmt = $conn->prepare("SELECT id FROM chat_members WHERE channel_id = ? AND user_id = ? AND left_at IS NULL");
            $stmt->execute([$channel_id, $user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('You are not a member of this channel');
            }

            // Insert message
            $stmt = $conn->prepare("
                INSERT INTO chat_messages (channel_id, user_id, message, message_type, reply_to_id)
                VALUES (?, ?, ?, 'text', ?)
            ");
            $stmt->execute([$channel_id, $user_id, $message, $reply_to_id]);
            $message_id = $conn->lastInsertId();

            // Get channel name and members for notifications
            $stmt = $conn->prepare("SELECT name, type FROM chat_channels WHERE id = ?");
            $stmt->execute([$channel_id]);
            $channel = $stmt->fetch(PDO::FETCH_ASSOC);

            // Send notifications to other members
            $stmt = $conn->prepare("
                SELECT cm.user_id, u.username
                FROM chat_members cm
                JOIN users u ON cm.user_id = u.id
                WHERE cm.channel_id = ? AND cm.user_id != ? AND cm.left_at IS NULL AND cm.is_muted = 0
            ");
            $stmt->execute([$channel_id, $user_id]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmtSender = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $stmtSender->execute([$user_id]);
            $senderRow = $stmtSender->fetch(PDO::FETCH_ASSOC);
            $sender_name = $senderRow['username'] ?? ($_SESSION['username'] ?? 'Someone');
            $message_preview = strlen($message) > 50 ? substr($message, 0, 50) . '...' : $message;

            // Send in-app notification and push notification to other members
            require_once __DIR__ . '/push_notification_helper.php';
            foreach ($members as $member) {
                addNotification(
                    $conn,
                    $member['user_id'],
                    "\uD83D\uDCAC {$sender_name} in {$channel['name']}: {$message_preview}",
                    'chat'
                );
                // Send push notification
                $pushTitle = "New Chat Message";
                $pushBody = "{$sender_name} in {$channel['name']}: {$message_preview}";
                $pushUrl = "/users/chat.php?channel_id={$channel_id}";
                $pushData = [
                    'type' => 'chat',
                    'channel_id' => $channel_id,
                    'sender_id' => $user_id,
                    'sender_name' => $sender_name,
                    'message_preview' => $message_preview
                ];
                sendPushNotification($member['user_id'], $pushTitle, $pushBody, $pushUrl, $pushData);
            }

            echo json_encode(['success' => true, 'message_id' => $message_id, 'message' => 'Message sent']);
            break;

        // ===== EDIT MESSAGE =====
        case 'edit_message':
            $message_id = (int) ($_POST['message_id'] ?? 0);
            $new_message = trim($_POST['message'] ?? '');

            if ($message_id <= 0) {
                throw new Exception('Invalid message ID');
            }

            if (empty($new_message)) {
                throw new Exception('Message cannot be empty');
            }

            // Verify ownership
            $stmt = $conn->prepare("SELECT id FROM chat_messages WHERE id = ? AND user_id = ?");
            $stmt->execute([$message_id, $user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('You can only edit your own messages');
            }

            // Update message
            $stmt = $conn->prepare("
                UPDATE chat_messages 
                SET message = ?, is_edited = 1, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$new_message, $message_id]);

            echo json_encode(['success' => true, 'message' => 'Message updated']);
            break;

        // ===== DELETE MESSAGE =====
        case 'delete_message':
            $message_id = (int) ($_POST['message_id'] ?? 0);

            if ($message_id <= 0) {
                throw new Exception('Invalid message ID');
            }

            // Verify ownership or admin
            $stmt = $conn->prepare("
                SELECT m.id, m.user_id, c.id as channel_id
                FROM chat_messages m
                JOIN chat_channels c ON m.channel_id = c.id
                LEFT JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = ?
                WHERE m.id = ? AND (m.user_id = ? OR cm.role = 'admin')
            ");
            $stmt->execute([$user_id, $message_id, $user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('You can only delete your own messages');
            }

            // Soft delete
            $stmt = $conn->prepare("UPDATE chat_messages SET is_deleted = 1 WHERE id = ?");
            $stmt->execute([$message_id]);

            echo json_encode(['success' => true, 'message' => 'Message deleted']);
            break;

        // ===== MARK CHANNEL AS READ =====
        case 'mark_read':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);

            if ($channel_id <= 0) {
                throw new Exception('Invalid channel ID');
            }

            $stmt = $conn->prepare("
                UPDATE chat_members 
                SET last_read_at = CURRENT_TIMESTAMP 
                WHERE channel_id = ? AND user_id = ?
            ");
            $stmt->execute([$channel_id, $user_id]);

            echo json_encode(['success' => true, 'message' => 'Marked as read']);
            break;

        // ===== GET UNREAD COUNT =====
        case 'get_unread_count':
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT m.id) as total_unread
                FROM chat_members cm
                JOIN chat_messages m ON m.channel_id = cm.channel_id
                WHERE cm.user_id = ? 
                    AND cm.left_at IS NULL
                    AND m.created_at > COALESCE(cm.last_read_at, '1970-01-01')
                    AND m.user_id != ?
                    AND m.is_deleted = 0
                    AND cm.is_muted = 0
            ");
            $stmt->execute([$user_id, $user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'unread_count' => (int) $result['total_unread']]);
            break;

        // ===== SEARCH MESSAGES =====
        case 'search_messages':
            $search = trim($_GET['query'] ?? $_GET['search'] ?? '');
            $channel_id = (int) ($_GET['channel_id'] ?? 0);

            if (empty($search)) {
                throw new Exception('Search query is required');
            }

            $sql = "
                SELECT 
                    m.id,
                    m.channel_id,
                    m.user_id,
                    m.message,
                    m.created_at,
                    u.username as sender_name,
                    c.name as channel_name,
                    c.type as channel_type
                FROM chat_messages m
                JOIN users u ON m.user_id = u.id
                JOIN chat_channels c ON m.channel_id = c.id
                JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = ?
                WHERE m.is_deleted = 0 AND cm.left_at IS NULL
                    AND MATCH(m.message) AGAINST(? IN NATURAL LANGUAGE MODE)
            ";

            $params = [$user_id, $search];

            if ($channel_id > 0) {
                $sql .= " AND m.channel_id = ?";
                $params[] = $channel_id;
            }

            $sql .= " ORDER BY m.created_at DESC LIMIT 50";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'results' => $results]);
            break;

        // ===== SET TYPING INDICATOR =====
        case 'set_typing':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);
            $is_typing = (int) ($_POST['is_typing'] ?? 1);

            if ($channel_id <= 0) {
                throw new Exception('Invalid channel ID');
            }

            if ($is_typing) {
                $stmt = $conn->prepare("
                    INSERT INTO chat_typing (channel_id, user_id, started_at)
                    VALUES (?, ?, CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE started_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$channel_id, $user_id]);
            } else {
                $stmt = $conn->prepare("DELETE FROM chat_typing WHERE channel_id = ? AND user_id = ?");
                $stmt->execute([$channel_id, $user_id]);
            }

            echo json_encode(['success' => true]);
            break;

        // ===== GET TYPING USERS =====
        case 'get_typing':
            $channel_id = (int) ($_GET['channel_id'] ?? 0);

            if ($channel_id <= 0) {
                throw new Exception('Invalid channel ID');
            }

            // Clean up old typing indicators (older than 10 seconds)
            $conn->exec("DELETE FROM chat_typing WHERE started_at < DATE_SUB(NOW(), INTERVAL 10 SECOND)");

            // Get current typing users
            $stmt = $conn->prepare("
                SELECT u.username
                FROM chat_typing ct
                JOIN users u ON ct.user_id = u.id
                WHERE ct.channel_id = ? AND ct.user_id != ?
            ");
            $stmt->execute([$channel_id, $user_id]);
            $typing_users = $stmt->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode(['success' => true, 'typing_users' => $typing_users]);
            break;

        // ===== ADD REACTION TO MESSAGE =====
        case 'add_reaction':
            $message_id = $_POST['message_id'] ?? null;
            $emoji = $_POST['emoji'] ?? null;

            if (!$message_id || !$emoji) {
                throw new Exception('Message ID and emoji are required');
            }

            // Check if user is in the channel
            $stmt = $conn->prepare("
                SELECT cm.channel_id 
                FROM chat_messages m
                JOIN chat_members cm ON m.channel_id = cm.channel_id
                WHERE m.id = ? AND cm.user_id = ?
            ");
            $stmt->execute([$message_id, $user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Not authorized');
            }

            // Add or toggle reaction
            $stmt = $conn->prepare("
                INSERT INTO chat_reactions (message_id, user_id, emoji)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE emoji = VALUES(emoji)
            ");
            $stmt->execute([$message_id, $user_id, $emoji]);

            echo json_encode(['success' => true]);
            break;

        // ===== REMOVE REACTION FROM MESSAGE =====
        case 'remove_reaction':
            $message_id = $_POST['message_id'] ?? null;
            $emoji = $_POST['emoji'] ?? null;

            if (!$message_id || !$emoji) {
                throw new Exception('Message ID and emoji are required');
            }

            $stmt = $conn->prepare("
                DELETE FROM chat_reactions 
                WHERE message_id = ? AND user_id = ? AND emoji = ?
            ");
            $stmt->execute([$message_id, $user_id, $emoji]);

            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>