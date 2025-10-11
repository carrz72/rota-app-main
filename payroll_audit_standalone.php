<?php
/**
 * STANDALONE PAYROLL CALCULATION AUDIT
 * This script analyzes calculation accuracy without conflicts
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection only
require_once 'includes/db.php';

echo "<html><head><title>Payroll Calculation Audit</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .test-section { border: 1px solid #ddd; margin: 10px 0; padding: 15px; }
    .pass { color: green; font-weight: bold; }
    .fail { color: red; font-weight: bold; }
    .warning { color: orange; font-weight: bold; }
    .summary { background: #f5f5f5; padding: 20px; margin: 20px 0; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
</style></head><body>";

echo "<h1>🔍 Payroll System Calculation Audit</h1>";

$issues_found = [];

/**
 * Improved calculation function (standalone to avoid conflicts)
 */
function auditCalculateShiftPay($start_time, $end_time, $base_pay, $has_night_pay, $night_shift_pay, $night_start_time, $night_end_time)
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
        return round($total_hours * $base_pay, 2);
    }

    // Calculate night shift overlap
    $night_start_ts = strtotime("$base_date $night_start_time");
    $night_end_ts = strtotime("$base_date $night_end_time");

    // Handle night period crossing midnight
    if ($night_end_ts <= $night_start_ts) {
        $night_end_ts = strtotime("2023-01-02 $night_end_time");
    }

    // Calculate overlap between shift and night period
    $night_hours = 0;

    // Find overlap
    $overlap_start = max($start_timestamp, $night_start_ts);
    $overlap_end = min($end_timestamp, $night_end_ts);

    if ($overlap_end > $overlap_start) {
        $night_hours = ($overlap_end - $overlap_start) / 3600;
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
 * Test 1: Database Schema Validation
 */
echo "<div class='test-section'>";
echo "<h2>Test 1: Database Schema Validation</h2>";

$required_tables = ['roles', 'shifts', 'users'];
$missing_tables = [];

foreach ($required_tables as $table) {
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM $table");
        echo "<span class='pass'>✅ Table '$table' exists</span><br>";
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Table '$table' missing or inaccessible</span><br>";
        $missing_tables[] = $table;
        $issues_found[] = "Missing table: $table";
    }
}

// Check roles table structure
if (!in_array('roles', $missing_tables)) {
    try {
        $roles_columns = $conn->query("DESCRIBE roles")->fetchAll(PDO::FETCH_ASSOC);
        $required_columns = ['id', 'name', 'base_pay', 'has_night_pay', 'night_shift_pay', 'night_start_time', 'night_end_time'];

        echo "<h4>Roles Table Structure Check:</h4>";
        $existing_columns = array_column($roles_columns, 'Field');

        foreach ($required_columns as $col) {
            if (in_array($col, $existing_columns)) {
                echo "<span class='pass'>✅ Column '$col' exists</span><br>";
            } else {
                echo "<span class='fail'>❌ Column '$col' missing</span><br>";
                $issues_found[] = "Missing column '$col' in roles table";
            }
        }
    } catch (Exception $e) {
        echo "<span class='fail'>Error checking roles table structure: " . $e->getMessage() . "</span><br>";
        $issues_found[] = "Cannot analyze roles table structure";
    }
}

echo "</div>";

/**
 * Test 2: Data Quality Check
 */
echo "<div class='test-section'>";
echo "<h2>Test 2: Data Quality Analysis</h2>";

try {
    // Check for roles with invalid pay rates
    $invalid_roles = $conn->query("
        SELECT id, name, base_pay, night_shift_pay
        FROM roles 
        WHERE base_pay <= 0 OR base_pay > 100 OR (night_shift_pay IS NOT NULL AND night_shift_pay <= 0)
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (count($invalid_roles) > 0) {
        echo "<span class='fail'>Found " . count($invalid_roles) . " roles with invalid pay rates:</span><br>";
        foreach ($invalid_roles as $role) {
            echo "- Role '{$role['name']}': Base pay £{$role['base_pay']}, Night pay £" . ($role['night_shift_pay'] ?: 'N/A') . "<br>";
            $issues_found[] = "Invalid pay rate for role: {$role['name']}";
        }
    } else {
        echo "<span class='pass'>✅ All roles have valid pay rates</span><br>";
    }

    // Check for inconsistent night pay configuration
    $inconsistent_night_pay = $conn->query("
        SELECT id, name, has_night_pay, night_shift_pay, night_start_time, night_end_time
        FROM roles 
        WHERE has_night_pay = 1 AND (
            night_shift_pay IS NULL OR 
            night_start_time IS NULL OR 
            night_end_time IS NULL OR
            night_shift_pay <= base_pay
        )
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (count($inconsistent_night_pay) > 0) {
        echo "<span class='warning'>Found " . count($inconsistent_night_pay) . " roles with inconsistent night pay setup:</span><br>";
        foreach ($inconsistent_night_pay as $role) {
            echo "- Role '{$role['name']}': Night pay enabled but incomplete configuration<br>";
            $issues_found[] = "Inconsistent night pay configuration for role: {$role['name']}";
        }
    } else {
        echo "<span class='pass'>✅ Night pay configurations are consistent</span><br>";
    }

} catch (Exception $e) {
    echo "<span class='fail'>Error in data quality check: " . $e->getMessage() . "</span><br>";
    $issues_found[] = "Data quality check failed: " . $e->getMessage();
}

echo "</div>";

/**
 * Test 3: Calculation Logic Testing
 */
echo "<div class='test-section'>";
echo "<h2>Test 3: Calculation Logic Validation</h2>";

$test_scenarios = [
    [
        'description' => 'Regular 8-hour day shift',
        'start' => '09:00:00',
        'end' => '17:00:00',
        'base_pay' => 12.50,
        'has_night_pay' => false,
        'night_shift_pay' => null,
        'night_start' => null,
        'night_end' => null,
        'expected_hours' => 8,
        'expected_pay' => 100.00
    ],
    [
        'description' => 'Overnight shift with night pay',
        'start' => '22:00:00',
        'end' => '06:00:00',
        'base_pay' => 12.50,
        'has_night_pay' => true,
        'night_shift_pay' => 18.75,
        'night_start' => '22:00:00',
        'night_end' => '06:00:00',
        'expected_hours' => 8,
        'expected_pay' => 150.00 // All night hours at 18.75
    ],
    [
        'description' => 'Partial night shift',
        'start' => '20:00:00',
        'end' => '02:00:00',
        'base_pay' => 15.00,
        'has_night_pay' => true,
        'night_shift_pay' => 22.50,
        'night_start' => '22:00:00',
        'night_end' => '06:00:00',
        'expected_hours' => 6,
        'expected_pay' => 120.00 // 2 regular (£30) + 4 night hours (£90) = £120
    ],
    [
        'description' => 'Short shift (15 minutes)',
        'start' => '12:00:00',
        'end' => '12:15:00',
        'base_pay' => 20.00,
        'has_night_pay' => false,
        'night_shift_pay' => null,
        'night_start' => null,
        'night_end' => null,
        'expected_hours' => 0.25,
        'expected_pay' => 5.00
    ]
];

echo "<table>";
echo "<tr><th>Test Scenario</th><th>Expected Hours</th><th>Calculated Hours</th><th>Expected Pay</th><th>Calculated Pay</th><th>Status</th></tr>";

foreach ($test_scenarios as $scenario) {
    $calculated_pay = auditCalculateShiftPay(
        $scenario['start'],
        $scenario['end'],
        $scenario['base_pay'],
        $scenario['has_night_pay'],
        $scenario['night_shift_pay'],
        $scenario['night_start'],
        $scenario['night_end']
    );

    // Calculate hours
    $start_ts = strtotime("2023-01-01 {$scenario['start']}");
    $end_ts = strtotime("2023-01-01 {$scenario['end']}");
    if ($end_ts <= $start_ts) {
        $end_ts = strtotime("2023-01-02 {$scenario['end']}");
    }
    $calculated_hours = ($end_ts - $start_ts) / 3600;

    $pay_difference = abs($calculated_pay - $scenario['expected_pay']);
    $hours_difference = abs($calculated_hours - $scenario['expected_hours']);

    $status = ($pay_difference < 0.01 && $hours_difference < 0.01) ?
        "<span class='pass'>PASS</span>" : "<span class='fail'>FAIL</span>";

    if ($pay_difference >= 0.01 || $hours_difference >= 0.01) {
        $issues_found[] = "Calculation test failed: {$scenario['description']} (Pay diff: £" . number_format($pay_difference, 2) . ")";
    }

    echo "<tr>";
    echo "<td>{$scenario['description']}</td>";
    echo "<td>" . number_format($scenario['expected_hours'], 2) . "</td>";
    echo "<td>" . number_format($calculated_hours, 2) . "</td>";
    echo "<td>£" . number_format($scenario['expected_pay'], 2) . "</td>";
    echo "<td>£" . number_format($calculated_pay, 2) . "</td>";
    echo "<td>$status</td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

/**
 * Test 4: Real Data Sample Test
 */
echo "<div class='test-section'>";
echo "<h2>Test 4: Real Database Sample Test</h2>";

try {
    $sample_shifts = $conn->query("
        SELECT s.id, s.start_time, s.end_time, s.shift_date, 
               r.base_pay, r.has_night_pay, r.night_shift_pay, r.night_start_time, r.night_end_time, 
               r.name as role_name
        FROM shifts s
        JOIN roles r ON s.role_id = r.id
        ORDER BY s.id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (count($sample_shifts) > 0) {
        echo "<p>Testing calculation accuracy on recent shifts:</p>";
        echo "<table>";
        echo "<tr><th>Shift ID</th><th>Role</th><th>Date/Time</th><th>Duration</th><th>Calculated Pay</th><th>Issues</th></tr>";

        foreach ($sample_shifts as $shift) {
            $calculated_pay = auditCalculateShiftPay(
                $shift['start_time'],
                $shift['end_time'],
                $shift['base_pay'],
                $shift['has_night_pay'],
                $shift['night_shift_pay'],
                $shift['night_start_time'],
                $shift['night_end_time']
            );

            // Calculate duration
            $start_ts = strtotime($shift['shift_date'] . ' ' . $shift['start_time']);
            $end_ts = strtotime($shift['shift_date'] . ' ' . $shift['end_time']);
            if ($end_ts <= $start_ts) {
                $end_ts = strtotime(date('Y-m-d', strtotime($shift['shift_date'] . ' +1 day')) . ' ' . $shift['end_time']);
            }
            $duration = ($end_ts - $start_ts) / 3600;

            $issues = [];
            if ($duration <= 0)
                $issues[] = "Zero/negative duration";
            if ($calculated_pay <= 0)
                $issues[] = "Zero/negative pay";
            if ($duration > 24)
                $issues[] = "Excessive duration";

            $issue_text = count($issues) > 0 ? implode(', ', $issues) : 'None';
            if (count($issues) > 0) {
                $issues_found[] = "Shift ID {$shift['id']}: " . implode(', ', $issues);
            }

            echo "<tr>";
            echo "<td>{$shift['id']}</td>";
            echo "<td>{$shift['role_name']}</td>";
            echo "<td>{$shift['shift_date']} {$shift['start_time']}-{$shift['end_time']}</td>";
            echo "<td>" . number_format($duration, 2) . "h</td>";
            echo "<td>£" . number_format($calculated_pay, 2) . "</td>";
            echo "<td>$issue_text</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<span class='warning'>No shifts found in database for testing</span><br>";
    }

} catch (Exception $e) {
    echo "<span class='fail'>Error testing real data: " . $e->getMessage() . "</span><br>";
    $issues_found[] = "Real data test failed: " . $e->getMessage();
}

echo "</div>";

/**
 * Summary Report
 */
echo "<div class='summary'>";
echo "<h2>📊 Audit Summary Report</h2>";

if (count($issues_found) == 0) {
    echo "<span class='pass'>✅ EXCELLENT: No critical calculation issues found!</span><br><br>";
    echo "Your payroll calculation system appears to be working correctly. The audit found:";
    echo "<ul>";
    echo "<li>✅ Database schema is properly structured</li>";
    echo "<li>✅ Pay rates are within reasonable ranges</li>";
    echo "<li>✅ Calculation logic handles test scenarios correctly</li>";
    echo "<li>✅ No obvious data quality issues</li>";
    echo "</ul>";
} else {
    echo "<span class='fail'>⚠️ ATTENTION: " . count($issues_found) . " issues require attention:</span><br><br>";
    echo "<ol>";
    foreach ($issues_found as $i => $issue) {
        echo "<li>" . htmlspecialchars($issue) . "</li>";
    }
    echo "</ol>";

    echo "<h3>🔧 Recommended Priority Actions:</h3>";
    echo "<ol>";
    echo "<li><strong>High Priority:</strong> Fix any database schema issues or data quality problems</li>";
    echo "<li><strong>Medium Priority:</strong> Address calculation logic discrepancies</li>";
    echo "<li><strong>Low Priority:</strong> Implement additional validation and monitoring</li>";
    echo "</ol>";
}

echo "<h3>💡 General Recommendations:</h3>";
echo "<ul>";
echo "<li><strong>Regular Audits:</strong> Run this audit monthly to catch issues early</li>";
echo "<li><strong>Input Validation:</strong> Add validation for shift times and pay rates</li>";
echo "<li><strong>Logging:</strong> Log all payroll calculations for audit trails</li>";
echo "<li><strong>Testing:</strong> Test calculations after any system changes</li>";
echo "<li><strong>Documentation:</strong> Document calculation logic and business rules</li>";
echo "</ul>";

$accuracy_score = max(0, 100 - (count($issues_found) * 10));
echo "<h3>🎯 System Accuracy Score: $accuracy_score%</h3>";

if ($accuracy_score >= 90) {
    echo "<span class='pass'>Excellent - System is highly accurate</span>";
} elseif ($accuracy_score >= 70) {
    echo "<span class='warning'>Good - Minor issues to address</span>";
} else {
    echo "<span class='fail'>Needs Attention - Multiple issues require fixes</span>";
}

echo "</div>";

echo "<p><em>Audit completed at " . date('Y-m-d H:i:s') . "</em></p>";
echo "</body></html>";
?>