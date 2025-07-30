<?php
/**
 * Session Validation Endpoint
 * Checks if user session is still valid
 */

require_once '../includes/session_manager.php';

// Start session to access session data
initializeSessionTimeout();

header('Content-Type: application/json');

try {
    // Check if session has timed out
    $isTimedOut = checkSessionTimeout();

    if ($isTimedOut) {
        http_response_code(401);
        echo json_encode([
            'valid' => false,
            'expired' => true,
            'message' => 'Session has expired'
        ]);
        exit;
    }

    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'valid' => false,
            'expired' => false,
            'message' => 'Not authenticated'
        ]);
        exit;
    }

    // Session is valid
    $timeRemaining = getSessionTimeRemaining();

    echo json_encode([
        'valid' => true,
        'expired' => false,
        'timeRemaining' => $timeRemaining,
        'formattedTime' => formatTimeRemaining($timeRemaining),
        'userId' => $_SESSION['user_id'],
        'role' => $_SESSION['role'] ?? null,
        'message' => 'Session is valid'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'valid' => false,
        'error' => 'Session check failed',
        'details' => $e->getMessage()
    ]);
}
?>