<?php
require_once '../includes/auth.php';
requireLogin(); // Only logged-in users can access
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
    $delete_id = $_POST['delete_request_id'];
    // Only allow delete if the request belongs to the user
    $stmt = $conn->prepare("DELETE FROM cross_branch_shift_requests WHERE id = ? AND requested_by_user_id = ?");
    $stmt->execute([$delete_id, $user_id]);
    $_SESSION['success_message'] = "Coverage request deleted.";
    header("Location: coverage_requests.php");
    exit();
}

// Handle edit request (show edit form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_request'])) {
    $edit_id = $_POST['edit_request_id'];
    // Fetch the request to edit
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
    $role_id = $_POST['role_id'] ?? null;
    $urgency_level = $_POST['urgency_level'];
    $description = $_POST['description'];
    $expires_hours = $_POST['expires_hours'];
    $expires_at = date('Y-m-d H:i:s', strtotime("+$expires_hours hours"));
    $stmt = $conn->prepare("UPDATE cross_branch_shift_requests SET target_branch_id=?, shift_date=?, start_time=?, end_time=?, role_id=?, urgency_level=?, description=?, expires_at=? WHERE id=? AND requested_by_user_id=?");
    $stmt->execute([$target_branch_id, $shift_date, $start_time, $end_time, $role_id, $urgency_level, $description, $expires_at, $edit_id, $user_id]);
    $_SESSION['success_message'] = "Coverage request updated.";
    header("Location: coverage_requests.php");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_request'])) {
        $target_branch_id = $_POST['target_branch_id'];
        $shift_date = $_POST['shift_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $role_id = $_POST['role_id'];
        $urgency_level = $_POST['urgency_level'];
        $description = $_POST['description'];
        $expires_hours = $_POST['expires_hours'];

        // Calculate expiration time
        $expires_at = date('Y-m-d H:i:s', strtotime("+$expires_hours hours"));

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
                $_SESSION['success_message'] = "Coverage request sent successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to create request.";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: coverage_requests.php");
        exit();
    }

    if (isset($_POST['offer_coverage'])) {
        $request_id = $_POST['request_id'];

        try {
            // Fetch the full request details
            $stmt = $conn->prepare("SELECT * FROM cross_branch_shift_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                $_SESSION['error_message'] = "Coverage request not found.";
            } elseif ($request['requesting_branch_id'] == $user_branch_id) {
                $_SESSION['error_message'] = "You cannot offer coverage for your own branch's request.";
            } else {
                // Only add the shift if fulfillCrossBranchRequest does NOT already add it
                $result = fulfillCrossBranchRequest($conn, $request_id, $user_id, $user_id);
                error_log('fulfillCrossBranchRequest result: ' . var_export($result, true));
                if ($result === true || $result === 1) {
                    // Mark the request as accepted and store the accepting user
                    $update = $conn->prepare("UPDATE cross_branch_shift_requests SET status = 'accepted', accepted_by_user_id = ? WHERE id = ?");
                    $update_success = $update->execute([$user_id, $request_id]);
                    error_log('Update cross_branch_shift_requests: ' . var_export($update_success, true));

                    // Check if the shift already exists for this user, date, and time to avoid duplicates
                    $check = $conn->prepare("SELECT id FROM shifts WHERE user_id = ? AND shift_date = ? AND start_time = ? AND end_time = ? AND branch_id = ? AND role_id = ?");
                    $check->execute([
                        $user_id,
                        $request['shift_date'],
                        $request['start_time'],
                        $request['end_time'],
                        $user_branch_id,
                        $request['role_id']
                    ]);
                    error_log('Shift exists rowCount: ' . $check->rowCount());
                    if ($check->rowCount() == 0) {
                        $sql = "INSERT INTO shifts (user_id, shift_date, start_time, end_time, branch_id, location, role_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $insert_success = $stmt->execute([
                            $user_id,
                            $request['shift_date'],
                            $request['start_time'],
                            $request['end_time'],
                            $user_branch_id, // The user's branch, not the requesting branch
                            'Cross-branch coverage',
                            $request['role_id']
                        ]);
                        error_log('Inserted shift for user_id ' . $user_id . ' on ' . $request['shift_date'] . ' success: ' . var_export($insert_success, true));
                    } else {
                        error_log('Shift already exists for user_id ' . $user_id . ' on ' . $request['shift_date']);
                    }
                    $_SESSION['success_message'] = "Coverage offer submitted successfully!";
                } else {
                    $_SESSION['error_message'] = "Failed to fulfill coverage request.";
                }
            }
        } catch (Exception $e) {
            error_log('Coverage offer error: ' . $e->getMessage());
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: coverage_requests.php");
        exit();
    }
}

// Get all branches for dropdown (excluding user's branch)
$all_branches = getAllBranches($conn);
// Get all roles for dropdown
$roles = $conn->query("SELECT id, name FROM roles ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get available coverage requests (from other branches)
$sql = "SELECT cbr.*, rb.name as requesting_branch_name, u.username as requested_by_username
        FROM cross_branch_shift_requests cbr
        JOIN branches rb ON cbr.requesting_branch_id = rb.id
        JOIN users u ON cbr.requested_by_user_id = u.id
        WHERE cbr.target_branch_id = ? 
        AND cbr.status = 'pending'
        AND cbr.expires_at > NOW()
        ORDER BY cbr.urgency_level DESC, cbr.created_at ASC";
$stmt = $conn->prepare($sql);
$stmt->execute([$user_branch_id]);
$available_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user's own requests
// Show both pending and accepted requests for the sender
$sql = "SELECT cbr.*, tb.name as target_branch_name, u_accept.username as accepted_by_username
        FROM cross_branch_shift_requests cbr
        JOIN branches tb ON cbr.target_branch_id = tb.id
        LEFT JOIN users u_accept ON cbr.accepted_by_user_id = u_accept.id
        WHERE cbr.requested_by_user_id = ?
        AND (cbr.status = 'pending' OR cbr.status = 'accepted')
        ORDER BY cbr.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([$user_id]);
$my_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check for messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);
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
                            <div class="notification-item notification-<?php echo $notification['type']; ?>"
                                data-id="<?php echo $notification['id']; ?>">
                                <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                <?php if ($notification['type'] === 'shift-invite' && !empty($notification['related_id'])): ?>
                                    <a class="shit-invt"
                                        href="../functions/pending_shift_invitations.php?invitation_id=<?php echo $notification['related_id']; ?>&notif_id=<?php echo $notification['id']; ?>">
                                        <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                    </a>
                                <?php else: ?>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                <?php endif; ?>
                            </div>
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
                            <label for="target_branch_id">Request Coverage From:</label>
                            <select id="target_branch_id" name="target_branch_id" required>
                                <option value="">Select a branch...</option>
                                <?php foreach ($all_branches as $branch): ?>
                                    <?php if ($branch['id'] != $user_branch_id): ?>
                                        <option value="<?php echo $branch['id']; ?>">
                                            <?php echo htmlspecialchars($branch['name']); ?>
                                            (<?php echo htmlspecialchars($branch['code']); ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
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
                                min="<?php echo date('Y-m-d'); ?>">
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
                            <input type="time" id="start_time" name="start_time" required>
                        </div>
                        <div class="form-group">
                            <label for="end_time">End Time:</label>
                            <input type="time" id="end_time" name="end_time" required>
                        </div>
                        <div class="form-group">
                            <label for="role_id">Role/Position:</label>
                            <select id="role_id" name="role_id" required>
                                <option value="">Select a role...</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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
                                    <?php if ($request['status'] === 'accepted'): ?>
                                        Accepted by
                                        <?php echo htmlspecialchars($request['accepted_by_username'] ?: 'another user'); ?>
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