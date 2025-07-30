<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

/**
 * Get all branches
 */
function getAllBranches($conn, $status = 'active')
{
    if ($status === 'all') {
        $sql = "SELECT * FROM branches ORDER BY name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    } else {
        $sql = "SELECT * FROM branches WHERE status = ? ORDER BY name";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$status]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get branch by ID
 */
function getBranchById($conn, $branch_id)
{
    $sql = "SELECT * FROM branches WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$branch_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get user's home branch
 */
function getUserHomeBranch($conn, $user_id)
{
    $sql = "SELECT b.* FROM branches b 
            JOIN users u ON b.id = u.branch_id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get branches user has permissions for
 */
function getUserPermittedBranches($conn, $user_id)
{
    $sql = "SELECT b.*, bp.permission_level 
            FROM branches b 
            LEFT JOIN branch_permissions bp ON b.id = bp.branch_id AND bp.user_id = ?
            WHERE b.status = 'active' 
            AND (bp.user_id IS NOT NULL OR b.id = (SELECT branch_id FROM users WHERE id = ?))
            ORDER BY b.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id, $user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if user can access branch
 */
function canUserAccessBranch($conn, $user_id, $branch_id, $required_level = 'view')
{
    // Check if it's user's home branch
    $sql = "SELECT branch_id FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    $user_branch = $stmt->fetchColumn();

    if ($user_branch == $branch_id) {
        return true; // Always can access home branch
    }

    // Check permissions
    $sql = "SELECT permission_level FROM branch_permissions 
            WHERE user_id = ? AND branch_id = ? 
            AND (expires_at IS NULL OR expires_at > NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id, $branch_id]);
    $permission = $stmt->fetchColumn();

    if (!$permission) {
        return false;
    }

    // Check permission level hierarchy
    $levels = ['view' => 1, 'manage' => 2, 'admin' => 3];
    return $levels[$permission] >= $levels[$required_level];
}

/**
 * Create cross-branch shift request
 */
function createCrossBranchRequest($conn, $data)
{
    $sql = "INSERT INTO cross_branch_shift_requests 
            (requesting_branch_id, target_branch_id, shift_date, start_time, end_time, 
             role_required, urgency_level, description, requested_by_user_id, expires_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    return $stmt->execute([
        $data['requesting_branch_id'],
        $data['target_branch_id'],
        $data['shift_date'],
        $data['start_time'],
        $data['end_time'],
        $data['role_required'],
        $data['urgency_level'],
        $data['description'],
        $data['requested_by_user_id'],
        $data['expires_at']
    ]);
}

/**
 * Get pending cross-branch requests for a branch
 */
function getPendingRequestsForBranch($conn, $branch_id)
{
    $sql = "SELECT cbr.*, 
                   rb.name as requesting_branch_name, rb.code as requesting_branch_code,
                   tb.name as target_branch_name, tb.code as target_branch_code,
                   u.username as requested_by_username
            FROM cross_branch_shift_requests cbr
            JOIN branches rb ON cbr.requesting_branch_id = rb.id
            JOIN branches tb ON cbr.target_branch_id = tb.id
            JOIN users u ON cbr.requested_by_user_id = u.id
            WHERE (cbr.requesting_branch_id = ? OR cbr.target_branch_id = ?)
            AND cbr.status = 'pending'
            AND (cbr.expires_at IS NULL OR cbr.expires_at > NOW())
            ORDER BY cbr.urgency_level DESC, cbr.created_at ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$branch_id, $branch_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get available employees from target branch for a request
 */
function getAvailableEmployeesForRequest($conn, $request_id)
{
    $sql = "SELECT r.*, u.id as user_id, u.username, u.email, 
                   ur.name as user_role_name, ur.employment_type, ur.base_pay
            FROM cross_branch_shift_requests r
            JOIN users u ON u.branch_id = r.target_branch_id
            LEFT JOIN roles ur ON u.role_id = ur.id
            WHERE r.id = ?
            AND u.id NOT IN (
                SELECT s.user_id FROM shifts s 
                WHERE s.shift_date = r.shift_date 
                AND ((s.start_time <= r.end_time AND s.end_time >= r.start_time))
            )
            ORDER BY u.username";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$request_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fulfill cross-branch request
 */
function fulfillCrossBranchRequest($conn, $request_id, $fulfilling_user_id, $approving_user_id)
{
    try {
        $conn->beginTransaction();

        // Get request details
        $sql = "SELECT * FROM cross_branch_shift_requests WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception("Request not found");
        }

        // Create shift for the fulfilling user at the requesting branch
        $sql = "INSERT INTO shifts (user_id, shift_date, start_time, end_time, branch_id, location) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $fulfilling_user_id,
            $request['shift_date'],
            $request['start_time'],
            $request['end_time'],
            $request['requesting_branch_id'],
            'Cross-branch coverage'
        ]);

        $shift_id = $conn->lastInsertId();

        // Create coverage record
        $sql = "INSERT INTO shift_coverage 
                (covering_user_id, home_branch_id, working_branch_id, shift_id, request_id, 
                 approved_by_user_id, approval_date, status) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 'approved')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $fulfilling_user_id,
            $request['target_branch_id'],
            $request['requesting_branch_id'],
            $shift_id,
            $request_id,
            $approving_user_id
        ]);

        // Update request status
        $sql = "UPDATE cross_branch_shift_requests 
                SET status = 'fulfilled', fulfilled_by_user_id = ?, fulfilled_at = NOW() 
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$fulfilling_user_id, $request_id]);

        $conn->commit();
        return true;

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Get cross-branch coverage statistics
 */
function getCrossBranchStats($conn, $branch_id = null, $period_days = 30)
{
    $where_clause = $branch_id ? "AND (cbr.requesting_branch_id = ? OR cbr.target_branch_id = ?)" : "";
    $params = $branch_id ? [$branch_id, $branch_id] : [];

    $sql = "SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN cbr.status = 'fulfilled' THEN 1 ELSE 0 END) as fulfilled_requests,
                SUM(CASE WHEN cbr.status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
                SUM(CASE WHEN cbr.status = 'declined' THEN 1 ELSE 0 END) as declined_requests
            FROM cross_branch_shift_requests cbr
            WHERE cbr.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            $where_clause";

    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$period_days], $params));
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>