<?php
ob_start();
require_once '../includes/session_starter.php';
require_once '../includes/error_handler.php';
require_once '../includes/auth.php';
require_once '../includes/session_manager.php';

// Require login using the enhanced session management
requireLogin();

require_once '../includes/db.php';
require_once '../includes/notifications.php';

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Retrieve current user details including branch info
$stmt = $conn->prepare("
    SELECT u.username, u.email, u.email_verified, u.role, u.created_at, u.branch_id,
           b.name as branch_name, b.code as branch_code
    FROM users u 
    LEFT JOIN branches b ON u.branch_id = b.id 
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    handleApplicationError('404', "User account not found.");
}

// Get all available branches for the dropdown
$branches_stmt = $conn->prepare("SELECT id, name, code, address FROM branches WHERE status = 'active' ORDER BY name");
$branches_stmt->execute();
$available_branches = $branches_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_shifts,
        COUNT(CASE WHEN shift_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as shifts_last_30_days,
        MIN(shift_date) as first_shift_date,
        MAX(shift_date) as last_shift_date,
        SUM(CASE WHEN shift_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 
            TIMESTAMPDIFF(HOUR, start_time, end_time) 
            ELSE 0 END) as hours_last_30_days
    FROM shifts 
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$user_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get notification preferences
$notification_prefs = [
    'shift_reminders' => true,
    'shift_invitations' => true,
    'schedule_changes' => true,
    'payroll_updates' => true
];

try {
    $stmt = $conn->prepare("SELECT * FROM notification_preferences WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $prefs = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($prefs) {
        $notification_prefs = [
            'shift_reminders' => (bool) $prefs['shift_reminders'],
            'shift_invitations' => (bool) $prefs['shift_invitations'],
            'schedule_changes' => (bool) $prefs['schedule_changes'],
            'payroll_updates' => (bool) $prefs['payroll_updates']
        ];
    }
} catch (Exception $e) {
    // Table doesn't exist, use defaults
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_account'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);

        // Validation
        if (strlen($username) < 3) {
            $error = "Username must be at least 3 characters long.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            // Check if email is already in use
            $stmtEmail = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmtEmail->execute([$email, $user_id]);

            if ($stmtEmail->rowCount() > 0) {
                $error = "Email address is already in use.";
            } else {
                $email_changed = ($email !== $user['email']);
                $stmtUpdate = $conn->prepare("UPDATE users SET username = ?, email = ?, email_verified = ? WHERE id = ?");
                $email_verified = $email_changed ? 0 : $user['email_verified'];

                if ($stmtUpdate->execute([$username, $email, $email_verified, $user_id])) {
                    $success = "Account settings updated successfully!";
                    $_SESSION['username'] = $username;
                    $user['username'] = $username;
                    $user['email'] = $email;
                    $user['email_verified'] = $email_verified;

                    if ($email_changed) {
                        $success .= " Please verify your new email address.";
                    }
                } else {
                    $error = "Failed to update account settings.";
                }
            }
        }
    }

    if (isset($_POST['update_preferences'])) {
        $shift_reminders = isset($_POST['shift_reminders']) ? 1 : 0;
        $shift_invitations = isset($_POST['shift_invitations']) ? 1 : 0;
        $schedule_changes = isset($_POST['schedule_changes']) ? 1 : 0;
        $payroll_updates = isset($_POST['payroll_updates']) ? 1 : 0;

        try {
            // Create table if it doesn't exist
            $conn->exec("
                CREATE TABLE IF NOT EXISTS notification_preferences (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    shift_reminders TINYINT(1) DEFAULT 1,
                    shift_invitations TINYINT(1) DEFAULT 1,
                    schedule_changes TINYINT(1) DEFAULT 1,
                    payroll_updates TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user (user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ");

            $stmt = $conn->prepare("
                INSERT INTO notification_preferences (user_id, shift_reminders, shift_invitations, schedule_changes, payroll_updates)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                shift_reminders = VALUES(shift_reminders),
                shift_invitations = VALUES(shift_invitations),
                schedule_changes = VALUES(schedule_changes),
                payroll_updates = VALUES(payroll_updates)
            ");

            if ($stmt->execute([$user_id, $shift_reminders, $shift_invitations, $schedule_changes, $payroll_updates])) {
                $notification_prefs = [
                    'shift_reminders' => (bool) $shift_reminders,
                    'shift_invitations' => (bool) $shift_invitations,
                    'schedule_changes' => (bool) $schedule_changes,
                    'payroll_updates' => (bool) $payroll_updates
                ];
                $success = "Notification preferences updated successfully!";
            } else {
                $error = "Failed to update notification preferences.";
            }
        } catch (Exception $e) {
            $error = "Could not update preferences: " . $e->getMessage();
        }
    }

    // Handle branch change
    if (isset($_POST['change_branch'])) {
        $new_branch_id = isset($_POST['branch_id']) ? (int) $_POST['branch_id'] : 0;
        
        if ($new_branch_id <= 0) {
            $error = "Please select a valid branch.";
        } else {
            // Verify the branch exists and is active
            $branch_check = $conn->prepare("SELECT id, name FROM branches WHERE id = ? AND status = 'active'");
            $branch_check->execute([$new_branch_id]);
            $selected_branch = $branch_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$selected_branch) {
                $error = "Selected branch is not available.";
            } elseif ($new_branch_id == $user['branch_id']) {
                $error = "You are already assigned to this branch.";
            } else {
                try {
                    // Update user's branch
                    $update_stmt = $conn->prepare("UPDATE users SET branch_id = ? WHERE id = ?");
                    if ($update_stmt->execute([$new_branch_id, $user_id])) {
                        // Log the branch change for audit purposes
                        $log_stmt = $conn->prepare("
                            INSERT INTO user_activity_log (user_id, action, details, created_at) 
                            VALUES (?, 'branch_change', ?, NOW())
                        ");
                        $log_details = json_encode([
                            'old_branch_id' => $user['branch_id'],
                            'new_branch_id' => $new_branch_id,
                            'old_branch_name' => $user['branch_name'],
                            'new_branch_name' => $selected_branch['name']
                        ]);
                        $log_stmt->execute([$user_id, $log_details]);
                        
                        $success = "Branch changed successfully to " . $selected_branch['name'] . "!";
                        
                        // Update the user data for display
                        $user['branch_id'] = $new_branch_id;
                        $user['branch_name'] = $selected_branch['name'];
                    } else {
                        $error = "Failed to update branch assignment.";
                    }
                } catch (Exception $e) {
                    $error = "Error updating branch: " . $e->getMessage();
                }
            }
        }
    }

    // Redirect to prevent form resubmission
    if ($success) {
        addNotification($conn, $user_id, $success, 'success');
    }
    if ($error) {
        addNotification($conn, $user_id, $error, 'error');
    }

    header("Location: settings.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Open Rota</title>
    <link rel="stylesheet" href="../css/settings.css">
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Modern settings page styles matching application theme */
        body {
            font-family: "newFont", Arial, sans-serif;
            background: url("../images/backg3.jpg") no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            color: #000;
        }

        .settings-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .page-header h1 {
            color: #fd2b2b;
            font-size: 2.5rem;
            margin-bottom: 10px;
            background-color: rgba(255, 255, 255, 0.9);
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .page-header p {
            font-size: 1.1rem;
            color: #333;
            background-color: rgba(255, 255, 255, 0.8);
            padding: 10px;
            border-radius: 8px;
            margin: 0 auto;
            max-width: 600px;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }

        .settings-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .settings-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .card-icon {
            width: 50px;
            height: 50px;
            background: #fd2b2b;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin: 0;
        }

        .profile-overview {
            grid-column: span 2;
            background: #fd2b2b;
            color: white;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: bold;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }

        .profile-info h2 {
            margin: 0 0 5px 0;
            font-size: 1.8rem;
        }

        .role-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
        }

        .stat-item {
            text-align: center;
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #fd2b2b;
            box-shadow: 0 0 0 3px rgba(253, 43, 43, 0.1);
        }

        .checkbox-group {
            display: grid;
            gap: 15px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .checkbox-item:hover {
            background: #e9ecef;
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #fd2b2b;
        }

        .checkbox-label {
            flex: 1;
        }

        .checkbox-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 3px;
        }

        .checkbox-description {
            font-size: 0.9rem;
            color: #666;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #fd2b2b;
            color: white;
        }

        .btn-primary:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 43, 43, 0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
        }

        /* Branch Management Styles */
        .current-branch-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 15px 0;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
        }

        .info-value {
            color: #212529;
            font-weight: 500;
        }

        .form-text {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 5px;
            display: block;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 40px;
        }

        .action-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 25px;
            border-radius: 15px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            color: #333;
        }

        .action-card i {
            font-size: 2rem;
            margin-bottom: 15px;
            color: #fd2b2b;
        }

        .action-card h4 {
            margin: 0 0 10px 0;
            font-size: 1.2rem;
        }

        .action-card p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }

        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }

            .profile-overview {
                grid-column: span 1;
            }

            .profile-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .page-header h1 {
                font-size: 2rem;
            }
        }

        @media (max-width: 480px) {
            .settings-container {
                padding: 10px;
            }

            .settings-card {
                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header style="opacity: 1; transition: opacity 0.5s ease;">
        <div class="logo">Open Rota</div>
        <div class="nav-group">
            <div class="notification-container">
                <!-- Bell Icon -->
                <i class="fa fa-bell notification-icon" id="notification-icon"></i>
                <?php if ($notificationCount > 0): ?>
                    <span class="notification-badge"><?php echo $notificationCount; ?></span>
                <?php endif; ?>
                
                <!-- Notification Dropdown -->
                <div class="notification-dropdown" id="notification-dropdown">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item" data-id="<?php echo $notification['id']; ?>">
                                <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                <small><?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></small>
                                <button onclick="markAsRead(this)" class="mark-read-btn">Mark as Read</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-item">
                            <p>No notifications</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Hamburger Menu -->
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
                <li><a href="payroll.php"><i class="fa fa-money-bill-wave"></i> Payroll</a></li>
                <li><a href="settings.php"><i class="fa fa-cogs"></i> Settings</a></li>
                <li><a href="../functions/logout.php"><i class="fa fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </header>
    </header>

    <div class="settings-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-cog"></i> Account Settings</h1>
            <p>Manage your account preferences and notification settings</p>
        </div>

        <!-- Display Messages -->
        <?php if ($success): ?>
            <div class="message success-message">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="settings-grid">
            <!-- Profile Overview -->
            <div class="settings-card profile-overview">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($user['username'], 0, 2)); ?>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($user['username']); ?></h2>
                        <div class="role-badge"><?php echo htmlspecialchars(ucfirst($user['role'])); ?></div>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $user_stats['total_shifts'] ?? 0; ?></div>
                        <div class="stat-label">Total Shifts</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $user_stats['shifts_last_30_days'] ?? 0; ?></div>
                        <div class="stat-label">This Month</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $user_stats['hours_last_30_days'] ?? 0; ?></div>
                        <div class="stat-label">Hours (30d)</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $user['email_verified'] ? 'Yes' : 'No'; ?></div>
                        <div class="stat-label">Verified</div>
                    </div>
                </div>
            </div>

            <!-- Account Management -->
            <div class="settings-card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-user-cog"></i>
                    </div>
                    <h3 class="card-title">Account Management</h3>
                </div>

                <form method="POST">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-control" 
                               value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        <?php if (!$user['email_verified']): ?>
                            <small style="color: #dc3545;">
                                <i class="fas fa-exclamation-triangle"></i> Email not verified
                            </small>
                        <?php endif; ?>
                    </div>

                    <button type="submit" name="update_account" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Account
                    </button>
                </form>
            </div>

            <!-- Branch Management -->
            <div class="settings-card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <h3 class="card-title">Branch Assignment</h3>
                </div>

                <div class="current-branch-info">
                    <div class="info-item">
                        <span class="info-label">Current Branch:</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['branch_name'] ?? 'Not Assigned'); ?></span>
                    </div>
                    <?php if ($user['branch_code']): ?>
                    <div class="info-item">
                        <span class="info-label">Branch Code:</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['branch_code']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <form method="POST" style="margin-top: 20px;">
                    <div class="form-group">
                        <label for="branch_id">Change Branch</label>
                        <select id="branch_id" name="branch_id" class="form-control" required>
                            <option value="">Select a new branch...</option>
                            <?php foreach ($available_branches as $branch): ?>
                                <?php if ($branch['id'] != $user['branch_id']): ?>
                                    <option value="<?php echo $branch['id']; ?>">
                                        <?php echo htmlspecialchars($branch['name']); ?>
                                        <?php if ($branch['code']): ?>
                                            (<?php echo htmlspecialchars($branch['code']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text">
                            <i class="fas fa-info-circle"></i> 
                            Select a different branch if you need to transfer or correct your assignment
                        </small>
                    </div>

                    <button type="submit" name="change_branch" class="btn btn-warning" 
                            onclick="return confirm('Are you sure you want to change your branch assignment? This action will be logged.');">
                        <i class="fas fa-exchange-alt"></i> Change Branch
                    </button>
                </form>
            </div>

            <!-- Notification Preferences -->
            <div class="settings-card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h3 class="card-title">Notification Preferences</h3>
                </div>

                <form method="POST">
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="shift_reminders" name="shift_reminders" 
                                   <?php echo $notification_prefs['shift_reminders'] ? 'checked' : ''; ?>>
                            <div class="checkbox-label">
                                <div class="checkbox-title">Shift Reminders</div>
                                <div class="checkbox-description">Get notified about upcoming shifts</div>
                            </div>
                        </div>

                        <div class="checkbox-item">
                            <input type="checkbox" id="shift_invitations" name="shift_invitations" 
                                   <?php echo $notification_prefs['shift_invitations'] ? 'checked' : ''; ?>>
                            <div class="checkbox-label">
                                <div class="checkbox-title">Shift Invitations</div>
                                <div class="checkbox-description">Receive invitations for new shifts</div>
                            </div>
                        </div>

                        <div class="checkbox-item">
                            <input type="checkbox" id="schedule_changes" name="schedule_changes" 
                                   <?php echo $notification_prefs['schedule_changes'] ? 'checked' : ''; ?>>
                            <div class="checkbox-label">
                                <div class="checkbox-title">Schedule Changes</div>
                                <div class="checkbox-description">Notifications about schedule updates</div>
                            </div>
                        </div>

                        <div class="checkbox-item">
                            <input type="checkbox" id="payroll_updates" name="payroll_updates" 
                                   <?php echo $notification_prefs['payroll_updates'] ? 'checked' : ''; ?>>
                            <div class="checkbox-label">
                                <div class="checkbox-title">Payroll Updates</div>
                                <div class="checkbox-description">Updates about payment and payroll</div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="update_preferences" class="btn btn-secondary" style="margin-top: 20px;">
                        <i class="fas fa-save"></i> Save Preferences
                    </button>
                </form>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="dashboard.php" class="action-card">
                <i class="fas fa-home"></i>
                <h4>Dashboard</h4>
                <p>Return to your main dashboard</p>
            </a>

            <a href="../functions/change_password.php" class="action-card">
                <i class="fas fa-key"></i>
                <h4>Change Password</h4>
                <p>Update your account password</p>
            </a>

            <a href="shifts.php" class="action-card">
                <i class="fas fa-calendar-alt"></i>
                <h4>My Shifts</h4>
                <p>View and manage your shifts</p>
            </a>

            <a href="../functions/logout.php" class="action-card" style="color: #dc3545;" onclick="return confirm('Are you sure you want to sign out?')">
                <i class="fas fa-sign-out-alt"></i>
                <h4>Sign Out</h4>
                <p>Securely logout of your account</p>
            </a>
        </div>
    </div>

    <script>
        // Initialize settings page functionality
        document.addEventListener('DOMContentLoaded', function () {
            // Auto-hide messages after 5 seconds
            const messages = document.querySelectorAll('.message');
            messages.forEach(message => {
                setTimeout(() => {
                    message.style.opacity = '0';
                    setTimeout(() => {
                        message.remove();
                    }, 300);
                }, 5000);
            });

            // Add click handlers for checkbox items
            const checkboxItems = document.querySelectorAll('.checkbox-item');
            checkboxItems.forEach(item => {
                item.addEventListener('click', function (e) {
                    if (e.target.type !== 'checkbox') {
                        const checkbox = this.querySelector('input[type="checkbox"]');
                        checkbox.checked = !checkbox.checked;
                    }
                });
            });

            // Form validation
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function (e) {
                    const requiredFields = this.querySelectorAll('[required]');
                    let isValid = true;

                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = '#dc3545';
                        } else {
                            field.style.borderColor = '#e0e0e0';
                        }
                    });

                    if (!isValid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                    }
                });
            });

            // Email validation
            const emailField = document.getElementById('email');
            if (emailField) {
                emailField.addEventListener('blur', function () {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(this.value)) {
                        this.style.borderColor = '#dc3545';
                    } else {
                        this.style.borderColor = '#28a745';
                    }
                });
            }

            // Username validation
            const usernameField = document.getElementById('username');
            if (usernameField) {
                usernameField.addEventListener('input', function () {
                    if (this.value.length < 3) {
                        this.style.borderColor = '#dc3545';
                    } else {
                        this.style.borderColor = '#28a745';
                    }
                });
            }
        });
    </script>

    <script src="../js/menu.js"></script>
    <script src="../js/session-timeout.js"></script>
</body>
</html>