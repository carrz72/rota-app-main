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

// Get shift details before deletion for notification
$shiftStmt = $conn->prepare("SELECT s.*, r.name as role_name, u.username FROM shifts s 
                              LEFT JOIN roles r ON s.role_id = r.id 
                              LEFT JOIN users u ON s.user_id = u.id 
                              WHERE s.id = ?");
$shiftStmt->execute([$shift_id]);
$shift_data = $shiftStmt->fetch(PDO::FETCH_ASSOC);

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
        
        // If admin deleted someone else's shift, notify that user
        if ($is_admin && $shift_data && $shift_data['user_id'] != $_SESSION['user_id']) {
            $affected_user_id = $shift_data['user_id'];
            $formatted_date = date("M j, Y", strtotime($shift_data['shift_date']));
            $formatted_time = date("g:i A", strtotime($shift_data['start_time']));
            
            // Get admin username
            $adminStmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
            $adminStmt->execute([$_SESSION['user_id']]);
            $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
            $admin_name = $admin ? $admin['username'] : 'An administrator';
            
            // Send in-app notification
            $message = "$admin_name removed your shift on $formatted_date at $formatted_time";
            addNotification($conn, $affected_user_id, $message, "shift_update");
            
            // Send push notification
            try {
                require_once __DIR__ . '/send_shift_notification.php';
                
                $title = "Shift Removed";
                $body = "$admin_name removed your {$shift_data['role_name']} shift on $formatted_date";
                $data = [
                    'url' => '/users/shifts.php'
                ];
                
                sendPushNotification($affected_user_id, $title, $body, $data);
            } catch (Exception $e) {
                error_log("Failed to send push notification: " . $e->getMessage());
            }
        }
        
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