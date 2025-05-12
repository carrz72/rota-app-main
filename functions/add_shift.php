<?php
session_start();
include '../includes/db.php';
include '../includes/notifications.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../functions/login.php");
    exit;
}

// Initialize variables
$session_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$redirect_url = "../users/shifts.php"; // Default redirect for regular users

try {
    // Check if we're in admin mode
    if (isset($_POST['admin_mode']) && $is_admin) {
        // Admin can add shifts for any user
        $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : $session_user_id;
        
        // If a return URL is provided, use it for redirection
        if (isset($_POST['return_url'])) {
            $redirect_url = $_POST['return_url'];
            // Basic validation to prevent open redirect
            if (strpos($redirect_url, '../admin/') !== 0) {
                $redirect_url = "../admin/manage_shifts.php";
            }
        } else {
            $redirect_url = "../admin/manage_shifts.php";
        }
    } else {
        // Regular user can only add shifts for themselves
        $user_id = $session_user_id;
    }

    // Get form data
    $shift_date = $_POST['shift_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $role_id = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
    $location = $_POST['location'] ?? '';

    // Validate required fields
    if (empty($shift_date) || empty($start_time) || empty($end_time) || empty($role_id) || empty($location)) {
        throw new Exception("All fields are required.");
    }

    // Insert the shift
    $stmt = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location) 
                          VALUES (?, ?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$user_id, $shift_date, $start_time, $end_time, $role_id, $location])) {
        $shift_id = $conn->lastInsertId();
        
        // Format date and time for notification
        $formatted_date = date("M j, Y", strtotime($shift_date));
        $formatted_time = date("g:i A", strtotime($start_time)) . " - " . date("g:i A", strtotime($end_time));
        
        if ($is_admin && $user_id != $session_user_id) {
            // Admin adding a shift for another user - send notification to that user
            $admin_name = $_SESSION['username'] ?? 'An administrator';
            $notif_message = "$admin_name added a new shift for you on $formatted_date ($formatted_time)";
            addNotification($conn, $user_id, $notif_message, "shift_update");
            
            // Success message for the admin
            $_SESSION['success_message'] = "Shift added successfully for user.";
        } else {
            // User adding their own shift
            addNotification($conn, $user_id, "New shift added: $formatted_date ($formatted_time)", "success");
        }
    } else {
        throw new Exception("Failed to add shift.");
    }

} catch (Exception $e) {
    if ($is_admin) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    } else {
        // For regular users, add as notification
        addNotification($conn, $session_user_id, "Error adding shift: " . $e->getMessage(), "error");
    }
}

// Clean up and redirect
$stmt = null;
$conn = null;

header("Location: $redirect_url");
exit();
?>
