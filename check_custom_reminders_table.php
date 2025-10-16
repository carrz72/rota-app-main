<?php
// Quick script to check if shift_reminder_preferences table exists
require_once 'includes/db.php';

try {
    // Try to query the table
    $stmt = $conn->prepare("DESCRIBE shift_reminder_preferences");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ Table 'shift_reminder_preferences' EXISTS!\n\n";
    echo "Columns:\n";
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    
    // Check for existing data
    $countStmt = $conn->query("SELECT COUNT(*) as count FROM shift_reminder_preferences");
    $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "\nTotal reminders: $count\n";
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "doesn't exist") !== false) {
        echo "❌ Table 'shift_reminder_preferences' DOES NOT EXIST\n";
        echo "\nYou need to run:\n";
        echo "  sudo mysql -u root -p rota_app < setup_custom_reminders.sql\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
