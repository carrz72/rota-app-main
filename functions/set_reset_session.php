<?php
// filepath: c:\xampp\htdocs\rota-app-main\functions\set_reset_session.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../includes/session_starter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['email']) || empty($input['email'])) {
        echo json_encode(['success' => false, 'error' => 'Email is required']);
        exit;
    }

    $email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email format']);
        exit;
    }

    // Set session variable for password reset
    $_SESSION['reset_email'] = $email;
    $_SESSION['reset_time'] = time(); // Track when the reset was initiated

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Error in set_reset_session.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>