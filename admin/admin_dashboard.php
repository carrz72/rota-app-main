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

// ========== SHIFTS MANAGEMENT ENHANCEMENTS ==========

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

// Helper to build navigation URLs while preserving existing GET filters
function nav_url_admin($overrides = []) {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        $q[$k] = $v;
    }
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) unset($q[$k]);
    }
    $path = $_SERVER['PHP_SELF'] ?? ($_SERVER['SCRIPT_NAME'] ?? 'admin_dashboard.php');
    return $path . '?' . http_build_query($q);
}

// Shift search and filter parameters
$shiftSearchQuery = $_GET['shift_search'] ?? '';
$shiftUserFilter = $_GET['shift_user'] ?? 'all';
$shiftRoleFilter = $_GET['shift_role'] ?? 'all';
$shiftLocationFilter = $_GET['shift_location'] ?? 'all';
$shiftPage = isset($_GET['shift_page']) ? max(1, intval($_GET['shift_page'])) : 1;
$shiftPerPage = 50; // Fixed at 50 for performance

// Build shift query with filters
$shiftWhereConditions = [];
$shiftParams = [];

// Date range filter
if ($viewType === 'week') {
    $shiftWhereConditions[] = "s.shift_date BETWEEN ? AND DATE_ADD(?, INTERVAL 6 DAY)";
    $shiftParams[] = $currentWeekStart;
    $shiftParams[] = $currentWeekStart;
} else {
    $shiftWhereConditions[] = "s.shift_date = ?";
    $shiftParams[] = $currentDay;
}

// Branch filter
if ($adminBranchId) {
    $shiftWhereConditions[] = "(u.branch_id = ? OR s.branch_id = ?)";
    $shiftParams[] = $adminBranchId;
    $shiftParams[] = $adminBranchId;
}

// Search filter
if (!empty($shiftSearchQuery)) {
    $shiftWhereConditions[] = "(u.username LIKE ? OR r.name LIKE ? OR s.location LIKE ?)";
    $searchParam = '%' . $shiftSearchQuery . '%';
    $shiftParams[] = $searchParam;
    $shiftParams[] = $searchParam;
    $shiftParams[] = $searchParam;
}

// User filter
if ($shiftUserFilter !== 'all') {
    $shiftWhereConditions[] = "s.user_id = ?";
    $shiftParams[] = $shiftUserFilter;
}

// Role filter
if ($shiftRoleFilter !== 'all') {
    $shiftWhereConditions[] = "s.role_id = ?";
    $shiftParams[] = $shiftRoleFilter;
}

// Location filter
if ($shiftLocationFilter !== 'all') {
    if ($shiftLocationFilter === 'cross_branch') {
        $shiftWhereConditions[] = "s.location = 'Cross-branch coverage'";
    } else {
        $shiftWhereConditions[] = "s.location = ?";
        $shiftParams[] = $shiftLocationFilter;
    }
}

$shiftWhereClause = 'WHERE ' . implode(' AND ', $shiftWhereConditions);

// Get total count for pagination
$countQuery = "
    SELECT COUNT(*) 
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    JOIN roles r ON s.role_id = r.id
    LEFT JOIN branches b ON s.branch_id = b.id
    $shiftWhereClause
";
$countStmt = $conn->prepare($countQuery);
$countStmt->execute($shiftParams);
$totalShifts = $countStmt->fetchColumn();
$shiftTotalPages = ceil($totalShifts / $shiftPerPage);
$shiftPage = min($shiftPage, max(1, $shiftTotalPages));
$shiftOffset = ($shiftPage - 1) * $shiftPerPage;

// Fetch shifts with pagination
$shiftQuery = "
    SELECT s.*, u.username, r.name as role_name, b.name as branch_name,
           CASE 
               WHEN s.end_time < s.start_time THEN (TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) + 1440) / 60
               ELSE TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) / 60
           END as duration_hours
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    JOIN roles r ON s.role_id = r.id
    LEFT JOIN branches b ON s.branch_id = b.id
    $shiftWhereClause
    ORDER BY s.shift_date, s.start_time
    LIMIT $shiftPerPage OFFSET $shiftOffset
";
$stmt = $conn->prepare($shiftQuery);
$stmt->execute($shiftParams);
$allShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate shift statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_shifts,
        SUM(CASE 
            WHEN s.end_time < s.start_time THEN (TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) + 1440) / 60
            ELSE TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) / 60
        END) as total_hours,
        COUNT(DISTINCT s.user_id) as unique_users
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    JOIN roles r ON s.role_id = r.id
    LEFT JOIN branches b ON s.branch_id = b.id
    $shiftWhereClause
";
$statsStmt = $conn->prepare($statsQuery);
$statsStmt->execute($shiftParams);
$shiftStats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Calculate total pay for all filtered shifts
$totalPay = 0;
foreach ($allShifts as $shift) {
    try {
        $totalPay += calculatePay($conn, $shift['id']);
    } catch (Exception $e) {
        // Skip if pay calculation fails
    }
}

// Get unique users for filter dropdown
$usersForFilterQuery = "SELECT DISTINCT u.id, u.username FROM users u JOIN shifts s ON u.id = s.user_id";
if ($adminBranchId) {
    $usersForFilterQuery .= " WHERE u.branch_id = ? OR s.branch_id = ?";
}
$usersForFilterQuery .= " ORDER BY u.username";
$usersForFilterStmt = $conn->prepare($usersForFilterQuery);
if ($adminBranchId) {
    $usersForFilterStmt->execute([$adminBranchId, $adminBranchId]);
} else {
    $usersForFilterStmt->execute();
}
$usersForFilter = $usersForFilterStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique roles for filter dropdown
$rolesForFilter = $conn->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get unique locations for filter dropdown
$locationsQuery = "SELECT DISTINCT location FROM shifts WHERE location IS NOT NULL AND location != '' ORDER BY location";
$locationsForFilter = $conn->query($locationsQuery)->fetchAll(PDO::FETCH_ASSOC);

// ========== ENHANCED ANALYTICS QUERIES ==========

// 1. Pending Coverage Requests
$coverageQuery = "SELECT COUNT(*) FROM shift_coverage_requests WHERE status = 'pending'";
$coverageParams = [];
if (!$isSuperAdmin && $adminBranchId) {
    $coverageQuery .= " AND branch_id = ?";
    $coverageParams[] = $adminBranchId;
}
try {
    $stmt = $conn->prepare($coverageQuery);
    $stmt->execute($coverageParams);
    $pendingCoverageRequests = $stmt->fetchColumn();
} catch (PDOException $e) {
    $pendingCoverageRequests = 0;
}

// 2. This Month's Payroll Estimate
$payrollQuery = "
    SELECT SUM(
        CASE 
            WHEN s.end_time < s.start_time THEN (TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) + 1440) / 60 * r.base_pay
            ELSE TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) / 60 * r.base_pay
        END
    ) as estimated_payroll
    FROM shifts s
    JOIN roles r ON s.role_id = r.id
    JOIN users u ON s.user_id = u.id
    WHERE MONTH(s.shift_date) = MONTH(CURDATE()) 
    AND YEAR(s.shift_date) = YEAR(CURDATE())";
$payrollParams = [];
if (!$isSuperAdmin && $adminBranchId) {
    $payrollQuery .= " AND (u.branch_id = ? OR s.branch_id = ?)";
    $payrollParams[] = $adminBranchId;
    $payrollParams[] = $adminBranchId;
}
$stmt = $conn->prepare($payrollQuery);
$stmt->execute($payrollParams);
$monthlyPayrollEstimate = $stmt->fetchColumn() ?: 0;

// 3. Staff Utilization (This Week)
$utilizationQuery = "
    SELECT COUNT(DISTINCT s.user_id) as active_users
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    WHERE YEARWEEK(s.shift_date, 1) = YEARWEEK(CURDATE(), 1)";
$utilizationParams = [];
if (!$isSuperAdmin && $adminBranchId) {
    $utilizationQuery .= " AND (u.branch_id = ? OR s.branch_id = ?)";
    $utilizationParams[] = $adminBranchId;
    $utilizationParams[] = $adminBranchId;
}
$stmt = $conn->prepare($utilizationQuery);
$stmt->execute($utilizationParams);
$activeUsersThisWeek = $stmt->fetchColumn();
$utilizationRate = $totalUsers > 0 ? round(($activeUsersThisWeek / $totalUsers) * 100, 1) : 0;

// 4. Average Hours Per Employee (This Month)
$avgHoursQuery = "
    SELECT AVG(total_hours) as avg_hours
    FROM (
        SELECT u.id, SUM(CASE 
            WHEN s.end_time < s.start_time THEN (TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) + 1440) / 60
            ELSE TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) / 60
        END) as total_hours
        FROM users u
        LEFT JOIN shifts s ON u.id = s.user_id 
            AND MONTH(s.shift_date) = MONTH(CURDATE()) 
            AND YEAR(s.shift_date) = YEAR(CURDATE())
        WHERE u.role != 'admin' AND u.role != 'super_admin'";
if (!$isSuperAdmin && $adminBranchId) {
    $avgHoursQuery .= " AND u.branch_id = ?";
}
$avgHoursQuery .= "
        GROUP BY u.id
    ) as user_hours";
$avgHoursParams = [];
if (!$isSuperAdmin && $adminBranchId) {
    $avgHoursParams[] = $adminBranchId;
}
$stmt = $conn->prepare($avgHoursQuery);
$stmt->execute($avgHoursParams);
$avgHoursPerEmployee = $stmt->fetchColumn() ?: 0;

// 5. Shift Distribution by Role (for chart)
$roleDistQuery = "
    SELECT r.name as role_name, COUNT(s.id) as shift_count
    FROM shifts s
    JOIN roles r ON s.role_id = r.id
    JOIN users u ON s.user_id = u.id
    WHERE MONTH(s.shift_date) = MONTH(CURDATE()) 
    AND YEAR(s.shift_date) = YEAR(CURDATE())";
$roleDistParams = [];
if (!$isSuperAdmin && $adminBranchId) {
    $roleDistQuery .= " AND (u.branch_id = ? OR s.branch_id = ?)";
    $roleDistParams[] = $adminBranchId;
    $roleDistParams[] = $adminBranchId;
}
$roleDistQuery .= " GROUP BY r.name ORDER BY shift_count DESC LIMIT 10";
$stmt = $conn->prepare($roleDistQuery);
$stmt->execute($roleDistParams);
$shiftDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. Weekly Trend Data (last 4 weeks)
$trendData = [];
for ($i = 3; $i >= 0; $i--) {
    $weekStart = date('Y-m-d', strtotime("-$i weeks monday this week"));
    $weekEnd = date('Y-m-d', strtotime("-$i weeks sunday this week"));
    
    $trendQuery = "
        SELECT 
            COUNT(s.id) as shift_count,
            SUM(CASE 
                WHEN s.end_time < s.start_time THEN (TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) + 1440) / 60
                ELSE TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) / 60
            END) as total_hours
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        WHERE s.shift_date BETWEEN ? AND ?";
    $trendParams = [$weekStart, $weekEnd];
    if (!$isSuperAdmin && $adminBranchId) {
        $trendQuery .= " AND (u.branch_id = ? OR s.branch_id = ?)";
        $trendParams[] = $adminBranchId;
        $trendParams[] = $adminBranchId;
    }
    
    $stmt = $conn->prepare($trendQuery);
    $stmt->execute($trendParams);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $trendData[] = [
        'week' => date('M j', strtotime($weekStart)),
        'shifts' => $result['shift_count'] ?: 0,
        'hours' => round($result['total_hours'] ?: 0, 1)
    ];
}

// 7. Recent Admin Actions (if audit log exists)
$recentActions = [];
try {
    $actionsQuery = "
        SELECT action, details, created_at, admin_username
        FROM audit_admin_actions
        ORDER BY created_at DESC
        LIMIT 5";
    $stmt = $conn->prepare($actionsQuery);
    $stmt->execute();
    $recentActions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // audit table might not exist
    $recentActions = [];
}

// 8. Unfilled Shifts / Invitations Pending Response
$pendingInvitationsQuery = "SELECT COUNT(*) FROM shift_invitations WHERE status = 'pending'";
$pendingInvitationsParams = [];
if (!$isSuperAdmin && $adminBranchId) {
    $pendingInvitationsQuery .= " AND branch_id = ?";
    $pendingInvitationsParams[] = $adminBranchId;
}
try {
    $stmt = $conn->prepare($pendingInvitationsQuery);
    $stmt->execute($pendingInvitationsParams);
    $pendingInvitations = $stmt->fetchColumn();
} catch (PDOException $e) {
    $pendingInvitations = 0;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <?php require_once __DIR__ . '/../includes/seo.php'; seo_render_head(['title' => seo_full_title('Admin Dashboard - Open Rota'), 'description' => 'Administration area for Open Rota.']); ?>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
        <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

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

        <!-- Quick Actions & Alerts Panel -->
        <div class="quick-actions-grid">
            <div class="quick-action-card alert <?php echo $pendingCoverageRequests > 0 ? 'has-alerts' : ''; ?>">
                <div class="quick-action-header">
                    <div class="quick-action-icon coverage">
                        <i class="fas fa-people-arrows"></i>
                    </div>
                    <div class="quick-action-content">
                        <div class="quick-action-value"><?php echo $pendingCoverageRequests; ?></div>
                        <div class="quick-action-label">Coverage Requests</div>
                    </div>
                </div>
                <?php if ($pendingCoverageRequests > 0): ?>
                    <a href="cross_branch_requests.php" class="quick-action-link">
                        <i class="fas fa-arrow-right"></i> Review Requests
                    </a>
                <?php endif; ?>
            </div>

            <div class="quick-action-card <?php echo $pendingInvitations > 0 ? 'has-alerts' : ''; ?>">
                <div class="quick-action-header">
                    <div class="quick-action-icon invitations">
                        <i class="fas fa-envelope-open-text"></i>
                    </div>
                    <div class="quick-action-content">
                        <div class="quick-action-value"><?php echo $pendingInvitations; ?></div>
                        <div class="quick-action-label">Pending Invitations</div>
                    </div>
                </div>
                <?php if ($pendingInvitations > 0): ?>
                    <a href="track_invitations.php" class="quick-action-link">
                        <i class="fas fa-arrow-right"></i> Track Status
                    </a>
                <?php endif; ?>
            </div>

            <div class="quick-action-card">
                <div class="quick-action-header">
                    <div class="quick-action-icon payroll">
                        <i class="fas fa-pound-sign"></i>
                    </div>
                    <div class="quick-action-content">
                        <div class="quick-action-value">£<?php echo number_format($monthlyPayrollEstimate, 0); ?></div>
                        <div class="quick-action-label">Est. Monthly Payroll</div>
                    </div>
                </div>
                <a href="payroll_management.php" class="quick-action-link">
                    <i class="fas fa-arrow-right"></i> View Details
                </a>
            </div>

            <div class="quick-action-card">
                <div class="quick-action-header">
                    <div class="quick-action-icon utilization">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="quick-action-content">
                        <div class="quick-action-value"><?php echo $utilizationRate; ?>%</div>
                        <div class="quick-action-label">Staff Utilization</div>
                        <div class="quick-action-subtext"><?php echo $activeUsersThisWeek; ?>/<?php echo $totalUsers; ?> active this week</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Analytics Dashboard -->
        <div class="analytics-panel">
            <div class="analytics-header">
                <h2><i class="fas fa-chart-bar"></i> Performance Analytics</h2>
                <div class="analytics-period">
                    <i class="fas fa-calendar"></i> October <?php echo date('Y'); ?>
                </div>
            </div>
            
            <div class="analytics-grid">
                <!-- Weekly Trends Chart -->
                <div class="analytics-card wide">
                    <div class="analytics-card-header">
                        <h3><i class="fas fa-chart-area"></i> Weekly Trends</h3>
                        <span class="analytics-subtitle">Last 4 weeks</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="weeklyTrendsChart"></canvas>
                    </div>
                </div>

                <!-- Shift Distribution by Role -->
                <div class="analytics-card">
                    <div class="analytics-card-header">
                        <h3><i class="fas fa-chart-pie"></i> Shifts by Role</h3>
                        <span class="analytics-subtitle">This month</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="roleDistributionChart"></canvas>
                    </div>
                </div>

                <!-- Performance Metrics -->
                <div class="analytics-card metrics">
                    <div class="analytics-card-header">
                        <h3><i class="fas fa-bullseye"></i> Key Metrics</h3>
                        <span class="analytics-subtitle">This month</span>
                    </div>
                    <div class="metrics-list">
                        <div class="metric-item">
                            <div class="metric-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value"><?php echo number_format($avgHoursPerEmployee, 1); ?></div>
                                <div class="metric-label">Avg Hours/Employee</div>
                            </div>
                        </div>
                        <div class="metric-item">
                            <div class="metric-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value"><?php echo count($allShifts); ?></div>
                                <div class="metric-label">Shifts <?php echo $viewType === 'week' ? 'This Week' : 'Today'; ?></div>
                            </div>
                        </div>
                        <div class="metric-item">
                            <div class="metric-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value"><?php echo $activeUsersThisWeek; ?></div>
                                <div class="metric-label">Active Staff</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Admin Actions -->
                <?php if (!empty($recentActions)): ?>
                <div class="analytics-card activity">
                    <div class="analytics-card-header">
                        <h3><i class="fas fa-history"></i> Recent Activity</h3>
                        <a href="audit_log.php" class="view-all-link">View All</a>
                    </div>
                    <div class="activity-list">
                        <?php foreach ($recentActions as $action): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-circle"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <strong><?php echo htmlspecialchars($action['admin_username']); ?></strong>
                                        <?php echo htmlspecialchars($action['action']); ?>
                                    </div>
                                    <div class="activity-time">
                                        <i class="fas fa-clock"></i>
                                        <?php 
                                            $time = strtotime($action['created_at']);
                                            $diff = time() - $time;
                                            if ($diff < 3600) {
                                                echo floor($diff / 60) . ' min ago';
                                            } elseif ($diff < 86400) {
                                                echo floor($diff / 3600) . ' hours ago';
                                            } else {
                                                echo date('M j, g:i A', $time);
                                            }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
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

        <!-- Shift Statistics -->
        <div class="shift-stats-grid">
            <div class="shift-stat-card total">
                <div class="shift-stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="shift-stat-content">
                    <div class="shift-stat-value"><?php echo $shiftStats['total_shifts'] ?? 0; ?></div>
                    <div class="shift-stat-label">Total Shifts</div>
                    <div class="shift-stat-trend">
                        <small><?php echo $viewType === 'week' ? 'This week' : 'Today'; ?></small>
                    </div>
                </div>
            </div>
            <div class="shift-stat-card hours">
                <div class="shift-stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="shift-stat-content">
                    <div class="shift-stat-value"><?php echo number_format($shiftStats['total_hours'] ?? 0, 1); ?></div>
                    <div class="shift-stat-label">Total Hours</div>
                    <div class="shift-stat-trend">
                        <small>Avg: <?php echo $shiftStats['total_shifts'] > 0 ? number_format(($shiftStats['total_hours'] ?? 0) / $shiftStats['total_shifts'], 1) : 0; ?>h per shift</small>
                    </div>
                </div>
            </div>
            <div class="shift-stat-card pay">
                <div class="shift-stat-icon">
                    <i class="fas fa-pound-sign"></i>
                </div>
                <div class="shift-stat-content">
                    <div class="shift-stat-value">£<?php echo number_format($totalPay, 0); ?></div>
                    <div class="shift-stat-label">Total Pay</div>
                    <div class="shift-stat-trend">
                        <small>Avg: £<?php echo $shiftStats['total_shifts'] > 0 ? number_format($totalPay / $shiftStats['total_shifts'], 2) : 0; ?> per shift</small>
                    </div>
                </div>
            </div>
            <div class="shift-stat-card staff">
                <div class="shift-stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="shift-stat-content">
                    <div class="shift-stat-value"><?php echo $shiftStats['unique_users'] ?? 0; ?></div>
                    <div class="shift-stat-label">Staff Working</div>
                    <div class="shift-stat-trend">
                        <small><?php echo $shiftStats['total_shifts'] > 0 && $shiftStats['unique_users'] > 0 ? number_format($shiftStats['total_shifts'] / $shiftStats['unique_users'], 1) : 0; ?> shifts per person</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-calendar-week"></i> Shift Management</h2>
                <div class="panel-actions">
                    <div class="view-toggle">
                        <a href="<?php echo htmlspecialchars(nav_url_admin(['view' => 'week', 'week_start' => $currentWeekStart])); ?>"
                            class="admin-btn <?php echo $viewType === 'week' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-week"></i> Week
                        </a>
                        <a href="<?php echo htmlspecialchars(nav_url_admin(['view' => 'day', 'day' => $currentDay])); ?>"
                            class="admin-btn <?php echo $viewType === 'day' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-day"></i> Day
                        </a>
                    </div>
                    <button onclick="exportShifts()" class="admin-btn secondary" title="Export shifts to CSV">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
            </div>
            
            <!-- Shift Search and Filter Bar -->
            <div class="search-filter-bar">
                <form method="GET" id="shift-filter-form" class="filter-form">
                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewType); ?>">
                    <?php if ($viewType === 'week'): ?>
                        <input type="hidden" name="week_start" value="<?php echo htmlspecialchars($currentWeekStart); ?>">
                    <?php else: ?>
                        <input type="hidden" name="day" value="<?php echo htmlspecialchars($currentDay); ?>">
                    <?php endif; ?>
                    
                    <!-- Search Input -->
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               name="shift_search" 
                               value="<?php echo htmlspecialchars($shiftSearchQuery); ?>" 
                               placeholder="Search shifts by user, role, location..."
                               class="search-input">
                        <?php if (!empty($shiftSearchQuery)): ?>
                            <button type="button" class="clear-search" onclick="clearShiftSearch()">
                                <i class="fas fa-times"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- User Filter -->
                    <div class="filter-select-group">
                        <label for="shift_user"><i class="fas fa-user"></i></label>
                        <select name="shift_user" id="shift_user" class="filter-select">
                            <option value="all" <?php echo $shiftUserFilter === 'all' ? 'selected' : ''; ?>>All Users</option>
                            <?php foreach ($usersForFilter as $userOpt): ?>
                                <option value="<?php echo $userOpt['id']; ?>" <?php echo $shiftUserFilter == $userOpt['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($userOpt['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Role Filter -->
                    <div class="filter-select-group">
                        <label for="shift_role"><i class="fas fa-user-tag"></i></label>
                        <select name="shift_role" id="shift_role" class="filter-select">
                            <option value="all" <?php echo $shiftRoleFilter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                            <?php foreach ($rolesForFilter as $roleOpt): ?>
                                <option value="<?php echo $roleOpt['id']; ?>" <?php echo $shiftRoleFilter == $roleOpt['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($roleOpt['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Location Filter -->
                    <div class="filter-select-group">
                        <label for="shift_location"><i class="fas fa-map-marker-alt"></i></label>
                        <select name="shift_location" id="shift_location" class="filter-select">
                            <option value="all" <?php echo $shiftLocationFilter === 'all' ? 'selected' : ''; ?>>All Locations</option>
                            <option value="cross_branch" <?php echo $shiftLocationFilter === 'cross_branch' ? 'selected' : ''; ?>>Cross-branch</option>
                            <?php foreach ($locationsForFilter as $locOpt): ?>
                                <?php if ($locOpt['location'] !== 'Cross-branch coverage'): ?>
                                    <option value="<?php echo htmlspecialchars($locOpt['location']); ?>" 
                                            <?php echo $shiftLocationFilter == $locOpt['location'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($locOpt['location']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Filter Button -->
                    <button type="submit" class="admin-btn">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    
                    <?php if (!empty($shiftSearchQuery) || $shiftUserFilter !== 'all' || $shiftRoleFilter !== 'all' || $shiftLocationFilter !== 'all'): ?>
                        <a href="?view=<?php echo $viewType; ?>&<?php echo $viewType === 'week' ? 'week_start=' . $currentWeekStart : 'day=' . $currentDay; ?>#shift-management" 
                           class="admin-btn secondary" title="Clear all filters">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Results Summary -->
            <div class="results-summary">
                <div class="results-text">
                    <i class="fas fa-info-circle"></i>
                    Showing <?php echo min($shiftOffset + 1, $totalShifts); ?> - <?php echo min($shiftOffset + $shiftPerPage, $totalShifts); ?> 
                    of <?php echo $totalShifts; ?> shift<?php echo $totalShifts != 1 ? 's' : ''; ?>
                    <?php if (!empty($shiftSearchQuery)): ?>
                        <span class="filter-tag">Search: "<?php echo htmlspecialchars($shiftSearchQuery); ?>"</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="admin-panel-body" id="shift-management">
                <div class="view-controls">
                    <div class="period-nav-buttons">
                        <?php if ($viewType === 'week'): ?>
                            <a href="<?php echo htmlspecialchars(nav_url_admin(['view' => 'week', 'week_start' => $prevWeekStart])); ?>" class="btn">
                                <i class="fas fa-chevron-left"></i> Previous Week
                            </a>
                            <a href="<?php echo htmlspecialchars(nav_url_admin(['view' => 'week', 'week_start' => date('Y-m-d', strtotime('monday this week'))])); ?>"
                                class="btn current">
                                Current Week
                            </a>
                            <a href="<?php echo htmlspecialchars(nav_url_admin(['view' => 'week', 'week_start' => $nextWeekStart])); ?>" class="btn">
                                Next Week <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars(nav_url_admin(['view' => 'day', 'day' => $prevDay])); ?>" class="btn">
                                <i class="fas fa-chevron-left"></i> Previous Day
                            </a>
                            <a href="<?php echo htmlspecialchars(nav_url_admin(['view' => 'day', 'day' => date('Y-m-d')])); ?>" class="btn current">
                                Today
                            </a>
                            <a href="<?php echo htmlspecialchars(nav_url_admin(['view' => 'day', 'day' => $nextDay])); ?>" class="btn">
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
                            <th>Duration</th>
                            <th>Role</th>
                            <th>Location</th>
                            <th>Pay</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allShifts)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 30px;">
                                    <i class="fas fa-calendar-times"
                                        style="font-size: 2rem; color: #ddd; margin-bottom: 10px; display: block;"></i>
                                    <p style="margin: 0;">No shifts found<?php echo !empty($shiftSearchQuery) || $shiftUserFilter !== 'all' || $shiftRoleFilter !== 'all' || $shiftLocationFilter !== 'all' ? ' matching your filters' : ' for this period'; ?></p>
                                    <?php if (!empty($shiftSearchQuery) || $shiftUserFilter !== 'all' || $shiftRoleFilter !== 'all' || $shiftLocationFilter !== 'all'): ?>
                                        <a href="?view=<?php echo $viewType; ?>&<?php echo $viewType === 'week' ? 'week_start=' . $currentWeekStart : 'day=' . $currentDay; ?>#shift-management" 
                                           class="admin-btn" style="margin-top: 15px;">Clear Filters</a>
                                    <?php endif; ?>
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
                                        <td colspan="9" class="day-header">
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
                                    <td data-label="Duration">
                                        <span class="duration-badge">
                                            <i class="fas fa-clock"></i>
                                            <?php echo number_format($shift['duration_hours'], 1); ?>h
                                        </span>
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
                
                <!-- Shift Pagination -->
                <?php if ($shiftTotalPages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Page <?php echo $shiftPage; ?> of <?php echo $shiftTotalPages; ?>
                        </div>
                        <div class="pagination-buttons">
                            <?php if ($shiftPage > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['shift_page' => 1])); ?>#shift-management" 
                                   class="pagination-btn" title="First page">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['shift_page' => $shiftPage - 1])); ?>#shift-management" 
                                   class="pagination-btn" title="Previous page">
                                    <i class="fas fa-angle-left"></i> Prev
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            $startPage = max(1, $shiftPage - 2);
                            $endPage = min($shiftTotalPages, $shiftPage + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['shift_page' => $i])); ?>#shift-management" 
                                   class="pagination-btn <?php echo $i === $shiftPage ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($shiftPage < $shiftTotalPages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['shift_page' => $shiftPage + 1])); ?>#shift-management" 
                                   class="pagination-btn" title="Next page">
                                    Next <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['shift_page' => $shiftTotalPages])); ?>#shift-management" 
                                   class="pagination-btn" title="Last page">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
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
            const userTables = document.querySelectorAll('.admin-table');
            const table = userTables[0]; // First table is users
            if (!table) return;
            
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
        
        function exportShifts() {
            // Export shifts table
            const shiftTables = document.querySelectorAll('.admin-table');
            const table = shiftTables[1] || shiftTables[0]; // Second table is shifts
            if (!table) return;
            
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                // Skip day header rows
                if (rows[i].querySelector('.day-header')) continue;
                
                const row = [], cols = rows[i].querySelectorAll('td, th');
                for (let j = 0; j < cols.length - 1; j++) { // Exclude actions column
                    if (cols[j]) {
                        let text = cols[j].innerText || cols[j].textContent || '';
                        text = text.replace(/\s+/g, ' ').trim();
                        text = text.replace(/"/g, '""');
                        if (text.includes(',') || text.includes('"') || text.includes('\n')) {
                            text = `"${text}"`;
                        }
                        row.push(text);
                    }
                }
                if (row.length > 0) {
                    csv.push(row.join(','));
                }
            }
            
            const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
            const downloadLink = document.createElement('a');
            downloadLink.download = `shifts_export_${new Date().toISOString().split('T')[0]}.csv`;
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = 'none';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }
        
        function clearShiftSearch() {
            const searchInput = document.querySelector('input[name="shift_search"]');
            if (searchInput) {
                searchInput.value = '';
                document.getElementById('shift-filter-form').submit();
            }
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

    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Weekly Trends Chart
            const weeklyCtx = document.getElementById('weeklyTrendsChart');
            if (weeklyCtx) {
                const trendData = <?php echo json_encode($trendData); ?>;
                
                new Chart(weeklyCtx, {
                    type: 'line',
                    data: {
                        labels: trendData.map(d => d.week),
                        datasets: [{
                            label: 'Total Shifts',
                            data: trendData.map(d => d.shifts),
                            borderColor: '#fd2b2b',
                            backgroundColor: 'rgba(253, 43, 43, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        }, {
                            label: 'Total Hours',
                            data: trendData.map(d => d.hours),
                            borderColor: '#198754',
                            backgroundColor: 'rgba(25, 135, 84, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    font: {
                                        family: 'newFont',
                                        size: 12
                                    },
                                    usePointStyle: true,
                                    padding: 15
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14
                                },
                                bodyFont: {
                                    size: 13
                                },
                                cornerRadius: 8
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                },
                                ticks: {
                                    font: {
                                        family: 'newFont'
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    font: {
                                        family: 'newFont'
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Role Distribution Chart
            const roleCtx = document.getElementById('roleDistributionChart');
            if (roleCtx) {
                const roleData = <?php echo json_encode($shiftDistribution); ?>;
                
                const colors = [
                    '#fd2b2b', '#198754', '#0d6efd', '#ffc107', '#6f42c1',
                    '#d63384', '#fd7e14', '#20c997', '#0dcaf0', '#adb5bd'
                ];
                
                new Chart(roleCtx, {
                    type: 'doughnut',
                    data: {
                        labels: roleData.map(d => d.role_name),
                        datasets: [{
                            data: roleData.map(d => d.shift_count),
                            backgroundColor: colors.slice(0, roleData.length),
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'bottom',
                                labels: {
                                    font: {
                                        family: 'newFont',
                                        size: 11
                                    },
                                    padding: 10,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14
                                },
                                bodyFont: {
                                    size: 13
                                },
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((value / total) * 100).toFixed(1);
                                        return label + ': ' + value + ' shifts (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Add smooth scroll animation to cards
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.analytics-card, .quick-action-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(card);
            });
        });
    </script>

    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
    <!-- No AJAX role-change handling: selects do not auto-submit; use the Update button to submit changes. -->
</body>

</html>