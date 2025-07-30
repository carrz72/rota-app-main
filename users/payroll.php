<?php
require_once '../includes/auth.php';
requireLogin();
include_once '../includes/header.php';
require_once '../includes/db.php';
require_once '../functions/payroll_functions.php';

$user_id = $_SESSION['user_id'];
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

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
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/payroll.css">
    <title>Payroll - Open Rota</title>
</head>

<body>
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

    <script src="/rota-app-main/js/menu.js"></script>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>