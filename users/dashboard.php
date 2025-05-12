<?php
require '../includes/auth.php';
requireLogin(); // Only logged-in users can access

include __DIR__ . '/../includes/header.php';

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
            r.night_start_time, r.night_end_time, r.name AS role_name 
     FROM shifts s 
     JOIN roles r ON s.role_id = r.id 
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
            r.night_start_time, r.night_end_time, r.name AS role_name
     FROM shifts s
     JOIN roles r ON s.role_id = r.id
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

// Do not set $conn to null here because we need it later for the overlapping shifts query.
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <title>Dashboard - Open Rota</title>
    <style>
        /* Enhanced dashboard styles */
        .dashboard-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        @media (min-width: 992px) {
            .dashboard-container {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* Navigation Menu - consistent with shifts page */
        .nav-links {
            display: none;
            position: absolute;
            top: 60px;
            right: 10px;
            background: #ffffff;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            z-index: 1000;
        }

        .nav-links.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .nav-links ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .nav-links ul li {
            margin: 0;
            padding: 0;
            display: block;
        }

        .nav-links ul li a {
            display: block;
            padding: 12px 20px;
            color: #333;
            text-decoration: none;
            white-space: nowrap;
            border-bottom: 1px solid #eee;
        }

        .nav-links ul li:last-child a {
            border-bottom: none;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Welcome card adjustments */
        .welcome-card {
            background: linear-gradient(145deg, #fd2b2b, #c82333);
            color: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            grid-column: 1 / -1;
        }

        .welcome-text h1 {
            margin: 0;
            font-size: 1.8rem;
            color: white;
        }

        .welcome-text p {
            margin: 8px 0 0 0;
            opacity: 0.9;
            font-size: 1rem;
        }

        .welcome-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .welcome-actions a {
            background-color: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .welcome-actions a:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
            grid-column: 1 / -1;
        }

        .stat-card {
            background-color: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            color: #fd2b2b;
            margin: 10px 0;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
            margin: 0;
        }

        .dashboard-card {
            background-color: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .dashboard-card h3 {
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
            font-weight: 600;
            font-size: 1.2rem;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .dashboard-card h3 i {
            color: #fd2b2b;
        }

        .next-shift-details {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .next-shift-date {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .next-shift-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }

        .next-shift-meta {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .next-shift-meta i {
            color: #fd2b2b;
            font-size: 0.9rem;
        }

        .next-shift-meta span {
            font-size: 0.95rem;
            color: #444;
        }

        .overlap-info {
            background-color: #f0f7ff;
            border-left: 4px solid #3498db;
            padding: 15px;
            margin-top: 15px;
            border-radius: 6px;
        }

        .overlap-info h4 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #2980b9;
            font-size: 1rem;
        }

        .colleague-list {
            list-style-type: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .colleague-item {
            background-color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .period-selector {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .period-selector label {
            font-size: 0.9rem;
            color: #666;
        }

        .period-selector select {
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #ddd;
            background-color: white;
        }

        .upcoming-shifts-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .upcoming-shifts-table th {
            background-color: #f5f5f5;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #eee;
        }

        .upcoming-shifts-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #eee;
        }

        .earnings-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .earnings-stat-box {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 6px;
            text-align: center;
        }

        .earnings-stat-value {
            font-size: 1.6rem;
            font-weight: bold;
            color: #fd2b2b;
            margin: 5px 0;
        }

        .earnings-stat-label {
            font-size: 0.9rem;
            color: #666;
        }

        .day-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            line-height: 30px;
            text-align: center;
            border-radius: 50%;
            background-color: #fd2b2b;
            color: white;
            margin-right: 5px;
            font-weight: bold;
            font-size: 0.8rem;
        }

        .time-badge {
            background-color: #333;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            display: inline-block;
        }

        /* Improved mobile responsiveness */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 10px;
                gap: 12px;
            }

            .welcome-card {
                flex-direction: column;
                align-items: flex-start;
                padding: 15px;
            }

            .welcome-text h1 {
                font-size: 1.5rem;
            }

            .welcome-actions {
                margin-top: 15px;
                width: 100%;
                justify-content: space-between;
            }

            .welcome-actions a {
                font-size: 0.85rem;
                padding: 8px 12px;
            }

            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .stat-card {
                padding: 12px 8px;
            }

            .stat-number {
                font-size: 1.5rem;
            }

            .dashboard-card {
                padding: 15px;
            }

            .earnings-stats {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .next-shift-info {
                grid-template-columns: 1fr;
            }

            .upcoming-shifts-table {
                font-size: 0.9rem;
            }

            /* Table responsive fix */
            .responsive-table {
                overflow-x: auto;
                display: block;
                width: 100%;
            }

            /* Ensure vertical nav menu (not horizontal) */
            .nav-links ul {
                display: block;
            }

            .nav-links ul li {
                display: block;
                margin: 0;
                width: 100%;
            }

            .nav-links ul li a {
                padding: 12px 15px;
                border-bottom: 1px solid #eee;
                display: block;
                width: 100%;
                box-sizing: border-box;
            }
        }

        @media (max-width: 480px) {
            .quick-stats {
                grid-template-columns: 1fr 1fr;
            }

            .welcome-actions {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 6px;
            }

            .welcome-actions a {
                flex: 1 0 calc(50% - 6px);
                text-align: center;
                font-size: 0.8rem;
                padding: 8px 5px;
            }

            .dashboard-card h3 {
                font-size: 1.1rem;
            }

            .next-shift-date {
                font-size: 1.1rem;
            }

            .colleague-list {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>

<body>
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
                <a href="shifts.php"><i class="fas fa-calendar"></i> My Shifts</a>
                <a href="rota.php"><i class="fas fa-users"></i> Rota</a>
                <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="../admin/admin_dashboard.php"><i class="fas fa-shield-alt"></i> Admin</a>
                <?php endif; ?>
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
                            <span><?php echo htmlspecialchars($next_shift['location']); ?></span>
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
                            <td><?php echo htmlspecialchars($shift['location']); ?></td>
                            <td><strong>£<?php echo number_format($shift['estimated_pay'], 2); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <div style="margin-top: 15px; text-align: right;">
                    <a href="shifts.php" style="font-size: 0.9rem;">View all shifts <i class="fas fa-arrow-right"></i></a>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 30px; color: #777;">
                    <i class="fas fa-calendar-minus" style="font-size: 2rem; color: #ddd; margin-bottom: 15px;"></i>
                    <p>No additional upcoming shifts scheduled.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($_SESSION['role'] === 'admin'): ?>
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
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Initialize the burger menu functionality 
            const menuToggle = document.getElementById('menu-toggle');
            const navLinks = document.getElementById('nav-links');

            if (menuToggle && navLinks) {
                menuToggle.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    navLinks.classList.toggle('show');
                    console.log('Menu toggled');
                });

                // Close menu when clicking outside
                document.addEventListener('click', function (event) {
                    if (!menuToggle.contains(event.target) &&
                        !navLinks.contains(event.target) &&
                        navLinks.classList.contains('show')) {
                        navLinks.classList.remove('show');
                    }
                });
            }

            // Set header opacity to 1 once page is loaded
            document.querySelector('header').style.opacity = "1";

            // Handle period selector
            const periodSelect = document.getElementById('period');
            if (periodSelect) {
                periodSelect.addEventListener('change', function () {
                    this.form.submit();
                });
            }
        });
    </script>
    <script src="/rota-app-main/js/menu.js"></script>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>