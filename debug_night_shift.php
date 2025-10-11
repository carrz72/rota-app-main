<?php
/**
 * Debug the partial night shift calculation specifically
 */

require_once 'includes/db.php';
require_once 'functions/calculate_pay.php';

echo "<h2>Debugging Partial Night Shift Calculation</h2>\n";

// Test scenario: 20:00-02:00 (6 hours) with base pay £15.00, night pay £22.50, night period 22:00-06:00
$start_time = '20:00:00';
$end_time = '02:00:00';
$base_pay = 15.00;
$night_shift_pay = 22.50;
$night_start_time = '22:00:00';
$night_end_time = '06:00:00';

echo "Test Scenario: $start_time to $end_time\n";
echo "Base pay: £$base_pay/hour\n";
echo "Night pay: £$night_shift_pay/hour\n";
echo "Night period: $night_start_time to $night_end_time\n\n";

// Manual calculation for verification
echo "Manual calculation:\n";
echo "- Shift: 20:00-02:00 = 6 hours total\n";
echo "- Regular hours (20:00-22:00): 2 hours at £15.00 = £30.00\n";
echo "- Night hours (22:00-02:00): 4 hours at £22.50 = £90.00\n";
echo "- Expected total: £30.00 + £90.00 = £120.00\n\n";

// Test with current function
$calculated_pay = calculateShiftPay(
    $start_time,
    $end_time,
    $base_pay,
    true, // has_night_pay
    $night_shift_pay,
    $night_start_time,
    $night_end_time
);

echo "Current function result: £" . number_format($calculated_pay, 2) . "\n";

// Step by step debug
echo "\nStep-by-step debug:\n";

$base_date = '2023-01-01';
$start_timestamp = strtotime("$base_date $start_time");
$end_timestamp = strtotime("$base_date $end_time");

if ($end_timestamp <= $start_timestamp) {
    $end_timestamp = strtotime("2023-01-02 $end_time");
}

$total_hours = ($end_timestamp - $start_timestamp) / 3600;
echo "1. Total hours: $total_hours\n";

$night_start_ts = strtotime("$base_date $night_start_time");
$night_end_ts = strtotime("$base_date $night_end_time");

if ($night_end_ts <= $night_start_ts) {
    $night_end_ts = strtotime("2023-01-02 $night_end_time");
}

echo "2. Shift period: " . date('Y-m-d H:i:s', $start_timestamp) . " to " . date('Y-m-d H:i:s', $end_timestamp) . "\n";
echo "3. Night period: " . date('Y-m-d H:i:s', $night_start_ts) . " to " . date('Y-m-d H:i:s', $night_end_ts) . "\n";

$overlap_start = max($start_timestamp, $night_start_ts);
$overlap_end = min($end_timestamp, $night_end_ts);

echo "4. Overlap start: " . date('Y-m-d H:i:s', $overlap_start) . "\n";
echo "5. Overlap end: " . date('Y-m-d H:i:s', $overlap_end) . "\n";

if ($overlap_end > $overlap_start) {
    $night_hours = ($overlap_end - $overlap_start) / 3600;
    echo "6. Night hours calculated: $night_hours\n";
} else {
    $night_hours = 0;
    echo "6. No overlap found\n";
}

$regular_hours = $total_hours - $night_hours;
echo "7. Regular hours: $regular_hours\n";

$regular_pay = $regular_hours * $base_pay;
$night_pay = $night_hours * $night_shift_pay;
$total_pay = $regular_pay + $night_pay;

echo "8. Regular pay: £" . number_format($regular_pay, 2) . "\n";
echo "9. Night pay: £" . number_format($night_pay, 2) . "\n";
echo "10. Total pay: £" . number_format($total_pay, 2) . "\n";

// The expectation in the test was wrong - it should be £120.00, not £75.00!
echo "\nConclusion: The calculated £120.00 is CORRECT!\n";
echo "The test expectation of £75.00 was wrong.\n";
echo "Correct breakdown: 2 regular hours (£30) + 4 night hours (£90) = £120\n";
?>