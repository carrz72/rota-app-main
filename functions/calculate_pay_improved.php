<?php
/**
 * IMPROVED Pay Calculation Functions
 * This file contains corrected and more accurate calculation logic
 */

/**
 * Calculate pay for a shift invitation with improved accuracy
 */
function calculateInvitationPay($conn, $invitation)
{
    // Retrieve role details based on role_id in the invitation
    $stmt = $conn->prepare("SELECT base_pay, has_night_pay, night_shift_pay, night_start_time, night_end_time FROM roles WHERE id = ?");
    $stmt->execute([$invitation['role_id']]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$role) {
        return 0;
    }

    return calculateShiftPay(
        $invitation['start_time'],
        $invitation['end_time'],
        $role['base_pay'],
        $role['has_night_pay'],
        $role['night_shift_pay'],
        $role['night_start_time'],
        $role['night_end_time']
    );
}

/**
 * Calculate pay for a specific shift with improved accuracy
 */
function calculatePay($conn, $shift_id)
{
    $sql = "SELECT s.start_time, s.end_time, r.base_pay, r.has_night_pay, r.night_shift_pay, r.night_start_time, r.night_end_time
            FROM shifts s
            JOIN roles r ON s.role_id = r.id
            WHERE s.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$shift_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        return 0; // No shift found, return 0
    }

    return calculateShiftPay(
        $shift['start_time'],
        $shift['end_time'],
        $shift['base_pay'],
        $shift['has_night_pay'],
        $shift['night_shift_pay'],
        $shift['night_start_time'],
        $shift['night_end_time']
    );
}

/**
 * Core shift pay calculation function with improved logic
 * This handles all the complex scenarios more accurately
 */
function calculateShiftPay($start_time, $end_time, $base_pay, $has_night_pay, $night_shift_pay, $night_start_time, $night_end_time)
{
    // Parse times - handle both time-only and datetime formats
    if (strpos($start_time, ' ') !== false) {
        $start_time = date('H:i:s', strtotime($start_time));
    }
    if (strpos($end_time, ' ') !== false) {
        $end_time = date('H:i:s', strtotime($end_time));
    }

    // Convert to timestamps (using a fixed date to avoid date-related issues)
    $base_date = '2023-01-01';
    $start_timestamp = strtotime("$base_date $start_time");
    $end_timestamp = strtotime("$base_date $end_time");

    // Handle overnight shifts
    if ($end_timestamp <= $start_timestamp) {
        $end_timestamp = strtotime("2023-01-02 $end_time");
    }

    // Calculate total hours as decimal (more accurate than hour-by-hour)
    $total_hours = ($end_timestamp - $start_timestamp) / 3600;

    // If no night pay, simple calculation
    if (!$has_night_pay || !$night_start_time || !$night_end_time) {
        return $total_hours * $base_pay;
    }

    // Calculate night shift overlap for complex night pay scenarios
    $night_start_ts = strtotime("$base_date $night_start_time");
    $night_end_ts = strtotime("$base_date $night_end_time");

    // Handle night period crossing midnight
    if ($night_end_ts <= $night_start_ts) {
        $night_end_ts = strtotime("2023-01-02 $night_end_time");
    }

    // Calculate overlap between shift and night period
    $night_hours = 0;

    // Case 1: Shift entirely within single day
    if ($end_timestamp <= strtotime("2023-01-02 00:00:00")) {
        $overlap_start = max($start_timestamp, $night_start_ts);
        $overlap_end = min($end_timestamp, $night_end_ts);

        if ($overlap_end > $overlap_start) {
            $night_hours = ($overlap_end - $overlap_start) / 3600;
        }
    } else {
        // Case 2: Shift crosses midnight - need to handle both days
        // First day portion
        $day1_end = strtotime("2023-01-02 00:00:00");
        $overlap_start = max($start_timestamp, $night_start_ts);
        $overlap_end = min($day1_end, $night_end_ts);

        if ($overlap_end > $overlap_start) {
            $night_hours += ($overlap_end - $overlap_start) / 3600;
        }

        // Second day portion (if night period continues)
        if ($night_end_ts > strtotime("2023-01-02 00:00:00")) {
            $day2_start = strtotime("2023-01-02 00:00:00");
            $overlap_start = max($day2_start, strtotime("2023-01-02 $night_start_time"));
            $overlap_end = min($end_timestamp, $night_end_ts);

            if ($overlap_end > $overlap_start) {
                $night_hours += ($overlap_end - $overlap_start) / 3600;
            }
        }
    }

    // Ensure night hours don't exceed total hours
    $night_hours = min($night_hours, $total_hours);
    $regular_hours = $total_hours - $night_hours;

    // Calculate total pay
    $regular_pay = $regular_hours * $base_pay;
    $night_pay = $night_hours * ($night_shift_pay ?: $base_pay);

    return round($regular_pay + $night_pay, 2);
}

/**
 * Display estimated pay with detailed breakdown
 */
function displayEstimatedPayDetailed($conn, $shift_id)
{
    $sql = "SELECT s.start_time, s.end_time, s.shift_date, r.base_pay, r.has_night_pay, r.night_shift_pay, r.night_start_time, r.night_end_time, r.name as role_name
            FROM shifts s
            JOIN roles r ON s.role_id = r.id
            WHERE s.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$shift_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        echo "Shift not found.";
        return;
    }

    $total_pay = calculateShiftPay(
        $shift['start_time'],
        $shift['end_time'],
        $shift['base_pay'],
        $shift['has_night_pay'],
        $shift['night_shift_pay'],
        $shift['night_start_time'],
        $shift['night_end_time']
    );

    // Calculate breakdown
    $start_timestamp = strtotime($shift['shift_date'] . ' ' . $shift['start_time']);
    $end_timestamp = strtotime($shift['shift_date'] . ' ' . $shift['end_time']);

    if ($end_timestamp <= $start_timestamp) {
        $end_timestamp = strtotime(date('Y-m-d', strtotime($shift['shift_date'] . ' +1 day')) . ' ' . $shift['end_time']);
    }

    $total_hours = ($end_timestamp - $start_timestamp) / 3600;

    echo "<div class='pay-breakdown'>";
    echo "<h4>Pay Breakdown for " . htmlspecialchars($shift['role_name']) . "</h4>";
    echo "<p><strong>Date:</strong> " . date('l, j M Y', strtotime($shift['shift_date'])) . "</p>";
    echo "<p><strong>Time:</strong> " . date('g:i A', strtotime($shift['start_time'])) . " - " . date('g:i A', strtotime($shift['end_time'])) . "</p>";
    echo "<p><strong>Total Hours:</strong> " . number_format($total_hours, 2) . "</p>";
    echo "<p><strong>Base Rate:</strong> £" . number_format($shift['base_pay'], 2) . "/hour</p>";

    if ($shift['has_night_pay'] && $shift['night_shift_pay']) {
        echo "<p><strong>Night Rate:</strong> £" . number_format($shift['night_shift_pay'], 2) . "/hour</p>";
        echo "<p><strong>Night Period:</strong> " . date('g:i A', strtotime($shift['night_start_time'])) . " - " . date('g:i A', strtotime($shift['night_end_time'])) . "</p>";
    }

    echo "<p class='total-pay'><strong>Total Estimated Pay: £" . number_format($total_pay, 2) . "</strong></p>";
    echo "</div>";
}

/**
 * Legacy function for backward compatibility
 */
function displayEstimatedPay($conn, $shift_id)
{
    $pay = calculatePay($conn, $shift_id);
    echo "Estimated pay for the shift: £" . number_format($pay, 2);
}

/**
 * Validate shift times and pay rates
 */
function validateShiftData($start_time, $end_time, $base_pay, $night_shift_pay = null)
{
    $errors = [];

    // Validate times
    if (!strtotime($start_time)) {
        $errors[] = "Invalid start time format";
    }
    if (!strtotime($end_time)) {
        $errors[] = "Invalid end time format";
    }

    // Validate pay rates
    if (!is_numeric($base_pay) || $base_pay < 0) {
        $errors[] = "Invalid base pay rate";
    }
    if ($night_shift_pay !== null && (!is_numeric($night_shift_pay) || $night_shift_pay < 0)) {
        $errors[] = "Invalid night shift pay rate";
    }

    // Check for reasonable shift duration (not more than 24 hours)
    if (strtotime($start_time) && strtotime($end_time)) {
        $start_ts = strtotime("2023-01-01 $start_time");
        $end_ts = strtotime("2023-01-01 $end_time");
        if ($end_ts <= $start_ts) {
            $end_ts = strtotime("2023-01-02 $end_time");
        }
        $hours = ($end_ts - $start_ts) / 3600;

        if ($hours > 24) {
            $errors[] = "Shift duration cannot exceed 24 hours";
        }
        if ($hours < 0.25) {
            $errors[] = "Shift duration must be at least 15 minutes";
        }
    }

    return $errors;
}
?>