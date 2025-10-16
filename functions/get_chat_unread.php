<?php
/**
 * Get unread chat message count for a user
 * Used to display badge in navigation
 */
function getUnreadChatCount($pdo, $user_id)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM chat_messages cm
            JOIN chat_members cmem ON cm.channel_id = cmem.channel_id
            WHERE cmem.user_id = ?
            AND cmem.is_muted = 0
            AND cm.created_at > COALESCE(cmem.last_read_at, '2000-01-01')
            AND cm.user_id != ?
        ");
        $stmt->execute([$user_id, $user_id]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        // If chat tables don't exist yet, return 0
        return 0;
    }
}
?>