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

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
    $delete_id = isset($_POST['delete_request_id']) ? (int)$_POST['delete_request_id'] : 0;
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
        if ((int)$owner !== (int)$user_id && !$isAdmin) {
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
        if ($conn->inTransaction()) $conn->rollBack();
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
    $target_branch_id = $_POST['target_branch_id'];
    $shift_date = $_POST['shift_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $role_id = isset($_POST['role_id']) && $_POST['role_id'] !== '' ? (int)$_POST['role_id'] : null;
    $urgency_level = $_POST['urgency_level'];
    $description = $_POST['description'];
    $expires_hours = $_POST['expires_hours'];
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_hours} hours"));
    $source_shift_id = isset($_POST['source_shift_id']) && $_POST['source_shift_id'] !== '' ? (int)$_POST['source_shift_id'] : null;

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
    $raw_target_ids = $_POST['target_branch_ids'] ?? '';
    $target_branch_ids = array_filter(array_map('intval', explode(',', $raw_target_ids)));
    // Limit to 5
    $target_branch_ids = array_slice($target_branch_ids, 0, 5);
    if (empty($target_branch_ids)) {
        $_SESSION['error_message'] = 'Please select at least one target branch (up to 5).';
        header("Location: coverage_requests.php");
        exit();
    }

    $shift_date = $_POST['shift_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $role_id = isset($_POST['role_id']) && $_POST['role_id'] !== '' ? (int)$_POST['role_id'] : null;
    $urgency_level = $_POST['urgency_level'];
    $description = $_POST['description'] ?? null;
    $expires_hours = $_POST['expires_hours'] ?? 24;

    // Optional: selected source shift from user's shifts
    $source_shift_id = isset($_POST['source_shift_id']) && $_POST['source_shift_id'] !== '' ? (int)$_POST['source_shift_id'] : null;

    // If a source_shift_id was provided, override the provided fields with the shift's details
    if ($source_shift_id) {
        $ps = $conn->prepare("SELECT shift_date, start_time, end_time, role_id FROM shifts WHERE id = ? AND user_id = ? LIMIT 1");
        $ps->execute([$source_shift_id, $user_id]);
        $shiftRow = $ps->fetch(PDO::FETCH_ASSOC);
        if ($shiftRow) {
            $shift_date = $shiftRow['shift_date'];
            $start_time = $shiftRow['start_time'];
            $end_time = $shiftRow['end_time'];
            $role_id = $shiftRow['role_id'];
        } else {
            // invalid selection - ignore
            $source_shift_id = null;
        }
    }

    $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_hours} hours"));

    $successCount = 0;
    $errors = [];
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
            'expires_at' => $expires_at,
            'source_shift_id' => $source_shift_id
        ];

        try {
            if (createCrossBranchRequest($conn, $request_data)) {
                $successCount++;
            } else {
                $errors[] = "Failed to create request for branch ID {$target_branch_id}";
            }
        } catch (Exception $e) {
            $errors[] = "Error for branch ID {$target_branch_id}: " . $e->getMessage();
        }
    }

    if ($successCount > 0) {
        $_SESSION['success_message'] = "Coverage request sent to {$successCount} branch(es).";
        if (!empty($errors)) {
            $_SESSION['error_message'] = implode('\n', $errors);
        }
    } else {
        $_SESSION['error_message'] = "No requests created. " . implode('\n', $errors);
    }

    header("Location: coverage_requests.php");
    exit();
}

// Handle clear (delete) of a fulfilled request by requester: remove the cross-branch request but keep the shift
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_request'])) {
    $clear_id = isset($_POST['clear_request_id']) ? (int)$_POST['clear_request_id'] : 0;
    if ($clear_id <= 0) {
        $_SESSION['error_message'] = "Invalid request id.";
        header("Location: coverage_requests.php");
        exit();
    }

    try {
        $stmt = $conn->prepare("SELECT requested_by_user_id, status FROM cross_branch_shift_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$clear_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $_SESSION['error_message'] = "Coverage request not found.";
            header("Location: coverage_requests.php");
            exit();
        }

        // Only the requester or an admin can clear
        $isAdmin = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true);
        if ((int)$row['requested_by_user_id'] !== (int)$user_id && !$isAdmin) {
            $_SESSION['error_message'] = "You are not authorized to clear this request.";
            header("Location: coverage_requests.php");
            exit();
        }

        // Only allow clearing fulfilled requests (don't clear pending)
        if ($row['status'] !== 'fulfilled') {
            $_SESSION['error_message'] = "Only fulfilled requests can be cleared.";
            header("Location: coverage_requests.php");
            exit();
        }

        $conn->beginTransaction();

        // Remove coverage entries but KEEP the shift record
        $delCov = $conn->prepare("DELETE FROM shift_coverage WHERE request_id = ?");
        $delCov->execute([$clear_id]);

        // Delete the request itself
        $delReq = $conn->prepare("DELETE FROM cross_branch_shift_requests WHERE id = ?");
        $delReq->execute([$clear_id]);

        $conn->commit();
        $_SESSION['success_message'] = "Fulfilled coverage request cleared (shift remains in place).";
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $_SESSION['error_message'] = "Error clearing request: " . $e->getMessage();
    }

    header("Location: coverage_requests.php");
    exit();
}

// Handle clear/remove by the user who covered a request: remove the created shift and revert request to pending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_covered'])) {
    $clear_id = isset($_POST['clear_covered_request_id']) ? (int)$_POST['clear_covered_request_id'] : 0;
    $shift_id = isset($_POST['clear_covered_shift_id']) ? (int)$_POST['clear_covered_shift_id'] : 0;
    if ($clear_id <= 0 || $shift_id <= 0) {
        $_SESSION['error_message'] = "Invalid request or shift id.";
        header("Location: coverage_requests.php");
        exit();
    }

    try {
        // Verify the user is indeed the covering user for this request/shift
        $chk = $conn->prepare("SELECT sc.covering_user_id, sc.shift_id, cbr.status FROM shift_coverage sc JOIN cross_branch_shift_requests cbr ON cbr.id = sc.request_id WHERE sc.request_id = ? AND sc.shift_id = ? LIMIT 1");
        $chk->execute([$clear_id, $shift_id]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $_SESSION['error_message'] = "Coverage record not found.";
            header("Location: coverage_requests.php");
            exit();
        }

        if ((int)$row['covering_user_id'] !== (int)$user_id) {
            $_SESSION['error_message'] = "You are not authorized to remove this shift.";
            header("Location: coverage_requests.php");
            exit();
        }

        $conn->beginTransaction();

        // Delete the shift record
        $delShift = $conn->prepare("DELETE FROM shifts WHERE id = ? AND user_id = ?");
        $delShift->execute([$shift_id, $user_id]);

        // Remove coverage record
        $delCov = $conn->prepare("DELETE FROM shift_coverage WHERE request_id = ? AND shift_id = ?");
        $delCov->execute([$clear_id, $shift_id]);

        // Revert the original request back to pending so other branches can still accept if appropriate
        $updReq = $conn->prepare("UPDATE cross_branch_shift_requests SET status = 'pending', fulfilled_by_user_id = NULL, fulfilled_at = NULL, notes = NULL WHERE id = ?");
        $updReq->execute([$clear_id]);

        $conn->commit();
        $_SESSION['success_message'] = "Your coverage shift has been removed and request reverted to pending.";
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $_SESSION['error_message'] = "Error removing covered shift: " . $e->getMessage();
    }

    header("Location: coverage_requests.php");
    exit();
}

// Handle offer coverage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['offer_coverage'])) {
    $request_id = $_POST['request_id'];
    try {
        $stmt = $conn->prepare("SELECT * FROM cross_branch_shift_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $_SESSION['error_message'] = "Coverage request not found.";
        } else {
            // Ensure request is still pending and not expired
            if ($request['status'] !== 'pending' || (isset($request['expires_at']) && strtotime($request['expires_at']) <= time())) {
                $_SESSION['error_message'] = "This coverage request is no longer available.";
            // Prevent users from covering their own branch's request
            } elseif ($request['requesting_branch_id'] === $user_branch_id) {
                $_SESSION['error_message'] = "You cannot offer coverage for your own branch's request.";
            // Ensure the current user is a member of the target branch (or an admin) before fulfilling
            } elseif ($request['target_branch_id'] !== $user_branch_id && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true)) {
                $_SESSION['error_message'] = "You are not authorized to cover this request.";
            } else {
                $result = fulfillCrossBranchRequest($conn, $request_id, $user_id, $user_id);
                if ($result) {
                    $_SESSION['success_message'] = "Coverage offer submitted successfully!";
                } else {
                    $_SESSION['error_message'] = "Failed to fulfill coverage request.";
                }
            }
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }

    header("Location: coverage_requests.php");
    exit();
}

// Fetch dropdowns
$all_branches = getAllBranches($conn);
$roles = $conn->query("SELECT id, name FROM roles ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch the current user's upcoming shifts (eligible for requesting coverage)
$userShiftsStmt = $conn->prepare("SELECT id, shift_date, start_time, end_time, location, branch_id FROM shifts WHERE user_id = ? AND shift_date >= CURDATE() ORDER BY shift_date, start_time");
$userShiftsStmt->execute([$user_id]);
$user_shifts = $userShiftsStmt->fetchAll(PDO::FETCH_ASSOC);

// Load available requests
$sql = "SELECT cbr.*, rb.name AS requesting_branch_name, u.username AS requested_by_username
        FROM cross_branch_shift_requests cbr
        JOIN branches rb ON cbr.requesting_branch_id=rb.id
        JOIN users u ON cbr.requested_by_user_id=u.id
        WHERE cbr.target_branch_id=? AND cbr.status='pending' AND cbr.expires_at>NOW()
        ORDER BY cbr.urgency_level DESC, cbr.created_at ASC";
$stmt = $conn->prepare($sql);
$stmt->execute([$user_branch_id]);
$available_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load my requests (only those I requested) — fulfilled requests I covered are shown separately in $my_fulfilled
$sql = "SELECT cbr.*, tb.name AS target_branch_name, uf.username AS fulfilled_by_username, fb.name AS accepted_by_branch
    FROM cross_branch_shift_requests cbr
    JOIN branches tb ON cbr.target_branch_id=tb.id
    LEFT JOIN users uf ON cbr.fulfilled_by_user_id=uf.id
    LEFT JOIN branches fb ON uf.branch_id = fb.id
    WHERE cbr.requested_by_user_id = ? AND cbr.status IN ('pending','fulfilled')
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

// If a source_shift_id is provided via GET (e.g., from shifts list), prefill the form
$prefill = ['shift_date' => '', 'start_time' => '', 'end_time' => '', 'role_id' => '', 'source_shift_id' => ''];
if (!empty($_GET['source_shift_id'])) {
    $ss = (int)$_GET['source_shift_id'];
    $ps = $conn->prepare("SELECT id, shift_date, start_time, end_time, role_id FROM shifts WHERE id = ? AND user_id = ? LIMIT 1");
    $ps->execute([$ss, $user_id]);
    $psr = $ps->fetch(PDO::FETCH_ASSOC);
    if ($psr) {
        $prefill['shift_date'] = $psr['shift_date'];
        $prefill['start_time'] = $psr['start_time'];
        $prefill['end_time'] = $psr['end_time'];
        $prefill['role_id'] = $psr['role_id'];
        $prefill['source_shift_id'] = $psr['id'];
    }
}

// Render HTML view below…
?>



<!DOCTYPE html>
<html lang="en">

<head>
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
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../css/coverage_requests.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        /* Multi-select styles (matching admin) */
        .multi-select { position: relative; border: 1px solid #ddd; padding: 8px; border-radius: 6px; }
        .multi-select input#branch-search { width: 100%; padding: 6px; border: 1px solid #eee; border-radius: 4px; }
        .options-list { max-height: 150px; overflow: auto; margin-top: 8px; border-top: 1px dashed #f0f0f0; padding-top: 8px; display: none; position: absolute; left: 8px; right: 8px; background: #fff; z-index: 50; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .options-list .option { padding: 6px; cursor: pointer; border-radius: 4px; }
        .options-list .option:hover { background: #f4f4f4; }
        .selected-list { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .chip { background: #eee; padding: 4px 8px; border-radius: 16px; display: inline-flex; align-items: center; gap: 6px; }
        .chip-remove { cursor: pointer; padding-left: 6px; color: #777; }
        .hint { display:block; font-size: 12px; color: #666; margin-top:4px; }
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
                                <div class="notification-item notification-<?php echo $notification['type']; ?>" data-id="<?php echo $notification['id']; ?>">
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
        <h1><i class="fas fa-exchange-alt"></i> Shift Coverage</h1>
        <p>Your branch: <strong><?php echo htmlspecialchars($user_branch['name']); ?></strong></p>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="showTab('available')">
                <i class="fas fa-list"></i> Available Coverage (<?php echo count($available_requests); ?>)
            </div>
            <div class="tab" onclick="showTab('create')">
                <i class="fas fa-plus"></i> Request Coverage
            </div>
            <div class="tab" onclick="showTab('my-requests')">
                <i class="fas fa-paper-plane"></i> My Requests (<?php echo count($my_requests); ?>)
            </div>
        </div>

        <!-- Available Coverage Tab -->
        <div id="available-tab" class="tab-content active">
            <h2><i class="fas fa-list"></i> Available Coverage Opportunities</h2>

            <?php if (empty($available_requests)): ?>
                <div class="request-card">
                    <p><i class="fas fa-info-circle"></i> No coverage requests available at this time.</p>
                </div>
            <?php else: ?>
                <?php foreach ($available_requests as $request): ?>
                    <div class="request-card <?php echo $request['urgency_level']; ?>-urgency">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3>
                                <i class="fas fa-building"></i>
                                Coverage Needed at <?php echo htmlspecialchars($request['requesting_branch_name']); ?>
                            </h3>
                            <span class="urgency-badge urgency-<?php echo $request['urgency_level']; ?>">
                                <?php echo ucfirst($request['urgency_level']); ?> Priority
                            </span>
                        </div>

                        <div class="request-details">
                            <div class="detail-item">
                                <div class="detail-label">Date & Time</div>
                                <div class="detail-value">
                                    <?php echo date('M j, Y', strtotime($request['shift_date'])); ?><br>
                                    <?php echo date('g:i A', strtotime($request['start_time'])); ?> -
                                    <?php echo date('g:i A', strtotime($request['end_time'])); ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Role Required</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['role_required'] ?: 'Any'); ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Requested By</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['requested_by_username']); ?>
                                From 
                                <?php echo htmlspecialchars($request['requesting_branch_name']); ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Expires</div>
                                <div class="detail-value"><?php echo date('M j, g:i A', strtotime($request['expires_at'])); ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($request['description']): ?>
                            <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 15px 0;">
                                <strong>Details:</strong><br>
                                <?php echo nl2br(htmlspecialchars($request['description'])); ?>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 15px;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                <button type="submit" name="offer_coverage" class="btn btn-success">
                                    <i class="fas fa-hand-paper"></i> Offer to Cover This Shift
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Create Request Tab -->
        <div id="create-tab" class="tab-content">
            <div class="request-card">
                <h2><i class="fas fa-plus"></i> Request Coverage for Your Shift</h2>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="target_branch_ids">Request Coverage From (select up to 5):</label>
                            <div id="branch-multi-select" class="multi-select">
                                <input type="text" id="branch-search" placeholder="Search branches..." autocomplete="off">
                                <div id="branch-selected" class="selected-list"></div>
                                <div id="branch-options" class="options-list">
                                    <?php foreach ($all_branches as $branch): ?>
                                        <?php if ($branch['id'] != $user_branch_id): ?>
                                            <div class="option" data-id="<?php echo $branch['id']; ?>">
                                                <?php echo htmlspecialchars($branch['name']); ?>
                                                <span class="muted">(<?php echo htmlspecialchars($branch['code']); ?>)</span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" id="target_branch_ids" name="target_branch_ids" value="">
                                <small class="hint">Type to search, click to select. Max 5 branches.</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="urgency_level">Urgency Level:</label>
                            <select id="urgency_level" name="urgency_level" required>
                                <option value="low">Low - Not urgent</option>
                                <option value="medium" selected>Medium - Preferred coverage</option>
                                <option value="high">High - Important to fill</option>
                                <option value="critical">Critical - Must be filled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shift_date">Shift Date:</label>
                            <input type="date" id="shift_date" name="shift_date" required
                                min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($prefill['shift_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="expires_hours">Request Expires In:</label>
                            <select id="expires_hours" name="expires_hours" required>
                                <option value="6">6 hours</option>
                                <option value="12">12 hours</option>
                                <option value="24" selected>24 hours</option>
                                <option value="48">48 hours</option>
                                <option value="72">72 hours</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="start_time">Start Time:</label>
                            <input type="time" id="start_time" name="start_time" required value="<?php echo htmlspecialchars($prefill['start_time'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="end_time">End Time:</label>
                            <input type="time" id="end_time" name="end_time" required value="<?php echo htmlspecialchars($prefill['end_time'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="role_id">Role/Position:</label>
                            <select id="role_id" name="role_id" required>
                                <option value="">Select a role...</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>" <?php if (!empty($prefill['role_id']) && $prefill['role_id'] == $role['id']) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- source_shift_id selector removed: users now pick branches and the form uses explicit fields only -->
                        <div class="form-group">
                            <label for="description">Additional Details:</label>
                            <textarea id="description" name="description" rows="3"
                                placeholder="Any specific requirements or information..."></textarea>
                        </div>
                    </div>
                    <button type="submit" name="create_request" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Coverage Request
                    </button>
                </form>
            </div>
        </div>

        <!-- My Requests Tab -->
        <div id="my-requests-tab" class="tab-content">
            <h2><i class="fas fa-paper-plane"></i> My Coverage Requests</h2>

            <?php if (empty($my_requests)): ?>
                <div class="request-card">
                    <p><i class="fas fa-info-circle"></i> You haven't made any coverage requests yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($my_requests as $request): ?>
                    <div class="request-card <?php echo $request['urgency_level']; ?>-urgency">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3>
                                <i class="fas fa-building"></i>
                                Request to <?php echo htmlspecialchars($request['target_branch_name']); ?>
                            </h3>
                            <span class="urgency-badge urgency-<?php echo $request['urgency_level']; ?>">
                                <?php echo ucfirst($request['urgency_level']); ?> Priority
                            </span>
                        </div>

                        <div class="request-details">
                            <div class="detail-item">
                                <div class="detail-label">Date & Time</div>
                                <div class="detail-value">
                                    <?php echo date('M j, Y', strtotime($request['shift_date'])); ?><br>
                                    <?php echo date('g:i A', strtotime($request['start_time'])); ?> -
                                    <?php echo date('g:i A', strtotime($request['end_time'])); ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Role Required</div>
                                <div class="detail-value">
                                    <?php
                                    $roleName = 'Any';
                                    if (isset($request['role_id']) && $request['role_id']) {
                                        foreach ($roles as $role) {
                                            if ($role['id'] == $request['role_id']) {
                                                $roleName = $role['name'];
                                                break;
                                            }
                                        }
                                    }
                                    echo htmlspecialchars($roleName);
                                    ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">
                                    <?php if ($request['status'] === 'fulfilled'): ?>
                                        Accepted by
                                        <?php echo htmlspecialchars($request['fulfilled_by_username'] ?: 'another user'); ?>
                                        From
                                        <?php echo htmlspecialchars($request['accepted_by_branch'] ?? $request['target_branch_name'] ?? 'unknown branch'); ?>
                                    <?php else: ?>
                                        Pending Response
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Expires</div>
                                <div class="detail-value"><?php echo date('M j, g:i A', strtotime($request['expires_at'])); ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($request['description']): ?>
                            <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 15px 0;">
                                <strong>Details:</strong><br>
                                <?php echo nl2br(htmlspecialchars($request['description'])); ?>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 10px; text-align: right;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="edit_request_id" value="<?php echo $request['id']; ?>">
                                <button type="submit" name="edit_request" class="btn btn-warning btn-sm"><i
                                        class="fas fa-edit"></i> Edit</button>
                            </form>
                            <form method="POST" style="display:inline; margin-left: 8px;"
                                onsubmit="return confirm('Are you sure you want to delete this request?');">
                                <input type="hidden" name="delete_request_id" value="<?php echo $request['id']; ?>">
                                <button type="submit" name="delete_request" class="btn btn-danger btn-sm"><i
                                        class="fas fa-trash"></i> Delete</button>
                            </form>
                            <?php if ($request['status'] === 'fulfilled'): ?>
                                <form method="POST" style="display:inline; margin-left:8px;" onsubmit="return confirm('Clear this fulfilled request (shift stays in place)?');">
                                    <input type="hidden" name="clear_request_id" value="<?php echo $request['id']; ?>">
                                    <button type="submit" name="clear_request" class="btn btn-secondary btn-sm"><i class="fas fa-eraser"></i> Clear</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Requests I Covered -->
            <h2 style="margin-top:30px;"><i class="fas fa-check"></i> Requests I Covered</h2>
            <?php if (empty($my_fulfilled)): ?>
                <div class="request-card">
                    <p><i class="fas fa-info-circle"></i> You haven't covered any requests yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($my_fulfilled as $f): ?>
                    <div class="request-card fulfilled">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <h3><i class="fas fa-user-check"></i> Covered: <?php echo htmlspecialchars($f['requesting_branch_name'] ?? 'requesting branch'); ?></h3>
                            <span><?php echo date('M j, Y', strtotime($f['fulfilled_at'] ?? $f['created_at'])); ?></span>
                        </div>
                        <div class="request-details">
                            <div class="detail-item"><div class="detail-label">When</div><div class="detail-value"><?php echo date('M j, Y', strtotime($f['shift_date'])); ?> <?php echo date('g:i A', strtotime($f['start_time'])); ?> - <?php echo date('g:i A', strtotime($f['end_time'])); ?></div></div>
                            <div class="detail-item"><div class="detail-label">Target Branch</div><div class="detail-value"><?php echo htmlspecialchars($f['target_branch_name']); ?></div></div>
                            <div class="detail-item"><div class="detail-label">Notes</div><div class="detail-value"><?php echo nl2br(htmlspecialchars($f['description'] ?? '')); ?></div></div>
                        </div>
                        <div style="margin-top:10px; text-align:right;">
                            <a href="coverage_requests.php#request-<?php echo (int)$f['id']; ?>" class="btn btn-secondary btn-sm">View</a>
                            <?php if (!empty($f['shift_id'])): ?>
                                <form method="POST" style="display:inline; margin-left:8px;" onsubmit="return confirm('Remove the created shift and revert request to pending? This will delete the shift for you.');">
                                    <input type="hidden" name="clear_covered_request_id" value="<?php echo (int)$f['id']; ?>">
                                    <input type="hidden" name="clear_covered_shift_id" value="<?php echo (int)$f['shift_id']; ?>">
                                    <button type="submit" name="clear_covered" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Remove Shift</button>
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
                    <form method="POST">
                        <input type="hidden" name="edit_id" value="<?php echo htmlspecialchars($edit_request['id']); ?>">
                        <div class="form-group">
                            <label for="target_branch_id">Target Branch</label>
                            <select name="target_branch_id" required>
                                <?php foreach ($all_branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>" <?php if ($edit_request['target_branch_id'] == $branch['id'])
                                           echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($branch['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                </div>
            </div>
            <script>
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
        (function() {
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

            document.addEventListener('click', function(e) {
                const container = document.getElementById('branch-multi-select');
                if (!container) return;
                if (!container.contains(e.target)) {
                    hideOptions();
                }
            });

            document.addEventListener('keydown', function(e) {
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

        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');

            // Add active class to clicked tab
            event.target.classList.add('active');
        }

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

        document.addEventListener('DOMContentLoaded', function () {
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