<?php
session_start();
include '../includes/db.php';
require_once '../functions/addNotification.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../functions/login.php");
    exit();
}

// Initialize variables
$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$redirect_url = "../users/shifts.php"; // Default redirect for regular users
$error_message = null;

// Check if we're in admin mode (coming from admin page)
if (isset($_POST['admin_mode']) && $is_admin) {
    $redirect_url = "../admin/manage_shifts.php";

    // If a specific return URL is provided and seems valid
    if (isset($_POST['return_url']) && strpos($_POST['return_url'], '../admin/') === 0) {
        $redirect_url = $_POST['return_url'];
    }
}

try {
    // Validate required fields
    $required_fields = ['shift_id', 'shift_date', 'start_time', 'end_time', 'location', 'role_id'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Get form data with basic validation
    $shift_id = filter_var($_POST['shift_id'], FILTER_VALIDATE_INT);
    $shift_date = htmlspecialchars($_POST['shift_date']);
    $start_time = htmlspecialchars($_POST['start_time']);
    $end_time = htmlspecialchars($_POST['end_time']);
    $location = htmlspecialchars($_POST['location']);
    $role_id = filter_var($_POST['role_id'], FILTER_VALIDATE_INT);

    // Additional field specifically for admins
    $edited_user_id = isset($_POST['user_id']) && $is_admin ? filter_var($_POST['user_id'], FILTER_VALIDATE_INT) : $user_id;

    // Further validation
    if (!$shift_id || !$role_id) {
        throw new Exception("Invalid shift or role ID");
    }

    if (!strtotime($shift_date)) {
        throw new Exception("Invalid date format");
    }

    // For security: regular users can only edit their own shifts
    if (!$is_admin) {
        // Regular user - check if shift belongs to them
        $check_stmt = $conn->prepare("SELECT user_id FROM shifts WHERE id = ?");
        $check_stmt->execute([$shift_id]);
        $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$check_result || $check_result['user_id'] != $user_id) {
            throw new Exception("You are not authorized to edit this shift");
        }

        // Regular users can only edit their own shifts
        $stmt = $conn->prepare("UPDATE shifts SET shift_date=?, start_time=?, end_time=?, location=?, role_id=? WHERE id=? AND user_id=?");
        $stmt->execute([$shift_date, $start_time, $end_time, $location, $role_id, $shift_id, $user_id]);
    } else {
        // Admin can edit any shift
        $stmt = $conn->prepare("UPDATE shifts SET shift_date=?, start_time=?, end_time=?, location=?, role_id=?, user_id=? WHERE id=?");
        $stmt->execute([$shift_date, $start_time, $end_time, $location, $role_id, $edited_user_id, $shift_id]);

        // If admin is editing someone else's shift, add notification for that user
        if ($edited_user_id != $user_id) {
            // Get the admin's username to include in the notification
            $admin_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
            $admin_stmt->execute([$user_id]);
            $admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);
            $admin_name = $admin ? $admin['username'] : 'An administrator';

            // Format date and time for notification
            $formatted_date = date("M j, Y", strtotime($shift_date));
            $formatted_time = date("g:i A", strtotime($start_time)) . " - " . date("g:i A", strtotime($end_time));

            // Notify the user whose shift was edited
            $message = "$admin_name updated your shift for $formatted_date ($formatted_time)";
            addNotification($conn, $edited_user_id, $message, "shift_update");
        }
    }

    // Add success message to session for display after redirect
    $_SESSION['success_message'] = "Shift updated successfully!";

} catch (Exception $e) {
    // Log the error
    error_log("Shift edit error: " . $e->getMessage());

    // Store error message in session
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
}

// Cleanup
$stmt = null;
$conn = null;

// Redirect back to appropriate page
header("Location: $redirect_url");
exit();
?>