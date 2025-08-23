<?php
// Only define if another include hasn't already defined addNotification (prevents redeclare fatal errors)
if (!function_exists('addNotification')) {
    function addNotification($conn, $user_id, $message, $type, $related_id = null) {
        // Check if a similar unread notification already exists to avoid duplicates.
        try {
            if ($related_id) {
                // Prefer one notification per (user, type, related_id). If an unread notification exists, update it
                // to the longer/more descriptive message instead of inserting a duplicate.
                $stmt = $conn->prepare("SELECT id, message FROM notifications WHERE user_id = ? AND type = ? AND related_id = ? AND is_read = 0 LIMIT 1");
                $stmt->execute([$user_id, $type, $related_id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    // If the new message is longer (contains branch info or more detail), update the existing message
                    if (strlen($message) > strlen($existing['message'])) {
                        $up = $conn->prepare("UPDATE notifications SET message = ?, created_at = NOW() WHERE id = ?");
                        $up->execute([$message, $existing['id']]);
                    }
                    return true; // deduplicated by updating existing notification
                }

                // No existing unread notification found for this related item; insert new one
                $insertNotif = $conn->prepare("INSERT INTO notifications (user_id, message, type, related_id) VALUES (?, ?, ?, ?)");
                return (bool)$insertNotif->execute([$user_id, $message, $type, $related_id]);
            } else {
                // For notifications without a related_id, avoid inserting exact duplicate (same message & type)
                $stmt = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND message = ? AND type = ? AND is_read = 0 LIMIT 1");
                $stmt->execute([$user_id, $message, $type]);
                if ($stmt->rowCount() > 0) return false;
                $insertNotif = $conn->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, ?)");
                return (bool)$insertNotif->execute([$user_id, $message, $type]);
            }
        } catch (PDOException $e) {
            error_log("addNotification failed: " . $e->getMessage());
            return false;
        }
    }
}
?>