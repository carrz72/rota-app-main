<?php
/**
 * Setup Push Notifications Database Table
 * Run this once to create the required table
 */

require_once 'includes/db.php';

echo "===========================================\n";
echo "  PUSH NOTIFICATIONS DATABASE SETUP\n";
echo "===========================================\n\n";

try {
    // Read SQL file
    $sql = file_get_contents(__DIR__ . '/push_subscriptions_table.sql');
    
    // Execute SQL
    $conn->exec($sql);
    
    echo "✅ Database table created successfully!\n";
    echo "   Table: push_subscriptions\n";
    echo "   Columns: id, user_id, endpoint, p256dh_key, auth_token, created_at, updated_at\n\n";
    
    // Verify table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'push_subscriptions'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Verification: Table exists in database\n\n";
    } else {
        echo "⚠️  Warning: Could not verify table creation\n\n";
    }
    
    echo "===========================================\n";
    echo "✅ Setup complete!\n";
    echo "===========================================\n";
    
} catch (PDOException $e) {
    echo "❌ Error creating table: " . $e->getMessage() . "\n";
    exit(1);
}
?>
