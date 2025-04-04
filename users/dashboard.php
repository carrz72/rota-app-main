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
    $total_earnings += (float)$shift_pay;
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

// Query the next 3 upcoming shifts along with role names.
$stmt2 = $conn->prepare(
    "SELECT s.*, r.base_pay, r.has_night_pay, r.night_shift_pay, 
            r.night_start_time, r.night_end_time, r.name AS role_name
     FROM shifts s
     JOIN roles r ON s.role_id = r.id
     WHERE s.user_id = ? AND s.shift_date >= CURDATE()
     ORDER BY s.shift_date ASC, s.start_time ASC
     LIMIT 3 OFFSET 1"
);
$stmt2->execute([$user_id]);
$next_shifts = $stmt2->fetchAll(PDO::FETCH_ASSOC);
$stmt2 = null;
foreach ($next_shifts as &$shift) {
    $shift['estimated_pay'] = calculatePay($conn, $shift['id']);
}
unset($shift);
// Do not set $conn to null here because we need it later for the overlapping shifts query.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="../images/logo.png">
    <!-- Other meta tags and styles -->
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <title>Dashboard</title>
    <style>
        /* Basic extra styling for overlapping shift info */
        .overlap-info { margin-top: 10px; padding: 8px; background: #f0f0f0; border-radius: 4px; }
        .overlap-info ul { list-style-type: disc; margin-left: 20px; }
    </style>
</head>
<body>
    <section class="all-content">
        <div class="subfooter">
            <h1>Welcome, <?php echo !empty($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Guest'; ?></h1>
            <p><?php echo date("l, F j, Y"); ?></p>
            <p>You are logged in as <strong><?php echo !empty($_SESSION['role']) ? htmlspecialchars($_SESSION['role']) : 'Undefined'; ?></strong>.</p>
            <a href="../functions/logout.php">Logout</a>
        </div>

        <section class="dashboard">
            <h2>Dashboard</h2>
            <ul>
                <li><a href="shifts.php">Shifts</a></li>
                <li><a href="roles.php">Roles</a></li>
                <li><a href="settings.php">Settings</a></li>
            </ul>
        </section>

        <?php if ($_SESSION['role'] === 'admin') : ?>
            <section class="admin-panel">
                <h3>Manager's Panel</h3>
                <ul>
                    <li><a href="../functions/shift_invitation_sender.php">Send shift</a></li>
                    <li><a href="../admin/admin_dashboard.php">Admins dashboard</a></li>
                </ul>
            </section>
        <?php endif; ?>

        <section class="front-view">
            <!-- Earnings and Hours Worked Section -->
             <div>
            <section class="earnings">
                <h3>Hours and Earnings <img src="../images/output-onlinepngtools.png" alt=""></h3>
                <div class="earning-box">
                    <form method="GET">
                        <label for="period">Select period: </label>
                        <select name="period" id="period" onchange="this.form.submit()">
                            <option value="week" <?php echo ($period == 'week') ? 'selected' : ''; ?>>Week</option>
                            <option value="month" <?php echo ($period == 'month') ? 'selected' : ''; ?>>Month</option>
                            <option value="year" <?php echo ($period == 'year') ? 'selected' : ''; ?>>Year</option>
                        </select>
                    </form>
                    <p>
                        Total Hours Worked: <?php echo $formatted_total_hours; ?><br>
                        Total Earnings: £<?php echo number_format($total_earnings, 2); ?>
                    </p>
                </div>
            </section>

            <!-- Next Shift Section -->
<section class="next-shift">
    <h3>Next Shift <img src="../images/output-onlinepngtools (4).png" alt=""></h3>
    <?php if ($next_shift): ?>
        <?php 
            $formattedDate = date("l, F j, Y", strtotime($next_shift['shift_date']));
            $formattedStart = date("g:i A", strtotime($next_shift['start_time']));
            $formattedEnd = date("g:i A", strtotime($next_shift['end_time']));
            
            // Compute next shift's start datetime.
            $next_start_dt = date("Y-m-d H:i:s", strtotime($next_shift['shift_date'] . " " . $next_shift['start_time']));
            // If the shift spans overnight (start > end), add one day to the end datetime.
            if (strtotime($next_shift['start_time']) < strtotime($next_shift['end_time'])) {
                $next_end_dt = date("Y-m-d H:i:s", strtotime($next_shift['shift_date'] . " " . $next_shift['end_time']));
            } else {
                $next_end_dt = date("Y-m-d H:i:s", strtotime(date("Y-m-d", strtotime($next_shift['shift_date'] . " +1 day")) . " " . $next_shift['end_time']));
            }
        ?>
        <p class="next-shift-day">
            <?php echo $formattedDate; ?><br>
        </p>
        <p id="next-shift-time">
            Start Time: <?php echo $formattedStart; ?><br>
            End Time: <?php echo $formattedEnd; ?><br>
            Role: <?php echo htmlspecialchars($next_shift['role_name']); ?><br>
            Location: <?php echo htmlspecialchars($next_shift['location']); ?><br>
            Estimated Pay: £<?php echo number_format($next_shift['estimated_pay'], 2); ?>
        </p>
        <?php
            // Query overlapping shifts using datetime comparisons.
            $overlappingShifts = [];
            try {
                $query = "
                    SELECT s.*, u.username 
                    FROM shifts s 
                    JOIN users u ON s.user_id = u.id 
                    WHERE s.user_id <> :user_id 
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
                    ':user_id'       => $user_id,
                    ':next_start_dt' => $next_start_dt,
                    ':next_end_dt'   => $next_end_dt
                ]);
                $overlappingShifts = $stmtOverlap->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $overlappingShifts = [];
                // For debugging, uncomment the following:
                // error_log("Overlap Query Error: " . $e->getMessage());
            }
            
            if (!empty($overlappingShifts)) {
                echo "<div class='overlap-info'><p>You will be working with:</p><ul>";
                foreach ($overlappingShifts as $colleague) {
                    $colStart = date("g:i A", strtotime($colleague['start_time']));
                  
                    echo "<li>" . htmlspecialchars($colleague['username']) . "<br><span class=\"colleague-time\">" . $colStart . "</span></li>";
                }
                echo "</ul></div>";
            } else {
                echo "<div class='overlap-info'><p>No shifts with colleagues for your next shift.</p></div>";
            }
        ?>
        </section>
        </div>

<!-- Upcoming Shifts Section -->
<section class="upcoming-shifts">
    <h3>Upcoming Shifts</h3>
    <?php if (!empty($next_shifts)): ?>
        <table>
            <tr>
                <th>Date</th>
                <th>Time</th>
                <th>Role</th>
                <th>Location</th>
                <th>Estimated Pay</th>
            </tr>
            <?php foreach ($next_shifts as $shift): ?>
                <tr>
                    <td><?php echo date("D, M j, Y", strtotime($shift['shift_date'])); ?></td>
                    <td><?php echo date("g:i A", strtotime($shift['start_time'])); ?> - <?php echo date("g:i A", strtotime($shift['end_time'])); ?></td>
                    <td><?php echo htmlspecialchars($shift['role_name']); ?></td>
                    <td><?php echo htmlspecialchars($shift['location']); ?></td>
                    <td>£<?php echo number_format($shift['estimated_pay'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No upcoming shifts scheduled.</p>
    <?php endif; ?>
</section>
    <?php else: ?>
        <p>No upcoming shift.</p>
    <?php endif; ?>
</section>
</body>
</html>