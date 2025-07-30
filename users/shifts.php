<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../includes/db.php';
require_once '../functions/payroll_functions.php';

$user_id = $_SESSION['user_id'];

// Determine period
$period = $_GET['period'] ?? 'week';

// Order shifts by date (closest to today first)
$orderBy = "ORDER BY ABS(DATEDIFF(shift_date, CURDATE()))";

// Change default to Saturday this week (week period is Saturday to Friday)
if (isset($_GET['weekStart'])) {
    $tempDate = $_GET['weekStart'];
    if (date('l', strtotime($tempDate)) !== 'Sunday') {
        $tempDate = date('Y-m-d', strtotime('last Sunday', strtotime($tempDate)));
    }
    $weekStart = $tempDate;
} else {
    if (date('l') === 'Sunday') {
        $weekStart = date('Y-m-d');
    } else {
        $weekStart = date('Y-m-d', strtotime('last Sunday'));
    }
}

$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');

// Adjust SQL based on period and optional params
if ($period == 'week') {
    $periodSql = "shift_date BETWEEN '$weekStart' AND DATE_ADD('$weekStart', INTERVAL 6 DAY)";
} elseif ($period == 'month') {
    $periodSql = "MONTH(shift_date) = $month AND YEAR(shift_date) = $year";
} elseif ($period == 'year') {
    $periodSql = "YEAR(shift_date) = $year";
} else {
    $period = 'week';
    $periodSql = "shift_date BETWEEN '$weekStart' AND DATE_ADD('$weekStart', INTERVAL 6 DAY)";
}

// Fetch roles for dropdown
$stmtRoles = $conn->query("SELECT id, name FROM roles ORDER BY name ASC");
$roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

// Query shifts
$stmtShifts = $conn->prepare(
    "SELECT s.*, r.name as role, s.location, r.base_pay, r.has_night_pay, r.night_shift_pay, r.night_start_time, r.night_end_time 
    FROM shifts s 
    JOIN roles r ON s.role_id = r.id 
    WHERE s.user_id = :user_id AND $periodSql 
    ORDER BY shift_date ASC, start_time ASC"
);
$stmtShifts->execute(['user_id' => $user_id]);
$shifts = $stmtShifts->fetchAll(PDO::FETCH_ASSOC);

// Calculate pay using new payroll system
$total_hours = 0;
$total_earnings = 0;
$individual_shift_pay = [];

// Get user's role information
$stmt_user_role = $conn->prepare("
    SELECT r.* 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    WHERE u.id = ? 
    LIMIT 1
");
$stmt_user_role->execute([$user_id]);
$user_role = $stmt_user_role->fetch(PDO::FETCH_ASSOC);

// Calculate pay for each shift using improved logic
foreach ($shifts as &$shift) {
    $start_time = strtotime($shift['start_time']);
    $end_time = strtotime($shift['end_time']);

    // Handle shifts crossing midnight
    if ($end_time < $start_time) {
        $end_time += 86400;
    }

    $hours = ($end_time - $start_time) / 3600;
    $total_hours += $hours;

    // Calculate pay based on employment type
    if ($user_role && ($user_role['employment_type'] ?? 'hourly') === 'salaried') {
        // For salaried employees, show pro-rated amount per shift
        $monthly_salary = $user_role['monthly_salary'] ?? 0;
        $working_days_per_month = 22; // Average working days
        $shift['pay'] = ($monthly_salary / $working_days_per_month) * ($hours / 8); // Assuming 8-hour standard day
    } else {
        // For hourly employees, use detailed calculation
        $base_rate = $user_role['base_pay'] ?? 10; // Default rate if not set
        $night_rate = $user_role['night_shift_pay'] ?? $base_rate;

        $regular_pay = 0;
        $night_pay = 0;

        if ($user_role['has_night_pay'] && $user_role['night_start_time'] && $user_role['night_end_time']) {
            $night_start = strtotime($user_role['night_start_time']);
            $night_end = strtotime($user_role['night_end_time']);

            // Handle night period crossing midnight
            if ($night_end < $night_start) {
                $night_end += 86400;
            }

            // Calculate overlap with night hours
            $overlap_start = max($start_time, $night_start);
            $overlap_end = min($end_time, $night_end);

            if ($overlap_start < $overlap_end) {
                $night_hours = ($overlap_end - $overlap_start) / 3600;
                $regular_hours = $hours - $night_hours;

                $night_pay = $night_hours * $night_rate;
                $regular_pay = $regular_hours * $base_rate;
            } else {
                $regular_pay = $hours * $base_rate;
            }
        } else {
            $regular_pay = $hours * $base_rate;
        }

        $shift['pay'] = $regular_pay + $night_pay;
    }

    $total_earnings += $shift['pay'];
}
unset($shift);

$whole_hours = floor($total_hours);
$minutes = round(($total_hours - $whole_hours) * 60);
$formatted_total_hours = "{$whole_hours} hr {$minutes} mins";

// Fetch notifications data for header
$notifications = [];
$notificationCount = 0;
if ($user_id) {
    require_once '../includes/notifications.php';
    $notifications = getNotifications($user_id);
    $notificationCount = count($notifications);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <title>Your Shifts - Open Rota</title>
    <link rel="stylesheet" href="../css/shifts.css">
    <style>
        .card {
            background-color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .card-header h3 {
            margin: 0;
            color: #fd2b2b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .summary-box {
            background-color: #f8f8f8;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }

        .summary-box h4 {
            margin: 0 0 10px 0;
            color: #333;
        }

        .summary-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #fd2b2b;
        }

        .period-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 15px 0;
            padding: 10px 0;
            border-top: 1px solid #eee;
            width: 100%;
        }

        .period-navigation p {
            margin: 0;
            font-weight: 500;
        }

        .period-nav-buttons {
            display: flex;
            gap: 10px;
        }

        .control-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        #toggleAddShiftBtn {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .add-shift-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .save-shift-btn {
            grid-column: span 2;
            margin-top: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        table th {
            background-color: #fd2b2b;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 500;
        }

        table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .shift-actions {
            display: flex;
            gap: 10px;
        }

        .editBtn,
        .deleteBtn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
        }

        .editBtn {
            background-color: #007bff;
            color: white;
        }

        .deleteBtn {
            background-color: #dc3545;
            color: white;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            overflow: auto;
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 25px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            position: relative;
            animation: modalFadeIn 0.3s;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .modal-header h3 {
            margin: 0;
            color: #fd2b2b;
        }

        .close-modal {
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: #666;
            transition: color 0.2s;
        }

        .close-modal:hover {
            color: #fd2b2b;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .no-shifts {
            text-align: center;
            padding: 30px;
            font-style: italic;
            color: #666;
        }

        /* Night shift styling */
        .night-shift {
            position: relative;
        }

        .night-shift:after {
            content: "🌙";
            position: absolute;
            top: 4px;
            right: 4px;
            font-size: 14px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .add-shift-form {
                grid-template-columns: 1fr;
            }

            .save-shift-btn {
                grid-column: 1;
            }

            table {
                font-size: 0.9rem;
            }

            /* Make table responsive */
            .responsive-table {
                overflow-x: auto;
            }

            .editBtn,
            .deleteBtn {
                padding: 5px 10px;
                font-size: 0.8rem;
            }
        }

        .period-nav-buttons a.btn {
            padding: 8px 15px;
            background-color: #fd2b2b;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .period-nav-buttons a.btn:hover {
            background-color: #d42020;
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .period-navigation {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }

            .period-nav-buttons {
                width: 100%;
                justify-content: space-between;
            }
        }

        /* Make sure these links aren't affected by PWA link handlers */
        .period-nav-buttons a.btn {
            position: relative;
            z-index: 5;
        }

        /* Mobile-specific styling for Add New Shift button */
        @media (max-width: 768px) {
            #toggleAddShiftBtn {
                padding: 8px 12px;
                font-size: 0.9rem;
                white-space: nowrap;
                width: auto;
                justify-content: center;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .card-header h3 {
                margin-bottom: 8px;
            }

            /* Make button span full width when header is stacked */
            .card-header #toggleAddShiftBtn {
                width: 100%;
            }
        }

        /* Extra small screens */
        @media (max-width: 480px) {
            #toggleAddShiftBtn {
                padding: 6px 10px;
                font-size: 0.85rem;
            }

            #toggleAddShiftBtn i {
                font-size: 0.9rem;
            }
        }
    </style>
</head>

<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <h1><i class="fa fa-calendar"></i> Your Shifts</h1>

        <!-- Summary Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fa fa-bar-chart"></i> Hours & Earnings</h3>

                <form method="GET" id="periodForm">
                    <label for="period">View: </label>
                    <select name="period" id="period" onchange="this.form.submit()">
                        <option value="week" <?php echo ($period == 'week') ? 'selected' : ''; ?>>This Week</option>
                        <option value="month" <?php echo ($period == 'month') ? 'selected' : ''; ?>>This Month</option>
                        <option value="year" <?php echo ($period == 'year') ? 'selected' : ''; ?>>This Year</option>
                    </select>
                    <input type="hidden" name="weekStart" value="<?php echo htmlspecialchars($weekStart); ?>">
                    <input type="hidden" name="month" value="<?php echo htmlspecialchars($month); ?>">
                    <input type="hidden" name="year" value="<?php echo htmlspecialchars($year); ?>">
                </form>
            </div>

            <div class="summary-grid">
                <div class="summary-box">
                    <h4>Total Hours</h4>
                    <div class="summary-value"><?php echo $formatted_total_hours; ?></div>
                </div>
                <div class="summary-box">
                    <h4>Total Earnings</h4>
                    <div class="summary-value">£<?php echo number_format($total_earnings, 2); ?></div>
                    <?php if ($user_role): ?>
                        <div class="summary-note">
                            <?php
                            $employment_type = $user_role['employment_type'] ?? 'hourly';
                            echo ucfirst($employment_type) . ' Employee';
                            if ($employment_type === 'salaried') {
                                echo '<br><small>Pro-rated from monthly salary</small>';
                            } else if ($user_role['has_night_pay']) {
                                echo '<br><small>Includes night shift premium</small>';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="period-navigation">
                <?php if ($period == 'week'): ?>
                    <?php $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days')); ?>
                    <p>Week of <?php echo date('j M', strtotime($weekStart)); ?> -
                        <?php echo date('j M Y', strtotime($weekEnd)); ?>
                    </p>
                    <div class="period-nav-buttons">
                        <a href="shifts.php?period=week&weekStart=<?php echo date('Y-m-d', strtotime($weekStart . ' -7 days')); ?>"
                            class="btn"><i class="fa fa-chevron-left"></i> Previous</a>
                        <a href="shifts.php?period=week&weekStart=<?php echo date('Y-m-d', strtotime($weekStart . ' +7 days')); ?>"
                            class="btn">Next <i class="fa fa-chevron-right"></i></a>
                    </div>
                <?php elseif ($period == 'month'): ?>
                    <p><?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></p>
                    <div class="period-nav-buttons">
                        <?php
                        $prevMonth = $month - 1;
                        $prevYear = $year;
                        if ($prevMonth < 1) {
                            $prevMonth = 12;
                            $prevYear -= 1;
                        }
                        $nextMonth = $month + 1;
                        $nextYear = $year;
                        if ($nextMonth > 12) {
                            $nextMonth = 1;
                            $nextYear += 1;
                        }
                        ?>
                        <a href="shifts.php?period=month&month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>"
                            class="btn"><i class="fa fa-chevron-left"></i> Previous</a>
                        <a href="shifts.php?period=month&month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>"
                            class="btn">Next <i class="fa fa-chevron-right"></i></a>
                    </div>
                <?php else: ?>
                    <p><?php echo $year; ?></p>
                    <div class="period-nav-buttons">
                        <a href="shifts.php?period=year&year=<?php echo $year - 1; ?>" class="btn"><i
                                class="fa fa-chevron-left"></i> Previous</a>
                        <a href="shifts.php?period=year&year=<?php echo $year + 1; ?>" class="btn">Next <i
                                class="fa fa-chevron-right"></i></a>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- Shifts Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fa fa-list"></i> Shift Management</h3>
                <button id="toggleAddShiftBtn" class="btn"><i class="fa fa-plus-circle"></i> Add New Shift</button>
            </div>

            <!-- Add Shift Form (Hidden by Default) -->
            <div id="addShiftSection" style="display:none;" class="add-shift-section">
                <form id="addShiftForm" method="POST" action="../functions/add_shift.php">
                    <div class="add-shift-form">
                        <div class="form-group">
                            <label for="shift_date"><i class="fa fa-calendar-o"></i> Date:</label>
                            <input type="date" id="shift_date" name="shift_date" required />
                        </div>
                        <div class="form-group">
                            <label for="role_id"><i class="fa fa-briefcase"></i> Role:</label>
                            <select name="role_id" id="role_id" required>
                                <option value="">-- Select Role --</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="start_time"><i class="fa fa-clock-o"></i> Start Time:</label>
                            <input type="time" id="start_time" name="start_time" required />
                        </div>
                        <div class="form-group">
                            <label for="end_time"><i class="fa fa-clock-o"></i> End Time:</label>
                            <input type="time" id="end_time" name="end_time" required />
                        </div>
                        <div class="form-group">
                            <label for="location"><i class="fa fa-map-marker"></i> Location:</label>
                            <input type="text" id="location" name="location" required
                                placeholder="Enter work location" />
                        </div>
                    </div>
                    <button class="btn save-shift-btn" type="submit"><i class="fa fa-save"></i> Save Shift</button>
                </form>
            </div>

            <div class="responsive-table">
                <?php if (count($shifts) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Role</th>
                                <th>Location</th>
                                <th>Pay</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shifts as $shift): ?>
                                <?php
                                $formattedDate = date("D, j M Y", strtotime($shift['shift_date']));
                                $formattedStart = date("g:i A", strtotime($shift['start_time']));
                                $formattedEnd = date("g:i A", strtotime($shift['end_time']));
                                // Check if this is a night shift
                                $isNightShift = false;
                                if ($shift['has_night_pay'] && !empty($shift['night_start_time']) && !empty($shift['night_end_time'])) {
                                    $shiftStart = strtotime($shift['start_time']);
                                    $shiftEnd = strtotime($shift['end_time']);
                                    $nightStart = strtotime($shift['night_start_time']);
                                    $nightEnd = strtotime($shift['night_end_time']);
                                    // Check if shift overlaps with night hours
                                    if (
                                        ($shiftStart >= $nightStart) || ($shiftEnd <= $nightEnd) ||
                                        ($shiftStart <= $nightStart && $shiftEnd >= $nightEnd)
                                    ) {
                                        $isNightShift = true;
                                    }
                                }
                                ?>
                                <tr class="<?php echo $isNightShift ? 'night-shift' : ''; ?>">
                                    <!-- Shift Date -->
                                    <td data-raw-date="<?php echo $shift['shift_date']; ?>">
                                        <?php echo $formattedDate; ?>
                                    </td>
                                    <!-- Times -->
                                    <td data-raw-start="<?php echo $shift['start_time']; ?>"
                                        data-raw-end="<?php echo $shift['end_time']; ?>">
                                        <?php echo $formattedStart; ?> - <?php echo $formattedEnd; ?>
                                    </td>
                                    <!-- Role -->
                                    <td data-raw-role="<?php echo $shift['role_id']; ?>">
                                        <?php echo htmlspecialchars($shift['role']); ?>
                                    </td>
                                    <!-- Location -->
                                    <td data-raw-location="<?php echo htmlspecialchars($shift['location']); ?>">
                                        <?php echo htmlspecialchars($shift['location']); ?>
                                    </td>
                                    <!-- Pay -->
                                    <td>
                                        <strong>£<?php echo number_format($shift['pay'], 2); ?></strong>
                                    </td>
                                    <!-- Actions -->
                                    <td>
                                        <div class="shift-actions">
                                            <button class="editBtn" data-id="<?php echo $shift['id']; ?>">
                                                <i class="fa fa-pencil"></i> Edit
                                            </button>
                                            <button class="deleteBtn" data-id="<?php echo $shift['id']; ?>">
                                                <i class="fa fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-shifts">
                        <p>No shifts found for the selected period.</p>
                        <p>Click "Add New Shift" to get started.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Edit Shift Modal -->
        <div id="editShiftModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fa fa-pencil"></i> Edit Shift</h3>
                    <span class="close-modal">&times;</span>
                </div>
                <form id="editShiftForm" method="POST" action="../functions/edit_shift.php">
                    <input type="hidden" name="shift_id" id="edit_shift_id">
                    <div class="add-shift-form">
                        <div class="form-group">
                            <label for="edit_shift_date"><i class="fa fa-calendar-o"></i> Date:</label>
                            <input type="date" name="shift_date" id="edit_shift_date" required />
                        </div>
                        <div class="form-group">
                            <label for="edit_role_id"><i class="fa fa-briefcase"></i> Role:</label>
                            <select name="role_id" id="edit_role_id" required>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_start_time"><i class="fa fa-clock-o"></i> Start Time:</label>
                            <input type="time" name="start_time" id="edit_start_time" required />
                        </div>
                        <div class="form-group">
                            <label for="edit_end_time"><i class="fa fa-clock-o"></i> End Time:</label>
                            <input type="time" name="end_time" id="edit_end_time" required />
                        </div>
                        <div class="form-group">
                            <label for="edit_location"><i class="fa fa-map-marker"></i> Location:</label>
                            <input type="text" name="location" id="edit_location" required />
                        </div>
                    </div>
                    <button type="submit" class="btn save-shift-btn"><i class="fa fa-save"></i> Update Shift</button>
                </form>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Toggle add shift form
            const toggleBtn = document.getElementById("toggleAddShiftBtn");
            const addSection = document.getElementById("addShiftSection");
            if (toggleBtn && addSection) {
                toggleBtn.addEventListener("click", function () {
                    const isVisible = addSection.style.display !== "none";
                    addSection.style.display = isVisible ? "none" : "block";
                    toggleBtn.innerHTML = isVisible ?
                        '<i class="fa fa-plus-circle"></i> Add New Shift' :
                        '<i class="fa fa-minus-circle"></i> Cancel';
                });
            }

            // Set today's date as default for new shift
            const shiftDate = document.getElementById("shift_date");
            if (shiftDate) {
                shiftDate.valueAsDate = new Date();
            }

            // Edit shift functionality
            const editBtns = document.querySelectorAll(".editBtn");
            const editModal = document.getElementById("editShiftModal");
            const closeModal = document.querySelector(".close-modal");

            editBtns.forEach(btn => {
                btn.addEventListener("click", function () {
                    const row = this.closest("tr");
                    document.getElementById("edit_shift_id").value = this.dataset.id;
                    document.getElementById("edit_shift_date").value = row.querySelector("td:nth-child(1)").dataset.rawDate;
                    document.getElementById("edit_start_time").value = row.querySelector("td:nth-child(2)").dataset.rawStart;
                    document.getElementById("edit_end_time").value = row.querySelector("td:nth-child(2)").dataset.rawEnd;
                    document.getElementById("edit_location").value = row.querySelector("td:nth-child(4)").dataset.rawLocation;
                    document.getElementById("edit_role_id").value = row.querySelector("td:nth-child(3)").dataset.rawRole;

                    // Show the modal
                    editModal.style.display = "block";
                });
            });

            // Close modal when clicking the X
            if (closeModal) {
                closeModal.addEventListener("click", function () {
                    editModal.style.display = "none";
                });
            }

            // Close modal when clicking outside of it
            window.addEventListener("click", function (event) {
                if (event.target === editModal) {
                    editModal.style.display = "none";
                }
            });

            // Handle delete buttons
            const deleteBtns = document.querySelectorAll(".deleteBtn");
            deleteBtns.forEach(btn => {
                btn.addEventListener("click", function () {
                    const shiftId = this.dataset.id;
                    if (confirm("Are you sure you want to delete this shift?")) {
                        fetch('../functions/delete_shift.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `shift_id=${shiftId}`
                        })
                            .then(response => response.text())
                            .then(data => {
                                alert(data);
                                location.reload();
                            })
                            .catch(error => {
                                alert("An error occurred: " + error);
                            });
                    }
                });
            });

            // Make period navigation buttons work properly
            const navButtons = document.querySelectorAll('.period-nav-buttons a');
            navButtons.forEach(button => {
                button.addEventListener('click', function (e) {
                    console.log('Period navigation button clicked:', this.href);
                    // Ensure direct navigation
                    window.location.href = this.href;
                    e.preventDefault();
                });
            });
        });
    </script>
    <script src="/rota-app-main/js/menu.js"></script>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>