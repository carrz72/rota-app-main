<?php
// functions/verify_and_create_account.php
// Verify OTP and create user account

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../includes/db.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['email']) || !isset($input['otp']) || !isset($input['registrationData'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Email, OTP, and registration data are required'
        ]);
        exit;
    }

    $email = trim($input['email']);
    $otp = trim($input['otp']);
    $registrationData = $input['registrationData'];

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

    // Validate registration data
    $requiredFields = ['username', 'email', 'password'];
    foreach ($requiredFields as $field) {
        if (!isset($registrationData[$field]) || empty(trim($registrationData[$field]))) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Missing required field: {$field}"
            ]);
            exit;
        }
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

    // Verify OTP against session data (skip database check for session-based registration)
    if ($registrationData['otp'] !== $otp) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid verification code'
        ]);
        exit;
    }

    // Skip expiry check since we removed timer functionality
    // OTP verification successful - proceed with account creation

    // Hash password
    $hashedPassword = password_hash($registrationData['password'], PASSWORD_DEFAULT);

    // Create user account - use existing database structure with branch_id
    $username = trim($registrationData['username']);
    $branchId = isset($registrationData['branch_id']) ? (int) $registrationData['branch_id'] : null;

    $insertUserSql = "INSERT INTO users (username, email, password, role, email_verified, branch_id, created_at) VALUES (?, ?, ?, 'user', 1, ?, NOW())";
    $insertUserStmt = $conn->prepare($insertUserSql);

    if ($insertUserStmt->execute([$username, $email, $hashedPassword, $branchId])) {
        $userId = $conn->lastInsertId();

        // Audit account creation
        try {
            require_once __DIR__ . '/../includes/audit_log.php';
            log_audit($conn, $userId, 'account_created', ['email' => $email, 'username' => $username], $userId, 'user', session_id());
        } catch (Exception $e) {}

        echo json_encode([
            'success' => true,
            'message' => 'Account created successfully!',
            'user_id' => $userId,
            'redirect_url' => 'index.php?registered=1'
        ]);

    } else {
        // Audit failure
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, null, 'account_create_failed', ['email' => $email], null, 'user', session_id()); } catch (Exception $e) {}
        throw new Exception('Failed to create user account');
    }

} catch (Exception $e) {
    error_log("Verify and Create Account Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error. Please try again.'
    ]);
}
?>