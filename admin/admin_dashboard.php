<?php
require_once '../includes/auth.php';
requireAdmin(); // Only admins can access
require_once '../functions/branch_functions.php';
require_once '../includes/super_admin.php';

// Get current admin user's branch
$currentUserId = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
$adminBranchId = $adminUser['branch_id'];

// Check if user is super admin
$isSuperAdmin = isSuperAdmin($currentUserId, $conn);

// Fetch total users (all for super admin, branch-specific for regular admin)
$userCountQuery = "SELECT COUNT(*) FROM users";
$userCountParams = [];
if (!$isSuperAdmin && $adminBranchId) {
    $userCountQuery .= " WHERE branch_id = ?";
    $userCountParams[] = $adminBranchId;
}
$stmt = $conn->prepare($userCountQuery);
$stmt->execute($userCountParams);
$totalUsers = $stmt->fetchColumn();

// Fetch total shifts (all for super admin, branch-specific for regular admin)
$shiftCountQuery = "SELECT COUNT(*) FROM shifts s JOIN users u ON s.user_id = u.id";
$shiftCountParams = [];
if (!$isSuperAdmin && $adminBranchId) {
    $shiftCountQuery .= " WHERE u.branch_id = ?";
    $shiftCountParams[] = $adminBranchId;
}
$stmt = $conn->prepare($shiftCountQuery);
$stmt->execute($shiftCountParams);
$totalShifts = $stmt->fetchColumn();

// Fetch role distribution (all for super admin, branch-specific for regular admin)
$roleQuery = "SELECT role, COUNT(*) as count FROM users";
$roleParams = [];
if (!$isSuperAdmin && $adminBranchId) {
    $roleQuery .= " WHERE branch_id = ?";
    $roleParams[] = $adminBranchId;
}
$roleQuery .= " GROUP BY role";
$stmt = $conn->prepare($roleQuery);
$stmt->execute($roleParams);
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Fetch shifts per day (only from admin's branch)
$shiftsPerDayQuery = "
    SELECT s.shift_date, COUNT(*) as count
    FROM shifts s 
    JOIN users u ON s.user_id = u.id";
$shiftsPerDayParams = [];
if ($adminBranchId) {
    $shiftsPerDayQuery .= " WHERE u.branch_id = ?";
    $shiftsPerDayParams[] = $adminBranchId;
}
$shiftsPerDayQuery .= " GROUP BY s.shift_date ORDER BY s.shift_date DESC LIMIT 10";
$stmt = $conn->prepare($shiftsPerDayQuery);
$stmt->execute($shiftsPerDayParams);
$shiftsPerDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get branch filter parameter (super admins can filter, regular admins see only their branch)
$branchFilter = $_GET['branch_filter'] ?? 'all';

// Fetch users (all for super admin, branch-specific for regular admin)
$whereClause = '';
$params = [];
if ($isSuperAdmin) {
    // Super admin can see all users and filter by branch
    if ($branchFilter !== 'all') {
        if ($branchFilter === 'none') {
            $whereClause = 'WHERE u.branch_id IS NULL';
        } else {
            $whereClause = 'WHERE u.branch_id = ?';
            $params[] = $branchFilter;
        }
    }
} else {
    // Regular admin sees only their branch users
    if ($adminBranchId) {
        $whereClause = 'WHERE u.branch_id = ?';
        $params[] = $adminBranchId;
    } else {
        $whereClause = 'WHERE u.branch_id IS NULL';
    }
}

$stmt = $conn->prepare("
    SELECT u.id, u.username, u.email, u.role, u.created_at, u.branch_id,
           b.name as branch_name, b.code as branch_code
    FROM users u
    LEFT JOIN branches b ON u.branch_id = b.id
    $whereClause
    ORDER BY u.id
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch branches (all branches for both super admin and regular admin for filtering)
$branches = $conn->query("SELECT id, name, code FROM branches WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Handle role update if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    $userId = $_POST['user_id'];
    $newRole = $_POST['role'];

    $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
    if ($stmt->execute([$newRole, $userId])) {
        $_SESSION['success_message'] = "User role updated successfully";
    } else {
        $_SESSION['error_message'] = "Error updating user role";
    }
    header("Location: admin_dashboard.php");
    exit();
}

// Handle branch assignment if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_branch'])) {
    $userId = $_POST['user_id'];
    $newBranchId = $_POST['branch_id'] === '' ? null : $_POST['branch_id'];

    // Super admins can assign to any branch, regular admins only to their own branch
    if (!$isSuperAdmin && $newBranchId && $newBranchId != $adminBranchId) {
        $_SESSION['error_message'] = "You can only assign users to your own branch";
    } else {
        $stmt = $conn->prepare("UPDATE users SET branch_id = ? WHERE id = ?");
        if ($stmt->execute([$newBranchId, $userId])) {
            $_SESSION['success_message'] = "User branch assignment updated successfully";
        } else {
            $_SESSION['error_message'] = "Error updating user branch assignment";
        }
    }
    header("Location: admin_dashboard.php");
    exit();
}

// Check for messages from other operations (like user deletion)
if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Determine view type (week or day)
$viewType = isset($_GET['view']) ? $_GET['view'] : 'week'; // Default to week view

// Get current week and day for filtering
$currentWeekStart = isset($_GET['week_start']) ? $_GET['week_start'] : date('Y-m-d', strtotime('monday this week'));
$currentDay = isset($_GET['day']) ? $_GET['day'] : date('Y-m-d');

// Calculate previous and next week/day for navigation
$prevWeekStart = date('Y-m-d', strtotime('-1 week', strtotime($currentWeekStart)));
$nextWeekStart = date('Y-m-d', strtotime('+1 week', strtotime($currentWeekStart)));
$prevDay = date('Y-m-d', strtotime('-1 day', strtotime($currentDay)));
$nextDay = date('Y-m-d', strtotime('+1 day', strtotime($currentDay)));

// Get all shifts with user and role info based on view type (only from admin's branch)
if ($viewType === 'week') {
    $weekQuery = "
        SELECT s.*, u.username, r.name as role_name
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        JOIN roles r ON s.role_id = r.id
        WHERE s.shift_date BETWEEN ? AND DATE_ADD(?, INTERVAL 6 DAY)";
    $weekParams = [$currentWeekStart, $currentWeekStart];
    
    if ($adminBranchId) {
        $weekQuery .= " AND u.branch_id = ?";
        $weekParams[] = $adminBranchId;
    }
    
    $weekQuery .= " ORDER BY s.shift_date, s.start_time";
    $stmt = $conn->prepare($weekQuery);
    $stmt->execute($weekParams);
    $allShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else { // day view
    $dayQuery = "
        SELECT s.*, u.username, r.name as role_name
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        JOIN roles r ON s.role_id = r.id
        WHERE s.shift_date = ?";
    $dayParams = [$currentDay];
    
    if ($adminBranchId) {
        $dayQuery .= " AND u.branch_id = ?";
        $dayParams[] = $adminBranchId;
    }
    
    $dayQuery .= " ORDER BY s.start_time";
    $stmt = $conn->prepare($dayQuery);
    $stmt->execute($dayParams);
    $allShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title>Admin Dashboard - Open Rota</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        /* Enhanced Admin Dashboard Styles */
        body {
            font-family: 'newFont', Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: url('../images/backg3.jpg') no-repeat center center fixed;
            background-size: cover;
            color: #333;
        }

        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background-color: rgba(255, 255, 255, 0.9);
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .admin-title h1 {
            color: #fd2b2b;
            font-size: 1.8rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-actions {
            display: flex;
            gap: 10px;
        }

        .admin-btn {
            padding: 8px 15px;
            background-color: #fd2b2b;
            color: white;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
            transition: background-color 0.3s, transform 0.2s;
        }

        .admin-btn:hover {
            background-color: #e61919;
            transform: translateY(-2px);
        }

        .admin-btn.secondary {
            background-color: #555;
        }

        .admin-btn.secondary:hover {
            background-color: #444;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #fd2b2b;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        .admin-panel {
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .admin-panel-header {
            padding: 15px 20px;
            background-color: #fd2b2b;
            color: white;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .admin-panel-body {
            padding: 20px;
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
        }

        .admin-table th {
            background-color: #f4f4f4;
            padding: 12px 15px;
            text-align: left;
            color: #333;
            font-weight: 600;
            border-bottom: 2px solid #ddd;
        }

        .admin-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }

        .admin-table tr:hover {
            background-color: #f9f9f9;
        }

        .admin-table .actions {
            display: flex;
            gap: 8px;
        }

        .admin-form {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .admin-form select {
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: white;
        }

        .admin-form button {
            padding: 6px 10px;
            background-color: #fd2b2b;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .view-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .view-navigation {
            display: flex;
            gap: 10px;
        }

        .view-toggle {
            display: flex;
            gap: 5px;
        }

        .view-toggle a {
            padding: 6px 12px;
            background-color: #eee;
            color: #333;
            text-decoration: none;
            border-radius: 4px;
        }

        .view-toggle a.active {
            background-color: #fd2b2b;
            color: white;
        }

        .day-header {
            background-color: #f0f0f0;
            font-weight: bold;
            color: #333;
            text-align: center;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            border-left: 4px solid #28a745;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            border-left: 4px solid #dc3545;
        }

        @media (max-width: 768px) {
            .admin-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .admin-actions {
                width: 100%;
                justify-content: flex-start;
                overflow-x: auto;
                padding-bottom: 5px;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }

            .admin-table {
                display: block;
                overflow-x: auto;
            }

            .view-controls {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Branch-related styles */
        .branch-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .branch-badge small {
            color: #666;
            font-weight: normal;
        }

        .no-branch {
            color: #999;
            font-style: italic;
            font-size: 12px;
        }

        .admin-form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .admin-form select {
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
            min-width: 120px;
        }

        .admin-form button {
            padding: 4px 12px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            white-space: nowrap;
        }

        .admin-form button:hover {
            background: #0056b3;
        }

        .actions {
            min-width: 300px;
        }

        .panel-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .super-admin-badge {
            background: #dc3545;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }

        .admin-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #ddd;
        }
    </style>
</head>

<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-shield-alt"></i> Admin Dashboard</h1>
            </div>
            <div class="admin-actions">
                <a href="../users/dashboard.php" class="admin-btn secondary">
                    <i class="fas fa-arrow-left"></i> Return to Dashboard
                </a>
                <a href="../functions/shift_invitation_sender.php" class="admin-btn">
                    <i class="fas fa-paper-plane"></i> Send Shift Invitations
                </a>
                <a href="upload_shifts.php" class="admin-btn">
                    <i class="fas fa-upload"></i> Upload Shifts
                </a>
                <a href="manage_shifts.php" class="admin-btn">
                    <i class="fas fa-calendar-alt"></i> Manage Shifts
                </a>
                <a href="branch_management.php" class="admin-btn">
                    <i class="fas fa-building"></i> Branch Management
                </a>
                <a href="cross_branch_requests.php" class="admin-btn">
                    <i class="fas fa-exchange-alt"></i> Cross-Branch Requests
                </a>
                <a href="payroll_management.php" class="admin-btn">
                    <i class="fas fa-money-bill-wave"></i> Payroll Management
                </a>
            </div>
        </div>

        <?php if (isset($successMessage)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo $successMessage; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errorMessage)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $errorMessage; ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $totalUsers; ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $totalShifts; ?></div>
                <div class="stat-label">Total Shifts</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($shiftsPerDay) ? $shiftsPerDay[0]['count'] : 0; ?></div>
                <div class="stat-label">Shifts Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?php
                    $adminCount = 0;
                    foreach ($roles as $role) {
                        if ($role['role'] === 'admin') {
                            $adminCount = $role['count'];
                            break;
                        }
                    }
                    echo $adminCount;
                    ?>
                </div>
                <div class="stat-label">Admin Users</div>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-users-cog"></i> User Management</h2>
                <div class="panel-info">
                    <?php if ($isSuperAdmin): ?>
                        <div class="panel-controls">
                            <form method="GET" style="display: inline-block;">
                                <select name="branch_filter" onchange="this.form.submit()" style="padding: 8px; border-radius: 4px; border: 1px solid #ddd;">
                                    <option value="all" <?php echo $branchFilter === 'all' ? 'selected' : ''; ?>>All Users</option>
                                    <option value="none" <?php echo $branchFilter === 'none' ? 'selected' : ''; ?>>No Branch Assigned</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo $branch['id']; ?>" <?php echo $branchFilter == $branch['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($branch['name']); ?> (<?php echo htmlspecialchars($branch['code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <span class="super-admin-badge">Super Admin</span>
                        </div>
                    <?php else: ?>
                        <small>Managing users for your branch</small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Branch</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <span class="role-badge <?php echo $user['role']; ?>">
                                        <?php echo ucfirst(htmlspecialchars($user['role'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['branch_name']): ?>
                                        <span class="branch-badge">
                                            <?php echo htmlspecialchars($user['branch_name']); ?>
                                            <small>(<?php echo htmlspecialchars($user['branch_code']); ?>)</small>
                                        </span>
                                    <?php else: ?>
                                        <span class="no-branch">No Branch Assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <!-- Role Update Form -->
                                    <form method="POST" class="admin-form" style="margin-bottom: 10px;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <select name="role">
                                            <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>
                                                User</option>
                                            <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>
                                                Admin</option>
                                        </select>
                                        <button type="submit" name="update_role">Update Role</button>
                                    </form>
                                    
                                    <!-- Branch Assignment Form -->
                                    <form method="POST" class="admin-form" style="margin-bottom: 10px;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <select name="branch_id">
                                            <option value="">No Branch</option>
                                            <?php foreach ($branches as $branch): ?>
                                                <option value="<?php echo $branch['id']; ?>" 
                                                    <?php echo $user['branch_id'] == $branch['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($branch['name']); ?> (<?php echo htmlspecialchars($branch['code']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="update_branch">Update Branch</button>
                                    </form>
                                    
                                    <a href="manage_shifts.php?user_id=<?php echo $user['id']; ?>" class="admin-btn">
                                        <i class="fas fa-calendar"></i> Shifts
                                    </a>
                                    <?php if ($user['id'] != $_SESSION['user_id']): // Don't allow deleting own account ?>
                                        <a href="../functions/delete_user.php?id=<?php echo $user['id']; ?>"
                                            class="admin-btn delete-btn"
                                            onclick="return confirm('Are you sure you want to delete user \'<?php echo htmlspecialchars($user['username']); ?>\'? This action cannot be undone and will also delete all their shifts and payroll data.');">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    <?php else: ?>
                                        <span class="admin-btn disabled" title="You cannot delete your own account">
                                            <i class="fas fa-trash"></i> Delete
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-calendar-week"></i> All Shifts</h2>
                <div class="view-toggle">
                    <a href="?view=week&week_start=<?php echo $currentWeekStart; ?>"
                        class="<?php echo $viewType === 'week' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-week"></i> Week
                    </a>
                    <a href="?view=day&day=<?php echo $currentDay; ?>"
                        class="<?php echo $viewType === 'day' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-day"></i> Day
                    </a>
                </div>
            </div>
            <div class="admin-panel-body">
                <div class="view-controls">
                    <div class="view-navigation">
                        <?php if ($viewType === 'week'): ?>
                            <a href="?view=week&week_start=<?php echo $prevWeekStart; ?>" class="admin-btn">
                                <i class="fas fa-chevron-left"></i> Previous Week
                            </a>
                            <a href="?view=week&week_start=<?php echo date('Y-m-d', strtotime('monday this week')); ?>"
                                class="admin-btn">
                                Current Week
                            </a>
                            <a href="?view=week&week_start=<?php echo $nextWeekStart; ?>" class="admin-btn">
                                Next Week <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <a href="?view=day&day=<?php echo $prevDay; ?>" class="admin-btn">
                                <i class="fas fa-chevron-left"></i> Previous Day
                            </a>
                            <a href="?view=day&day=<?php echo date('Y-m-d'); ?>" class="admin-btn">
                                Today
                            </a>
                            <a href="?view=day&day=<?php echo $nextDay; ?>" class="admin-btn">
                                Next Day <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Role</th>
                            <th>Location</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allShifts)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 30px;">
                                    <i class="fas fa-calendar-times"
                                        style="font-size: 2rem; color: #ddd; margin-bottom: 10px; display: block;"></i>
                                    <p style="margin: 0;">No shifts found for this period</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $currentDate = '';
                            foreach ($allShifts as $shift):
                                $shiftDate = $shift['shift_date'];

                                // Add a separator row when the date changes
                                if ($viewType === 'week' && $currentDate !== $shiftDate):
                                    $currentDate = $shiftDate;
                                    ?>
                                    <tr>
                                        <td colspan="6" class="day-header">
                                            <i class="fas fa-calendar-day"></i>
                                            <?php echo date("l, F j, Y", strtotime($currentDate)); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($shift['username']); ?></td>
                                    <td><?php echo date("D, M j", strtotime($shift['shift_date'])); ?></td>
                                    <td>
                                        <?php echo date("g:i A", strtotime($shift['start_time'])); ?> -
                                        <?php echo date("g:i A", strtotime($shift['end_time'])); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($shift['role_name']); ?></td>
                                    <td><?php echo htmlspecialchars($shift['location']); ?></td>
                                    <td class="actions">
                                        <a href="edit_shift.php?id=<?php echo $shift['id']; ?>" class="admin-btn">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete_shift.php?id=<?php echo $shift['id']; ?>" class="admin-btn secondary"
                                            onclick="return confirm('Are you sure you want to delete this shift?');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>