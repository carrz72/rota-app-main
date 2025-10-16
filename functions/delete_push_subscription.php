<?php
/**
 * Delete Push Subscription
 * Removes user's push notification subscription from database
 */

session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['endpoint'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $endpoint = $input['endpoint'];

    // Delete subscription
    $stmt = $conn->prepare("
        DELETE FROM push_subscriptions 
        WHERE user_id = ? AND endpoint = ?
    ");
    
    $stmt->execute([$user_id, $endpoint]);

    echo json_encode([
        'success' => true,
        'message' => 'Subscription deleted successfully'
    ]);

} catch (Exception $e) {
    error_log('Error deleting push subscription: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to delete subscription']);
}
?>
