<?php
require_once '../includes/db.php';
require '../includes/auth.php';
require_once '../functions/addNotification.php';
requireAdmin();

// Check if a user_id filter is being applied:
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
$user = ['username' => 'All Users']; // Default value for when no user_id is provided
if ($user_id) {
    $stmtUser = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $user = ['username' => 'Unknown User'];
    }
}

// --- Filtering Setup ---
$period = $_GET['period'] ?? 'week';
$bindings = [];
if ($period === 'day') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $filterCondition = "shift_date = :date";
    $bindings[':date'] = $date;
} elseif ($period === 'week') {
    $weekStart = $_GET['weekStart'] ?? date('Y-m-d', strtotime('last Saturday'));
    $filterCondition = "shift_date BETWEEN :weekStart AND DATE_ADD(:weekStart, INTERVAL 6 DAY)";
    $bindings[':weekStart'] = $weekStart;
} elseif ($period === 'month') {
    $month = $_GET['month'] ?? date('n');
    $year = $_GET['year'] ?? date('Y');
    $filterCondition = "MONTH(shift_date) = :month AND YEAR(shift_date) = :year";
    $bindings[':month'] = $month;
    $bindings[':year'] = $year;
} else {
    $filterCondition = "1=1";
}

// Fetch all users for displaying usernames in the shifts table
$stmtUsers = $conn->query("SELECT id, username FROM users");
$users = [];
while ($userData = $stmtUsers->fetch(PDO::FETCH_ASSOC)) {
    $users[$userData['id']] = $userData['username'];
}
if ($user_id) {
    $userFilter = "AND user_id = :user_id";
    $bindings[':user_id'] = $user_id;
} else {
    $userFilter = "";
}

// Process form submissions for add/edit/delete actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // DELETE ACTION
    if ($_POST['action'] == 'delete' && isset($_POST['shift_id'])) {
        $stmtShift = $conn->prepare("SELECT user_id, shift_date, start_time, end_time FROM shifts WHERE id = ?");
        $stmtShift->execute([$_POST['shift_id']]);
        $shiftData = $stmtShift->fetch(PDO::FETCH_ASSOC);
        $targetUser = $shiftData['user_id'] ?? $_SESSION['user_id'];
        $formattedDate = date("D, M j, Y", strtotime($shiftData['shift_date']));
        $formattedStart = date("g:i A", strtotime($shiftData['start_time']));
        $formattedEnd = date("g:i A", strtotime($shiftData['end_time']));
        $stmtDel = $conn->prepare("DELETE FROM shifts WHERE id = ?" . ($user_id ? " AND user_id = ?" : ""));
        $params = [$_POST['shift_id']];
        if ($user_id) {
            $params[] = $user_id;
        }
        $stmtDel->execute($params);
        $notifMessage = "Your shift on {$formattedDate} from {$formattedStart} to {$formattedEnd} has been deleted by management.";
        addNotification($conn, $targetUser, $notifMessage, "info");
    }
    // EDIT ACTION
    elseif ($_POST['action'] == 'edit' && isset($_POST['shift_id'])) {
        $stmtEdit = $conn->prepare("UPDATE shifts SET shift_date = ?, start_time = ?, end_time = ?, role_id = ?, location = ? WHERE id = ?" . ($user_id ? " AND user_id = ?" : ""));
        $params = [
            $_POST['shift_date'],
            $_POST['start_time'],
            $_POST['end_time'],
            $_POST['role_id'],
            $_POST['location'],
            $_POST['shift_id']
        ];
        if ($user_id) {
            $params[] = $user_id;
        }
        $stmtEdit->execute($params);
        
        $stmtShift = $conn->prepare("SELECT user_id, shift_date, start_time, end_time FROM shifts WHERE id = ?");
        $stmtShift->execute([$_POST['shift_id']]);
        $shiftData = $stmtShift->fetch(PDO::FETCH_ASSOC);
        $targetUser = $shiftData['user_id'] ?? $_SESSION['user_id'];
        $formattedDate = date("D, M j, Y", strtotime($shiftData['shift_date']));
        $formattedStart = date("g:i A", strtotime($shiftData['start_time']));
        $formattedEnd = date("g:i A", strtotime($shiftData['end_time']));
        $notifMessage = "Your shift on {$formattedDate} from {$formattedStart} to {$formattedEnd} has been updated by management.";
        addNotification($conn, $targetUser, $notifMessage, "info");
    }
    // ADD ACTION
    elseif ($_POST['action'] == 'add') {
        $stmtAdd = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location) VALUES (?, ?, ?, ?, ?, ?)");
        $targetUser = $user_id ? $user_id : ($_POST['user_id'] ?? $_SESSION['user_id']);
        $stmtAdd->execute([
            $targetUser,
            $_POST['shift_date'],
            $_POST['start_time'],
            $_POST['end_time'],
            $_POST['role_id'],
            $_POST['location']
        ]);
        $formattedDate = date("D, M j, Y", strtotime($_POST['shift_date']));
        $formattedStart = date("g:i A", strtotime($_POST['start_time']));
        $formattedEnd = date("g:i A", strtotime($_POST['end_time']));
        $notifMessage = "A new shift on {$formattedDate} from {$formattedStart} to {$formattedEnd} has been added to your schedule by management.";
        addNotification($conn, $targetUser, $notifMessage, "info");
    }
}

$query = "SELECT * FROM shifts WHERE 1=1 AND $filterCondition $userFilter ORDER BY shift_date ASC, start_time ASC";
$params = array_merge([], $bindings);
$stmtShifts = $conn->prepare($query);
$stmtShifts->execute($params);
$shifts = $stmtShifts->fetchAll(PDO::FETCH_ASSOC);

$stmtRoles = $conn->query("SELECT id, name FROM roles");
$roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Open Rota">
<link rel="icon" type="image/png" href="/rota-app-main/icon.png">
<link rel="manifest" href="/rota-app-main/manifest.json">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title><?php echo $user_id ? "Manage " . htmlspecialchars($user['username']) . "'s Shifts" : "Manage All Shifts"; ?></title>
    <link rel="stylesheet" href="../css/manage_shifts.css">
</head>
<body>
    <div class="container">
        <h1><?php echo $user_id ? "Manage " . htmlspecialchars($user['username']) . "'s Shifts" : "Manage All Shifts"; ?></h1>
        <a href="admin_dashboard.php" class="action-button">Back to Dashboard</a>
        
        <!-- Filter Controls -->
        <form method="GET" action="manage_shifts.php" style="margin-bottom:20px;">
            <?php if ($user_id): ?>
                <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
            <?php endif; ?>
            <label for="period">Filter By: </label>
            <select name="period" id="period" onchange="this.form.submit()">
                <option value="day" <?php echo ($period === 'day') ? 'selected' : ''; ?>>Day</option>
                <option value="week" <?php echo ($period === 'week') ? 'selected' : ''; ?>>Week</option>
                <option value="month" <?php echo ($period === 'month') ? 'selected' : ''; ?>>Month</option>
            </select>
            <?php if ($period === 'day'): ?>
                <input type="date" name="date" value="<?php echo $_GET['date'] ?? date('Y-m-d'); ?>" onchange="this.form.submit()">
            <?php elseif ($period === 'week'): ?>
                <input type="date" name="weekStart" value="<?php echo $_GET['weekStart'] ?? $weekStart; ?>" onchange="this.form.submit()">
            <?php elseif ($period === 'month'): ?>
                <select name="month" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo ((isset($_GET['month']) ? $_GET['month'] : date('n')) == $m) ? 'selected' : ''; ?>>
                            <?php echo date("F", mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <input type="number" name="year" value="<?php echo $_GET['year'] ?? date('Y'); ?>" min="2000" max="2100" onchange="this.form.submit()">
            <?php endif; ?>
            <noscript><button type="submit">Filter</button></noscript>
        </form>

        <?php
// Navigation for "day" filter:
if ($period === 'day'):
    // Get the current date (from GET or today's date)
    $currentDay = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    $currentDate = DateTime::createFromFormat('Y-m-d', $currentDay);
    $prevDay = clone $currentDate;
    $prevDay->modify('-1 day');
    $nextDay = clone $currentDate;
    $nextDay->modify('+1 day');
?>
<div class="day-navigation" style="margin:20px 0; text-align:center;">
    <a href="manage_shifts.php?period=day&date=<?php echo $prevDay->format('Y-m-d'); ?><?php echo $user_id ? '&user_id=' . $user_id : ''; ?>" class="nav-button">Previous Day</a>
    <a href="manage_shifts.php?period=day&date=<?php echo date('Y-m-d'); ?><?php echo $user_id ? '&user_id=' . $user_id : ''; ?>" class="nav-button">Current Day</a>
    <a href="manage_shifts.php?period=day&date=<?php echo $nextDay->format('Y-m-d'); ?><?php echo $user_id ? '&user_id=' . $user_id : ''; ?>" class="nav-button">Next Day</a>
</div>
<?php endif; ?>


<?php
// Navigation for "week" filter:
if ($period === 'week'):
    $currentDate = new DateTime($weekStart);
    $prevWeek = clone $currentDate;
    $prevWeek->modify('-7 days');
    $nextWeek = clone $currentDate;
    $nextWeek->modify('+7 days');
    // For "current week" we might use today's date adjusted to the desired start day (for example, last Saturday)
    $currentWeekStart = date('Y-m-d', strtotime('last Saturday'));
?>
<div class="week-navigation" style="margin:20px 0; text-align:center;">
    <a href="manage_shifts.php?period=week&weekStart=<?php echo $prevWeek->format('Y-m-d'); ?><?php echo $user_id ? '&user_id=' . $user_id : ''; ?>" class="nav-button">Previous Week</a>
    <a href="manage_shifts.php?period=week&weekStart=<?php echo $currentWeekStart; ?><?php echo $user_id ? '&user_id=' . $user_id : ''; ?>" class="nav-button">Current Week</a>
    <a href="manage_shifts.php?period=week&weekStart=<?php echo $nextWeek->format('Y-m-d'); ?><?php echo $user_id ? '&user_id=' . $user_id : ''; ?>" class="nav-button">Next Week</a>
</div>
<?php endif; ?>

<?php
// Navigation for "month" filter:
if ($period === 'month'):
    $currentMonth = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
    $currentYear = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
    $currentDate = DateTime::createFromFormat('Y-n-d', "{$currentYear}-{$currentMonth}-1");
    $prevMonthDate = clone $currentDate;
    $prevMonthDate->modify('-1 month');
    $nextMonthDate = clone $currentDate;
    $nextMonthDate->modify('+1 month');
    $prevMonth = $prevMonthDate->format('n');
    $prevYear = $prevMonthDate->format('Y');
    $nextMonth = $nextMonthDate->format('n');
    $nextYear = $nextMonthDate->format('Y');
    $curMonth = date('n');
    $curYear = date('Y');
?>
<div class="month-navigation" style="margin:20px 0; text-align:center;">
    <a href="manage_shifts.php?period=month&month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?><?php echo $user_id ? '&user_id=' . $user_id : ''; ?>" class="nav-button">Previous Month</a>
    <a href="manage_shifts.php?period=month&month=<?php echo $curMonth; ?>&year=<?php echo $curYear; ?><?php echo $user_id ? '&user_id=' . $user_id : ''; ?>" class="nav-button">Current Month</a>
    <a href="manage_shifts.php?period=month&month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?><?php echo $user_id ? '&user_id=' . $user_id : ''; ?>" class="nav-button">Next Month</a>
</div>

<?php endif; ?>
        
        <!-- Table of Shifts -->
        <table>
            <thead>
                <tr>
                <?php if (!$user_id): ?>
                        <th>User</th>
                    <?php endif; ?>
                    <th>Shift Date</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Role</th>
                    <th>Location</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody class="shifts_display">
                <?php 
                $colspan = $user_id ? 7 : 8;
                $currentGroup = ""; // Initialize current grouping key
                if (!$shifts) {
                    echo "<tr><td colspan='{$colspan}'>No shifts found for the selected period.</td></tr>";
                } else {
                    foreach ($shifts as $shift):
                        // Determine the group key and label based on $period:
                        if ($period === 'day' || $period === 'week') {
                            $groupKey = date("Y-m-d", strtotime($shift['shift_date']));
                            $groupLabel = date("l, F j, Y", strtotime($shift['shift_date']));
                        } elseif ($period === 'month') {
                            $groupKey = date("W-Y", strtotime($shift['shift_date']));
                            $groupLabel = "Week " . date("W", strtotime($shift['shift_date'])) . " of " . date("Y", strtotime($shift['shift_date']));
                        } else {
                            $groupKey = "";
                            $groupLabel = "";
                        }
                        
                        // Output a group header row if this shift starts a new group.
                        if ($groupKey !== $currentGroup) {
                            $currentGroup = $groupKey;
                            echo "<tr class='group-header'><td colspan='{$colspan}' style='background:#eee; font-weight:bold; text-align:center;'>{$groupLabel}</td></tr>";
                        }
                ?>
                    <tr>
                        <?php if (!$user_id): ?>
                            <td><?php echo isset($users[$shift['user_id']]) ? htmlspecialchars($users[$shift['user_id']]) : 'Unknown User'; ?></td>
                        <?php endif; ?>
                        <td><?php echo date("D, M j, Y", strtotime($shift['shift_date'])); ?></td>
                        <td><?php echo date("g:i A", strtotime($shift['start_time'])); ?></td>
                        <td><?php echo date("g:i A", strtotime($shift['end_time'])); ?></td>
                        <td>
                            <?php 
                            foreach ($roles as $role) { 
                                if ($role['id'] == $shift['role_id']) {
                                    echo htmlspecialchars($role['name']);
                                    break;
                                }
                            }
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($shift['location']); ?></td>
                        <td>
                            <!-- Edit Shift form -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                                <input type="hidden" name="action" value="edit">
                                <input type="date" name="shift_date" value="<?php echo $shift['shift_date']; ?>">
                                <input type="time" name="start_time" value="<?php echo $shift['start_time']; ?>">
                                <input type="time" name="end_time" value="<?php echo $shift['end_time']; ?>">
                                <select name="role_id">
                                    <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>" <?php if($shift['role_id'] == $role['id']) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="location" value="<?php echo htmlspecialchars($shift['location']); ?>">
                                <button type="submit">Update</button>
                            </form>
                            <!-- Delete Shift form -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" onclick="return confirm('Delete this shift?');">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; 
                } 
                ?>
            </tbody>
        </table>
        
        <!-- Add New Shift Form -->
        <h2>Add New Shift</h2>
        <form method="POST" class="add_shift_form">
            <input type="hidden" name="action" value="add">
            <?php if (!$user_id): ?>
                <p>
                    <label for="user_id">User:</label>
                    <select name="user_id" id="user_id" required>
                        <option value="">Select a user</option>
                        <?php foreach ($users as $id => $username): ?>
                            <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($username); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>
            <p>
                <label for="new_shift_date">Date:</label>
                <input type="date" id="new_shift_date" name="shift_date" required>
            </p>
            <p>
                <label for="new_start_time">Start Time:</label>
                <input type="time" id="new_start_time" name="start_time" required>
            </p>
            <p>
                <label for="new_end_time">End Time:</label>
                <input type="time" id="new_end_time" name="end_time" required>
            </p>
            <p>
                <label for="new_role_id">Role:</label>
                <select id="new_role_id" name="role_id" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="new_location">Location:</label>
                <input type="text" id="new_location" name="location" required>
            </p>
            <p>
                <button type="submit">Add Shift</button>
            </p>
        </form>
    </div>
</body>
</html>