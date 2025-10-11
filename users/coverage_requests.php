<?php
session_start();
require_once '../includes/auth.php';
requireLogin(); // Only logged-in users can access
require_once '../includes/db.php';
require_once '../functions/branch_functions.php';

$user_id = $_SESSION['user_id'];

// Fetch notifications for header dropdown
require_once '../includes/notifications.php';
$notifications = [];
$notificationCount = 0;
if ($user_id) {
    $notifications = getNotifications($user_id);
    $notificationCount = count($notifications);
}

// Get user's branch information
$stmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_branch_id = $user['branch_id'];

if (!$user_branch_id) {
    $_SESSION['error_message'] = "You must be assigned to a branch to use coverage requests.";
    header("Location: dashboard.php");
    exit();
}

$user_branch = getUserHomeBranch($conn, $user_id);

// Ensure shift_swaps has request_id column (some setups may not have it); add if missing
try {
    $colCheck = $conn->query("SHOW COLUMNS FROM shift_swaps LIKE 'request_id'")->fetchAll();
    if (empty($colCheck)) {
        $conn->exec("ALTER TABLE shift_swaps ADD COLUMN request_id INT DEFAULT NULL");
    }
} catch (Exception $e) {
    // Non-blocking: if ALTER fails (permissions/schema), queries later may still fail — logging for debug
    error_log('Could not ensure shift_swaps.request_id: ' . $e->getMessage());
}

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
    $delete_id = isset($_POST['delete_request_id']) ? (int) $_POST['delete_request_id'] : 0;
    if ($delete_id <= 0) {
        $_SESSION['error_message'] = "Invalid request id.";
        header("Location: coverage_requests.php");
        exit();
    }

    try {
        // Verify the request exists and who created it
        $chk = $conn->prepare("SELECT requested_by_user_id FROM cross_branch_shift_requests WHERE id = ? LIMIT 1");
        $chk->execute([$delete_id]);
        $owner = $chk->fetchColumn();

        if (!$owner) {
            $_SESSION['error_message'] = "Coverage request not found.";
            header("Location: coverage_requests.php");
            exit();
        }

        $isAdmin = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true);
        if ((int) $owner !== (int) $user_id && !$isAdmin) {
            $_SESSION['error_message'] = "You are not authorized to delete this request.";
            header("Location: coverage_requests.php");
            exit();
        }

        // Perform deletion inside a transaction
        $conn->beginTransaction();

        // Remove any related coverage entries to satisfy foreign key constraints
        $delCoverage = $conn->prepare("DELETE FROM shift_coverage WHERE request_id = ?");
        $delCoverage->execute([$delete_id]);

        $stmt = $conn->prepare("DELETE FROM cross_branch_shift_requests WHERE id = ?");
        $stmt->execute([$delete_id]);

        if ($stmt->rowCount() === 0) {
            $conn->rollBack();
            $_SESSION['error_message'] = "Failed to delete coverage request.";
            header("Location: coverage_requests.php");
            exit();
        }

        $conn->commit();
        $_SESSION['success_message'] = "Coverage request deleted.";
        header("Location: coverage_requests.php");
        exit();
    } catch (Exception $e) {
        if ($conn->inTransaction())
            $conn->rollBack();
        $_SESSION['error_message'] = "Error deleting request: " . $e->getMessage();
        header("Location: coverage_requests.php");
        exit();
    }
}

// Handle edit request (show edit form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_request'])) {
    $edit_id = $_POST['edit_request_id'];
    $stmt = $conn->prepare("SELECT * FROM cross_branch_shift_requests WHERE id = ? AND requested_by_user_id = ?");
    $stmt->execute([$edit_id, $user_id]);
    $edit_request = $stmt->fetch(PDO::FETCH_ASSOC);
    $show_edit_modal = true;
}

// Handle update after editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_request'])) {
    $edit_id = $_POST['edit_id'];
    $target_branch_ids = isset($_POST['target_branch_ids']) ? $_POST['target_branch_ids'] : '';
    if (is_string($target_branch_ids)) {
        $target_branch_ids = array_filter(explode(',', $target_branch_ids));
    }
    if (!is_array($target_branch_ids) || count($target_branch_ids) === 0) {
        $_SESSION['error_message'] = "Please select a branch.";
        header("Location: coverage_requests.php");
        exit();
    }
    $target_branch_id = $target_branch_ids[0]; // Only one allowed for edit
    $shift_date = $_POST['shift_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $role_id = isset($_POST['role_id']) && $_POST['role_id'] !== '' ? (int) $_POST['role_id'] : null;
    $urgency_level = $_POST['urgency_level'];
    $description = $_POST['description'];
    $expires_hours = $_POST['expires_hours'];
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_hours} hours"));
    $source_shift_id = isset($_POST['source_shift_id']) && $_POST['source_shift_id'] !== '' ? (int) $_POST['source_shift_id'] : null;

    // derive role_required text if role_id provided
    $role_required = null;
    if ($role_id) {
        $rstmt = $conn->prepare("SELECT name FROM roles WHERE id = ? LIMIT 1");
        $rstmt->execute([$role_id]);
        $role_required = $rstmt->fetchColumn();
    }

    $stmt = $conn->prepare(
        "UPDATE cross_branch_shift_requests
         SET target_branch_id = ?, shift_date = ?, start_time = ?, end_time = ?, role_id = ?, role_required = ?, urgency_level = ?, description = ?, expires_at = ?
         WHERE id = ? AND requested_by_user_id = ?"
    );
    $stmt->execute([
        $target_branch_id,
        $shift_date,
        $start_time,
        $end_time,
        $role_id,
        $role_required,
        $urgency_level,
        $description,
        $expires_at,
        $edit_id,
        $user_id
    ]);

    $_SESSION['success_message'] = "Coverage request updated.";
    header("Location: coverage_requests.php");
    exit();
}

// Handle create request (supports selecting up to 5 target branches)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_request'])) {
    $target_branch_ids = isset($_POST['target_branch_ids']) ? $_POST['target_branch_ids'] : '';
    if (is_string($target_branch_ids)) {
        $target_branch_ids = array_filter(explode(',', $target_branch_ids));
    }
    if (!is_array($target_branch_ids) || count($target_branch_ids) === 0) {
        $_SESSION['error_message'] = "Please select at least one branch.";
        header("Location: coverage_requests.php");
        exit();
    }
    if (count($target_branch_ids) > 5) {
        $_SESSION['error_message'] = "You can select up to 5 branches.";
        header("Location: coverage_requests.php");
        exit();
    }
    $shift_date = $_POST['shift_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $role_id = isset($_POST['role_id']) ? (int) $_POST['role_id'] : 0;
    $urgency_level = $_POST['urgency_level'];
    $description = $_POST['description'];
    $expires_hours = $_POST['expires_hours'];
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_hours} hours"));

    // Validate role_id
    if (empty($role_id) || $role_id == 0) {
        $_SESSION['error_message'] = "You must select a valid role for the coverage request.";
        header("Location: coverage_requests.php");
        exit();
    }

    $success_count = 0;
    $fail_count = 0;
    foreach ($target_branch_ids as $target_branch_id) {
        $request_data = [
            'requesting_branch_id' => $user_branch_id,
            'target_branch_id' => $target_branch_id,
            'shift_date' => $shift_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'role_id' => $role_id,
            'urgency_level' => $urgency_level,
            'description' => $description,
            'requested_by_user_id' => $user_id,
            'expires_at' => $expires_at
        ];
        try {
            if (createCrossBranchRequest($conn, $request_data)) {
                $success_count++;
            } else {
                $fail_count++;
            }
        } catch (Exception $e) {
            $fail_count++;
        }
    }
    if ($success_count > 0) {
        $_SESSION['success_message'] = "Coverage request sent to $success_count branch(es)!";
    } else {
        $_SESSION['error_message'] = "Failed to create request.";
    }
    header("Location: coverage_requests.php");
    exit();
}

// Handle offer coverage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['offer_coverage'])) {
    $request_id = $_POST['request_id'];
    try {
        $conn->beginTransaction();

        // Fetch the request details
        $stmt = $conn->prepare("SELECT * FROM cross_branch_shift_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new Exception("Coverage request not found.");
        }

        // Only allow if not already fulfilled
        if ($request['status'] !== 'pending') {
            throw new Exception("This request has already been fulfilled or is not pending.");
        }

        // Get shift details
        $shift_date = $request['shift_date'];
        $start_time = $request['start_time'];
        $end_time = $request['end_time'];
        $branch_id = $request['requesting_branch_id'];
        $role_id = $request['role_id']; // Always use role_id
        $location = 'Coverage at ';
        // Get branch name for location
        $bstmt = $conn->prepare("SELECT name FROM branches WHERE id = ?");
        $bstmt->execute([$branch_id]);
        $branch = $bstmt->fetch(PDO::FETCH_ASSOC);
        if ($branch) {
            $location .= $branch['name'];
        } else {
            $location .= 'Unknown';
        }

        // Ensure role_id is valid (not null or 0)
        if (empty($role_id) || $role_id == 0) {
            // Try to get user's default role
            $role_stmt = $conn->prepare("SELECT role_id FROM users WHERE id = ?");
            $role_stmt->execute([$user_id]);
            $user_role_id = $role_stmt->fetchColumn();
            if ($user_role_id) {
                $role_id = $user_role_id;
            } else {
                throw new Exception("No valid role_id for coverage shift.");
            }
        }

        // Check for duplicate shift
        $check = $conn->prepare('SELECT id FROM shifts WHERE user_id=? AND shift_date=? AND start_time=? AND end_time=? AND branch_id=? AND (role_id <=> ?)');
        $check->execute([$user_id, $shift_date, $start_time, $end_time, $branch_id, $role_id]);
        if (!$check->fetch()) {
            $insert = $conn->prepare('INSERT INTO shifts (user_id, shift_date, start_time, end_time, branch_id, location, role_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([$user_id, $shift_date, $start_time, $end_time, $branch_id, $location, $role_id]);
        }

        // Log coverage
        $log = $conn->prepare('INSERT INTO shift_coverage (request_id, covered_by_user_id) VALUES (?, ?)');
        $log->execute([$request_id, $user_id]);

        // Update request status
        $update = $conn->prepare('UPDATE cross_branch_shift_requests SET status = "fulfilled", fulfilled_by_user_id = ?, fulfilled_at = NOW() WHERE id = ?');
        $update->execute([$user_id, $request_id]);

        $conn->commit();
        $_SESSION['success_message'] = "Coverage offer submitted and shift added successfully!";
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    header("Location: coverage_requests.php");
    exit();
}

// Fetch dropdowns

require_once __DIR__ . '/../functions/coverage_pay_helper.php';
$all_branches = getAllBranches($conn);
$roles = $conn->query("SELECT id, name FROM roles ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch user's upcoming shifts for swap proposals
$my_shifts_stmt = $conn->prepare("SELECT s.*, r.name as role_name FROM shifts s LEFT JOIN roles r ON s.role_id = r.id WHERE s.user_id = ? AND s.shift_date >= CURDATE() ORDER BY s.shift_date, s.start_time");
$my_shifts_stmt->execute([$user_id]);
$my_shifts = $my_shifts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle shift swap proposal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['propose_swap'])) {
    $swap_request_id = $_POST['swap_request_id'];
    $offered_shift_id = $_POST['offered_shift_id'];
    // Insert swap proposal
    $stmt = $conn->prepare("INSERT INTO shift_swaps (request_id, offered_shift_id, proposer_user_id, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
    $stmt->execute([$swap_request_id, $offered_shift_id, $user_id]);
    $_SESSION['success_message'] = "Shift swap proposal sent!";
    header("Location: coverage_requests.php");
    exit();
}

// Handle accept swap
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_swap'])) {
    $swap_id = $_POST['swap_id'];
    // Fetch swap details
    $stmt = $conn->prepare("SELECT ss.*, s.user_id AS offered_user_id, s.id AS offered_shift_id, cbr.requested_by_user_id, cbr.shift_date AS req_shift_date, cbr.start_time AS req_start_time, cbr.end_time AS req_end_time, cbr.role_id AS req_role_id, cbr.target_branch_id AS req_branch_id FROM shift_swaps ss JOIN shifts s ON ss.offered_shift_id = s.id JOIN cross_branch_shift_requests cbr ON ss.request_id = cbr.id WHERE ss.id = ?");
    $stmt->execute([$swap_id]);
    $swap = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($swap) {
        // Find the original request shift (the one to be swapped with)
        $stmt2 = $conn->prepare("SELECT * FROM shifts WHERE user_id = ? AND shift_date = ? AND start_time = ? AND end_time = ? AND role_id = ? AND branch_id = ? LIMIT 1");
        $stmt2->execute([
            $swap['requested_by_user_id'],
            $swap['req_shift_date'],
            $swap['req_start_time'],
            $swap['req_end_time'],
            $swap['req_role_id'],
            $swap['req_branch_id']
        ]);
        $request_shift = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($request_shift) {
            // Swap user_id between the two shifts
            $conn->beginTransaction();
            $stmt3 = $conn->prepare("UPDATE shifts SET user_id = ? WHERE id = ?");
            $stmt3->execute([$swap['offered_user_id'], $request_shift['id']]);
            $stmt3->execute([$swap['requested_by_user_id'], $swap['offered_shift_id']]);
            // Mark swap as accepted
            $stmt4 = $conn->prepare("UPDATE shift_swaps SET status='accepted', accepted_at=NOW() WHERE id=?");
            $stmt4->execute([$swap_id]);
            // Optionally, notify both users
            require_once '../includes/notifications.php';
            addNotification($conn, $swap['offered_user_id'], "Your shift swap has been completed.", "success");
            addNotification($conn, $swap['requested_by_user_id'], "Your shift swap has been completed.", "success");
            $conn->commit();
            $_SESSION['success_message'] = "Shift swap completed and shifts updated!";
        } else {
            $_SESSION['error_message'] = "Could not find the original shift to swap.";
        }
    } else {
        $_SESSION['error_message'] = "Swap proposal not found.";
    }
    header("Location: coverage_requests.php");
    exit();
}

// Fetch all swap proposals for the user (either as proposer or as request owner)
$swap_proposals_stmt = $conn->prepare("
    SELECT ss.*, s.shift_date, s.start_time, s.end_time, s.location, r.name as role_name, u.username as proposer_name, cbr.shift_date as req_shift_date, cbr.start_time as req_start_time, cbr.end_time as req_end_time
    FROM shift_swaps ss
    JOIN shifts s ON ss.offered_shift_id = s.id
    JOIN roles r ON s.role_id = r.id
    JOIN users u ON ss.proposer_user_id = u.id
    JOIN cross_branch_shift_requests cbr ON ss.request_id = cbr.id
    WHERE cbr.requested_by_user_id = ? OR ss.proposer_user_id = ?
    ORDER BY ss.created_at DESC
");
$swap_proposals_stmt->execute([$user_id, $user_id]);
$swap_proposals = $swap_proposals_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load available requests
$sql = "SELECT cbr.*, rb.name AS requesting_branch_name, u.username AS requested_by_username, r.name AS role_name
        FROM cross_branch_shift_requests cbr
        JOIN branches rb ON cbr.requesting_branch_id=rb.id
        JOIN users u ON cbr.requested_by_user_id=u.id
        LEFT JOIN roles r ON cbr.role_id = r.id
        WHERE cbr.target_branch_id=? AND cbr.status='pending' AND cbr.expires_at>NOW()
        ORDER BY cbr.urgency_level DESC, cbr.created_at ASC";
$stmt = $conn->prepare($sql);
$stmt->execute([$user_branch_id]);
$available_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
// DEBUG: Output role_id for each available request
if (!empty($available_requests)) {
    echo '<pre>DEBUG: Available requests role_id values:';
    foreach ($available_requests as $req) {
        echo "\nRequest ID {$req['id']}: role_id=" . var_export($req['role_id'], true);
    }
    echo "</pre>";
}

// Load my requests
$sql = "SELECT cbr.*, tb.name AS target_branch_name, uf.username AS fulfilled_by_username
        FROM cross_branch_shift_requests cbr
        JOIN branches tb ON cbr.target_branch_id=tb.id
        LEFT JOIN users uf ON cbr.fulfilled_by_user_id=uf.id
        WHERE cbr.requested_by_user_id=? AND cbr.status IN ('pending','fulfilled')
        ORDER BY cbr.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([$user_id]);
$my_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Additionally load requests the current user has fulfilled (so they appear under "Requests I Covered")
$sqlFulfilled = "SELECT cbr.*, tb.name AS target_branch_name, uf.username AS fulfilled_by_username,
    (SELECT b.name FROM branches b WHERE b.id = cbr.requesting_branch_id LIMIT 1) AS requesting_branch_name
    FROM cross_branch_shift_requests cbr
    JOIN branches tb ON cbr.target_branch_id=tb.id
    LEFT JOIN users uf ON cbr.fulfilled_by_user_id=uf.id
    LEFT JOIN shift_coverage sc ON sc.request_id = cbr.id
    WHERE cbr.fulfilled_by_user_id = ? AND cbr.status = 'fulfilled'
    ORDER BY cbr.fulfilled_at DESC";
$stmt2 = $conn->prepare($sqlFulfilled);
$stmt2->execute([$user_id]);
$my_fulfilled = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Flash messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Render HTML view below
?>





<!DOCTYPE html>
<html lang="en">

<head>
    <script>
        try {
            if (!document.documentElement.getAttribute('data-theme')) {
                var saved = localStorage.getItem('rota_theme');
                if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
            }
        } catch (e) { }
    </script>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="icon" type="image/png" href="../images/icon.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="apple-touch-icon" href="../images/icon.png">
    <title>Coverage Requests - Open Rota</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/coverage_requests_modern.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/dark_mode.css">
    <style>
        [data-theme="dark"] .page-header,
        [data-theme="dark"] .current-branch-info {
            background: transparent !important;
            color: var(--text) !important;
        }
    </style>
    <style>
        /* Multi-select styles (matching admin) */
        .multi-select {
            position: relative;
            border: 1px solid #ddd;
            padding: 8px;
            border-radius: 6px;
        }

        .multi-select input#branch-search {
            width: 100%;
            padding: 6px;
            border: 1px solid #eee;
            border-radius: 4px;
        }

        .options-list {
            max-height: 150px;
            overflow: auto;
            margin-top: 8px;
            border-top: 1px dashed #f0f0f0;
            padding-top: 8px;
            display: none;
            position: absolute;
            left: 8px;
            right: 8px;
            background: #fff;
            z-index: 50;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .options-list .option {
            padding: 6px;
            cursor: pointer;
            border-radius: 4px;
        }

        .options-list .option:hover {
            background: #f4f4f4;
        }

        .selected-list {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .chip {
            background: #eee;
            padding: 4px 8px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .chip-remove {
            cursor: pointer;
            padding-left: 6px;
            color: #777;
        }

        .hint {
            display: block;
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
    </style>
    <style>
        /* Match application styling */
        body {
            font-family: "newFont", Arial, sans-serif;
            background-image: url(../images/backg3.jpg);
            background-size: cover;
            background-repeat: no-repeat;
            margin: 0;
            padding: 0;
            color: #000000;
            min-height: 100vh;
        }
    </style>
</head>

<body>
    <!-- Navigation Header -->
    <header style="opacity: 1; transition: opacity 0.5s ease;">
        <div class="logo">Open Rota</div>
        <div class="nav-group">
            <div class="notification-container">
                <!-- Bell Icon -->
                <i class="fa fa-bell notification-icon" id="notification-icon"></i>
                <?php if (isset($notificationCount) && $notificationCount > 0): ?>
                    <span class="notification-badge"><?php echo $notificationCount; ?></span>
                <?php endif; ?>

                <!-- Notifications Dropdown -->
                <div class="notification-dropdown" id="notification-dropdown">
                    <?php if (isset($notifications) && !empty($notifications)): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <?php if ($notification['type'] === 'shift-invite' && !empty($notification['related_id'])): ?>
                                <a class="notification-item shit-invt notification-<?php echo $notification['type']; ?>"
                                    data-id="<?php echo $notification['id']; ?>"
                                    href="../functions/pending_shift_invitations.php?invitation_id=<?php echo $notification['related_id']; ?>&notif_id=<?php echo $notification['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                </a>
                            <?php elseif ($notification['type'] === 'shift-swap' && !empty($notification['related_id'])): ?>
                                <a class="notification-item shit-invt notification-<?php echo $notification['type']; ?>"
                                    data-id="<?php echo $notification['id']; ?>"
                                    href="../functions/pending_shift_swaps.php?swap_id=<?php echo $notification['related_id']; ?>&notif_id=<?php echo $notification['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                </a>
                            <?php else: ?>
                                <div class="notification-item notification-<?php echo $notification['type']; ?>"
                                    data-id="<?php echo $notification['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-item">
                            <p>No notifications</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="menu-toggle" id="menu-toggle">
                ☰
            </div>
            <nav class="nav-links" id="nav-links">
                <ul>
                    <li><a href="dashboard.php"><i class="fa fa-tachometer"></i> Dashboard</a></li>
                    <li><a href="shifts.php"><i class="fa fa-calendar"></i> My Shifts</a></li>
                    <li><a href="rota.php"><i class="fa fa-table"></i> Rota</a></li>
                    <li><a href="roles.php"><i class="fa fa-users"></i> Roles</a></li>
                    <li><a href="payroll.php"><i class="fa fa-money"></i> Payroll</a></li>
                    <li><a href="settings.php"><i class="fa fa-cog"></i> Settings</a></li>
                    <li><a href="../functions/logout.php"><i class="fa fa-sign-out"></i> Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-exchange-alt"></i> Shift Coverage</h1>
            <p>Your branch: <strong><?php echo htmlspecialchars($user_branch['name']); ?></strong></p>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Modern Tab System -->
        <div class="tabs">
            <button class="tab active" onclick="showTab('available')">
                <i class="fas fa-list"></i>
                <span>Available</span>
                <?php if (count($available_requests) > 0): ?>
                    <span class="tab-badge"><?php echo count($available_requests); ?></span>
                <?php endif; ?>
            </button>
            <button class="tab" onclick="showTab('create')">
                <i class="fas fa-plus"></i>
                <span>Request</span>
            </button>
            <button class="tab" onclick="showTab('my-requests')">
                <i class="fas fa-paper-plane"></i>
                <span>My Requests</span>
                <?php if (count($my_requests) > 0): ?>
                    <span class="tab-badge"><?php echo count($my_requests); ?></span>
                <?php endif; ?>
            </button>
            <button class="tab" onclick="showTab('swap')">
                <i class="fas fa-exchange-alt"></i>
                <span>Swaps</span>
            </button>
        </div>
        <!-- Shift Swap Tab -->
        <div id="swap-tab" class="tab-content">
            <div class="form-section">
                <h2><i class="fas fa-exchange-alt"></i> Shift Swap Proposals</h2>
                <p>Propose a swap by offering one of your shifts in exchange for a coverage request, or accept a swap
                    offered to you.</p>

                <h3>Propose a Swap</h3>
                <form method="POST">
                <div class="form-group">
                    <label for="swap_request_id">Select Coverage Request to Swap With:</label>
                    <select name="swap_request_id" required>
                        <option value="">Select a coverage request...</option>
                        <?php foreach ($available_requests as $req): ?>
                            <option value="<?php echo $req['id']; ?>">
                                <?php echo htmlspecialchars($req['requesting_branch_name']) . ' - ' . date('M j, Y', strtotime($req['shift_date'])) . ' ' . date('g:i A', strtotime($req['start_time'])) . ' - ' . date('g:i A', strtotime($req['end_time'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="offered_shift_id">Select One of Your Shifts to Offer:</label>
                    <select name="offered_shift_id" required>
                        <option value="">Select your shift...</option>
                        <?php foreach ($my_shifts as $shift): ?>
                            <option value="<?php echo $shift['id']; ?>">
                                <?php echo date('M j, Y', strtotime($shift['shift_date'])) . ' ' . date('g:i A', strtotime($shift['start_time'])) . ' - ' . date('g:i A', strtotime($shift['end_time'])) . ' (' . htmlspecialchars($shift['role_name']) . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="propose_swap" class="btn btn-primary">Propose Swap</button>
            </form>

            <h3 style="margin-top:30px;">Swap Proposals</h3>
            <?php if (empty($swap_proposals)): ?>
                <div class="request-card">
                    <p><i class="fas fa-info-circle"></i> No swap proposals yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($swap_proposals as $swap): ?>
                    <div class="request-card">
                        <div><strong>Proposed by:</strong> <?php echo htmlspecialchars($swap['proposer_name']); ?></div>
                        <div><strong>Offered Shift:</strong>
                            <?php echo date('M j, Y', strtotime($swap['shift_date'])) . ' ' . date('g:i A', strtotime($swap['start_time'])) . ' - ' . date('g:i A', strtotime($swap['end_time'])) . ' (' . htmlspecialchars($swap['role_name']) . ')'; ?>
                        </div>
                        <div><strong>For Coverage Request:</strong>
                            <?php echo date('M j, Y', strtotime($swap['req_shift_date'])) . ' ' . date('g:i A', strtotime($swap['req_start_time'])) . ' - ' . date('g:i A', strtotime($swap['req_end_time'])); ?>
                        </div>
                        <div><strong>Status:</strong> <?php echo ucfirst($swap['status']); ?></div>
                        <?php if ($swap['status'] === 'pending' && $swap['proposer_user_id'] != $user_id): ?>
                            <form method="POST" style="margin-top:10px;">
                                <input type="hidden" name="swap_id" value="<?php echo $swap['id']; ?>">
                                <button type="submit" name="accept_swap" class="btn btn-success">Accept Swap</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>

        <!-- Available Coverage Tab -->
        <div id="available-tab" class="tab-content active">
            <?php if (empty($available_requests)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No Coverage Requests</h3>
                    <p>There are no coverage requests available at this time.</p>
                </div>
            <?php else: ?>
                <?php foreach ($available_requests as $request): ?>
                    <?php
                    $estimated_pay = calculateCoverageRequestPay($conn, $request);
                    $urgency_class = 'urgency-' . $request['urgency_level'];
                    ?>
                    <div class="request-card <?php echo $urgency_class; ?>">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-building"></i>
                                Coverage at <?php echo htmlspecialchars($request['requesting_branch_name']); ?>
                            </h3>
                            <span class="urgency-badge <?php echo $urgency_class; ?>">
                                <?php echo ucfirst($request['urgency_level']); ?>
                            </span>
                        </div>

                        <div class="request-details">
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-calendar"></i>
                                    Date & Time
                                </div>
                                <div class="detail-value">
                                    <?php echo date('l, M j, Y', strtotime($request['shift_date'])); ?><br>
                                    <?php echo date('g:i A', strtotime($request['start_time'])); ?> - 
                                    <?php echo date('g:i A', strtotime($request['end_time'])); ?>
                                </div>
                            </div>

                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-user-tag"></i>
                                    Role Required
                                </div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($request['role_name'] ?: 'Any Role'); ?>
                                </div>
                            </div>

                            <div class="detail-item pay-item">
                                <div class="detail-label">
                                    <i class="fas fa-pound-sign"></i>
                                    Estimated Pay
                                </div>
                                <div class="detail-value">
                                    £<?php echo number_format($estimated_pay, 2); ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($request['description']): ?>
                            <div class="detail-item" style="margin-top: 12px;">
                                <div class="detail-label">
                                    <i class="fas fa-comment"></i>
                                    Details
                                </div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($request['description']); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="card-actions">
                            <form method="POST" style="display: inline-block;">
                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                <button type="submit" name="offer_coverage" class="btn btn-success">
                                    <i class="fas fa-check"></i>
                                    Accept Request
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Create Request Tab -->
        <div id="create-tab" class="tab-content">
            <div class="form-section">
                <h2><i class="fas fa-plus"></i> Request Coverage</h2>
                <p>Request coverage from other branches when you need help with shifts.</p>
                
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="target_branch_id">Request Coverage From:</label>
                            <select name="target_branch_id" class="form-control" required>
                                <option value="">Select a branch...</option>
                                <?php foreach ($all_branches as $branch): ?>
                                    <?php if ($branch['id'] != $user_branch_id): ?>
                                        <option value="<?php echo $branch['id']; ?>">
                                            <?php echo htmlspecialchars($branch['name']); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="role_id">Role Required:</label>
                            <select name="role_id" class="form-control" required>
                                <option value="">Select role...</option>
                                <?php
                                $roles_stmt = $conn->query("SELECT id, name FROM roles ORDER BY name");
                                while ($role = $roles_stmt->fetch(PDO::FETCH_ASSOC)):
                                ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="shift_date">Date:</label>
                            <input type="date" name="shift_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="urgency_level">Urgency Level:</label>
                            <select name="urgency_level" class="form-control" required>
                                <option value="low">Low - Plan Ahead</option>
                                <option value="medium" selected>Medium - Normal</option>
                                <option value="high">High - Urgent</option>
                                <option value="critical">Critical - Emergency</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="start_time">Start Time:</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="end_time">End Time:</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Additional Details:</label>
                        <textarea name="description" class="form-control" placeholder="Provide any additional information about this coverage request..."></textarea>
                    </div>

                    <button type="submit" name="create_request" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i>
                        Submit Request
                    </button>
                </form>
            </div>
        </div>
                            <script>
                                // Robust custom multi-select for branches (up to 5, mobile friendly, with search)
                                document.addEventListener('DOMContentLoaded', function () {
                                    function setupBranchMultiselect(multiselectId, dropdownId, selectedDivId, hiddenInputId, searchId, optionsListId) {
                                        const multiselect = document.getElementById(multiselectId);
                                        const selectedDiv = document.getElementById(selectedDivId);
                                        const dropdown = document.getElementById(dropdownId);
                                        const hiddenInput = document.getElementById(hiddenInputId);
                                        const searchInput = document.getElementById(searchId);
                                        const optionsList = document.getElementById(optionsListId);
                                        if (!multiselect || !selectedDiv || !dropdown || !hiddenInput || !searchInput || !optionsList) return;
                                        let selected = [];
                                        // Prepopulate if checkboxes are checked
                                        function getBranchName(cb) {
                                            return cb.parentElement.textContent.trim();
                                        }
                                        function updateSelectedDisplay() {
                                            selectedDiv.innerHTML = '';
                                            let any = false;
                                            optionsList.querySelectorAll('.branch-checkbox').forEach(cb => {
                                                if (cb.checked) {
                                                    any = true;
                                                    const badge = document.createElement('span');
                                                    badge.textContent = getBranchName(cb);
                                                    badge.style.cssText = 'background:#007bff;color:#fff;padding:3px 10px;border-radius:12px;margin:2px 6px 2px 0;font-size:14px;display:inline-flex;align-items:center;';
                                                    const remove = document.createElement('span');
                                                    remove.textContent = '×';
                                                    remove.style.cssText = 'margin-left:7px;cursor:pointer;font-weight:bold;';
                                                    remove.onclick = function (e) {
                                                        e.stopPropagation();
                                                        cb.checked = false;
                                                        updateSelectedDisplay();
                                                        updateHiddenInput();
                                                    };
                                                    badge.appendChild(remove);
                                                    selectedDiv.appendChild(badge);
                                                }
                                            });
                                            if (!any) selectedDiv.textContent = 'Select branches...';
                                        }
                                        function updateHiddenInput() {
                                            const checked = Array.from(optionsList.querySelectorAll('.branch-checkbox:checked')).map(cb => cb.value);
                                            hiddenInput.value = checked.join(',');
                                        }
                                        // Toggle dropdown
                                        selectedDiv.onclick = function (e) {
                                            if (dropdown.style.display === 'block') {
                                                dropdown.style.display = 'none';
                                            } else {
                                                dropdown.style.display = 'block';
                                                searchInput.value = '';
                                                filterOptions('');
                                                searchInput.focus();
                                            }
                                            e.stopPropagation();
                                        };
                                        // Prevent closing when clicking inside dropdown or multiselect
                                        dropdown.addEventListener('mousedown', function (e) { e.stopPropagation(); });
                                        multiselect.addEventListener('mousedown', function (e) { e.stopPropagation(); });
                                        // Close dropdown on outside click
                                        document.addEventListener('mousedown', function (e) {
                                            if (!multiselect.contains(e.target)) {
                                                dropdown.style.display = 'none';
                                            }
                                        });
                                        // Limit selection to 5
                                        optionsList.querySelectorAll('.branch-checkbox').forEach(cb => {
                                            cb.onchange = function () {
                                                const checked = optionsList.querySelectorAll('.branch-checkbox:checked');
                                                if (checked.length > 5) {
                                                    cb.checked = false;
                                                    alert('You can select up to 5 branches.');
                                                }
                                                updateSelectedDisplay();
                                                updateHiddenInput();
                                            };
                                        });
                                        // Search filter
                                        function filterOptions(query) {
                                            const q = query.trim().toLowerCase();
                                            optionsList.querySelectorAll('.branch-option').forEach(opt => {
                                                if (!q || opt.getAttribute('data-name').includes(q)) {
                                                    opt.style.display = 'block';
                                                } else {
                                                    opt.style.display = 'none';
                                                }
                                            });
                                        }
                                        searchInput.addEventListener('input', function () {
                                            filterOptions(this.value);
                                        });
                                        // Keyboard accessibility
                                        selectedDiv.addEventListener('keydown', function (e) {
                                            if (e.key === 'Enter' || e.key === ' ') {
                                                e.preventDefault();
                                                if (dropdown.style.display === 'block') {
                                                    dropdown.style.display = 'none';
                                                } else {
                                                    dropdown.style.display = 'block';
                                                    searchInput.value = '';
                                                    filterOptions('');
                                                    searchInput.focus();
                                                }
                                            }
                                        });
                                        // Initial state
                                        updateSelectedDisplay();
                                        updateHiddenInput();
                                    }
                                    // Setup for create request
                                    setupBranchMultiselect('branch-multiselect', 'branch-dropdown', 'selected-branches', 'target_branch_ids', 'branch-search', 'branch-options-list');
                                });
                            </script>


        <!-- My Requests Tab -->
        <div id="my-requests-tab" class="tab-content">
            <?php if (empty($my_requests)): ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No Requests Yet</h3>
                    <p>You haven't created any coverage requests yet.</p>
                    <button class="btn btn-primary" onclick="showTab('create')">
                        <i class="fas fa-plus"></i>
                        Create Request
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($my_requests as $request): ?>
                    <?php
                    $status_class = $request['status'];
                    $status_icon = $request['status'] === 'fulfilled' ? 'check-circle' : 
                                  ($request['status'] === 'pending' ? 'clock' : 'times-circle');
                    ?>
                    <div class="request-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-<?php echo $status_icon; ?>"></i>
                                Request to <?php echo htmlspecialchars($request['target_branch_name']); ?>
                            </h3>
                            <span class="urgency-badge urgency-<?php echo $request['urgency_level']; ?>">
                                <?php echo ucfirst($request['status']); ?>
                            </span>
                        </div>

                        <div class="request-details">
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-calendar"></i>
                                    Date & Time
                                </div>
                                <div class="detail-value">
                                    <?php echo date('l, M j, Y', strtotime($request['shift_date'])); ?><br>
                                    <?php echo date('g:i A', strtotime($request['start_time'])); ?> - 
                                    <?php echo date('g:i A', strtotime($request['end_time'])); ?>
                                </div>
                            </div>

                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-user-tag"></i>
                                    Role
                                </div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($request['role_name'] ?: 'Any Role'); ?>
                                </div>
                            </div>

                            <?php if ($request['accepted_by_username']): ?>
                                <div class="detail-item">
                                    <div class="detail-label">
                                        <i class="fas fa-user-check"></i>
                                        Accepted By
                                    </div>
                                    <div class="detail-value">
                                        <?php echo htmlspecialchars($request['accepted_by_username']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($request['description']): ?>
                            <div class="detail-item" style="margin-top: 12px;">
                                <div class="detail-label">
                                    <i class="fas fa-comment"></i>
                                    Details
                                </div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($request['description']); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($request['status'] === 'pending'): ?>
                            <div class="card-actions">
                                <form method="POST" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete this request?')">
                                    <input type="hidden" name="delete_request_id" value="<?php echo $request['id']; ?>">
                                    <button type="submit" name="delete_request" class="btn btn-danger">
                                        <i class="fas fa-trash"></i>
                                        Delete Request
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Requests I Covered -->
            <div style="background: white; border-radius: 8px; padding: 16px 20px; margin: 30px 0 16px 0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                <h2 style="margin: 0;"><i class="fas fa-check"></i> Requests I Covered</h2>
            </div>
            <?php if (empty($my_fulfilled)): ?>
                <div class="request-card">
                    <p><i class="fas fa-info-circle"></i> You haven't covered any requests yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($my_fulfilled as $f): ?>
                    <div class="request-card fulfilled">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <h3><i class="fas fa-user-check"></i> Covered:
                                <?php echo htmlspecialchars($f['requesting_branch_name'] ?? 'requesting branch'); ?></h3>
                            <span><?php echo date('M j, Y', strtotime($f['fulfilled_at'] ?? $f['created_at'])); ?></span>
                        </div>
                        <div class="request-details">
                            <div class="detail-item">
                                <div class="detail-label">When</div>
                                <div class="detail-value"><?php echo date('M j, Y', strtotime($f['shift_date'])); ?>
                                    <?php echo date('g:i A', strtotime($f['start_time'])); ?> -
                                    <?php echo date('g:i A', strtotime($f['end_time'])); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Target Branch</div>
                                <div class="detail-value"><?php echo htmlspecialchars($f['target_branch_name']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Notes</div>
                                <div class="detail-value"><?php echo nl2br(htmlspecialchars($f['description'] ?? '')); ?></div>
                            </div>
                        </div>
                        <div style="margin-top:10px; text-align:right;">
                            <a href="coverage_requests.php#request-<?php echo (int) $f['id']; ?>"
                                class="btn btn-secondary btn-sm">View</a>
                            <?php if (!empty($f['shift_id'])): ?>
                                <form method="POST" style="display:inline; margin-left:8px;"
                                    onsubmit="return confirm('Remove the created shift and revert request to pending? This will delete the shift for you.');">
                                    <input type="hidden" name="clear_covered_request_id" value="<?php echo (int) $f['id']; ?>">
                                    <input type="hidden" name="clear_covered_shift_id" value="<?php echo (int) $f['shift_id']; ?>">
                                    <button type="submit" name="clear_covered" class="btn btn-danger btn-sm"><i
                                            class="fas fa-trash"></i> Remove Shift</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if (isset($show_edit_modal) && $show_edit_modal && isset($edit_request) && $edit_request): ?>
            <div id="editRequestModal" class="modal"
                style="display:block; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:2000;">
                <div class="modal-content"
                    style="background:#fff; margin:30px auto; padding:30px; border-radius:10px; max-width:500px; position:relative; max-height:90vh; overflow-y:auto;">
                    <span class="close-modal" onclick="window.location.href='coverage_requests.php'"
                        style="position:absolute; top:10px; right:18px; font-size:2rem; cursor:pointer;">&times;</span>
                    <h3>Edit Coverage Request</h3>
                    <form method="POST" id="edit-request-form">
                        <input type="hidden" name="edit_id" value="<?php echo htmlspecialchars($edit_request['id']); ?>">
                        <div class="form-group">
                            <label for="edit_target_branch_ids">Target Branch</label>
                            <div class="branch-multiselect" id="edit-branch-multiselect">
                                <div class="selected-branches" id="edit-selected-branches" tabindex="0">Select branch...
                                </div>
                                <div class="branch-dropdown" id="edit-branch-dropdown" style="display:none;">
                                    <?php foreach ($all_branches as $branch): ?>
                                        <label>
                                            <input type="checkbox" class="edit-branch-checkbox"
                                                value="<?php echo $branch['id']; ?>">
                                            <?php echo htmlspecialchars($branch['name']); ?>
                                            (<?php echo htmlspecialchars($branch['code']); ?>)
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <input type="hidden" name="target_branch_ids[]" id="edit_target_branch_ids">
                        </div>
                        <div class="form-group">
                            <label for="shift_date">Shift Date</label>
                            <input type="date" name="shift_date"
                                value="<?php echo htmlspecialchars($edit_request['shift_date']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="start_time">Start Time</label>
                            <input type="time" name="start_time"
                                value="<?php echo htmlspecialchars($edit_request['start_time']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="end_time">End Time</label>
                            <input type="time" name="end_time"
                                value="<?php echo htmlspecialchars($edit_request['end_time']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="role_id">Role Required</label>
                            <select name="role_id" required>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>" <?php if ($edit_request['role_id'] == $role['id'])
                                           echo 'selected'; ?>><?php echo htmlspecialchars($role['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="urgency_level">Urgency Level</label>
                            <select name="urgency_level" required>
                                <option value="low" <?php if ($edit_request['urgency_level'] == 'low')
                                    echo 'selected'; ?>>Low</option>
                                <option value="medium" <?php if ($edit_request['urgency_level'] == 'medium')
                                    echo 'selected'; ?>>Medium</option>
                                <option value="high" <?php if ($edit_request['urgency_level'] == 'high')
                                    echo 'selected'; ?>>High</option>
                                <option value="critical" <?php if ($edit_request['urgency_level'] == 'critical')
                                    echo 'selected'; ?>>Critical</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea name="description"
                                rows="3"><?php echo htmlspecialchars($edit_request['description']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="expires_hours">Expires In (hours)</label>
                            <input type="number" name="expires_hours" min="1" max="168"
                                value="<?php echo isset($edit_request['expires_at']) ? round((strtotime($edit_request['expires_at']) - time()) / 3600) : 24; ?>"
                                required>
                        </div>
                        <button type="submit" name="update_request" class="btn btn-primary">Update Request</button>
                        <button type="button" class="btn btn-secondary"
                            onclick="window.location.href='coverage_requests.php'">Cancel</button>
                    </form>
                    <script>
                        // Custom Multi-Select for Branches (up to 5, mobile friendly)
                        document.addEventListener('DOMContentLoaded', function () {
                            const multiselect = document.getElementById('branch-multiselect');
                            const selectedDiv = document.getElementById('selected-branches');
                            const dropdown = document.getElementById('branch-dropdown');
                            const checkboxes = dropdown.querySelectorAll('.branch-checkbox');
                            const hiddenInput = document.getElementById('target_branch_ids');
                            let selected = [];

                            function updateSelectedDisplay() {
                                selectedDiv.innerHTML = '';
                                if (selected.length === 0) {
                                    selectedDiv.textContent = 'Select branches...';
                                } else {
                                    selected.forEach(obj => {
                                        const badge = document.createElement('span');
                                        badge.className = 'selected-branch-badge';
                                        badge.textContent = obj.name;
                                        const remove = document.createElement('span');
                                        remove.className = 'remove-branch';
                                        remove.textContent = '×';
                                        remove.onclick = function (e) {
                                            e.stopPropagation();
                                            checkboxes.forEach(cb => {
                                                if (cb.value === obj.id) cb.checked = false;
                                            });
                                            selected = selected.filter(b => b.id !== obj.id);
                                            updateSelectedDisplay();
                                            updateHiddenInput();
                                        };
                                        badge.appendChild(remove);
                                        selectedDiv.appendChild(badge);
                                    });
                                }
                            }

                            function updateHiddenInput() {
                                hiddenInput.value = selected.map(b => b.id).join(',');
                            }

                            selectedDiv.addEventListener('click', function () {
                                dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
                            });
                            selectedDiv.addEventListener('keydown', function (e) {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
                                }
                            });
                            document.addEventListener('click', function (e) {
                                if (!multiselect.contains(e.target)) {
                                    dropdown.style.display = 'none';
                                }
                            });

                            checkboxes.forEach(cb => {
                                cb.addEventListener('change', function () {
                                    if (cb.checked) {
                                        if (selected.length >= 5) {
                                            cb.checked = false;
                                            alert('You can select up to 5 branches.');
                                            return;
                                        }
                                        selected.push({ id: cb.value, name: cb.parentElement.textContent.trim() });
                                    } else {
                                        selected = selected.filter(b => b.id !== cb.value);
                                    }
                                    updateSelectedDisplay();
                                    updateHiddenInput();
                                });
                            });

                            // On form submit, set hidden input as array
                            document.getElementById('coverage-request-form').addEventListener('submit', function (e) {
                                hiddenInput.value = selected.map(b => b.id);
                                if (selected.length === 0) {
                                    alert('Please select at least one branch.');
                                    e.preventDefault();
                                }
                            });

                            // Initialize
                            updateSelectedDisplay();
                            updateHiddenInput();
                        });
                        // Edit modal multi-select logic (single branch for now, but UI matches main form)
                        document.addEventListener('DOMContentLoaded', function () {
                            const multiselect = document.getElementById('edit-branch-multiselect');
                            const selectedDiv = document.getElementById('edit-selected-branches');
                            const dropdown = document.getElementById('edit-branch-dropdown');
                            const checkboxes = dropdown.querySelectorAll('.edit-branch-checkbox');
                            const hiddenInput = document.getElementById('edit_target_branch_ids');
                            let selected = [];
                            // Pre-select the branch from the request
                            checkboxes.forEach(cb => {
                                if (cb.value == <?php echo json_encode($edit_request['target_branch_id']); ?>) {
                                    cb.checked = true;
                                    selected.push({ id: cb.value, name: cb.parentElement.textContent.trim() });
                                }
                            });
                            function updateSelectedDisplay() {
                                selectedDiv.innerHTML = '';
                                if (selected.length === 0) {
                                    selectedDiv.textContent = 'Select branch...';
                                } else {
                                    selected.forEach(obj => {
                                        const badge = document.createElement('span');
                                        badge.className = 'selected-branch-badge';
                                        badge.textContent = obj.name;
                                        const remove = document.createElement('span');
                                        remove.className = 'remove-branch';
                                        remove.textContent = '×';
                                        remove.onclick = function (e) {
                                            e.stopPropagation();
                                            checkboxes.forEach(cb => {
                                                if (cb.value === obj.id) cb.checked = false;
                                            });
                                            selected = selected.filter(b => b.id !== obj.id);
                                            updateSelectedDisplay();
                                            updateHiddenInput();
                                        };
                                        badge.appendChild(remove);
                                        selectedDiv.appendChild(badge);
                                    });
                                }
                            }
                            function updateHiddenInput() {
                                hiddenInput.value = selected.map(b => b.id).join(',');
                            }
                            selectedDiv.addEventListener('click', function () {
                                dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
                            });
                            selectedDiv.addEventListener('keydown', function (e) {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
                                }
                            });
                            document.addEventListener('click', function (e) {
                                if (!multiselect.contains(e.target)) {
                                    dropdown.style.display = 'none';
                                }
                            });
                            checkboxes.forEach(cb => {
                                cb.addEventListener('change', function () {
                                    if (cb.checked) {
                                        // Only allow one for edit (for now)
                                        checkboxes.forEach(other => { if (other !== cb) other.checked = false; });
                                        selected = [{ id: cb.value, name: cb.parentElement.textContent.trim() }];
                                    } else {
                                        selected = [];
                                    }
                                    updateSelectedDisplay();
                                    updateHiddenInput();
                                });
                            });
                            document.getElementById('edit-request-form').addEventListener('submit', function (e) {
                                hiddenInput.value = selected.map(b => b.id);
                                if (selected.length === 0) {
                                    alert('Please select a branch.');
                                    e.preventDefault();
                                }
                            });
                            updateSelectedDisplay();
                            updateHiddenInput();
                        });
                    </script>
                </div>
            </div>
            <script>
                // Custom Multi-Select for Branches (up to 5)
                document.addEventListener('DOMContentLoaded', function () {
                    const multiselect = document.getElementById('branch-multiselect');
                    const selectedDiv = document.getElementById('selected-branches');
                    const dropdown = document.getElementById('branch-dropdown');
                    const checkboxes = dropdown.querySelectorAll('.branch-checkbox');
                    const hiddenInput = document.getElementById('target_branch_ids');
                    let selected = [];

                    function updateSelectedDisplay() {
                        selectedDiv.innerHTML = '';
                        if (selected.length === 0) {
                            selectedDiv.textContent = 'Select branches...';
                        } else {
                            selected.forEach(obj => {
                                const badge = document.createElement('span');
                                badge.className = 'selected-branch-badge';
                                badge.textContent = obj.name;
                                const remove = document.createElement('span');
                                remove.className = 'remove-branch';
                                remove.textContent = '×';
                                remove.onclick = function (e) {
                                    e.stopPropagation();
                                    checkboxes.forEach(cb => {
                                        if (cb.value === obj.id) cb.checked = false;
                                    });
                                    selected = selected.filter(b => b.id !== obj.id);
                                    updateSelectedDisplay();
                                    updateHiddenInput();
                                };
                                badge.appendChild(remove);
                                selectedDiv.appendChild(badge);
                            });
                        }
                    }

                    function updateHiddenInput() {
                        hiddenInput.value = selected.map(b => b.id).join(',');
                    }

                    selectedDiv.addEventListener('click', function () {
                        dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
                    });
                    selectedDiv.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
                        }
                    });
                    document.addEventListener('click', function (e) {
                        if (!multiselect.contains(e.target)) {
                            dropdown.style.display = 'none';
                        }
                    });

                    checkboxes.forEach(cb => {
                        cb.addEventListener('change', function () {
                            if (cb.checked) {
                                if (selected.length >= 5) {
                                    cb.checked = false;
                                    alert('You can select up to 5 branches.');
                                    return;
                                }
                                selected.push({ id: cb.value, name: cb.parentElement.textContent.trim() });
                            } else {
                                selected = selected.filter(b => b.id !== cb.value);
                            }
                            updateSelectedDisplay();
                            updateHiddenInput();
                        });
                    });

                    // On form submit, set hidden input as array
                    document.getElementById('coverage-request-form').addEventListener('submit', function (e) {
                        hiddenInput.value = selected.map(b => b.id);
                        if (selected.length === 0) {
                            alert('Please select at least one branch.');
                            e.preventDefault();
                        }
                    });

                    // Initialize
                    updateSelectedDisplay();
                    updateHiddenInput();
                });
                // Close modal on outside click
                window.onclick = function (event) {
                    var modal = document.getElementById('editRequestModal');
                    if (modal && event.target === modal) {
                        window.location.href = 'coverage_requests.php';
                    }
                }
            </script>
        <?php endif; ?>
    </div>

    <div style="margin-top: 30px; text-align: center;">
        <a href="dashboard.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
    </div>

    <script>
            (function () {
                const maxSelect = 5;
                const selectedContainer = document.getElementById('branch-selected');
                const optionsList = document.getElementById('branch-options');
                const options = optionsList ? optionsList.querySelectorAll('.option') : [];
                const search = document.getElementById('branch-search');
                const hiddenInput = document.getElementById('target_branch_ids');
                let selected = [];

                function renderSelected() {
                    selectedContainer.innerHTML = '';
                    selected.forEach(id => {
                        const opt = Array.from(options).find(o => parseInt(o.dataset.id, 10) === id);
                        if (!opt) return;
                        const chip = document.createElement('span');
                        chip.className = 'chip';
                        chip.textContent = opt.textContent.trim();
                        const rem = document.createElement('span');
                        rem.className = 'chip-remove';
                        rem.innerHTML = '&times;';
                        rem.addEventListener('click', () => {
                            selected = selected.filter(s => s !== id);
                            updateHidden();
                            renderSelected();
                        });
                        chip.appendChild(rem);
                        selectedContainer.appendChild(chip);
                    });
                }

                function updateHidden() {
                    if (hiddenInput) hiddenInput.value = selected.join(',');
                }

                function filterOptions(q) {
                    const term = q.trim().toLowerCase();
                    Array.from(options).forEach(o => {
                        const txt = o.textContent.toLowerCase();
                        o.style.display = txt.indexOf(term) === -1 ? 'none' : 'block';
                    });
                }

                function positionOptions() {
                    const container = document.getElementById('branch-multi-select');
                    const selectedRect = selectedContainer.getBoundingClientRect();
                    optionsList.style.top = (selectedContainer.offsetTop + selectedContainer.offsetHeight + 6) + 'px';
                    optionsList.style.left = '8px';
                    optionsList.style.right = '8px';
                }

                function showOptions() { positionOptions(); optionsList.style.display = 'block'; }
                function hideOptions() { optionsList.style.display = 'none'; }

                document.addEventListener('click', function (e) {
                    const container = document.getElementById('branch-multi-select');
                    if (!container) return;
                    if (!container.contains(e.target)) {
                        hideOptions();
                    }
                });

                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') hideOptions();
                });

                Array.from(options).forEach(o => {
                    o.addEventListener('click', () => {
                        const id = parseInt(o.dataset.id, 10);
                        if (selected.includes(id)) return;
                        if (selected.length >= maxSelect) {
                            alert('You can select up to ' + maxSelect + ' branches.');
                            return;
                        }
                        selected.push(id);
                        updateHidden();
                        renderSelected();
                        positionOptions();
                        showOptions();
                    });
                });

                if (search) {
                    search.addEventListener('input', (e) => { filterOptions(e.target.value); });
                    search.addEventListener('focus', () => { filterOptions(search.value); showOptions(); });
                }
            })();

        // Notification functionality
        function markAsRead(element) {
            const notificationId = element.getAttribute('data-id');
            fetch('../functions/mark_notification.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: notificationId })
            })
                .then(response => response.json())
                .then(data => {
                    console.log('Response data:', data); // Debug log
                    if (data.success) {
                        element.style.display = 'none';

                        // Count remaining visible notifications more reliably
                        const allNotifications = document.querySelectorAll('.notification-item[data-id]');
                        let visibleCount = 0;

                        allNotifications.forEach(notification => {
                            const computedStyle = window.getComputedStyle(notification);
                            if (computedStyle.display !== 'none') {
                                visibleCount++;
                            }
                        });

                        console.log('Total notifications with data-id:', allNotifications.length); // Debug log
                        console.log('Visible notifications count:', visibleCount); // Debug log

                        if (visibleCount === 0) {
                            document.getElementById('notification-dropdown').innerHTML = '<div class="notification-item"><p>No notifications</p></div>';
                            const badge = document.querySelector('.notification-badge');
                            if (badge) {
                                badge.style.display = 'none';
                                console.log('Badge hidden - no notifications left'); // Debug log
                            }
                        } else {
                            const badge = document.querySelector('.notification-badge');
                            if (badge) {
                                badge.textContent = visibleCount;
                                badge.style.display = 'flex'; // Ensure badge is visible
                                console.log('Badge updated to:', visibleCount); // Debug log
                            }
                        }
                    } else {
                        console.error('Failed to mark notification as read:', data.error);
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Tab functionality - make sure it's global
        function showTab(tabName) {
            console.log('showTab called with:', tabName); // Debug log
            
            // Hide all tab contents
            const allTabContents = document.querySelectorAll('.tab-content');
            console.log('Found tab contents:', allTabContents.length);
            allTabContents.forEach(content => {
                content.classList.remove('active');
                console.log('Hiding:', content.id);
            });
            
            // Remove active class from all tabs
            const allTabs = document.querySelectorAll('.tab');
            console.log('Found tabs:', allTabs.length);
            allTabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            const targetTabId = tabName + '-tab';
            const tabContent = document.getElementById(targetTabId);
            console.log('Looking for tab content:', targetTabId, 'Found:', !!tabContent);
            
            if (tabContent) {
                tabContent.classList.add('active');
                console.log('Showing tab:', targetTabId);
            } else {
                console.error('Tab content not found:', targetTabId);
                // List all available tab content IDs for debugging
                const allTabContents = document.querySelectorAll('[id$="-tab"]');
                console.log('Available tab IDs:', Array.from(allTabContents).map(el => el.id));
            }
            
            // Add active class to clicked tab - get event from global scope if available
            let clickedTab = null;
            if (typeof event !== 'undefined' && event.target) {
                clickedTab = event.target.closest('.tab');
            }
            
            // Fallback: find the tab button that corresponds to this tabName
            if (!clickedTab) {
                const tabButtons = document.querySelectorAll('.tab');
                for (let tab of tabButtons) {
                    if (tab.getAttribute('onclick') && tab.getAttribute('onclick').includes(tabName)) {
                        clickedTab = tab;
                        break;
                    }
                }
            }
            
            if (clickedTab) {
                clickedTab.classList.add('active');
                console.log('Made tab active:', clickedTab);
            } else {
                console.error('Could not find clicked tab for:', tabName);
            }
        }

        // Test function that can be called from console
        function testTabs() {
            console.log('Testing tabs...');
            console.log('Available tab elements:', document.querySelectorAll('.tab-content'));
            console.log('Tab buttons:', document.querySelectorAll('.tab'));
            
            // Test each tab
            ['available', 'create', 'my-requests', 'swap'].forEach(tabName => {
                console.log(`Testing ${tabName}...`);
                showTab(tabName);
            });
        }
        
        // Make functions global for console access
        window.showTab = showTab;
        window.testTabs = testTabs;

        document.addEventListener('DOMContentLoaded', function () {
            // Tab setup - add event listeners to all tab buttons
            const tabButtons = document.querySelectorAll('.tab');
            console.log('Setting up tabs, found buttons:', tabButtons.length);
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const onclick = this.getAttribute('onclick');
                    if (onclick) {
                        const match = onclick.match(/showTab\('(.+?)'\)/);
                        if (match) {
                            const tabName = match[1];
                            console.log('Tab clicked:', tabName);
                            showTab(tabName);
                        }
                    }
                });
            });
            
            // Notification setup
            var notificationIcon = document.getElementById('notification-icon');
            var dropdown = document.getElementById('notification-dropdown');

            if (notificationIcon && dropdown) {
                notificationIcon.addEventListener('click', function (e) {
                    e.stopPropagation();
                    dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
                });
            }

            document.addEventListener('click', function (e) {
                if (dropdown && !dropdown.contains(e.target) && !notificationIcon.contains(e.target)) {
                    dropdown.style.display = "none";
                }
            });
        });
    </script>
    <script src="../js/menu.js"></script>
    <script src="../js/pwa-debug.js"></script>
    <script src="../js/links.js"></script>
</body>

</html>