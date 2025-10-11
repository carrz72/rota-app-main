<?php
require '../includes/auth.php';
requireAdmin();
require_once '../includes/db.php';
// Include notification functionality
if (!function_exists('addNotification')) {
    require_once '../functions/addNotification.php';
}

// Determine current admin branch for filtering
$currentAdminId = $_SESSION['user_id'] ?? null;
$adminBranchId = null;
if ($currentAdminId) {
    $bstmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
    $bstmt->execute([$currentAdminId]);
    $adminBranchId = $bstmt->fetchColumn();
}

// Check if we're managing shifts for a specific user
$user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
$username = null;

// If a specific user is provided, get their username
if ($user_id) {
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $username = $user ? $user['username'] : null;
}

// Determine period (week, month, range)
$period = $_GET['period'] ?? 'week';
$currentWeekStart = isset($_GET['week_start']) ? $_GET['week_start'] : date('Y-m-d', strtotime('monday this week'));
$currentMonth = isset($_GET['month']) ? $_GET['month'] : date('n');
$currentYear = isset($_GET['year']) ? $_GET['year'] : date('Y');
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d', strtotime('+7 days'));

// Build SQL condition based on period
if ($period == 'week') {
    $periodSql = "shift_date BETWEEN '$currentWeekStart' AND DATE_ADD('$currentWeekStart', INTERVAL 6 DAY)";
    $periodDesc = "Week of " . date("M j, Y", strtotime($currentWeekStart));
} elseif ($period == 'month') {
    $periodSql = "MONTH(shift_date) = $currentMonth AND YEAR(shift_date) = $currentYear";
    $periodDesc = date("F Y", strtotime("$currentYear-$currentMonth-01"));
} elseif ($period == 'range') {
    $periodSql = "shift_date BETWEEN '$startDate' AND '$endDate'";
    $periodDesc = date("M j, Y", strtotime($startDate)) . " to " . date("M j, Y", strtotime($endDate));
} else {
    $periodSql = "shift_date BETWEEN '$currentWeekStart' AND DATE_ADD('$currentWeekStart', INTERVAL 6 DAY)";
    $periodDesc = "Week of " . date("M j, Y", strtotime($currentWeekStart));
}

// Build the user filter SQL
$userSql = $user_id ? "AND s.user_id = $user_id" : "";

// Get all shifts with user and role info
$shiftsQuery = "
    SELECT s.*, u.username, r.name as role_name, b.id AS branch_id, b.name AS branch_name
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    JOIN roles r ON s.role_id = r.id
    LEFT JOIN branches b ON s.branch_id = b.id
    WHERE $periodSql";

// If filtering to a specific user, add that clause
if ($userSql) {
    $shiftsQuery .= " $userSql";
} elseif ($adminBranchId) {
    // Include shifts where either the user's home branch matches admin branch or the shift is scheduled at admin branch
    $shiftsQuery .= " AND (u.branch_id = " . (int)$adminBranchId . " OR s.branch_id = " . (int)$adminBranchId . ")";
}

$shiftsQuery .= " ORDER BY s.shift_date ASC, s.start_time ASC";

$shifts = $conn->query($shiftsQuery)->fetchAll(PDO::FETCH_ASSOC);

// If form submitted for shift deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_shift'])) {
    $shift_id = (int) $_POST['shift_id'];

    try {
        $stmt = $conn->prepare("DELETE FROM shifts WHERE id = ?");
        $stmt->execute([$shift_id]);
        
        if ($stmt->rowCount() > 0) {
            $successMessage = "Shift deleted successfully";
            
            // Add notification
            addNotification($conn, $_SESSION['user_id'], "Shift deleted successfully from manage shifts", "success");
            
            // Audit logging
            try {
                require_once __DIR__ . '/../includes/audit_log.php';
                log_audit($conn, $_SESSION['user_id'] ?? null, 'delete_shift', [], $shift_id, 'shift', session_id());
            } catch (Exception $e) {
                // Audit logging failed, but don't show error to user
            }
            
            // Refresh shifts list
            $shifts = $conn->query($shiftsQuery)->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $errorMessage = "Shift not found or could not be deleted";
            addNotification($conn, $_SESSION['user_id'], "Error: Shift not found or could not be deleted", "error");
        }
    } catch (PDOException $e) {
        $errorMessage = "Database error: " . $e->getMessage();
        addNotification($conn, $_SESSION['user_id'], "Database error while deleting shift: " . $e->getMessage(), "error");
    }
}

// Fetch all users for the filter dropdown
$users = $conn->query("SELECT id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Calculate navigation dates
$prevWeekStart = date('Y-m-d', strtotime('-1 week', strtotime($currentWeekStart)));
$nextWeekStart = date('Y-m-d', strtotime('+1 week', strtotime($currentWeekStart)));
$prevMonth = $currentMonth > 1 ? $currentMonth - 1 : 12;
$prevYear = $currentMonth > 1 ? $currentYear : $currentYear - 1;
$nextMonth = $currentMonth < 12 ? $currentMonth + 1 : 1;
$nextYear = $currentMonth < 12 ? $currentYear : $currentYear + 1;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title>Manage Shifts - Admin</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

</head>

<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-calendar-alt"></i> Manage Shifts</h1>
                <?php if ($username): ?>
                    <span class="user-filter">
                        for <?php echo htmlspecialchars($username); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="admin-actions">
                <a href="admin_dashboard.php" class="admin-btn secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <a href="add_shift.php" class="admin-btn">
                    <i class="fas fa-plus"></i> Add Shift
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

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-filter"></i> Filter Options</h2>
            </div>
            <div class="admin-panel-body">
                <div class="filter-bar">
                    <div class="filter-group">
                        <label for="period">View:</label>
                        <select id="period" onchange="updatePeriod()">
                            <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Week</option>
                            <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Month</option>
                            <option value="range" <?php echo $period === 'range' ? 'selected' : ''; ?>>Date Range</option>
                        </select>
                    </div>

                    <div class="filter-group" id="week-filter"
                        style="<?php echo $period !== 'week' ? 'display: none;' : ''; ?>">
                        <label for="week_start">Week Starting:</label>
                        <input type="date" id="week_start" value="<?php echo $currentWeekStart; ?>">
                    </div>

                    <div class="filter-group" id="month-filter"
                        style="<?php echo $period !== 'month' ? 'display: none;' : ''; ?>">
                        <label for="month">Month:</label>
                        <select id="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == $currentMonth ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1, date('Y'))); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <label for="year">Year:</label>
                        <select id="year">
                            <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $currentYear ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="filter-group" id="range-filter"
                        style="<?php echo $period !== 'range' ? 'display: none;' : ''; ?>">
                        <label for="start_date">From:</label>
                        <input type="date" id="start_date" value="<?php echo $startDate; ?>">
                        <label for="end_date">To:</label>
                        <input type="date" id="end_date" value="<?php echo $endDate; ?>">
                    </div>

                    <div class="filter-group">
                        <label for="user_filter">User:</label>
                        <select id="user_filter">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $user_id == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button class="admin-btn" onclick="applyFilters()">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($periodDesc); ?></h2>
                <div class="view-toggle">
                    <?php if ($period === 'week'): ?>
                        <a href="?period=week&week_start=<?php echo $prevWeekStart; ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>"
                            class="admin-btn">
                            <i class="fas fa-chevron-left"></i> Previous Week
                        </a>
                        <a href="?period=week&week_start=<?php echo date('Y-m-d', strtotime('monday this week')); ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>"
                            class="admin-btn">
                            Current Week
                        </a>
                        <a href="?period=week&week_start=<?php echo $nextWeekStart; ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>"
                            class="admin-btn">
                            Next Week <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php elseif ($period === 'month'): ?>
                        <a href="?period=month&month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>"
                            class="admin-btn">
                            <i class="fas fa-chevron-left"></i> Previous Month
                        </a>
                        <a href="?period=month&month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>"
                            class="admin-btn">
                            Current Month
                        </a>
                        <a href="?period=month&month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>"
                            class="admin-btn">
                            Next Month <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-list"></i> Shifts</h2>
            </div>
            <div class="admin-panel-body">

                <?php if (empty($shifts)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <p>No shifts found for the selected period</p>
                        <a href="add_shift.php<?php echo $user_id ? "?user_id=$user_id" : ''; ?>" class="admin-btn">
                            <i class="fas fa-plus"></i> Add Shift
                        </a>
                    </div>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width:56px;text-align:center">U</th>
                                <th>User</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Role</th>
                                <th>Location</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $current_date = '';
                            foreach ($shifts as $shift):
                                $shift_date = $shift['shift_date'];

                                // Add date header when date changes
                                if ($current_date !== $shift_date):
                                    $current_date = $shift_date;
                                    ?>
                                    <tr>
                                        <td colspan="7" class="day-header">
                                            <i class="fas fa-calendar-day"></i>
                                            <?php echo date('l, F j, Y', strtotime($shift_date)); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <?php
                                    // compute compact username (initials or first two chars)
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
                                    <td data-label="Date"><?php echo date('D, M j', strtotime($shift['shift_date'])); ?></td>
                                    <td data-label="Time"><?php echo date('g:i A', strtotime($shift['start_time'])); ?> -
                                        <?php echo date('g:i A', strtotime($shift['end_time'])); ?>
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
                                    <td class="actions" data-label="Actions">
                                        <a href="edit_shift.php?id=<?php echo $shift['id']; ?>&return=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="admin-btn"
                                            title="Edit shift">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                                            <input type="hidden" name="delete_shift" value="1">
                                            <button type="submit" class="admin-btn delete-btn"
                                                title="Delete shift"
                                                onclick="return confirm('Are you sure you want to delete the shift for <?php echo addslashes(htmlspecialchars($shift['username'])); ?> on <?php echo date('M j, Y', strtotime($shift['shift_date'])); ?>?');">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function updatePeriod() {
            const period = document.getElementById('period').value;
            document.getElementById('week-filter').style.display = period === 'week' ? 'flex' : 'none';
            document.getElementById('month-filter').style.display = period === 'month' ? 'flex' : 'none';
            document.getElementById('range-filter').style.display = period === 'range' ? 'flex' : 'none';
        }

        function applyFilters() {
            const period = document.getElementById('period').value;
            const user_id = document.getElementById('user_filter').value;
            let url = 'manage_shifts.php?period=' + period;

            if (user_id) {
                url += '&user_id=' + user_id;
            }

            if (period === 'week') {
                const week_start = document.getElementById('week_start').value;
                url += '&week_start=' + week_start;
            } else if (period === 'month') {
                const month = document.getElementById('month').value;
                const year = document.getElementById('year').value;
                url += '&month=' + month + '&year=' + year;
            } else if (period === 'range') {
                const start_date = document.getElementById('start_date').value;
                const end_date = document.getElementById('end_date').value;
                url += '&start_date=' + start_date + '&end_date=' + end_date;
            }

            window.location.href = url;
        }
    </script>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>