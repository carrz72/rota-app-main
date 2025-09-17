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

// Fetch all users for swap dropdown
$stmtUsers = $conn->query("SELECT id, username FROM users ORDER BY username ASC");
$all_users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Query shifts

$stmtShifts = $conn->prepare(
    "SELECT s.*, r.name as role, s.location, r.base_pay, r.has_night_pay, r.night_shift_pay, r.night_start_time, r.night_end_time, b.id AS branch_id, b.name AS branch_name 
    FROM shifts s 
    LEFT JOIN roles r ON s.role_id = r.id 
    LEFT JOIN branches b ON s.branch_id = b.id 
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

// Fetch user's branch info to default the add-shift location
$stmtUserBranch = $conn->prepare("SELECT b.id, b.name, b.address FROM users u LEFT JOIN branches b ON u.branch_id = b.id WHERE u.id = ? LIMIT 1");
$stmtUserBranch->execute([$user_id]);
$user_branch = $stmtUserBranch->fetch(PDO::FETCH_ASSOC);

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
    <script>
        try {
            if (!document.documentElement.getAttribute('data-theme')) {
                var saved = localStorage.getItem('rota_theme');
                if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
            }
        } catch (e) {}
    </script>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="icon" type="image/png" href="../images/icon.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="apple-touch-icon" href="../images/icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="../css/dark_mode.css">
    <style>[data-theme="dark"] .page-header, [data-theme="dark"] .current-branch-info {background:transparent !important; color:var(--text) !important;}</style>
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
    <title>Your Shifts - Open Rota</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/navigation.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

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

      

        .shift-actions {
            display: flex;
            gap: 10px;
        }

    .editBtn,
    .deleteBtn,
    .swapBtn {
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

        .swapBtn {
            background-color: #6c757d;
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
        
        #toggleAddShiftBtn {
            background-color: #fd2b2b;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        #toggleAddShiftBtn:hover {
            background-color: #e63946;
        }

        .btn.save-shift-btn {
            background-color: #fd2b2b;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        #saveShiftBtn:hover {
            background-color: #e63946;
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
            .container {
                margin: 10px;
                padding: 15px;
            }

            h1 {
                font-size: 1.5rem;
                text-align: center;
                margin-bottom: 20px;
            }

            .card {
                padding: 15px;
                margin-bottom: 15px;
            }

            .card-header {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
                text-align: center;
            }

            .card-header h3 {
                justify-content: center;
                margin-bottom: 10px;
                font-size: 1.2rem;
            }

            /* Period form styling */
            .card-header form {
                display: flex;
                flex-direction: column;
                gap: 8px;
                width: 100%;
            }

            .card-header form label {
                font-weight: 600;
                color: #333;
                text-align: center;
                font-size: 14px;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
            }

            .card-header form label::before {
                content: "\f017";
                font-family: "Font Awesome 5 Free", "FontAwesome";
                font-weight: 900;
                color: #fd2b2b;
            }

            .card-header form select {
                width: 100%;
                padding: 10px 12px;
                border: 2px solid #e0e0e0;
                border-radius: 8px;
                font-size: 1rem;
                background: white;
                box-sizing: border-box;
                font-family: "newFont", Arial, sans-serif;
                color: #333;
                transition: all 0.3s ease;
                appearance: none;
                -webkit-appearance: none;
                -moz-appearance: none;
                background-image: url("data:image/svg+xml;utf8,<svg fill='%23666' height='20' viewBox='0 0 24 24' width='20' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/><path d='M0 0h24v24H0z' fill='none'/></svg>");
                background-repeat: no-repeat;
                background-position: right 10px center;
                background-size: 16px;
                padding-right: 40px;
                cursor: pointer;
            }

            .card-header form select:hover {
                border-color: #fd2b2b;
                box-shadow: 0 2px 8px rgba(253, 43, 43, 0.1);
            }

            .card-header form select:focus {
                outline: none;
                border-color: #fd2b2b;
                box-shadow: 0 0 0 3px rgba(253, 43, 43, 0.1);
            }

            .summary-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .summary-box {
                padding: 12px;
            }

            .summary-value {
                font-size: 1.5rem;
            }

            .period-navigation {
                flex-direction: column;
                gap: 15px;
                align-items: center;
                text-align: center;
            }

            .period-nav-buttons {
                width: 100%;
                justify-content: space-between;
                gap: 8px;
            }

            .period-nav-buttons a.btn {
                flex: 1;
                padding: 10px 8px;
                font-size: 0.9rem;
                justify-content: center;
                min-width: 0;
            }

            .add-shift-form {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .save-shift-btn {
                grid-column: 1;
                margin-top: 10px;
            }

            /* Table responsive design */
            .responsive-table {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            table {
                min-width: 600px;
                font-size: 0.85rem;
            }

            table th,
            table td {
                padding: 8px 6px;
                white-space: nowrap;
            }

            table th {
                font-size: 0.8rem;
            }

            /* Make Add New Shift button full width */
            #toggleAddShiftBtn {
                width: 100%;
                justify-content: center;
                padding: 12px;
                font-size: 1rem;
                margin-top: 10px;
            }

            .shift-actions {
                flex-direction: column;
                gap: 5px;
            }

            .editBtn,
            .deleteBtn {
                padding: 5px 8px;
                font-size: 0.8rem;
                width: 100%;
                justify-content: center;
            }

            /* Modal responsiveness */
            .modal-content {
                margin: 5% auto;
                width: 95%;
                max-width: none;
                padding: 20px;
            }

            .modal-header h3 {
                font-size: 1.1rem;
            }

            /* No shifts message */
            .no-shifts {
                padding: 20px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .container {
                margin: 5px;
                padding: 10px;
            }

            h1 {
                font-size: 1.3rem;
                margin-bottom: 15px;
            }

            .card {
                padding: 12px;
                margin-bottom: 12px;
            }

            .card-header h3 {
                font-size: 1.1rem;
            }

            .summary-box {
                padding: 10px;
            }

            .summary-box h4 {
                font-size: 0.9rem;
                margin-bottom: 8px;
            }

            .summary-value {
                font-size: 1.3rem;
            }

            .period-navigation p {
                font-size: 0.9rem;
            }

            .period-nav-buttons a.btn {
                padding: 8px 6px;
                font-size: 0.8rem;
            }

            #toggleAddShiftBtn {
                padding: 10px;
                font-size: 0.9rem;
            }

            .form-group label {
                font-size: 0.9rem;
            }

            .form-group input,
            .form-group select {
                padding: 8px;
                font-size: 0.9rem;
            }

            table {
                min-width: 500px;
                font-size: 0.75rem;
            }

            table th,
            table td {
                padding: 6px 4px;
            }

            .editBtn,
            .deleteBtn {
                padding: 4px 6px;
                font-size: 0.7rem;
            }

            .modal-content {
                padding: 15px;
                margin: 2% auto;
            }

            .modal-header {
                margin-bottom: 15px;
            }

            .modal-header h3 {
                font-size: 1rem;
            }
        }

        /* Extra small screens */
        @media (max-width: 360px) {
            .container {
                margin: 2px;
                padding: 8px;
            }

            .card {
                padding: 10px;
            }

            .summary-value {
                font-size: 1.2rem;
            }

            .period-nav-buttons a.btn {
                padding: 6px 4px;
                font-size: 0.75rem;
            }

            table {
                min-width: 450px;
                font-size: 0.7rem;
            }

            table th,
            table td {
                padding: 4px 3px;
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
                gap: 15px;
                align-items: center;
                text-align: center;
                padding: 15px 0;
            }

            .period-navigation p {
                margin: 0;
                font-weight: 600;
                color: #333;
            }

            .period-nav-buttons {
                width: 100%;
                justify-content: space-between;
                gap: 10px;
            }

            .period-nav-buttons a.btn {
                flex: 1;
                text-align: center;
                white-space: nowrap;
                min-width: 0;
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

        /* Disable hover effects for responsive shifts table rows to keep dark mode and UX consistent */
        .responsive-table table tbody tr:hover,
        .responsive-table tbody tr:hover,
        .responsive-table table tr:hover {
            background: transparent !important;
            transform: none !important;
            box-shadow: none !important;
            cursor: default !important;
        }

        html[data-theme='dark'] h3 {
            color: var(--text) !important;
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header style="opacity: 1; transition: opacity 0.5s ease;">
        <div class="logo">Open Rota</div>
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
                                <a class="notification-item shit-invt notification-<?php echo $notification['type']; ?>"
                                   data-id="<?php echo $notification['id']; ?>"
                                   href="../functions/pending_shift_invitations.php?invitation_id=<?php echo $notification['related_id']; ?>&notif_id=<?php echo $notification['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                </a>
                            <?php elseif ($notification['type'] === 'shift-swap' && !empty($notification['related_id'])): ?>
                                <a class="notification-item shit-invt notification-<?php echo $notification['type']; ?>"
                                   data-id="<?php echo $notification['id']; ?>"
                                   href="../functions/pending_shift_swaps.php?swap_id=<?php echo $notification['related_id']; ?>&notif_id=<?php echo $notification['id']; ?>">
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
                    <div id="addShiftMessage" style="display:none; margin-bottom:10px; padding:8px; border-radius:4px;"></div>
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
                            <label for="branch_search"><i class="fa fa-building"></i> Branch (default to your branch):</label>
                            <input list="branches_list" id="branch_search" name="branch_search" placeholder="Search branch or keep your branch" value="<?php echo htmlspecialchars($user_branch['name'] ?? ''); ?>" />
                            <datalist id="branches_list"></datalist>
                            <input type="hidden" id="branch_id" name="branch_id" value="<?php echo htmlspecialchars($user_branch['id'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="location"><i class="fa fa-map-marker"></i> Location:</label>
                            <input type="text" id="location" name="location" required placeholder="Enter work location" value="<?php echo htmlspecialchars(isset($user_branch) && $user_branch ? ($user_branch['name']) : ''); ?>" />
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
                                        <?php
                                        $loc = $shift['location'] ?? '';
                                        if ($loc === 'Cross-branch coverage' && !empty($shift['branch_name'])) {
                                            echo htmlspecialchars($loc) . ' (' . htmlspecialchars($shift['branch_name']) . ')';
                                        } else {
                                            echo htmlspecialchars($loc);
                                        }
                                        ?>
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
                                            <button class="swapBtn" data-id="<?php echo $shift['id']; ?>">
                                                <i class="fa fa-exchange"></i> Swap
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
        <!-- Swap Modal -->
        <div id="swapModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Propose Shift Swap</h3>
                    <span class="close-swap-modal close-modal">&times;</span>
                </div>
                <form id="swapForm">
                    <input type="hidden" name="from_shift_id" id="swap_from_shift_id">
                    <input type="hidden" name="action" value="propose">
                    <div class="form-group">
                        <label for="swap_to_user_id">Select User to swap with</label>
                        <select id="swap_to_user_id" name="to_user_id" required>
                            <option value="">-- Select user --</option>
                            <?php foreach ($all_users as $u): ?>
                                <?php if ($u['id'] == $user_id) continue; ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <small id="swap-help">Only select the user you'd like to request a swap with — the recipient will choose which of their shifts to give when accepting.</small>
                    </div>
                    <button type="submit" class="editBtn save-shift-btn">Send Proposal</button>
                </form>
            </div>
        </div>
    </div>
    <script>
        // Notification functionality (robust)
        function markAsRead(elementOrDescendant) {
            // Ensure we have the notification item element
            let notificationElem = null;
            try {
                if (!elementOrDescendant) return;
                if (elementOrDescendant.classList && elementOrDescendant.classList.contains('notification-item')) {
                    notificationElem = elementOrDescendant;
                } else if (elementOrDescendant.closest) {
                    notificationElem = elementOrDescendant.closest('.notification-item');
                } else if (elementOrDescendant.parentElement) {
                    // fallback
                    notificationElem = elementOrDescendant.parentElement;
                }
            } catch (e) {
                console.error('Invalid element passed to markAsRead', e);
                return;
            }

            if (!notificationElem) {
                console.error('No notification element found');
                return;
            }

            const rawId = notificationElem.getAttribute('data-id');
            const notificationId = rawId ? parseInt(rawId, 10) : 0;
            console.log('Marking notification as read:', notificationId);

            if (!notificationId || notificationId <= 0) {
                console.error('No notification ID found');
                return;
            }

            fetch('../functions/mark_notification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: notificationId })
            })
                .then(res => {
                    console.log('Response status:', res.status);
                    return res.json();
                })
                .then(data => {
                    console.log('Response data:', data);
                    if (data && data.success) {
                        // remove element
                        if (notificationElem.parentNode) notificationElem.parentNode.removeChild(notificationElem);

                        // update badge and dropdown
                        const allNotifications = document.querySelectorAll('.notification-item[data-id]');
                        let visibleCount = 0;
                        allNotifications.forEach(n => {
                            if (window.getComputedStyle(n).display !== 'none') visibleCount++;
                        });

                        const badge = document.querySelector('.notification-badge');
                        const dropdown = document.getElementById('notification-dropdown');
                        if (visibleCount === 0) {
                            if (dropdown) dropdown.innerHTML = '<div class="notification-item"><p>No notifications</p></div>';
                            if (badge) badge.style.display = 'none';
                        } else if (badge) {
                            badge.textContent = visibleCount;
                            badge.style.display = 'flex';
                        }
                    } else {
                        console.error('Failed to mark notification as read:', data && data.error ? data.error : data);
                    }
                })
                .catch(err => console.error('Error:', err));
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Notification functionality
            var notificationIcon = document.getElementById('notification-icon');
            var dropdown = document.getElementById('notification-dropdown');

            // Strip malformed notifications if any
            if (typeof stripMalformedNotifications === 'function') stripMalformedNotifications();

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

            // Add click event listeners for notification close buttons
            const closeButtons = document.querySelectorAll('.close-btn');
            closeButtons.forEach(function (button) {
                button.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Close button clicked'); // Debug log
                    const notificationItem = this.closest('.notification-item');
                    if (notificationItem) {
                        markAsRead(notificationItem);
                    }
                });
            });

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

            // AJAX submit for Add Shift form with inline messages
            (function setupAddShiftAjax(){
                const addForm = document.getElementById('addShiftForm');
                const msgBox = document.getElementById('addShiftMessage');
                if (!addForm) return;

                addForm.addEventListener('submit', function(e){
                    e.preventDefault();
                    if (!msgBox) return;
                    msgBox.style.display = 'none';
                    msgBox.textContent = '';

                    const formData = new FormData(addForm);
                    // indicate AJAX to server
                    formData.append('ajax', '1');

                    fetch(addForm.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                    .then(async res => {
                        // Try JSON first
                        let payload;
                        try {
                            payload = await res.json();
                        } catch (err) {
                            // Not JSON - try to read text
                            try {
                                const txt = await res.text();
                                payload = { success: false, message: txt };
                            } catch (t) {
                                payload = { success: false, message: 'Unexpected server response.' };
                            }
                        }

                        // Normalize message
                        let userMessage = payload && payload.message ? String(payload.message) : '';
                        // Map known server messages to friendlier versions
                        if (userMessage.match(/manager/i) || userMessage.match(/manage/i)) {
                            userMessage = 'Only users with manager-level access can add shifts at other branches.';
                        } else if (userMessage.match(/Selected branch does not exist/i)) {
                            userMessage = 'The selected branch could not be found. Please choose a valid branch.';
                        } else if (userMessage.match(/All fields are required/i)) {
                            userMessage = 'Please complete all required fields (date, times, role and location).';
                        } else if (!userMessage) {
                            userMessage = 'Failed to add shift.';
                        }

                        if (payload && payload.success) {
                            msgBox.style.display = 'block';
                            msgBox.style.background = '#e6ffed';
                            msgBox.style.border = '1px solid #3cb371';
                            msgBox.style.color = '#046b2f';
                            msgBox.textContent = userMessage;
                            setTimeout(() => { location.reload(); }, 900);
                        } else {
                            msgBox.style.display = 'block';
                            msgBox.style.background = '#fff1f0';
                            msgBox.style.border = '1px solid #e04b4b';
                            msgBox.style.color = '#a10000';
                            msgBox.textContent = userMessage;
                        }
                    })
                    .catch(err => {
                        msgBox.style.display = 'block';
                        msgBox.style.background = '#fff1f0';
                        msgBox.style.border = '1px solid #e04b4b';
                        msgBox.style.color = '#a10000';
                        msgBox.textContent = 'Network error while saving shift.';
                        console.error('Add Shift AJAX error', err);
                    });
                });
            })();

            // Fetch branches and populate branch selector; default to user's branch
            (function setupBranchSelector(){
                const branchList = document.getElementById('branches_list');
                const branchSearch = document.getElementById('branch_search');
                const branchIdInput = document.getElementById('branch_id');
                const locationInput = document.getElementById('location');

                if (!branchList || !branchSearch || !branchIdInput) return;

                fetch('../functions/get_branches.php')
                    .then(r => r.json())
                    .then(data => {
                        if (!data || !data.branches) return;
                        data.branches.forEach(b => {
                            // add option to datalist
                            const option = document.createElement('option');
                            option.value = b.name + (b.code ? ' (' + b.code + ')' : '');
                            option.setAttribute('data-branch-id', b.id);
                            option.setAttribute('data-branch-address', b.address || '');
                            branchList.appendChild(option);

                            // If this branch matches user's branch id, select it by default
                            const userBranchId = '<?php echo htmlspecialchars($user_branch['id'] ?? ''); ?>';
                            if (userBranchId && String(b.id) === String(userBranchId)) {
                                branchSearch.value = option.value;
                                branchIdInput.value = b.id;
                                // Prefill location with branch name only (do not include address)
                                locationInput.value = b.name;
                            }
                        });
                    })
                    .catch(err => console.error('Failed to load branches:', err));

                // When user types/selects a branch from the datalist, try to set the branch_id and prefill location
                branchSearch.addEventListener('input', function(e){
                    const val = this.value;
                    // find matching option
                    const opts = branchList.querySelectorAll('option');
                    let matched = null;
                    opts.forEach(opt => {
                        if (opt.value === val) matched = opt;
                    });
                    if (matched) {
                        const bid = matched.getAttribute('data-branch-id');
                        // Only set branch id and pref fill location with branch name (no address)
                        branchIdInput.value = bid || '';
                        locationInput.value = this.value;
                    } else {
                        // If user clears or types a custom location, clear branch_id so add_shift treats it as local entry
                        branchIdInput.value = '';
                    }
                });
            })();

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

            // Swap: open modal and submit proposal
            const swapModal = document.getElementById('swapModal');
            const swapForm = document.getElementById('swapForm');
            const swapFromShift = document.getElementById('swap_from_shift_id');
            const swapBtns = document.querySelectorAll('.swapBtn');
            const closeSwap = document.querySelector('.close-swap-modal');

            swapBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    swapFromShift.value = btn.getAttribute('data-id');
                    if (swapModal) swapModal.style.display = 'block';
                });
            });

            if (closeSwap) {
                closeSwap.addEventListener('click', () => swapModal.style.display = 'none');
            }
            window.addEventListener('click', (e) => {
                if (e.target === swapModal) swapModal.style.display = 'none';
            });

            // Submit a swap proposal (recipient will choose which of their shifts to give)
            const swapUserSelect = document.getElementById('swap_to_user_id');
            if (swapForm) {
                swapForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    const fd = new FormData(swapForm);
                    fetch('../functions/shift_swap.php', {
                        method: 'POST',
                        body: fd,
                    })
                        .then(res => res.text())
                        .then(txt => {
                            if (txt.includes('proposed')) {
                                alert('Swap proposal sent.');
                                swapModal.style.display = 'none';
                            } else if (txt.includes('invalid') || txt.includes('not_owner')) {
                                alert('Invalid swap request.');
                            } else {
                                alert('Swap request failed: ' + txt);
                            }
                        })
                        .catch(() => alert('Network error while proposing swap.'));
                });
            }

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
    <script src="../js/darkmode.js"></script>
    <script src="../js/menu.js"></script>
    <script src="../js/pwa-debug.js"></script>
    <script src="../js/links.js"></script>
</body>

</html>