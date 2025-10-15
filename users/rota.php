<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../includes/db.php';
require_once '../includes/notifications.php';

// Get user-specific data for header
// Get user-specific data for header
$user_id = $_SESSION['user_id'];
$notifications = [];
$notificationCount = 0;
if ($user_id) {
    $notifications = getNotifications($user_id);
    $notificationCount = count($notifications);
}

// Get user's branch
$stmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_branch_id = $user['branch_id'] ?? null;

// Determine filtering period from GET parameters (default to week)
$period = $_GET['period'] ?? 'week';
// Calendar view is now the only view available

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
    $periodSql = "s.shift_date BETWEEN :weekStart AND DATE_ADD(:weekStart, INTERVAL 6 DAY)";
    $bindings = [':weekStart' => $weekStart];
} elseif ($period === 'month') {
    $month = $_GET['month'] ?? date('n');
    $year = $_GET['year'] ?? date('Y');
    $periodSql = "MONTH(s.shift_date) = :month AND YEAR(s.shift_date) = :year";
    $bindings = [':month' => $month, ':year' => $year];
} elseif ($period === 'year') {
    $year = $_GET['year'] ?? date('Y');
    $periodSql = "YEAR(s.shift_date) = :year";
    $bindings = [':year' => $year];
} else {
    // Default fallback
    $period = 'week';
    $weekStart = date('Y-m-d', strtotime('last Saturday'));
    $periodSql = "s.shift_date BETWEEN :weekStart AND DATE_ADD(:weekStart, INTERVAL 6 DAY)";
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

// Modify the SQL query to include filters

$filterConditions = [];
if ($roleFilter) {
    $filterConditions[] = "r.id = :role_id";
    $bindings[':role_id'] = $roleFilter;
}

// Combine all filter conditions
// Always prefix shift_date with s.
$sql = "WHERE $periodSql";
if (!empty($filterConditions)) {
    $sql .= " AND " . implode(" AND ", $filterConditions);
}

// Query shifts for ALL users for the selected period
// Only show shifts for user's branch, including cover shifts
$query = "
    SELECT s.*, u.username, r.name AS role_name, r.base_pay, r.has_night_pay, 
           r.night_shift_pay, r.night_start_time, r.night_end_time,
           b.name AS branch_name
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    JOIN roles r ON s.role_id = r.id
    LEFT JOIN branches b ON s.branch_id = b.id
    $sql
    AND s.branch_id = :user_branch_id
    ORDER BY s.shift_date ASC, s.start_time ASC
";

$stmt = $conn->prepare($query);
foreach ($bindings as $param => $value) {
    $stmt->bindValue($param, $value);
}
$stmt->bindValue(':user_branch_id', $user_branch_id);
$stmt->execute();
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics for the current period
$totalHours = 0;
$totalShifts = count($shifts);
$shiftsByRole = [];
$shiftsByUser = [];
$uniqueDays = [];

foreach ($shifts as $shift) {
    // Calculate hours for this shift
    $start = new DateTime($shift['shift_date'] . ' ' . $shift['start_time']);
    $end = new DateTime($shift['shift_date'] . ' ' . $shift['end_time']);
    
    // Handle shifts that cross midnight
    if ($end < $start) {
        $end->modify('+1 day');
    }
    
    $interval = $start->diff($end);
    $hours = $interval->h + ($interval->i / 60);
    $totalHours += $hours;
    
    // Count shifts by role
    $roleName = $shift['role_name'] ?? 'Unknown';
    if (!isset($shiftsByRole[$roleName])) {
        $shiftsByRole[$roleName] = 0;
    }
    $shiftsByRole[$roleName]++;
    
    // Count shifts by user
    $userName = $shift['username'] ?? 'Unknown';
    if (!isset($shiftsByUser[$userName])) {
        $shiftsByUser[$userName] = 0;
    }
    $shiftsByUser[$userName]++;
    
    // Track unique days with shifts
    $uniqueDays[$shift['shift_date']] = true;
}

// Calculate additional metrics
$daysWithShifts = count($uniqueDays);
$avgShiftsPerDay = $daysWithShifts > 0 ? round($totalShifts / $daysWithShifts, 1) : 0;
$avgHoursPerShift = $totalShifts > 0 ? round($totalHours / $totalShifts, 1) : 0;

// Find busiest day
$shiftCountByDate = [];
foreach ($shifts as $shift) {
    $date = $shift['shift_date'];
    if (!isset($shiftCountByDate[$date])) {
        $shiftCountByDate[$date] = 0;
    }
    $shiftCountByDate[$date]++;
}
arsort($shiftCountByDate);
$busiestDay = !empty($shiftCountByDate) ? array_key_first($shiftCountByDate) : null;
$busiestDayCount = !empty($shiftCountByDate) ? reset($shiftCountByDate) : 0;

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
    <script>
        try {
            if (!document.documentElement.getAttribute('data-theme')) {
                var saved = localStorage.getItem('rota_theme');
                if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
            }
        } catch (e) {}
    </script>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="../images/icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Full Rota</title>
    <link rel="stylesheet" href="../css/rota.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/navigation.css?v=<?php echo time(); ?>">
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="../css/dark_mode.css?v=<?php echo time(); ?>">
    <style>[data-theme="dark"] .page-header, [data-theme="dark"] .current-branch-info {background:transparent !important; color:var(--text) !important;}</style>
    <style>
    /* Rota page specific dark-mode overrides */
    html[data-theme='dark'] body {
      
        color: var(--text) !important;
      
    }

    html[data-theme='dark'] .container,
    html[data-theme='dark'] .filter-section,
    html[data-theme='dark'] .calendar-day,
    html[data-theme='dark'] .shift-card,
    html[data-theme='dark'] table,
    html[data-theme='dark'] thead,
    html[data-theme='dark'] tbody,
    html[data-theme='dark'] td,
    html[data-theme='dark'] th {
        background: var(--panel) !important;
        color: var(--text) !important;
        border-color: rgba(255,255,255,0.03) !important;
        box-shadow: var(--card-shadow) !important;
    }

    /* Calendar specific */
    html[data-theme='dark'] .calendar-day-header {
        background: transparent !important;
        color: var(--text) !important;
    }

    /* Keep role-colour border but darken card */
    html[data-theme='dark'] .shift-card {
        background: linear-gradient(180deg, rgba(255,255,255,0.02), transparent) !important;
        color: var(--text) !important;
        border-left-width: 4px !important;
    }

    /* Day number contrast */
    html[data-theme='dark'] .day-number, h1, .upcoming-shifts {
        background-color: var(--accent) !important;
        color: #ff0000ff !important;
    }

    /* Filters & controls */
    html[data-theme='dark'] .filter-group label,
    html[data-theme='dark'] .filter-group select,
    html[data-theme='dark'] .view-toggle button {
        color: var(--text) !important;
    }

    html[data-theme='dark'] .view-toggle button {
        background: transparent !important;
        border: 1px solid rgba(255,255,255,0.03) !important;
    }

    html[data-theme='dark'] .view-toggle button.active {
        background: linear-gradient(135deg,var(--accent),#ff3b3b) !important;
        color: #fff !important;
        border-color: transparent !important;
    }

    /* Tables: neutral hover */
    html[data-theme='dark'] table tbody tr:hover,
    html[data-theme='dark'] table tr:hover,
    html[data-theme='dark'] .calendar-day:hover {
        background: transparent !important;
        transform: none !important;
        box-shadow: none !important;
    }

    /* Header/nav/icon visibility */
  
    /* Catch inline white backgrounds */
    html[data-theme='dark'] [style*="background:#fff"],
    html[data-theme='dark'] [style*="background: #fff"],
    html[data-theme='dark'] [style*="background:#ffffff"],
    html[data-theme='dark'] [style*="background: #ffffff"],
    html[data-theme='dark'] [style*="background: white"] {
        background: var(--panel) !important;
        color: var(--text) !important;
    }

    </style>
    <?php
    if (isset($_SESSION['user_id'])) {
        try {
            $stmtTheme = $conn->prepare('SELECT theme FROM users WHERE id = ? LIMIT 1');
            $stmtTheme->execute([$_SESSION['user_id']]);
            $row = $stmtTheme->fetch(PDO::FETCH_ASSOC);
            $userTheme = $row && !empty($row['theme']) ? $row['theme'] : null;
            if ($userTheme === 'dark') {
                echo "<script>document.documentElement.setAttribute('data-theme','dark');</script>\n";
            }
        } catch (Exception $e) {}
    }
    ?>
    <style>
        /* Rota Statistics Panel */
        .rota-statistics-panel {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 280px));
            gap: 20px;
            margin-bottom: 30px;
            justify-content: center;
            padding: 10px 0;
        }

        .stat-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            flex-shrink: 0;
        }

        .stat-content {
            flex: 1;
            min-width: 0;
        }

        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #2c3e50;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 13px;
            color: #7f8c8d;
            margin-top: 4px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-sublabel {
            font-size: 12px;
            color: #95a5a6;
            margin-top: 3px;
        }

        /* Dark mode for statistics */
        html[data-theme='dark'] .stat-card {
            background: var(--panel) !important;
        }

        html[data-theme='dark'] .stat-value {
            color: var(--text) !important;
        }

        html[data-theme='dark'] .stat-label,
        html[data-theme='dark'] .stat-sublabel {
            color: rgba(255, 255, 255, 0.6) !important;
        }

        /* Responsive statistics */
        @media (max-width: 768px) {
            .rota-statistics-panel {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .stat-card {
                padding: 15px;
                gap: 12px;
            }

            .stat-icon {
                width: 45px;
                height: 45px;
                font-size: 20px;
            }

            .stat-value {
                font-size: 22px;
            }

            .stat-label {
                font-size: 11px;
            }

            .stat-sublabel {
                font-size: 10px;
            }
        }

        @media (max-width: 480px) {
            .rota-statistics-panel {
                grid-template-columns: 1fr;
            }
        }

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

        /* View toggle styles removed - calendar view is now the only option */

        /* Calendar View Styles */
        .calendar-view {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
            margin-top: 20px;
        }

        .calendar-day {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 10px;
            min-height: 140px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            position: relative;
            transition: box-shadow 0.2s;
        }

        .calendar-day:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .calendar-day-header {
            background-color: #fd2b2b;
            padding: 10px;
            margin: -10px -10px 10px -10px;
            text-align: center;
            font-weight: bold;
            color: white;
            font-size: 13px;
            letter-spacing: 0.5px;
        }

        .calendar-day:empty {
            background-color: #f9f9f9;
            opacity: 0.6;
        }

        .day-number {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 28px;
            height: 28px;
            background: rgba(255, 255, 255, 0.25);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: bold;
            backdrop-filter: blur(10px);
        }

        /* Enhanced Shift Card in Calendar */
        .shift-card {
            background-color: #ffffff;
            border-left: 4px solid #fd2b2b;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 6px;
            font-size: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }

        .shift-card:hover {
            transform: translateX(3px);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
        }

        .shift-card:last-child {
            margin-bottom: 0;
        }

        .shift-time {
            font-weight: bold;
            font-size: 13px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 4px;
        }

      
        .shift-user {
            margin-top: 6px;
            font-weight: 600;
            color: #34495e;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .shift-user::before {
            content: "👤";
            font-size: 12px;
        }

        .shift-role {
            font-style: italic;
            font-size: 11px;
            color: #7f8c8d;
            margin-top: 4px;
            padding: 2px 6px;
            background: rgba(0, 0, 0, 0.04);
            border-radius: 3px;
            display: inline-block;
        }

        .shift-location {
            font-size: 10px;
            color: #95a5a6;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .shift-location::before {
            content: "📍";
            font-size: 10px;
        }

        /* Shift duration badge */
        .shift-duration {
            display: inline-block;
            background-color: #fd2b2b;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-top: 4px;
            font-weight: 600;
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

        /* Button styling enhancements */
        .btn {
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            cursor: pointer;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Empty state styling */
        .no-shifts {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
            font-size: 18px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border-radius: 12px;
            margin-top: 20px;
        }

        .no-shifts::before {
            content: "📅";
            display: block;
            font-size: 48px;
            margin-bottom: 15px;
        }

        /* Print-friendly styles */
        @media print {

            header,
            .filter-section,
            .view-toggle,
            .export-btn,
            button,
            .rota-statistics-panel,
            .btn {
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

            .calendar-day,
            .shift-card {
                page-break-inside: avoid !important;
            }

            .calendar-view {
                gap: 5px;
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
    <!-- Header -->
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
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <?php if ($notification['type'] === 'shift-invite' && !empty($notification['related_id'])): ?>
                                <a class="notification-item shit-invt notification-<?php echo $notification['type']; ?>" data-id="<?php echo $notification['id']; ?>" href="../functions/pending_shift_invitations.php?invitation_id=<?php echo $notification['related_id']; ?>&notif_id=<?php echo $notification['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                </a>
                            <?php elseif ($notification['type'] === 'shift-swap' && !empty($notification['related_id'])): ?>
                                <a class="notification-item shit-invt notification-<?php echo $notification['type']; ?>" data-id="<?php echo $notification['id']; ?>" href="../functions/pending_shift_swaps.php?swap_id=<?php echo $notification['related_id']; ?>&notif_id=<?php echo $notification['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                </a>
                            <?php else: ?>
                                <div class="notification-item notification-<?php echo $notification['type']; ?>" data-id="<?php echo $notification['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
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
    </header>
    
    <div class="container">
        <h1>Full Rota</h1>

        <!-- Rota Statistics Panel -->
        <?php if (!empty($shifts)): ?>
        <div class="rota-statistics-panel">
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #fd2b2b;">
                    <i class="fa fa-clock-o"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($totalHours, 1); ?></div>
                    <div class="stat-label">Total Hours</div>
                    <div class="stat-sublabel"><?php echo $avgHoursPerShift; ?> hrs/shift avg</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #fd2b2b;">
                    <i class="fa fa-calendar-check-o"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $totalShifts; ?></div>
                    <div class="stat-label">Total Shifts</div>
                    <div class="stat-sublabel"><?php echo $avgShiftsPerDay; ?> shifts/day avg</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #fd2b2b;">
                    <i class="fa fa-users"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo count($shiftsByRole); ?></div>
                    <div class="stat-label">Active Roles</div>
                    <div class="stat-sublabel"><?php echo count($shiftsByUser); ?> team members</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <i class="fa fa-line-chart"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $busiestDayCount; ?></div>
                    <div class="stat-label">Busiest Day</div>
                    <div class="stat-sublabel">
                        <?php 
                        if ($busiestDay) {
                            echo date('D, M j', strtotime($busiestDay));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
                        <label>My Branch:</label>
                        <div style="font-weight: bold; font-size: 16px; color: #3366cc; padding: 8px 0;">
                            <?php 
                            // Get branch name
                            $branchName = '';
                            if ($user_branch_id) {
                                $branchStmt = $conn->prepare("SELECT name FROM branches WHERE id = ?");
                                $branchStmt->execute([$user_branch_id]);
                                $branchRow = $branchStmt->fetch(PDO::FETCH_ASSOC);
                                $branchName = $branchRow ? $branchRow['name'] : '';
                            }
                            echo htmlspecialchars($branchName);
                            ?>
                        </div>
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

                <noscript><button type="submit" class="btn">Apply Filters</button></noscript>
            </form>

            <!-- Period Navigation and Export Buttons -->
            <div class="filter-row">
                <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; width: 100%;">
                    <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                        <?php if ($period === 'week'): ?>
                        <a href="?period=week&weekStart=<?php echo date('Y-m-d', strtotime($weekStart . ' -7 days')); ?>&role_filter=<?php echo $roleFilter; ?>"
                            class="btn">Previous Week</a>
                        <a href="?period=week&weekStart=<?php echo date('Y-m-d'); ?>&role_filter=<?php echo $roleFilter; ?>"
                            class="btn">Current Week</a>
                        <a href="?period=week&weekStart=<?php echo date('Y-m-d', strtotime($weekStart . ' +7 days')); ?>&role_filter=<?php echo $roleFilter; ?>"
                            class="btn">Next Week</a>
                        <?php elseif ($period === 'month'): ?>
                        <a href="?period=month&month=<?php echo $month == 1 ? 12 : $month - 1; ?>&year=<?php echo $month == 1 ? $year - 1 : $year; ?>&role_filter=<?php echo $roleFilter; ?>"
                            class="btn">Previous Month</a>
                        <a href="?period=month&month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>&role_filter=<?php echo $roleFilter; ?>"
                            class="btn">Current Month</a>
                        <a href="?period=month&month=<?php echo $month == 12 ? 1 : $month + 1; ?>&year=<?php echo $month == 12 ? $year + 1 : $year; ?>&role_filter=<?php echo $roleFilter; ?>"
                            class="btn">Next Month</a>
                        <?php elseif ($period === 'year'): ?>
                        <a href="?period=year&year=<?php echo $year - 1; ?>&role_filter=<?php echo $roleFilter; ?>"
                            class="btn">Previous Year</a>
                        <a href="?period=year&year=<?php echo date('Y'); ?>&role_filter=<?php echo $roleFilter; ?>"
                            class="btn">Current Year</a>
                        <a href="?period=year&year=<?php echo $year + 1; ?>&role_filter=<?php echo $roleFilter; ?>"
                            class="btn">Next Year</a>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Export Buttons -->
                    <?php if (!empty($shifts)): ?>
                    <div style="display: flex; gap: 10px;">
                        <button onclick="exportToCSV()" class="btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                            <i class="fa fa-download"></i> Export CSV
                        </button>
                        <button onclick="window.print()" class="btn" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none;">
                            <i class="fa fa-print"></i> Print
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($shifts)): ?>
            <!-- Calendar View -->
            <section id="calendar-view">
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
                                        
                                        // Calculate shift duration
                                        $start = new DateTime($shift['shift_date'] . ' ' . $shift['start_time']);
                                        $end = new DateTime($shift['shift_date'] . ' ' . $shift['end_time']);
                                        if ($end < $start) {
                                            $end->modify('+1 day');
                                        }
                                        $interval = $start->diff($end);
                                        $duration = $interval->h + ($interval->i / 60);
                                        $durationText = $interval->h . 'h';
                                        if ($interval->i > 0) {
                                            $durationText .= ' ' . $interval->i . 'm';
                                        }
                                        ?>
                                        <div class="shift-card" style="border-left-color: <?php echo $roleColor; ?>;">
                                            <div class="shift-time">
                                                <?php echo date("g:i A", strtotime($shift['start_time'])); ?> -
                                                <?php echo date("g:i A", strtotime($shift['end_time'])); ?>
                                            </div>
                                            <div class="shift-user"><?php echo htmlspecialchars($shift['username']); ?></div>
                                            <div class="shift-role"><?php echo htmlspecialchars($shift['role_name']); ?></div>
                                            <div class="shift-duration"><?php echo $durationText; ?></div>
                                            <?php
                                            $loc = $shift['location'] ?? '';
                                            if (!empty($loc)):
                                            ?>
                                            <div class="shift-location">
                                                <?php
                                                if ($loc === 'Cross-branch coverage' && !empty($shift['branch_name'])) {
                                                    echo htmlspecialchars($loc) . ' (' . htmlspecialchars($shift['branch_name']) . ')';
                                                } else {
                                                    echo htmlspecialchars($loc);
                                                }
                                                ?>
                                            </div>
                                            <?php endif; ?>
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
        // Notification functionality
        function markAsRead(element) {
            const notificationId = element.getAttribute('data-id');
            fetch('../functions/mark_notification.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: notificationId })
            })
            .then(response => response.json())
            .then(data => {
                console.log('Response data:', data); // Debug log
                if (data.success) {
                    element.style.display = 'none';
                    
                    // Count remaining visible notifications more reliably
                    const allNotifications = document.querySelectorAll('.notification-item[data-id]');
                    let visibleCount = 0;
                    
                    allNotifications.forEach(notification => {
                        const computedStyle = window.getComputedStyle(notification);
                        if (computedStyle.display !== 'none') {
                            visibleCount++;
                        }
                    });

                    console.log('Total notifications with data-id:', allNotifications.length); // Debug log
                    console.log('Visible notifications count:', visibleCount); // Debug log
                    
                    if (visibleCount === 0) {
                        document.getElementById('notification-dropdown').innerHTML = '<div class="notification-item"><p>No notifications</p></div>';
                        const badge = document.querySelector('.notification-badge');
                        if (badge) {
                            badge.style.display = 'none';
                            console.log('Badge hidden - no notifications left'); // Debug log
                        }
                    } else {
                        const badge = document.querySelector('.notification-badge');
                        if (badge) {
                            badge.textContent = visibleCount;
                            badge.style.display = 'flex'; // Ensure badge is visible
                            console.log('Badge updated to:', visibleCount); // Debug log
                        }
                    }
                } else {
                    console.error('Failed to mark notification as read:', data.error);
                }
            })
            .catch(error => console.error('Error:', error));
        }

        // Page-specific styling fix
        document.addEventListener('DOMContentLoaded', function () {
            // Notification setup
            var notificationIcon = document.getElementById('notification-icon');
            var dropdown = document.getElementById('notification-dropdown');

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

            // Fix navigation menu links styling
            const navLinks = document.querySelectorAll('.nav-links ul li a');
            navLinks.forEach(link => {
                link.style.backgroundColor = '#fd2b2b';
                link.style.color = '#ffffff';
            });
        });

        // CSV Export Function
        function exportToCSV() {
            const shifts = <?php echo json_encode($shifts); ?>;
            
            if (!shifts || shifts.length === 0) {
                alert('No shifts to export');
                return;
            }
            
            // CSV Headers
            let csv = 'Date,Day,Start Time,End Time,Duration,Employee,Role,Location,Branch\n';
            
            // Add data rows
            shifts.forEach(shift => {
                const date = shift.shift_date;
                const day = new Date(date).toLocaleDateString('en-US', { weekday: 'short' });
                const startTime = shift.start_time;
                const endTime = shift.end_time;
                
                // Calculate duration
                const start = new Date(date + ' ' + startTime);
                let end = new Date(date + ' ' + endTime);
                if (end < start) {
                    end = new Date(end.getTime() + 24*60*60*1000);
                }
                const durationHours = Math.round((end - start) / (1000 * 60 * 60) * 10) / 10;
                
                const employee = (shift.username || '').replace(/"/g, '""');
                const role = (shift.role_name || '').replace(/"/g, '""');
                const location = (shift.location || '').replace(/"/g, '""');
                const branch = (shift.branch_name || '').replace(/"/g, '""');
                
                csv += `"${date}","${day}","${startTime}","${endTime}","${durationHours}h","${employee}","${role}","${location}","${branch}"\n`;
            });
            
            // Create download
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            const filename = 'rota_' + new Date().toISOString().split('T')[0] + '.csv';
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // View switching functions removed - calendar view is now the only option
    </script>
    
    <script src="../js/menu.js"></script>
    <script src="../js/pwa-debug.js"></script>
    <script src="../js/links.js"></script>

   
</body>

</html>