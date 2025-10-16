<?php
session_start();
require_once '../includes/db.php';
require_once 'send_shift_notification.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
header('Content-Type: application/json');

try {
    // Check if user has push notifications enabled
    $stmt = $conn->prepare("SELECT username, push_notifications_enabled FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User not found');
    }

    if (!$user['push_notifications_enabled']) {
        throw new Exception('Push notifications are not enabled. Please enable them in settings.');
    }

    // Check if user has any push subscriptions
    $subStmt = $conn->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?");
    $subStmt->execute([$user_id]);
    $subCount = $subStmt->fetchColumn();

    if ($subCount == 0) {
        throw new Exception('No push subscription found. Please enable push notifications in your browser.');
    }

    // Send test notification
    $title = "Test Notification";
    $body = "This is a test of your custom reminder notifications. You're all set! 🎉";
    $data = [
        'url' => '/users/settings.php',
        'test' => true
    ];

    $result = sendPushNotification($user_id, $title, $body, $data);

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Test notification sent successfully! Check your device.'
        ]);
    } else {
        throw new Exception('Failed to send notification. Please check your subscription.');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>