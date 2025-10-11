<?php
// Fix NULL role_id values in coverage requests
require_once 'includes/db.php';

echo "Fixing NULL role_id values in coverage requests...\n\n";

// First, let's see which requests have NULL role_id
$stmt = $conn->prepare("
    SELECT id, start_time, end_time, description, status
    FROM cross_branch_shift_requests 
    WHERE role_id IS NULL
");
$stmt->execute();
$null_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($null_requests)) {
    echo "No requests with NULL role_id found.\n";
} else {
    echo "Found " . count($null_requests) . " requests with NULL role_id:\n";
    foreach ($null_requests as $req) {
        echo "ID: {$req['id']}, Time: {$req['start_time']}-{$req['end_time']}, Status: {$req['status']}\n";
    }

    // Update them to use CSA role (id=4) as default
    echo "\nUpdating NULL role_id values to CSA role (id=4)...\n";
    $update_stmt = $conn->prepare("UPDATE cross_branch_shift_requests SET role_id = 4 WHERE role_id IS NULL");
    $result = $update_stmt->execute();

    if ($result) {
        $affected = $update_stmt->rowCount();
        echo "Successfully updated {$affected} records.\n";
    } else {
        echo "Error updating records.\n";
    }
}

// Verify the fix
echo "\nVerifying fix - checking all requests now:\n";
$verify_stmt = $conn->prepare("
    SELECT id, role_id, start_time, end_time, status 
    FROM cross_branch_shift_requests 
    ORDER BY id DESC 
    LIMIT 10
");
$verify_stmt->execute();
$all_requests = $verify_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($all_requests as $req) {
    $role_status = ($req['role_id'] !== null) ? "Role ID: {$req['role_id']}" : "NULL role_id";
    echo "ID: {$req['id']}, {$role_status}, Status: {$req['status']}\n";
}
?>