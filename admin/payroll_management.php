<?php
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/db.php';
require_once '../functions/payroll_functions.php';

// Get period type filter
$period_type = $_GET['period_type'] ?? 'hourly';
$period_id = $_GET['period_id'] ?? null;

// Get all payroll periods for the selected type
$stmt = $conn->prepare("SELECT * FROM payroll_periods WHERE period_type = ? ORDER BY start_date DESC LIMIT 12");
$stmt->execute([$period_type]);
$periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current period if not specified
if (!$period_id && !empty($periods)) {
    $today = date('Y-m-d');
    foreach ($periods as $period) {
        if ($period['start_date'] <= $today && $period['end_date'] >= $today) {
            $period_id = $period['id'];
            break;
        }
    }
    // If no current period, use the most recent
    if (!$period_id) {
        $period_id = $periods[0]['id'];
    }
}

// Get selected period details
$selected_period = null;
if ($period_id) {
    $stmt = $conn->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$period_id]);
    $selected_period = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get payroll calculations for the selected period
$payroll_data = [];
if ($period_id) {
    $payroll_data = getPayrollSummary($conn, $period_id);
}

// Calculate payroll for all users if requested
if (isset($_POST['calculate_payroll']) && $period_id) {
    $users = getUsersForPayroll($conn, $period_type);
    $calculated_count = 0;

    foreach ($users as $user) {
        if ($user['employment_type'] === $period_type) {
            $calculation = calculatePayrollForPeriod($conn, $user['id'], $period_id);
            if (!isset($calculation['error'])) {
                savePayrollCalculation($conn, $user['id'], $period_id, $calculation);
                $calculated_count++;
            }
        }
    }

    $success_message = "Payroll calculated for $calculated_count employees.";

    // Refresh payroll data
    $payroll_data = getPayrollSummary($conn, $period_id);
}

// Mark period as processed if requested
if (isset($_POST['mark_processed']) && $period_id) {
    markPayrollPeriodAsProcessed($conn, $period_id);
    $success_message = "Payroll period marked as processed.";

    // Refresh period data
    $stmt = $conn->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$period_id]);
    $selected_period = $stmt->fetch(PDO::FETCH_ASSOC);
}

$total_payroll = array_sum(array_column($payroll_data, 'gross_pay'));
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
    <link rel="stylesheet" href="../css/payroll_management.css">
    <title>Admin Payroll Management - Open Rota</title>
</head>

<body>
    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fa fa-shield"></i>
                Admin Payroll Management
            </h1>

            <div class="controls">
                <div class="control-group">
                    <label for="period_type">Staff Type:</label>
                    <select id="period_type" onchange="filterByType()">
                        <option value="hourly" <?php echo $period_type === 'hourly' ? 'selected' : ''; ?>>Hourly Staff
                        </option>
                        <option value="salaried" <?php echo $period_type === 'salaried' ? 'selected' : ''; ?>>Salaried
                            Staff</option>
                    </select>
                </div>

                <div class="control-group">
                    <label for="period_select">Period:</label>
                    <select id="period_select" onchange="filterByPeriod()">
                        <?php foreach ($periods as $period): ?>
                            <option value="<?php echo $period['id']; ?>" <?php echo $period['id'] == $period_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($period['period_name']); ?>
                                (<?php echo date('j M Y', strtotime($period['payment_date'])); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <a href="../admin/admin_dashboard.php" class="btn btn-secondary">
                    <i class="fa fa-arrow-left"></i> Back to Admin
                </a>
            </div>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="success-message">
                <i class="fa fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($selected_period): ?>
            <div class="period-info">
                <h3><?php echo htmlspecialchars($selected_period['period_name']); ?></h3>

                <div class="period-details">
                    <div class="period-detail">
                        <div class="period-detail-label">Period Dates</div>
                        <div class="period-detail-value">
                            <?php echo date('j M Y', strtotime($selected_period['start_date'])); ?> -
                            <?php echo date('j M Y', strtotime($selected_period['end_date'])); ?>
                        </div>
                    </div>
                    <div class="period-detail">
                        <div class="period-detail-label">Payment Date</div>
                        <div class="period-detail-value">
                            <?php echo date('l, j M Y', strtotime($selected_period['payment_date'])); ?>
                        </div>
                    </div>
                    <div class="period-detail">
                        <div class="period-detail-label">Staff Type</div>
                        <div class="period-detail-value">
                            <span class="employment-badge <?php echo $selected_period['period_type']; ?>">
                                <?php echo ucfirst($selected_period['period_type']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="period-detail">
                        <div class="period-detail-label">Status</div>
                        <div class="period-detail-value">
                            <span class="status-badge status-<?php echo $selected_period['status']; ?>">
                                <?php echo ucfirst($selected_period['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="action-buttons">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="calculate_payroll" class="btn btn-primary">
                            <i class="fa fa-calculator"></i> Calculate Payroll
                        </button>
                    </form>

                    <?php if ($selected_period['status'] === 'pending' && !empty($payroll_data)): ?>
                        <form method="POST" style="display: inline;">
                            <button type="submit" name="mark_processed" class="btn btn-success"
                                onclick="return confirm('Mark this payroll period as processed?')">
                                <i class="fa fa-check"></i> Mark as Processed
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="payroll-table">
                <div class="table-header">
                    <h3>
                        <i class="fa fa-users"></i>
                        Payroll Summary (<?php echo count($payroll_data); ?> employees)
                    </h3>
                    <div class="total-payroll">
                        Total: £<?php echo number_format($total_payroll, 2); ?>
                    </div>
                </div>

                <?php if (!empty($payroll_data)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Role</th>
                                    <th>Type</th>
                                    <th>Hours</th>
                                    <th>Rate/Salary</th>
                                    <th>Night Hours</th>
                                    <th>Night Pay</th>
                                    <th>Gross Pay</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payroll_data as $calc): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($calc['username']); ?></strong><br>
                                            <small style="color: #666;"><?php echo htmlspecialchars($calc['email']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($calc['role_name'] ?: 'No Role'); ?></td>
                                        <td>
                                            <span class="employment-badge <?php echo $calc['employment_type']; ?>">
                                                <?php echo ucfirst($calc['employment_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $calc['total_hours'] ? number_format($calc['total_hours'], 1) . 'h' : 'N/A'; ?>
                                        </td>
                                        <td>
                                            <?php if ($calc['employment_type'] === 'hourly'): ?>
                                                £<?php echo number_format($calc['hourly_rate'], 2); ?>/hr
                                            <?php else: ?>
                                                £<?php echo number_format($calc['monthly_salary'], 2); ?>/month
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo $calc['night_shift_hours'] > 0 ? number_format($calc['night_shift_hours'], 1) . 'h' : '-'; ?>
                                        </td>
                                        <td>
                                            <?php echo $calc['night_shift_pay'] > 0 ? '£' . number_format($calc['night_shift_pay'], 2) : '-'; ?>
                                        </td>
                                        <td>
                                            <strong>£<?php echo number_format($calc['gross_pay'], 2); ?></strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fa fa-calculator" style="font-size: 2rem; color: #ddd; margin-bottom: 10px;"></i><br>
                        No payroll calculations found for this period.<br>
                        Click "Calculate Payroll" to generate calculations.
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="no-data">
                <i class="fa fa-calendar-times-o" style="font-size: 2rem; color: #ddd; margin-bottom: 10px;"></i><br>
                No payroll periods found.
            </div>
        <?php endif; ?>
    </div>

    <script>
        function filterByType() {
            const periodType = document.getElementById('period_type').value;
            window.location.href = `?period_type=${periodType}`;
        }

        function filterByPeriod() {
            const periodType = document.getElementById('period_type').value;
            const periodId = document.getElementById('period_select').value;
            window.location.href = `?period_type=${periodType}&period_id=${periodId}`;
        }
    </script>
</body>

</html>