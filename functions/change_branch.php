<?php
// functions/change_branch.php
// Allow users to change their branch assignment

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Please log in to change your branch'
    ]);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['branch_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Branch ID is required'
        ]);
        exit;
    }

    $userId = $_SESSION['user_id'];
    $newBranchId = (int) $input['branch_id'];

    // Validate that the branch exists and is active
    $branchCheckSql = "SELECT id, name FROM branches WHERE id = ? AND status = 'active'";
    $branchCheckStmt = $conn->prepare($branchCheckSql);
    $branchCheckStmt->execute([$newBranchId]);
    $branch = $branchCheckStmt->fetch(PDO::FETCH_ASSOC);

    if (!$branch) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or inactive branch selected'
        ]);
        exit;
    }

    // Get current user's branch for comparison
    $currentBranchSql = "SELECT u.branch_id, b.name as current_branch_name 
                        FROM users u 
                        LEFT JOIN branches b ON u.branch_id = b.id 
                        WHERE u.id = ?";
    $currentBranchStmt = $conn->prepare($currentBranchSql);
    $currentBranchStmt->execute([$userId]);
    $currentBranch = $currentBranchStmt->fetch(PDO::FETCH_ASSOC);

    // Check if it's the same branch
    if ($currentBranch && $currentBranch['branch_id'] == $newBranchId) {
        echo json_encode([
            'success' => true,
            'message' => 'You are already assigned to this branch',
            'no_change' => true
        ]);
        exit;
    }

    // Update user's branch
    $updateSql = "UPDATE users SET branch_id = ? WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);

    if ($updateStmt->execute([$newBranchId, $userId])) {
        // Audit: branch change via audit_log
        $logDetails = json_encode([
            'old_branch_id' => $currentBranch['branch_id'],
            'old_branch_name' => $currentBranch['current_branch_name'],
            'new_branch_id' => $newBranchId,
            'new_branch_name' => $branch['name']
        ]);
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $userId, 'change_branch', json_decode($logDetails, true), $newBranchId, 'branch_change', session_id()); } catch (Exception $e) {
            // Fallback: keep existing user_activity_log if audit_log unavailable
            try {
                $logSql = "INSERT INTO user_activity_log (user_id, action, details, created_at) 
                           VALUES (?, 'branch_change', ?, NOW())";
                $logStmt = $conn->prepare($logSql);
                $logStmt->execute([$userId, $logDetails]);
            } catch (Exception $logError) {
                error_log("Branch change logging failed: " . $logError->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Branch changed successfully to ' . $branch['name'],
            'new_branch' => [
                'id' => $newBranchId,
                'name' => $branch['name']
            ]
        ]);

    } else {
        throw new Exception('Failed to update branch assignment');
    }

} catch (Exception $e) {
    error_log("Branch Change Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to change branch. Please try again.'
    ]);
}
?>