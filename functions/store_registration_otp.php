<?php
// functions/store_registration_otp.php
// Store OTP for registration verification

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../includes/db.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['email']) || !isset($input['otp'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Email and OTP are required'
        ]);
        exit;
    }

    $email = trim($input['email']);
    $otp = trim($input['otp']);

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email format'
        ]);
        exit;
    }

    // Validate OTP format (6 digits)
    if (!preg_match('/^\d{6}$/', $otp)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid OTP format'
        ]);
        exit;
    }

    // Check if email is already registered
    $checkUserSql = "SELECT id FROM users WHERE email = ?";
    $checkUserStmt = $conn->prepare($checkUserSql);
    $checkUserStmt->execute([$email]);
    $userResult = $checkUserStmt->fetchAll();

    if (count($userResult) > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Email is already registered'
        ]);
        exit;
    }

    // Delete any existing OTP for this email
    $deleteSql = "DELETE FROM email_verification WHERE email = ?";
    $deleteStmt = $conn->prepare($deleteSql);
    $deleteStmt->execute([$email]);

    // Insert new OTP
    $insertSql = "INSERT INTO email_verification (email, verification_code, created_at) VALUES (?, ?, NOW())";
    $insertStmt = $conn->prepare($insertSql);

    if ($insertStmt->execute([$email, $otp])) {
        echo json_encode([
            'success' => true,
            'message' => 'OTP stored successfully',
            'expires_in_minutes' => 10
        ]);
    } else {
        throw new Exception('Failed to store OTP');
    }

} catch (Exception $e) {
    error_log("Store Registration OTP Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error. Please try again.'
    ]);
}
?>