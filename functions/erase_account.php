<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';

// Assumptions: tables `users`, `shifts`, `payroll`, `login_history`, `user_sessions` exist. We will attempt to delete related rows if present.
// This endpoint requires POST and a `confirm` field with value 'ERASE' to prevent accidental deletion.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token)) {
    header('HTTP/1.1 400 Bad Request');
    echo "Invalid CSRF token";
    exit;
}

$confirm = $_POST['confirm'] ?? '';
if ($confirm !== 'ERASE') {
    header('HTTP/1.1 400 Bad Request');
    echo "Please confirm account erasure by typing ERASE.";
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $conn->beginTransaction();
    $tables = ['user_sessions', 'login_history', 'shifts', 'payroll'];
    foreach ($tables as $t) {
        try {
            $stmt = $conn->prepare("DELETE FROM {$t} WHERE user_id = ?");
            $stmt->execute([$user_id]);
        } catch (Exception $e) {
            // skip if table missing
        }
    }

    // Finally delete user account (soft-delete option could be safer)
    try {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
    } catch (Exception $e) {
        // If delete fails, rollback
        $conn->rollBack();
        throw $e;
    }

    $conn->commit();

    // Log audit
    try { log_audit($conn, $user_id, 'erase_account', ['method' => 'self_service'], $user_id, 'account_erasure', session_id()); } catch (Exception $e) {}

    // Destroy session and cookies
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();

    // Show simple response
    echo "Your account and associated data have been erased. You will be logged out.";
    if (!defined('UNIT_TEST')) exit;
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log("erase_account error: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo "An error occurred while erasing the account.";
    if (!defined('UNIT_TEST')) exit;
}
