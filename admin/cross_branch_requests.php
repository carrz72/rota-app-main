<?php
// Debug: Turn on error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/auth.php';
requireAdmin(); // Only admins can access
require_once '../functions/branch_functions.php';

$user_id = $_SESSION['user_id'];
$user_branch = getUserHomeBranch($conn, $user_id);

// Get admin's branch - only allow them to see their branch requests
$stmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
$adminBranchId = $adminUser['branch_id'];

if (!$adminBranchId || !$user_branch) {
    $_SESSION['error_message'] = "You must be assigned to a branch to manage cross-branch requests.";
    header("Location: admin_dashboard.php");
    exit();
}

// Handle form submission
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
            'requesting_branch_id' => $user_branch['id'],
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
                $_SESSION['success_message'] = "Cross-branch coverage request sent successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to create request.";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: cross_branch_requests.php");
        exit();
    }

    if (isset($_POST['fulfill_request'])) {
        $request_id = $_POST['request_id'];
        $fulfilling_user_id = $_POST['fulfilling_user_id'];

        try {
            fulfillCrossBranchRequest($conn, $request_id, $fulfilling_user_id, $user_id);
            $_SESSION['success_message'] = "Request fulfilled successfully!";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error fulfilling request: " . $e->getMessage();
        }

        header("Location: cross_branch_requests.php");
        exit();
    }

    if (isset($_POST['decline_request'])) {
        $request_id = $_POST['request_id'];
        $notes = trim($_POST['decline_notes'] ?? '');

        $sql = "UPDATE cross_branch_shift_requests SET status = 'declined', notes = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$notes, $request_id]);

        $_SESSION['success_message'] = "Request declined.";
        header("Location: cross_branch_requests.php");
        exit();
    }
}

// Get all branches for dropdown
$all_branches = getAllBranches($conn);

// Get pending requests for user's branch
$pending_requests = getPendingRequestsForBranch($conn, $user_branch['id']);

// Get user's own pending requests
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cross-Branch Coverage Requests - Rota System</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
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
    <div class="container">
        <h1><i class="fas fa-exchange-alt"></i> Cross-Branch Coverage Requests</h1>
        <p>Your branch: <strong><?php echo htmlspecialchars($user_branch['name']); ?></strong></p>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="showTab('create')">
                <i class="fas fa-plus"></i> Create Request
            </div>
            <div class="tab" onclick="showTab('incoming')">
                <i class="fas fa-inbox"></i> Incoming Requests (<?php echo count($pending_requests); ?>)
            </div>
            <div class="tab" onclick="showTab('my-requests')">
                <i class="fas fa-paper-plane"></i> My Requests (<?php echo count($my_requests); ?>)
            </div>
        </div>

        <!-- Create Request Tab -->
        <div id="create-tab" class="tab-content active">
            <div class="request-card">
                <h2><i class="fas fa-plus"></i> Request Coverage from Another Branch</h2>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="target_branch_id">Request Coverage From:</label>
                            <select id="target_branch_id" name="target_branch_id" required>
                                <option value="">Select a branch...</option>
                                <?php foreach ($all_branches as $branch): ?>
                                    <?php if ($branch['id'] != $user_branch['id']): ?>
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
                            <label for="role_required">Role/Position Required:</label>
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

        <!-- Incoming Requests Tab -->
        <div id="incoming-tab" class="tab-content">
            <h2><i class="fas fa-inbox"></i> Incoming Coverage Requests</h2>

            <?php if (empty($pending_requests)): ?>
                <div class="request-card">
                    <p><i class="fas fa-info-circle"></i> No pending coverage requests at this time.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pending_requests as $request): ?>
                    <?php if ($request['target_branch_id'] == $user_branch['id']): // Only show requests TO this branch ?>
                        <div class="request-card <?php echo $request['urgency_level']; ?>-urgency">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                <h3>
                                    <i class="fas fa-building"></i>
                                    Coverage Request from <?php echo htmlspecialchars($request['requesting_branch_name']); ?>
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
                                <button onclick="showAvailableEmployees(<?php echo $request['id']; ?>)" class="btn btn-success">
                                    <i class="fas fa-users"></i> View Available Employees
                                </button>
                                <button onclick="showDeclineForm(<?php echo $request['id']; ?>)" class="btn btn-danger">
                                    <i class="fas fa-times"></i> Decline Request
                                </button>
                            </div>

                            <!-- Available Employees (Hidden by default) -->
                            <div id="employees-<?php echo $request['id']; ?>" style="display: none; margin-top: 15px;">
                                <!-- This will be populated by JavaScript -->
                            </div>

                            <!-- Decline Form (Hidden by default) -->
                            <div id="decline-<?php echo $request['id']; ?>" style="display: none; margin-top: 15px;">
                                <form method="POST">
                                    <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                    <div class="form-group">
                                        <label>Reason for declining (optional):</label>
                                        <textarea name="decline_notes" rows="3"
                                            placeholder="e.g., No available staff, scheduling conflict..."></textarea>
                                    </div>
                                    <button type="submit" name="decline_request" class="btn btn-danger">
                                        <i class="fas fa-times"></i> Decline Request
                                    </button>
                                    <button type="button" onclick="hideDeclineForm(<?php echo $request['id']; ?>)"
                                        class="btn btn-secondary">
                                        Cancel
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
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
            <a href="admin_dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Admin Dashboard
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

        function showAvailableEmployees(requestId) {
            const container = document.getElementById('employees-' + requestId);
            container.innerHTML = '<p><i class="fas fa-spinner fa-spin"></i> Loading available employees...</p>';
            container.style.display = 'block';

            fetch('get_available_employees.php?request_id=' + requestId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '<div style="background: #e9ecef; padding: 15px; border-radius: 4px;">';
                        html += '<strong>Available Employees:</strong><br>';

                        if (data.employees.length === 0) {
                            html += '<p><i class="fas fa-info-circle"></i> No available employees found for this time slot.</p>';
                        } else {
                            html += '<form method="POST" style="margin-top: 10px;">';
                            html += '<input type="hidden" name="request_id" value="' + requestId + '">';
                            html += '<select name="fulfilling_user_id" required style="margin-bottom: 10px;">';
                            html += '<option value="">Select employee...</option>';

                            data.employees.forEach(employee => {
                                html += '<option value="' + employee.id + '">';
                                html += employee.username + ' (' + (employee.role_name || 'No role assigned') + ')';
                                if (employee.hourly_rate > 0) {
                                    html += ' - $' + parseFloat(employee.hourly_rate).toFixed(2) + '/hr';
                                }
                                html += '</option>';
                            });

                            html += '</select><br>';
                            html += '<button type="submit" name="fulfill_request" class="btn btn-success">';
                            html += '<i class="fas fa-check"></i> Assign Employee';
                            html += '</button>';
                            html += '</form>';
                        }

                        html += '</div>';
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<p style="color: red;"><i class="fas fa-exclamation-triangle"></i> Error: ' + data.error + '</p>';
                    }
                })
                .catch(error => {
                    container.innerHTML = '<p style="color: red;"><i class="fas fa-exclamation-triangle"></i> Error loading employees: ' + error.message + '</p>';
                });
        }

        function showDeclineForm(requestId) {
            document.getElementById('decline-' + requestId).style.display = 'block';
        }

        function hideDeclineForm(requestId) {
            document.getElementById('decline-' + requestId).style.display = 'none';
        }
    </script>
</body>

</html>