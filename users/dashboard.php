<?php
require_once '../includes/auth.php';
requireLogin(); // Only logged-in users can access

// Include header components
require_once '../includes/db.php';
require_once '../includes/notifications.php';
require_once '../includes/session_config.php';

// Include DB connection.
require_once '../includes/db.php';
if (!$conn) {
    $conn = new PDO("mysql:host=localhost;dbname=rota_app", "username", "password");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// Include pay calculation function.
require_once '../functions/calculate_pay.php';

$user_id = $_SESSION['user_id'];

// Determine the period to display (default is week)
$period = $_GET['period'] ?? 'week';
if ($period == 'week') {
    // Current week using ISO week
    $periodSql = "YEARWEEK(shift_date, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($period == 'month') {
    $periodSql = "MONTH(shift_date) = MONTH(CURDATE()) AND YEAR(shift_date) = YEAR(CURDATE())";
} elseif ($period == 'year') {
    $periodSql = "YEAR(shift_date) = YEAR(CURDATE())";
} else {
    $periodSql = "YEARWEEK(shift_date, 1) = YEARWEEK(CURDATE(), 1)";
}

// Query shifts for the current period along with role pay details and role name.
$stmt = $conn->prepare(
    "SELECT s.*, r.base_pay, r.has_night_pay, r.night_shift_pay, 
            r.night_start_time, r.night_end_time, r.name AS role_name, b.id AS branch_id, b.name AS branch_name 
     FROM shifts s 
     JOIN roles r ON s.role_id = r.id 
     LEFT JOIN branches b ON s.branch_id = b.id 
     WHERE s.user_id = ? AND $periodSql"
);
$stmt->execute([$user_id]);
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total hours worked and total earnings for the period.
$total_hours = 0;
$total_earnings = 0;
foreach ($shifts as $shift) {
    $start_time = strtotime($shift['start_time']);
    $end_time = strtotime($shift['end_time']);
    $hours = ($end_time - $start_time) / 3600;
    if ($hours < 0) {
        $hours += 24;
    }
    $total_hours += $hours;
    $shift_pay = calculatePay($conn, $shift['id']);
    $total_earnings += (float) $shift_pay;
}
$whole_hours = floor($total_hours);
$minutes = round(($total_hours - $whole_hours) * 60);
$formatted_total_hours = "{$whole_hours} hr {$minutes} mins";

// Query the next upcoming shift (closest shift).
$stmt_next = $conn->prepare(
    "SELECT s.*, r.base_pay, r.has_night_pay, r.night_shift_pay, 
            r.night_start_time, r.night_end_time, r.name AS role_name, b.name AS branch_name
     FROM shifts s
     JOIN roles r ON s.role_id = r.id
    LEFT JOIN branches b ON s.branch_id = b.id
    WHERE s.user_id = ? AND s.shift_date >= CURDATE()
     ORDER BY s.shift_date ASC, s.start_time ASC
     LIMIT 1"
);
$stmt_next->execute([$user_id]);
$next_shift = $stmt_next->fetch(PDO::FETCH_ASSOC);
$stmt_next = null;
if ($next_shift) {
    $next_shift['estimated_pay'] = calculatePay($conn, $next_shift['id']);
}

// Query the next 5 upcoming shifts along with role names (increased from 3 to 5)
$stmt2 = $conn->prepare(
    "SELECT s.*, r.base_pay, r.has_night_pay, r.night_shift_pay, 
            r.night_start_time, r.night_end_time, r.name AS role_name
     FROM shifts s
     JOIN roles r ON s.role_id = r.id
    LEFT JOIN branches b ON s.branch_id = b.id
    WHERE s.user_id = ? AND s.shift_date >= CURDATE()
     ORDER BY s.shift_date ASC, s.start_time ASC
     LIMIT 5 OFFSET 1"
);
$stmt2->execute([$user_id]);
$next_shifts = $stmt2->fetchAll(PDO::FETCH_ASSOC);
$stmt2 = null;
foreach ($next_shifts as &$shift) {
    $shift['estimated_pay'] = calculatePay($conn, $shift['id']);
}
unset($shift);

// Get current month's shifts count and total earnings
$currentMonth = date('n');
$currentYear = date('Y');
$stmt_month = $conn->prepare(
    "SELECT COUNT(*) as shift_count, SUM(r.base_pay) as base_earnings
     FROM shifts s
     JOIN roles r ON s.role_id = r.id
     WHERE s.user_id = ? AND MONTH(s.shift_date) = ? AND YEAR(s.shift_date) = ?"
);
$stmt_month->execute([$user_id, $currentMonth, $currentYear]);
$month_stats = $stmt_month->fetch(PDO::FETCH_ASSOC);

// Get the day of week distribution for better scheduling insights
$day_distribution = [0, 0, 0, 0, 0, 0, 0]; // Sun to Sat
$stmt_days = $conn->prepare(
    "SELECT DAYOFWEEK(shift_date) as day_num, COUNT(*) as count
     FROM shifts
     WHERE user_id = ?
     GROUP BY DAYOFWEEK(shift_date)"
);
$stmt_days->execute([$user_id]);
$days_result = $stmt_days->fetchAll(PDO::FETCH_ASSOC);
foreach ($days_result as $day) {
    $index = $day['day_num'] - 1; // Convert 1-7 to 0-6 array index
    $day_distribution[$index] = $day['count'];
}

// ========== ANALYTICS QUERIES ==========

// Get last 4 weeks earnings trend
$weekly_earnings = [];
for ($i = 3; $i >= 0; $i--) {
    $week_start = date('Y-m-d', strtotime("-$i weeks saturday"));
    $week_end = date('Y-m-d', strtotime("$week_start +6 days"));
    
    $stmt_week = $conn->prepare(
        "SELECT COALESCE(SUM(r.base_pay * TIMESTAMPDIFF(HOUR, s.start_time, s.end_time)), 0) as earnings
         FROM shifts s
         JOIN roles r ON s.role_id = r.id
         WHERE s.user_id = ? AND s.shift_date BETWEEN ? AND ?"
    );
    $stmt_week->execute([$user_id, $week_start, $week_end]);
    $week_data = $stmt_week->fetch(PDO::FETCH_ASSOC);
    
    $weekly_earnings[] = [
        'label' => date('M d', strtotime($week_start)),
        'value' => round($week_data['earnings'] ?? 0, 2)
    ];
}

// Get shift distribution by role for current month
$role_distribution = [];
$stmt_roles = $conn->prepare(
    "SELECT r.name as role_name, COUNT(*) as count
     FROM shifts s
     JOIN roles r ON s.role_id = r.id
     WHERE s.user_id = ? AND MONTH(s.shift_date) = ? AND YEAR(s.shift_date) = ?
     GROUP BY r.name
     ORDER BY count DESC
     LIMIT 5"
);
$stmt_roles->execute([$user_id, $currentMonth, $currentYear]);
$role_distribution = $stmt_roles->fetchAll(PDO::FETCH_ASSOC);

// Get pending coverage requests count
$pending_coverage_count = 0;
try {
    $stmt_coverage = $conn->prepare(
        "SELECT COUNT(*) as count FROM cross_branch_shift_requests 
         WHERE requested_by_user_id = ? AND status = 'pending'"
    );
    $stmt_coverage->execute([$user_id]);
    $pending_coverage_count = $stmt_coverage->fetchColumn();
} catch (Exception $e) {
    // Table might not exist
}

// Get pending shift invitations count
$pending_invitations_count = 0;
try {
    $stmt_invitations = $conn->prepare(
        "SELECT COUNT(*) as count FROM shift_invitations 
         WHERE user_id = ? AND status = 'pending'"
    );
    $stmt_invitations->execute([$user_id]);
    $pending_invitations_count = $stmt_invitations->fetchColumn();
} catch (Exception $e) {
    // Table might not exist
}

// Calculate average hourly rate
$avg_hourly_rate = 0;
if ($total_hours > 0) {
    $avg_hourly_rate = $total_earnings / $total_hours;
}

// Get year-to-date earnings
$ytd_earnings = 0;
$stmt_ytd = $conn->prepare(
    "SELECT s.*, r.base_pay, r.has_night_pay, r.night_shift_pay,
            r.night_start_time, r.night_end_time
     FROM shifts s
     JOIN roles r ON s.role_id = r.id
     WHERE s.user_id = ? AND YEAR(s.shift_date) = ?"
);
$stmt_ytd->execute([$user_id, $currentYear]);
$ytd_shifts = $stmt_ytd->fetchAll(PDO::FETCH_ASSOC);
foreach ($ytd_shifts as $shift) {
    $ytd_earnings += (float) calculatePay($conn, $shift['id']);
}

// Do not set $conn to null here because we need it later for the overlapping shifts query.
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
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="../images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/navigation.css?v=<?php echo time(); ?>">
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../css/dark_mode.css?v=<?php echo time(); ?>">
    <style>
        [data-theme="dark"] .page-header,
        [data-theme="dark"] .current-branch-info {
            background: transparent !important;
            color: var(--text) !important;
        }
    </style>
    <?php
    // If user is logged in, inline their saved theme early to prevent FOUC
    if (isset($_SESSION['user_id'])) {
        try {
            $stmtTheme = $conn->prepare('SELECT theme FROM users WHERE id = ? LIMIT 1');
            $stmtTheme->execute([$_SESSION['user_id']]);
            $row = $stmtTheme->fetch(PDO::FETCH_ASSOC);
            $userTheme = $row && !empty($row['theme']) ? $row['theme'] : null;
            if ($userTheme === 'dark') {
                echo "<script>document.documentElement.setAttribute('data-theme','dark');</script>\n";
            }
        } catch (Exception $e) {
            // ignore theme fetch errors
        }
    }
    ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <title>Dashboard - Open Rota</title>
</head>

<body>
    <!-- Header -->
    <?php
    // Retrieve the notification count from the database
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    $notifications = [];
    $notificationCount = 0;
    if ($user_id) {
        $notifications = getNotifications($user_id);
        $notificationCount = count($notifications);
    }
    ?>
    <header style="opacity: 1; transition: opacity 0.5s ease;">
        <div class="logo"><img src="../images/new logo.png" alt="Open Rota" style="height: 60px;"></div>
        <div class="nav-group">
            <div class="notification-container">
                <!-- Bell Icon -->
                <i class="fa fa-bell notification-icon" id="notification-icon"></i>
                <?php if ($notificationCount > 0): ?>
                    <span class="notification-badge"><?php echo $notificationCount; ?></span>
                <?php endif; ?>

                <!-- Notifications Dropdown -->
                <div class="notification-dropdown" id="notification-dropdown">
                    <?php if ($notificationCount > 0): ?>
                        <?php foreach ($notifications as $notif): ?>
                            <?php if ($notif['type'] === 'shift-invite' && !empty($notif['related_id'])): ?>
                                <a class="notification-item shit-invt notification-<?php echo $notif['type']; ?>"
                                    data-id="<?php echo $notif['id']; ?>"
                                    href="../functions/pending_shift_invitations.php?invitation_id=<?php echo $notif['related_id']; ?>&notif_id=<?php echo $notif['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notif['message']); ?></p>
                                </a>
                            <?php elseif ($notif['type'] === 'shift-swap' && !empty($notif['related_id'])): ?>
                                <a class="notification-item shit-invt notification-<?php echo $notif['type']; ?>"
                                    data-id="<?php echo $notif['id']; ?>"
                                    href="../functions/pending_shift_swaps.php?swap_id=<?php echo $notif['related_id']; ?>&notif_id=<?php echo $notif['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notif['message']); ?></p>
                                </a>
                            <?php else: ?>
                                <div class="notification-item notification-<?php echo $notif['type']; ?>"
                                    data-id="<?php echo $notif['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notif['message']); ?></p>
                                </div>
                            <?php endif; ?>
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
                    <?php if (isset($_SESSION['role']) && (($_SESSION['role'] === 'admin') || ($_SESSION['role'] === 'super_admin'))): ?>
                        <li><a href="../admin/admin_dashboard.php"><i class="fa fa-shield"></i> Admin</a></li>
                    <?php endif; ?>
                    <li><a href="../functions/logout.php"><i class="fa fa-sign-out"></i> Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <div class="dashboard-container">
        <!-- Welcome Banner -->
        <div class="welcome-card">
            <div class="welcome-text">
                <h1>Welcome,
                    <?php echo !empty($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Guest'; ?>
                </h1>
                <p><?php echo date("l, F j, Y"); ?> •
                    <?php echo !empty($_SESSION['role']) ? ucfirst(htmlspecialchars($_SESSION['role'])) : 'User'; ?>
                </p>
            </div>
            <div class="welcome-actions">
                <a href="shifts.php"><i class="fa fa-calendar"></i> My Shifts</a>
                <a href="rota.php"><i class="fa fa-table"></i> Rota</a>
                <a href="payroll.php"><i class="fa fa-money"></i> Payroll</a>
                <a href="coverage_requests.php"><i class="fa fa-exchange"></i> Coverage Requests</a>
                <a href="settings.php"><i class="fa fa-cog"></i> Settings</a>
                <?php if (isset($_SESSION['role']) && (($_SESSION['role'] === 'admin') || ($_SESSION['role'] === 'super_admin'))): ?>
                    <a href="../admin/admin_dashboard.php"><i class="fa fa-shield"></i> Admin</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions Section -->
        <div class="quick-actions-section">
            <div class="quick-actions-panel">
                <div class="quick-action-card" onclick="window.location.href='coverage_requests.php'">
                    <?php if ($pending_coverage_count > 0): ?>
                        <span class="action-badge pulse"><?php echo $pending_coverage_count; ?></span>
                    <?php endif; ?>
                    <div class="action-icon" style="background: linear-gradient(135deg, #fd2b2b, #ff6b6b);">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <h3>Request Coverage</h3>
                    <p>Find someone to cover your shift</p>
                </div>
                <div class="quick-action-card" onclick="window.location.href='coverage_requests.php?view=swap'">
                    <div class="action-icon" style="background: linear-gradient(135deg, #0d6efd, #6ea8fe);">
                        <i class="fas fa-sync-alt"></i>
                    </div>
                    <h3>Swap Shift</h3>
                    <p>Exchange shifts with colleagues</p>
                </div>
                <div class="quick-action-card" onclick="window.location.href='rota.php'">
                    <div class="action-icon" style="background: linear-gradient(135deg, #198754, #75b798);">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <h3>View Schedule</h3>
                    <p>See the full team rota</p>
                </div>
                <div class="quick-action-card" onclick="window.location.href='settings.php?tab=support'">
                    <?php if ($pending_invitations_count > 0): ?>
                        <span class="action-badge pulse"><?php echo $pending_invitations_count; ?></span>
                    <?php endif; ?>
                    <div class="action-icon" style="background: linear-gradient(135deg, #6f42c1, #a98eda);">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <h3>Support</h3>
                    <p>Get help or report an issue</p>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo $month_stats['shift_count'] ?? 0; ?></div>
                <div class="stat-label">Shifts This Month</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $whole_hours; ?></div>
                <div class="stat-label">Hours This <?php echo ucfirst($period); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-number">£<?php echo number_format($total_earnings, 0); ?></div>
                <div class="stat-label">Earnings This <?php echo ucfirst($period); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($next_shifts) + ($next_shift ? 1 : 0); ?></div>
                <div class="stat-label">Upcoming Shifts</div>
            </div>
        </div>

        <!-- Performance Analytics -->
        <div class="analytics-panel">
            <h2><i class="fas fa-chart-line"></i> Performance Analytics</h2>
            
            <div class="analytics-grid">
                <!-- Weekly Earnings Chart -->
                <div class="analytics-card">
                    <h3><i class="fas fa-pound-sign"></i> Weekly Earnings Trend</h3>
                    <canvas id="earningsChart"></canvas>
                    <div class="chart-stats">
                        <div class="chart-stat">
                            <span class="stat-label">Average</span>
                            <span class="stat-value">£<?php 
                                $avg_weekly = count($weekly_earnings) > 0 ? array_sum(array_column($weekly_earnings, 'value')) / count($weekly_earnings) : 0;
                                echo number_format($avg_weekly, 0); 
                            ?></span>
                        </div>
                        <div class="chart-stat">
                            <span class="stat-label">This Week</span>
                            <span class="stat-value">£<?php echo number_format($total_earnings, 0); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Role Distribution Chart -->
                <div class="analytics-card">
                    <h3><i class="fas fa-briefcase"></i> Shifts by Role (This Month)</h3>
                    <canvas id="roleChart"></canvas>
                    <div class="role-legend">
                        <?php foreach (array_slice($role_distribution, 0, 5) as $role): ?>
                            <div class="legend-item">
                                <span class="legend-dot"></span>
                                <span class="legend-label"><?php echo htmlspecialchars($role['role_name']); ?></span>
                                <span class="legend-value"><?php echo $role['count']; ?> shifts</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Earnings Insights -->
                <div class="analytics-card insights">
                    <h3><i class="fas fa-lightbulb"></i> Earnings Insights</h3>
                    <div class="insight-row">
                        <div class="insight-label">Average Hourly Rate</div>
                        <div class="insight-value">£<?php echo number_format($avg_hourly_rate, 2); ?>/hr</div>
                    </div>
                    <div class="insight-row">
                        <div class="insight-label">Year-to-Date Earnings</div>
                        <div class="insight-value">£<?php echo number_format($ytd_earnings, 0); ?></div>
                    </div>
                    <div class="insight-row">
                        <div class="insight-label">Projected Monthly</div>
                        <div class="insight-value">£<?php 
                            $days_in_month = date('t');
                            $current_day = date('j');
                            $projected = $days_in_month > 0 ? ($total_earnings / $current_day) * $days_in_month : 0;
                            echo number_format($projected, 0); 
                        ?></div>
                    </div>
                    <div class="insight-row">
                        <div class="insight-label">Total Hours (Month)</div>
                        <div class="insight-value"><?php echo number_format($total_hours, 1); ?>h</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Shift Information Section -->
        <div class="shifts-section">
            <!-- Next Shift Section -->
            <div class="dashboard-card">
                <h3><i class="fas fa-clock"></i> Next Shift</h3>
            <?php if ($next_shift): ?>
                <?php
                $formattedDate = date("l, F j, Y", strtotime($next_shift['shift_date']));
                $formattedStart = date("g:i A", strtotime($next_shift['start_time']));
                $formattedEnd = date("g:i A", strtotime($next_shift['end_time']));

                // Calculate days until next shift
                $today = new DateTime('today');
                $shift_date = new DateTime($next_shift['shift_date']);
                $days_until = $today->diff($shift_date)->days;
                $days_label = $days_until == 0 ? 'Today' : ($days_until == 1 ? 'Tomorrow' : "In $days_until days");

                // Compute next shift's start datetime
                $next_start_dt = date("Y-m-d H:i:s", strtotime($next_shift['shift_date'] . " " . $next_shift['start_time']));
                // If the shift spans overnight (start > end), add one day to the end datetime
                if (strtotime($next_shift['start_time']) < strtotime($next_shift['end_time'])) {
                    $next_end_dt = date("Y-m-d H:i:s", strtotime($next_shift['shift_date'] . " " . $next_shift['end_time']));
                } else {
                    $next_end_dt = date("Y-m-d H:i:s", strtotime(date("Y-m-d", strtotime($next_shift['shift_date'] . " +1 day")) . " " . $next_shift['end_time']));
                }
                ?>
                <div class="next-shift-details">
                    <div class="next-shift-date">
                        <span class="day-badge"><?php echo date("d", strtotime($next_shift['shift_date'])); ?></span>
                        <?php echo $formattedDate; ?>
                        <span style="color: #fd2b2b; font-weight: bold;"><?php echo $days_label; ?></span>
                    </div>

                    <div class="next-shift-info">
                        <div class="next-shift-meta">
                            <i class="fas fa-clock"></i>
                            <span><?php echo $formattedStart; ?> - <?php echo $formattedEnd; ?></span>
                        </div>

                        <div class="next-shift-meta">
                            <i class="fas fa-briefcase"></i>
                            <span><?php echo htmlspecialchars($next_shift['role_name']); ?></span>
                        </div>

                        <div class="next-shift-meta">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>
                                <?php
                                $nloc = $next_shift['location'] ?? '';
                                if ($nloc === 'Cross-branch coverage' && !empty($next_shift['branch_name'])) {
                                    echo htmlspecialchars($nloc) . ' (' . htmlspecialchars($next_shift['branch_name']) . ')';
                                } else {
                                    echo htmlspecialchars($nloc);
                                }
                                ?>
                            </span>
                        </div>

                        <div class="next-shift-meta">
                            <i class="fas fa-pound-sign"></i>
                            <span>£<?php echo number_format($next_shift['estimated_pay'], 2); ?></span>
                        </div>
                    </div>

                    <?php
                    // Query overlapping shifts with matching location
                    $overlappingShifts = [];
                    try {
                        $query = "
                            SELECT s.*, u.username 
                            FROM shifts s 
                            JOIN users u ON s.user_id = u.id 
                            WHERE s.user_id <> :user_id 
                              AND s.location = :location
                              AND (
                                STR_TO_DATE(CONCAT(s.shift_date, ' ', s.start_time), '%Y-%m-%d %H:%i:%s') <= :next_end_dt
                                AND IF(s.start_time < s.end_time,
                                    STR_TO_DATE(CONCAT(s.shift_date, ' ', s.end_time), '%Y-%m-%d %H:%i:%s'),
                                    STR_TO_DATE(CONCAT(DATE_ADD(s.shift_date, INTERVAL 1 DAY), ' ', s.end_time), '%Y-%m-%d %H:%i:%s')
                                ) > :next_start_dt
                              )
                        ";
                        $stmtOverlap = $conn->prepare($query);
                        $stmtOverlap->execute([
                            ':user_id' => $user_id,
                            ':location' => $next_shift['location'],
                            ':next_start_dt' => $next_start_dt,
                            ':next_end_dt' => $next_end_dt
                        ]);
                        $overlappingShifts = $stmtOverlap->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        $overlappingShifts = [];
                    }

                    if (!empty($overlappingShifts)) {
                        echo "<div class='overlap-info'>";
                        echo "<h4><i class='fas fa-users'></i> Working with Colleagues</h4>";
                        echo "<ul class='colleague-list'>";
                        foreach ($overlappingShifts as $colleague) {
                            $colStart = date("g:i A", strtotime($colleague['start_time']));
                            echo "<li class='colleague-item'>" . htmlspecialchars($colleague['username']) .
                                " <span class='time-badge'>" . $colStart . "</span></li>";
                        }
                        echo "</ul>";
                        echo "</div>";
                    }
                    ?>
                </div>
            <?php else: ?>
                <div class="next-shift-details" style="text-align: center; padding: 30px;">
                    <i class="fas fa-calendar-times" style="font-size: 2rem; color: #ddd; margin-bottom: 15px;"></i>
                    <p style="margin: 0; color: #777;">No upcoming shifts scheduled</p>
                    <a href="shifts.php" style="display: inline-block; margin-top: 15px;">Add a new shift</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Earnings Section -->
        <div class="dashboard-card">
            <h3><i class="fas fa-chart-line"></i> Hours & Earnings</h3>

            <div class="period-selector">
                <form method="GET">
                    <label for="period">Time Period:</label>
                    <select name="period" id="period" onchange="this.form.submit()">
                        <option value="week" <?php echo ($period == 'week') ? 'selected' : ''; ?>>This Week</option>
                        <option value="month" <?php echo ($period == 'month') ? 'selected' : ''; ?>>This Month</option>
                        <option value="year" <?php echo ($period == 'year') ? 'selected' : ''; ?>>This Year</option>
                    </select>
                </form>
            </div>

            <div class="earnings-stats">
                <div class="earnings-stat-box">
                    <div class="earnings-stat-label">Hours Worked</div>
                    <div class="earnings-stat-value"><?php echo $formatted_total_hours; ?></div>
                </div>
                <div class="earnings-stat-box">
                    <div class="earnings-stat-label">Total Earnings</div>
                    <div class="earnings-stat-value">£<?php echo number_format($total_earnings, 2); ?></div>
                </div>
            </div>

            <?php if (count($shifts) > 0): ?>
                <div style="margin-top: 20px; font-size: 0.9rem; color: #666;">
                    <p>Most common working days:</p>
                    <div style="display: flex; gap: 5px; margin-top: 5px;">
                        <?php
                        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                        $max_val = max($day_distribution);

                        foreach ($day_distribution as $index => $count) {
                            $opacity = $max_val > 0 ? ($count / $max_val) : 0;
                            $opacity = max(0.2, $opacity); // Minimum opacity of 0.2
                            echo "<div style='background-color: rgba(253, 43, 43, {$opacity}); color: white; padding: 5px; 
                              border-radius: 4px; text-align: center; flex: 1;'>{$days[$index]}</div>";
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Upcoming Shifts Section -->
        <div class="dashboard-card" style="grid-column: 1 / -1;">
            <h3><i class="fas fa-calendar-alt"></i> Upcoming Shifts</h3>

            <?php if (!empty($next_shifts)): ?>
                <table class="upcoming-shifts-table">
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Role</th>
                        <th>Location</th>
                        <th>Est. Pay</th>
                    </tr>

                    <?php
                    $current_date = '';
                    foreach ($next_shifts as $shift):
                        $shift_date = date("Y-m-d", strtotime($shift['shift_date']));
                        $is_new_date = ($shift_date != $current_date);
                        $current_date = $shift_date;

                        // Format for display
                        $formattedShiftDate = date("D, M j, Y", strtotime($shift['shift_date']));
                        $formattedStartTime = date("g:i A", strtotime($shift['start_time']));
                        $formattedEndTime = date("g:i A", strtotime($shift['end_time']));
                        ?>
                        <tr>
                            <td><?php echo $formattedShiftDate; ?></td>
                            <td><?php echo $formattedStartTime; ?> - <?php echo $formattedEndTime; ?></td>
                            <td><?php echo htmlspecialchars($shift['role_name']); ?></td>
                            <td>
                                <?php
                                $loc = $shift['location'] ?? '';
                                if ($loc === 'Cross-branch coverage' && !empty($shift['branch_name'])) {
                                    echo htmlspecialchars($loc) . ' (' . htmlspecialchars($shift['branch_name']) . ')';
                                } else {
                                    echo htmlspecialchars($loc);
                                }
                                ?>
                            </td>
                            <td><strong>£<?php echo number_format($shift['estimated_pay'], 2); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <div style="margin-top: 15px; text-align: right;">
                    <a href="shifts.php" style="font-size: 0.9rem;">View all shifts <i class="fa fa-arrow-right"></i></a>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 30px; color: #777;">
                    <i class="fas fa-calendar-minus" style="font-size: 2rem; color: #ddd; margin-bottom: 15px;"></i>
                    <p>No additional upcoming shifts scheduled.</p>
                </div>
            <?php endif; ?>
        </div>
        </div> <!-- End Shifts Section -->

        <?php if (isset($_SESSION['role']) && (($_SESSION['role'] === 'admin') || ($_SESSION['role'] === 'super_admin'))): ?>
            <!-- Admin Quick Access -->
            <div class="dashboard-card">
                <h3><i class="fas fa-shield-alt"></i> Admin Tools</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 15px;">
                    <a href="../functions/shift_invitation_sender.php" style="text-decoration: none;">
                        <div style="background-color: #f9f9f9; padding: 15px; border-radius: 8px; text-align: center;">
                            <i class="fas fa-paper-plane" style="font-size: 2rem; color: #fd2b2b; margin-bottom: 10px;"></i>
                            <p style="margin: 0; color: #333; font-weight: 500;">Send Shift Invites</p>
                        </div>
                    </a>
                    <a href="../admin/admin_dashboard.php" style="text-decoration: none;">
                        <div style="background-color: #f9f9f9; padding: 15px; border-radius: 8px; text-align: center;">
                            <i class="fas fa-tachometer-alt"
                                style="font-size: 2rem; color: #fd2b2b; margin-bottom: 10px;"></i>
                            <p style="margin: 0; color: #333; font-weight: 500;">Admin Dashboard</p>
                        </div>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="../js/pwa-debug.js"></script>
    <script src="../js/links.js"></script>
    <script>
        // Notification functionality (robust)
        function markAsRead(elementOrDescendant) {
            // Ensure we have the notification item element
            let notificationElem = null;
            try {
                if (!elementOrDescendant) return;
                if (elementOrDescendant.classList && elementOrDescendant.classList.contains('notification-item')) {
                    notificationElem = elementOrDescendant;
                } else if (elementOrDescendant.closest) {
                    notificationElem = elementOrDescendant.closest('.notification-item');
                } else if (elementOrDescendant.parentElement) {
                    // fallback
                    notificationElem = elementOrDescendant.parentElement;
                }
            } catch (e) {
                console.error('Invalid element passed to markAsRead', e);
                return;
            }

            if (!notificationElem) {
                console.error('No notification element found');
                return;
            }

            const rawId = notificationElem.getAttribute('data-id');
            const notificationId = rawId ? parseInt(rawId, 10) : 0;
            console.log('Marking notification as read:', notificationId);

            if (!notificationId || notificationId <= 0) {
                console.error('No notification ID found');
                return;
            }

            fetch('../functions/mark_notification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: notificationId })
            })
                .then(res => {
                    console.log('Response status:', res.status);
                    return res.json();
                })
                .then(data => {
                    console.log('Response data:', data);
                    if (data && data.success) {
                        // remove element
                        if (notificationElem.parentNode) notificationElem.parentNode.removeChild(notificationElem);

                        // update badge and dropdown
                        const allNotifications = document.querySelectorAll('.notification-item[data-id]');
                        let visibleCount = 0;
                        allNotifications.forEach(n => {
                            if (window.getComputedStyle(n).display !== 'none') visibleCount++;
                        });

                        const badge = document.querySelector('.notification-badge');
                        const dropdown = document.getElementById('notification-dropdown');
                        if (visibleCount === 0) {
                            if (dropdown) dropdown.innerHTML = '<div class="notification-item"><p>No notifications</p></div>';
                            if (badge) badge.style.display = 'none';
                        } else if (badge) {
                            badge.textContent = visibleCount;
                            badge.style.display = 'flex';
                        }
                    } else {
                        console.error('Failed to mark notification as read:', data && data.error ? data.error : data);
                    }
                })
                .catch(err => console.error('Error:', err));
        }

        // Debug script to test hamburger menu
        document.addEventListener('DOMContentLoaded', function () {
            // Notification setup
            var notificationIcon = document.getElementById('notification-icon');
            var dropdown = document.getElementById('notification-dropdown');

            // Strip malformed notifications if any
            if (typeof stripMalformedNotifications === 'function') stripMalformedNotifications();

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

            // Fix navigation menu links specifically for Chrome
            const navLinks2 = document.querySelectorAll('.nav-links ul li a');
            navLinks2.forEach(link => {
                link.style.backgroundColor = '#fd2b2b';
                link.style.color = '#ffffff';
            });
        });
    </script>
    <script src="../js/menu.js"></script>
    <script src="../js/darkmode.js"></script>
    <script src="../js/pwa-debug.js"></script>
    <script src="../js/links.js"></script>
    
    <!-- Chart.js Initialization -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Check if Chart.js is loaded
        if (typeof Chart === 'undefined') {
            console.error('Chart.js not loaded');
            return;
        }

        // Weekly Earnings Chart
        const earningsCtx = document.getElementById('earningsChart');
        if (earningsCtx) {
            const weeklyData = <?php echo json_encode($weekly_earnings); ?>;
            
            new Chart(earningsCtx, {
                type: 'line',
                data: {
                    labels: weeklyData.map(w => w.label),
                    datasets: [{
                        label: 'Weekly Earnings (£)',
                        data: weeklyData.map(w => w.value),
                        borderColor: '#fd2b2b',
                        backgroundColor: 'rgba(253, 43, 43, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#fd2b2b',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#fd2b2b',
                            borderWidth: 1,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return '£' + context.parsed.y.toFixed(2);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '£' + value;
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // Role Distribution Chart
        const roleCtx = document.getElementById('roleChart');
        if (roleCtx) {
            const roleData = <?php echo json_encode($role_distribution); ?>;
            
            const colors = [
                'rgba(253, 43, 43, 0.8)',
                'rgba(13, 110, 253, 0.8)',
                'rgba(25, 135, 84, 0.8)',
                'rgba(111, 66, 193, 0.8)',
                'rgba(255, 193, 7, 0.8)'
            ];
            
            new Chart(roleCtx, {
                type: 'doughnut',
                data: {
                    labels: roleData.map(r => r.role_name),
                    datasets: [{
                        data: roleData.map(r => r.count),
                        backgroundColor: colors,
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#fd2b2b',
                            borderWidth: 1,
                            displayColors: true,
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.parsed / total) * 100).toFixed(1);
                                    return context.label + ': ' + context.parsed + ' shifts (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
            
            // Color the legend dots
            const legendDots = document.querySelectorAll('.legend-dot');
            legendDots.forEach((dot, index) => {
                if (colors[index]) {
                    dot.style.backgroundColor = colors[index];
                }
            });
        }
    });
    </script>
</body>

</html>