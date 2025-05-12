<?php
require '../includes/auth.php';
requireAdmin();
require_once '../includes/db.php';

// Check if we're managing shifts for a specific user
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
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
    SELECT s.*, u.username, r.name as role_name
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    JOIN roles r ON s.role_id = r.id
    WHERE $periodSql $userSql
    ORDER BY s.shift_date ASC, s.start_time ASC
";

$shifts = $conn->query($shiftsQuery)->fetchAll(PDO::FETCH_ASSOC);

// If form submitted for shift deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_shift'])) {
    $shift_id = (int)$_POST['shift_id'];
    
    try {
        $stmt = $conn->prepare("DELETE FROM shifts WHERE id = ?");
        $deleted = $stmt->execute([$shift_id]);
        
        if ($deleted) {
            $successMessage = "Shift deleted successfully";
            // Refresh shifts list
            $shifts = $conn->query($shiftsQuery)->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $errorMessage = "Error deleting shift";
        }
    } catch (PDOException $e) {
        $errorMessage = "Database error: " . $e->getMessage();
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        @font-face {
            font-family: 'newFont';
            src: url('../fonts/CooperHewitt-Book.otf');
            font-weight: normal;
            font-style: normal;
        }

        body {
            font-family: 'newFont', Arial, sans-serif;
            background: url('../images/backg3.jpg') no-repeat center center fixed;
            background-size: cover;
            margin: 0;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #f4f4f4;
            padding-bottom: 15px;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        h1 {
            color: #fd2b2b;
            margin: 0;
            font-size: 1.8rem;
        }

        .user-filter {
            color: #666;
            font-weight: normal;
            font-size: 1rem;
            margin-left: 10px;
        }

        .action-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: #555;
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            transition: background-color 0.3s, transform 0.2s;
        }

        .action-button:hover {
            background-color: #444;
            transform: translateY(-2px);
        }

        .action-button.primary {
            background-color: #fd2b2b;
        }

        .action-button.primary:hover {
            background-color: #e61919;
        }

        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 30px;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            align-items: center;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        label {
            font-weight: 600;
            color: #555;
        }

        select, input[type="date"] {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: white;
        }

        .filter-button {
            padding: 8px 15px;
            background-color: #fd2b2b;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.2s;
        }

        .filter-button:hover {
            background-color: #e61919;
            transform: translateY(-2px);
        }

        .period-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .period-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
        }

        .period-buttons {
            display: flex;
            gap: 10px;
        }

        .shifts-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        .shifts-table th {
            background-color: #fd2b2b;
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }

        .shifts-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }

        .shifts-table tr:hover {
            background-color: #f8f8f8;
        }

        .date-group-header {
            background-color: #f0f0f0;
            color: #333;
            font-weight: 600;
            text-align: center;
            padding: 8px 15px;
        }

        .shift-actions {
            display: flex;
            gap: 5px;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            color: white;
            border: none;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .btn-icon:hover {
            transform: translateY(-2px);
        }

        .btn-edit {
            background-color: #007bff;
        }

        .btn-delete {
            background-color: #dc3545;
        }

        .empty-state {
            text-align: center;
            padding: 40px 0;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 15px;
            display: block;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px 15px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .shifts-table {
                display: block;
                overflow-x: auto;
            }
            
            .period-navigation {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="page-title">
                <h1><i class="fas fa-calendar-alt"></i> Manage Shifts</h1>
                <?php if ($username): ?>
                    <span class="user-filter">
                        for <?php echo htmlspecialchars($username); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div>
                <a href="admin_dashboard.php" class="action-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <a href="add_shift.php" class="action-button primary">
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
        
        <div class="filter-bar">
            <div class="filter-group">
                <label for="period">View:</label>
                <select id="period" onchange="updatePeriod()">
                    <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Week</option>
                    <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Month</option>
                    <option value="range" <?php echo $period === 'range' ? 'selected' : ''; ?>>Date Range</option>
                </select>
            </div>
            
            <div class="filter-group" id="week-filter" style="<?php echo $period !== 'week' ? 'display: none;' : ''; ?>">
                <label for="week_start">Week Starting:</label>
                <input type="date" id="week_start" value="<?php echo $currentWeekStart; ?>">
            </div>
            
            <div class="filter-group" id="month-filter" style="<?php echo $period !== 'month' ? 'display: none;' : ''; ?>">
                <label for="month">Month:</label>
                <select id="month">
                    <?php for($m=1; $m<=12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m == $currentMonth ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0, 0, 0, $m, 1, date('Y'))); ?>
                    </option>
                    <?php endfor; ?>
                </select>
                <label for="year">Year:</label>
                <select id="year">
                    <?php for($y=date('Y')-2; $y<=date('Y')+1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $currentYear ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="filter-group" id="range-filter" style="<?php echo $period !== 'range' ? 'display: none;' : ''; ?>">
                <label for="start_date">From:</label>
                <input type="date" id="start_date" value="<?php echo $startDate; ?>">
                <label for="end_date">To:</label>
                <input type="date" id="end_date" value="<?php echo $endDate; ?>">
            </div>
            
            <div class="filter-group">
                <label for="user_filter">User:</label>
                <select id="user_filter">
                    <option value="">All Users</option>
                    <?php foreach($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>" <?php echo $user_id == $user['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($user['username']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button class="filter-button" onclick="applyFilters()">
                <i class="fas fa-filter"></i> Apply Filters
            </button>
        </div>
        
        <div class="period-navigation">
            <div class="period-title">
                <i class="fas fa-calendar"></i> <?php echo htmlspecialchars($periodDesc); ?>
            </div>
            
            <div class="period-buttons">
                <?php if ($period === 'week'): ?>
                    <a href="?period=week&week_start=<?php echo $prevWeekStart; ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>" class="action-button">
                        <i class="fas fa-chevron-left"></i> Previous Week
                    </a>
                    <a href="?period=week&week_start=<?php echo date('Y-m-d', strtotime('monday this week')); ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>" class="action-button">
                        Current Week
                    </a>
                    <a href="?period=week&week_start=<?php echo $nextWeekStart; ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>" class="action-button">
                        Next Week <i class="fas fa-chevron-right"></i>
                    </a>
                <?php elseif ($period === 'month'): ?>
                    <a href="?period=month&month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>" class="action-button">
                        <i class="fas fa-chevron-left"></i> Previous Month
                    </a>
                    <a href="?period=month&month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>" class="action-button">
                        Current Month
                    </a>
                    <a href="?period=month&month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?><?php echo $user_id ? "&user_id=$user_id" : ''; ?>" class="action-button">
                        Next Month <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (empty($shifts)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <p>No shifts found for the selected period</p>
                <a href="add_shift.php<?php echo $user_id ? "?user_id=$user_id" : ''; ?>" class="action-button primary">
                    <i class="fas fa-plus"></i> Add Shift
                </a>
            </div>
        <?php else: ?>
            <table class="shifts-table">
                <thead>
                    <tr>
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
                            <td colspan="6" class="date-group-header">
                                <i class="fas fa-calendar-day"></i>
                                <?php echo date('l, F j, Y', strtotime($shift_date)); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td><?php echo htmlspecialchars($shift['username']); ?></td>
                        <td><?php echo date('D, M j', strtotime($shift['shift_date'])); ?></td>
                        <td><?php echo date('g:i A', strtotime($shift['start_time'])); ?> - <?php echo date('g:i A', strtotime($shift['end_time'])); ?></td>
                        <td><?php echo htmlspecialchars($shift['role_name']); ?></td>
                        <td><?php echo htmlspecialchars($shift['location']); ?></td>
                        <td class="shift-actions">
                            <a href="edit_shift.php?id=<?php echo $shift['id']; ?>" class="btn-icon btn-edit" title="Edit shift">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this shift?');">
                                <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                                <button type="submit" name="delete_shift" class="btn-icon btn-delete" title="Delete shift">
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