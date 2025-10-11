<?php
// Debug script to check what's preventing coverage_requests.php from loading
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Coverage Requests Debug</h1>";

// Test 1: Check if basic PHP works
echo "<h2>✓ PHP is working</h2>";

// Test 2: Check includes
echo "<h2>Testing includes...</h2>";

$includes_to_test = [
    '../includes/auth.php',
    '../includes/db.php', 
    '../functions/branch_functions.php',
    '../includes/notifications.php',
    '../functions/coverage_pay_helper.php'
];

foreach ($includes_to_test as $include) {
    echo "<p>Testing: $include ... ";
    if (file_exists($include)) {
        echo "<span style='color: green'>✓ File exists</span>";
        try {
            require_once $include;
            echo " <span style='color: green'>✓ Include successful</span>";
        } catch (Exception $e) {
            echo " <span style='color: red'>✗ Include failed: " . $e->getMessage() . "</span>";
        }
    } else {
        echo "<span style='color: red'>✗ File not found</span>";
    }
    echo "</p>";
}

// Test 3: Check database connection
echo "<h2>Testing database connection...</h2>";
try {
    if (isset($conn)) {
        echo "<p style='color: green'>✓ Database connection exists</p>";
        
        // Test a simple query
        $test = $conn->query("SELECT 1 as test")->fetch();
        if ($test['test'] == 1) {
            echo "<p style='color: green'>✓ Database query works</p>";
        }
    } else {
        echo "<p style='color: red'>✗ Database connection not available</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red'>✗ Database error: " . $e->getMessage() . "</p>";
}

// Test 4: Check session
echo "<h2>Testing session...</h2>";
if (!session_id()) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green'>✓ User session exists (ID: " . $_SESSION['user_id'] . ")</p>";
} else {
    echo "<p style='color: orange'>⚠ No user session - this would cause auth redirect</p>";
}

// Test 5: Check file permissions
echo "<h2>Testing file permissions...</h2>";
$files_to_check = [
    'coverage_requests.php',
    '../functions/coverage_pay_helper.php'
];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        echo "<p>$file: " . substr(sprintf('%o', fileperms($file)), -4) . "</p>";
    }
}

echo "<h2>Debug Complete</h2>";
echo "<p><a href='coverage_requests.php'>Try loading coverage_requests.php</a></p>";
?>