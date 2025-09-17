<?php
session_start();
include '../includes/db.php';
include '../includes/notifications.php';
require_once 'branch_functions.php';

// Helper to detect AJAX/XHR requests
$isAjax = false;
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $isAjax = true;
} elseif (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $isAjax = true;
} elseif (isset($_POST['ajax']) && $_POST['ajax']) {
    $isAjax = true;
}

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
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : $session_user_id;

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
    $role_id = isset($_POST['role_id']) ? (int) $_POST['role_id'] : 0;
    $location = $_POST['location'] ?? '';
    $branch_id = isset($_POST['branch_id']) && is_numeric($_POST['branch_id']) ? (int) $_POST['branch_id'] : null;

    // Server-side branch validation: ensure branch exists and user has access (home branch or permitted)
    if ($branch_id) {
        $branch = getBranchById($conn, $branch_id);
        if (!$branch) {
            throw new Exception('Selected branch does not exist.');
        }

        // Require at least 'manage' permission for adding shifts to non-home branches
        $canAccess = canUserAccessBranch($conn, $session_user_id, $branch_id, 'manage');
        if (!$canAccess && !$is_admin) {
            throw new Exception('You do not have permission to add shifts for the selected branch. Manager-level access is required.');
        }
    }

    // Validate required fields
    if (empty($shift_date) || empty($start_time) || empty($end_time) || empty($role_id) || empty($location)) {
        throw new Exception("All fields are required.");
    }

    // Insert the shift
    // Include branch_id if provided
    if ($branch_id) {
        $stmt = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location, branch_id) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
        $execParams = [$user_id, $shift_date, $start_time, $end_time, $role_id, $location, $branch_id];
    } else {
        $stmt = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location) 
                              VALUES (?, ?, ?, ?, ?, ?)");
        $execParams = [$user_id, $shift_date, $start_time, $end_time, $role_id, $location];
    }

        if ($stmt->execute($execParams)) {
        $shift_id = $conn->lastInsertId();

        // Minimal fallback: if lastInsertId returned 0 for any reason, read the current MAX(id)
        // (Assumes database schema has been fixed to AUTO_INCREMENT)
        if (empty($shift_id) || $shift_id == '0') {
            $shift_id = (int)$conn->query("SELECT IFNULL(MAX(id),0) FROM shifts")->fetchColumn();
            error_log('Warning: lastInsertId returned 0; using MAX(id)=' . $shift_id);
        }

        // Format date and time for notification
        $formatted_date = date("M j, Y", strtotime($shift_date));
        $formatted_time = date("g:i A", strtotime($start_time)) . " - " . date("g:i A", strtotime($end_time));

        // Determine user's home branch to detect cross-branch scheduling
        $homeBranchStmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
        $homeBranchStmt->execute([$user_id]);
        $user_home_branch = (int)$homeBranchStmt->fetchColumn();

        // If a branch was selected for the shift, get its name for friendly messages
        $shift_branch_name = null;
        if ($branch_id) {
            $shift_branch_name = $branch['name'] ?? null; // getBranchById returned $branch earlier
        }

        // Build a contextual notification message. If the shift is at a different branch than the user's home branch,
        // mention the branch so the user is aware they are scheduled away from their home branch.
        if ($is_admin && $user_id != $session_user_id) {
            $admin_name = $_SESSION['username'] ?? 'An administrator';
            if ($branch_id && $branch_id !== $user_home_branch && $shift_branch_name) {
                $notif_message = "$admin_name added a new shift for you at {$shift_branch_name} on $formatted_date ($formatted_time)";
            } else {
                $notif_message = "$admin_name added a new shift for you on $formatted_date ($formatted_time)";
            }
            addNotification($conn, $user_id, $notif_message, "shift_update");

            // Success message for the admin
            $_SESSION['success_message'] = "Shift added successfully for user.";
        } else {
            // User adding their own shift
            if ($branch_id && $branch_id !== $user_home_branch && $shift_branch_name) {
                addNotification($conn, $user_id, "New shift added at {$shift_branch_name}: $formatted_date ($formatted_time)", "success");
            } else {
                addNotification($conn, $user_id, "New shift added: $formatted_date ($formatted_time)", "success");
            }
        }

        // If AJAX request, return JSON instead of redirecting
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'shift_id' => $shift_id,
                'message' => 'Shift added successfully'
            ]);
            // Clean up
            $stmt = null;
            $conn = null;
            exit();
        }
        // Audit shift creation
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'add_shift', ['user_id' => $user_id, 'branch_id' => $branch_id], $shift_id, 'shift', session_id()); } catch (Exception $e) {}
    } else {
        throw new Exception("Failed to add shift.");
    }

} catch (Exception $e) {
    // If AJAX request, return JSON error for nicer inline messages
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        // Clean up
        $stmt = null;
        $conn = null;
        exit();
    }

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