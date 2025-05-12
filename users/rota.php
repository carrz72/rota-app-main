<?php
require_once '../includes/auth.php';
requireLogin();
include_once '../includes/header.php';
require_once '../includes/db.php';

// Determine filtering period from GET parameters (default to week)
$period = $_GET['period'] ?? 'week';
$view = $_GET['view'] ?? 'list'; // Add view option: list or calendar

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

// Get all available roles for filter
$roleStmt = $conn->prepare("SELECT id, name FROM roles ORDER BY name");
$roleStmt->execute();
$allRoles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all locations for filter
$locationStmt = $conn->prepare("SELECT DISTINCT location FROM shifts ORDER BY location");
$locationStmt->execute();
$allLocations = $locationStmt->fetchAll(PDO::FETCH_ASSOC);

// Add filters for role and location
$roleFilter = $_GET['role_filter'] ?? '';
$locationFilter = $_GET['location_filter'] ?? '';

// Modify the SQL query to include filters
$filterConditions = [];
if ($roleFilter) {
    $filterConditions[] = "r.id = :role_id";
    $bindings[':role_id'] = $roleFilter;
}
if ($locationFilter) {
    $filterConditions[] = "s.location = :location";
    $bindings[':location'] = $locationFilter;
}

// Combine all filter conditions
$sql = "WHERE $periodSql";
if (!empty($filterConditions)) {
    $sql .= " AND " . implode(" AND ", $filterConditions);
}

// Query shifts for ALL users for the selected period
$query = "
    SELECT s.*, u.username, r.name AS role_name, r.base_pay, r.has_night_pay, 
           r.night_shift_pay, r.night_start_time, r.night_end_time
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    JOIN roles r ON s.role_id = r.id
    $sql
    ORDER BY s.shift_date ASC, s.start_time ASC
";

$stmt = $conn->prepare($query);
foreach ($bindings as $param => $value) {
    $stmt->bindValue($param, $value);
}
$stmt->execute();
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all role colors for styling shifts by role
$roleColors = [
    'default' => '#fd2b2b', // Default red
    'Manager' => '#3366cc', // Blue
    'Assistant Manager' => '#109618', // Green
    'Supervisor' => '#ff9900', // Orange
    'CSA' => '#990099', // Purple
    'Customer Service Associate' => '#990099', // Purple
    'Barista' => '#0099c6', // Teal
    'Server' => '#dd4477', // Pink
    'Cook' => '#66aa00', // Lime
    'Host' => '#b82e2e', // Dark Red
    'Dishwasher' => '#316395', // Dark Blue
];

// Helper function to organize shifts by date for calendar view
function organizeShiftsByDate($shifts)
{
    $organized = [];
    foreach ($shifts as $shift) {
        $date = $shift['shift_date'];
        if (!isset($organized[$date])) {
            $organized[$date] = [];
        }
        $organized[$date][] = $shift;
    }
    return $organized;
}

// Organize shifts by date
$shiftsByDate = organizeShiftsByDate($shifts);

// Generate dates for the current period for calendar view
$calendarDates = [];
if ($period === 'week') {
    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime("$weekStart +$i days"));
        $calendarDates[] = $date;
    }
} elseif ($period === 'month') {
    $firstDay = date('Y-m-01', strtotime("$year-$month-01"));
    $lastDay = date('Y-m-t', strtotime("$year-$month-01"));
    $currentDate = $firstDay;
    while ($currentDate <= $lastDay) {
        $calendarDates[] = $currentDate;
        $currentDate = date('Y-m-d', strtotime("$currentDate +1 day"));
    }
}
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
    <link rel="stylesheet" href="../css/rota.css">
    <style>
        /* Navigation menu styling specific to rota page */
        .nav-links {
            display: none;
            position: absolute;
            top: 60px;
            right: 10px;
            background: #fd2b2b !important;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            overflow: hidden;
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
            color: #ffffff !important;
            background-color: #fd2b2b !important;
            text-decoration: none;
            white-space: nowrap;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 14px;
        }

        .nav-links ul li:last-child a {
            border-bottom: none;
        }

        /* Enhanced Filter Section */
        .filter-section {
            background-color: #f8f8f8;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            font-size: 14px;
            color: #555;
        }

        .filter-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .view-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .view-toggle button {
            background-color: rgb(42, 42, 42);
            border: 1px solid #ddd;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }

        .view-toggle button.active {
            background-color: #fd2b2b;
            color: white;
            border-color: #fd2b2b;
        }

        /* Calendar View Styles */
        .calendar-view {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
            margin-top: 20px;
        }

        .calendar-day {
            background-color: #fff;
            border-radius: 5px;
            padding: 10px;
            min-height: 120px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .calendar-day-header {
            background-color: #f5f5f5;
            padding: 8px;
            border-radius: 5px 5px 0 0;
            margin: -10px -10px 10px -10px;
            text-align: center;
            font-weight: bold;
        }

        .calendar-day:empty {
            background-color: #f9f9f9;
        }

        .day-number {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 25px;
            height: 25px;
            background-color: #fd2b2b;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
        }

        /* Shift Card in Calendar */
        .shift-card {
            background-color: #f8f8f8;
            border-left: 3px solid #fd2b2b;
            padding: 8px;
            margin-bottom: 8px;
            border-radius: 3px;
            font-size: 12px;
        }

        .shift-card:last-child {
            margin-bottom: 0;
        }

        .shift-time {
            font-weight: bold;
        }

        .shift-user {
            margin-top: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .shift-role {
            font-style: italic;
            font-size: 11px;
            color: #666;
        }

        /* Enhanced Calendar View Responsiveness */
        .calendar-view {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
            margin-top: 20px;
            width: 100%;
            overflow-x: hidden;
        }

        .calendar-day {
            background-color: #fff;
            border-radius: 5px;
            padding: 10px;
            min-height: 120px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: relative;
            overflow-y: auto;
            max-height: 250px;
        }

        /* Fix for calendar overflow at specific breakpoints */
        @media (min-width: 993px) and (max-width: 1200px) {
            .calendar-view {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (min-width: 769px) and (max-width: 992px) {
            .calendar-view {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .calendar-view {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-row {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            /* Improved filter buttons for small screens */
            .filter-row > div {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 5px;
            }
            
            .filter-row a.btn {
                flex: 1 1 auto;
                white-space: nowrap;
                text-align: center;
                min-width: auto;
                padding: 8px 10px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .calendar-view {
                grid-template-columns: 1fr;
            }
            
            /* Additional filter button improvements for very small screens */
            .filter-row > div {
                flex-direction: column;
            }
            
            .filter-row a.btn {
                margin-bottom: 5px;
            }
            
            .filter-group {
                margin-bottom: 10px;
            }
        }

        /* Ensure calendar container has proper padding on all screen sizes */
        #calendar-view {
            padding: 0 5px;
            box-sizing: border-box;
            width: 100%;
        }
        
        /* Shift card with proper overflow handling */
        .shift-card {
            background-color: #f8f8f8;
            border-left: 3px solid #fd2b2b;
            padding: 8px;
            margin-bottom: 8px;
            border-radius: 3px;
            font-size: 12px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Improved responsive adjustments */
        @media (max-width: 992px) {
            .calendar-view {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                gap: 10px;
            }

            .calendar-view {
                grid-template-columns: repeat(2, 1fr);
            }

            .export-btn {
                width: 100%;
                justify-content: center;
                margin-bottom: 10px;
            }
        }

        @media (max-width: 992px) and (min-width: 769px) {
            .calendar-view {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* Safari-specific fixes */
        @supports (-webkit-touch-callout: none) {
            .nav-links {
                -webkit-transform: translateZ(0);
                transform: translateZ(0);
            }

            .nav-links ul li a {
                -webkit-appearance: none;
                padding: 12px 20px !important;
            }

            @-webkit-keyframes fadeIn {
                from {
                    opacity: 0;
                    -webkit-transform: translateY(-10px);
                }

                to {
                    opacity: 1;
                    -webkit-transform: translateY(0);
                }
            }
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

        /* Print-friendly styles */
        @media print {

            header,
            .filter-section,
            .view-toggle,
            .export-btn,
            button {
                display: none !important;
            }

            body,
            .container {
                background: white !important;
                color: black !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            table {
                width: 100% !important;
                page-break-inside: auto !important;
            }

            tr {
                page-break-inside: avoid !important;
                page-break-after: auto !important;
            }
        }
    </style>
</head>

<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Full Rota</h1>

        <!-- Enhanced Filter Section -->
        <div class="filter-section">
            <form method="GET" id="filterForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="period">Time Period:</label>
                        <select name="period" id="period" onchange="this.form.submit()">
                            <option value="week" <?php echo ($period == 'week') ? 'selected' : ''; ?>>Week</option>
                            <option value="month" <?php echo ($period == 'month') ? 'selected' : ''; ?>>Month</option>
                            <option value="year" <?php echo ($period == 'year') ? 'selected' : ''; ?>>Year</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="role_filter">Filter by Role:</label>
                        <select name="role_filter" id="role_filter" onchange="this.form.submit()">
                            <option value="">All Roles</option>
                            <?php foreach ($allRoles as $role): ?>
                                <option value="<?php echo $role['id']; ?>" <?php echo ($roleFilter == $role['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($role['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="location_filter">Filter by Location:</label>
                        <select name="location_filter" id="location_filter" onchange="this.form.submit()">
                            <option value="">All Locations</option>
                            <?php foreach ($allLocations as $location): ?>
                                <option value="<?php echo $location['location']; ?>" <?php echo ($locationFilter == $location['location']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($location['location']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if ($period === 'week'): ?>
                    <div class="filter-group">
                        <label for="weekStart">Week Starting:</label>
                        <input type="date" name="weekStart" id="weekStart"
                            value="<?php echo htmlspecialchars($weekStart); ?>" onchange="this.form.submit()">
                        <span>Viewing week from <?php echo date('D, j M Y', strtotime($weekStart)); ?> to
                            <?php echo date('D, j M Y', strtotime($weekEnd)); ?></span>
                    </div>
                <?php elseif ($period === 'month'): ?>
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="month">Month:</label>
                            <select name="month" id="month" onchange="this.form.submit()">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo (($month ?? date('n')) == $m) ? 'selected' : ''; ?>>
                                        <?php echo date("F", mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="year">Year:</label>
                            <input type="number" name="year" id="year" value="<?php echo htmlspecialchars($year); ?>"
                                min="2000" max="2100" onchange="this.form.submit()">
                        </div>
                    </div>
                <?php elseif ($period === 'year'): ?>
                    <div class="filter-group">
                        <label for="year">Year:</label>
                        <input type="number" name="year" id="year" value="<?php echo htmlspecialchars($year); ?>" min="2000"
                            max="2100" onchange="this.form.submit()">
                    </div>
                <?php endif; ?>

                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                <noscript><button type="submit" class="btn">Apply Filters</button></noscript>
            </form>

            <!-- Period Navigation Buttons -->
            <div class="filter-row">
                <div>
                    <?php if ($period === 'week'): ?>
                        <a href="?period=week&weekStart=<?php echo date('Y-m-d', strtotime($weekStart . ' -7 days')); ?>&role_filter=<?php echo $roleFilter; ?>&location_filter=<?php echo $locationFilter; ?>&view=<?php echo $view; ?>"
                            class="btn">Previous Week</a>
                        <a href="?period=week&weekStart=<?php echo date('Y-m-d'); ?>&role_filter=<?php echo $roleFilter; ?>&location_filter=<?php echo $locationFilter; ?>&view=<?php echo $view; ?>"
                            class="btn">Current Week</a>
                        <a href="?period=week&weekStart=<?php echo date('Y-m-d', strtotime($weekStart . ' +7 days')); ?>&role_filter=<?php echo $roleFilter; ?>&location_filter=<?php echo $locationFilter; ?>&view=<?php echo $view; ?>"
                            class="btn">Next Week</a>
                    <?php elseif ($period === 'month'): ?>
                        <a href="?period=month&month=<?php echo $month == 1 ? 12 : $month - 1; ?>&year=<?php echo $month == 1 ? $year - 1 : $year; ?>&role_filter=<?php echo $roleFilter; ?>&location_filter=<?php echo $locationFilter; ?>&view=<?php echo $view; ?>"
                            class="btn">Previous Month</a>
                        <a href="?period=month&month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>&role_filter=<?php echo $roleFilter; ?>&location_filter=<?php echo $locationFilter; ?>&view=<?php echo $view; ?>"
                            class="btn">Current Month</a>
                        <a href="?period=month&month=<?php echo $month == 12 ? 1 : $month + 1; ?>&year=<?php echo $month == 12 ? $year + 1 : $year; ?>&role_filter=<?php echo $roleFilter; ?>&location_filter=<?php echo $locationFilter; ?>&view=<?php echo $view; ?>"
                            class="btn">Next Month</a>
                    <?php elseif ($period === 'year'): ?>
                        <a href="?period=year&year=<?php echo $year - 1; ?>&role_filter=<?php echo $roleFilter; ?>&location_filter=<?php echo $locationFilter; ?>&view=<?php echo $view; ?>"
                            class="btn">Previous Year</a>
                        <a href="?period=year&year=<?php echo date('Y'); ?>&role_filter=<?php echo $roleFilter; ?>&location_filter=<?php echo $locationFilter; ?>&view=<?php echo $view; ?>"
                            class="btn">Current Year</a>
                        <a href="?period=year&year=<?php echo $year + 1; ?>&role_filter=<?php echo $roleFilter; ?>&location_filter=<?php echo $locationFilter; ?>&view=<?php echo $view; ?>"
                            class="btn">Next Year</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- View Toggle Buttons -->
        <div class="view-toggle">
            <button onclick="switchView('list')" class="<?php echo $view === 'list' ? 'active' : ''; ?>">
                <i class="fa fa-list"></i> List View
            </button>
            <button onclick="switchView('calendar')" class="<?php echo $view === 'calendar' ? 'active' : ''; ?>">
                <i class="fa fa-calendar"></i> Calendar View
            </button>
        </div>

        <?php if (!empty($shifts)): ?>
            <!-- List View -->
            <section class="upcoming-shifts" <?php echo $view === 'calendar' ? 'style="display:none;"' : ''; ?>
                id="list-view">
                <h3>Shifts for Selected Period</h3>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Role</th>
                            <th>Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $lastDate = '';
                        foreach ($shifts as $shift):
                            $currentDate = date("Y-m-d", strtotime($shift['shift_date']));
                            $roleColor = $roleColors[$shift['role_name']] ?? $roleColors['default'];
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
                                <td><?php echo date("D, j M", strtotime($shift['shift_date'])); ?></td>
                                <td><?php echo date("g:i A", strtotime($shift['start_time'])); ?> -
                                    <?php echo date("g:i A", strtotime($shift['end_time'])); ?></td>
                                <td>
                                    <span
                                        style="display:inline-block; width:12px; height:12px; background-color:<?php echo $roleColor; ?>; border-radius:50%; margin-right:5px;"></span>
                                    <?php echo htmlspecialchars($shift['role_name']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($shift['location']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <!-- Calendar View -->
            <section id="calendar-view" <?php echo $view === 'list' ? 'style="display:none;"' : ''; ?>>
                <h3>Calendar View</h3>
                <div class="calendar-view">
                    <?php
                    if ($period === 'week' || $period === 'month'):
                        foreach ($calendarDates as $date):
                            $dayName = date('D', strtotime($date));
                            $dayNumber = date('j', strtotime($date));
                            $isToday = date('Y-m-d') === $date;
                            ?>
                            <div class="calendar-day" <?php echo $isToday ? 'style="border: 2px solid #fd2b2b;"' : ''; ?>>
                                <div class="calendar-day-header">
                                    <?php echo $dayName; ?>
                                    <span class="day-number"><?php echo $dayNumber; ?></span>
                                </div>
                                <?php if (isset($shiftsByDate[$date])):
                                    foreach ($shiftsByDate[$date] as $shift):
                                        $roleColor = $roleColors[$shift['role_name']] ?? $roleColors['default'];
                                        ?>
                                        <div class="shift-card" style="border-left-color: <?php echo $roleColor; ?>;">
                                            <div class="shift-time">
                                                <?php echo date("g:i A", strtotime($shift['start_time'])); ?> -
                                                <?php echo date("g:i A", strtotime($shift['end_time'])); ?>
                                            </div>
                                            <div class="shift-user"><?php echo htmlspecialchars($shift['username']); ?></div>
                                            <div class="shift-role"><?php echo htmlspecialchars($shift['role_name']); ?></div>
                                            <small><?php echo htmlspecialchars($shift['location']); ?></small>
                                        </div>
                                    <?php
                                    endforeach;
                                endif;
                                ?>
                            </div>
                        <?php
                        endforeach;
                    endif;
                    ?>
                </div>
            </section>
        <?php else: ?>
            <p class="no-shifts">No shifts scheduled for the selected period.</p>
        <?php endif; ?>
    </div>

    <script>
        // Page-specific navigation fix
        document.addEventListener('DOMContentLoaded', function () {
            // Fix navigation menu links
            const navLinks = document.querySelectorAll('.nav-links ul li a');
            navLinks.forEach(link => {
                link.style.backgroundColor = '#fd2b2b';
                link.style.color = '#ffffff';
            });

            // Ensure menu toggle works properly
            const menuToggle = document.getElementById('menu-toggle');
            const navMenu = document.getElementById('nav-links');
            if (menuToggle && navMenu) {
                menuToggle.addEventListener('click', function () {
                    navMenu.classList.toggle('show');
                });
            }
        });

        // Switch between list and calendar views
        function switchView(view) {
            const listView = document.getElementById('list-view');
            const calendarView = document.getElementById('calendar-view');
            const viewParam = document.querySelector('input[name="view"]');

            if (view === 'list') {
                listView.style.display = 'block';
                calendarView.style.display = 'none';
                document.querySelectorAll('.view-toggle button')[0].classList.add('active');
                document.querySelectorAll('.view-toggle button')[1].classList.remove('active');
            } else {
                listView.style.display = 'none';
                calendarView.style.display = 'block';
                document.querySelectorAll('.view-toggle button')[0].classList.remove('active');
                document.querySelectorAll('.view-toggle button')[1].classList.add('active');
            }

            viewParam.value = view;
        }

        // Removed printRota() function
        // Removed exportToCSV() function
    </script>
    
    <script>
        // Chrome-specific navigation fix - must be before other scripts
        document.addEventListener('DOMContentLoaded', function() {
            // Get navigation elements with explicit selectors
            const menuToggle = document.querySelector('#menu-toggle');
            const navLinks = document.querySelector('#nav-links');
            
            if (menuToggle && navLinks) {
                console.log('Menu elements found, attaching event listeners');
                
                // Remove any existing event handlers to avoid conflicts
                const newMenuToggle = menuToggle.cloneNode(true);
                menuToggle.parentNode.replaceChild(newMenuToggle, menuToggle);
                
                // Add click handler with debugging
                newMenuToggle.addEventListener('click', function(e) {
                    console.log('Menu toggle clicked');
                    e.preventDefault();
                    e.stopPropagation();
                    if (navLinks.classList.contains('show')) {
                        navLinks.classList.remove('show');
                        console.log('Menu hidden');
                    } else {
                        navLinks.classList.add('show');
                        console.log('Menu shown');
                    }
                });
                
                // Close menu when clicking elsewhere
                document.addEventListener('click', function(e) {
                    if (navLinks.classList.contains('show') && 
                        !navLinks.contains(e.target) && 
                        !newMenuToggle.contains(e.target)) {
                        navLinks.classList.remove('show');
                        console.log('Menu closed by outside click');
                    }
                });
                
                // Apply consistent styling
                const links = navLinks.querySelectorAll('a');
                links.forEach(link => {
                    link.style.backgroundColor = '#fd2b2b';
                    link.style.color = '#ffffff';
                    link.style.padding = '12px 20px';
                });
            } else {
                console.error('Navigation menu elements not found!');
                console.log('MenuToggle found:', !!menuToggle);
                console.log('NavLinks found:', !!navLinks);
            }
        });
    </script>
    
    <script src="/rota-app-main/js/menu.js"></script>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>

    <script>
        // Special fix for dashboard link in Chrome
        document.addEventListener('DOMContentLoaded', function() {
            // Fix the dashboard link specifically
            const navLinks = document.querySelectorAll('.nav-links ul li a');
            
            navLinks.forEach(link => {
                if (link.textContent.trim() === 'Dashboard' || link.href.includes('dashboard.php')) {
                    // Create new link with absolute path
                    const newLink = document.createElement('a');
                    newLink.href = "/rota-app-main/users/dashboard.php";
                    newLink.textContent = "Dashboard";
                    newLink.style.backgroundColor = '#fd2b2b';
                    newLink.style.color = '#ffffff';
                    newLink.style.display = 'block';
                    newLink.style.padding = '12px 20px';
                    newLink.style.textDecoration = 'none';
                    newLink.style.whiteSpace = 'nowrap';
                    newLink.style.borderBottom = '1px solid rgba(255, 255, 255, 0.1)';
                    newLink.style.fontSize = '14px';
                    
                    // Add direct click handler
                    newLink.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        window.location.href = "/rota-app-main/users/dashboard.php";
                    });
                    
                    // Replace the old link
                    link.parentNode.replaceChild(newLink, link);
                }
            });

            // Page-specific navigation fix - unchanged
            // ...existing code...
        });
    </script>
</body>

</html>