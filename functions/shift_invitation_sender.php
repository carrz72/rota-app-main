<?php
session_start();
require_once '../includes/db.php';
if (!function_exists('addNotification')) {
    require_once '../functions/addNotification.php';
}
require_once '../functions/branch_functions.php';

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
$branch_id = '';

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
        // default branch id for the form
        $branch_id = $adminBranchId;
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

// Load all branches for the branch picker
$all_branches = getAllBranches($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form input.
    $invited_user_id_input = trim($_POST['invited_user_id'] ?? '');
    $shift_date = trim($_POST['shift_date'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time = trim($_POST['end_time'] ?? '');
    $role_id = trim($_POST['role_id'] ?? ''); // now coming from dropdown
    $location = trim($_POST['location'] ?? '');
    $branch_id = trim($_POST['branch_id'] ?? '');

    // Normalize branch_id to integer or empty
    if ($branch_id !== '') {
        $branch_id = (int) $branch_id;
    } else {
        $branch_id = '';
    }

    // Use NULL to represent "broadcast to everyone" if "all" is chosen.
    $invited_user_id = ($invited_user_id_input === 'all') ? null : $invited_user_id_input;

    // Basic validation.
    if (empty($invited_user_id_input) || empty($shift_date) || empty($start_time) || empty($end_time) || empty($role_id) || empty($location)) {
        $error = "All fields are required.";
    } else {
        // Validate targeted invitee belongs to admin's branch (unless super_admin)
        if (!is_null($invited_user_id)) {
            // ensure numeric id
            $invited_user_id = (int) $invited_user_id;
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

        // Server-side: only super_admins may choose a branch other than their own.
        if ($branch_id !== '') {
            if ($adminRole !== 'super_admin') {
                // Non-super-admins may only use their own branch (or none if they have no branch)
                if (is_null($adminBranchId) || ((int) $branch_id !== (int) $adminBranchId)) {
                    $error = "You can only select your own branch.";
                }
            }

            // If still OK, prefer the branch name as the location on the server side.
            if (empty($error)) {
                $bstmt = $conn->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
                $bstmt->execute([$branch_id]);
                $brow = $bstmt->fetch(PDO::FETCH_ASSOC);
                if ($brow && !empty($brow['name'])) {
                    $location = $brow['name'];
                }
            }
        } else {
            // No branch selected: if admin has a branch, force it as default
            if ($adminRole !== 'super_admin' && !is_null($adminBranchId)) {
                $branch_id = $adminBranchId;
                $location = $defaultLocation;
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
            try {
                require_once __DIR__ . '/../includes/audit_log.php';
                log_audit($conn, $admin_id, 'shift_invitation_created', ['invited_user_id' => $invited_user_id, 'shift_date' => $shift_date], $invitation_id, 'shift_invitation', session_id());
            } catch (Exception $e) {
            }

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
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title>Send Shift Invitation - Admin</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        /* Small form polish to match Add Shift */
        .admin-panel {
            max-width: 920px;
            margin: 18px auto;
        }

        .admin-form {
            background: #fff;
            padding: 18px;
            border-radius: 10px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        .form-control {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #e6e6e6;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 12px;
        }

        .admin-btn.primary {
            background: #2b7cff;
            color: #fff;
            border-radius: 8px;
            padding: 10px 14px;
        }

        .admin-btn.secondary {
            background: #f4f6fa;
            color: #333;
            border-radius: 8px;
            padding: 10px 14px;
        }

        @media (max-width:900px) {
            .form-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width:600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
                                <i class="fas fa-user"></i>
                                Invitee:
                            </label>
                            <select name="invited_user_id" id="invited_user_id" class="form-control" required>
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
                                <i class="fas fa-calendar"></i>
                                Shift Date:
                            </label>
                            <input type="date" name="shift_date" id="shift_date" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="start_time">
                                <i class="fas fa-clock"></i>
                                Start Time:
                            </label>
                            <input type="time" name="start_time" id="start_time" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="end_time">
                                <i class="fas fa-clock"></i>
                                End Time:
                            </label>
                            <input type="time" name="end_time" id="end_time" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="role_id">
                                <i class="fas fa-user-tag"></i>
                                Role:
                            </label>
                            <select name="role_id" id="role_id" class="form-control" required>
                                <option value="">Select a role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($adminRole !== 'super_admin'): ?>
                            <div class="form-group">
                                <label>
                                    <i class="fas fa-map-marker-alt"></i>
                                    Location / Branch:
                                </label>
                                <div
                                    style="padding:10px 12px; background:#f7f9fb; border-radius:8px; border:1px solid #e6e6e6;">
                                    <?php echo htmlspecialchars($defaultLocation ?: ($location ?? '')); ?>
                                </div>
                                <!-- Hidden fields to ensure server receives the enforced branch/location -->
                                <input type="hidden" name="location"
                                    value="<?php echo htmlspecialchars($defaultLocation ?: ($location ?? '')); ?>">
                                <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($branch_id); ?>">
                                <small class="hint">Invitations are restricted to your branch.</small>
                            </div>
                        <?php else: ?>
                            <div class="form-group">
                                <label for="location">
                                    <i class="fas fa-map-marker-alt"></i>
                                    Location:
                                </label>
                                <input type="text" name="location" id="location" class="form-control" required
                                    value="<?php echo htmlspecialchars((isset($location) && $location !== '') ? $location : $defaultLocation); ?>">
                            </div>

                            <div class="form-group">
                                <label for="branch_id">
                                    <i class="fas fa-code-branch"></i>
                                    Branch (optional):
                                </label>
                                <select name="branch_id" id="branch_id" class="form-control">
                                    <option value="">-- Keep as typed location --</option>
                                    <?php foreach ($all_branches as $b): ?>
                                        <option value="<?php echo $b['id']; ?>" <?php echo ((string) $b['id'] === (string) $branch_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($b['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="hint">Select a branch to prefill the location (admin only).</small>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="admin-btn primary">
                            <i class="fas fa-paper-plane"></i> Send Invitation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const branchSelect = document.getElementById('branch_id');
            const locationInput = document.getElementById('location');
            if (!branchSelect) return;

            branchSelect.addEventListener('change', function () {
                const id = this.value;
                if (!id) return;
                const opt = this.options[this.selectedIndex];
                if (opt && opt.text) {
                    locationInput.value = opt.text;
                }
            });
        })();
    </script>
</body>

</html>