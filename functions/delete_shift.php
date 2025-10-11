<?php
session_start();
include '../includes/db.php';
if (!function_exists('addNotification')) {
    require_once __DIR__ . '/addNotification.php';
}

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

// Handle both GET and POST requests for shift_id
$shift_id = $_POST['shift_id'] ?? $_GET['id'] ?? '';

if (empty($shift_id)) {
    addNotification($conn, $_SESSION['user_id'], "Shift ID missing.", "error");
    echo "Shift ID missing.";
    exit;
}

// Check if user is admin - admins can delete any shift, regular users only their own
$is_admin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'super_admin']);

if ($is_admin) {
    // Admins can delete any shift
    $stmt = $conn->prepare("DELETE FROM shifts WHERE id=?");
    $execute_params = [$shift_id];
} else {
    // Regular users can only delete their own shifts
    $stmt = $conn->prepare("DELETE FROM shifts WHERE id=? AND user_id=?");
    $execute_params = [$shift_id, $_SESSION['user_id']];
}

// Determine redirect URL based on user role and referrer
$redirect_url = "../users/shifts.php"; // Default for regular users
if ($is_admin) {
    $redirect_url = "../admin/admin_dashboard.php"; // Default for admins
    // Check if there's a specific return URL
    if (isset($_GET['return']) && !empty($_GET['return'])) {
        $return = $_GET['return'];
        // Basic validation to prevent open redirect
        if (strpos($return, '../admin/') === 0) {
            $redirect_url = $return;
        }
    }
}

if ($stmt->execute($execute_params)) {
    if ($stmt->rowCount() > 0) {
        addNotification($conn, $_SESSION['user_id'], "Shift deleted successfully.", "success");
        // Audit: successful deletion
        try {
            require_once __DIR__ . '/../includes/audit_log.php';
            log_audit($conn, $_SESSION['user_id'] ?? null, 'delete_shift', [], $shift_id, 'shift', session_id());
        } catch (Exception $e) {
        }
        $_SESSION['success_message'] = "Shift deleted successfully.";
    } else {
        addNotification($conn, $_SESSION['user_id'], "Shift not found or already deleted.", "error");
        // Audit: attempted delete but not found
        try {
            require_once __DIR__ . '/../includes/audit_log.php';
            log_audit($conn, $_SESSION['user_id'] ?? null, 'delete_shift_not_found', [], $shift_id, 'shift', session_id());
        } catch (Exception $e) {
        }
        $_SESSION['error_message'] = "Shift not found or already deleted.";
    }
} else {
    $errorInfo = $stmt->errorInfo();
    addNotification($conn, $_SESSION['user_id'], "Error deleting shift: " . $errorInfo[2], "error");
    // Audit: database error while deleting shift
    try {
        require_once __DIR__ . '/../includes/audit_log.php';
        log_audit($conn, $_SESSION['user_id'] ?? null, 'delete_shift_error', ['error' => $errorInfo[2]], $shift_id, 'shift', session_id());
    } catch (Exception $e) {
    }
    $_SESSION['error_message'] = "Error deleting shift: " . $errorInfo[2];
}

// Clean up and redirect
$stmt = null;
$conn = null;

header("Location: " . $redirect_url);
exit();
?>