<?php
function addNotification($conn, $user_id, $message, $type, $related_id = null)
{
    // Check if a similar unread notification already exists to avoid duplicates.
    $stmt = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND message = ? AND type = ? AND is_read = 0");
    $stmt->execute([$user_id, $message, $type]);
    if ($stmt->rowCount() == 0) {
        $insertNotif = $conn->prepare("INSERT INTO notifications (user_id, type, message, is_read, related_id) VALUES (?, ?, ?, 0, ?)");
        return $insertNotif->execute([$user_id, $type, $message, $related_id]);
    }
    return false;
}
?>