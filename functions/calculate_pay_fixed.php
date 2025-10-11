<?php
/**
 * CORRECTED Pay Calculation Functions
 * Fixed the night shift calculation logic to properly handle partial overlaps
 */

function calculateInvitationPay($conn, $invitation)
{
    // Retrieve role details based on role_id in the invitation.
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
 * CORRECTED: Core shift pay calculation function
 * This properly calculates overlapping hours between shift and night period
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

    // Convert to timestamps using fixed date to avoid date-related issues
    $base_date = '2023-01-01';
    $start_timestamp = strtotime("$base_date $start_time");
    $end_timestamp = strtotime("$base_date $end_time");

    // Handle overnight shifts
    if ($end_timestamp <= $start_timestamp) {
        $end_timestamp = strtotime("2023-01-02 $end_time");
    }

    // Calculate total hours
    $total_hours = ($end_timestamp - $start_timestamp) / 3600;

    // If no night pay, simple calculation
    if (!$has_night_pay || !$night_shift_pay || !$night_start_time || !$night_end_time) {
        return round($total_hours * $base_pay, 2);
    }

    // Calculate night shift overlap
    $night_start_ts = strtotime("$base_date $night_start_time");
    $night_end_ts = strtotime("$base_date $night_end_time");

    // Handle night period crossing midnight
    if ($night_end_ts <= $night_start_ts) {
        $night_end_ts = strtotime("2023-01-02 $night_end_time");
    }

    // Calculate actual overlap between shift and night period
    $night_hours = 0;

    // Method 1: Direct overlap calculation
    $overlap_start = max($start_timestamp, $night_start_ts);
    $overlap_end = min($end_timestamp, $night_end_ts);

    if ($overlap_end > $overlap_start) {
        $night_hours = ($overlap_end - $overlap_start) / 3600;
    }

    // Handle case where shift crosses midnight but night period doesn't
    if ($end_timestamp > strtotime("2023-01-02 00:00:00") && $night_end_ts <= strtotime("2023-01-02 00:00:00")) {
        // Check overlap with night period on second day
        $night_start_day2 = strtotime("2023-01-02 $night_start_time");
        $night_end_day2 = strtotime("2023-01-02 $night_end_time");

        if ($night_end_day2 <= $night_start_day2) {
            $night_end_day2 = strtotime("2023-01-03 $night_end_time");
        }

        $overlap_start_day2 = max(strtotime("2023-01-02 00:00:00"), $night_start_day2);
        $overlap_end_day2 = min($end_timestamp, $night_end_day2);

        if ($overlap_end_day2 > $overlap_start_day2) {
            $night_hours += ($overlap_end_day2 - $overlap_start_day2) / 3600;
        }
    }

    // Ensure night hours don't exceed total hours
    $night_hours = min($night_hours, $total_hours);
    $regular_hours = $total_hours - $night_hours;

    // Calculate total pay
    $regular_pay = $regular_hours * $base_pay;
    $night_pay = $night_hours * $night_shift_pay;

    return round($regular_pay + $night_pay, 2);
}

function displayEstimatedPay($conn, $shift_id)
{
    $pay = calculatePay($conn, $shift_id);
    echo "Estimated pay for the shift: £" . number_format($pay, 2); // Fixed currency symbol
}

/**
 * Enhanced function to show detailed pay breakdown
 */
function displayDetailedPay($conn, $shift_id)
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

    // Calculate detailed breakdown
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

    echo "<p class='total-pay'><strong>Total Pay: £" . number_format($total_pay, 2) . "</strong></p>";
    echo "</div>";
}
?>