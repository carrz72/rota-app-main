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
        <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

</head>

<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-tachometer-alt"></i> Admin Dashboard</h1>
                <div class="admin-subtitle">
                    <?php if ($isSuperAdmin): ?>
                        <span class="super-admin-badge"><i class="fas fa-crown"></i> Super Admin</span>
                    <?php else: ?>
                        <span class="branch-info">Managing: 
                            <?php 
                            $branchName = 'Default Branch';
                            foreach ($branches as $branch) {
                                if ($branch['id'] == $adminBranchId) {
                                    $branchName = $branch['name'];
                                    break;
                                }
                            }
                            echo htmlspecialchars($branchName);
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="admin-nav-grid">
                <a href="../users/dashboard.php" class="admin-nav-card" title="Return to main dashboard">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="manage_shifts.php" class="admin-nav-card primary" title="View and manage all shifts">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Manage Shifts</span>
                </a>
                <a href="../functions/shift_invitation_sender.php" class="admin-nav-card" title="Send shift invitations to users">
                    <i class="fas fa-paper-plane"></i>
                    <span>Send Invitations</span>
                </a>
                <a href="upload_shifts.php" class="admin-nav-card" title="Bulk upload shifts from file">
                    <i class="fas fa-upload"></i>
                    <span>Upload Shifts</span>
                </a>
                <a href="branch_management.php" class="admin-nav-card" title="Manage branches and locations">
                    <i class="fas fa-building"></i>
                    <span>Branches</span>
                </a>
                <a href="cross_branch_requests.php" class="admin-nav-card" title="Handle cross-branch coverage requests">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Cross-Branch</span>
                </a>
                <a href="payroll_management.php" class="admin-nav-card" title="Manage payroll and payments">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Payroll</span>
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
            <div class="stat-card users">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $totalUsers; ?></div>
                    <div class="stat-label">Total Users</div>
                    <div class="stat-trend">
                        <?php if ($isSuperAdmin): ?>
                            <small>Across all branches</small>
                        <?php else: ?>
                            <small>In your branch</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="stat-card admins">
                <div class="stat-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="stat-content">
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
                    <div class="stat-trend">
                        <small><?php echo round(($adminCount / max($totalUsers, 1)) * 100, 1); ?>% of total</small>
                    </div>
                </div>
            </div>
            
            <div class="stat-card shifts">
                <div class="stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo count($allShifts); ?></div>
                    <div class="stat-label">
                        <?php echo $viewType === 'week' ? 'This Week' : 'Today'; ?> Shifts
                    </div>
                    <div class="stat-trend">
                        <small><?php echo $viewType === 'week' ? 'Week of ' . date('M j', strtotime($currentWeekStart)) : date('M j, Y', strtotime($currentDay)); ?></small>
                    </div>
                </div>
            </div>
            
            <div class="stat-card branches">
                <div class="stat-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo count($branches); ?></div>
                    <div class="stat-label">Active Branches</div>
                    <div class="stat-trend">
                        <?php if ($isSuperAdmin): ?>
                            <small>System-wide</small>
                        <?php else: ?>
                            <small>Your access level</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-users-cog"></i> User Management</h2>
                <div class="panel-controls">
                    <?php if ($isSuperAdmin): ?>
                        <div class="filter-group">
                            <label for="branch-filter">Filter by Branch:</label>
                            <form method="GET" style="display: inline-block;">
                                <select name="branch_filter" id="branch-filter" onchange="this.form.submit()" class="form-control-inline">
                                    <option value="all" <?php echo $branchFilter === 'all' ? 'selected' : ''; ?>>All Branches</option>
                                    <option value="none" <?php echo $branchFilter === 'none' ? 'selected' : ''; ?>>No Branch Assigned</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo $branch['id']; ?>" <?php echo $branchFilter == $branch['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($branch['name']); ?> (<?php echo htmlspecialchars($branch['code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="branch-info">
                            <i class="fas fa-info-circle"></i>
                            <span>Managing users for your branch only</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="admin-panel-body">
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No Users Found</h3>
                        <p>No users found for the selected criteria.</p>
                        <?php if ($isSuperAdmin && $branchFilter !== 'all'): ?>
                            <a href="?branch_filter=all" class="admin-btn">View All Users</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-hashtag"></i> ID</th>
                                    <th><i class="fas fa-user"></i> Username</th>
                                    <th><i class="fas fa-envelope"></i> Email</th>
                                    <th><i class="fas fa-user-tag"></i> Role</th>
                                    <th><i class="fas fa-building"></i> Branch</th>
                                    <th><i class="fas fa-cogs"></i> Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr class="user-row">
                                <td data-label="ID">
                                    <span class="user-id">#<?php echo $user['id']; ?></span>
                                </td>
                                <td data-label="Username">
                                    <div class="user-info">
                                        <i class="fas fa-user-circle"></i>
                                        <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                    </div>
                                </td>
                                <td data-label="Email">
                                    <div class="email-info">
                                        <i class="fas fa-envelope"></i>
                                        <a href="mailto:<?php echo htmlspecialchars($user['email']); ?>">
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </a>
                                    </div>
                                </td>
                                <td data-label="Role">
                                    <div class="role-update-section">
                                        <span class="role-badge <?php echo $user['role']; ?>">
                                            <i class="fas fa-<?php echo $user['role'] === 'admin' ? 'user-shield' : 'user'; ?>"></i>
                                            <?php echo ucfirst(htmlspecialchars($user['role'])); ?>
                                        </span>
                                        <div class="quick-update-form">
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <select name="role" class="mini-select" onchange="this.form.submit()" title="Change user role">
                                                    <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                </select>
                                                <button type="submit" name="update_role" class="mini-btn" title="Update role">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Branch">
                                    <div class="branch-update-section">
                                        <?php if ($user['branch_name']): ?>
                                            <span class="branch-badge">
                                                <i class="fas fa-building"></i>
                                                <?php echo htmlspecialchars($user['branch_name']); ?>
                                                <small>(<?php echo htmlspecialchars($user['branch_code']); ?>)</small>
                                            </span>
                                        <?php else: ?>
                                            <span class="no-branch">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                No Branch Assigned
                                            </span>
                                        <?php endif; ?>
                                        <div class="quick-update-form">
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <select name="branch_id" class="mini-select" onchange="this.form.submit()" title="Change branch assignment">
                                                    <option value="">No Branch</option>
                                                    <?php foreach ($branches as $branch): ?>
                                                        <option value="<?php echo $branch['id']; ?>" 
                                                            <?php echo $user['branch_id'] == $branch['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($branch['name']); ?> (<?php echo htmlspecialchars($branch['code']); ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" name="update_branch" class="mini-btn" title="Update branch">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Actions">
                                    <div class="action-buttons">
                                        <a href="manage_shifts.php?user_id=<?php echo $user['id']; ?>" 
                                           class="action-btn shifts" title="View user's shifts">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span>Shifts</span>
                                        </a>
                                        <a href="add_shift.php?user_id=<?php echo $user['id']; ?>" 
                                           class="action-btn add" title="Add shift for user">
                                            <i class="fas fa-plus"></i>
                                            <span>Add</span>
                                        </a>
                                        <a href="edit_user.php?id=<?php echo $user['id']; ?>" 
                                           class="action-btn edit" title="Edit user details">
                                            <i class="fas fa-edit"></i>
                                            <span>Edit</span>
                                        </a>
                                        <button onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" 
                                                class="action-btn delete" title="Delete user">
                                            <i class="fas fa-trash"></i>
                                            <span>Delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-calendar-week"></i> All Shifts</h2>
                <div class="view-toggle">
                    <a href="?view=week&week_start=<?php echo $currentWeekStart; ?>"
                        class="admin-btn <?php echo $viewType === 'week' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-week"></i> Week
                    </a>
                    <a href="?view=day&day=<?php echo $currentDay; ?>"
                        class="admin-btn <?php echo $viewType === 'day' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-day"></i> Day
                    </a>
                </div>
            </div>
            <div class="admin-panel-body">
                <div class="view-controls">
                    <div class="period-nav-buttons">
                        <?php if ($viewType === 'week'): ?>
                            <a href="?view=week&week_start=<?php echo $prevWeekStart; ?>" class="btn">
                                <i class="fas fa-chevron-left"></i> Previous Week
                            </a>
                            <a href="?view=week&week_start=<?php echo date('Y-m-d', strtotime('monday this week')); ?>"
                                class="btn current">
                                Current Week
                            </a>
                            <a href="?view=week&week_start=<?php echo $nextWeekStart; ?>" class="btn">
                                Next Week <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <a href="?view=day&day=<?php echo $prevDay; ?>" class="btn">
                                <i class="fas fa-chevron-left"></i> Previous Day
                            </a>
                            <a href="?view=day&day=<?php echo date('Y-m-d'); ?>" class="btn current">
                                Today
                            </a>
                            <a href="?view=day&day=<?php echo $nextDay; ?>" class="btn">
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
                                    <td data-label="User"><?php echo htmlspecialchars($shift['username']); ?></td>
                                    <td data-label="Date"><?php echo date("D, M j", strtotime($shift['shift_date'])); ?></td>
                                    <td data-label="Time">
                                        <?php echo date("g:i A", strtotime($shift['start_time'])); ?> -
                                        <?php echo date("g:i A", strtotime($shift['end_time'])); ?>
                                    </td>
                                    <td data-label="Role"><?php echo htmlspecialchars($shift['role_name']); ?></td>
                                    <td data-label="Location"><?php echo htmlspecialchars($shift['location']); ?></td>
                                    <td class="actions" data-label="Actions">
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

    <script>
        // User management functions
        function confirmDelete(userId, username) {
            if (confirm(`Are you sure you want to delete user "${username}"? This action cannot be undone and will also delete all their shifts and payroll data.`)) {
                window.location.href = `../functions/delete_user.php?id=${userId}`;
            }
        }

        function confirmDeleteShift(shiftId, username, date) {
            if (confirm(`Are you sure you want to delete the shift for ${username} on ${date}?`)) {
                window.location.href = `../functions/delete_shift.php?id=${shiftId}`;
            }
        }

        function exportUsers() {
            // Simple CSV export functionality
            const table = document.querySelector('.admin-table');
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = [], cols = rows[i].querySelectorAll('td, th');
                for (let j = 0; j < cols.length - 1; j++) { // Exclude actions column
                    if (cols[j] && cols[j].innerText) {
                        row.push(cols[j].innerText.replace(/,/g, ';')); // Replace commas to avoid CSV issues
                    }
                }
                if (row.length > 0) {
                    csv.push(row.join(','));
                }
            }
            
            const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
            const downloadLink = document.createElement('a');
            downloadLink.download = `users_export_${new Date().toISOString().split('T')[0]}.csv`;
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = 'none';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }

        // Enhanced form submission with loading states
        document.querySelectorAll('.inline-form').forEach(form => {
            form.addEventListener('submit', function() {
                const button = this.querySelector('.mini-btn');
                if (button) {
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    button.disabled = true;
                }
            });
        });

        // Auto-save functionality for quick updates
        document.querySelectorAll('.mini-select').forEach(select => {
            select.addEventListener('change', function() {
                this.style.background = '#fff3cd';
                this.style.borderColor = '#ffc107';
                setTimeout(() => {
                    // Show loading state
                    const button = this.parentNode.querySelector('.mini-btn');
                    if (button) {
                        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                        button.disabled = true;
                    }
                }, 100);
            });
        });

        // Add tooltips and better user feedback
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to action buttons
            document.querySelectorAll('.action-btn').forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Add visual feedback for form changes
            document.querySelectorAll('select, input').forEach(input => {
                input.addEventListener('change', function() {
                    this.style.boxShadow = '0 0 0 3px rgba(253, 43, 43, 0.1)';
                    setTimeout(() => {
                        this.style.boxShadow = '';
                    }, 1000);
                });
            });
        });
    </script>

    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>

</html>