<?php
require '../includes/auth.php';
requireLogin(); // Only logged-in users can access

include '../includes/header.php';

// Include the DB connection
require_once '../includes/db.php';
if (!$conn) {
    $conn = new PDO("mysql:host=localhost;dbname=rota_app", "username", "password");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}


// Include the pay calculation function
require_once '../functions/calculate_pay.php';
// Determine the period to display (default is week)
$period = $_GET['period'] ?? 'week';
$user_id = $_SESSION['user_id'];

if ($period == 'week') {
    // Current week using ISO week (mode 1)
    $periodSql = "YEARWEEK(shift_date, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($period == 'month') {
    $periodSql = "MONTH(shift_date) = MONTH(CURDATE()) AND YEAR(shift_date) = YEAR(CURDATE())";
} elseif ($period == 'year') {
    $periodSql = "YEAR(shift_date) = YEAR(CURDATE())";
} else {
    $periodSql = "YEARWEEK(shift_date, 1) = YEARWEEK(CURDATE(), 1)";
}

// Query shifts for the current period along with role pay details and role name
$stmt = $conn->prepare(
    "SELECT s.*, r.base_pay, r.has_night_pay, r.night_shift_pay, r.night_start_time, r.night_end_time, r.name AS role_name 
     FROM shifts s 
     JOIN roles r ON s.role_id = r.id 
     WHERE s.user_id = ? AND $periodSql"
);
$stmt->execute([$user_id]);
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total hours worked and total earnings for the period
$total_hours = 0;
$total_earnings = 0;
foreach ($shifts as $shift) {
    // Calculate hours worked using start_time and end_time, accounting for shifts spanning midnight
    $start_time = strtotime($shift['start_time']);
    $end_time = strtotime($shift['end_time']);
    $hours = ($end_time - $start_time) / 3600;
    if ($hours < 0) {
        $hours += 24;
    }
    $total_hours += $hours;

    // Use calculatePay() from calculate_pay.php to get this shift's earnings.
    // It is assumed the function accepts the DB connection and the shift ID.
    $shift_pay = calculatePay($conn, $shift['id']);
    $total_earnings += (float)$shift_pay;
}

// Format total hours: whole hours plus minutes
$whole_hours = floor($total_hours);
$minutes = round(($total_hours - $whole_hours) * 60);
$formatted_total_hours = "{$whole_hours} hr {$minutes} mins";
// Query the next upcoming shift (closest shift)
// Use ">" instead of ">=" so that today's shifts (if already started) aren’t shown.
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
// Again, use ">" so shifts scheduled for future days are returned.
$stmt2 =  $conn->prepare(
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

// For each upcoming shift, calculate the estimated pay.
foreach ($next_shifts as &$shift) {
    $shift['estimated_pay'] = calculatePay($conn, $shift['id']);
}
unset($shift);

$conn = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/dashboard.css">
    <title>Dashboard</title>
</head>
<body>
    <section class="all-content">
       
        <div class="subfooter">
       
            <h1>Welcome, <?php echo !empty($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Guest'; ?></h1>
            <p><?php echo date("l, F j, Y"); ?></p>
            <p>You are logged in as <strong><?php echo !empty($_SESSION['role']) ? htmlspecialchars($_SESSION['role']) : 'Undefined'; ?></strong>.</p>
            <a href="logout.php">Logout</a>
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
            <sectio class="admin-panel">
            <h3>Manger's Panel</h3>
            <ul>
                <li><a href="../functions/shift_invitation_sender.php">Send shift</a></li>
                <li><a href="../admin/admin_dashboard.php">Admins dashboard</a></li>
            </ul>
            </sectio>
        <?php endif; ?>

        <section class="front-view">
        <!-- Earnings and Hours Worked Section -->
        
        <section class="earnings">
        <h3>Hours and Earnings <img src="../images/output-onlinepngtools.png" alt="">   </h3>
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
        <h3>Next Shift  <img src="../images/output-onlinepngtools (4).png" alt="">  </h3>
            <?php if ($next_shift): ?>
                <?php 
                    $formattedDate = date("l, F j, Y", strtotime($next_shift['shift_date']));
                    $formattedStart = date("g:i A", strtotime($next_shift['start_time']));
                    $formattedEnd = date("g:i A", strtotime($next_shift['end_time']));
                ?>
                <p class="next-shift-day">
                    <?php echo $formattedDate; ?><br>
                </p>
                <p id="next-shift-time">
                    Start Time : <?php echo $formattedStart; ?><br>
                    End Time : <?php echo $formattedEnd; ?><br>
                    Role : <?php echo htmlspecialchars($next_shift['role_name']); ?><br>
                    Location: <?php echo htmlspecialchars($next_shift['location']); ?><br>
                    Estimated Pay: £<?php echo number_format($next_shift['estimated_pay'], 2); ?>
                </p>
            <?php else: ?>
                <p>No upcoming shift.</p>
            <?php endif; ?>
        </section>

        </section>

        <!-- Upcoming Shifts Section -->
        <h3>Upcoming Shifts</h3>
        <section class="upcoming-shifts">
            <?php if (count($next_shifts) > 0): ?>
                <table border="1" cellspacing="0" cellpadding="5">
                    <tr>
                        <th>Shift Date</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>Role</th>
                        <th>Location</th>
                        <th>Estimated Pay</th>
                    </tr>
                    <?php foreach ($next_shifts as $shift): ?>
                        <?php 
                            $formattedDate = date("l, F j, Y", strtotime($shift['shift_date']));
                            $formattedStart = date("g:i A", strtotime($shift['start_time']));
                            $formattedEnd = date("g:i A", strtotime($shift['end_time']));
                        ?>
                        <tr>
                            <td><?php echo $formattedDate; ?></td>
                            <td><?php echo $formattedStart; ?></td>
                            <td><?php echo $formattedEnd; ?></td>
                            <td><?php echo htmlspecialchars($shift['role_name']); ?></td>
                            <td><?php echo htmlspecialchars($shift['location']); ?><br></td>
                            <td>£<?php echo number_format($shift['estimated_pay'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <p>No upcoming shifts.</p>
            <?php endif; ?>
        </section>
    </section>
</body>
</html>
