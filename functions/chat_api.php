<?php
/**
 * Chat API - Main endpoint for chat operations
 * Handles: sending messages, retrieving messages, editing, deleting, marking as read
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
$action = $_POST['action'] ?? $_GET['action'] ?? '';

header('Content-Type: application/json');

try {
    switch ($action) {

        // Get messages for a channel
        case 'get_messages':
            $channel_id = (int) ($_GET['channel_id'] ?? 0);
            $limit = min((int) ($_GET['limit'] ?? 50), 100); // Max 100 messages
            $before_id = (int) ($_GET['before_id'] ?? 0); // For pagination

            if (!$channel_id) {
                throw new Exception('Channel ID required');
            }

            // Verify user has access to this channel
            $accessStmt = $conn->prepare("SELECT id FROM chat_members WHERE channel_id = ? AND user_id = ?");
            $accessStmt->execute([$channel_id, $user_id]);
            if (!$accessStmt->fetch()) {
                throw new Exception('Access denied to this channel');
            }

            // Build query
            $query = "
                SELECT 
                    m.id, m.message, m.message_type, m.file_url, m.file_name, m.file_size,
                    m.reply_to_id, m.is_edited, m.created_at,
                    u.id as user_id, u.username, u.profile_picture,
                    reply_m.message as reply_message,
                    reply_u.username as reply_username
                FROM chat_messages m
                INNER JOIN users u ON m.user_id = u.id
                LEFT JOIN chat_messages reply_m ON m.reply_to_id = reply_m.id
                LEFT JOIN users reply_u ON reply_m.user_id = reply_u.id
                WHERE m.channel_id = ? AND m.is_deleted = 0
            ";

            $params = [$channel_id];

            if ($before_id > 0) {
                $query .= " AND m.id < ?";
                $params[] = $before_id;
            }

            $query .= " ORDER BY m.created_at DESC LIMIT ?";
            $params[] = $limit;

            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Reverse order (oldest first)
            $messages = array_reverse($messages);

            echo json_encode(['success' => true, 'messages' => $messages]);
            break;

        // Send a new message
        case 'send_message':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            $reply_to_id = (int) ($_POST['reply_to_id'] ?? 0) ?: null;

            if (!$channel_id) {
                throw new Exception('Channel ID required');
            }

            if (empty($message)) {
                throw new Exception('Message cannot be empty');
            }

            if (strlen($message) > 5000) {
                throw new Exception('Message too long (max 5000 characters)');
            }

            // Verify channel membership
            $memberStmt = $conn->prepare("SELECT id FROM chat_members WHERE channel_id = ? AND user_id = ?");
            $memberStmt->execute([$channel_id, $user_id]);
            if (!$memberStmt->fetch()) {
                throw new Exception('You are not a member of this channel');
            }

            // Insert message
            $insertStmt = $conn->prepare("
                INSERT INTO chat_messages (channel_id, user_id, message, reply_to_id)
                VALUES (?, ?, ?, ?)
            ");
            $insertStmt->execute([$channel_id, $user_id, $message, $reply_to_id]);
            $message_id = $conn->lastInsertId();

            // Update channel timestamp
            $conn->prepare("UPDATE chat_channels SET updated_at = NOW() WHERE id = ?")->execute([$channel_id]);

            // Get the complete message data
            $msgStmt = $conn->prepare("
                SELECT m.*, u.username, u.profile_picture
                FROM chat_messages m
                INNER JOIN users u ON m.user_id = u.id
                WHERE m.id = ?
            ");
            $msgStmt->execute([$message_id]);
            $newMessage = $msgStmt->fetch(PDO::FETCH_ASSOC);

            // Send push notifications to other channel members
            require_once 'send_shift_notification.php';
            $notifyStmt = $conn->prepare("
                SELECT u.id, u.username, u.push_notifications_enabled
                FROM chat_members cm
                INNER JOIN users u ON cm.user_id = u.id
                WHERE cm.channel_id = ? AND cm.user_id != ? AND cm.is_muted = 0
            ");
            $notifyStmt->execute([$channel_id, $user_id]);
            $members = $notifyStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get channel name
            $channelStmt = $conn->prepare("SELECT name FROM chat_channels WHERE id = ?");
            $channelStmt->execute([$channel_id]);
            $channel = $channelStmt->fetch(PDO::FETCH_ASSOC);
            $channel_name = $channel['name'] ?? 'Chat';

            $sender_username = $_SESSION['username'] ?? 'Someone';
            $preview = strlen($message) > 50 ? substr($message, 0, 50) . '...' : $message;

            foreach ($members as $member) {
                if ($member['push_notifications_enabled']) {
                    sendPushNotification(
                        $member['id'],
                        "{$sender_username} in {$channel_name}",
                        $preview,
                        ['url' => '/users/chat.php?channel=' . $channel_id]
                    );
                }
            }

            echo json_encode(['success' => true, 'message' => $newMessage, 'message_id' => $message_id]);
            break;

        // Edit a message
        case 'edit_message':
            $message_id = (int) ($_POST['message_id'] ?? 0);
            $new_message = trim($_POST['message'] ?? '');

            if (!$message_id || empty($new_message)) {
                throw new Exception('Message ID and new content required');
            }

            // Verify ownership
            $checkStmt = $conn->prepare("SELECT channel_id FROM chat_messages WHERE id = ? AND user_id = ? AND is_deleted = 0");
            $checkStmt->execute([$message_id, $user_id]);
            $msg = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$msg) {
                throw new Exception('Message not found or access denied');
            }

            // Update message
            $updateStmt = $conn->prepare("UPDATE chat_messages SET message = ?, is_edited = 1 WHERE id = ?");
            $updateStmt->execute([$new_message, $message_id]);

            echo json_encode(['success' => true, 'message' => 'Message updated']);
            break;

        // Delete a message
        case 'delete_message':
            $message_id = (int) ($_POST['message_id'] ?? 0);

            if (!$message_id) {
                throw new Exception('Message ID required');
            }

            // Verify ownership or admin
            $is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);

            $checkStmt = $conn->prepare("
                SELECT m.id, m.user_id, cm.is_admin as channel_admin
                FROM chat_messages m
                INNER JOIN chat_members cm ON m.channel_id = cm.channel_id AND cm.user_id = ?
                WHERE m.id = ?
            ");
            $checkStmt->execute([$user_id, $message_id]);
            $msg = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$msg) {
                throw new Exception('Message not found');
            }

            if ($msg['user_id'] != $user_id && !$is_admin && !$msg['channel_admin']) {
                throw new Exception('Access denied');
            }

            // Soft delete
            $deleteStmt = $conn->prepare("UPDATE chat_messages SET is_deleted = 1, message = '[Message deleted]' WHERE id = ?");
            $deleteStmt->execute([$message_id]);

            echo json_encode(['success' => true, 'message' => 'Message deleted']);
            break;

        // Mark messages as read
        case 'mark_read':
            $channel_id = (int) ($_POST['channel_id'] ?? 0);
            $message_id = (int) ($_POST['message_id'] ?? 0);

            if (!$channel_id) {
                throw new Exception('Channel ID required');
            }

            // Update last read
            $updateStmt = $conn->prepare("
                UPDATE chat_members 
                SET last_read_message_id = ?, last_read_at = NOW()
                WHERE channel_id = ? AND user_id = ?
            ");
            $updateStmt->execute([$message_id ?: null, $channel_id, $user_id]);

            echo json_encode(['success' => true, 'message' => 'Marked as read']);
            break;

        // Get unread count
        case 'get_unread_count':
            $query = "
                SELECT 
                    cm.channel_id,
                    COUNT(m.id) as unread_count
                FROM chat_members cm
                LEFT JOIN chat_messages m ON m.channel_id = cm.channel_id 
                    AND m.id > COALESCE(cm.last_read_message_id, 0)
                    AND m.user_id != cm.user_id
                    AND m.is_deleted = 0
                WHERE cm.user_id = ? AND cm.is_muted = 0
                GROUP BY cm.channel_id
            ";

            $stmt = $conn->prepare($query);
            $stmt->execute([$user_id]);
            $unread = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total_unread = array_sum(array_column($unread, 'unread_count'));

            echo json_encode(['success' => true, 'unread' => $unread, 'total' => $total_unread]);
            break;

        // Search messages
        case 'search':
            $query_text = trim($_GET['q'] ?? '');
            $channel_id = (int) ($_GET['channel_id'] ?? 0);

            if (empty($query_text)) {
                throw new Exception('Search query required');
            }

            $sql = "
                SELECT 
                    m.id, m.message, m.created_at,
                    u.username, u.profile_picture,
                    c.name as channel_name, c.id as channel_id
                FROM chat_messages m
                INNER JOIN users u ON m.user_id = u.id
                INNER JOIN chat_channels c ON m.channel_id = c.id
                INNER JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = ?
                WHERE MATCH(m.message) AGAINST(? IN BOOLEAN MODE)
                AND m.is_deleted = 0
            ";

            $params = [$user_id, $query_text];

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

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>