<?php
require_once '../includes/auth.php';
requireLogin(); // Only logged-in users can access
require_once '../functions/branch_functions.php';

$user_id = $_SESSION['user_id'];

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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_request'])) {
        $target_branch_id = $_POST['target_branch_id'];
        $shift_date = $_POST['shift_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $role_required = $_POST['role_required'];
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
            'role_required' => $role_required,
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
            // Check if user can fulfill this request (not from their own branch)
            $stmt = $conn->prepare("SELECT requesting_branch_id FROM cross_branch_shift_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($request['requesting_branch_id'] == $user_branch_id) {
                $_SESSION['error_message'] = "You cannot offer coverage for your own branch's request.";
            } else {
                fulfillCrossBranchRequest($conn, $request_id, $user_id, $user_id);
                $_SESSION['success_message'] = "Coverage offer submitted successfully!";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: coverage_requests.php");
        exit();
    }
}

// Get all branches for dropdown (excluding user's branch)
$all_branches = getAllBranches($conn);

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
$sql = "SELECT cbr.*, tb.name as target_branch_name
        FROM cross_branch_shift_requests cbr
        JOIN branches tb ON cbr.target_branch_id = tb.id
        WHERE cbr.requested_by_user_id = ? 
        AND cbr.status = 'pending'
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
    <style>
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .request-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #007bff;
        }

        .request-card.high-urgency {
            border-left-color: #dc3545;
        }

        .request-card.medium-urgency {
            border-left-color: #ffc107;
        }

        .request-card.low-urgency {
            border-left-color: #28a745;
        }

        .request-card.critical-urgency {
            border-left-color: #6f42c1;
        }

        .urgency-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .urgency-critical {
            background: #6f42c1;
            color: white;
        }

        .urgency-high {
            background: #dc3545;
            color: white;
        }

        .urgency-medium {
            background: #ffc107;
            color: #212529;
        }

        .urgency-low {
            background: #28a745;
            color: white;
        }

        .request-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }

        .detail-item {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
        }

        .detail-label {
            font-weight: bold;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }

        .detail-value {
            font-size: 16px;
            margin-top: 5px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 2px;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid #ddd;
            margin-bottom: 20px;
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
        }

        .tab.active {
            border-bottom-color: #007bff;
            background: #f8f9fa;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }
    </style>
</head>

<body>
    <!-- Navigation Header -->
    <div class="logo">
        <img src="../images/logo.png" alt="Open Rota Logo">
        <span>Open Rota</span>
    </div>

    <div class="nav-group">
        <div class="notification-icon" id="notification-icon">
            <i class="fa fa-bell"></i>
        </div>

        <div class="menu-toggle" id="menu-toggle">
            ☰
        </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="nav-links" id="nav-links">
        <ul>
            <li><a href="dashboard.php"><i class="fa fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="shifts.php"><i class="fa fa-calendar-alt"></i> My Shifts</a></li>
            <li><a href="rota.php"><i class="fa fa-calendar"></i> Rota</a></li>
            <li><a href="roles.php"><i class="fa fa-briefcase"></i> Roles</a></li>
            <li><a href="coverage_requests.php"><i class="fa fa-exchange-alt"></i> Coverage</a></li>
            <li><a href="settings.php"><i class="fa fa-cogs"></i> Settings</a></li>
            <li><a href="../functions/logout.php"><i class="fa fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </nav>

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
                            <label for="role_required">Role/Position:</label>
                            <input type="text" id="role_required" name="role_required"
                                placeholder="e.g., Cashier, Manager, Sales Associate">
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
                                <div class="detail-value"><?php echo htmlspecialchars($request['role_required'] ?: 'Any'); ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">Pending Response</div>
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
                    </div>
                <?php endforeach; ?>
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
    </script>
    <script src="../js/menu.js"></script>
</body>

</html>