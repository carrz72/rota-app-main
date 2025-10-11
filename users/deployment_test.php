<?php
// Test specifically for Digital Ocean deployment issues
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Digital Ocean Deployment Test</h1>";

// Test timezone
echo "<h2>Timezone Test</h2>";
echo "Server timezone: " . date_default_timezone_get() . "<br>";
echo "Current time: " . date('Y-m-d H:i:s') . "<br>";

// Test database functions used in coverage_requests
echo "<h2>Database Functions Test</h2>";

try {
    require_once '../includes/db.php';

    // Test NOW() function
    $now_test = $conn->query("SELECT NOW() as now_time")->fetch();
    echo "NOW() works: " . $now_test['now_time'] . "<br>";

    // Test CURDATE() function  
    $date_test = $conn->query("SELECT CURDATE() as current_date")->fetch();
    echo "CURDATE() works: " . $date_test['current_date'] . "<br>";

    // Test if key tables exist
    $tables_to_check = [
        'cross_branch_shift_requests',
        'branches',
        'users',
        'roles',
        'shifts'
    ];

    echo "<h3>Table Existence Check</h3>";
    foreach ($tables_to_check as $table) {
        try {
            $count = $conn->query("SELECT COUNT(*) as count FROM $table")->fetch();
            echo "✓ Table '$table' exists with {$count['count']} records<br>";
        } catch (Exception $e) {
            echo "✗ Table '$table' error: " . $e->getMessage() . "<br>";
        }
    }

} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "<br>";
}

// Test PHP extensions
echo "<h2>PHP Extensions</h2>";
$required_extensions = ['pdo', 'pdo_mysql', 'session', 'json'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "✓ $ext loaded<br>";
    } else {
        echo "✗ $ext NOT loaded<br>";
    }
}

// Test memory and limits
echo "<h2>PHP Settings</h2>";
echo "Memory limit: " . ini_get('memory_limit') . "<br>";
echo "Max execution time: " . ini_get('max_execution_time') . "<br>";
echo "Upload max filesize: " . ini_get('upload_max_filesize') . "<br>";

// Test file paths
echo "<h2>File Path Test</h2>";
$files_to_check = [
    '../includes/auth.php',
    '../includes/db.php',
    '../functions/branch_functions.php',
    '../functions/coverage_pay_helper.php'
];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        echo "✓ $file exists<br>";
    } else {
        echo "✗ $file NOT found<br>";
    }
}

echo "<hr>";
echo "<p><strong>Next steps:</strong></p>";
echo "<ul>";
echo "<li>If all tests pass, the issue might be in the coverage_requests.php logic</li>";
echo "<li>If database tests fail, check database connection settings</li>";
echo "<li>If file tests fail, check file upload and permissions</li>";
echo "<li>Check server error logs for specific errors</li>";
echo "</ul>";
?>