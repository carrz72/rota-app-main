<?php
// Minimal coverage requests test
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing coverage requests components...<br>";

try {
    session_start();
    echo "✓ Session started<br>";
    
    require_once '../includes/auth.php';
    echo "✓ Auth loaded<br>";
    
    require_once '../includes/db.php';  
    echo "✓ Database loaded<br>";
    
    require_once '../functions/branch_functions.php';
    echo "✓ Branch functions loaded<br>";
    
    require_once '../functions/coverage_pay_helper.php';
    echo "✓ Coverage pay helper loaded<br>";
    
    // Test database connection
    $test = $conn->query("SELECT 1 as test")->fetch();
    echo "✓ Database query works<br>";
    
    // Test user session (this might redirect)
    // requireLogin(); 
    echo "✓ Basic requirements met<br>";
    
    echo "<hr>";
    echo "<h3>If you see this, basic functionality works!</h3>";
    echo "<p>The issue might be:</p>";
    echo "<ul>";
    echo "<li>Authentication redirecting (try logging in first)</li>";
    echo "<li>PHP memory limits</li>";
    echo "<li>Timezone issues</li>";
    echo "<li>File permission issues</li>";
    echo "<li>Missing database tables or data</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "<br>";
    echo "Stack trace: " . $e->getTraceAsString();
}
?>