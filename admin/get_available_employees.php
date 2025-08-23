<?php
require_once '../includes/auth.php';
require_once '../functions/branch_functions.php';

header('Content-Type: application/json');

if (!isset($_GET['request_id'])) {
    echo json_encode(['error' => 'Request ID required']);
    exit();
}

$request_id = $_GET['request_id'];

try {
    // Get request details
    $sql = "SELECT * FROM cross_branch_shift_requests WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        echo json_encode(['error' => 'Request not found']);
        exit();
    }

    // Check if user has permission to view this request
    $user_branch = getUserHomeBranch($conn, $_SESSION['user_id']);
    $userRole = $_SESSION['role'] ?? '';
    // Allow branch admins (whose home branch matches the target) and global admins/super_admins
    if ((empty($user_branch['id']) || $user_branch['id'] != $request['target_branch_id']) && !in_array($userRole, ['admin', 'super_admin'], true)) {
        echo json_encode(['error' => 'Access denied']);
        exit();
    }

    // Get available employees
    $sql = "SELECT u.id, u.username, u.email, 
                   r.name as role_name, r.employment_type, r.base_pay,
                   COALESCE(r.base_pay, 0) as hourly_rate
            FROM users u 
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.branch_id = ?
            AND u.id NOT IN (
                SELECT s.user_id FROM shifts s 
                WHERE s.shift_date = ? 
                AND ((s.start_time <= ? AND s.end_time >= ?) 
                     OR (s.start_time <= ? AND s.end_time >= ?))
            )
            ORDER BY u.username";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $request['target_branch_id'],
        $request['shift_date'],
        $request['end_time'],
        $request['start_time'],
        $request['start_time'],
        $request['end_time']
    ]);

    $available_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter by role if specified
    if ($request['role_required']) {
        $available_employees = array_filter($available_employees, function ($employee) use ($request) {
            return stripos($employee['role_name'], $request['role_required']) !== false;
        });
    }

    echo json_encode([
        'success' => true,
        'request' => $request,
        'employees' => array_values($available_employees)
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>