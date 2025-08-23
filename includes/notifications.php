<?php
// Use shared addNotification implementation to avoid duplicate declarations
require_once __DIR__ . '/../functions/addNotification.php';

function addShiftInviteNotification($conn, $user_id, $message) {  
    // Pass the "shift-invite" type to addNotification
    addNotification($conn, $user_id, $message, 'shift-invite');
}

function getNotifications($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function displayNotification($message, $type = 'warning') {
    echo "<div class='notification notification-$type'>
            <span class='close-btn' onclick='this.parentElement.style.display=\"none\";'>&times;</span>
            <p>" . htmlspecialchars($message) . "</p>
          </div>";
}
?>