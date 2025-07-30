<?php
// filepath: c:\xampp\htdocs\rota-app-main\functions\verify_email_exists.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['email']) || empty($input['email'])) {
        echo json_encode(['exists' => false, 'error' => 'Email is required']);
        exit;
    }

    $email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['exists' => false, 'error' => 'Invalid email format']);
        exit;
    }

    // Check if email exists in the database
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['exists' => $user !== false]);

} catch (PDOException $e) {
    error_log("Database error in verify_email_exists.php: " . $e->getMessage());
    echo json_encode(['exists' => false, 'error' => 'Database error']);
} catch (Exception $e) {
    error_log("General error in verify_email_exists.php: " . $e->getMessage());
    echo json_encode(['exists' => false, 'error' => 'Server error']);
}
?>