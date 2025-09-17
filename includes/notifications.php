<?php
// Avoid redeclaring addNotification if it's already defined in functions/addNotification.php
if (!function_exists('addNotification')) {
    function addNotification($conn, $user_id, $message, $type)
    {
        // Check if a similar unread notification already exists to avoid duplicates.
        $stmt = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND message = ? AND type = ? AND is_read = 0");
        $stmt->execute([$user_id, $message, $type]);
        if ($stmt->rowCount() == 0) {
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, message, is_read) VALUES (?, ?, ?, 0)");
            $stmt->execute([$user_id, $type, $message]);
        }
    }
}

function addShiftInviteNotification($conn, $user_id, $message)
{
    // Pass the "shift-invite" type to addNotification
    addNotification($conn, $user_id, $message, 'shift-invite');
}

function getNotifications($user_id)
{
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function displayNotification($message, $type = 'warning')
{
    echo "<div class='notification notification-$type'>
            <span class='close-btn' onclick='this.parentElement.style.display=\"none\";'>&times;</span>
            <p>" . htmlspecialchars($message) . "</p>
          </div>";
}
?>