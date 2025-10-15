<?php
require_once '../includes/auth.php';
requireAdmin(); // Only admins can access
require_once '../functions/branch_functions.php';
require_once '../includes/super_admin.php';
require_once '../functions/calculate_pay.php';

// Get current admin user's branch
$currentUserId = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
$adminBranchId = $adminUser['branch_id'];

// Check if user is super admin
$isSuperAdmin = isSuperAdmin($currentUserId, $conn);

// Load retention-related settings for display to super admins (safe if table missing)
$displayScheduleAdmin = '';
$displayRetentionDays = '';
try {
    $stmt = $conn->prepare("SELECT v FROM app_settings WHERE `k` = ? LIMIT 1");
    $stmt->execute(['SCHEDULE_ADMIN_ID']);
    $displayScheduleAdmin = $stmt->fetchColumn();
    $stmt->execute(['AUDIT_RETENTION_DAYS']);
    $displayRetentionDays = $stmt->fetchColumn();
} catch (PDOException $e) {
    // app_settings table might not exist yet (migration not run). Fall back to empty defaults.
    $displayScheduleAdmin = '';
    $displayRetentionDays = '';
}

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
    // Super admin can see all users and filter by branch (support multiple branches)
    if ($branchFilter !== 'all') {
        if ($branchFilter === 'none') {
            $whereClause = 'WHERE u.branch_id IS NULL';
        } else {
            $branchIds = array_filter(explode(',', $branchFilter), function($id) { return $id !== ''; });
            if (count($branchIds) > 1) {
                $inClause = implode(',', array_fill(0, count($branchIds), '?'));
                $whereClause = 'WHERE u.branch_id IN (' . $inClause . ')';
                $params = array_merge($params, $branchIds);
            } else {
                $whereClause = 'WHERE u.branch_id = ?';
                $params[] = $branchFilter;
            }
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

    // Only allow super_admin to assign super_admin role, and prevent non-super_admins from assigning it
    if ($newRole === 'super_admin' && !$isSuperAdmin) {
        $_SESSION['error_message'] = "Only a super admin can assign the super admin role.";
        header("Location: admin_dashboard.php");
        exit();
    }

    // Prevent demoting the last super admin
    if ($newRole !== 'super_admin') {
        // Check if this is the last super admin
        $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'super_admin'");
        $stmtCheck->execute();
        $superAdminCount = $stmtCheck->fetchColumn();
        $stmtCurrent = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $stmtCurrent->execute([$userId]);
        $currentRole = $stmtCurrent->fetchColumn();
        if ($currentRole === 'super_admin' && $superAdminCount <= 1) {
            $_SESSION['error_message'] = "You cannot demote the last super admin.";
            header("Location: admin_dashboard.php");
            exit();
        }
    }

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
        SELECT s.*, u.username, r.name as role_name, b.name as branch_name
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        JOIN roles r ON s.role_id = r.id
        LEFT JOIN branches b ON s.branch_id = b.id
        WHERE s.shift_date BETWEEN ? AND DATE_ADD(?, INTERVAL 6 DAY)";
    $weekParams = [$currentWeekStart, $currentWeekStart];
    
    if ($adminBranchId) {
            // Include shifts where either the user's home branch matches the admin's branch
            // or the shift is explicitly scheduled at the admin's branch (s.branch_id)
            $weekQuery .= " AND (u.branch_id = ? OR s.branch_id = ?)";
            $weekParams[] = $adminBranchId;
            $weekParams[] = $adminBranchId;
    }
    
    $weekQuery .= " ORDER BY s.shift_date, s.start_time";
    $stmt = $conn->prepare($weekQuery);
    $stmt->execute($weekParams);
    $allShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else { // day view
    $dayQuery = "
        SELECT s.*, u.username, r.name as role_name, b.name as branch_name
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        JOIN roles r ON s.role_id = r.id
        LEFT JOIN branches b ON s.branch_id = b.id
        WHERE s.shift_date = ?";
    $dayParams = [$currentDay];
    
    if ($adminBranchId) {
    // Include shifts scheduled at admin's branch or performed by users from admin's branch
    $dayQuery .= " AND (u.branch_id = ? OR s.branch_id = ?)";
    $dayParams[] = $adminBranchId;
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
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
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
                <a href="track_invitations.php" class="admin-nav-card" title="Track shift invitations and responses">
                    <i class="fas fa-search"></i>
                    <span>Track Invitations</span>
                </a>
                <a href="upload_shifts.php" class="admin-nav-card" title="Bulk upload shifts from file">
                    <i class="fas fa-upload"></i>
                    <span>Upload Shifts</span>
                </a>
                <?php if ($isSuperAdmin): ?>
                <a href="audit_log.php" class="admin-nav-card" title="View audit log">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Audit Log</span>
                </a>
                <a href="audit_search.php" class="admin-nav-card" title="Search audit events">
                    <i class="fas fa-search"></i>
                    <span>Audit Search</span>
                </a>
                <a href="audit_settings.php" class="admin-nav-card" title="Audit log settings">
                    <i class="fas fa-toggle-on"></i>
                    <span>Audit Settings</span>
                </a>
                <?php endif; ?>
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
            <?php if ($isSuperAdmin): ?>
            <div class="stat-card retention">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo htmlspecialchars($displayRetentionDays ?: (365*3)); ?></div>
                    <div class="stat-label">Audit retention (days)</div>
                    <div class="stat-trend">
                        <small>Scheduled admin: <?php echo htmlspecialchars($displayScheduleAdmin ?: '(not set)'); ?></small>
                    </div>
                    <div style="margin-top:8px;"><a href="retention_settings.php" class="admin-btn">Manage retention</a></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-users-cog"></i> User Management</h2>
                <div class="panel-controls">
                    <?php if ($isSuperAdmin): ?>
                        <div class="filter-group">
                            <label for="branch-multiselect">Filter by Branch:</label>
                            <form method="GET" id="branch-multiselect-form" style="display: inline-block;" onsubmit="return submitBranchMultiSelect();">
                                <div id="branch-multiselect-container" class="multiselect-container">
                                    <div id="branch-tags" class="multiselect-tags" onclick="document.getElementById('branch-multiselect-search').focus();"></div>
                                    <input type="text" id="branch-multiselect-search" class="form-control-inline multiselect-search" placeholder="Tap to select up to 5 branches" onkeyup="filterBranchMultiOptions()" autocomplete="off">
                                    <button type="button" id="branch-multiselect-clear" class="multiselect-clear-btn" title="Clear selection" style="display:none;" onclick="clearBranchMultiSelect(event)"><i class="fas fa-times-circle"></i></button>
                                    <div id="branch-multiselect-dropdown" class="multiselect-dropdown" style="display:none;"></div>
                                </div>
                                <input type="hidden" name="branch_filter" id="branch-multiselect-value" value="<?php echo htmlspecialchars($branchFilter); ?>">
                            </form>
                        </div>
                        <style>
                        .multiselect-container {
                            position: relative;
                            display: flex;
                            flex-wrap: wrap;
                            align-items: center;
                            background: #fff;
                            border: 1.5px solid #b6b6b6;
                            border-radius: 8px;
                            min-height: 40px;
                            padding: 3px 8px 3px 8px;
                            cursor: text;
                            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
                            transition: border-color 0.2s;
                        }
                        .multiselect-container:focus-within {
                            border-color: #198754;
                        }
                        .multiselect-tags {
                            display: flex;
                            flex-wrap: wrap;
                            gap: 4px;
                            margin-right: 4px;
                        }
                        .multiselect-tag {
                            background: linear-gradient(90deg, #e9ecef 60%, #f8f9fa 100%);
                            border-radius: 4px;
                            padding: 2px 8px 2px 6px;
                            font-size: 13px;
                            margin: 2px 0;
                            display: flex;
                            align-items: center;
                            border: 1px solid #d1e7dd;
                        }
                        .multiselect-tag .remove-tag {
                            margin-left: 6px;
                            color: #dc3545;
                            cursor: pointer;
                            font-weight: bold;
                            font-size: 15px;
                        }
                        .multiselect-search {
                            border: none;
                            outline: none;
                            width: 140px;
                            min-width: 80px;
                            font-size: 14px;
                            background: transparent;
                            margin-left: 2px;
                            margin-right: 2px;
                        }
                        .multiselect-clear-btn {
                            background: none;
                            border: none;
                            color: #888;
                            font-size: 18px;
                            margin-left: 2px;
                            margin-right: 2px;
                            cursor: pointer;
                            align-self: center;
                            transition: color 0.2s;
                        }
                        .multiselect-clear-btn:hover {
                            color: #dc3545;
                        }
                        .multiselect-dropdown {
                            position: absolute;
                            top: 100%;
                            left: 0;
                            right: 0;
                            background: #fff;
                            border: 1.5px solid #b6b6b6;
                            border-radius: 0 0 8px 8px;
                            z-index: 10;
                            max-height: 200px;
                            overflow-y: auto;
                            box-shadow: 0 4px 16px rgba(0,0,0,0.10);
                        }
                        .multiselect-option {
                            padding: 9px 14px;
                            cursor: pointer;
                            font-size: 15px;
                            border-bottom: 1px solid #f1f1f1;
                            transition: background 0.15s;
                        }
                        .multiselect-option:last-child {
                            border-bottom: none;
                        }
                        .multiselect-option:hover {
                            background: #f8f9fa;
                        }
                        .multiselect-option.selected {
                            background: #d1e7dd;
                            color: #198754;
                            font-weight: bold;
                        }
                        </style>
                        <script>
                        // Branch data for JS
                        const branchMultiOptions = [
                            { id: 'all', label: 'All Branches' },
                            { id: 'none', label: 'No Branch Assigned' },
                            <?php foreach ($branches as $branch): ?>
                            { id: '<?php echo $branch['id']; ?>', label: '<?php echo addslashes(htmlspecialchars($branch['name'] . " (" . $branch['code'] . ")")); ?>' },
                            <?php endforeach; ?>
                        ];
                        let branchMultiSelected = [];
                        // Parse initial value from PHP
                        (function(){
                            let val = '<?php echo addslashes($branchFilter); ?>';
                            if (val === '' || val === 'all') branchMultiSelected = ['all'];
                            else if (val === 'none') branchMultiSelected = ['none'];
                            else branchMultiSelected = val.split(',').filter(Boolean);
                        })();
                        function renderBranchMultiSelect(triggerSubmit = false) {
                            const tagsDiv = document.getElementById('branch-tags');
                            tagsDiv.innerHTML = '';
    branchMultiSelected.forEach(id => {
        const opt = branchMultiOptions.find(o => o.id == id);
        if (opt) {
            const tag = document.createElement('span');
            tag.className = 'multiselect-tag';
            tag.textContent = opt.label;
            if (id !== 'all' && id !== 'none') {
                const remove = document.createElement('span');
                remove.className = 'remove-tag';
                remove.innerHTML = '&times;';
                remove.onclick = function(e) {
                    e.stopPropagation();
                    branchMultiSelected = branchMultiSelected.filter(x => x != id);
                    // If all tags are removed, default to 'all'
                    if (branchMultiSelected.length === 0) {
                        branchMultiSelected = ['all'];
                    }
                    renderBranchMultiSelect(true);
                    updateClearBtn();
                };
                tag.appendChild(remove);
            }
            tagsDiv.appendChild(tag);
        }
    });
    // If all tags were removed by other means, also default to 'all'
    if (branchMultiSelected.length === 0) {
        branchMultiSelected = ['all'];
    }
    document.getElementById('branch-multiselect-value').value = branchMultiSelected.join(',');
    updateClearBtn();
    if (triggerSubmit) {
        autoSubmitBranchMultiForm();
    }
                        }
                        function filterBranchMultiOptions() {
                            const search = document.getElementById('branch-multiselect-search').value.toLowerCase();
                            const dropdown = document.getElementById('branch-multiselect-dropdown');
                            dropdown.innerHTML = '';
                            let shown = 0;
                            branchMultiOptions.forEach(opt => {
                                if (opt.id === 'all' || opt.id === 'none' || (opt.label.toLowerCase().indexOf(search) > -1)) {
                                    const div = document.createElement('div');
                                    div.className = 'multiselect-option' + (branchMultiSelected.includes(opt.id) ? ' selected' : '');
                                    div.textContent = opt.label;
                                    div.onclick = function() {
                                        if (opt.id === 'all') {
                                            branchMultiSelected = ['all'];
                                        } else if (opt.id === 'none') {
                                            branchMultiSelected = ['none'];
                                        } else {
                                            if (branchMultiSelected.includes('all') || branchMultiSelected.includes('none')) branchMultiSelected = [];
                                            if (branchMultiSelected.includes(opt.id)) {
                                                branchMultiSelected = branchMultiSelected.filter(x => x != opt.id);
                                            } else if (branchMultiSelected.length < 5) {
                                                branchMultiSelected.push(opt.id);
                                            }
                                        }
                                        renderBranchMultiSelect(true);
                                        filterBranchMultiOptions();
                                    };
                                    dropdown.appendChild(div);
                                    shown++;
                                }
                            });
                            dropdown.style.display = shown ? 'block' : 'none';
                        }
                        function showBranchMultiDropdown() {
                            filterBranchMultiOptions();
                            document.getElementById('branch-multiselect-dropdown').style.display = 'block';
                        }
                        function hideBranchMultiDropdown() {
                            setTimeout(() => {
                                document.getElementById('branch-multiselect-dropdown').style.display = 'none';
                            }, 150);
                        }
                        function submitBranchMultiSelect() {
                            // If nothing selected, default to all
                            if (!branchMultiSelected.length) branchMultiSelected = ['all'];
                            document.getElementById('branch-multiselect-value').value = branchMultiSelected.join(',');
                            return true;
                        }
                        function clearBranchMultiSelect(e) {
                            e.preventDefault();
                            branchMultiSelected = ['all'];
                            renderBranchMultiSelect(true);
                            filterBranchMultiOptions();
                        }
                        function autoSubmitBranchMultiForm() {
                            document.getElementById('branch-multiselect-form').submit();
                        }
                        function updateClearBtn() {
                            const clearBtn = document.getElementById('branch-multiselect-clear');
                            if (!clearBtn) return;
                            if (branchMultiSelected.length && !(branchMultiSelected.length === 1 && branchMultiSelected[0] === 'all')) {
                                clearBtn.style.display = '';
                            } else {
                                clearBtn.style.display = 'none';
                            }
                        }
                        document.addEventListener('DOMContentLoaded', function() {
                            renderBranchMultiSelect();
                            const search = document.getElementById('branch-multiselect-search');
                            const container = document.getElementById('branch-multiselect-container');
                            search.addEventListener('focus', showBranchMultiDropdown);
                            search.addEventListener('blur', hideBranchMultiDropdown);
                            container.addEventListener('click', function(e) {
                                if (e.target.classList.contains('multiselect-clear-btn')) return;
                                search.focus();
                                showBranchMultiDropdown();
                            });
                            updateClearBtn();
                        });
                        </script>
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
                                                <select name="role" class="mini-select" title="Change user role">
                                                    <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                    <?php if ($isSuperAdmin): ?>
                                                        <option value="super_admin" <?php echo $user['role'] === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                                                    <?php endif; ?>
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
                                                <select name="branch_id" class="mini-select" title="Change branch assignment">
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
                                        <!-- Edit removed: role changes are handled inline and profile edits limited to the user details page -->
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
                            <th style="width:56px;text-align:center">U</th>
                            <th>User</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Role</th>
                            <th>Location</th>
                            <th>Pay</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allShifts)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 30px;">
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
                                        <td colspan="8" class="day-header">
                                            <i class="fas fa-calendar-day"></i>
                                            <?php echo date("l, F j, Y", strtotime($currentDate)); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <?php
                                    // compute compact username (initials)
                                    $compactUsername = '';
                                    $rawName = trim($shift['username'] ?? '');
                                    if ($rawName !== '') {
                                        $parts = preg_split('/\s+/', $rawName);
                                        if (count($parts) >= 2) {
                                            $compactUsername = strtoupper(substr($parts[0],0,1) . substr($parts[1],0,1));
                                        } else {
                                            $compactUsername = strtoupper(substr($rawName,0,2));
                                        }
                                    }
                                    ?>
                                    <td class="compact-col" data-label="U" style="text-align:center;font-weight:600"><?php echo htmlspecialchars($compactUsername); ?></td>
                                    <td data-label="User"><?php echo htmlspecialchars($shift['username']); ?></td>
                                    <td data-label="Date"><?php echo date("D, M j", strtotime($shift['shift_date'])); ?></td>
                                    <td data-label="Time">
                                        <?php echo date("g:i A", strtotime($shift['start_time'])); ?> -
                                        <?php echo date("g:i A", strtotime($shift['end_time'])); ?>
                                    </td>
                                    <td data-label="Role"><?php echo htmlspecialchars($shift['role_name']); ?></td>
                                    <td data-label="Location">
                                        <?php
                                        $loc = $shift['location'] ?? '';
                                        if ($loc === 'Cross-branch coverage' && !empty($shift['branch_name'])) {
                                            echo htmlspecialchars($loc) . ' (' . htmlspecialchars($shift['branch_name']) . ')';
                                        } else {
                                            echo htmlspecialchars($loc);
                                        }
                                        ?>
                                    </td>
                                    <td data-label="Pay">
                                        <strong>£<?php echo number_format(calculatePay($conn, $shift['id']), 2); ?></strong>
                                    </td>
                                    <td class="actions" data-label="Actions">
                                        <a href="edit_shift.php?id=<?php echo $shift['id']; ?>&return=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="admin-btn">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="admin-btn secondary"
                                            onclick="confirmDeleteShift(<?php echo $shift['id']; ?>, '<?php echo addslashes(htmlspecialchars($shift['username'])); ?>', '<?php echo date('M j, Y', strtotime($shift['shift_date'])); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
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
                window.location.href = `../functions/delete_shift.php?id=${shiftId}&return=../admin/admin_dashboard.php`;
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

    // Forms submit manually via the Update button now; no auto-submit on select change.

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
    <!-- No AJAX role-change handling: selects do not auto-submit; use the Update button to submit changes. -->
</body>

</html>

</html>