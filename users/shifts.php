<?php
// filepath: c:\xampp\htdocs\rota-app\users\shifts.php
require '../includes/auth.php';
requireLogin();

include '../includes/header.php';

// Include the DB connection and pay calculation function
require_once '../includes/db.php';
require_once '../functions/calculate_pay.php';

$user_id = $_SESSION['user_id'];

// Determine period
// Determine period
$period = $_GET['period'] ?? 'week';

// Order shifts by date (closest to today first)
// Add this to your SQL query below
$orderBy = "ORDER BY ABS(DATEDIFF(shift_date, CURDATE()))";

// Change default to Saturday this week (week period is Saturday to Friday)
if (isset($_GET['weekStart'])) {
    $tempDate = $_GET['weekStart'];
    // Adjust to the Saturday of that week if not already Saturday
    if (date('l', strtotime($tempDate)) !== 'Saturday') {
        $tempDate = date('Y-m-d', strtotime('last Saturday', strtotime($tempDate)));
    }
    $weekStart = $tempDate;
} else {
    if (date('l') === 'Saturday') {
        $weekStart = date('Y-m-d');
    } else {
        $weekStart = date('Y-m-d', strtotime('last Saturday'));
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
    $query = "SELECT s.*, r.name as role, s.location, r.base_pay, r.has_night_pay, r.night_shift_pay, r.night_start_time, r.night_end_time 
    FROM shifts s 
    JOIN roles r ON s.role_id = r.id 
    WHERE s.user_id = :user_id AND $periodSql 
    ORDER BY shift_date ASC, start_time ASC"
);
$stmtShifts->execute(['user_id' => $user_id]);
$shifts = $stmtShifts->fetchAll(PDO::FETCH_ASSOC);

// Calculate pay for each shift
foreach ($shifts as &$shift) {
    $shift['pay'] = calculatePay($conn, $shift['id']);
}
unset($shift);

// Sum totals
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
    $total_earnings += (float)$shift['pay'];
}
$whole_hours = floor($total_hours);
$minutes = round(($total_hours - $whole_hours) * 60);
$formatted_total_hours = "{$whole_hours} hr {$minutes} mins";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Open Rota">
<link rel="icon" type="image/png" href="/rota-app-main/images/icon.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Shifts</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="../css/shifts.css">
</head>
<body>
<div class="container">
    <h1>Your Shifts</h1>

    <div class="hoursandearnings">
    <h3>Hours and Earnings</h3>
    <section class="earnings">
        <form method="GET">
            <label for="period">Select period: </label>
            <select name="period" id="period" onchange="this.form.submit()">
                <option value="week"  <?php echo ($period == 'week')  ? 'selected' : ''; ?>>Week</option>
                <option value="month" <?php echo ($period == 'month') ? 'selected' : ''; ?>>Month</option>
                <option value="year"  <?php echo ($period == 'year')  ? 'selected' : ''; ?>>Year</option>
            </select>
            <input type="hidden" name="weekStart" value="<?php echo htmlspecialchars($weekStart); ?>">
            <input type="hidden" name="month" value="<?php echo htmlspecialchars($month); ?>">
            <input type="hidden" name="year" value="<?php echo htmlspecialchars($year); ?>">
        </form>

        <?php if ($period == 'week'): ?>
            <?php $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days')); ?>
            <p>Currently viewing week from <?php echo date('D, j M Y', strtotime($weekStart)); ?> to <?php echo date('D, j M Y', strtotime($weekEnd)); ?></p>
        <?php elseif ($period == 'month'): ?>
            <p>Currently viewing <?php echo date('F', mktime(0, 0, 0, $month, 1)); ?> <?php echo $year; ?></p>
        <?php else: ?>
            <p>Currently viewing <?php echo $year; ?></p>
        <?php endif; ?>

        <p>
            <?php if ($period == 'week'): ?>
                <a href="?period=week&weekStart=<?php echo date('Y-m-d', strtotime($weekStart . ' -7 days')); ?>">Previous Week</a> |
                <a href="?period=week&weekStart=<?php echo date('Y-m-d', strtotime($weekStart . ' +7 days')); ?>">Next Week</a>
            <?php elseif ($period == 'month'): ?>
                <?php
                  $prevMonth = $month - 1;
                  $prevYear = $year;
                  if ($prevMonth < 1) { $prevMonth = 12; $prevYear -= 1; }
                  $nextMonth = $month + 1;
                  $nextYear = $year;
                  if ($nextMonth > 12) { $nextMonth = 1; $nextYear += 1; }
                ?>
                <a href="?period=month&month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>">Previous Month</a> |
                <a href="?period=month&month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>">Next Month</a>
            <?php else: ?>
                <a href="?period=year&year=<?php echo $year - 1; ?>">Previous Year</a> |
                <a href="?period=year&year=<?php echo $year + 1; ?>">Next Year</a>
            <?php endif; ?>
        </p>

        <p>
            Total Hours Worked: <?php echo $formatted_total_hours; ?><br>
            Total Earnings: £<?php echo number_format($total_earnings, 2); ?>
        </p>
    </section>

   
    <button id="toggleAddShiftBtn">Add Shift</button>
    <div id="addShiftSection" style="display:none;">
        <form id="addShiftForm" method="POST" action="../functions/add_shift.php">
            <div class="add-shift-form">
            <p>
                <label for="shift_date">Date:</label>
                <input type="date" name="shift_date" required />
            </p>
            <p>
                <label for="start_time">Start:</label>
                <input type="time" name="start_time" required />
            </p>
            <p>
                <label for="end_time">End:</label>
                <input type="time" name="end_time" required />
            </p>
            <p>
                <label for="role_id">Role:</label>
                <select name="role_id" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>">
                            <?php echo htmlspecialchars($role['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
           
            <p>
    <label for="location">Location:</label>
    <input class="loctation-box" type="text" name="location" required />
</p>
</div>
            
            <button class="save-shift-btn" type="submit">Save Shift</button>
        </form>
        </div>
        <h3>Shifts</h3>
    <!-- Edit Shift Modal (hidden by default) -->
    <div id="editShiftModal" style="display:none;">
        <form id="editShiftForm" method="POST" action="../functions/edit_shift.php">
            <input type="hidden" name="shift_id" id="edit_shift_id">
            <p>
                <label for="edit_shift_date">Date:</label>
                <input type="date" name="shift_date" id="edit_shift_date" required />
            </p>
            <p>
                <label for="edit_start_time">Start:</label>
                <input type="time" name="start_time" id="edit_start_time" required />
            </p>
            <p>
                <label for="edit_end_time">End:</label>
                <input type="time" name="end_time" id="edit_end_time" required />
            </p>
            <p>
    <label for="edit_location">Location:</label>
    <input type="text" name="location" id="edit_location" required />
</p>
            <p>
                <label for="edit_role_id">Role:</label>
                <select name="role_id" id="edit_role_id" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>">
                            <?php echo htmlspecialchars($role['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <button type="submit">Update Shift</button>
        </form>
    </div>

    <section>
        <?php if (count($shifts) > 0): ?>
            <table>
                <tr>
                    <th>Shift Date</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Role</th>
                    <th>Location</th>
                    <th>Pay</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($shifts as $shift): ?>
                    <?php 
                        $formattedDate  = date("l, F j, Y", strtotime($shift['shift_date']));
                        $formattedStart = date("g:i A", strtotime($shift['start_time']));
                        $formattedEnd   = date("g:i A", strtotime($shift['end_time']));
                    ?>
                    <tr>
                        <!-- Shift Date -->
    <td data-raw-date="<?php echo $shift['shift_date']; ?>">
        <?php echo $formattedDate; ?>
    </td>
    <!-- Start Time -->
    <td data-raw-start="<?php echo $shift['start_time']; ?>">
        <?php echo $formattedStart; ?>
    </td>
    <!-- End Time -->
    <td data-raw-end="<?php echo $shift['end_time']; ?>">
        <?php echo $formattedEnd; ?>
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
        £<?php echo number_format($shift['pay'], 2); ?>
    </td>
    <!-- Actions -->
    <td>
        <button class="editBtn" data-id="<?php echo $shift['id']; ?>">Edit</button>
        <button class="deleteBtn" data-id="<?php echo $shift['id']; ?>">Delete</button>
    </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No shifts found for the selected period.</p>
        <?php endif; ?>
    </section>
</div>

<script>
$(document).ready(function() {
    $("#toggleAddShiftBtn").on("click", function() {
        $("#addShiftSection").toggle();
    });

    $(".editBtn").on("click", function() {
        if ($("#editShiftModal").is(":visible")) {
            $("#editShiftModal").hide();
            return;
        }
        var row = $(this).closest("tr");
        $("#edit_shift_id").val($(this).data("id"));
        $("#edit_shift_date").val(row.find("td:eq(0)").data("raw-date"));
        $("#edit_start_time").val(row.find("td:eq(1)").data("raw-start"));
        $("#edit_end_time").val(row.find("td:eq(2)").data("raw-end"));
        $("#edit_location").val(row.find("td:eq(4)").data("raw-location"));
        $("#edit_role_id").val(row.find("td:eq(3)").data("raw-role"));
        $("#editShiftModal").show();
    });

    $(".deleteBtn").on("click", function() {
        var shiftId = $(this).data("id");
        if (confirm("Are you sure you want to delete this shift?")) {
            $.ajax({
                url: '../functions/delete_shift.php',
                type: 'POST',
                data: { shift_id: shiftId },
                success: function(response) {
                    alert(response);
                    location.reload();
                },
                error: function(xhr, status, error) {
                    alert("An error occurred: " + error);
                }
            });
        }
    });
});
</script>
</body>
</html>