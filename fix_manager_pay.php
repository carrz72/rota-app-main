<?php
require_once 'includes/db.php';

// Fix Manager role pay rate
$stmt = $conn->prepare('UPDATE roles SET base_pay = 25.00 WHERE name = ? AND base_pay = 0');
$result = $stmt->execute(['Manager']);

if ($result) {
    echo "✅ Manager role pay rate updated to £25.00\n";
} else {
    echo "❌ Failed to update Manager role\n";
}

// Check for any other roles with zero pay
$zero_pay_roles = $conn->query("SELECT id, name, base_pay FROM roles WHERE base_pay <= 0")->fetchAll(PDO::FETCH_ASSOC);

if (count($zero_pay_roles) > 0) {
    echo "⚠️ Other roles with zero/negative pay found:\n";
    foreach ($zero_pay_roles as $role) {
        echo "  - {$role['name']}: £{$role['base_pay']}\n";
    }
} else {
    echo "✅ All roles now have valid pay rates\n";
}
?>