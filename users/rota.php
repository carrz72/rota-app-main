<?php
require_once '../includes/auth.php';
requireLogin();
include_once '../includes/header.php';
require_once '../includes/db.php';

// Determine filtering period from GET parameters (default to week)
$period = $_GET['period'] ?? 'week';

// Set up filtering conditions and variables
if ($period === 'week') {
    if (isset($_GET['weekStart'])) {
        $weekStart = $_GET['weekStart'];
        // Use the provided weekStart date
    } else {
        // Default to last Saturday as week start
        $weekStart = date('Y-m-d', strtotime('last Saturday'));
    }
    $weekEnd = date('Y-m-d', strtotime("$weekStart +6 days"));
    $periodSql = "shift_date BETWEEN :weekStart AND DATE_ADD(:weekStart, INTERVAL 6 DAY)";
    $bindings = [':weekStart' => $weekStart];
} elseif ($period === 'month') {
    $month = $_GET['month'] ?? date('n');
    $year = $_GET['year'] ?? date('Y');
    $periodSql = "MONTH(shift_date) = :month AND YEAR(shift_date) = :year";
    $bindings = [':month' => $month, ':year' => $year];
} elseif ($period === 'year') {
    $year = $_GET['year'] ?? date('Y');
    $periodSql = "YEAR(shift_date) = :year";
    $bindings = [':year' => $year];
} else {
    // Default fallback
    $period = 'week';
    $weekStart = date('Y-m-d', strtotime('last Saturday'));
    $periodSql = "shift_date BETWEEN :weekStart AND DATE_ADD(:weekStart, INTERVAL 6 DAY)";
    $bindings = [':weekStart' => $weekStart];
}

// Query shifts for ALL users for the selected period
$query = "
    SELECT s.*, u.username, r.name AS role_name, r.base_pay, r.has_night_pay, 
           r.night_shift_pay, r.night_start_time, r.night_end_time
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    JOIN roles r ON s.role_id = r.id
    WHERE $periodSql
    ORDER BY s.shift_date ASC, s.start_time ASC
";
$stmt = $conn->prepare($query);
foreach ($bindings as $param => $value) {
    $stmt->bindValue($param, $value);
}
$stmt->execute();
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Open Rota">
<link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Full Rota</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/rota.css">
</head>
<body>
    <div class="container">
        <h1>Full Rota</h1>
        
        <!-- Filtering Controls -->
        <form method="GET">
            <label for="period">Select period: </label>
            <select name="period" id="period" onchange="this.form.submit()">
                <option value="week" <?php echo ($period == 'week') ? 'selected' : ''; ?>>Week</option>
                <option value="month" <?php echo ($period == 'month') ? 'selected' : ''; ?>>Month</option>
                <option value="year" <?php echo ($period == 'year') ? 'selected' : ''; ?>>Year</option>
            </select>
            <?php if ($period === 'week'): ?>
                <input type="date" name="weekStart" value="<?php echo htmlspecialchars($weekStart); ?>" onchange="this.form.submit()">
                <span>Viewing week from <?php echo date('D, j M Y', strtotime($weekStart)); ?> to <?php echo date('D, j M Y', strtotime($weekEnd)); ?></span>
            <?php elseif ($period === 'month'): ?>
                <select name="month" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo (($month ?? date('n')) == $m) ? 'selected' : ''; ?>>
                            <?php echo date("F", mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <input type="number" name="year" value="<?php echo htmlspecialchars($year); ?>" min="2000" max="2100" onchange="this.form.submit()">
                <span>Viewing <?php echo date("F", mktime(0,0,0,$month,1)); ?> <?php echo $year; ?></span>
            <?php elseif ($period === 'year'): ?>
                <input type="number" name="year" value="<?php echo htmlspecialchars($year); ?>" min="2000" max="2100" onchange="this.form.submit()">
              
                <span class="viewing">Viewing <?php echo $year; ?></span>
            <?php endif; ?>
            <noscript><button type="submit">Filter</button></noscript>
          
        </form>
        
        <!-- Navigation Buttons for Week Period -->
        <?php if ($period === 'week'): ?>
            <p>
                <a href="?period=week&weekStart=<?php echo date('Y-m-d', strtotime($weekStart . ' -7 days')); ?>">Previous Week</a> |
                <a href="?period=week&weekStart=<?php echo date('Y-m-d'); ?>">Current Week</a> |
                <a href="?period=week&weekStart=<?php echo date('Y-m-d', strtotime($weekStart . ' +7 days')); ?>">Next Week</a>
            </p>
        <?php endif; ?>

<?php if ($period === 'month'): ?>
    <p>
        <a href="?period=month&month=<?php echo $month == 1 ? 12 : $month - 1; ?>&year=<?php echo $month == 1 ? $year - 1 : $year; ?>">Previous Month</a> |
        <a href="?period=month&month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>">Current Month</a> |
        <a href="?period=month&month=<?php echo $month == 12 ? 1 : $month + 1; ?>&year=<?php echo $month == 12 ? $year + 1 : $year; ?>">Next Month</a>
    </p>
<?php endif; ?>

        <?php if ($period === 'year'): ?>
    <p>
        <a href="?period=year&year=<?php echo $year - 1; ?>">Previous Year</a> |
        <a href="?period=year&year=<?php echo date('Y'); ?>">Current Year</a> |
        <a href="?period=year&year=<?php echo $year + 1; ?>">Next Year</a>
    </p>
<?php endif; ?>
        
        <?php if (!empty($shifts)): ?>
    <section class="upcoming-shifts">
        <h3>Shifts for Selected Period</h3>
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Role</th>
                    <th>Location</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $lastDate = ''; 
                foreach ($shifts as $shift): 
                    $currentDate = date("Y-m-d", strtotime($shift['shift_date']));
                    if ($currentDate !== $lastDate): 
                        // Output a day separator row.
                        $lastDate = $currentDate;
                ?>
                    <tr class="day-separator">
                        <td colspan="5"><?php echo date("l, F j, Y", strtotime($shift['shift_date'])); ?></td>
                    </tr>
                <?php endif; ?>
                    <tr>
                        <td><?php echo htmlspecialchars($shift['username']); ?></td>
                        <td><?php echo date("g:i A", strtotime($shift['start_time'])); ?></td>
                        <td><?php echo date("g:i A", strtotime($shift['end_time'])); ?></td>
                        <td><?php echo htmlspecialchars($shift['role_name']); ?></td>
                        <td><?php echo htmlspecialchars($shift['location']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php else: ?>
    <p class="no-shifts">No shifts scheduled for the selected period.</p>
<?php endif; ?>
    </div>
    
    <script src="/rota-app-main/js/menu.js"></script>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>
</html>