<?php
/**
 * Save Push Subscription
 * Saves user's push notification subscription to database
 */

session_start();
require_once '../includes/db.php';
require_once '../includes/push_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['endpoint']) || !isset($input['keys'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid subscription data']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $endpoint = $input['endpoint'];
    $p256dh = $input['keys']['p256dh'];
    $auth = $input['keys']['auth'];

    // Insert or update subscription
    $stmt = $conn->prepare("
        INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_token)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            p256dh_key = VALUES(p256dh_key),
            auth_token = VALUES(auth_token),
            updated_at = CURRENT_TIMESTAMP
    ");
    
    $stmt->execute([$user_id, $endpoint, $p256dh, $auth]);

    echo json_encode([
        'success' => true,
        'message' => 'Subscription saved successfully'
    ]);

} catch (Exception $e) {
    error_log('Error saving push subscription: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save subscription']);
}
?>
