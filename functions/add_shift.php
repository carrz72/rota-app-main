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

    // Detect shift mode
    $shift_mode = $_POST['shift_mode'] ?? 'single';

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

    // Validate required fields based on mode
    if (empty($start_time) || empty($end_time) || empty($role_id) || empty($location)) {
        throw new Exception("Time, role, and location fields are required.");
    }

    $shifts_added = 0;
    $dates_to_process = [];

    // Handle different shift modes
    switch ($shift_mode) {
        case 'single':
            if (empty($shift_date)) {
                throw new Exception("Date is required for single shift.");
            }
            $dates_to_process[] = $shift_date;
            break;

        case 'multiple':
            $start_date = $_POST['start_date'] ?? '';
            $end_date = $_POST['end_date'] ?? '';
            $selected_days = $_POST['days'] ?? [];

            if (empty($start_date) || empty($end_date) || empty($selected_days)) {
                throw new Exception("Start date, end date, and selected days are required for multiple shifts.");
            }

            // Generate dates based on selected days of week
            $current_date = new DateTime($start_date);
            $end_date_obj = new DateTime($end_date);

            while ($current_date <= $end_date_obj) {
                $day_of_week = $current_date->format('w'); // 0=Sunday, 1=Monday, etc.
                if (in_array($day_of_week, $selected_days)) {
                    $dates_to_process[] = $current_date->format('Y-m-d');
                }
                $current_date->add(new DateInterval('P1D'));
            }
            break;

        case 'template':
            if (empty($shift_date)) {
                throw new Exception("Date is required when saving template.");
            }
            $template_name = $_POST['template_name'] ?? '';
            if (empty($template_name)) {
                throw new Exception("Template name is required.");
            }

            // For template mode, we'll save the template and also add the shift
            $dates_to_process[] = $shift_date;

            // Save template to database (create table if needed)
            try {
                $conn->exec("CREATE TABLE IF NOT EXISTS shift_templates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    template_name VARCHAR(255) NOT NULL,
                    role_id INT NOT NULL,
                    start_time TIME NOT NULL,
                    end_time TIME NOT NULL,
                    location VARCHAR(255) NOT NULL,
                    branch_id INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id),
                    FOREIGN KEY (role_id) REFERENCES roles(id),
                    FOREIGN KEY (branch_id) REFERENCES branches(id)
                )");

                $template_stmt = $conn->prepare("INSERT INTO shift_templates 
                    (user_id, template_name, role_id, start_time, end_time, location, branch_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $template_stmt->execute([$session_user_id, $template_name, $role_id, $start_time, $end_time, $location, $branch_id]);
            } catch (Exception $e) {
                error_log("Template save error: " . $e->getMessage());
                // Continue with shift creation even if template save fails
            }
            break;

        default:
            throw new Exception("Invalid shift mode.");
    }

    // Insert shifts for all processed dates
    if ($branch_id) {
        $stmt = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location, branch_id) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
    } else {
        $stmt = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location) 
                              VALUES (?, ?, ?, ?, ?, ?)");
    }

    foreach ($dates_to_process as $date) {
        // Check if shift already exists for this user/date/time
        $check_stmt = $conn->prepare("SELECT id FROM shifts WHERE user_id = ? AND shift_date = ? AND start_time = ? AND end_time = ?");
        $check_stmt->execute([$user_id, $date, $start_time, $end_time]);

        if (!$check_stmt->fetch()) {
            // Only add if shift doesn't already exist
            if ($branch_id) {
                $execParams = [$user_id, $date, $start_time, $end_time, $role_id, $location, $branch_id];
            } else {
                $execParams = [$user_id, $date, $start_time, $end_time, $role_id, $location];
            }

            if ($stmt->execute($execParams)) {
                $shifts_added++;
                $shift_id = $conn->lastInsertId();

                // Minimal fallback: if lastInsertId returned 0 for any reason, read the current MAX(id)
                if (empty($shift_id) || $shift_id == '0') {
                    $shift_id = (int) $conn->query("SELECT IFNULL(MAX(id),0) FROM shifts")->fetchColumn();
                    error_log('Warning: lastInsertId returned 0; using MAX(id)=' . $shift_id);
                }

                // Send push notification for new shift assignment (only if assigning to another user)
                if ($user_id != $session_user_id) {
                    try {
                        require_once __DIR__ . '/send_shift_notification.php';

                        // Get role name
                        $roleStmt = $conn->prepare("SELECT name FROM roles WHERE id = ?");
                        $roleStmt->execute([$role_id]);
                        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
                        $role_name = $roleRow ? $roleRow['name'] : 'Shift';

                        $shift_details = [
                            'shift_id' => $shift_id,
                            'shift_date' => $date,
                            'start_time' => $start_time,
                            'role_name' => $role_name
                        ];

                        notifyShiftAssigned($user_id, $shift_details);
                    } catch (Exception $e) {
                        error_log("Failed to send push notification: " . $e->getMessage());
                    }
                }
            }
        }
    }

    if ($shifts_added > 0) {
        // Success message based on mode
        if ($shift_mode === 'template') {
            $template_name = $_POST['template_name'] ?? 'Template';
            $_SESSION['message'] = "Template '$template_name' saved and shift added successfully!";
        } elseif ($shifts_added > 1) {
            $_SESSION['message'] = "$shifts_added shifts added successfully!";
        } else {
            $_SESSION['message'] = "Shift added successfully!";
        }

        // Format time for notification
        $formatted_time = date("g:i A", strtotime($start_time)) . " - " . date("g:i A", strtotime($end_time));

        // Determine user's home branch to detect cross-branch scheduling
        $homeBranchStmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
        $homeBranchStmt->execute([$user_id]);
        $user_home_branch = (int) $homeBranchStmt->fetchColumn();

        // If a branch was selected for the shift, get its name for friendly messages
        $shift_branch_name = null;
        if ($branch_id) {
            $shift_branch_name = $branch['name'] ?? null; // getBranchById returned $branch earlier
        }

        // Build contextual notification message based on number of shifts added
        if ($is_admin && $user_id != $session_user_id) {
            $admin_name = $_SESSION['username'] ?? 'An administrator';
            if ($shifts_added > 1) {
                $notif_message = "$admin_name added $shifts_added shifts for you ($formatted_time)";
            } else {
                $formatted_date = date("M j, Y", strtotime($dates_to_process[0]));
                if ($branch_id && $branch_id !== $user_home_branch && $shift_branch_name) {
                    $notif_message = "$admin_name added a new shift for you at {$shift_branch_name} on $formatted_date ($formatted_time)";
                } else {
                    $notif_message = "$admin_name added a new shift for you on $formatted_date ($formatted_time)";
                }
            }
            addNotification($conn, $user_id, $notif_message, "shift_update");

            // Success message for the admin
            $_SESSION['success_message'] = $shifts_added > 1 ? "$shifts_added shifts added successfully for user." : "Shift added successfully for user.";
        } else {
            // User adding their own shift
            if ($shifts_added > 1) {
                addNotification($conn, $user_id, "Added $shifts_added shifts ($formatted_time)", "success");
            } else {
                $formatted_date = date("M j, Y", strtotime($dates_to_process[0]));
                if ($branch_id && $branch_id !== $user_home_branch && $shift_branch_name) {
                    addNotification($conn, $user_id, "New shift added at {$shift_branch_name}: $formatted_date ($formatted_time)", "success");
                } else {
                    addNotification($conn, $user_id, "New shift added: $formatted_date ($formatted_time)", "success");
                }
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
        try {
            require_once __DIR__ . '/../includes/audit_log.php';
            log_audit($conn, $_SESSION['user_id'] ?? null, 'add_shift', ['user_id' => $user_id, 'branch_id' => $branch_id, 'shifts_added' => $shifts_added], $shift_id ?? 0, 'shift', session_id());
        } catch (Exception $e) {
        }
    } else {
        if ($shift_mode === 'multiple') {
            throw new Exception("No shifts were added. All selected shifts may already exist for this user.");
        } else {
            throw new Exception("Failed to add shift. Shift may already exist for this time slot.");
        }
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