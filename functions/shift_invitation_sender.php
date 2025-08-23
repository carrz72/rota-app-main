<?php
session_start();
require_once '../includes/db.php';
require_once '../functions/addNotification.php';

// Only allow admin or super_admin access.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true)) {
    // Not authorized - redirect to login (preserves existing behavior)
    header("Location: ../functions/login.php");
    exit;
}

$error = '';
$message = '';
// Keep location defined so we can reuse it for sticky form values.
$location = '';

// Determine admin role and branch so we can apply branch-scoped rules like the rest of the app.
$admin_id = $_SESSION['user_id'];
$stmtAdmin = $conn->prepare("SELECT role, branch_id FROM users WHERE id = ? LIMIT 1");
$stmtAdmin->execute([$admin_id]);
$adminUser = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
$adminRole = $adminUser['role'] ?? '';
$adminBranchId = isset($adminUser['branch_id']) ? $adminUser['branch_id'] : null;

// Default location should be the branch name of the sending admin (if available).
$defaultLocation = '';
if (!is_null($adminBranchId)) {
    $stmtBranch = $conn->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
    $stmtBranch->execute([$adminBranchId]);
    $branchRow = $stmtBranch->fetch(PDO::FETCH_ASSOC);
    if ($branchRow && !empty($branchRow['name'])) {
        $defaultLocation = $branchRow['name'];
    }
}

// Fetch users excluding the current admin. Scope to admin's branch if not super_admin.
if ($adminRole === 'super_admin') {
    $stmtUsers = $conn->prepare("SELECT id, username, email FROM users WHERE id <> ? ORDER BY username");
    $stmtUsers->execute([$admin_id]);
} else {
    if (is_null($adminBranchId)) {
        // Admin without a branch: show only users with no branch
        $stmtUsers = $conn->prepare("SELECT id, username, email FROM users WHERE id <> ? AND branch_id IS NULL ORDER BY username");
        $stmtUsers->execute([$admin_id]);
    } else {
        $stmtUsers = $conn->prepare("SELECT id, username, email FROM users WHERE id <> ? AND branch_id = ? ORDER BY username");
        $stmtUsers->execute([$admin_id, $adminBranchId]);
    }
}
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Fetch all roles for the dropdown.
$stmtRoles = $conn->query("SELECT id, name FROM roles ORDER BY name ASC");
$roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form input.
    $invited_user_id_input = trim($_POST['invited_user_id'] ?? '');
    $shift_date = trim($_POST['shift_date'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time = trim($_POST['end_time'] ?? '');
    $role_id = trim($_POST['role_id'] ?? ''); // now coming from dropdown
    $location = trim($_POST['location'] ?? '');

    // Use NULL to represent "broadcast to everyone" if "all" is chosen.
    $invited_user_id = ($invited_user_id_input === 'all') ? null : $invited_user_id_input;

    // Basic validation.
    if (empty($invited_user_id_input) || empty($shift_date) || empty($start_time) || empty($end_time) || empty($role_id) || empty($location)) {
        $error = "All fields are required.";
    } else {
        // Validate targeted invitee belongs to admin's branch (unless super_admin)
        if (!is_null($invited_user_id)) {
            // ensure numeric id
            $invited_user_id = (int)$invited_user_id;
            $checkStmt = $conn->prepare("SELECT id, branch_id FROM users WHERE id = ? LIMIT 1");
            $checkStmt->execute([$invited_user_id]);
            $targetUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$targetUser) {
                $error = "Selected user not found.";
            } else if ($adminRole !== 'super_admin') {
                // Non-super admins may only invite users in their branch (including NULL branch if admin has no branch)
                $targetBranch = isset($targetUser['branch_id']) ? $targetUser['branch_id'] : null;
                if ($adminBranchId !== $targetBranch) {
                    $error = "You can only invite users in your branch.";
                }
            }
        }

        // Insert invitation into the database.
        $stmt = $conn->prepare("INSERT INTO shift_invitations (shift_date, start_time, end_time, role_id, location, admin_id, user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        $admin_id = $_SESSION['user_id'];
        if (empty($error) && $stmt->execute([$shift_date, $start_time, $end_time, $role_id, $location, $admin_id, $invited_user_id])) {
            // Get the invitation ID.
            $invitation_id = $conn->lastInsertId();
            $notif_message = "You have a new shift invitation. Click to view details.";

            if (is_null($invited_user_id)) {
                // Broadcast: notify all non-admin users. For non-super admins only notify users in their branch.
                if ($adminRole === 'super_admin') {
                    $stmtAll = $conn->prepare("SELECT id FROM users WHERE id <> ?");
                    $stmtAll->execute([$admin_id]);
                } else {
                    if (is_null($adminBranchId)) {
                        $stmtAll = $conn->prepare("SELECT id FROM users WHERE id <> ? AND branch_id IS NULL");
                        $stmtAll->execute([$admin_id]);
                    } else {
                        $stmtAll = $conn->prepare("SELECT id FROM users WHERE id <> ? AND branch_id = ?");
                        $stmtAll->execute([$admin_id, $adminBranchId]);
                    }
                }

                $allUsers = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
                foreach ($allUsers as $user) {
                    // Send a notification to each user.
                    addNotification($conn, $user['id'], $notif_message, "shift-invite", $invitation_id);
                }
            } else {
                // Single user invitation.
                addNotification($conn, $invited_user_id, $notif_message, "shift-invite", $invitation_id);
            }

                // Audit: shift invitation created
                try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $admin_id, 'shift_invitation_created', ['invited_user_id' => $invited_user_id, 'shift_date' => $shift_date], $invitation_id, 'shift_invitation', session_id()); } catch (Exception $e) {}

                $message = "Shift invitation sent successfully.";
        } else {
            $error = "Failed to send shift invitation.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title>Send Shift Invitation - Admin</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>

<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-paper-plane"></i> Send Shift Invitation</h1>
            </div>
            <div class="admin-actions">
                <a href="../admin/admin_dashboard.php" class="admin-btn secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php elseif (!empty($message)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-envelope"></i> Invitation Details</h2>
            </div>
            <div class="admin-panel-body">
                <form method="POST" action="" class="admin-form">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="invited_user_id">
                                <i class="fas fa-user"></i> Invitee:
                            </label>
                            <select name="invited_user_id" id="invited_user_id" required>
                                <option value="">Select a user</option>
                                <option value="all">Everyone</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['username'] . " (" . $user['email'] . ")"); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="shift_date">
                                <i class="fas fa-calendar"></i> Shift Date:
                            </label>
                            <input type="date" name="shift_date" id="shift_date" required>
                        </div>

                        <div class="form-group">
                            <label for="start_time">
                                <i class="fas fa-clock"></i> Start Time:
                            </label>
                            <input type="time" name="start_time" id="start_time" required>
                        </div>

                        <div class="form-group">
                            <label for="end_time">
                                <i class="fas fa-clock"></i> End Time:
                            </label>
                            <input type="time" name="end_time" id="end_time" required>
                        </div>

                        <div class="form-group">
                            <label for="role_id">
                                <i class="fas fa-user-tag"></i> Role:
                            </label>
                            <select name="role_id" id="role_id" required>
                                <option value="">Select a role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="location">
                                <i class="fas fa-map-marker-alt"></i> Location:
                            </label>
                            <input type="text" name="location" id="location" required value="<?php echo htmlspecialchars((isset(
                                $location) && $location !== '') ? $location : $defaultLocation); ?>">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="admin-btn">
                            <i class="fas fa-paper-plane"></i> Send Invitation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>

</html>