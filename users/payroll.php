<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../includes/db.php';
require_once '../includes/notifications.php';
require_once '../functions/payroll_functions.php';

$user_id = $_SESSION['user_id'];
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

// Get user-specific data for header
$notifications = [];
$notificationCount = 0;
if ($user_id) {
    $notifications = getNotifications($user_id);
    $notificationCount = count($notifications);
}

// Get current user's role info
$stmt = $conn->prepare("SELECT r.*, u.username FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->execute([$user_id]);
$user_role = $stmt->fetch(PDO::FETCH_ASSOC);

$employment_type = $user_role['employment_type'] ?? 'hourly';

// Get current and next payroll periods
$current_period = getCurrentPayrollPeriod($conn, $employment_type);
$next_period = getNextPaymentPeriod($conn, $employment_type);

// Calculate current period pay if period exists
$current_calculation = null;
if ($current_period) {
    $current_calculation = calculatePayrollForPeriod($conn, $user_id, $current_period['id']);
}

// Get upcoming payment dates
$upcoming_payments = getUpcomingPaymentDates($conn, 3);

// If admin, get payroll summary
$payroll_summary = null;
if ($is_admin && $current_period) {
    $payroll_summary = getPayrollSummary($conn, $current_period['id']);
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
        } catch (e) { }
    </script>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="icon" type="image/png" href="../images/icon.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="apple-touch-icon" href="../images/icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/payroll.css">
    <link rel="stylesheet" href="../css/dark_mode.css">
    <style>
        [data-theme="dark"] .page-header,
        [data-theme="dark"] .current-branch-info {
            background: transparent !important;
            color: var(--text) !important;
        }
    </style>
    <style>
        /* Payroll page dark-mode overrides */


        html[data-theme='dark'] .container,
        html[data-theme='dark'] .page-header,
        html[data-theme='dark'] .payroll-grid,
        html[data-theme='dark'] .payroll-card,
        html[data-theme='dark'] .upcoming-payments,
        html[data-theme='dark'] .payment-item,
        html[data-theme='dark'] .admin-section,
        html[data-theme='dark'] .admin-table,
        html[data-theme='dark'] .admin-table th,
        html[data-theme='dark'] .admin-table td {
            background: var(--panel) !important;
            color: var(--text) !important;
            border-color: rgba(255, 255, 255, 0.03) !important;
            box-shadow: var(--card-shadow) !important;
            border-radius: 12px !important;
            padding: 12px !important;
        }

        /* Neutralize inline light backgrounds (TOTAL PAYROLL row and other inline styles) */
        html[data-theme='dark'] .admin-table tr[style],
        html[data-theme='dark'] .admin-table tr:last-child,
        html[data-theme='dark'] .payment-item [style],
        html[data-theme='dark'] [style*="#f8f9fa"] {
            background: transparent !important;
            background-color: transparent !important;
            color: var(--text) !important;
        }

        /* Tables: remove light hover */
        html[data-theme='dark'] table tbody tr:hover,
        html[data-theme='dark'] table tr:hover {
            background: transparent !important;
            transform: none !important;
            box-shadow: none !important;
        }

        /* Make badges, labels and small text visible */
        html[data-theme='dark'] .employment-badge,
        html[data-theme='dark'] .pay-amount,
        html[data-theme='dark'] .pay-detail-label,
        html[data-theme='dark'] .pay-detail-value {
            color: var(--text) !important;
        }

        /* Links, icons and header controls */
        html[data-theme='dark'] header,
        html[data-theme='dark'] header * {
            color: var(--text) !important;
        }

        html[data-theme='dark'] .logo {
            color: var(--text) !important;
        }

        html[data-theme='dark'] .notification-icon {
            color: var(--text) !important;
        }

        /* Ensure small panels and no-data messages are readable */
        html[data-theme='dark'] .no-data,
        html[data-theme='dark'] .payment-date,
        html[data-theme='dark'] .period-info {
            color: #424242ff;
        }

        html[data-theme='dark'] h3 {
            color: var(--text) !important;
        }

        /* Catch common inline white backgrounds */
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
        } catch (Exception $e) {
        }
    }
    ?>
    <title>Payroll - Open Rota</title>
</head>

<body>
    <!-- Navigation Header -->
    <header style="opacity: 1; transition: opacity 0.5s ease;">
        <div class="logo"><img src="../images/new logo.png" alt="Open Rota" style="height: 60px;"></div>
        <div class="nav-group">
            <div class="notification-container">
                <!-- Bell Icon -->
                <i class="fa fa-bell notification-icon" id="notification-icon"></i>
                <?php if (isset($notificationCount) && $notificationCount > 0): ?>
                    <span class="notification-badge"><?php echo $notificationCount; ?></span>
                <?php endif; ?>

                <!-- Notifications Dropdown -->
                <div class="notification-dropdown" id="notification-dropdown">
                    <?php if (isset($notifications) && !empty($notifications)): ?>
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
                                <div class="notification-item notification-<?php echo $notification['type']; ?>"
                                    data-id="<?php echo $notification['id']; ?>">
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
            <nav class="nav-links" id="nav-links">
                <ul>
                    <li><a href="dashboard.php"><i class="fa fa-tachometer"></i> Dashboard</a></li>
                    <li><a href="shifts.php"><i class="fa fa-calendar"></i> My Shifts</a></li>
                    <li><a href="rota.php"><i class="fa fa-table"></i> Rota</a></li>
                    <li><a href="roles.php"><i class="fa fa-users"></i> Roles</a></li>
                    <li><a href="payroll.php"><i class="fa fa-money"></i> Payroll</a></li>
                    <li><a href="chat.php"><i class="fa fa-comments"></i> Team Chat</a></li>
                    <li><a href="settings.php"><i class="fa fa-cog"></i> Settings</a></li>
                    <?php if (isset($_SESSION['role']) && (($_SESSION['role'] === 'admin') || ($_SESSION['role'] === 'super_admin'))): ?>
                        <li><a href="../admin/admin_dashboard.php"><i class="fa fa-shield"></i> Admin</a></li>
                    <?php endif; ?>
                    <li><a href="../functions/logout.php"><i class="fa fa-sign-out"></i> Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fa fa-money"></i>
                Payroll Dashboard
            </h1>
            <div class="subtitle">
                <?php echo htmlspecialchars($user_role['username']); ?> -
                <span class="employment-badge <?php echo $employment_type; ?>">
                    <?php echo ucfirst($employment_type); ?> Staff
                </span>
            </div>
        </div>

        <div class="payroll-grid">
            <!-- Current Period -->
            <div class="payroll-card current-period">
                <h3><i class="fa fa-calendar"></i> Current Pay Period</h3>

                <?php if ($current_period && $current_calculation && !isset($current_calculation['error'])): ?>
                    <div class="period-info">
                        <strong><?php echo htmlspecialchars($current_period['period_name']); ?></strong><br>
                        <?php echo date('j M Y', strtotime($current_period['start_date'])); ?> -
                        <?php echo date('j M Y', strtotime($current_period['end_date'])); ?>
                    </div>

                    <div class="pay-amount">
                        £<?php echo number_format($current_calculation['gross_pay'], 2); ?>
                    </div>

                    <div class="pay-details">
                        <?php if ($employment_type === 'hourly'): ?>
                            <div class="pay-detail-item">
                                <div class="pay-detail-label">Total Hours</div>
                                <div class="pay-detail-value">
                                    <?php echo number_format($current_calculation['total_hours'], 1); ?>h
                                </div>
                            </div>
                            <div class="pay-detail-item">
                                <div class="pay-detail-label">Hourly Rate</div>
                                <div class="pay-detail-value">
                                    £<?php echo number_format($current_calculation['hourly_rate'], 2); ?></div>
                            </div>
                            <?php if ($current_calculation['night_shift_hours'] > 0): ?>
                                <div class="pay-detail-item">
                                    <div class="pay-detail-label">Night Hours</div>
                                    <div class="pay-detail-value">
                                        <?php echo number_format($current_calculation['night_shift_hours'], 1); ?>h
                                    </div>
                                </div>
                                <div class="pay-detail-item">
                                    <div class="pay-detail-label">Night Pay</div>
                                    <div class="pay-detail-value">
                                        £<?php echo number_format($current_calculation['night_pay'], 2); ?></div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="pay-detail-item">
                                <div class="pay-detail-label">Monthly Salary</div>
                                <div class="pay-detail-value">
                                    £<?php echo number_format($current_calculation['monthly_salary'], 2); ?></div>
                            </div>
                            <div class="pay-detail-item">
                                <div class="pay-detail-label">Hours Worked</div>
                                <div class="pay-detail-value">
                                    <?php echo number_format($current_calculation['total_hours'], 1); ?>h
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: 15px; font-size: 14px; color: #666;">
                        Payment Date:
                        <strong><?php echo date('j M Y', strtotime($current_period['payment_date'])); ?></strong>
                    </div>

                <?php elseif ($current_period): ?>
                    <div class="period-info">
                        <strong><?php echo htmlspecialchars($current_period['period_name']); ?></strong><br>
                        <?php echo date('j M Y', strtotime($current_period['start_date'])); ?> -
                        <?php echo date('j M Y', strtotime($current_period['end_date'])); ?>
                    </div>
                    <div class="no-data">
                        <?php echo $current_calculation['error'] ?? 'No shifts scheduled in this period'; ?>
                    </div>
                <?php else: ?>
                    <div class="no-data">No current pay period found</div>
                <?php endif; ?>
            </div>

            <!-- Next Period -->
            <div class="payroll-card next-period">
                <h3><i class="fa fa-clock-o"></i> Next Pay Period</h3>

                <?php if ($next_period): ?>
                    <div class="period-info">
                        <strong><?php echo htmlspecialchars($next_period['period_name']); ?></strong><br>
                        <?php echo date('j M Y', strtotime($next_period['start_date'])); ?> -
                        <?php echo date('j M Y', strtotime($next_period['end_date'])); ?>
                    </div>

                    <div style="margin-top: 15px; font-size: 14px; color: #666;">
                        Payment Date: <strong><?php echo date('j M Y', strtotime($next_period['payment_date'])); ?></strong>
                    </div>

                    <?php
                    $days_until_payment = ceil((strtotime($next_period['payment_date']) - time()) / (60 * 60 * 24));
                    ?>
                    <div style="margin-top: 10px; font-size: 18px; font-weight: bold; color: #28a745;">
                        <?php echo $days_until_payment; ?> days until next payment
                    </div>

                <?php else: ?>
                    <div class="no-data">No upcoming pay period found</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Upcoming Payments -->
        <div class="upcoming-payments">
            <h3><i class="fa fa-calendar-check-o"></i> Upcoming Payment Dates</h3>

            <?php if (!empty($upcoming_payments)): ?>
                <?php foreach ($upcoming_payments as $payment): ?>
                    <div class="payment-item">
                        <div>
                            <div style="font-weight: bold;"><?php echo htmlspecialchars($payment['period_name']); ?></div>
                            <div class="payment-type"><?php echo $payment['period_type']; ?> staff</div>
                        </div>
                        <div class="payment-date">
                            <?php echo date('j M Y', strtotime($payment['payment_date'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-data">No upcoming payments found</div>
            <?php endif; ?>
        </div>

        <?php if ($is_admin && $payroll_summary): ?>
            <!-- Admin Payroll Summary -->
            <div class="admin-section">
                <h3><i class="fa fa-shield"></i> Admin: Current Period Payroll Summary</h3>
                <p><strong><?php echo htmlspecialchars($current_period['period_name']); ?></strong></p>

                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Type</th>
                            <th>Hours</th>
                            <th>Rate/Salary</th>
                            <th>Gross Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total_payroll = 0;
                        foreach ($payroll_summary as $calc):
                            $total_payroll += $calc['gross_pay'];
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($calc['username']); ?></td>
                                <td>
                                    <span class="employment-badge <?php echo $calc['employment_type']; ?>">
                                        <?php echo ucfirst($calc['employment_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo $calc['total_hours'] ? number_format($calc['total_hours'], 1) . 'h' : 'N/A'; ?>
                                </td>
                                <td>
                                    <?php if ($calc['employment_type'] === 'hourly'): ?>
                                        £<?php echo number_format($calc['hourly_rate'], 2); ?>/hr
                                    <?php else: ?>
                                        £<?php echo number_format($calc['monthly_salary'], 2); ?>/month
                                    <?php endif; ?>
                                </td>
                                <td><strong>£<?php echo number_format($calc['gross_pay'], 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background: #f8f9fa; font-weight: bold;">
                            <td colspan="4">TOTAL PAYROLL</td>
                            <td>£<?php echo number_format($total_payroll, 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div style="margin: 20px 0; text-align: center;">
            <a href="dashboard.php" style="color: #fd2b2b; text-decoration: none;">
                <i class="fa fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
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
        });
    </script>
    <script src="../js/darkmode.js"></script>
    <script src="../js/menu.js"></script>
    <script src="../js/pwa-debug.js"></script>
    <script src="../js/links.js"></script>
</body>

</html>