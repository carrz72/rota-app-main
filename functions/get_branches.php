<?php
// functions/get_branches.php
// Fetch available branches for registration

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../includes/db.php';

try {
    // Fetch all active branches
    $sql = "SELECT id, name, code, address FROM branches WHERE status = 'active' ORDER BY name ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'branches' => $branches
    ]);

} catch (Exception $e) {
    error_log("Get Branches Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch branches'
    ]);
}
?>