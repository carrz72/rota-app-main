<?php
/**
 * Test Push Notification
 * Send a test notification to yourself
 */

session_start();
require_once 'functions/push_notification_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Please log in first to test push notifications');
}

$user_id = $_SESSION['user_id'];

echo "<h1>Push Notification Test</h1>";
echo "<p>Your User ID: $user_id</p>";

// Check if subscriptions exist
try {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM push_subscriptions WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p>Push Subscriptions: " . $result['count'] . "</p>";
    
    if ($result['count'] == 0) {
        echo "<p style='color: orange;'>⚠️  No push subscriptions found. Please enable notifications in your browser first.</p>";
        echo "<p><a href='users/dashboard.php'>Go to Dashboard</a> and enable notifications when prompted.</p>";
        exit;
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    exit;
}

// Send test notification
echo "<h2>Sending Test Notification...</h2>";

$success = sendPushNotification(
    $user_id,
    "Test Notification",
    "This is a test push notification from Open Rota! 🎉",
    "/users/dashboard.php",
    ['type' => 'test']
);

if ($success) {
    echo "<p style='color: green;'>✅ Notification sent successfully!</p>";
    echo "<p>Check your device - you should see a notification appear.</p>";
} else {
    echo "<p style='color: red;'>❌ Failed to send notification</p>";
    echo "<p>Check the server logs for details.</p>";
}

echo "<br><br>";
echo "<a href='users/dashboard.php'>Back to Dashboard</a>";
?>
