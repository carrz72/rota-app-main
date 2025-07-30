<?php
/**
 * Enhanced Payroll System for Salaried and Hourly Staff
 * Supports the specific payment schedules as requested
 */

/**
 * Calculate pay for a specific payroll period and user
 */
function calculatePayrollForPeriod($conn, $user_id, $payroll_period_id)
{
    // Get period information
    $stmt = $conn->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$payroll_period_id]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$period) {
        return ['error' => 'Invalid payroll period'];
    }

    // Get user's employment details from their primary role
    $stmt = $conn->prepare("
        SELECT r.*, u.username 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $user_role = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_role || !$user_role['id']) {
        return ['error' => 'User has no assigned role'];
    }

    $employment_type = $user_role['employment_type'] ?: 'hourly';

    if ($employment_type === 'salaried') {
        return calculateSalariedPay($conn, $user_id, $period, $user_role);
    } else {
        return calculateHourlyPay($conn, $user_id, $period, $user_role);
    }
}

/**
 * Calculate pay for salaried staff (monthly salary)
 */
function calculateSalariedPay($conn, $user_id, $period, $user_role)
{
    if (!$user_role['monthly_salary']) {
        return ['error' => 'No monthly salary set for this role'];
    }

    // For salaried staff, pay is fixed monthly amount regardless of hours worked
    $gross_pay = $user_role['monthly_salary'];

    // Get actual hours worked for reference
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as shift_count,
            SUM(TIMESTAMPDIFF(MINUTE, CONCAT(shift_date, ' ', start_time), CONCAT(shift_date, ' ', end_time))) / 60 as total_hours
        FROM shifts 
        WHERE user_id = ? 
        AND shift_date BETWEEN ? AND ?
    ");
    $stmt->execute([$user_id, $period['start_date'], $period['end_date']]);
    $hours_data = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'employment_type' => 'salaried',
        'gross_pay' => $gross_pay,
        'monthly_salary' => $user_role['monthly_salary'],
        'total_hours' => $hours_data['total_hours'] ?: 0,
        'shift_count' => $hours_data['shift_count'] ?: 0,
        'period_type' => $period['period_type'],
        'period_name' => $period['period_name'],
        'payment_date' => $period['payment_date']
    ];
}

/**
 * Calculate pay for hourly staff (based on hours worked)
 */
function calculateHourlyPay($conn, $user_id, $period, $user_role)
{
    if (!$user_role['base_pay']) {
        return ['error' => 'No hourly rate set for this role'];
    }

    // Get all shifts in the period
    $stmt = $conn->prepare("
        SELECT 
            shift_date,
            start_time,
            end_time,
            TIMESTAMPDIFF(MINUTE, CONCAT(shift_date, ' ', start_time), CONCAT(shift_date, ' ', end_time)) / 60 as hours
        FROM shifts 
        WHERE user_id = ? 
        AND shift_date BETWEEN ? AND ?
        ORDER BY shift_date, start_time
    ");
    $stmt->execute([$user_id, $period['start_date'], $period['end_date']]);
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_hours = 0;
    $night_shift_hours = 0;
    $regular_hours = 0;

    foreach ($shifts as $shift) {
        $shift_hours = $shift['hours'];
        $total_hours += $shift_hours;

        // Check if this shift qualifies for night pay
        if ($user_role['has_night_pay'] && $user_role['night_start_time'] && $user_role['night_end_time']) {
            $shift_start = strtotime($shift['start_time']);
            $shift_end = strtotime($shift['end_time']);
            $night_start = strtotime($user_role['night_start_time']);
            $night_end = strtotime($user_role['night_end_time']);

            // Handle night shifts that cross midnight
            if ($night_end < $night_start) {
                $night_end += 86400; // Add 24 hours
            }
            if ($shift_end < $shift_start) {
                $shift_end += 86400; // Add 24 hours
            }

            // Calculate overlap with night hours
            $overlap_start = max($shift_start, $night_start);
            $overlap_end = min($shift_end, $night_end);

            if ($overlap_start < $overlap_end) {
                $night_hours = ($overlap_end - $overlap_start) / 3600;
                $night_shift_hours += $night_hours;
                $regular_hours += ($shift_hours - $night_hours);
            } else {
                $regular_hours += $shift_hours;
            }
        } else {
            $regular_hours += $shift_hours;
        }
    }

    // Calculate pay
    $regular_pay = $regular_hours * $user_role['base_pay'];
    $night_pay = $night_shift_hours * ($user_role['night_shift_pay'] ?: $user_role['base_pay']);
    $gross_pay = $regular_pay + $night_pay;

    return [
        'employment_type' => 'hourly',
        'gross_pay' => $gross_pay,
        'total_hours' => $total_hours,
        'regular_hours' => $regular_hours,
        'night_shift_hours' => $night_shift_hours,
        'hourly_rate' => $user_role['base_pay'],
        'night_rate' => $user_role['night_shift_pay'],
        'regular_pay' => $regular_pay,
        'night_pay' => $night_pay,
        'shift_count' => count($shifts),
        'period_type' => $period['period_type'],
        'period_name' => $period['period_name'],
        'payment_date' => $period['payment_date']
    ];
}

/**
 * Get current payroll period for a specific employment type
 */
function getCurrentPayrollPeriod($conn, $employment_type = 'hourly')
{
    $today = date('Y-m-d');

    $stmt = $conn->prepare("
        SELECT * FROM payroll_periods 
        WHERE period_type = ? 
        AND start_date <= ? 
        AND end_date >= ? 
        ORDER BY start_date DESC 
        LIMIT 1
    ");
    $stmt->execute([$employment_type, $today, $today]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get next payroll period for a specific employment type
 */
function getNextPayrollPeriod($conn, $employment_type = 'hourly')
{
    $today = date('Y-m-d');

    $stmt = $conn->prepare("
        SELECT * FROM payroll_periods 
        WHERE period_type = ? 
        AND start_date > ? 
        ORDER BY start_date ASC 
        LIMIT 1
    ");
    $stmt->execute([$employment_type, $today]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get all users for payroll calculation
 */
function getUsersForPayroll($conn, $employment_type = null)
{
    $sql = "
        SELECT u.id, u.username, u.email, r.name as role_name, r.employment_type, r.base_pay, r.monthly_salary
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        WHERE u.role_id IS NOT NULL
    ";

    $params = [];
    if ($employment_type) {
        $sql .= " AND r.employment_type = ?";
        $params[] = $employment_type;
    }

    $sql .= " ORDER BY r.employment_type, u.username";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Save payroll calculation to database
 */
function savePayrollCalculation($conn, $user_id, $payroll_period_id, $calculation_data)
{
    // Check if calculation already exists
    $stmt = $conn->prepare("
        SELECT id FROM payroll_calculations 
        WHERE user_id = ? AND payroll_period_id = ?
    ");
    $stmt->execute([$user_id, $payroll_period_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update existing calculation
        $stmt = $conn->prepare("
            UPDATE payroll_calculations SET 
                employment_type = ?,
                total_hours = ?,
                hourly_rate = ?,
                monthly_salary = ?,
                gross_pay = ?,
                night_shift_hours = ?,
                night_shift_pay = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $calculation_data['employment_type'],
            $calculation_data['total_hours'] ?? null,
            $calculation_data['hourly_rate'] ?? null,
            $calculation_data['monthly_salary'] ?? null,
            $calculation_data['gross_pay'],
            $calculation_data['night_shift_hours'] ?? 0,
            $calculation_data['night_pay'] ?? 0,
            $existing['id']
        ]);
        return $existing['id'];
    } else {
        // Insert new calculation
        $stmt = $conn->prepare("
            INSERT INTO payroll_calculations 
            (user_id, payroll_period_id, employment_type, total_hours, hourly_rate, monthly_salary, 
             gross_pay, night_shift_hours, night_shift_pay) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $payroll_period_id,
            $calculation_data['employment_type'],
            $calculation_data['total_hours'] ?? null,
            $calculation_data['hourly_rate'] ?? null,
            $calculation_data['monthly_salary'] ?? null,
            $calculation_data['gross_pay'],
            $calculation_data['night_shift_hours'] ?? 0,
            $calculation_data['night_pay'] ?? 0
        ]);
        return $conn->lastInsertId();
    }
}

/**
 * Get payroll summary for a period
 */
function getPayrollSummary($conn, $payroll_period_id)
{
    $stmt = $conn->prepare("
        SELECT 
            pc.*,
            u.username,
            u.email,
            pp.period_name,
            pp.payment_date,
            r.name as role_name
        FROM payroll_calculations pc
        JOIN users u ON pc.user_id = u.id
        JOIN payroll_periods pp ON pc.payroll_period_id = pp.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE pc.payroll_period_id = ?
        ORDER BY pc.employment_type, u.username
    ");
    $stmt->execute([$payroll_period_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mark payroll period as processed
 */
function markPayrollPeriodAsProcessed($conn, $payroll_period_id)
{
    $stmt = $conn->prepare("
        UPDATE payroll_periods 
        SET status = 'processed', updated_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    return $stmt->execute([$payroll_period_id]);
}

/**
 * Get next payment period for a user based on their employment type
 */
function getNextPaymentPeriod($conn, $employment_type = 'hourly')
{
    $today = date('Y-m-d');

    $stmt = $conn->prepare("
        SELECT * FROM payroll_periods 
        WHERE period_type = ? 
        AND payment_date >= ? 
        ORDER BY payment_date ASC 
        LIMIT 1
    ");
    $stmt->execute([$employment_type, $today]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get upcoming payment dates
 */
function getUpcomingPaymentDates($conn, $limit = 5)
{
    $today = date('Y-m-d');

    $stmt = $conn->prepare("
        SELECT period_name, payment_date, period_type, status
        FROM payroll_periods 
        WHERE payment_date >= ?
        ORDER BY payment_date ASC
        LIMIT " . (int) $limit . "
    ");
    $stmt->execute([$today]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>