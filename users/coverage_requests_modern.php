<?php
session_start();
require_once '../includes/auth.php';
requireLogin(); // Only logged-in users can access
require_once '../includes/db.php';
require_once '../functions/branch_functions.php';
require_once '../functions/coverage_pay_helper.php';

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

// Initialize messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_request'])) {
        // Create new coverage request
        $target_branch_id = $_POST['target_branch_id'];
        $shift_date = $_POST['shift_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $role_id = $_POST['role_id'];
        $urgency = $_POST['urgency_level'];
        $description = $_POST['description'];
        $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

        $stmt = $conn->prepare("INSERT INTO cross_branch_shift_requests 
            (requesting_branch_id, target_branch_id, shift_date, start_time, end_time, role_id, urgency_level, description, requested_by_user_id, status, created_at, expires_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)");

        if ($stmt->execute([$user_branch_id, $target_branch_id, $shift_date, $start_time, $end_time, $role_id, $urgency, $description, $user_id, $expires_at])) {
            $success_message = "Coverage request created successfully!";
        } else {
            $error_message = "Failed to create coverage request.";
        }
    }

    if (isset($_POST['accept_request'])) {
        // Accept a coverage request
        $request_id = $_POST['request_id'];

        $stmt = $conn->prepare("UPDATE cross_branch_shift_requests SET status = 'fulfilled', accepted_by_user_id = ?, fulfilled_at = NOW() WHERE id = ? AND status = 'pending'");
        if ($stmt->execute([$user_id, $request_id])) {
            $success_message = "Coverage request accepted! You're now scheduled for this shift.";
        } else {
            $error_message = "Failed to accept request.";
        }
    }

    if (isset($_POST['delete_request'])) {
        // Delete own request
        $request_id = $_POST['delete_request_id'];

        $stmt = $conn->prepare("DELETE FROM cross_branch_shift_requests WHERE id = ? AND requested_by_user_id = ?");
        if ($stmt->execute([$request_id, $user_id])) {
            $success_message = "Request deleted successfully.";
        } else {
            $error_message = "Failed to delete request.";
        }
    }
}

// Fetch available coverage requests (from other branches to my branch)
$available_requests = $conn->prepare("
    SELECT cbr.*, rb.name AS requesting_branch_name, u.username AS requested_by_username, r.name AS role_name
    FROM cross_branch_shift_requests cbr
    JOIN branches rb ON cbr.requesting_branch_id = rb.id
    JOIN users u ON cbr.requested_by_user_id = u.id
    LEFT JOIN roles r ON cbr.role_id = r.id
    WHERE cbr.target_branch_id = ? AND cbr.status = 'pending' AND cbr.expires_at > NOW()
    ORDER BY cbr.urgency_level DESC, cbr.created_at ASC
");
$available_requests->execute([$user_branch_id]);
$available_requests = $available_requests->fetchAll(PDO::FETCH_ASSOC);

// Fetch my coverage requests
$my_requests = $conn->prepare("
    SELECT cbr.*, tb.name AS target_branch_name, r.name AS role_name,
           au.username AS accepted_by_username
    FROM cross_branch_shift_requests cbr
    JOIN branches tb ON cbr.target_branch_id = tb.id
    LEFT JOIN roles r ON cbr.role_id = r.id
    LEFT JOIN users au ON cbr.accepted_by_user_id = au.id
    WHERE cbr.requested_by_user_id = ?
    ORDER BY cbr.created_at DESC
");
$my_requests->execute([$user_id]);
$my_requests = $my_requests->fetchAll(PDO::FETCH_ASSOC);

// Fetch branches for dropdown
$branches = $conn->query("SELECT id, name FROM branches WHERE status = 'active' AND id != $user_branch_id ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch roles for dropdown
$roles = $conn->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
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
    <link rel="stylesheet" href="../css/dark_mode.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <!-- Navigation Header -->
    <header style="opacity: 1; transition: opacity 0.5s ease;">
        <div class="logo">Open Rota</div>
        <div class="nav-group">
            <div class="notification-container">
                <div class="notification-bell" onclick="toggleNotifications()">
                    <i class="fa fa-bell"></i>
                    <?php if ($notificationCount > 0): ?>
                        <span class="notification-badge"><?php echo $notificationCount; ?></span>
                    <?php endif; ?>
                </div>
                <div class="notification-dropdown" id="notification-dropdown">
                    <?php if (empty($notifications)): ?>
                        <div class="notification-item">
                            <p>No notifications</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item" data-id="<?php echo $notification['id']; ?>">
                                <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                <small><?php echo date('M j, g:i A', strtotime($notification['created_at'])); ?></small>
                                <button onclick="markAsRead(this)" class="mark-read-btn">×</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <nav class="hamburger-menu">
                <div class="hamburger" onclick="toggleMenu()">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <ul class="nav-menu">
                    <li><a href="dashboard.php"><i class="fa fa-home"></i> Dashboard</a></li>
                    <li><a href="rota.php"><i class="fa fa-calendar"></i> Rota</a></li>
                    <li><a href="shifts.php"><i class="fa fa-clock-o"></i> Shifts</a></li>
                    <li><a href="coverage_requests.php" class="active"><i class="fa fa-exchange"></i> Coverage</a></li>
                    <li><a href="payroll.php"><i class="fa fa-money"></i> Payroll</a></li>
                    <li><a href="roles.php"><i class="fa fa-user-tag"></i> Roles</a></li>
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
                    // Calculate estimated pay
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
                                <button type="submit" name="accept_request" class="btn btn-success">
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
                            <select name="target_branch_id" required>
                                <option value="">Select a branch...</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>">
                                        <?php echo htmlspecialchars($branch['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="role_id">Role Required:</label>
                            <select name="role_id" required>
                                <option value="">Select role...</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="shift_date">Date:</label>
                            <input type="date" name="shift_date" required min="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="urgency_level">Urgency Level:</label>
                            <select name="urgency_level" required>
                                <option value="low">Low - Plan Ahead</option>
                                <option value="medium" selected>Medium - Normal</option>
                                <option value="high">High - Urgent</option>
                                <option value="critical">Critical - Emergency</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="start_time">Start Time:</label>
                            <input type="time" name="start_time" required>
                        </div>

                        <div class="form-group">
                            <label for="end_time">End Time:</label>
                            <input type="time" name="end_time" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Additional Details:</label>
                        <textarea name="description"
                            placeholder="Provide any additional information about this coverage request..."></textarea>
                    </div>

                    <button type="submit" name="create_request" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i>
                        Submit Request
                    </button>
                </form>
            </div>
        </div>

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
                                <form method="POST" style="display: inline-block;"
                                    onsubmit="return confirm('Are you sure you want to delete this request?')">
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
        </div>
    </div>

    <script>
        // Tab functionality
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
        function toggleNotifications() {
            const dropdown = document.getElementById('notification-dropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }

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
                    if (data.success) {
                        element.parentElement.style.display = 'none';

                        // Update notification count
                        const badge = document.querySelector('.notification-badge');
                        const visible = document.querySelectorAll('.notification-item[data-id]').length - 1;

                        if (visible <= 0 && badge) {
                            badge.style.display = 'none';
                            document.getElementById('notification-dropdown').innerHTML = '<div class="notification-item"><p>No notifications</p></div>';
                        } else if (badge) {
                            badge.textContent = visible;
                        }
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Mobile menu functionality
        function toggleMenu() {
            const navMenu = document.querySelector('.nav-menu');
            const hamburger = document.querySelector('.hamburger');
            navMenu.classList.toggle('active');
            hamburger.classList.toggle('active');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function (event) {
            const notificationContainer = document.querySelector('.notification-container');
            const hamburgerMenu = document.querySelector('.hamburger-menu');

            if (!notificationContainer.contains(event.target)) {
                document.getElementById('notification-dropdown').style.display = 'none';
            }

            if (!hamburgerMenu.contains(event.target)) {
                document.querySelector('.nav-menu').classList.remove('active');
                document.querySelector('.hamburger').classList.remove('active');
            }
        });

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function () {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });
    </script>

    <script src="../js/darkmode.js"></script>
    <script src="../js/links.js"></script>
</body>

</html>