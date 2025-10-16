<?php
/**
 * Chat Database Setup Checker
 * Verifies that all required chat tables exist
 */

require_once 'includes/db.php';

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html>
<head>
    <title>Chat Database Checker</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .table-status { margin: 20px 0; }
        .status { padding: 10px; margin: 5px 0; border-radius: 4px; }
        .exists { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .missing { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .success-box { background: #d4edda; padding: 20px; border-radius: 4px; margin: 20px 0; }
        .error-box { background: #f8d7da; padding: 20px; border-radius: 4px; margin: 20px 0; }
        .instructions { background: #e7f3ff; padding: 20px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #2196F3; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: 'Courier New', monospace; }
        .btn { display: inline-block; padding: 12px 24px; background: #fd2b2b; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
        .btn:hover { background: #c82333; }
    </style>
</head>
<body>
<div class='container'>
    <h1>🔍 Team Chat Database Status</h1>";

// List of required tables
$requiredTables = [
    'chat_channels' => 'Stores chat channels (general, branch, role, direct)',
    'chat_messages' => 'Stores all chat messages',
    'chat_members' => 'Tracks channel membership and read status',
    'chat_reactions' => 'Stores emoji reactions to messages',
    'chat_typing' => 'Tracks typing indicators'
];

$missingTables = [];
$existingTables = [];

// Check each table
foreach ($requiredTables as $table => $description) {
    try {
        $stmt = $conn->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $existingTables[] = $table;
            echo "<div class='status exists'>✅ <strong>$table</strong>: Exists - $description</div>";

            // Get row count
            $countStmt = $conn->query("SELECT COUNT(*) as count FROM $table");
            $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
            echo "<div style='margin-left: 30px; color: #666; font-size: 0.9em;'>Records: $count</div>";
        } else {
            $missingTables[] = $table;
            echo "<div class='status missing'>❌ <strong>$table</strong>: MISSING - $description</div>";
        }
    } catch (Exception $e) {
        $missingTables[] = $table;
        echo "<div class='status missing'>❌ <strong>$table</strong>: ERROR - {$e->getMessage()}</div>";
    }
}

echo "<hr style='margin: 30px 0;'>";

// Summary and instructions
if (empty($missingTables)) {
    echo "<div class='success-box'>
        <h2>✅ All Chat Tables Exist!</h2>
        <p>Your chat system database is properly configured.</p>
        <a href='users/chat.php' class='btn'>Go to Team Chat</a>
    </div>";
} else {
    echo "<div class='error-box'>
        <h2>⚠️ Missing Tables</h2>
        <p>The following tables need to be created: <strong>" . implode(', ', $missingTables) . "</strong></p>
    </div>";

    echo "<div class='instructions'>
        <h3>📋 Setup Instructions</h3>
        <ol>
            <li>Open <strong>phpMyAdmin</strong> (usually at <code>http://localhost/phpmyadmin</code>)</li>
            <li>Select your database: <code>rota_app</code></li>
            <li>Click on the <strong>SQL</strong> tab</li>
            <li>Open the file: <code>setup_team_chat.sql</code> (in your project root folder)</li>
            <li>Copy all the SQL code and paste it into phpMyAdmin</li>
            <li>Click <strong>Go</strong> to execute</li>
            <li>Refresh this page to verify installation</li>
        </ol>
        
        <h4>Alternative Method (Command Line):</h4>
        <p>If you have MySQL command line access:</p>
        <code>mysql -u root -p rota_app < setup_team_chat.sql</code>
        
        <h4>Quick Setup Link:</h4>
        <p><a href='http://localhost/phpmyadmin' target='_blank' class='btn'>Open phpMyAdmin</a></p>
    </div>";
}

// Additional diagnostics
echo "<hr style='margin: 30px 0;'>
    <h3>🔧 Database Connection Info</h3>
    <div class='status exists'>
        Database: Connected<br>
        Total Tables in Database: ";

try {
    $allTables = $conn->query("SHOW TABLES");
    echo $allTables->rowCount();
} catch (Exception $e) {
    echo "Error counting tables";
}

echo "</div>
    </div>
</body>
</html>";
?>