<?php
require_once '../includes/session_manager.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Not authenticated');
}

// Extend the session
extendSession();

// Return success response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'time_remaining' => getSessionTimeRemaining(),
    'formatted_time' => formatTimeRemaining(getSessionTimeRemaining())
]);
?>